<?php
ob_start();

ini_set('display_errors', 0);              // no mostrar errores en pantalla (evita romper headers)
ini_set('log_errors', 1);                  // loguear errores
ini_set('error_log', __DIR__ . '/descargar_excel_historico.error.log');
error_reporting(E_ALL);

ini_set('memory_limit', '1024M');
set_time_limit(0);

// Iniciar sesión si aún no está iniciada
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
// Liberar la sesión para evitar bloqueos
session_write_close();

// Conexión
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
mysqli_set_charset($conn, 'utf8mb4');

/**
 * Escape seguro: null -> '' y a string.
 */
if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

// 1) Validar parámetros
if (!isset($_GET['id']) || !filter_var($_GET['id'], FILTER_VALIDATE_INT)) {
    // limpiar buffer para no enviar headers parciales
    if (ob_get_length()) { ob_end_clean(); }
    die('ID de formulario inválido.');
}
$formulario_id = intval($_GET['id']);
$inline        = isset($_GET['inline']) && $_GET['inline'] === '1';

// Obtener nombre de campaña (para el nombre del archivo)
$stmt_form = $conn->prepare("SELECT nombre FROM formulario WHERE id = ? LIMIT 1");
$stmt_form->bind_param("i", $formulario_id);
$stmt_form->execute();
$stmt_form->bind_result($nombreForm);
if (!$stmt_form->fetch()) {
    if (ob_get_length()) { ob_end_clean(); }
    die("Formulario no encontrado.");
}
$stmt_form->close();

/* -----------------------------------------------------------------------------
   Funciones de obtención de datos (HÍBRIDO):
   - Base = TODOS los programados desde formularioQuestion (FQ)
   - Overlay = ÚLTIMA gestión por ejecución desde gestion_visita (GV)
   - + Extra = GV con id_formularioQuestion = 0 (solo auditoría / cancelado / pendiente)
----------------------------------------------------------------------------- */

function getCampaignData($idForm) {
    global $conn;
    $sql = "
        SELECT 
            f.id,
            f.nombre,
            f.fechaInicio,
            f.fechaTermino,
            e.nombre AS nombre_empresa,

            /* Programados: base FQ */
            (
               SELECT COUNT(DISTINCT l2.codigo)
               FROM formularioQuestion fq2
               JOIN local l2 ON l2.id = fq2.id_local
               WHERE fq2.id_formulario = f.id
            ) AS locales_programados,

            /* Visitados: al menos una GV con fecha válida o visita asociada (incluye id_fq = 0) */
            (
               SELECT COUNT(DISTINCT l3.codigo)
               FROM gestion_visita gv3
               JOIN local l3 ON l3.id = gv3.id_local
               LEFT JOIN visita v3 ON v3.id = gv3.visita_id
               WHERE gv3.id_formulario = f.id
                 AND (
                      (gv3.fecha_visita IS NOT NULL AND gv3.fecha_visita <> '0000-00-00 00:00:00')
                      OR v3.id IS NOT NULL
                 )
            ) AS locales_visitados,

            /* Implementados: desde GV */
            (
               SELECT COUNT(DISTINCT l4.codigo)
               FROM gestion_visita gv4
               JOIN local l4 ON l4.id = gv4.id_local
               WHERE gv4.id_formulario = f.id
                 AND (
                       IFNULL(gv4.valor_real,0) > 0
                       OR LOWER(gv4.estado_gestion) IN ('implementado_auditado','solo_implementado','solo_auditoria')
                     )
            ) AS locales_implementados

        FROM formulario f
        JOIN empresa e ON e.id = f.id_empresa
        WHERE f.id = {$idForm}
        GROUP BY f.id, f.nombre, f.fechaInicio, f.fechaTermino, e.nombre
    ";
    $res = mysqli_query($conn, $sql);
    if (!$res) {
        if (ob_get_length()) { ob_end_clean(); }
        die("Error en getCampaignData: " . mysqli_error($conn));
    }
    return mysqli_fetch_all($res, MYSQLI_ASSOC);
}

/**
 * Devuelve una fila por ejecución programada (FQ) con overlay de la
 * ÚLTIMA GV por id_formularioQuestion. Además agrega (UNION ALL) una fila
 * por local con la ÚLTIMA GV donde id_formularioQuestion = 0.
 * Primera columna: ID_EJECUCION (para GV sin FQ -> 0).
 */
function getLocalesDetails($idForm) {
    global $conn;

    $sql = "
        /* =========================
         * BLOQUE A: FQ + última GV por FQ
         * ========================= */
        SELECT 
            /* 1) ID_EJECUCION */
            fq.id                                        AS id_ejecucion,

            /* Identidad Local */
            l.id                                         AS idLocal,
            l.codigo                                     AS codigo_local,
            CASE
              WHEN l.nombre REGEXP '^[0-9]+' THEN SUBSTRING_INDEX(l.nombre, ' ', 1)
              ELSE CAST(l.codigo AS UNSIGNED)
            END                                          AS numero_local,

            /* Campaña */
            f.modalidad                                  AS modalidad,
            UPPER(f.nombre)                              AS nombreCampaña,
            DATE(f.fechaInicio)                          AS fechaInicio,
            DATE(f.fechaTermino)                         AS fechaTermino,

            /* FECHA VISITA / HORA */
            DATE(
              COALESCE(
                NULLIF(gv_last.fecha_visita,'0000-00-00 00:00:00'),
                v_last.fecha_fin,
                v_last.fecha_inicio
              )
            )                                            AS fechaVisita,
            TIME(
              COALESCE(
                NULLIF(gv_last.fecha_visita,'0000-00-00 00:00:00'),
                v_last.fecha_fin,
                v_last.fecha_inicio
              )
            )                                            AS hora,

            /* PLANIFICADA (FQ) */
            DATE(fq.fechaPropuesta)                      AS fechaPropuesta,

            /* Datos del local */
            UPPER(l.nombre)                              AS nombre_local,
            UPPER(l.direccion)                           AS direccion_local,
            UPPER(cm.comuna)                             AS comuna,
            UPPER(re.region)                             AS region,
            UPPER(cu.nombre)                             AS cuenta,
            UPPER(ca.nombre)                             AS cadena,

            /* MATERIAL: si GV trae id_material, usar catálogo; si no, FQ.material */
            UPPER(COALESCE(m.nombre, fq.material))       AS material,

            /* JEFE VENTA */
            UPPER(jv.nombre)                             AS jefeVenta,

            /* PROPUESTO (FQ) */
            fq.valor_propuesto                           AS valor_propuesto,

            /* CANTIDAD EJECUTADA (GV) */
            gv_last.valor_real                           AS valor,

            /* OBSERVACIÓN (prioriza GV) */
            UPPER(COALESCE(gv_last.observacion, fq.observacion, '')) AS observacion,

            /* GESTIONADO POR: usuario de la última GV o el asignado en FQ */
            UPPER(COALESCE(u_gv.usuario, u_fq.usuario))  AS gestionado_por,

            /* ESTADO VISITA */
            CASE
              WHEN (gv_last.id IS NOT NULL AND (gv_last.fecha_visita IS NOT NULL AND gv_last.fecha_visita <> '0000-00-00 00:00:00'))
                   OR v_last.id IS NOT NULL
              THEN 'VISITADO'
              ELSE 'NO VISITADO'
            END                                          AS ESTADO_VISTA,

            /* ESTADO ACTIVIDAD */
            CASE
              WHEN gv_last.id IS NULL                     THEN 'NO IMPLEMENTADO'
              WHEN IFNULL(gv_last.valor_real,0) > 0       THEN 'IMPLEMENTADO'
              WHEN LOWER(gv_last.estado_gestion) = 'solo_implementado'                THEN 'IMPLEMENTADO'
              WHEN LOWER(gv_last.estado_gestion) IN ('solo_auditoria','solo_auditado') THEN 'AUDITORIA'
              WHEN LOWER(gv_last.estado_gestion) = 'retiro'                            THEN 'RETIRO'
              WHEN LOWER(gv_last.estado_gestion) = 'entrega'                           THEN 'ENTREGA'
              WHEN LOWER(gv_last.estado_gestion) = 'implementado_auditado'             THEN 'IMPLEMENTADO/AUDITADO'
              ELSE 'NO IMPLEMENTADO'
            END                                          AS ESTADO_ACTIVIDAD,

            /* MOTIVO (prioriza GV) */
            UPPER(
              REPLACE(
                TRIM(
                  COALESCE(
                    NULLIF(gv_last.motivo_no_implementacion,''),
                    NULLIF(
                      TRIM(
                        SUBSTRING_INDEX(
                          REPLACE(COALESCE(gv_last.observacion,''),'|','-'),
                          '-',
                          1
                        )
                      ), ''
                    ),
                    NULLIF(gv_last.estado_gestion,''),
                    ''
                  )
                )
              , '_', ' ')
            )                                            AS MOTIVO

        FROM formulario f
        INNER JOIN formularioQuestion fq   ON fq.id_formulario = f.id
        INNER JOIN local l                 ON l.id = fq.id_local

        /* Última GV por ejecución (LEFT JOIN: puede no existir) */
        LEFT JOIN (
            SELECT gv1.*
            FROM gestion_visita gv1
            JOIN (
                SELECT 
                    id_formularioQuestion,
                    MAX(
                      COALESCE(
                        NULLIF(fecha_visita,'0000-00-00 00:00:00'),
                        created_at
                      )
                    ) AS max_ts
                FROM gestion_visita
                WHERE id_formulario = {$idForm}
                  AND id_formularioQuestion IS NOT NULL
                  AND id_formularioQuestion <> 0
                GROUP BY id_formularioQuestion
            ) t ON t.id_formularioQuestion = gv1.id_formularioQuestion
               AND COALESCE(
                     NULLIF(gv1.fecha_visita,'0000-00-00 00:00:00'),
                     gv1.created_at
                   ) = t.max_ts
            WHERE gv1.id_formulario = {$idForm}
        ) gv_last ON gv_last.id_formularioQuestion = fq.id

        LEFT JOIN visita  v_last ON v_last.id = gv_last.visita_id
        LEFT JOIN material m     ON m.id      = gv_last.id_material
        LEFT JOIN usuario u_gv   ON u_gv.id   = gv_last.id_usuario
        LEFT JOIN usuario u_fq   ON u_fq.id   = fq.id_usuario
        LEFT JOIN jefe_venta jv  ON jv.id     = l.id_jefe_venta

        INNER JOIN cuenta cu  ON cu.id = l.id_cuenta
        INNER JOIN cadena ca  ON ca.id = l.id_cadena
        INNER JOIN comuna cm  ON cm.id = l.id_comuna
        INNER JOIN region re  ON re.id = cm.id_region

        WHERE f.id = {$idForm}

        UNION ALL

        /* ===========================================
         * BLOQUE B: Última GV sin FQ (id_fq = 0 / NULL)
         * Una fila por (id_formulario, id_local)
         * =========================================== */
        SELECT
            /* ID_EJECUCION inexistente */
            0                                           AS id_ejecucion,

            /* Identidad Local */
            l2.id                                       AS idLocal,
            l2.codigo                                   AS codigo_local,
            CASE
              WHEN l2.nombre REGEXP '^[0-9]+' THEN SUBSTRING_INDEX(l2.nombre, ' ', 1)
              ELSE CAST(l2.codigo AS UNSIGNED)
            END                                         AS numero_local,

            /* Campaña */
            f2.modalidad                                AS modalidad,
            UPPER(f2.nombre)                            AS nombreCampaña,
            DATE(f2.fechaInicio)                        AS fechaInicio,
            DATE(f2.fechaTermino)                       AS fechaTermino,

            /* FECHA VISITA / HORA (GV0) */
            DATE(
              COALESCE(
                NULLIF(gv0.fecha_visita,'0000-00-00 00:00:00'),
                v0.fecha_fin,
                v0.fecha_inicio
              )
            )                                           AS fechaVisita,
            TIME(
              COALESCE(
                NULLIF(gv0.fecha_visita,'0000-00-00 00:00:00'),
                v0.fecha_fin,
                v0.fecha_inicio
              )
            )                                           AS hora,

            /* PLANIFICADA: no existe (no hay FQ representativo) */
            NULL                                        AS fechaPropuesta,

            /* Datos del local */
            UPPER(l2.nombre)                            AS nombre_local,
            UPPER(l2.direccion)                         AS direccion_local,
            UPPER(cm2.comuna)                           AS comuna,
            UPPER(re2.region)                           AS region,
            UPPER(cu2.nombre)                           AS cuenta,
            UPPER(ca2.nombre)                           AS cadena,

            /* MATERIAL: si trae id_material, catálogo; si no, 'N/A' */
            UPPER(COALESCE(m2.nombre, 'N/A'))           AS material,

            /* JEFE VENTA */
            UPPER(jv2.nombre)                           AS jefeVenta,

            /* PROPUESTO: no aplica */
            NULL                                        AS valor_propuesto,

            /* CANTIDAD EJECUTADA (GV0) */
            gv0.valor_real                              AS valor,

            /* OBSERVACIÓN (GV0) */
            UPPER(COALESCE(gv0.observacion, ''))        AS observacion,

            /* GESTIONADO POR: usuario de GV0 */
            UPPER(u_gv0.usuario)                        AS gestionado_por,

            /* ESTADO VISITA */
            CASE
              WHEN (gv0.fecha_visita IS NOT NULL AND gv0.fecha_visita <> '0000-00-00 00:00:00')
                   OR v0.id IS NOT NULL
              THEN 'VISITADO'
              ELSE 'NO VISITADO'
            END                                         AS ESTADO_VISTA,

            /* ESTADO ACTIVIDAD (solo GV0) */
            CASE
              WHEN IFNULL(gv0.valor_real,0) > 0                           THEN 'IMPLEMENTADO'
              WHEN LOWER(gv0.estado_gestion) = 'solo_implementado'        THEN 'IMPLEMENTADO'
              WHEN LOWER(gv0.estado_gestion) IN ('solo_auditoria','solo_auditado','pendiente','cancelado')
                   THEN UPPER(LOWER(gv0.estado_gestion))  /* AUDITORIA / PENDIENTE / CANCELADO */
              WHEN LOWER(gv0.estado_gestion) = 'retiro'                    THEN 'RETIRO'
              WHEN LOWER(gv0.estado_gestion) = 'entrega'                   THEN 'ENTREGA'
              WHEN LOWER(gv0.estado_gestion) = 'implementado_auditado'     THEN 'IMPLEMENTADO/AUDITADO'
              ELSE 'NO IMPLEMENTADO'
            END                                         AS ESTADO_ACTIVIDAD,

            /* MOTIVO (GV0) */
            UPPER(
              REPLACE(
                TRIM(
                  COALESCE(
                    NULLIF(gv0.motivo_no_implementacion,''),
                    NULLIF(
                      TRIM(
                        SUBSTRING_INDEX(
                          REPLACE(COALESCE(gv0.observacion,''),'|','-'),
                          '-',
                          1
                        )
                      ), ''
                    ),
                    NULLIF(gv0.estado_gestion,''),
                    ''
                  )
                )
              , '_', ' ')
            )                                           AS MOTIVO

        FROM (
            /* última GV con id_fq 0/NULL por (id_formulario, id_local) */
            SELECT gv1.*
            FROM gestion_visita gv1
            JOIN (
                SELECT 
                    id_formulario,
                    id_local,
                    MAX(
                      COALESCE(
                        NULLIF(fecha_visita,'0000-00-00 00:00:00'),
                        created_at
                      )
                    ) AS max_ts
                FROM gestion_visita
                WHERE id_formulario = {$idForm}
                  AND (id_formularioQuestion IS NULL OR id_formularioQuestion = 0)
                GROUP BY id_formulario, id_local
            ) t ON t.id_formulario = gv1.id_formulario
               AND t.id_local      = gv1.id_local
               AND COALESCE(
                     NULLIF(gv1.fecha_visita,'0000-00-00 00:00:00'),
                     gv1.created_at
                   ) = t.max_ts
            WHERE gv1.id_formulario = {$idForm}
              AND (gv1.id_formularioQuestion IS NULL OR gv1.id_formularioQuestion = 0)
        ) gv0
        JOIN formulario f2 ON f2.id = gv0.id_formulario
        JOIN local     l2  ON l2.id = gv0.id_local
        LEFT JOIN visita   v0  ON v0.id = gv0.visita_id
        LEFT JOIN material m2  ON m2.id = gv0.id_material
        LEFT JOIN usuario  u_gv0 ON u_gv0.id = gv0.id_usuario
        LEFT JOIN jefe_venta jv2 ON jv2.id = l2.id_jefe_venta
        JOIN cuenta cu2 ON cu2.id = l2.id_cuenta
        JOIN cadena ca2 ON ca2.id = l2.id_cadena
        JOIN comuna cm2 ON cm2.id = l2.id_comuna
        JOIN region re2 ON re2.id = cm2.id_region

        WHERE f2.id = {$idForm}
    ";

    /* Envolvemos el UNION para poder ordenar por alias */
    $sql_wrapped = "
        SELECT * FROM (
            {$sql}
        ) AS x
        ORDER BY codigo_local,
                 COALESCE(fechaVisita, '1900-01-01'),
                 COALESCE(hora, '00:00:00')
    ";

    $res = mysqli_query($conn, $sql_wrapped);
    if (!$res) {
        if (ob_get_length()) { ob_end_clean(); }
        die("Error en getLocalesDetails: " . mysqli_error($conn));
    }
    $rows = mysqli_fetch_all($res, MYSQLI_ASSOC);

    // Normalizar numero_local si faltó
    foreach ($rows as &$loc) {
        if (empty($loc['numero_local'])) {
            $loc['numero_local'] = preg_replace('/\D+/', '', (string)($loc['codigo_local'] ?? ''));
        }
    }
    unset($loc);

    return $rows;
}

function getEncuestaPivot($idForm) {
    global $conn;
    // Preguntas en orden
    $allQuestions = [];
    $qry = "
        SELECT question_text
        FROM form_questions
        WHERE id_formulario = {$idForm}
        ORDER BY sort_order
    ";
    $resQ = mysqli_query($conn, $qry)
        or die("Error al obtener preguntas: " . mysqli_error($conn));
    while ($rQ = mysqli_fetch_assoc($resQ)) {
        $allQuestions[] = $rQ['question_text'];
    }

    // Respuestas pivot
    $sql = "
        SELECT
            f.id                                        AS idCampana,
            UPPER(f.nombre)                             AS nombreCampana,
            UPPER(l.codigo)                             AS codigo_local,
            UPPER(
              CASE
                WHEN l.nombre REGEXP '^[0-9]+'
                THEN SUBSTRING_INDEX(l.nombre, ' ', 1)
                ELSE ''
              END
            )                                           AS numero_local,
            UPPER(l.nombre)                             AS nombre_local,
            UPPER(l.direccion)                          AS direccion_local,
            UPPER(cu.nombre)                            AS cuenta,
            UPPER(ca.nombre)                            AS cadena,
            UPPER(cm.comuna)                            AS comuna,
            UPPER(re.region)                            AS region,
            UPPER(u.usuario)                            AS usuario,
            DATE(fqr.created_at)                        AS fechaVisita,
            UPPER(fp.question_text)                     AS question_text,
            UPPER(
              GROUP_CONCAT(
                DISTINCT fqr.answer_text
                ORDER BY fp.sort_order
                SEPARATOR '; '
              )
            )                                           AS concat_answers,
            UPPER(
              GROUP_CONCAT(
                DISTINCT CASE WHEN fqr.valor <> '0.00' THEN fqr.valor END
                ORDER BY fp.sort_order
                SEPARATOR '; '
              )
            )                                           AS concat_valores
        FROM formulario f
        JOIN form_questions fp           ON fp.id_formulario = f.id
        JOIN form_question_responses fqr ON fqr.id_form_question = fp.id
        JOIN usuario u                   ON u.id = fqr.id_usuario
        JOIN local l                     ON l.id = fqr.id_local
        JOIN cuenta cu                   ON cu.id = l.id_cuenta
        JOIN cadena ca                   ON ca.id = l.id_cadena
        JOIN comuna cm                   ON cm.id = l.id_comuna
        JOIN region re                   ON re.id   = cm.id_region
        WHERE f.id = {$idForm}
        GROUP BY
            l.codigo,
            DATE(fqr.created_at),
            fp.question_text
        ORDER BY l.codigo, fp.sort_order
    ";
    $res = mysqli_query($conn, $sql)
        or die("Error en getEncuestaPivot: " . mysqli_error($conn));

    // Agrupar por Local + Fecha
    $grouped = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $key = $row['codigo_local'] . '_' . $row['fechaVisita'];
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'ID CAMPAÑA'     => $row['idCampana'],
                'CODIGO LOCAL'   => $row['codigo_local'],
                'N° LOCAL'       => $row['numero_local'],
                'NOMBRE CAMPAÑA' => $row['nombreCampana'],
                'LOCAL'          => $row['nombre_local'],
                'DIRECCION'      => $row['direccion_local'],
                'CUENTA'         => $row['cuenta'],
                'CADENA'         => $row['cadena'],
                'COMUNA'         => $row['comuna'],
                'REGION'         => $row['region'],
                'USUARIO'        => $row['usuario'],
                'FECHA VISITA'   => $row['fechaVisita'],
                'questions'      => []
            ];
        }
        $q = $row['question_text'];
        $grouped[$key]['questions'][$q] = [
            'answer' => $row['concat_answers'] ?? '',
            'valor'  => $row['concat_valores'] ?? ''
        ];
    }

    // Pivotear columnas de encuesta
    $final = [];
    foreach ($grouped as $g) {
        $rowOut = [
            'ID CAMPAÑA'     => $g['ID CAMPAÑA'],
            'CODIGO LOCAL'   => $g['CODIGO LOCAL'],
            'N° LOCAL'       => $g['N° LOCAL'],
            'NOMBRE CAMPAÑA' => $g['NOMBRE CAMPAÑA'],
            'CUENTA'         => $g['CUENTA'],
            'CADENA'         => $g['CADENA'],
            'LOCAL'          => $g['LOCAL'],
            'DIRECCION'      => $g['DIRECCION'],
            'COMUNA'         => $g['COMUNA'],
            'REGION'         => $g['REGION'],
            'USUARIO'        => $g['USUARIO'],
            'FECHA VISITA'   => $g['FECHA VISITA']
        ];
        foreach ($allQuestions as $q) {
            if (isset($g['questions'][$q])) {
                $rowOut[$q]            = $g['questions'][$q]['answer'];
                $rowOut[$q . '_valor'] = $g['questions'][$q]['valor'];
            } else {
                $rowOut[$q]            = '';
                $rowOut[$q . '_valor'] = '';
            }
        }
        $final[] = $rowOut;
    }

    // Eliminar columnas “_valor” totalmente vacías
    $valorCols = [];
    foreach ($final as $r) {
        foreach ($r as $c => $v) {
            if (strpos($c, '_valor') !== false) {
                $valorCols[$c] = $valorCols[$c] ?? false;
                if (trim((string)$v) !== '') $valorCols[$c] = true;
            }
        }
    }
    foreach ($final as &$r) {
        foreach ($valorCols as $c => $has) {
            if (!$has) unset($r[$c]);
        }
    }
    unset($r);

    return $final;
}

/* -----------------------------------------------------------------------------
   Generador de HTML/XLS (mismas columnas + primera columna ID_EJECUCION)
----------------------------------------------------------------------------- */

function generarExcel($campaign, $locales, $encuesta, $archivo = null, $inline = false) {
    // Si es inline, corregir URLs relativas de imágenes en Encuesta
    if ($inline) {
        foreach ($encuesta as &$fila) {
            foreach ($fila as $col => $valor) {
                $valorStr = (string)($valor ?? '');
                if (preg_match('#^/?visibility2/#', $valorStr)) {
                    $ruta = ltrim($valorStr, '/');
                    $fila[$col] = 'https://visibility.cl/' . $ruta;
                }
            }
        }
        unset($fila);
    }

    $html = <<<HTML
<html>
  <head>
    <meta charset="UTF-8">
    <link rel="stylesheet"
          href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
      table { width: 100%; table-layout: auto; border-collapse: collapse; }
      th, td { border: 1px solid #666; padding: 4px; white-space: normal; word-wrap: break-word; vertical-align: top; }
      img.inline-img { max-width: 120px; max-height: 120px; cursor: pointer; }
    </style>
  </head>
  <body>
HTML;

    /* —– Detalle de Locales —— */
    if (!empty($locales)) {
        $html .= "<b>Detalle de Locales</b>
                  <table border='1' 
                         style='border-collapse:collapse; table-layout:auto; font-size:9pt;'>
                    <tr>
                      <th>ID_EJECUCION</th>
                      <th>ID LOCAL</th>
                      <th>CODIGO</th>
                      <th>N° LOCAL</th>
                      <th>CAMPAÑA</th>
                      <th>CUENTA</th>
                      <th>CADENA</th>
                      <th>LOCAL</th>
                      <th>DIRECCION</th>
                      <th>COMUNA</th>
                      <th>REGION</th>
                      <th>JEFE VENTA</th>
                      <th>USUARIO</th>
                      <th>FECHA PLANIFICADA</th>
                      <th>FECHA VISITA</th>
                      <th>HORA</th>
                      <th>ESTADO VISITA</th>
                      <th>ESTADO ACTIVIDAD</th>
                      <th>MOTIVO</th>
                      <th>MATERIAL</th>
                      <th>CANTIDAD MATERIAL EJECUTADO</th>
                      <th>MATERIAL PROPUESTO</th>
                      <th>OBSERVACION</th>
                    </tr>";

        foreach ($locales as $l) {
            $fechaPropuesta = ($l['fechaPropuesta'] !== null && $l['fechaPropuesta'] !== '0000-00-00')
                              ? $l['fechaPropuesta'] : '-';
            $fechaVisita    = ($l['fechaVisita'] !== null && $l['fechaVisita'] !== '0000-00-00')
                              ? $l['fechaVisita'] : '-';

            $html .= "<tr>
                        <td>" . e($l['id_ejecucion']) . "</td>
                        <td>" . e($l['idLocal']) . "</td>
                        <td>" . e($l['codigo_local']) . "</td>
                        <td>" . e($l['numero_local']) . "</td>
                        <td>" . e($l['nombreCampaña']) . "</td>
                        <td>" . e($l['cuenta']) . "</td>
                        <td>" . e($l['cadena']) . "</td>
                        <td>" . e($l['nombre_local']) . "</td>
                        <td>" . e($l['direccion_local']) . "</td>
                        <td>" . e($l['comuna']) . "</td>
                        <td>" . e($l['region']) . "</td>
                        <td>" . e($l['jefeVenta']) . "</td>
                        <td>" . e($l['gestionado_por']) . "</td>
                        <td>{$fechaPropuesta}</td>
                        <td>{$fechaVisita}</td>
                        <td>" . e($l['hora']) . "</td>
                        <td>" . e($l['ESTADO_VISTA']) . "</td>
                        <td>" . e($l['ESTADO_ACTIVIDAD']) . "</td>
                        <td>" . e($l['MOTIVO']) . "</td>
                        <td>" . e($l['material']) . "</td>
                        <td>" . e($l['valor']) . "</td>
                        <td>" . e($l['valor_propuesto']) . "</td>
                        <td>" . e($l['observacion']) . "</td>
                      </tr>";
        }

        $html .= "</table><br>";
    }

    /* —– Encuesta Pivot —— */
    $html .= "<b>Encuesta</b>
              <table border='1' 
                     style='border-collapse:collapse; table-layout:auto; font-size:9pt;'>
                <tr>";
    if (!empty($encuesta)) {
        $keys = array_keys($encuesta[0]);
        foreach ($keys as $k) {
            $html .= "<th>" . e($k) . "</th>";
        }
    }
    $html .= "</tr>";
    foreach ($encuesta as $row) {
        $html .= "<tr>";
        foreach ($row as $v) {
            $vs = (string)($v ?? '');
            if ($inline && preg_match('#^https?://#', $vs)) {
                $urls  = preg_split('/\s*;\s*/', $vs);
                $first = trim($urls[0] ?? '');
                if (preg_match('#\.(jpe?g|png|gif|webp)$#i', $first)) {
                    $html .= "<td><img class=\"inline-img\" src=\"" 
                           . e($first) 
                           . "\" data-toggle=\"modal\" data-target=\"#imgModal\" data-src=\"" 
                           . e($first) 
                           . "\"></td>";
                } else {
                    $html .= "<td>" . e($vs) . "</td>";
                }
            } else {
                $html .= "<td>" . e($vs) . "</td>";
            }
        }
        $html .= "</tr>";
    }
    $html .= "</table>";

    // Modal para zoom de imágenes (solo inline)
    if ($inline) {
        $html .= <<<HTML
<!-- Modal -->
<div class="modal fade" id="imgModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 bg-transparent">
      <div class="modal-body p-0 text-center">
        <img id="modalImg" src="" class="img-fluid rounded">
      </div>
    </div>
  </div>
</div>
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.bundle.min.js"></script>
<script>
  $('#imgModal').on('show.bs.modal', function(e) {
    var src = $(e.relatedTarget).data('src');
    $('#modalImg').attr('src', src);
  });
</script>
</body></html>
HTML;
    } else {
        $html .= "</body></html>";
    }

    if ($inline) {
        return $html;
    }

    // Descargar como .xls (HTML compatible con Excel)
    $content = "\xEF\xBB\xBF" . $html;
    $content = strtr(
        $content,
        [
            'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U',
            'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u',
            'Ñ'=>'N','ñ'=>'n',
            'Ü'=>'U','ü'=>'u'
        ]
    );

    // limpiar cualquier salida previa antes de headers
    if (ob_get_length()) { ob_end_clean(); }

    header("Cache-Control: no-cache, must-revalidate");
    header("Pragma: no-cache");
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header("Expires: 0");
    header("Content-Disposition: attachment; filename={$archivo}");
    header("Content-Length: " . strlen($content));
    echo $content;
    exit();
}

/* -----------------------------------------------------------------------------
   Punto de entrada
----------------------------------------------------------------------------- */

$campaignData   = getCampaignData($formulario_id);
$localesDetails = getLocalesDetails($formulario_id);     // HÍBRIDO (FQ + última GV + GV id_fq=0)
$encuestaPivot  = getEncuestaPivot($formulario_id);

if (empty($campaignData) && empty($localesDetails) && empty($encuestaPivot)) {
    if (ob_get_length()) { ob_end_clean(); }
    die("No se encontraron datos para la campaña.");
}

// Preparar nombre de archivo
$nombreCampaña = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$nombreForm);
$archivo = "Historico_{$nombreCampaña}_" . date('Y-m-d_His') . ".xls";

// Mostrar inline o descargar
if ($inline) {
    // limpiar buffer antes de imprimir HTML inline
    if (ob_get_length()) { ob_end_clean(); }
    echo generarExcel($campaignData, $localesDetails, $encuestaPivot, null, true);
    exit();
}

generarExcel($campaignData, $localesDetails, $encuestaPivot, $archivo);
