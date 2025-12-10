<?php
// Activar la visualización de errores para depuración (desactivar en producción)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Incluir el archivo de base de datos y funciones necesarias
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

// Iniciar la sesión para manejar mensajes de éxito y error
session_start();

// ––– BLOQUE CSRF –––
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_POST['csrf_token'])
    || $_POST['csrf_token'] !== $_SESSION['csrf_token']
) {
    $_SESSION['error_formulario'] = "Solicitud inválida (CSRF).";
    header("Location: ../mod_create_user.php");
    exit();
}


// Verificar que la solicitud se realiza mediante POST
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    // Verificar si se ha subido un archivo
    if (isset($_FILES['csvFile']) && $_FILES['csvFile']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['csvFile']['tmp_name'];
        $fileName = $_FILES['csvFile']['name'];
        $fileSize = $_FILES['csvFile']['size'];
        $fileType = $_FILES['csvFile']['type'];
        $fileNameCmps = explode(".", $fileName);
        $fileExtension = strtolower(end($fileNameCmps));

        // Verificar la extensión del archivo
        if ($fileExtension !== 'csv') {
            $_SESSION['error_formulario'] = "Solo se permiten archivos CSV.";
            header("Location: ../mod_create_user.php");
            exit();
        }

        // Definir el directorio de destino
        $uploadFileDir = '../uploads/csv/';
        if (!is_dir($uploadFileDir)) {
            if (!mkdir($uploadFileDir, 0755, true)) {
                $_SESSION['error_formulario'] = "No se pudo crear el directorio de subida.";
                header("Location: ../mod_create_user.php");
                exit();
            }
        }
        $newFileName = 'usuarios_' . time() . '.' . $fileExtension;
        $dest_path = $uploadFileDir . $newFileName;

        // Mover el archivo al directorio de destino
        if (!move_uploaded_file($fileTmpPath, $dest_path)) {
            $_SESSION['error_formulario'] = "Hubo un error al subir el archivo CSV.";
            header("Location: ../mod_create_user.php");
            exit();
        }

        // Procesar el archivo CSV con delimitador ','
        $handle = fopen($dest_path, 'r');
        if ($handle === FALSE) {
            $_SESSION['error_formulario'] = "No se pudo abrir el archivo CSV.";
            header("Location: ../mod_create_user.php");
            exit();
        }

        // Asumimos que la primera fila contiene los encabezados
        $header = fgetcsv($handle, 1000, ";");
        if ($header === FALSE) {
            $_SESSION['error_formulario'] = "El archivo CSV está vacío.";
            fclose($handle);
            unlink($dest_path); // Eliminar el archivo tras el error
            header("Location: ../mod_create_user.php");
            exit();
        }

        // Lista de columnas requeridas (excluyendo 'division' y 'foto_perfil')
        $columnas_requeridas = ['rut', 'nombre', 'apellido', 'telefono', 'email', 'usuario', 'password'];

        // Normalizar los encabezados: minúsculas y sin espacios
        $header_normalizado = array_map(function($col) {
            return strtolower(trim($col));
        }, $header);

        // Verificar que todas las columnas requeridas estén presentes
        $columnas_faltantes = array_diff($columnas_requeridas, $header_normalizado);


        if (!empty($columnas_faltantes)) {
            $encabezados_encontrados = implode(", ", $header_normalizado);
            $_SESSION['error_formulario'] = "El archivo CSV no contiene las columnas requeridas: " . implode(", ", $columnas_faltantes) . ".<br>Encabezados encontrados: " . $encabezados_encontrados;
            fclose($handle);
            unlink($dest_path); // Eliminar el archivo tras el error
            header("Location: ../mod_create_user.php");
            exit();
        }

        // Obtener los índices de las columnas
        $rut_idx = array_search('rut', $header_normalizado);
        $nombre_idx = array_search('nombre', $header_normalizado);
        $apellido_idx = array_search('apellido', $header_normalizado);
        $telefono_idx = array_search('telefono', $header_normalizado);
        $email_idx = array_search('email', $header_normalizado);
        $usuario_idx = array_search('usuario', $header_normalizado);
        $password_idx = array_search('password', $header_normalizado);

        // Preparar consulta para insertar en usuario
        $stmt_insert = $conn->prepare("
            INSERT INTO usuario 
                (rut, nombre, apellido, telefono, email, usuario, clave, fechaCreacion, activo, id_perfil, id_empresa, id_division, login_count, last_login) 
            VALUES 
                (?, ?, ?, ?, ?, ?, ?, NOW(), 1, ?, ?, ?, 1, NOW())
        ");
        if (!$stmt_insert) {
            $_SESSION['error_formulario'] = "Error en la preparación de la consulta de usuario: " . htmlspecialchars($conn->error, ENT_QUOTES, 'UTF-8');
            fclose($handle);
            unlink($dest_path);
            header("Location: ../mod_create_user.php");
            exit();
        }

        // Contador de fila para identificar errores
        $fila = 1; // Ya se ha leído el encabezado

        // Arreglo para almacenar errores
        $errores = [];
        $exitosos = 0;

        // Obtener el ID del perfil 'ejecutor'
        $id_perfil_ejecutor = obtenerIdPerfilEjecutor();
        if ($id_perfil_ejecutor === null) {
            $_SESSION['error_formulario'] = "Perfil 'ejecutor' no encontrado en el sistema.";
            fclose($handle);
            unlink($dest_path);
            header("Location: ../mod_create_user.php");
            exit();
        }

        // Obtener el ID de la empresa para la carga masiva
        if (isset($_POST['empresa_id_csv']) && !empty($_POST['empresa_id_csv'])) {
            $id_empresa_csv = intval($_POST['empresa_id_csv']);
        } else {
            $_SESSION['error_formulario'] = "ID de empresa no especificado en el formulario.";
            fclose($handle);
            unlink($dest_path);
            header("Location: ../mod_create_user.php");
            exit();
        }

        // Obtener la división desde el formulario
        if (isset($_POST['division_id']) && isset($_POST['division_id'])) {
            $id_division_csv = intval($_POST['division_id']);
        } else {
            $_SESSION['error_formulario'] = "ID de división no especificado en el formulario.";
            fclose($handle);
            unlink($dest_path);
            header("Location: ../mod_create_user.php");
            exit();
        }

        // Asignar la división directamente desde el formulario
        $id_division = $id_division_csv;
        error_log("Asignando id_division = " . $id_division);

        // Procesar cada fila del CSV
        while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
    $fila++;
    $rut_input = trim($data[$rut_idx]);
    $nombre = trim($data[$nombre_idx]);
    $apellido = trim($data[$apellido_idx]);
    $telefono = trim($data[$telefono_idx]);
    $email = trim($data[$email_idx]);
    $usuario = trim($data[$usuario_idx]);
    $clave = trim($data[$password_idx]);

    // Depurar los datos de la fila
    error_log("Fila {$fila}: RUT='{$rut_input}', Nombre='{$nombre}', Apellido='{$apellido}', Teléfono='{$telefono}', Email='{$email}', Usuario='{$usuario}', Password='{$clave}'");

            // Validar que los campos obligatorios no estén vacíos
            if (empty($rut_input) || empty($nombre) || empty($apellido) || empty($telefono) || empty($email) || empty($usuario) || empty($clave)) {
                $errores[] = "Fila {$fila}: Hay campos vacíos.";
                error_log("Fila {$fila}: Hay campos vacíos.");
                continue; // Saltar a la siguiente fila
            }

            // Estandarizar y validar el RUT
            $rut_estandarizado = preg_replace('/[^0-9kK]/', '', $rut_input);
            $rut_estandarizado = strtoupper($rut_estandarizado);

            if (!validarRut($rut_estandarizado)) {
                $errores[] = "Fila {$fila}: RUT inválido.";
                continue;
            }

            // Hashear la contraseña
            $hashed_password = password_hash($clave, PASSWORD_DEFAULT);

            // Asignar id_perfil fijo para 'ejecutor'
            $id_perfil = $id_perfil_ejecutor;

            // Verificar si el RUT ya existe
            if (existeRut($rut_estandarizado)) {
                $errores[] = "Fila {$fila}: El RUT '{$rut_estandarizado}' ya existe.";
                continue;
            }

            // Verificar si el email o nombre de usuario ya existen
            if (existeUsuario($email, $usuario)) {
                $errores[] = "Fila {$fila}: El email '{$email}' o el usuario '{$usuario}' ya están registrados.";
                continue;
            }

            // Insertar el usuario en la base de datos
            $stmt_insert->bind_param(
                'sssssssiii',
                $rut_estandarizado,
                $nombre,
                $apellido,
                $telefono,
                $email,
                $usuario,
                $hashed_password,
                $id_perfil,
                $id_empresa_csv,
                $id_division
            );

            if ($stmt_insert->execute()) {
                $exitosos++;
                error_log("Fila {$fila}: Usuario '{$usuario}' insertado correctamente.");
            } else {
                $errores[] = "Fila {$fila}: Error al insertar el usuario en la base de datos.";
                error_log("Fila {$fila}: Error al insertar usuario '{$usuario}': " . $stmt_insert->error);
            }
        }

        fclose($handle);
        $stmt_insert->close();

        // Eliminar el archivo CSV después de procesarlo
        unlink($dest_path);

        // Añadir mensajes de éxito y error a las variables de sesión
        if ($exitosos > 0) {
            $_SESSION['success_formulario'] = "Se insertaron {$exitosos} usuarios correctamente.";
        }

        if (!empty($errores)) {
            $_SESSION['error_formulario'] = "Errores durante la carga masiva:<br>" . implode("<br>", $errores);
        }

        // Redirigir al usuario
        header("Location: ../mod_create_user.php");
        exit();
    } else {
        // Si no se envió el formulario correctamente
        $_SESSION['error_formulario'] = "Método de solicitud inválido.";
        header("Location: ../mod_create_user.php");
        exit();
    }
}
?>

