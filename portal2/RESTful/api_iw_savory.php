<?php
header("Content-Type: application/json; charset=UTF-8");
set_time_limit(0);
ini_set('memory_limit','-1');

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

if (isset($_GET['nombre']) && trim($_GET['nombre']) !== '') {
    $nombre  = mysqli_real_escape_string($conn, trim($_GET['nombre']));
    // preparamos la parte del WHERE para el LIKE
    $filtroNombre = "AND f.nombre LIKE '%{$nombre}%'";
} else {
    // no filtramos por nombre cuando venga vac铆o
    $filtroNombre = "";
}

// 2) Validaci贸n de division (igual que antes)
if (!isset($_GET['id_division']) || !is_numeric($_GET['id_division'])) {
    http_response_code(400);
    echo json_encode(['error'=>'ID de divisi贸n inv谩lido.']);
    exit;
}
$division = (int) $_GET['id_division'];

$sql = "
SELECT
    f.nombre,
    date(r.created_at) AS fechaVisita,
    u.usuario,
    CONCAT(u.nombre,' ',u.apellido) AS nombreUsuario,
    MAX(CASE WHEN q.question_text = 'CODIGO LOCAL' 
             THEN r.answer_text 
        END) AS CodigoLocal,
    MAX(CASE WHEN q.question_text = 'DIRECCIÓN' 
             THEN r.answer_text 
        END) AS DireccionLocal,        
    MAX(CASE WHEN q.question_text = 'INGRESE COMUNA' 
             THEN r.answer_text 
        END) AS ComunaLocal,
    MAX(CASE WHEN q.question_text = '¿PUDO REALIZAR LA GESTION?' 
             THEN r.answer_text 
        END) AS PudoGestionar,
    MAX(CASE WHEN q.question_text = 'MOTIVO DEL NO' 
             THEN r.answer_text 
        END) AS MotivoNoGestion,        
    MAX(CASE WHEN q.question_text = 'TIPO DE LOCAL' 
             THEN r.answer_text 
        END) AS TipoLocal,
  SUM(
    CASE 
      WHEN q.question_text = 'SELECCIONE MATERIALES IMPLEMENTADOS'
       AND r.answer_text    = 'METROS DE CENEFA'
      THEN r.valor
      ELSE 0
    END
  ) AS Cenefa,
SUM(
    CASE 
      WHEN q.question_text = 'SELECCIONE MATERIALES IMPLEMENTADOS'
       AND r.answer_text    = 'METROS DE CENEFA SIN LOGO'
      THEN r.valor
      ELSE 0
    END
  ) AS cenefa_sin_logo,
  SUM(
    CASE 
      WHEN q.question_text = 'SELECCIONE MATERIALES IMPLEMENTADOS'
       AND r.answer_text    = 'QUITASOL CON LOGO'
      THEN r.valor
      ELSE 0
    END
  ) AS quitasol_con_logo,
  SUM(
    CASE 
      WHEN q.question_text = 'SELECCIONE MATERIALES IMPLEMENTADOS'
       AND r.answer_text    = 'QUITASOL SIN LOGO'
      THEN r.valor
      ELSE 0
    END
  ) AS quitasol_sin_logo,
    SUM(
    CASE 
      WHEN q.question_text = 'SELECCIONE MATERIALES IMPLEMENTADOS'
       AND r.answer_text    = 'GRAFICA FREEZER'
      THEN r.valor
      ELSE 0
    END
  ) AS grafica_freezer,
      SUM(
    CASE 
      WHEN q.question_text = 'SELECCIONE MATERIALES IMPLEMENTADOS'
       AND r.answer_text    = 'BASURERO'
      THEN r.valor
      ELSE 0
    END
  ) AS basurero,
  SUM(
    CASE 
      WHEN q.question_text = 'SELECCIONE MATERIALES IMPLEMENTADOS'
       AND r.answer_text    = 'CABALLETE'
      THEN r.valor
      ELSE 0
    END
  ) AS caballete,
  SUM(
    CASE 
      WHEN q.question_text = 'SELECCIONE MATERIALES IMPLEMENTADOS'
       AND r.answer_text    = 'PENDON CABALLETE'
      THEN r.valor
      ELSE 0
    END
  ) AS pendon_caballete,
   SUM(
    CASE 
      WHEN q.question_text = 'SELECCIONE MATERIALES IMPLEMENTADOS'
       AND r.answer_text    = 'SAVORYN'
      THEN r.valor
      ELSE 0
    END
  ) AS savoryn,
     SUM(
    CASE 
      WHEN q.question_text = 'SELECCIONE MATERIALES IMPLEMENTADOS'
       AND r.answer_text    = 'PENDON SAVORYN'
      THEN r.valor
      ELSE 0
    END
  ) AS pendon_savoryn,
  SUM(
    CASE 
      WHEN q.question_text = 'SELECCIONE MATERIALES IMPLEMENTADOS'
       AND r.answer_text    = 'PENDON INNOVACION'
      THEN r.valor
      ELSE 0
    END
  ) AS pendon_innovacion,
    SUM(
    CASE 
      WHEN q.question_text = 'SELECCIONE MATERIALES IMPLEMENTADOS'
       AND r.answer_text    = 'BANDERA TRADICIONAL'
      THEN r.valor
      ELSE 0
    END
  ) AS bandera_tradicional,
   SUM(
    CASE 
      WHEN q.question_text = 'SELECCIONE MATERIALES IMPLEMENTADOS'
       AND r.answer_text    = 'BANDERA AVENTO'
      THEN r.valor
      ELSE 0
    END
  ) AS bandera_avento,
  SUM(
    CASE 
      WHEN q.question_text = 'SELECCIONE MATERIALES IMPLEMENTADOS'
       AND r.answer_text    = 'BANDERA MINI AVENTO'
      THEN r.valor
      ELSE 0
    END
  ) AS bandera_mini_avento,
    SUM(
    CASE 
      WHEN q.question_text = 'SELECCIONE MATERIALES IMPLEMENTADOS'
       AND r.answer_text    = 'BANDERA PLAYA'
      THEN r.valor
      ELSE 0
    END
  ) AS bandera_playa,
  SUM(
    CASE 
      WHEN q.question_text = 'SELECCIONE MATERIALES IMPLEMENTADOS'
       AND r.answer_text    = 'REPOSERA'
      THEN r.valor
      ELSE 0
    END
  ) AS reposera,
   SUM(
    CASE 
      WHEN q.question_text = 'SELECCIONE MATERIALES IMPLEMENTADOS'
       AND r.answer_text    = 'CONJUNTO PLASTICO'
      THEN r.valor
      ELSE 0
    END
  ) AS conjunto_plastico,
     SUM(
    CASE 
      WHEN q.question_text = 'SELECCIONE MATERIALES IMPLEMENTADOS'
       AND r.answer_text    = 'CONJUNTO DIRECTOR SILLA MADERA'
      THEN r.valor
      ELSE 0
    END
  ) AS conjunto_director_silla_madera,
     SUM(
    CASE 
      WHEN q.question_text = 'SELECCIONE MATERIALES IMPLEMENTADOS'
       AND r.answer_text    = 'CONJUNTO DIRECTOR SILLA METÁLICA'
      THEN r.valor
      ELSE 0
    END
  ) AS conjunto_director_silla_metalica,
     SUM(
    CASE 
      WHEN q.question_text = 'SELECCIONE MATERIALES IMPLEMENTADOS'
       AND r.answer_text    = 'PENDON MARCA SAVORY'
      THEN r.valor
      ELSE 0
    END
  ) AS pendon_marca_savory,
    SUM(
    CASE 
      WHEN q.question_text = 'SELECCIONE MATERIALES IMPLEMENTADOS'
       AND r.answer_text    = 'FLANGE RUTA'
      THEN r.valor
      ELSE 0
    END
  ) AS flange_ruta,
  SUM(
    CASE 
      WHEN q.question_text = 'SELECCIONE MATERIALES IMPLEMENTADOS'
       AND r.answer_text    = 'FLANGE HELADERIA'
      THEN r.valor
      ELSE 0
    END
  ) AS flange_heladeria,
  SUM(
    CASE 
      WHEN q.question_text = 'SELECCIONE MATERIALES IMPLEMENTADOS'
       AND r.answer_text    = 'MASTIL'
      THEN r.valor
      ELSE 0
    END
  ) AS mastil,
    SUM(
    CASE 
      WHEN q.question_text = 'SELECCIONE MATERIALES IMPLEMENTADOS'
       AND r.answer_text    = 'PIZARRA'
      THEN r.valor
      ELSE 0
    END
  ) AS pizarra,
    MAX(CASE WHEN q.question_text = 'EXISTE PRODUCTO EN EL FREEZER' 
             THEN r.answer_text 
        END) AS ExisteProductoFreezer,
        
    MAX(CASE WHEN q.question_text = 'INDIQUE QUE PORCENTAJE DE LLENADO SE VISUALIZA EN EL FREEZER' 
             THEN r.answer_text 
        END) AS PorcentajeFreezer,
        
    MAX(CASE WHEN q.question_text = 'MOTIVO DEL POR QUE NO HAY PRODUCTOS EN FREEZER' 
             THEN r.answer_text 
        END) AS MotivoNoProductos,

    GROUP_CONCAT(
      DISTINCT CASE 
        WHEN q.question_text = 'SELECCIONE TIPO DE MANTENCION REALIZADA' 
        THEN r.answer_text 
      END 
      SEPARATOR '; '
    ) AS TipoMantencion,

    MAX(CASE WHEN q.question_text = 'INDIQUE MARCA DE FREEZERS EXISTENTES EN LOCAL' 
             THEN r.answer_text 
        END) AS MarcaFreezers,
                
    MAX(CASE WHEN q.question_text = '¿EL FREEZER SAVORY ESTÁ UBICADO EN PRIMERA POSICION?' 
             THEN r.answer_text 
        END) AS FreezerUbicadoPrimeraPosicion,
                        
    MAX(CASE WHEN q.question_text = '¿NUESTRO FREEZER SAVORY ESTA CONTAMINADO CON OTROS PRODUCTOS?' 
             THEN r.answer_text 
        END) AS FreezerContaminado,
                                
    MAX(CASE WHEN q.question_text = '¿QUÉ MARCAS TIENEN ELEMENTOS DE VISIBILIDAD EN EL PUNTO DE VENTA?' 
             THEN r.answer_text 
        END) AS MarcasVisibilidad,
        
    MAX(CASE WHEN q.question_text = 'INDIQUE MARCA DE FREEZERS EXISTENTES EN LOCAL' 
             THEN r.answer_text 
        END) AS MarcasPresentes,
        
    MAX(CASE WHEN q.question_text = '¿EL FREEZER SAVORY ESTÁ UBICADO EN PRIMERA POSICION?' 
             THEN r.answer_text 
        END) AS UbicacionFreezerSavory,
        
    MAX(CASE WHEN q.question_text = '¿NUESTRO FREEZER SAVORY ESTA CONTAMINADO CON OTROS PRODUCTOS?' 
             THEN r.answer_text 
        END) AS FreezerSavoryContaminado,  
        
    MAX(CASE WHEN q.question_text = '¿QUÉ MARCAS TIENEN ELEMENTOS DE VISIBILIDAD EN EL PUNTO DE VENTA?' 
             THEN r.answer_text 
        END) AS MarcasConElementosVisibilidad,  
        
    MAX(CASE WHEN q.question_text = '¿QUÉ MARCAS TIENEN ELEMENTOS DE VISIBILIDAD EN EL PUNTO DE VENTA?' 
             THEN r.answer_text 
        END) AS MarcasConElementosVisibilidad       
        
 
FROM form_question_responses AS r
JOIN form_questions        AS q ON q.id = r.id_form_question
JOIN formulario            AS f ON f.id = q.id_formulario
JOIN usuario               AS u ON u.id = r.id_usuario

WHERE f.id_division     = $division
  $filtroNombre 
  AND f.id              <> 138
  AND q.id_question_type <> 7

GROUP BY
    f.nombre,
    DATE(r.created_at),
    TIME(r.created_at),
    u.usuario,
    CONCAT(u.nombre,' ',u.apellido)
";

$res = mysqli_query($conn, $sql, MYSQLI_USE_RESULT);
if (!$res) {
    http_response_code(500);
    echo json_encode(['error'=>mysqli_error($conn)]);
    exit;
}

echo '[';
$first = true;
while ($row = mysqli_fetch_assoc($res)) {
    if (!$first) echo ',';
    echo json_encode($row, JSON_UNESCAPED_UNICODE);
    $first = false;
}
echo ']';
mysqli_free_result($res);
exit;