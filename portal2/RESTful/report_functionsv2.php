<?php
// report_functions.php

include '../con_.php';

// Función para obtener datos resumen de la campaña
function getCampaignData($idForm) {
    global $conn;
    $query = "SELECT f.id, f.nombre, f.fechaInicio, f.fechaTermino, e.nombre AS nombre_empresa,
                     COUNT(DISTINCT l.codigo) AS locales_programados, 
                     SUM(CASE WHEN fq.pregunta IN ('implementado_auditado','solo_implementado','solo_auditoria','local_cerrado','no_permitieron') THEN 1 ELSE 0 END) AS locales_visitados,
                     SUM(CASE WHEN fq.pregunta IN ('implementado_auditado', 'solo_implementado', 'solo_auditoria') THEN 1 ELSE 0 END) AS locales_implementados
              FROM formulario f
              INNER JOIN empresa AS e ON e.id = f.id_empresa
              INNER JOIN formularioQuestion AS fq ON fq.id_formulario = f.id
              INNER JOIN local AS l ON l.id = fq.id_local
              WHERE f.id = $idForm
              GROUP BY f.id, f.nombre, f.fechaInicio, f.fechaTermino, e.nombre";
    $result = mysqli_query($conn, $query);
    $data = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $data[] = $row;
    }
    return $data;
}

// Función para obtener detalles de los locales
function getMaterialPivot($nombre) {
    global $conn;
    // Escapar el valor para evitar inyección SQL
    $nombreEscaped = mysqli_real_escape_string($conn, $nombre);
    $query = "SELECT 
            l.id as idLocal,
            l.codigo as codigo_local,
            f.nombre as nombreCampaña,
            f.fechaInicio,
            f.fechaTermino,
            fq.fechaVisita,
            l.nombre as nombre_local,
            l.direccion as direccion_local,
            co.comuna as comuna_local,
            re.region as region_local,
            c.nombre as cuenta,
            ca.nombre as cadena,
            fq.material,
            fq.valor_propuesto,
            fq.valor,
            fq.observacion,
            fq.pregunta,
            u.usuario AS gestionado_por,
            sc.nombre_subcanal as subcanal
        FROM formularioQuestion fq
        INNER JOIN local l ON l.id = fq.id_local
        INNER JOIN subcanal sc ON l.id_subcanal = sc.id
		INNER JOIN comuna AS co ON co.id = l.id_comuna
		INNER JOIN region as re on re.id = co.id_region
        INNER JOIN formulario f ON f.id = fq.id_formulario
        INNER JOIN usuario u ON fq.id_usuario = u.id
        INNER JOIN cuenta c ON l.id_cuenta = c.id
        INNER JOIN cadena ca ON l.id_cadena = ca.id
        WHERE f.nombre LIKE '%$nombreEscaped%'
        ORDER BY l.codigo, fq.fechaVisita ASC";
    $result = mysqli_query($conn, $query);
    $data = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $data[] = $row;
    }
    return $data;
}


// Función para obtener detalles de la encuesta y pivotearlos (filas a columnas)
function getEncuestaPivotByName($nombre) {
    global $conn;
    // Escapar el valor para evitar inyección SQL
    $nombreEscaped = mysqli_real_escape_string($conn, $nombre);
    $query = "SELECT 
                 f.id AS idCampana, 
                 f.nombre AS nombreCampana, 
                 l.codigo AS codigo_local, 
                 l.nombre AS nombre_local, 
                 fp.id AS id_pregunta, 
                 fp.question_text,
                 fp.id_question_type, 
                 fqr.answer_text,
                 fqr.valor as precio,                  
                 fqr.created_at AS fecha_respuesta,
                 c.comuna as comuna,
                 r.region as region
              FROM formulario f
              JOIN form_questions fp ON fp.id_formulario = f.id
              JOIN form_question_responses fqr ON fqr.id_form_question = fp.id
              JOIN local l ON l.id = fqr.id_local
              JOIN comuna c ON c.id = l.id_comuna
              JOIN region r ON r.id = c.id_region
              WHERE f.nombre LIKE '%$nombreEscaped%'
              ORDER BY l.codigo, fp.sort_order";
    $result = mysqli_query($conn, $query);
    $pivot = [];
    
    // Definir la pregunta especial (ajusta según corresponda)
    $special_question = "INGRESE PRECIO REGULAR DEL SIGUIENTE PRODUCTO (EXCLUYE PROMOCION)";
    
    while ($row = mysqli_fetch_assoc($result)) {
        $codigo_local = $row['codigo_local'];
        if (!isset($pivot[$codigo_local])) {
            $pivot[$codigo_local] = [
                'idCampana'     => $row['idCampana'],
                'nombreCampana' => $row['nombreCampana'],
                'codigo_local'  => $row['codigo_local'],
                'nombre_local'  => $row['nombre_local'],
                'fecha_respuesta'  => $row['fecha_respuesta'],
                'comuna'  => $row['comuna'],
                'region'  => $row['region']                
            ];
        }
        $question = $row['question_text'];
        
        if ($question == $special_question) {
            // Para la pregunta especial, se usa la respuesta como nombre de columna
            $column = $row['answer_text'];
            $cell = $row['precio'] . ")";
            $pivot[$codigo_local][$column] = $cell;
        } else {
            // Para las demás preguntas, se concatenan las respuestas
            $response = $row['answer_text'] . " (Valor: " . $row['precio'] . ")";
            if (isset($pivot[$codigo_local][$question])) {
                $existing = explode(", ", $pivot[$codigo_local][$question]);
                if (!in_array($response, $existing)) {
                    $pivot[$codigo_local][$question] .= ", " . $response;
                }
            } else {
                $pivot[$codigo_local][$question] = $response;
            }
        }
    }
    return array_values($pivot);
}

function getLocalMapData($divisionName = null) {
    global $conn;

    $sql = "
      SELECT
        de.nombre   AS division,
        l.codigo    AS codigo,
        l.nombre    AS local,
        l.direccion AS direccion,
        c.comuna    AS comuna,
        r.region    AS region,
        l.lat       AS latitud,
        l.lng       AS longitud
      FROM local l
      INNER JOIN comuna c            ON c.id = l.id_comuna
      INNER JOIN region r            ON r.id = c.id_region
      INNER JOIN division_empresa de ON de.id = l.id_division
      INNER JOIN formularioQuestion fq ON fq.id_local   = l.id
      INNER JOIN formulario f         ON f.id          = fq.id_formulario
      INNER JOIN usuario u            ON u.id          = fq.id_usuario
    ";

    // Si me pasaron división, la agrego
    if ($divisionName !== null) {
        // evita inyección
        $divisionEsc = $conn->real_escape_string($divisionName);
        $sql .= " WHERE de.nombre = '{$divisionEsc}' ";
    }

    $sql .= " ORDER BY l.codigo;";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();

    return $rows;
}
?>