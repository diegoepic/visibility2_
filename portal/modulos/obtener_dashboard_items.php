<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

if (isset($_GET['empresa_id']) && isset($_GET['division_id'])) {
    $empresa_id  = $conn->real_escape_string($_GET['empresa_id']);
    $division_id = $conn->real_escape_string($_GET['division_id']);

    $queryDash = "
      SELECT *
      FROM dashboard_items
      WHERE id_empresa  = '$empresa_id'
        AND id_division = '$division_id'
      ORDER BY orden ASC
    ";
    $resultDash = $conn->query($queryDash);

    if ($resultDash && $resultDash->num_rows > 0) {
        echo '<div class="dashboard-table-wrap">';
        echo '<table class="table table-bordered table-hover dashboard-items-table">';
        echo '<thead>
                <tr>
                  <th>ID</th>
                  <th>Orden</th>
                  <th>Imagen</th>
                  <th>Código Iframe</th>
                  <th>Etiqueta Principal</th>
                  <th>Etiqueta Secundaria</th>
                  <th>Ícono</th>
                  <th>Activo</th>
                  <th>Creado</th>
                  <th>Acción</th>
                </tr>
              </thead>
              <tbody>';

        while ($row = $resultDash->fetch_assoc()) {
            $id         = (int)$row['id'];
            $orden      = (int)$row['orden'];
            $image_url  = htmlspecialchars((string)$row['image_url'], ENT_QUOTES, 'UTF-8');
            $target_url = htmlspecialchars((string)$row['target_url'], ENT_QUOTES, 'UTF-8');
            $main_label = htmlspecialchars((string)$row['main_label'], ENT_QUOTES, 'UTF-8');
            $sub_label  = htmlspecialchars((string)$row['sub_label'], ENT_QUOTES, 'UTF-8');
            $icon_class = htmlspecialchars((string)$row['icon_class'], ENT_QUOTES, 'UTF-8');
            $created_at = htmlspecialchars((string)$row['created_at'], ENT_QUOTES, 'UTF-8');
            $is_active  = !empty($row['is_active']) ? 'checked' : '';

echo '<tr class="dashboard-item-row" data-id="' . $id . '">';

// ID
echo '<td class="col-id">';
echo $id;
echo '</td>';

// Orden
echo '<td class="col-orden">';
echo '<input type="number" name="orden" class="table-input" value="' . $orden . '" min="1">';
echo '</td>';

// Imagen
echo '<td class="col-imagen text-center">';
echo '  <button 
            type="button"
            class="image-thumb-btn js-open-image-modal"
            data-id="' . $id . '"
            data-image="' . $image_url . '"
            data-main="' . $main_label . '"
            title="Ver o cambiar imagen"
        >';
echo '      <img src="' . $image_url . '" alt="Imagen dashboard" class="table-thumb">';
echo '      <span class="thumb-overlay">Cambiar</span>';
echo '  </button>';
echo '</td>';

// Código iframe / target_url
echo '<td class="col-iframe">';
echo '<textarea name="target_url" class="table-textarea" rows="3">' . $target_url . '</textarea>';
echo '</td>';

// Etiqueta principal
echo '<td class="col-main">';
echo '<input type="text" name="main_label" class="table-input" value="' . $main_label . '">';
echo '</td>';

// Etiqueta secundaria
echo '<td class="col-sub">';
echo '<input type="text" name="sub_label" class="table-input" value="' . $sub_label . '">';
echo '</td>';

// Ícono
echo '<td class="col-icon">';
echo '<input type="text" name="icon_class" class="table-input" value="' . $icon_class . '">';
echo '</td>';

// Activo
echo '<td class="col-active text-center">';
echo '<label class="table-check-wrap">';
echo '<input type="checkbox" name="is_active" ' . $is_active . '>';
echo '</label>';
echo '</td>';

// Creado
echo '<td class="col-created">';
echo $created_at;
echo '</td>';

// Acción
echo '<td class="col-action text-center">';
echo '<button type="button" class="btn btn-sm btn-primary table-save-btn js-save-dashboard-row">Guardar</button>';
echo '</td>';

echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    } else {
        echo '<div class="empty-dashboard-items">';
        echo 'No hay dashboard items creados para esta empresa y división.';
        echo '</div>';
    }
}
?>