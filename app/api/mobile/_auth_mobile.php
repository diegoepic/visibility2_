<?php
declare(strict_types=1);

function mobile_json_response(int $code, array $payload): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mobile_allow_cors(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if (
        preg_match('#^http://localhost:\d+$#', $origin) ||
        preg_match('#^http://127\.0\.0\.1:\d+$#', $origin) ||
        preg_match('#^https://([a-z0-9.-]+\.)?visibility\.cl$#i', $origin)
    ) {
        header("Access-Control-Allow-Origin: $origin");
        header('Vary: Origin');
    }

    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
}

function mobile_handle_options(): void {
    mobile_allow_cors();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function mobile_get_bearer_token(): ?string {
    $candidates = [
        $_SERVER['HTTP_AUTHORIZATION'] ?? '',
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '',
        $_SERVER['Authorization'] ?? '',
    ];

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (!empty($headers['Authorization'])) {
            $candidates[] = $headers['Authorization'];
        }
        if (!empty($headers['authorization'])) {
            $candidates[] = $headers['authorization'];
        }
    }

    foreach ($candidates as $header) {
        if ($header && preg_match('/Bearer\s+(.+)/i', $header, $m)) {
            return trim($m[1]);
        }
    }

    return null;
}

function mobile_require_auth(mysqli $conn): array {
    mobile_handle_options();

    $token = mobile_get_bearer_token();
    if (!$token) {
        mobile_json_response(401, [
            'ok' => false,
            'message' => 'Token no enviado'
        ]);
    }

    $tokenHash = hash('sha256', $token);

    $sql = "
        SELECT
            u.id,
            u.rut,
            u.nombre,
            u.apellido,
            u.email,
            u.usuario,
            u.fotoPerfil,
            u.id_perfil,
            u.id_empresa,
            u.id_division,
            p.nombre AS perfil_nombre,
            e.nombre AS empresa_nombre,
            t.id AS token_id
        FROM api_mobile_tokens t
        INNER JOIN usuario u ON u.id = t.user_id
        INNER JOIN perfil p  ON p.id = u.id_perfil
        INNER JOIN empresa e ON e.id = u.id_empresa
        WHERE t.token_hash = ?
          AND t.revoked_at IS NULL
          AND t.expires_at > NOW()
          AND u.activo = 1
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        mobile_json_response(500, [
            'ok' => false,
            'message' => 'Error preparando auth'
        ]);
    }

    $stmt->bind_param("s", $tokenHash);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows !== 1) {
        $stmt->close();
        mobile_json_response(401, [
            'ok' => false,
            'message' => 'Token inválido o expirado'
        ]);
    }

    $user = $res->fetch_assoc();
    $stmt->close();

    $upd = $conn->prepare("UPDATE api_mobile_tokens SET last_used_at = NOW() WHERE id = ?");
    if ($upd) {
        $tokenId = (int)$user['token_id'];
        $upd->bind_param("i", $tokenId);
        $upd->execute();
        $upd->close();
    }

    return $user;
}