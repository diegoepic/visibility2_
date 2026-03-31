<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

if (isset($_GET['empresa_id']) && isset($_GET['division_id'])) {
    $empresa_id  = $conn->real_escape_string($_GET['empresa_id']);
    $division_id = $conn->real_escape_string($_GET['division_id']);

    // Ahora ordenamos por 'orden' ASC
    $queryDash = "
      SELECT *
      FROM dashboard_items
      WHERE id_empresa  = '$empresa_id'
        AND id_division = '$division_id'
      ORDER BY orden ASC
    ";
    $resultDash = $conn->query($queryDash);

    if ($resultDash->num_rows > 0) {
        echo '<table class="table table-bordered table-hover">';
        echo '<thead>
                <tr>
                  <th style="width:5%;">ID</th>
                  <th style="width:5%;">Orden</th>
                  <th style="width:15%;">Imagen</th>
                  <th style="width:20%;">Codigo Iframe</th>
                  <th style="width:15%;">Etiqueta Principal</th>
                  <th style="width:15%;">Etiqueta Secundaria</th>
                  <th style="width:10%;">Icono</th>
                  <th style="width:5%;">Activo</th>
                  <th style="width:10%;">Creado</th>
                  <th style="width:10%;">Accion</th>
                </tr>
              </thead>
              <tbody>';
        while ($row = $resultDash->fetch_assoc()) {
            echo '<tr>';
              // Un 迆nico <td> con todo el form
              echo '<td colspan="10">';
                echo '<form method="post" action="modulos/actualizar_dashboard_item.php" enctype="multipart/form-data">';
                  echo '<table style="width:100%;">';
                    echo '<tr>';
                      // ID
                      echo '<td style="width:5%;">' . $row['id'] . '</td>';

                      // Orden (input number)
                      echo '<td style="width:7%;">';
                        echo '<input type="number" name="orden" class="form-control form-control-sm" '
                           . 'value="' . intval($row['orden']) . '" min="1">';
                      echo '</td>';

                      // Imagen
                      echo '<td style="width:15%;">';
                        echo '<img src="' . htmlspecialchars($row['image_url']) . '" '
                           . 'alt="" style="max-width:100px; margin-bottom:5px; display:block;">';
                        echo '<input type="file" name="image" class="form-control form-control-sm">';
                      echo '</td>';

                      // C車digo Iframe
                      echo '<td style="width:20%;">';
                        echo '<textarea name="target_url" class="form-control form-control-sm" rows="3">'
                           . htmlspecialchars($row['target_url'])
                           . '</textarea>';
                      echo '</td>';

                      // Main label
                      echo '<td style="width:15%;">';
                        echo '<input type="text" name="main_label" class="form-control form-control-sm" '
                           . 'value="' . htmlspecialchars($row['main_label']) . '">';
                      echo '</td>';

                      // Sub label
                      echo '<td style="width:15%;">';
                        echo '<input type="text" name="sub_label" class="form-control form-control-sm" '
                           . 'value="' . htmlspecialchars($row['sub_label']) . '">';
                      echo '</td>';

                      // Icon class
                      echo '<td style="width:10%;">';
                        echo '<input type="text" name="icon_class" class="form-control form-control-sm" '
                           . 'value="' . htmlspecialchars($row['icon_class']) . '">';
                      echo '</td>';

                      // Activo checkbox
                      echo '<td style="width:5%; text-align:center;">';
                        echo '<input type="checkbox" name="is_active" '
                           . ($row['is_active'] ? 'checked' : '') . '>';
                      echo '</td>';

                      // Created at
                      echo '<td style="width:10%;">' . htmlspecialchars($row['created_at']) . '</td>';

                      // Bot車n guardar
                      echo '<td style="width:10%;">';
                        // Campo oculto ID y empresa/divisi車n
                        echo '<input type="hidden" name="id" value="' . $row['id'] . '">';
                        echo '<button type="submit" class="btn btn-sm btn-primary">Guardar</button>';
                      echo '</td>';
                    echo '</tr>';
                  echo '</table>';
                echo '</form>';
              echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p>No hay dashboard items creados para esta empresa y divisi車n.</p>';
    }
}
?>

