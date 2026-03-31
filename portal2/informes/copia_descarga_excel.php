<?php
ob_clean();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ini_set('memory_limit', '1024M');
set_time_limit(0);


// Iniciar sesión si aún no está iniciada
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Liberar la sesión para evitar bloqueos
session_write_close();

// Incluir el archivo de conexión a la base de datos
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

// 1) Validar parámetros
if (!isset($_GET['id']) || !filter_var($_GET['id'], FILTER_VALIDATE_INT)) {
    die('ID de formulario inválido.');
}
$formulario_id = intval($_GET['id']);
$inline        = isset($_GET['inline']) && $_GET['inline'] === '1';

$stmt_form = $conn->prepare("SELECT nombre FROM formulario WHERE id = ? LIMIT 1");
$stmt_form->bind_param("i", $formulario_id);
$stmt_form->execute();
$stmt_form->bind_result($nombreForm);
if (!$stmt_form->fetch()) {
    die("Formulario no encontrado.");
}
$stmt_form->close();

// -----------------------------------------------------------------------------
// Funciones de obtención de datos (sin filtro de fechas)
// -----------------------------------------------------------------------------

function getCampaignData($idForm) {
    global $conn;
    $sql = "
        SELECT 
            f.id,
            f.nombre,
            f.fechaInicio,
            f.fechaTermino,
            e.nombre AS nombre_empresa,
            COUNT(DISTINCT l.codigo) AS locales_programados,
            SUM(
                CASE WHEN fq.pregunta IN (
                    'implementado_auditado','solo_implementado','solo_auditoria',
                    'local_cerrado','no_permitieron'
                ) AND fq.fechaVisita != '0000-00-00 00:00:00' THEN 1 ELSE 0 END
            ) AS locales_visitados,
            SUM(
                CASE WHEN fq.pregunta IN (
                    'implementado_auditado','solo_implementado','solo_auditoria'
                ) THEN 1 ELSE 0 END
            ) AS locales_implementados
        FROM formulario f
        INNER JOIN empresa e              ON e.id = f.id_empresa
        INNER JOIN formularioQuestion fq  ON fq.id_formulario = f.id
        INNER JOIN local l                ON l.id = fq.id_local
        WHERE f.id = $idForm
        GROUP BY f.id, f.nombre, f.fechaInicio, f.fechaTermino, e.nombre
    ";
    $res = mysqli_query($conn, $sql);
    if (!$res) {
        die("Error en getCampaignData: " . mysqli_error($conn));
    }
    return mysqli_fetch_all($res, MYSQLI_ASSOC);
}

function getLocalesDetails($idForm) {
    global $conn;
    $sql = "
        SELECT 
            l.id                               AS idLocal,
            l.codigo                           AS codigo_local,
            CASE
              WHEN l.nombre REGEXP '^[0-9]+' 
              THEN SUBSTRING_INDEX(l.nombre, ' ', 1)
              ELSE CAST(l.codigo AS UNSIGNED)
            END                                AS numero_local,
            f.modalidad                        AS modalidad,
            UPPER(f.nombre)                    AS nombreCampaña,
            DATE(f.fechaInicio)                AS fechaInicio,
            DATE(f.fechaTermino)               AS fechaTermino,
            DATE(fq.fechaVisita)               AS fechaVisita,
            TIME(fq.fechaVisita)               AS hora,
            DATE(fq.fechaPropuesta)            AS fechaPropuesta,
            UPPER(l.nombre)                    AS nombre_local,
            UPPER(l.direccion)                 AS direccion_local,
            UPPER(cm.comuna)                   AS comuna,
            UPPER(re.region)                   AS region,
            UPPER(cu.nombre)                   AS cuenta,
            UPPER(ca.nombre)                   AS cadena,
            UPPER(fq.material)                 AS material,
            UPPER(jv.nombre)                   AS jefeVenta,
            fq.valor_propuesto,
            fq.valor,
            UPPER(fq.observacion)              AS observacion,
            CASE
                WHEN fq.fechaVisita IS NOT NULL 
                     AND fq.fechaVisita <> '0000-00-00 00:00:00'
                THEN 'VISITADO'
                ELSE 'NO VISITADO'
            END                                AS ESTADO_VISTA,
    CASE
      -- 1) Si el valor es 0 o NULL, forzamos NO IMPLEMENTADO
      WHEN IFNULL(fq.valor, 0) = 0 THEN 'NO IMPLEMENTADO'
      -- 2) En caso contrario, evaluamos según la pregunta
      WHEN LOWER(fq.pregunta) = 'solo_implementado'      THEN 'IMPLEMENTADO'
      WHEN LOWER(fq.pregunta) = 'solo_auditado'         THEN 'AUDITORIA'
      WHEN LOWER(fq.pregunta) = 'solo_auditoria'        THEN 'AUDITORIA'
      WHEN LOWER(fq.pregunta) = 'retiro'                THEN 'RETIRO'
      WHEN LOWER(fq.pregunta) = 'entrega'               THEN 'ENTREGA'
      WHEN LOWER(fq.pregunta) = 'implementado_auditado' THEN 'IMPLEMENTADO/AUDITADO'
      -- 3) Si no coincide con nada de lo anterior, por defecto NO IMPLEMENTADO
      ELSE 'NO IMPLEMENTADO'
    END AS ESTADO_ACTIVIDAD,
        UPPER(
          REPLACE(
            CASE
              WHEN IFNULL(fq.valor,0) = 0 THEN
                TRIM(
                  SUBSTRING_INDEX(
                    REPLACE(fq.observacion,'|','-'),
                    '-',
                    1
                  )
                )
              WHEN LOWER(fq.pregunta) IN ('en proceso','cancelado') THEN
                TRIM(
                  SUBSTRING_INDEX(
                    REPLACE(fq.observacion,'|','-'),
                    '-',
                    1
                  )
                )
              WHEN LOWER(fq.pregunta) IN ('solo_implementado','solo_auditoria') THEN
                ''
              ELSE
                fq.pregunta
            END
          , '_', ' ')
        ) AS MOTIVO,
            UPPER(u.usuario)                    AS gestionado_por
        FROM formularioQuestion fq
        INNER JOIN formulario   f ON f.id      = fq.id_formulario
        INNER JOIN local        l ON l.id      = fq.id_local
        INNER JOIN jefe_venta   jv ON jv.id    = l.id_jefe_venta
        INNER JOIN usuario      u ON u.id      = fq.id_usuario
        INNER JOIN cuenta       cu ON cu.id     = l.id_cuenta
        INNER JOIN cadena       ca ON ca.id     = l.id_cadena
        INNER JOIN comuna       cm ON cm.id     = l.id_comuna
        INNER JOIN region       re ON re.id     = cm.id_region
        WHERE f.id = $idForm
        ORDER BY l.codigo, fq.fechaVisita ASC
    ";
    $res = mysqli_query($conn, $sql);
    if (!$res) {
        die("Error en getLocalesDetails: " . mysqli_error($conn));
    }
    $rows = mysqli_fetch_all($res, MYSQLI_ASSOC);

    return $rows;
}

function getEncuestaPivot($idForm) {
    global $conn;
    // Obtener listado de preguntas
    $allQuestions = [];
    $qry = "
        SELECT question_text
        FROM form_questions
        WHERE id_formulario = $idForm
        ORDER BY sort_order
    ";
    $resQ = mysqli_query($conn, $qry)
        or die("Error al obtener preguntas: " . mysqli_error($conn));
    while ($rQ = mysqli_fetch_assoc($resQ)) {
        $allQuestions[] = $rQ['question_text'];
    }

    // Consulta principal sin filtro de fechas
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
        WHERE f.id = $idForm
        GROUP BY
            l.codigo,
            DATE(fqr.created_at),
            fp.question_text
        ORDER BY l.codigo, fp.sort_order
    ";
    $res = mysqli_query($conn, $sql)
        or die("Error en getEncuestaPivot: " . mysqli_error($conn));

    // Agrupar resultados
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
            'answer' => $row['concat_answers'],
            'valor'  => $row['concat_valores']
        ];
    }

    // Pivotear columnas
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

    // Eliminar columnas “_valor” vacías
    $valorCols = [];
    foreach ($final as $r) {
        foreach ($r as $c => $v) {
            if (strpos($c, '_valor') !== false) {
                $valorCols[$c] = $valorCols[$c] ?? false;
                if (trim($v) !== '') {
                    $valorCols[$c] = true;
                }
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

// -----------------------------------------------------------------------------
// Generador de HTML/XLS
// -----------------------------------------------------------------------------

function generarExcel($campaign, $locales, $encuesta, $archivo = null, $inline = false) {
    // Si es inline, corregir URLs de imágenes
    if ($inline) {
        foreach ($encuesta as &$fila) {
            foreach ($fila as $col => $valor) {
                if (preg_match('#^/?visibility2/#', $valor)) {
                    $ruta = ltrim($valor, '/');
                    $fila[$col] = 'https://visibility.cl/' . $ruta;
                }
            }
        }
        unset($fila);
    }

    // Construcción del HTML
    $html = <<<HTML
<html>
  <head>
    <meta charset="UTF-8">
    <!-- Bootstrap CSS para el modal -->
    <link rel="stylesheet"
          href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
      table { width: 100%; table-layout: auto; border-collapse: collapse; }
      th, td {
        border: 1px solid #666;
        padding: 4px;
        white-space: normal;
        word-wrap: break-word;
        vertical-align: top;
      }
      img.inline-img {
        max-width: 120px;
        max-height: 120px;
        cursor: pointer;
      }
    </style>
  </head>
  <body>
HTML;

    // —– Detalle de Locales —— 
    if (!empty($locales)) {
        $html .= "<b>Detalle de Locales</b>
                  <table border='1' 
                         style='border-collapse:collapse; table-layout:auto; font-size:9pt;'>
                    <tr>
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
                              ? $l['fechaPropuesta']
                              : '-';
            $fechaVisita    = ($l['fechaVisita'] !== null && $l['fechaVisita'] !== '0000-00-00')
                              ? $l['fechaVisita']
                              : '-';

            $html .= "<tr>
                        <td>" . htmlspecialchars($l['idLocal'], ENT_QUOTES, 'UTF-8') . "</td>
                        <td>" . htmlspecialchars($l['codigo_local'], ENT_QUOTES, 'UTF-8') . "</td>
                        <td>" . htmlspecialchars($l['numero_local'], ENT_QUOTES, 'UTF-8') . "</td>
                        <td>" . htmlspecialchars($l['nombreCampaña'], ENT_QUOTES, 'UTF-8') . "</td>
                        <td>" . htmlspecialchars($l['cuenta'], ENT_QUOTES, 'UTF-8') . "</td>
                        <td>" . htmlspecialchars($l['cadena'], ENT_QUOTES, 'UTF-8') . "</td>
                        <td>" . htmlspecialchars($l['nombre_local'], ENT_QUOTES, 'UTF-8') . "</td>
                        <td>" . htmlspecialchars($l['direccion_local'], ENT_QUOTES, 'UTF-8') . "</td>
                        <td>" . htmlspecialchars($l['comuna'], ENT_QUOTES, 'UTF-8') . "</td>
                        <td>" . htmlspecialchars($l['region'], ENT_QUOTES, 'UTF-8') . "</td>
                        <td>" . htmlspecialchars($l['jefeVenta'], ENT_QUOTES, 'UTF-8') . "</td>                        
                        <td>" . htmlspecialchars($l['gestionado_por'], ENT_QUOTES, 'UTF-8') . "</td>
                        <td>{$fechaPropuesta}</td>
                        <td>{$fechaVisita}</td>
                        <td>" . htmlspecialchars($l['hora'], ENT_QUOTES, 'UTF-8') . "</td>
                        <td>" . htmlspecialchars($l['ESTADO_VISTA'], ENT_QUOTES, 'UTF-8') . "</td>
                        <td>" . htmlspecialchars($l['ESTADO_ACTIVIDAD'], ENT_QUOTES, 'UTF-8') . "</td>
                        <td>" . htmlspecialchars($l['MOTIVO'], ENT_QUOTES, 'UTF-8') . "</td>
                        <td>" . htmlspecialchars($l['material'], ENT_QUOTES, 'UTF-8') . "</td>
                        <td>" . htmlspecialchars($l['valor'], ENT_QUOTES, 'UTF-8') . "</td>
                        <td>" . htmlspecialchars($l['valor_propuesto'], ENT_QUOTES, 'UTF-8') . "</td>
                        <td>" . htmlspecialchars($l['observacion'], ENT_QUOTES, 'UTF-8') . "</td>
                      </tr>";
        }

        $html .= "</table><br>";
    }

    // —– Encuesta Pivot —— 
    $html .= "<b>Encuesta</b>
              <table border='1' 
                     style='border-collapse:collapse; table-layout:auto; font-size:9pt;'>
                <tr>";
    if (!empty($encuesta)) {
        $keys = array_keys($encuesta[0]);
        foreach ($keys as $k) {
            $html .= "<th>" . htmlspecialchars($k) . "</th>";
        }
    }
    $html .= "</tr>";
    foreach ($encuesta as $row) {
        $html .= "<tr>";
        foreach ($row as $v) {
            if ($inline && preg_match('#^https?://#', $v)) {
                $urls = preg_split('/\s*;\s*/', $v);
                $first = trim($urls[0]);
                if (preg_match('#\.(jpe?g|png|gif)$#i', $first)) {
                    $html .= "<td><img class=\"inline-img\" src=\"" 
                           . htmlspecialchars($first) 
                           . "\" data-toggle=\"modal\" data-target=\"#imgModal\" data-src=\"" 
                           . htmlspecialchars($first) 
                           . "\"></td>";
                } else {
                    $html .= "<td>" . htmlspecialchars($v) . "</td>";
                }
            } else {
                $html .= "<td>" . htmlspecialchars($v) . "</td>";
            }
        }
        $html .= "</tr>";
    }
    $html .= "</table>";

    // Modal de Bootstrap para ver la imagen ampliada (solo si es inline)
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

<!-- Scripts de Bootstrap y JS para manejar el clic -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.bundle.min.js"></script>
<script>
  // Al abrir el modal, toma la URL del atributo data-src de la miniatura
  $('#imgModal').on('show.bs.modal', function(e) {
    var src = $(e.relatedTarget).data('src');
    $('#modalImg').attr('src', src);
  });
</script>
</body></html>
HTML;
    } else {
        // Cierre normal de HTML si no es inline
        $html .= "</body></html>";
    }

    // Si es inline, devolvemos el HTML sin forzar descarga
    if ($inline) {
        return $html;
    }

    // Si no es inline, forzamos descarga Excel
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
    header("Cache-Control: no-cache, must-revalidate");
    header("Pragma: no-cache");
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header("Expires: 0");
    header("Content-Disposition: attachment; filename={$archivo}");
    header("Content-Length: " . strlen($content));
    echo $content;
    exit();
}


// -----------------------------------------------------------------------------
// Punto de entrada
// -----------------------------------------------------------------------------
$campaignData   = getCampaignData($formulario_id);
$localesDetails = getLocalesDetails($formulario_id);
$encuestaPivot  = getEncuestaPivot($formulario_id);

foreach ($localesDetails as &$loc) {
    // Si no vino un número desde la SQL, extraemos todos los dígitos de codigo_local
    if (empty($loc['numero_local'])) {
        // \D+ significa “todo lo que NO sea dígito”
        $loc['numero_local'] = preg_replace('/\D+/', '', $loc['codigo_local']);
    }
}
unset($loc);

if (empty($campaignData) && empty($localesDetails) && empty($encuestaPivot)) {
    die("No se encontraron datos para la campaña.");
}

// Preparar nombre de archivo
$nombreCampaña = preg_replace('/[^A-Za-z0-9_\-]/', '_', $nombreForm);
$archivo = "Reporte_{$nombreCampaña}_" . date('Y-m-d_His') . ".xls";

// Si inline, mostramos el HTML; si no, forzamos descarga
if ($inline) {
    echo generarExcel($campaignData, $localesDetails, $encuestaPivot, null, true);
    exit();
}

generarExcel($campaignData, $localesDetails, $encuestaPivot, $archivo);
?>
