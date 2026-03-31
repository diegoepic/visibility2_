<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    // 1) Recoger el ID y el nuevo orden
    $id      = $conn->real_escape_string($_POST['id']);
    $orden   = intval($_POST['orden']);  // campo numérico

    // 2) Recoger el resto de campos
    $target_url = $conn->real_escape_string($_POST['target_url']);
    $main_label = $conn->real_escape_string($_POST['main_label']);
    $sub_label  = $conn->real_escape_string($_POST['sub_label']);
    $icon_class = $conn->real_escape_string($_POST['icon_class']);
    $is_active  = isset($_POST['is_active']) ? 1 : 0;
    
    // 3) Construir la consulta de actualización, incluyendo 'orden'
    $updateQuery = "
        UPDATE dashboard_items
        SET 
            target_url  = '$target_url',
            main_label  = '$main_label',
            sub_label   = '$sub_label',
            icon_class  = '$icon_class',
            is_active   = '$is_active',
            orden       = '$orden'
    ";
    
    // 4) Procesar imagen si se subió una nueva
    if(isset($_FILES['image']) && $_FILES['image']['error'] == 0){
        $allowed = [
            "jpg"  => "image/jpeg",
            "jpeg" => "image/jpeg",
            "png"  => "image/png",
            "gif"  => "image/gif"
        ];
        $filename = $_FILES['image']['name'];
        $filetype = $_FILES['image']['type'];
        $filesize = $_FILES['image']['size'];
        $ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if(array_key_exists($ext, $allowed) 
           && in_array($filetype, $allowed) 
           && $filesize <= 5 * 1024 * 1024) 
        {
            $newFilename = uniqid() . "." . $ext;
            $destination = $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/uploads/dashboard/' . $newFilename;
            if(move_uploaded_file($_FILES['image']['tmp_name'], $destination)){
                $image_url = '/visibility2/portal/uploads/dashboard/' . $newFilename;
                // 5) Agregar al UPDATE la nueva URL de la imagen
                $updateQuery .= ", image_url = '$image_url'";
            }
        }
    }
    
    // 6) Finalizar WHERE
    $updateQuery .= " WHERE id = '$id'";
    
    // 7) Ejecutar
        header('Content-Type: application/json; charset=utf-8');
        if($conn->query($updateQuery) === TRUE){
            echo json_encode([ "success" => true ]);
        } else {
            echo json_encode([ "success" => false, "error" => $conn->error ]);
        }
        exit;
}
?>