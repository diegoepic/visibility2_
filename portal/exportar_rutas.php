<?php
// ConfiguraciÃ³n de cabecera
header('Content-Type: application/json');

// Verifica si se recibieron datos
if (!isset($_POST['ubicaciones'])) {
    echo json_encode(['success' => false, 'message' => 'No se recibieron ubicaciones']);
    exit;
}

$ubicaciones = json_decode($_POST['ubicaciones'], true);

// API Key de Google Maps
$apiKey = 'AIzaSyAkWMIwHuWxwVkC-1Tk208gNRUBbwqZYIQ';

// Archivo temporal para guardar el CSV
$filename = 'rutas_exportadas.csv';
$filepath = $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/exportaciones/' . $filename;

// Crear carpeta si no existe
if (!file_exists($_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/exportaciones')) {
    mkdir($_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/exportaciones', 0777, true);
}

// Abrir el archivo CSV para escritura
$csv = fopen($filepath, 'w');

// Escribir la cabecera del CSV
fputcsv($csv, ['Direcci¨®n', 'Comuna', 'Latitud', 'Longitud'], ';');

foreach ($ubicaciones as $ubicacion) {
    $lat = $ubicacion['lat'];
    $lng = $ubicacion['lng'];

    // Obtener la direcciÃ³n usando Geocoding API
    $url = "https://maps.googleapis.com/maps/api/geocode/json?latlng=$lat,$lng&key=$apiKey";
    $response = file_get_contents($url);
    $data = json_decode($response, true);

    if ($data['status'] === 'OK') {
        $direccion = $data['results'][0]['formatted_address'];
        
        // Extraer comuna (administrative_area_level_3 o level_2)
        $comuna = '';
        foreach ($data['results'][0]['address_components'] as $component) {
            if (in_array('administrative_area_level_3', $component['types']) || in_array('administrative_area_level_2', $component['types'])) {
                $comuna = $component['long_name'];
                break;
            }
        }
    } else {
        $direccion = 'No encontrada';
        $comuna = 'No encontrada';
    }
    // Escribir en el archivo CSV usando ; como delimitador
    fputcsv($csv, [$direccion, $comuna, $lat, $lng], ';');
}

fclose($csv);

// Enviar respuesta al frontend
echo json_encode(['success' => true, 'file' => '/visibility2/portal/exportaciones/' . $filename]);
exit;
?>