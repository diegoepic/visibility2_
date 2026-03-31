<?php
// 1) Defino la ruta física base a “repositorio”
$baseDir = __DIR__ . '/repositorio';

// 2) Lista las carpetas existentes, como antes
$carpetas = array_filter(scandir($baseDir), function($f) use ($baseDir) {
    return $f !== '.' && $f !== '..' && is_dir("$baseDir/$f");
});

// 3) Función recursiva para listar archivos y devolver filas de tabla
function listarArchivosEnTabla($rutaFisica, $urlBase) {
    $items = scandir($rutaFisica);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $rutaActual = $rutaFisica . '/' . $item;
        if (is_dir($rutaActual)) {
            // Si es carpeta, llamamos recursivamente y pasamos la URL relativa
            listarArchivosEnTabla($rutaActual, $urlBase . '/' . rawurlencode($item));
        } else {
            // Si es archivo, obtenemos la fecha de última modificación
            $modTime    = filemtime($rutaActual);
            $fechaMod   = date('Y-m-d H:i:s', $modTime);
            // Construyo la URL pública completa (codifico el nombre de archivo)
            $urlArchivo = $urlBase . '/' . rawurlencode($item);

            // Imprimo una fila de tabla con Nombre, URL y Last Modified
            echo '<tr>';
            echo '  <td>' . htmlspecialchars($item) . '</td>';
            echo '  <td><a href="' . htmlspecialchars($urlArchivo) . '" target="_blank">'
                  . htmlspecialchars($urlArchivo) . '</a></td>';
            echo '  <td>' . htmlspecialchars($fechaMod) . '</td>';
            echo '</tr>';
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8" />
  <title>Subir Archivo</title>
  <!-- Bootstrap CSS (opcional si ya lo usas) -->
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>
<body>
  <div class="container-fluid mt-3">
    <div class="row">
      <div class="col-12">
        <div class="card mb-4">
          <div class="card-header bg-info">
            <h3 class="card-title">Subir archivo</h3>
          </div>
          <div class="card-body">
            <!-- Formulario cuya acción se manejará vía JavaScript -->
            <form id="formSubirArchivo" method="POST" enctype="multipart/form-data">
              <div class="form-group row">
                <label class="col-sm-2 col-form-label">Carpeta existente:</label>
                <div class="col-sm-4">
                  <select class="form-control" name="carpeta_existente">
                    <option value="">-- Ninguna --</option>
                    <?php foreach($carpetas as $c): ?>
                      <option value="<?= htmlspecialchars($c) ?>">
                        <?= htmlspecialchars($c) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>

              <div class="form-group row">
                <label class="col-sm-2 col-form-label">Crear carpeta nueva:</label>
                <div class="col-sm-4">
                  <input
                    type="text"
                    class="form-control"
                    name="carpeta_nueva"
                    placeholder="Nombre de carpeta..."
                  />
                </div>
              </div>

              <div class="form-group row">
                <label class="col-sm-2 col-form-label">Archivo (.ppt, .csv, .xlsx, .zip, .rar):</label>
                <div class="col-sm-4">
                  <input
                    type="file"
                    class="form-control"
                    name="mi_archivo"
                    accept=".ppt,.pptx,.csv,.xlsx,.zip,.rar"
                    required
                  />
                </div>
              </div>

              <div class="form-group row">
                <div class="col-sm-10 offset-sm-2">
                  <button
                    type="submit"
                    class="btn btn-primary"
                    id="btnSubir"
                  >
                    Subir
                  </button>
                </div>
              </div>
            </form>
          </div>
        </div>

        <div class="card">
          <div class="card-header bg-info">
            <h3 class="card-title">Archivos creados</h3>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-striped mb-0">
                <thead class="thead-light">
                  <tr>
                    <th>Nombre</th>
                    <th>URL completa</th>
                    <th>Last Modified</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                    $urlBase = 'https://visibility.cl/visibility2/portal/repositorio';
                    listarArchivosEnTabla($baseDir, $urlBase);
                  ?>
                </tbody>
              </table>
            </div>
          </div>    
        </div>    
      </div>
    </div>
  </div>

  <!-- jQuery + Bootstrap JS (opcional si usas Bootstrap) -->
  <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
  <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.bundle.min.js"></script>

  <script>
  document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('formSubirArchivo');
    const btnSubir = document.getElementById('btnSubir');

    form.addEventListener('submit', function(e) {
      e.preventDefault();
      btnSubir.disabled = true;               // Deshabilito botón mientras sube
      btnSubir.innerText = 'Subiendo...';

      // Construyo FormData con todos los campos del form
      const formData = new FormData(form);

      fetch('modulos/upload_archivo.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        btnSubir.disabled = false;
        btnSubir.innerText = 'Subir';

        if (data.error) {
          // Si viene “error” en JSON
          alert('Error: ' + data.error);
        }
        else if (data.exito) {
          // Éxito: muestro URL devuelta
          alert('¡Archivo subido con éxito!\nURL: ' + data.url);
          // Recargo la página para que se actualice la tabla de archivos
          location.reload();
        } else {
          // Cualquier otro caso inesperado
          alert('Respuesta inesperada:\n' + JSON.stringify(data));
        }
      })
      .catch(err => {
        btnSubir.disabled = false;
        btnSubir.innerText = 'Subir';
        alert('Ocurrió un error de red o servidor:\n' + err);
      });
    });
  });
  </script>
</body>
</html>
