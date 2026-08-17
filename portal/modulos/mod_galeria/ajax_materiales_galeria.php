<?php
/**
 * Lista de materiales para el filtro de la vista "Fotos Implementación" (mod_galeria_ipt.php).
 *
 * Devuelve JSON: [ { "material": "AFICHE", "divisiones": "Coca-Cola, Nestlé" }, ... ]
 *
 * Por qué se filtra por NOMBRE y no por material.id:
 *   La FK fotoVisita.id_material sólo viene poblada en ~65% de las fotos de esta vista; el
 *   resto sólo tiene el texto libre formularioQuestion.material. Filtrar por la FK dejaría
 *   fuera un tercio de las fotos, así que la clave es el nombre normalizado.
 *
 * De dónde sale la división:
 *   Del catálogo (material.id_division) cuando la foto trae la FK; si no la trae, se usa la
 *   división de la campaña (formulario.id_division). Un mismo nombre puede existir en varias
 *   divisiones (AFICHE está en 6), por eso se devuelven agrupadas en un solo texto.
 *
 * El WHERE replica el de la rama 'implementacion' de ajax_galeria_table.php para que la lista
 * ofrezca exactamente los materiales que la tabla va a mostrar.
 */
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($usuario_id)) {
    echo json_encode([]);
    exit;
}

$divisionLogin     = (int)($_SESSION['division_id'] ?? 0);
$division          = (int)($_GET['division'] ?? $divisionLogin);
$subdivision       = (int)($_GET['subdivision'] ?? 0);
$region            = (int)($_GET['region'] ?? 0);
$zona              = (int)($_GET['zona'] ?? 0);
$distrito          = (int)($_GET['distrito'] ?? 0);
$comuna            = (int)($_GET['comuna'] ?? 0);
$usuarioFiltro     = (int)($_GET['usuario'] ?? 0);
$jefeVentaFiltro   = (int)($_GET['jefe_venta'] ?? 0);
$codigoLocalFiltro = trim($_GET['codigo_local'] ?? '');
$start_date        = trim($_GET['start_date'] ?? '');
$end_date          = trim($_GET['end_date'] ?? '');

// Scoping por división: sólo MC (división 1) puede consultar otras divisiones; al resto se le
// fija la de su sesión aunque manipulen el GET.
if ($divisionLogin !== 1 && $divisionLogin > 0) {
    $division = $divisionLogin;
}

// Mismo default que la tabla: sin fechas, se acota a hoy. Además de mantener la lista
// coherente con lo que se va a mostrar, evita barrer todo el histórico de fotos.
if ($start_date === '' && $end_date === '') {
    $start_date = date('Y-m-d');
    $end_date   = $start_date;
}

$where  = "1=1";
$params = [];
$types  = "";

if ($division        > 0) { $where .= " AND f.id_division    = ?"; $types .= "i"; $params[] = $division; }
if ($subdivision     > 0) { $where .= " AND f.id_subdivision = ?"; $types .= "i"; $params[] = $subdivision; }
if ($region          > 0) { $where .= " AND r.id             = ?"; $types .= "i"; $params[] = $region; }
if ($zona            > 0) { $where .= " AND z.id             = ?"; $types .= "i"; $params[] = $zona; }
if ($distrito        > 0) { $where .= " AND d.id             = ?"; $types .= "i"; $params[] = $distrito; }
if ($comuna          > 0) { $where .= " AND co.id            = ?"; $types .= "i"; $params[] = $comuna; }
if ($usuarioFiltro   > 0) { $where .= " AND fv.id_usuario    = ?"; $types .= "i"; $params[] = $usuarioFiltro; }
if ($jefeVentaFiltro > 0) { $where .= " AND l.id_jefe_venta  = ?"; $types .= "i"; $params[] = $jefeVentaFiltro; }

if ($codigoLocalFiltro !== '') {
    $where  .= " AND l.codigo LIKE ?";
    $types  .= "s";
    $params[] = '%' . $codigoLocalFiltro . '%';
}

if ($start_date !== '') {
    $where  .= " AND fq.fechaVisita >= ?";
    $types  .= "s";
    $params[] = $start_date . ' 00:00:00';
}

if ($end_date !== '') {
    $where  .= " AND fq.fechaVisita <= ?";
    $types  .= "s";
    $params[] = $end_date . ' 23:59:59';
}

/*
 * Dos detalles de MySQL que hay que respetar acá:
 *
 * 1) NULLIF sobre m.id_division: el catálogo usa 0 (no NULL) para "sin división", así que sin
 *    el NULLIF el COALESCE se quedaría con el 0 y nunca caería a la división de la campaña.
 *
 * 2) El alias NO puede llamarse `material`: en GROUP BY, MySQL resuelve primero las columnas
 *    del FROM y después los alias del SELECT, así que `GROUP BY material` se engancharía a la
 *    columna fq.material (el texto crudo) en vez de a la expresión normalizada. De ahí
 *    `material_nombre`, y el GROUP BY repite la expresión completa para no depender de eso.
 */
$sql = "
    SELECT
        UPPER(TRIM(COALESCE(m.nombre, fq.material))) AS material_nombre,
        -- Con DISTINCT, MySQL exige que el ORDER BY repita la MISMA expresión del argumento;
        -- si se ordena por dv.nombre a secas, aborta la consulta.
        GROUP_CONCAT(DISTINCT COALESCE(dv.nombre, 'Sin división')
                     ORDER BY COALESCE(dv.nombre, 'Sin división')
                     SEPARATOR ', ')  AS divisiones,
        COUNT(*) AS total_fotos
    FROM formularioQuestion fq
    INNER JOIN formulario f   ON f.id  = fq.id_formulario
    INNER JOIN fotoVisita fv  ON fv.id_formularioQuestion = fq.id
    INNER JOIN local l        ON l.id  = fq.id_local
    LEFT  JOIN comuna co      ON co.id = l.id_comuna
    LEFT  JOIN region r       ON r.id  = co.id_region
    LEFT  JOIN distrito d     ON d.id  = l.id_distrito
    LEFT  JOIN zona z         ON z.id  = d.id_zona
    LEFT  JOIN jefe_venta jv  ON jv.id = l.id_jefe_venta
    INNER JOIN cadena c       ON c.id  = l.id_cadena
    INNER JOIN cuenta ct      ON ct.id = l.id_cuenta
    INNER JOIN usuario u      ON u.id  = fv.id_usuario
    LEFT  JOIN material m     ON m.id  = fv.id_material
    LEFT  JOIN division_empresa dv
           ON dv.id = COALESCE(NULLIF(m.id_division, 0), f.id_division)
    WHERE {$where}
      AND fq.fechaVisita IS NOT NULL
      AND COALESCE(TRIM(COALESCE(m.nombre, fq.material)), '') <> ''
    GROUP BY UPPER(TRIM(COALESCE(m.nombre, fq.material)))
    ORDER BY material_nombre ASC
";

$out  = [];
$stmt = $conn->prepare($sql);
if ($stmt) {
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $out[] = [
            'material'   => $r['material_nombre'],
            'divisiones' => $r['divisiones'] ?? '',
            'total'      => (int)$r['total_fotos'],
        ];
    }
    $stmt->close();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
