<?php
ini_set('display_startup_errors',1);
ini_set('display_errors',1);
error_reporting(E_ALL);

// Incluir el archivo de conexión
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

// Función genérica para consultas SELECT
function ejecutarConsulta($consulta, $params = [], $tipos = '') {
    global $conn;

    $stmt = mysqli_prepare($conn, $consulta);
    if ($stmt === false) {
        die("Error en la preparación de la consulta: " . mysqli_error($conn));
    }

    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $tipos, ...$params);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($result === false) {
        die("Error en la ejecución de la consulta: " . mysqli_stmt_error($stmt));
    }

    $data = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $data[] = $row;
    }

    mysqli_stmt_close($stmt);
    return $data;
}


function ejecutarModificacion($consulta, $params = []) {
    global $conn;

    $stmt = mysqli_prepare($conn, $consulta);
    if (!empty($params)) {
        $tipos = str_repeat('s', count($params)); 
        mysqli_stmt_bind_param($stmt, $tipos, ...$params);
    }

    $resultado = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return $resultado;
}

function createQuestionSet($nombre_set, $description = '') {
    global $conn;
    $sql = "INSERT INTO question_set (nombre_set, description, created_at, updated_at)
            VALUES (?, ?, NOW(), NOW())";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Error preparando createQuestionSet: " . $conn->error);
    }
    $stmt->bind_param("ss", $nombre_set, $description);
    if (!$stmt->execute()) {
        throw new Exception("Error al crear set de preguntas: " . $stmt->error);
    }
    $new_id = $conn->insert_id;
    $stmt->close();
    return $new_id;
}

/**
 * Actualiza un set de preguntas (solo nombre y desc).
 */
function updateQuestionSet($idSet, $nombre_set, $description = '') {
    global $conn;
    $sql = "UPDATE question_set
            SET nombre_set = ?, description = ?, updated_at = NOW()
            WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Error preparando updateQuestionSet: " . $conn->error);
    }
    $stmt->bind_param("ssi", $nombre_set, $description, $idSet);
    if (!$stmt->execute()) {
        throw new Exception("Error al actualizar set de preguntas: " . $stmt->error);
    }
    $stmt->close();
}

/**
 * Elimina un set (y en cascada sus preguntas).
 */
function deleteQuestionSet($idSet) {
    global $conn;
    $sql = "DELETE FROM question_set WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Error preparando deleteQuestionSet: " . $conn->error);
    }
    $stmt->bind_param("i", $idSet);
    if (!$stmt->execute()) {
        throw new Exception("Error al eliminar set de preguntas: " . $stmt->error);
    }
    $stmt->close();
}

/**
 * Obtiene la lista de todos los sets.
 */
function getQuestionSets() {
    $sql = "SELECT id, nombre_set, description, created_at, updated_at
            FROM question_set
            ORDER BY nombre_set ASC";
    return ejecutarConsulta($sql);
}

/**
 * Obtiene info de 1 set, más sus preguntas, para edición.
 */
function getQuestionSet($idSet) {
    $sql = "SELECT id, nombre_set, description, created_at, updated_at
            FROM question_set
            WHERE id = ?";
    $arr = ejecutarConsulta($sql, [$idSet], 'i');
    if (count($arr) > 0) {
        return $arr[0];
    }
    return null;
}

/**
 * Agrega una pregunta al set: devuelte id de question_set_questions
 */
/**
 * Agrega una pregunta al set y devuelve el ID de la nueva pregunta.
 * Ahora acepta dos parámetros adicionales:
 *   - $id_dependency_option: (opcional) ID de la opción de la pregunta padre que dispara esta pregunta.
 *   - $is_valued: (opcional) bandera (0 o 1) que indica si la pregunta es valorizada.
 */
function addQuestionToSet($idSet, $question_text, $id_question_type, $is_required = 0, $sort_order = 1, $id_dependency_option = null, $is_valued = 0) {
    global $conn;
    $sql = "INSERT INTO question_set_questions
            (id_question_set, question_text, id_question_type, sort_order, is_required, id_dependency_option, is_valued)
            VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Error preparando addQuestionToSet: " . $conn->error);
    }
    // Los tipos: i (idSet), s (question_text), i (id_question_type), i (sort_order), i (is_required),
    // i (id_dependency_option) y i (is_valued)
    $stmt->bind_param("isiiiii", $idSet, $question_text, $id_question_type, $sort_order, $is_required, $id_dependency_option, $is_valued);
    if (!$stmt->execute()) {
        throw new Exception("Error al agregar pregunta al set: " . $stmt->error);
    }
    $idQ = $conn->insert_id;
    $stmt->close();
    return $idQ;
}


function addOptionToSetQuestionWithImage($idQuestionSetQuestion, $option_text, $sort_order, $reference_image) {
    global $conn;
    // asumiendo que tu tabla question_set_options tiene la columna reference_image
    $sql = "INSERT INTO question_set_options
            (id_question_set_question, option_text, sort_order, reference_image)
            VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Error en la preparación: " . $conn->error);
    }
    $stmt->bind_param("isis", $idQuestionSetQuestion, $option_text, $sort_order, $reference_image);
    if (!$stmt->execute()) {
        throw new Exception("Error al insertar opción con imagen: " . $stmt->error);
    }
    $stmt->close();
}
/**
 * Agrega una opción a la pregunta del set.
 */
function addOptionToSetQuestion($idSetQuestion, $option_text, $sort_order = 1) {
    global $conn;
    $sql = "INSERT INTO question_set_options
            (id_question_set_question, option_text, sort_order)
            VALUES (?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Error preparando addOptionToSetQuestion: " . $conn->error);
    }
    $stmt->bind_param("isi", $idSetQuestion, $option_text, $sort_order);
    if (!$stmt->execute()) {
        throw new Exception("Error al agregar opción: " . $stmt->error);
    }
    $stmt->close();
}

/**
 * Obtiene preguntas de un set
 */
function getQuestionsFromSet($idSet) {
    $sql = "SELECT q.id, 
                   q.id_question_set, 
                   q.question_text, 
                   q.id_question_type,
                   q.sort_order, 
                   q.is_required,
                   q.id_dependency_option,
                   q.is_valued
            FROM question_set_questions q
            WHERE q.id_question_set = ?
            ORDER BY q.sort_order ASC, q.id ASC";
    return ejecutarConsulta($sql, [$idSet], 'i');
}

/**
 * Obtiene opciones de una pregunta del set
 */
function getOptionsFromSetQuestion($idSetQuestion) {
    $sql = "SELECT 
                o.id, 
                o.id_question_set_question, 
                o.option_text, 
                o.sort_order, 
                o.reference_image
            FROM question_set_options o
            WHERE o.id_question_set_question = ?
            ORDER BY o.sort_order ASC, o.id ASC";
    return ejecutarConsulta($sql, [$idSetQuestion], 'i');
}

/**
 * Copia todo un set (preguntas + opciones) a form_questions + form_question_options
 */
 
function copySetToFormulario($idSet, $idFormulario) {
    global $conn;

    $questions = getQuestionsFromSet($idSet);

    // Hallar el mayor sort_order actual en form_questions para este formulario
    $sqlMaxOrder = "SELECT COALESCE(MAX(sort_order),0) AS max_sort
                    FROM form_questions
                    WHERE id_formulario = ?";
    $arrMax = ejecutarConsulta($sqlMaxOrder, [$idFormulario], 'i');
    $baseSort = (count($arrMax) > 0) ? intval($arrMax[0]['max_sort']) : 0;

    // Preparar statements para seleccionar, insertar y actualizar preguntas
    $sqlSelectQ = "SELECT id FROM form_questions
                   WHERE id_formulario = ? AND id_question_set_question = ?";
    $stmtSelectQ = $conn->prepare($sqlSelectQ);

    // Se incluye id_dependency_option e is_valued en la inserción
    // Ahora se indica correctamente: "i" para id_formulario, "s" para question_text,
    // y luego 6 "i" para: id_question_type, sort_order, is_required, id_question_set_question, id_dependency_option, is_valued
    $sqlInsQ = "INSERT INTO form_questions
                (id_formulario, question_text, id_question_type, sort_order, is_required, id_question_set_question, id_dependency_option, is_valued)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $stmtInsQ = $conn->prepare($sqlInsQ);

    // Actualización (se podría actualizar la dependencia y la bandera también)
    // Aquí se necesitan 8 parámetros, por lo que se usa "siiiiiii"
    $sqlUpdQ = "UPDATE form_questions
                SET question_text = ?, id_question_type = ?, is_required = ?, sort_order = ?, id_dependency_option = ?, is_valued = ?
                WHERE id_formulario = ? AND id_question_set_question = ?";
    $stmtUpdQ = $conn->prepare($sqlUpdQ);

    // Para las opciones (se mantienen igual)
    $sqlSelectO = "SELECT id FROM form_question_options
                   WHERE id_form_question = ? AND id_question_set_option = ?";
    $stmtSelectO = $conn->prepare($sqlSelectO);

    $sqlInsO = "INSERT INTO form_question_options
                (id_form_question, option_text, sort_order, id_question_set_option)
                VALUES (?, ?, ?, ?)";
    $stmtInsO = $conn->prepare($sqlInsO);

    $sqlUpdO = "UPDATE form_question_options
                SET option_text = ?, sort_order = ?
                WHERE id = ?";
    $stmtUpdO = $conn->prepare($sqlUpdO);

    $offset = 0;
    foreach ($questions as $q) {
        $idSetQ = $q['id'];
        $question_text = $q['question_text'];
        $qtype = $q['id_question_type'];
        $isreq = $q['is_required'];
        $sort_set = $q['sort_order'];
        // Capturar la dependencia y la bandera de valorización del set
        $dependency_option = isset($q['id_dependency_option']) ? $q['id_dependency_option'] : null;
        $is_valued = isset($q['is_valued']) ? $q['is_valued'] : 0;

        $sortQ = $baseSort + $offset + 1;
        $offset++;

        // Revisar si ya existe la pregunta en form_questions
        $stmtSelectQ->bind_param("ii", $idFormulario, $idSetQ);
        $stmtSelectQ->execute();
        $resSelQ = $stmtSelectQ->get_result();
        if ($rowExist = $resSelQ->fetch_assoc()) {
            $existingFQid = $rowExist['id'];
            // Corregido el string de tipos: se requiere "siiiiiii" (8 caracteres)
            $stmtUpdQ->bind_param("siiiiiii", 
                $question_text,
                $qtype,
                $isreq,
                $sortQ,
                $dependency_option,
                $is_valued,
                $idFormulario,
                $idSetQ
            );
            $stmtUpdQ->execute();
        } else {
            // Corregido el string de tipos: se requiere "isiiiiii" (8 caracteres)
            $stmtInsQ->bind_param("isiiiiii", 
                $idFormulario,
                $question_text,
                $qtype,
                $sortQ,
                $isreq,
                $idSetQ,
                $dependency_option,
                $is_valued
            );
            $stmtInsQ->execute();
            $existingFQid = $conn->insert_id;
        }

        // Procesar las opciones
        $opts = getOptionsFromSetQuestion($idSetQ);
        foreach ($opts as $opt) {
            $optIdSet = $opt['id'];
            $optText = $opt['option_text'];
            $optSort = $opt['sort_order'];

            $stmtSelectO->bind_param("ii", $existingFQid, $optIdSet);
            $stmtSelectO->execute();
            $resSelO = $stmtSelectO->get_result();
            if ($rowOpt = $resSelO->fetch_assoc()) {
                $formOptId = $rowOpt['id'];
                $stmtUpdO->bind_param("sii", 
                    $optText,
                    $optSort,
                    $formOptId
                );
                $stmtUpdO->execute();
            } else {
                $stmtInsO->bind_param("isii",
                    $existingFQid,
                    $optText,
                    $optSort,
                    $optIdSet
                );
                $stmtInsO->execute();
            }
        }
    }
    $stmtSelectQ->close();
    $stmtInsQ->close();
    $stmtUpdQ->close();
    $stmtSelectO->close();
    $stmtInsO->close();
    $stmtUpdO->close();
}

function insertarComuna($nombre_comuna, $id_region) {
    global $conn;
   
    $stmt = $conn->prepare("INSERT INTO comuna (comuna, id_region) VALUES (?, ?)");
    $stmt->bind_param("si", $nombre_comuna, $id_region); // "s" para string, "i" para integer
    return $stmt->execute();
}

function insertarCadena($nombre_cadena, $id_cuenta) {
    global $conn;
   
    $stmt = $conn->prepare("INSERT INTO cadena (nombre, id_cuenta) VALUES (?, ?)");
    $stmt->bind_param("si", $nombre_cadena, $id_cuenta); // "s" para string, "i" para integer
    return $stmt->execute();
}



function insertarCuenta($nombre_cuenta) {
    global $conn;
   
    $stmt = $conn->prepare("INSERT INTO cuenta (nombre) VALUES (?)");
    $stmt->bind_param("s", $nombre_cuenta); // "s" para string, "i" para integer
    return $stmt->execute();
}



function obtenerCuentas() {
    global $conn; 
    $sql = "SELECT id, nombre FROM cuenta"; // Incluye el ID
    $result = mysqli_query($conn, $sql);

    $cuentas = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $cuentas[] = $row;
        }
    }
    return $cuentas;
}

function obtenerSubcanales() {
    global $conn;
    $sql = "SELECT id, nombre_subcanal FROM subcanal ORDER BY nombre_subcanal ASC";
    $result = mysqli_query($conn, $sql);

    $subcanales = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $subcanales[] = $row;
        }
    }
    return $subcanales;
}

/***************************************************************
 * OBTENER ZONAS
 * SELECT id, nombre_zona FROM zona
 ***************************************************************/
function obtenerZonas() {
    // Usamos la función genérica 'ejecutarConsulta'
    $sql = "SELECT id, nombre_zona 
            FROM zona
            ORDER BY nombre_zona ASC";
    return ejecutarConsulta($sql);
}

/***************************************************************
 * OBTENER DISTRITOS (todos)
 * SELECT id, nombre_distrito, id_zona FROM distrito
 ***************************************************************/
function obtenerDistritos() {
    $sql = "SELECT id, nombre_distrito, id_zona
            FROM distrito
            ORDER BY nombre_distrito ASC";
    return ejecutarConsulta($sql);
}

/***************************************************************
 * OBTENER DISTRITOS POR ZONA
 * SELECT id, nombre_distrito FROM distrito WHERE id_zona = ?
 ***************************************************************/
function obtenerDistritosPorZona($zona_id) {
    $sql = "SELECT id, nombre_distrito
            FROM distrito
            WHERE id_zona = ?
            ORDER BY nombre_distrito ASC";
    // Requiere un parámetro entero (id_zona)
    return ejecutarConsulta($sql, [$zona_id], 'i');
}

/***************************************************************
 * OBTENER JEFES DE VENTA
 * SELECT id, nombre FROM jefe_venta
 ***************************************************************/
function obtenerJefesVenta() {
    $sql = "SELECT id, nombre
            FROM jefe_venta
            ORDER BY nombre ASC";
    return ejecutarConsulta($sql);
}

/***************************************************************
 * OBTENER VENDEDORES
 * SELECT id, nombre_vendedor FROM vendedor
 ***************************************************************/
function obtenerVendedores() {
    $sql = "SELECT id, nombre_vendedor
            FROM vendedor
            ORDER BY nombre_vendedor ASC";
    return ejecutarConsulta($sql);
}


function obtenerCanales() {
    global $conn;
    $sql = "SELECT id, nombre_canal FROM canal ORDER BY nombre_canal ASC";
    $result = mysqli_query($conn, $sql);

    $canales = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $canales[] = $row;
        }
    }
    return $canales;
}

function obtenerDivision() {
    global $conn;
    $sql = "SELECT id, nombre FROM division_empresa ORDER BY nombre ASC";
    $result = mysqli_query($conn, $sql);

    $canales = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $canales[] = $row;
        }
    }
    return $canales;
}


function insertarCanal($nombre_canal) {
    global $conn;
    $stmt = $conn->prepare("INSERT INTO canal (nombre_canal) VALUES (?)");
    if (!$stmt) {
        throw new Exception("Error preparando la inserción de canal: " . $conn->error);
    }
    $stmt->bind_param("s", $nombre_canal);
    if (!$stmt->execute()) {
        throw new Exception("Error al crear el canal: " . $stmt->error);
    }
    return $conn->insert_id; // Retorna el ID del canal creado
}

function insertarEmpresa($nombre_empresa) {
    global $conn;
    $activo = 1; 
    $stmt = $conn->prepare("INSERT INTO empresa (nombre, activo) VALUES (?, ?)");
    $stmt->bind_param("si", $nombre_empresa, $activo);
    if ($stmt->execute()) {
        return $conn->insert_id; // Devuelve el ID de la empresa recién creada
    } else {
        return false;
    }
}
function existeEmpresa($nombre_empresa) {
    global $conn;
    $stmt = $conn->prepare("SELECT COUNT(*) FROM empresa WHERE nombre = ?");
    if ($stmt === false) {
        die("Error en la preparación de la consulta: " . $conn->error);
    }
    $stmt->bind_param("s", $nombre_empresa);
    if (!$stmt->execute()) {
        die("Error en la ejecución de la consulta: " . $stmt->error);
    }
    $count = 0; // Inicializar variable
    $stmt->bind_result($count);
    if ($stmt->fetch()) {
        $stmt->close();
        return $count > 0;
    } else {
        $stmt->close();
        return false;
    }
}

function existeUsuario($email, $usuario) {
    global $conn;
    $stmt = $conn->prepare("SELECT COUNT(*) FROM usuario WHERE email = ? OR usuario = ?");
    $stmt->bind_param("ss", $email, $usuario);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    
    return $count > 0;
}

function validarRut($rut) {
    // Eliminar puntos y guiones
    $rut = preg_replace('/[^0-9kK]/', '', $rut);

    // Verificar longitud mínima (7 dígitos + DV)
    if (strlen($rut) < 8) {
        return false;
    }

    // Separar número y dígito verificador
    $numero = substr($rut, 0, -1);
    $dv = strtoupper(substr($rut, -1));

    // Validar que $numero sea numérico
    if (!is_numeric($numero)) {
        return false;
    }

    $suma = 0;
    $multiplicador = 2;

    for ($i = strlen($numero) - 1; $i >= 0; $i--) {
        $suma += intval($numero[$i]) * $multiplicador;
        $multiplicador = ($multiplicador < 7) ? $multiplicador + 1 : 2;
    }

    $resto = $suma % 11;
    $dvCalculado = 11 - $resto;

    if ($dvCalculado == 11) {
        $dvCalculado = '0';
    } elseif ($dvCalculado == 10) {
        $dvCalculado = 'K';
    } else {
        $dvCalculado = (string)$dvCalculado;
    }

    return $dv === $dvCalculado;
}



function existeRut($rut) {
    global $conn;
    $stmt = $conn->prepare("SELECT COUNT(*) FROM usuario WHERE rut = ?");
    $stmt->bind_param("s", $rut);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    
    return $count > 0;
}



function insertarDivision($nombre_division, $empresaId) {
    global $conn;
    $stmt = $conn->prepare("INSERT INTO division_empresa (nombre, id_empresa) VALUES (?, ?)");
    $stmt->bind_param("si", $nombre_division, $empresaId);
    return $stmt->execute();
}

function existeDivision($nombre_division, $empresaId) {
    global $conn;
    $stmt = $conn->prepare("SELECT COUNT(*) FROM division_empresa WHERE LOWER(nombre) = LOWER(?) AND id_empresa = ?");
    $stmt->bind_param("si", $nombre_division, $empresaId);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    return $count > 0;
}

function obtenerNombrePerfil($id_perfil) {
    global $conn;
    $stmt = $conn->prepare("SELECT nombre FROM perfil WHERE id = ?");
    $stmt->bind_param("i", $id_perfil);
    $stmt->execute();
    $stmt->bind_result($nombre_perfil);
    $stmt->fetch();
    $stmt->close();
    return $nombre_perfil;
}


function obtenerIdPerfilEjecutor() {
    global $conn;
    $stmt = $conn->prepare("SELECT id FROM perfil WHERE nombre = 'ejecutor'");
    if ($stmt === false) {
        error_log("Error en la preparación de la consulta de perfil 'ejecutor': " . $conn->error);
        return null;
    }
    if (!$stmt->execute()) {
        error_log("Error en la ejecución de la consulta de perfil 'ejecutor': " . $stmt->error);
        $stmt->close();
        return null;
    }
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return isset($row['id']) ? intval($row['id']) : null;
}

// Modificar esta función para obtener el ID, el nombre y el estado
function obtenerEmpresas() {
    global $conn; 
    $sql = "SELECT id, nombre, activo FROM empresa"; // Incluye el ID
    $result = mysqli_query($conn, $sql);

    $empresas = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $empresas[] = $row;
        }
    }
    return $empresas;
}

// Modificar esta función para obtener el ID, el nombre y el estado
function obtenerEmpresasActivas() {
    global $conn; 
    $sql = "SELECT id, nombre, activo FROM empresa WHERE activo = 1"; // Incluye el ID
    $result = mysqli_query($conn, $sql);

    $empresas = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $empresas[] = $row;
        }
    }
    return $empresas;
}


function actualizarEstadoEmpresa($empresaId, $nuevoEstado) {
    global $conn;

    // Preparar la consulta SQL usando sentencias preparadas
    $stmt = $conn->prepare("UPDATE empresa SET activo = ? WHERE id = ?");
    if ($stmt === false) {
        error_log("Error en la preparación de la consulta: " . $conn->error);
        return false;
    }

    // Vincular parámetros: 'i' para entero
    $stmt->bind_param("ii", $nuevoEstado, $empresaId);

    // Ejecutar la consulta
    if ($stmt->execute()) {
        // Verificar si se actualizó alguna fila
        if ($stmt->affected_rows > 0) {
            $stmt->close();
            return true;
        } else {
            // No se encontró la empresa o el estado ya estaba establecido
            error_log("No se encontró la empresa con ID $empresaId o el estado ya estaba establecido.");
            $stmt->close();
            return false;
        }
    } else {
        // Error en la ejecución
        error_log("Error en la ejecución de la consulta: " . $stmt->error);
        $stmt->close();
        return false;
    }
}
// Modificar esta función para obtener el ID, el nombre y el estado


function obtenerPerfiles() {
    global $conn;
    $sql = "SELECT id, nombre FROM perfil";
    return ejecutarConsulta($sql);
}

function obtenerUsuarios($filtro = ['estado' => 'todos', 'empresa' => 'todos', 'perfil' => 'todos', 'division' => 'todos']){
    global $conn;
    $usuarios = [];
    
    // Base de la consulta
    $sql = "SELECT u.id, CONCAT(u.nombre, ' ', u.apellido) AS nombre_completo, u.usuario AS nombre_login, 
                   e.nombre AS nombre_empresa, d.nombre AS nombre_division, p.nombre AS nombre_perfil, u.activo
            FROM usuario u
            LEFT JOIN empresa e ON u.id_empresa = e.id
            LEFT JOIN division_empresa d ON u.id_division = d.id
            LEFT JOIN perfil p ON u.id_perfil = p.id";
    
    // Construir las condiciones WHERE basadas en los filtros
    $condiciones = [];
    $params = [];
    $tipos = '';
    
    if ($filtro['estado'] === 'activos') {
        $condiciones[] = "u.activo = ?";
        $params[] = 1;
        $tipos .= 'i';
    } elseif ($filtro['estado'] === 'inactivos') {
        $condiciones[] = "u.activo = ?";
        $params[] = 0;
        $tipos .= 'i';
    }
    
    if ($filtro['empresa'] !== 'todos') {
        $condiciones[] = "u.id_empresa = ?";
        $params[] = intval($filtro['empresa']);
        $tipos .= 'i';
    }
    
    if ($filtro['perfil'] !== 'todos') {
        $condiciones[] = "u.id_perfil = ?";
        $params[] = intval($filtro['perfil']);
        $tipos .= 'i';
    }
    
    if ($filtro['division'] !== 'todos') {
        $condiciones[] = "u.id_division = ?";
        $params[] = intval($filtro['division']);
        $tipos .= 'i';
    }
    
    // Si hay condiciones, agregarlas a la consulta
    if (count($condiciones) > 0) {
        $sql .= " WHERE " . implode(" AND ", $condiciones);
    }
    
    $sql .= " ORDER BY u.nombre ASC";
    
    // Preparar y ejecutar la consulta
    if ($stmt = $conn->prepare($sql)) {
        if (count($params) > 0) {
            $stmt->bind_param($tipos, ...$params);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        while($row = $result->fetch_assoc()){
            $usuarios[] = $row;
        }
        $stmt->close();
    }
    
    return $usuarios;
}

function obtenerUsuariosConActividad() {
    global $conn;
    $usuarios = [];

    // Consulta base
    $sql = "SELECT 
                u.id, 
                CONCAT(u.nombre, ' ', u.apellido) AS nombre_completo, 
                u.usuario AS nombre_login, 
                e.nombre AS nombre_empresa, 
                d.nombre AS nombre_division, 
                p.nombre AS nombre_perfil,
                DATE(u.fechaCreacion) AS fechaCreacion,
                DATE(u.last_login) AS UltimoLogin,
                u.login_count AS logeos,
                u.activo
            FROM usuario u
            LEFT JOIN empresa e ON u.id_empresa = e.id
            LEFT JOIN division_empresa d ON u.id_division = d.id
            LEFT JOIN perfil p ON u.id_perfil = p.id
            ORDER BY u.nombre ASC";

    // Preparar y ejecutar
    if ($stmt = $conn->prepare($sql)) {
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $usuarios[] = $row;
        }

        $stmt->close();
    }

    return $usuarios;
}

function contarDivisionesPorEmpresa($id_empresa) {
    global $conn;
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM division_empresa WHERE id_empresa = ?");
    if ($stmt === false) {
        error_log("Error en la preparación de la consulta de conteo de divisiones: " . $conn->error);
        return 0;
    }
    $stmt->bind_param("i", $id_empresa);
    if (!$stmt->execute()) {
        error_log("Error en la ejecución de la consulta de conteo de divisiones: " . $stmt->error);
        $stmt->close();
        return 0;
    }
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $total = intval($row['total']);
    $stmt->close();
    return $total;
}

function obtenerDivisionesPorEmpresa($id_empresa) {
    global $conn;
    $stmt = $conn->prepare("SELECT id, nombre FROM division_empresa WHERE id_empresa = ? and estado = 1");
    $stmt->bind_param("i", $id_empresa);
    $stmt->execute();
    $result = $stmt->get_result();

    $divisiones = [];
    while ($row = $result->fetch_assoc()) {
        $divisiones[] = $row;
    }
    $stmt->close();
    return $divisiones;
}


function insertarUsuario($rut, $nombre, $apellido, $telefono, $email, $usuario, $fotoPerfil, $clave, $id_perfil, $id_empresa, $id_division) {
    global $conn; 

    if ($id_division !== null) {
        // Cuando $id_division tiene un valor
        $sql = "INSERT INTO usuario (
            rut, nombre, apellido, telefono, email, usuario, fotoPerfil, clave,
            fechaCreacion, activo, id_perfil, id_empresa, id_division, login_count, last_login
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 1, ?, ?, ?, 1, NOW()
        )";

        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            echo "Error en la preparación de la consulta: " . $conn->error . "<br>";
            return false;
        }

        $stmt->bind_param(
            'ssssssssiii',
            $rut,
            $nombre,
            $apellido,
            $telefono,
            $email,
            $usuario,
            $fotoPerfil,
            $clave,
            $id_perfil,
            $id_empresa,
            $id_division
        );
    } else {
        // Cuando $id_division es NULL
        $sql = "INSERT INTO usuario (
            rut, nombre, apellido, telefono, email, usuario, fotoPerfil, clave,
            fechaCreacion, activo, id_perfil, id_empresa, login_count, last_login
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 1, ?, ?, 1, NOW()
        )";

        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            echo "Error en la preparación de la consulta: " . $conn->error . "<br>";
            return false;
        }

        $stmt->bind_param(
            'ssssssssii',
            $rut,
            $nombre,
            $apellido,
            $telefono,
            $email,
            $usuario,
            $fotoPerfil,
            $clave,
            $id_perfil,
            $id_empresa
        );
    }

    if ($stmt->execute()) {
        return true;
    } else {
        echo "Error en la ejecución de la consulta: " . $stmt->error . "<br>";
        return false;
    }
}





function insertarLocal($codigo, $nombre, $direccion, $id_cuenta, $id_cadena, $id_comuna, $id_empresa) {
    global $conn; 
    $sql = "INSERT INTO local (codigo, nombre, direccion, id_cuenta, id_cadena, id_comuna, id_empresa)
            VALUES (?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        echo "Error en la preparación de la consulta: " . $conn->error . "<br>";
        return false;
    }

    // codigo, nombre, direccion: strings; id_cuenta, id_cadena, id_comuna, id_empresa: enteros
    $stmt->bind_param('sssiiii', $codigo, $nombre, $direccion, $id_cuenta, $id_cadena, $id_comuna, $id_empresa);

    if ($stmt->execute()) {
        return true;
    } else {
        echo "Error en la ejecución de la consulta: " . $stmt->error . "<br>";
        return false;
    }
}
function obtenerLocalPorId($local_id) {
    global $conn; // Asegúrate de que $conn es la conexión a la base de datos

    // Preparar la consulta con JOIN para obtener nombres relacionados
    $stmt = $conn->prepare("
        SELECT 
            l.id,
            l.codigo,
            l.nombre,
            l.direccion,
            l.id_cuenta,
            l.id_cadena,
            l.id_comuna,
            l.id_empresa,
            l.lat,
            l.lng,
            com.comuna,
            r.region,
            e.nombre AS empresa,
            cu.nombre AS cuenta,
            ca.nombre AS cadena
        FROM local l
        JOIN comuna com ON l.id_comuna = com.id
        JOIN region r ON com.id_region = r.id
        JOIN empresa e ON l.id_empresa = e.id
        JOIN cuenta cu ON l.id_cuenta = cu.id
        JOIN cadena ca ON l.id_cadena = ca.id
        WHERE l.id = ?
        LIMIT 1
    ");

    if ($stmt === false) {
        error_log("Error en la preparación de la consulta: " . $conn->error);
        return null;
    }

    $stmt->bind_param("i", $local_id);

    if (!$stmt->execute()) {
        error_log("Error en la ejecución de la consulta: " . $stmt->error);
        $stmt->close();
        return null;
    }

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $stmt->close();
        return $row;
    }

    $stmt->close();
    return null;
}

function obtenerDetalleLocal(int $local_id): ?array {
    global $conn;

    $sql = "
    SELECT 
        l.id,
        l.id_division                 AS division_id,
        l.codigo,
        l.nombre,
        l.direccion,
        l.id_cuenta,
        l.id_cadena,
        l.id_comuna,
        l.lat,
        l.lng,
        l.id_empresa,
        l.id_canal,
        l.id_subcanal,
        l.id_zona,
        l.id_distrito,
        l.id_jefe_venta,
        l.id_vendedor,

        co.id_region                  AS region_id,

        -- Nombres para fallbacks/UX
        c.nombre                      AS cuenta_nombre,
        ca.nombre                     AS cadena_nombre,
        co.comuna                     AS comuna_nombre,
        r.region                      AS region_nombre,

        cn.nombre_canal,
        sc.nombre_subcanal,
        zn.nombre_zona,
        dt.nombre_distrito,
        jv.nombre                     AS jefe_venta_nombre,
        vd.nombre_vendedor
    FROM local l
    LEFT JOIN comuna     co ON co.id  = l.id_comuna
    LEFT JOIN region     r  ON r.id   = co.id_region
    LEFT JOIN cuenta     c  ON c.id   = l.id_cuenta
    LEFT JOIN cadena     ca ON ca.id  = l.id_cadena
    LEFT JOIN canal      cn ON cn.id  = l.id_canal
    LEFT JOIN subcanal   sc ON sc.id  = l.id_subcanal
    LEFT JOIN zona       zn ON zn.id  = l.id_zona
    LEFT JOIN distrito   dt ON dt.id  = l.id_distrito
    LEFT JOIN jefe_venta jv ON jv.id  = l.id_jefe_venta
    LEFT JOIN vendedor   vd ON vd.id  = l.id_vendedor
    WHERE l.id = ?
    LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('[obtenerDetalleLocal] prepare() falló: ' . $conn->error);
        return null;
    }
    $stmt->bind_param("i", $local_id);
    if (!$stmt->execute()) {
        error_log('[obtenerDetalleLocal] execute() falló: ' . $stmt->error);
        $stmt->close();
        return null;
    }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$row) return null;

    return [
        'id'                => $row['id'],
        'codigo'            => $row['codigo'],
        'nombre'            => $row['nombre'],
        'direccion'         => $row['direccion'],

        'cuenta_id'         => $row['id_cuenta'],
        'cadena_id'         => $row['id_cadena'],
        'comuna_id'         => $row['id_comuna'],
        'region_id'         => $row['region_id'],

        'lat'               => $row['lat'],
        'lng'               => $row['lng'],

        'empresa_id'        => $row['id_empresa'],
        'canal_id'          => $row['id_canal'],
        'subcanal_id'       => $row['id_subcanal'],
        'zona_id'           => $row['id_zona'],
        'distrito_id'       => $row['id_distrito'],
        'jefe_venta_id'     => $row['id_jefe_venta'],
        'vendedor_id'       => $row['id_vendedor'],
        'division_id'       => $row['division_id'],

        'nombre_canal'      => $row['nombre_canal'],
        'nombre_subcanal'   => $row['nombre_subcanal'],
        'nombre_zona'       => $row['nombre_zona'],
        'nombre_distrito'   => $row['nombre_distrito'],
        'jefe_venta_nombre' => $row['jefe_venta_nombre'],
        'nombre_vendedor'   => $row['nombre_vendedor'],

        // extras útiles para fallback por texto en el front
        'cuenta_nombre'     => $row['cuenta_nombre'],
        'cadena_nombre'     => $row['cadena_nombre'],
        'comuna_nombre'     => $row['comuna_nombre'],
        'region_nombre'     => $row['region_nombre']
    ];
}

function actualizarLocal(
    $id,
    $codigo,
    $nombre,
    $direccion,
    $cuenta_id,
    $cadena_id,
    $comuna_id,
    $empresa_id,
    $division_id, 
    $lat,
    $lng,
    $canal_id,
    $subcanal_id,
    $zona_id,
    $distrito_id,
    $jefe_venta_id,
    $vendedor_id
) {
    global $conn;

    $sql = "UPDATE local
            SET codigo        = ?,
                nombre        = ?,
                direccion     = ?,
                id_cuenta     = ?,
                id_cadena     = ?,
                id_comuna     = ?,
                id_empresa    = ?,
                id_division   = NULLIF(?, 0), 
                lat           = ?,
                lng           = ?,
                id_canal      = ?,
                id_subcanal   = ?,
                id_zona       = ?,
                id_distrito   = ?,
                id_jefe_venta = ?,
                id_vendedor   = ?
            WHERE id = ?";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $_SESSION['error_local'] = "Error preparando la actualización: " . $conn->error;
        return false;
    }

    // Tipo de parámetros: 3 strings, 7 enteros, 2 double, 5 enteros, 1 entero para id final => total 16 parámetros
    // La cadena de tipos es: "sssiiiiddiiiiiii"
    $stmt->bind_param(
        "sssiiiiiddiiiiiii",
        $codigo,
        $nombre,
        $direccion,
        $cuenta_id,
        $cadena_id,
        $comuna_id,
        $empresa_id,
        $division_id, 
        $lat,
        $lng,
        $canal_id,
        $subcanal_id,
        $zona_id,
        $distrito_id,
        $jefe_venta_id,
        $vendedor_id,
        $id
    );

    $resultado = $stmt->execute();
    $stmt->close();
    return $resultado;
}



function obtenerCadenasPorCuenta($cuenta_id) {
    global $conn;
    $cadenas = [];

    $sql = "SELECT id, nombre FROM cadena WHERE id_cuenta = ? ORDER BY nombre ASC";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("i", $cuenta_id);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $cadenas[] = $row;
        }

        $stmt->close();
    }

    return $cadenas;
}

function obtenerComunas() {
    global $conn; 
    $sql = "SELECT id, comuna FROM comuna"; 
    $result = mysqli_query($conn, $sql);

    $comuna = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $comuna[] = $row;
        }
    }
    return $comuna;
}

function obtenerComunasPorRegion($region_id) {
    global $conn;

    $stmt = $conn->prepare("SELECT id, comuna FROM comuna WHERE id_region = ? ORDER BY comuna ASC");
    if ($stmt === false) {
        error_log("Error en la preparación de la consulta: " . $conn->error);
        return false;
    }

    $stmt->bind_param("i", $region_id);

    if (!$stmt->execute()) {
        error_log("Error en la ejecución de la consulta: " . $stmt->error);
        $stmt->close();
        return false;
    }

    $result = $stmt->get_result();
    $comunas = [];

    while ($row = $result->fetch_assoc()) {
        $comunas[] = $row;
    }

    $stmt->close();
    return $comunas;
}


function obtenerLocalesFiltrados($filtros = [], $offset = 0, $limit = 50) {
    global $conn;
    $locales = [];

    $sql = "SELECT 
                l.id,
                l.codigo,
                l.nombre,
                l.direccion,
                c.nombre AS cuenta,
                ca.nombre AS cadena,
                co.comuna,
                r.region,
                e.nombre AS empresa,
                l.lat,
                l.lng,
                can.nombre_canal AS canal,
                s.nombre_subcanal AS subcanal,
                d.nombre AS division
            FROM local l
            JOIN cuenta c ON l.id_cuenta = c.id
            JOIN cadena ca ON l.id_cadena = ca.id
            JOIN comuna co ON l.id_comuna = co.id
            JOIN region r ON co.id_region = r.id
            JOIN empresa e ON l.id_empresa = e.id
            LEFT JOIN canal can ON l.id_canal = can.id
            LEFT JOIN subcanal s ON l.id_subcanal = s.id
            LEFT JOIN division_empresa d ON l.id_division = d.id
            WHERE e.activo = 1"; // Solo activos

    $params = [];
    $tipos  = '';

    // Filtros (por empresa, región, comuna, canal, subcanal, división)
    if (!empty($filtros['empresa_id'])) {
        $sql .= " AND e.id = ?";
        $params[] = intval($filtros['empresa_id']);
        $tipos   .= 'i';
    }
    if (!empty($filtros['region_id'])) {
        $sql .= " AND r.id = ?";
        $params[] = intval($filtros['region_id']);
        $tipos   .= 'i';
    }
    if (!empty($filtros['comuna_id'])) {
        $sql .= " AND co.id = ?";
        $params[] = intval($filtros['comuna_id']);
        $tipos   .= 'i';
    }
    if (!empty($filtros['canal_id'])) {
        $sql .= " AND can.id = ?";
        $params[] = intval($filtros['canal_id']);
        $tipos   .= 'i';
    }
    if (!empty($filtros['subcanal_id'])) {
        $sql .= " AND s.id = ?";
        $params[] = intval($filtros['subcanal_id']);
        $tipos   .= 'i';
    }
    if (!empty($filtros['division_id'])) {
        $sql .= " AND d.id = ?";
        $params[] = intval($filtros['division_id']);
        $tipos   .= 'i';
    }

    // NUEVO: filtro por código local
    if (!empty($filtros['codigo'])) {
        $sql .= " AND l.codigo LIKE ?";
        $params[] = '%' . $filtros['codigo'] . '%';
        $tipos   .= 's';
    }
    
    
   if (!empty($filtros['id_local'])) {
    $sql .= " AND l.id = ?";
    $params[] = intval($filtros['id_local']);
    $tipos   .= 'i';
}

    // Filtro por nombre
    if (!empty($filtros['nombre'])) {
        $sql .= " AND l.nombre LIKE ?";
        $params[] = '%' . $filtros['nombre'] . '%';
        $tipos   .= 's';
    }

    // Orden y paginación
    $sql .= " ORDER BY l.nombre ASC
              LIMIT ? OFFSET ?";
    $params[] = intval($limit);
    $params[] = intval($offset);
    $tipos   .= 'ii';

    if ($stmt = $conn->prepare($sql)) {
        if (!empty($params)) {
            $stmt->bind_param($tipos, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $locales[] = $row;
        }
        $stmt->close();
    }
    return $locales;
}

function obtenerIdDivision($nombre_division, $id_empresa) {
    global $conn;
    $stmt = $conn->prepare("SELECT id FROM division_empresa WHERE nombre = ? AND id_empresa = ?");
    if ($stmt === false) {
        die("Error en la preparación de la consulta de división: " . $conn->error);
    }
    $stmt->bind_param("si", $nombre_division, $id_empresa);
    $stmt->execute();

    $id_division = null; // Inicializar variable
    $stmt->bind_result($id_division);

    if ($stmt->fetch()) {
        $stmt->close();
        return $id_division;
    } else {
        $stmt->close();
        return null; // Manejar caso donde no se encuentra la división
    }
}


function subdivisionPerteneceADivision($id_subdivision, $id_division) {
    global $conn;
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM subdivision WHERE id = ? AND id_division = ?");
    if (!$stmt) return false;
    $stmt->bind_param("ii", $id_subdivision, $id_division);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();
    return intval($row['c'] ?? 0) > 0;
}

function obtenerNombreSubdivision($id_subdivision) {
    global $conn;
    $stmt = $conn->prepare("SELECT nombre FROM subdivision WHERE id = ?");
    if (!$stmt) return null;
    $stmt->bind_param("i", $id_subdivision);
    $stmt->execute();
    $stmt->bind_result($nombre);
    $ok = $stmt->fetch();
    $stmt->close();
    return $ok ? $nombre : null;
}


function obtenerSubdivisionesPorDivision($id_division) {
    global $conn;
    $stmt = $conn->prepare("SELECT id, nombre FROM subdivision WHERE id_division = ? ORDER BY nombre ASC");
    if (!$stmt) return [];
    $stmt->bind_param("i", $id_division);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) { $rows[] = $r; }
    $stmt->close();
    return $rows;
}

function contarSubdivisionesPorDivision($id_division) {
    global $conn;
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM subdivision WHERE id_division = ?");
    if (!$stmt) return 0;
    $stmt->bind_param("i", $id_division);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();
    return intval($row['total'] ?? 0);
}


function obtenerNombreEmpresa($empresa_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT nombre FROM empresa WHERE id = ?");
    if ($stmt === false) {
        throw new Exception("Error en la preparación de la consulta: " . $conn->error);
    }
    $stmt->bind_param("i", $empresa_id);
    $stmt->execute();
    $stmt->bind_result($nombre_empresa);
    if ($stmt->fetch()) {
        $stmt->close();
        return $nombre_empresa;
    }
    $stmt->close();
    throw new Exception("Empresa no encontrada.");
}

function obtenerRegiones() {
    global $conn; 
    $sql = "SELECT id, region FROM region order by region asc"; 
    $result = mysqli_query($conn, $sql);

    $region = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $region[] = $row;
        }
    }
    return $region;
}
?>