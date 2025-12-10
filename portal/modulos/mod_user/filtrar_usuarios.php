<?php
// mod_user/filtrar_usuarios.php

session_start();

// Incluir archivos necesarios
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

// Obtener los parámetros de filtro desde la solicitud GET
$estado = isset($_GET['filtro_estado']) ? $_GET['filtro_estado'] : 'todos';
$empresa = isset($_GET['filtro_empresa']) ? $_GET['filtro_empresa'] : 'todos';
$perfil = isset($_GET['filtro_perfil']) ? $_GET['filtro_perfil'] : 'todos';
$division = isset($_GET['filtro_division']) ? $_GET['filtro_division'] : 'todos';

// Validar los filtros
$estado = strtolower($estado);
$empresa = strtolower($empresa);
$perfil = strtolower($perfil);
$division = strtolower($division);

// Validar los valores permitidos
$estado = in_array($estado, ['todos', 'activos', 'inactivos']) ? $estado : 'todos';
$empresa = ($empresa === 'todos' || is_numeric($empresa)) ? $empresa : 'todos';
$perfil = ($perfil === 'todos' || is_numeric($perfil)) ? $perfil : 'todos';
$division = ($division === 'todos' || is_numeric($division)) ? $division : 'todos';

// Preparar el arreglo de filtros
$filtro = [
    'estado' => $estado,
    'empresa' => $empresa,
    'perfil' => $perfil,
    'division' => $division
];

// Obtener los usuarios filtrados
$usuarios = obtenerUsuarios($filtro);

// Preparar los datos para enviar
$data = [];
foreach ($usuarios as $usuario) {
    $data[] = [
        'id' => $usuario['id'],
        'nombre_completo' => htmlspecialchars($usuario['nombre_completo']),
        'nombre_login' => htmlspecialchars($usuario['nombre_login']),
        'nombre_empresa' => htmlspecialchars($usuario['nombre_empresa']),
        'nombre_division' => htmlspecialchars(!empty($usuario['nombre_division']) ? $usuario['nombre_division'] : 'N/A'),
        'nombre_perfil' => htmlspecialchars($usuario['nombre_perfil']),
        'activo' => $usuario['activo'] ? '<span class="badge badge-success">Activo</span>' : '<span class="badge badge-danger">Inactivo</span>',
        'acciones' => ''
    ];
    
    // Determinar las acciones disponibles
    if ($usuario['activo']) {
        $acciones = '
            <button type="button" class="btn btn-sm btn-primary editar-usuario-btn" data-id="' . $usuario['id'] . '">
                <i class="fas fa-edit"></i> Editar
            </button>
            <button type="button" class="btn btn-sm btn-danger eliminar-usuario-btn" data-id="' . $usuario['id'] . '">
                <i class="fas fa-trash-alt"></i> Eliminar
            </button>
        ';
    } else {
        $acciones = '
            <button type="button" class="btn btn-sm btn-secondary reactivar-usuario-btn" data-id="' . $usuario['id'] . '">
                <i class="fas fa-undo"></i> Reactivar
            </button>
        ';
    }
    
    $data[count($data)-1]['acciones'] = $acciones;
}

echo json_encode(['data' => $data]);
?>

