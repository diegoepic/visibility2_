<?php
declare(strict_types=1);
/**
 * foto_hash.php — utilidades de detección de fotos duplicadas en campañas.
 *
 * Dos capas, ambas calculadas SERVER-SIDE sobre el archivo ya almacenado (los valores que envía el
 * cliente son falsificables, y el archivo guardado es idéntico para todas las rutas de ingesta):
 *
 *   - content_sha256: sha256 de los bytes del archivo → detecta el MISMO archivo reusado entre salas.
 *   - phash (dHash 64-bit, en HEX de 16 chars): hash perceptual robusto a recompresión/redimensión y
 *     a pequeñas diferencias → detecta re-tomas casi idénticas / reuso del mismo mueble. La distancia
 *     se calcula en SQL con BIT_COUNT(CONV(phash,16,10) ^ CONV(?,16,10)).
 *
 * Tolerante a fallos: si una imagen no se puede leer/decodificar, las funciones devuelven '' / null y
 * el llamador NO debe bloquear la subida (la detección es best-effort, nunca rompe la gestión).
 *
 * El acotado de las búsquedas a "misma campaña, distinto local" es el guard anti-falso-positivo: dos
 * fotos parecidas del mismo mueble en la MISMA sala (evidencia legítima de varios ángulos) no se marcan.
 */

if (!function_exists('v2_sha256_file')) {

/** sha256 del contenido del archivo. Devuelve '' si no se puede leer. */
function v2_sha256_file(string $path): string {
    if ($path === '' || !is_file($path)) return '';
    $h = @hash_file('sha256', $path);
    return is_string($h) ? $h : '';
}

/**
 * dHash perceptual de 64 bits, devuelto como HEX de 16 chars (para CHAR(16) / CONV(...,16,10)).
 * Redimensiona a 9x8 en escala de grises y compara cada pixel con su vecino derecho (gradiente
 * horizontal). Devuelve null si no se pudo decodificar la imagen.
 */
function v2_dhash(string $path): ?string {
    $W = 9; $H = 8; // 9x8 → 8 comparaciones por fila × 8 filas = 64 bits
    $gris = v2__muestra_gris($path, $W, $H);
    if ($gris === null || count($gris) < $W * $H) return null;

    $bits = '';
    for ($y = 0; $y < $H; $y++) {
        for ($x = 0; $x < $W - 1; $x++) {
            $izq = $gris[$y * $W + $x];
            $der = $gris[$y * $W + $x + 1];
            $bits .= ($izq > $der) ? '1' : '0';
        }
    }
    // 64 bits → 16 dígitos hex (exacto, sin gmp/bcmath).
    $hex = '';
    for ($i = 0; $i < 64; $i += 4) {
        $hex .= dechex((int)bindec(substr($bits, $i, 4)));
    }
    return str_pad($hex, 16, '0', STR_PAD_LEFT);
}

/**
 * Muestra de intensidades (0..255) de la imagen redimensionada a $W×$H en gris, row-major.
 * Prefiere Imagick (exportImagePixels); cae a GD. Devuelve null si falla.
 */
function v2__muestra_gris(string $path, int $W, int $H): ?array {
    if ($path === '' || !is_file($path)) return null;

    if (class_exists('Imagick')) {
        try {
            $im = new Imagick();
            $im->readImage($path);
            if ($im->getNumberImages() > 1) { $im = $im->coalesceImages(); $im->setIteratorIndex(0); }
            $im->setImageColorspace(Imagick::COLORSPACE_GRAY);
            $im->resizeImage($W, $H, Imagick::FILTER_LANCZOS, 1, false);
            $im->setImageDepth(8);
            $px = $im->exportImagePixels(0, 0, $W, $H, 'I', Imagick::PIXEL_CHAR);
            $im->clear(); $im->destroy();
            if (is_array($px) && count($px) >= $W * $H) {
                return array_map('intval', array_slice($px, 0, $W * $H));
            }
        } catch (Throwable $e) { /* cae a GD */ }
    }

    $info = @getimagesize($path);
    $mime = $info['mime'] ?? '';
    $src  = null;
    switch ($mime) {
        case 'image/jpeg': $src = @imagecreatefromjpeg($path); break;
        case 'image/png':  $src = @imagecreatefrompng($path);  break;
        case 'image/gif':  $src = @imagecreatefromgif($path);  break;
        case 'image/webp': if (function_exists('imagecreatefromwebp')) { $src = @imagecreatefromwebp($path); } break;
        default: return null;
    }
    if (!$src) return null;

    $dst = imagecreatetruecolor($W, $H);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $W, $H, imagesx($src), imagesy($src));
    imagedestroy($src);

    $out = [];
    for ($y = 0; $y < $H; $y++) {
        for ($x = 0; $x < $W; $x++) {
            $rgb = imagecolorat($dst, $x, $y);
            $r = ($rgb >> 16) & 0xFF; $g = ($rgb >> 8) & 0xFF; $b = $rgb & 0xFF;
            $out[] = (int)round(0.299 * $r + 0.587 * $g + 0.114 * $b);
        }
    }
    imagedestroy($dst);
    return $out;
}

/** Distancia de Hamming entre dos phash HEX (16 chars). -1 si alguno es inválido. */
function v2_hamming(?string $hexA, ?string $hexB): int {
    if (!is_string($hexA) || !is_string($hexB)) return -1;
    if (!ctype_xdigit($hexA) || !ctype_xdigit($hexB)) return -1;
    $a = str_pad($hexA, 16, '0', STR_PAD_LEFT);
    $b = str_pad($hexB, 16, '0', STR_PAD_LEFT);
    $dist = 0;
    for ($i = 0; $i < 16; $i++) {
        $x = hexdec($a[$i]) ^ hexdec($b[$i]); // 0..15
        // popcount de un nibble
        $x = ($x & 1) + (($x >> 1) & 1) + (($x >> 2) & 1) + (($x >> 3) & 1);
        $dist += $x;
    }
    return $dist;
}

/**
 * Busca un duplicado EXACTO (mismo content_sha256) en la MISMA campaña pero en OTRO local.
 * Devuelve ['id'=>..,'id_local'=>..] o null.
 */
function v2_buscar_dup_exacto(mysqli $conn, string $sha, int $idForm, int $idLocal): ?array {
    if ($sha === '' || $idForm <= 0) return null;
    $st = $conn->prepare(
        "SELECT fv.id, fv.id_local, l.codigo AS local_codigo, l.nombre AS local_nombre
           FROM fotoVisita fv
           LEFT JOIN local l ON l.id = fv.id_local
          WHERE fv.id_formulario = ? AND fv.content_sha256 = ? AND fv.id_local <> ?
          LIMIT 1"
    );
    if (!$st) return null;
    $st->bind_param('isi', $idForm, $sha, $idLocal);
    if (!$st->execute()) { $st->close(); return null; }
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

/**
 * Busca una foto PARECIDA (Hamming(phash) <= $umbral) en la MISMA campaña pero en OTRO local.
 * Devuelve ['id'=>..,'id_local'=>..,'dist'=>..] o null.
 */
function v2_buscar_parecida(mysqli $conn, ?string $phashHex, int $idForm, int $idLocal, int $umbral = 8): ?array {
    if ($phashHex === null || $phashHex === '' || !ctype_xdigit($phashHex) || $idForm <= 0) return null;
    $st = $conn->prepare(
        "SELECT id, id_local, BIT_COUNT(CONV(phash,16,10) ^ CONV(?,16,10)) AS dist
           FROM fotoVisita
          WHERE id_formulario = ? AND id_local <> ? AND phash IS NOT NULL
         HAVING dist <= ?
          ORDER BY dist ASC
          LIMIT 1"
    );
    if (!$st) return null;
    $st->bind_param('siii', $phashHex, $idForm, $idLocal, $umbral);
    if (!$st->execute()) { $st->close(); return null; }
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

/* ============================================================================================
 * Variantes para FOTOS DE ENCUESTA (tabla form_question_photo_meta).
 * Misma lógica que las de fotoVisita, pero la tabla no tiene id_material/kind; se acota por
 * id_formulario (columna agregada en scripts/13_fotos_duplicadas_encuesta.sql) + id_local distinto.
 * ============================================================================================ */

/** Duplicado EXACTO de encuesta: mismo content_sha256 en la misma campaña pero en OTRO local. */
function v2_encuesta_buscar_dup_exacto(mysqli $conn, string $sha, int $idForm, int $idLocal): ?array {
    if ($sha === '' || $idForm <= 0) return null;
    $st = $conn->prepare(
        "SELECT m.id, m.id_local, l.codigo AS local_codigo, l.nombre AS local_nombre
           FROM form_question_photo_meta m
           LEFT JOIN local l ON l.id = m.id_local
          WHERE m.id_formulario = ? AND m.content_sha256 = ? AND m.id_local <> ?
          LIMIT 1"
    );
    if (!$st) return null;
    $st->bind_param('isi', $idForm, $sha, $idLocal);
    if (!$st->execute()) { $st->close(); return null; }
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

/** Foto PARECIDA de encuesta: Hamming(phash) <= $umbral en la misma campaña pero en OTRO local. */
function v2_encuesta_buscar_parecida(mysqli $conn, ?string $phashHex, int $idForm, int $idLocal, int $umbral = 8): ?array {
    if ($phashHex === null || $phashHex === '' || !ctype_xdigit($phashHex) || $idForm <= 0) return null;
    $st = $conn->prepare(
        "SELECT m.id, m.id_local, BIT_COUNT(CONV(m.phash,16,10) ^ CONV(?,16,10)) AS dist
           FROM form_question_photo_meta m
          WHERE m.id_formulario = ? AND m.id_local <> ? AND m.phash IS NOT NULL
         HAVING dist <= ?
          ORDER BY dist ASC
          LIMIT 1"
    );
    if (!$st) return null;
    $st->bind_param('siii', $phashHex, $idForm, $idLocal, $umbral);
    if (!$st->execute()) { $st->close(); return null; }
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

/**
 * Marca una foto recién insertada como posible duplicada (dup_flag=1 + dup_ref_id) si existe un
 * duplicado EXACTO o PARECIDO en OTRA sala de la misma campaña. No bloquea — pensado para las rutas
 * batch (procesar_gestion_pruebas.php), donde abortar la gestión completa sería peor que marcar.
 */
function v2_marcar_dup_si_aplica(mysqli $conn, int $idFoto, ?string $sha, ?string $phashHex, int $idForm, int $idLocal): void {
    if ($idFoto <= 0 || $idForm <= 0) return;
    $ref = null;
    if ($sha !== null && $sha !== '') {
        $r = v2_buscar_dup_exacto($conn, $sha, $idForm, $idLocal);
        if ($r) { $ref = (int)$r['id']; }
    }
    if ($ref === null && $phashHex !== null && $phashHex !== '') {
        $r = v2_buscar_parecida($conn, $phashHex, $idForm, $idLocal, 8);
        if ($r) { $ref = (int)$r['id']; }
    }
    if ($ref === null) return;
    $u = $conn->prepare("UPDATE fotoVisita SET dup_flag = 1, dup_ref_id = ? WHERE id = ?");
    if ($u) { $u->bind_param('ii', $ref, $idFoto); @$u->execute(); $u->close(); }
}

/**
 * Registra un INTENTO de subir una foto duplicada EXACTA que fue bloqueado (409), para revisión en el
 * portal. Best-effort: si la tabla foto_duplicada_log no existe (migración 14 sin correr), no rompe.
 * $tipo = 'material' | 'encuesta'. $dupRefId/$dupRefLocal referencian la foto ORIGINAL.
 */
function v2_log_intento_duplicado(mysqli $conn, int $idForm, ?int $idLocal, int $idUsuario, string $tipo, ?string $sha, ?string $phash, ?int $dupRefId, ?int $dupRefLocal, ?int $idFormQuestion = null, ?string $material = null): void {
    if ($idForm <= 0 || $idUsuario <= 0) return;
    if (!in_array($tipo, ['material', 'encuesta'], true)) $tipo = 'material';
    // ¿La tabla tiene las columnas de contexto (migración 15)? (cache por request)
    static $hasCtx = null;
    if ($hasCtx === null) {
        $hasCtx = false;
        try { $r = $conn->query("SHOW COLUMNS FROM foto_duplicada_log LIKE 'id_form_question'");
              $hasCtx = ($r && $r->num_rows > 0); if ($r) $r->close(); } catch (Throwable $e) {}
    }
    try {
        if ($hasCtx) {
            $st = $conn->prepare(
                "INSERT INTO foto_duplicada_log
                   (id_formulario, id_local, id_usuario, tipo, content_sha256, phash, dup_ref_id, dup_ref_local, id_form_question, material)
                 VALUES (?,?,?,?,?,?,?,?,?,?)"
            );
            if (!$st) return;
            $st->bind_param('iiisssiiis', $idForm, $idLocal, $idUsuario, $tipo, $sha, $phash, $dupRefId, $dupRefLocal, $idFormQuestion, $material);
        } else {
            $st = $conn->prepare(
                "INSERT INTO foto_duplicada_log
                   (id_formulario, id_local, id_usuario, tipo, content_sha256, phash, dup_ref_id, dup_ref_local)
                 VALUES (?,?,?,?,?,?,?,?)"
            );
            if (!$st) return;
            $st->bind_param('iiisssii', $idForm, $idLocal, $idUsuario, $tipo, $sha, $phash, $dupRefId, $dupRefLocal);
        }
        @$st->execute();
        $st->close();
    } catch (Throwable $e) { /* tabla ausente u otro error → no romper la respuesta 409 */ }
}

} // fin guard function_exists
