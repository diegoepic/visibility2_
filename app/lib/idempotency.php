<?php
declare(strict_types=1);

if (!function_exists('idempo_raw_header')) {

  function idempo_raw_header(): ?string {
    $h = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? '';
    if ($h === '' && isset($_POST['X_Idempotency_Key'])) {
      $h = (string)$_POST['X_Idempotency_Key'];
    }
    return $h !== '' ? $h : null;
  }
}

if (!function_exists('idempo_sanitize')) {
  /**
   * Permite únicamente [A-Za-z0-9_.:-] y limita a 64 chars.
   * Si excede, aplica SHA-256 y recorta a 64 para estabilidad.
   */
  function idempo_sanitize(?string $k): ?string {
    if ($k === null || $k === '') return null;
    $k = preg_replace('/[^A-Za-z0-9_\-:\.]/', '', $k);
    if ($k === null) $k = '';
    if (strlen($k) > 64) { $k = substr(hash('sha256', $k), 0, 64); }
    return $k !== '' ? $k : null;
  }
}

if (!function_exists('idempo_get_key')) {
  /**
   * Key final lista para usar en índices y comparaciones.
   */
  function idempo_get_key(): ?string {
    return idempo_sanitize(idempo_raw_header());
  }
}

/* =========================================================================
 * Claim / Store
 * ========================================================================= */

if (!function_exists('idempo_claim_or_fail')) {
  /**
   * Busca respuesta persistida para (key, endpoint, user).
   * - Si existe y tiene status_code: responde y exit.
   * - Si no existe: inserta placeholder (INSERT IGNORE) y devuelve null.
   * - Si no hay key: devuelve null (no aplica idempotencia).
   *
   * @return ?array  (no se usa actualmente; reservado para futuros metadatos)
   */
  function idempo_claim_or_fail(mysqli $conn, string $endpoint): ?array {
    $key = idempo_get_key();
    if (!$key) return null;

    $uid = isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : 0;

    // ¿ya hay respuesta cerrada?
    if ($q = $conn->prepare(
      "SELECT status_code, response_json
         FROM request_log
        WHERE idempotency_key=? AND endpoint=? AND user_id=?
        LIMIT 1"
    )) {
      $q->bind_param("ssi", $key, $endpoint, $uid);
      $q->execute();

      // Compatibilidad sin mysqlnd: preferimos bind_result si no hay get_result()
      $status = null; $json = null;
      if (method_exists($q, 'get_result')) {
        $res = $q->get_result();
        if ($res && ($row = $res->fetch_assoc())) {
          $status = $row['status_code'];
          $json   = $row['response_json'];
        }
      } else {
        $q->bind_result($status, $json);
        $q->fetch();
      }
      $q->close();

      if ($status !== null) {
        http_response_code((int)$status);
        header('Content-Type: application/json; charset=UTF-8');
        echo $json ?? '{}';
        exit;
      }
    }

    // Placeholder (puede fallar si ya existe; IGNORE evita error y deja continuar)
    if ($ins = $conn->prepare(
      "INSERT IGNORE INTO request_log (idempotency_key, endpoint, user_id, created_at)
       VALUES (?,?,?,NOW())"
    )) {
      $ins->bind_param("ssi", $key, $endpoint, $uid);
      $ins->execute();
      $ins->close();
    }

    return null;
  }
}

if (!function_exists('idempo_store_and_reply')) {
  /**
   * Persiste la respuesta final de la operación idempotente y responde.
   * Si no hay key, sólo responde (no persiste en request_log).
   */
  function idempo_store_and_reply(mysqli $conn, string $endpoint, int $statusCode, array $payload): void {
    $key  = idempo_get_key();
    $uid  = isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : 0;
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    if ($key) {
      if ($up = $conn->prepare(
        "UPDATE request_log
            SET status_code=?, response_json=?, completed_at=NOW()
          WHERE idempotency_key=? AND endpoint=? AND user_id=?"
      )) {
        $up->bind_param("isssi", $statusCode, $json, $key, $endpoint, $uid);
        $up->execute();
        $up->close();
      }
    }

    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo $json;
    exit;
  }
}

if (!function_exists('idempo_gc')) {
  /**
   * Limpieza básica:
   *  - placeholders sin completar (>2 días)
   *  - completados muy antiguos (>30 días)
   */
  function idempo_gc(mysqli $conn): void {
    @$conn->query("DELETE FROM request_log WHERE completed_at IS NULL AND created_at < NOW() - INTERVAL 2 DAY");
    @$conn->query("DELETE FROM request_log WHERE completed_at IS NOT NULL AND completed_at < NOW() - INTERVAL 30 DAY");
  }
}

/* GC eventual (1%) si $conn está disponible en el ámbito global del endpoint */
if (mt_rand(1, 100) === 1 && isset($conn) && $conn instanceof mysqli) {
  idempo_gc($conn);
}
