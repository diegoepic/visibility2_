<?php
// Conexión, validación y consultas similares para obtener los datos...
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=rutas.csv');

$output = fopen('php://output', 'w');
// Escribe la cabecera
fputcsv($output, ['Local', 'Direccion', 'Estado', 'Fecha Visita', 'Pregunta']);

// Supón que $locales es el array obtenido (similar a tu consulta)
foreach ($locales as $loc) {
    // Puedes formatear el estado según tu lógica
    $prg = !empty($loc['pregunta']) ? $loc['pregunta'] : '-';
    if (in_array($prg, ['solo_auditoria', 'solo_implementado', 'implementado_auditado'])) {
        $estado = 'Compl.';
    } elseif ($prg === '-') {
        $estado = 'Pend.';
    } else {
        $estado = 'Cancel.';
    }
    fputcsv($output, [
      $loc['nombre_local'],
      $loc['direccion_local'],
      $estado,
      $loc['fechaVisita'],
      $prg
    ]);
}
fclose($output);
exit();
