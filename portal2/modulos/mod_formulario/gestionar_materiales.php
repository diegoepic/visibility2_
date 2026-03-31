<?php
// gestionar_materiales.php

session_start();


// Mostrar errores
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

/// requiere login
if (!isset($_SESSION['usuario_id'])) {
  header("Location: /visibility2/portal/index.php");
  exit();
}

function fetchAll($conn, $sql, $types = '', $params = []) {
  $stmt = $conn->prepare($sql);
  if (!$stmt) { throw new Exception("DB prepare error: " . $conn->error); }
  if ($types !== '' && !empty($params)) {
    $stmt->bind_param($types, ...$params);
  }
  $stmt->execute();
  $res = $stmt->get_result();
  $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
  $stmt->close();
  return $rows;
}

function fetchOne($conn, $sql, $types = '', $params = []) {
  $all = fetchAll($conn, $sql, $types, $params);
  return $all[0] ?? null;
}

function flash($key, $msg) {
  $_SESSION[$key] = $msg;
}

function read_flash($key) {
  if (isset($_SESSION[$key])) {
    $m = $_SESSION[$key];
    unset($_SESSION[$key]);
    return $m;
  }
  return null;
}

// Para generar nombres de archivos seguros
function slugify($text) {
  $text = preg_replace('~[^\pL\d]+~u', '-', $text);
  $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
  $text = preg_replace('~[^-\w]+~', '', $text);
  $text = trim($text, '-');
  $text = preg_replace('~-+~', '-', $text);
  $text = strtolower($text);
  return $text ?: 'file';
}

// -------------------------------------------------------------
// Contexto de empresa / mentecreativa
// -------------------------------------------------------------
$empresa_id = $_SESSION['empresa_id'] ?? 0;
$empresa = fetchOne($conn, "SELECT nombre FROM empresa WHERE id = ?", "i", [$empresa_id]);
$nombre_empresa = $empresa['nombre'] ?? '';
$es_mentecreativa = (strtolower(trim($nombre_empresa)) === 'mentecreativa');

// -------------------------------------------------------------
// Cargar divisiones disponibles
//   - mentecreativa: todas (con empresa)
//   - normal: solo de su empresa
// -------------------------------------------------------------
if ($es_mentecreativa) {
  $divisiones = fetchAll(
    $conn,
    "SELECT d.id, d.nombre, d.id_empresa, e.nombre AS empresa
       FROM division_empresa d
  LEFT JOIN empresa e ON e.id = d.id_empresa
   ORDER BY e.nombre ASC, d.nombre ASC"
  );
} else {
  $divisiones = fetchAll(
    $conn,
    "SELECT d.id, d.nombre, d.id_empresa, e.nombre AS empresa
       FROM division_empresa d
  LEFT JOIN empresa e ON e.id = d.id_empresa
      WHERE e.id = ?
   ORDER BY d.nombre ASC",
    "i", [$empresa_id]
  );
}

// -------------------------------------------------------------
// Filtros (nombre y división)
// -------------------------------------------------------------
$fil_nombre   = trim($_GET['f_nombre']  ?? '');
$fil_division = intval($_GET['f_division'] ?? 0);

// -------------------------------------------------------------
// Acciones POST: crear material / subir foto ref
// -------------------------------------------------------------
$publicBase = '/visibility2/app/'; // base pública
$relUploadDir = 'uploads/foto_ref_material/'; // ruta relativa que se guarda en DB
$absUploadDir = $_SERVER['DOCUMENT_ROOT'] . $publicBase . $relUploadDir; // dir físico

if (!is_dir($absUploadDir)) {
  @mkdir($absUploadDir, 0755, true);
}

// Crear material
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_material') {
  $nombre      = trim($_POST['nombre'] ?? '');
  $id_division = intval($_POST['id_division'] ?? 0);

  if ($nombre === '' || $id_division < 0) {
    flash('error', 'Completa nombre y división.');
    header("Location: gestionar_materiales.php?f_nombre=" . urlencode($fil_nombre) . "&f_division=" . $fil_division);
    exit();
  }

  // Validación de duplicado por división (case-insensitive, trim)
  $dup = fetchOne(
    $conn,
    "SELECT id FROM material WHERE id_division = ? AND LOWER(TRIM(nombre)) = LOWER(TRIM(?)) LIMIT 1",
    "is", [$id_division, $nombre]
  );
  if ($dup) {
    flash('error', 'Ya existe un material con ese nombre en la división seleccionada.');
    header("Location: gestionar_materiales.php?f_nombre=" . urlencode($fil_nombre) . "&f_division=" . $fil_division);
    exit();
  }

  // Manejo de imagen (opcional)
  $ref_rel = null;
  if (isset($_FILES['ref_image']) && $_FILES['ref_image']['error'] === UPLOAD_ERR_OK) {
    $allowed = [
      'jpg'  => 'image/jpeg',
      'jpeg' => 'image/jpeg',
      'png'  => 'image/png',
      'gif'  => 'image/gif',
      'webp' => 'image/webp'
    ];
    $tmp  = $_FILES['ref_image']['tmp_name'];
    $name = $_FILES['ref_image']['name'];
    $type = $_FILES['ref_image']['type'];
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if (!isset($allowed[$ext]) || (strpos($allowed[$ext], 'image/') !== 0)) {
      flash('error', 'Formato de imagen no permitido.');
      header("Location: gestionar_materiales.php");
      exit();
    }

    $safeBase = slugify(pathinfo($name, PATHINFO_FILENAME));
    $finalName = $safeBase . '-' . uniqid() . '.' . $ext;

    if (!move_uploaded_file($tmp, $absUploadDir . $finalName)) {
      flash('error', 'No se pudo guardar la imagen de referencia.');
      header("Location: gestionar_materiales.php");
      exit();
    }

    $ref_rel = $relUploadDir . $finalName; // ruta relativa que queda en DB
  }

  // Insert
  $stmt = $conn->prepare("INSERT INTO material (nombre, ref_image, id_division) VALUES (?, ?, ?)");
  if (!$stmt) { flash('error', 'Error preparando la inserción.'); header("Location: gestionar_materiales.php"); exit(); }
  $stmt->bind_param("ssi", $nombre, $ref_rel, $id_division);
  if ($stmt->execute()) {
    flash('success', 'Material creado correctamente.');
  } else {
    flash('error', 'Error al crear material: ' . htmlspecialchars($stmt->error));
  }
  $stmt->close();

  header("Location: gestionar_materiales.php");
  exit();
}

// Reemplazar / subir foto de referencia de un material existente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_ref_image') {
  $mat_id = intval($_POST['material_id'] ?? 0);
  if ($mat_id <= 0) {
    flash('error', 'ID de material inválido.');
    header("Location: gestionar_materiales.php");
    exit();
  }

  // Ver material
  $mat = fetchOne($conn, "SELECT id, ref_image FROM material WHERE id = ?", "i", [$mat_id]);
  if (!$mat) {
    flash('error', 'Material no encontrado.');
    header("Location: gestionar_materiales.php");
    exit();
  }

  if (!isset($_FILES['ref_image']) || $_FILES['ref_image']['error'] !== UPLOAD_ERR_OK) {
    flash('error', 'No se recibió imagen válida.');
    header("Location: gestionar_materiales.php");
    exit();
  }

  $allowed = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp'
  ];
  $tmp  = $_FILES['ref_image']['tmp_name'];
  $name = $_FILES['ref_image']['name'];
  $type = $_FILES['ref_image']['type'];
  $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

  if (!isset($allowed[$ext]) || (strpos($allowed[$ext], 'image/') !== 0)) {
    flash('error', 'Formato de imagen no permitido.');
    header("Location: gestionar_materiales.php");
    exit();
  }

  $safeBase = slugify(pathinfo($name, PATHINFO_FILENAME));
  $finalName = $safeBase . '-' . uniqid() . '.' . $ext;

  if (!move_uploaded_file($tmp, $absUploadDir . $finalName)) {
    flash('error', 'No se pudo guardar la imagen de referencia.');
    header("Location: gestionar_materiales.php");
    exit();
  }

  // Borrar imagen anterior si existía
  if (!empty($mat['ref_image'])) {
    $oldAbs = $_SERVER['DOCUMENT_ROOT'] . $publicBase . $mat['ref_image'];
    if (file_exists($oldAbs)) @unlink($oldAbs);
  }

  $ref_rel = $relUploadDir . $finalName;

  $stmt = $conn->prepare("UPDATE material SET ref_image = ? WHERE id = ?");
  if (!$stmt) { flash('error', 'Error preparando actualización.'); header("Location: gestionar_materiales.php"); exit(); }
  $stmt->bind_param("si", $ref_rel, $mat_id);
  if ($stmt->execute()) {
    flash('success', 'Imagen de referencia actualizada.');
  } else {
    flash('error', 'Error al actualizar imagen: ' . htmlspecialchars($stmt->error));
  }
  $stmt->close();

  header("Location: gestionar_materiales.php?f_nombre=" . urlencode($fil_nombre) . "&f_division=" . $fil_division);
  exit();
}

// -------------------------------------------------------------
// Listado de materiales con filtros
// -------------------------------------------------------------
$params = [];
$types  = '';
if ($es_mentecreativa) {
  $sql = "SELECT m.id, m.nombre, m.ref_image, m.id_division,
                 d.nombre AS division, e.nombre AS empresa
            FROM material m
       LEFT JOIN division_empresa d ON d.id = m.id_division
       LEFT JOIN empresa e         ON e.id = d.id_empresa
           WHERE 1";
} else {
  $sql = "SELECT m.id, m.nombre, m.ref_image, m.id_division,
                 d.nombre AS division, e.nombre AS empresa
            FROM material m
       LEFT JOIN division_empresa d ON d.id = m.id_division
       LEFT JOIN empresa e         ON e.id = d.id_empresa
           WHERE e.id = ?";
  $params[] = $empresa_id;
  $types   .= 'i';
}

if ($fil_division > 0) {
  $sql     .= " AND m.id_division = ?";
  $params[] = $fil_division;
  $types   .= 'i';
}
if ($fil_nombre !== '') {
  $sql     .= " AND LOWER(m.nombre) LIKE ?";
  $params[] = '%' . strtolower($fil_nombre) . '%';
  $types   .= 's';
}

$sql .= $es_mentecreativa
  ? " ORDER BY e.nombre ASC, d.nombre ASC, m.nombre ASC"
  : " ORDER BY d.nombre ASC, m.nombre ASC";

$materiales = fetchAll($conn, $sql, $types, $params);

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Gestionar Materiales</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootstrap 4 + FA -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

  <style>
    body { background: #f5f7fb; }
    .page-header {
      display:flex; align-items:center; justify-content:space-between;
      margin-bottom: 1rem;
    }
    .card { border: 0; box-shadow: 0 10px 20px rgba(0,0,0,.04); }
    .thumb {
      width:64px; height:64px; object-fit:cover; border-radius:8px; background:#efefef;
    }
    .table thead th { background:#0d6efd; color:#fff; border:0; }
    .badge-muted { background:#e9ecef; color:#6c757d; }
    .filter-panel .form-group { margin-bottom: .5rem; }
    .empty {
      padding: 2rem; text-align:center; color:#6c757d;
    }
    .modal .custom-file-label::after { content: "Buscar"; }
  </style>
</head>
<body>
<div class="container mt-4 mb-5">

  <div class="page-header">
    <h1 class="h3 mb-0">
      <i class="fa-solid fa-layer-group mr-2"></i>Gestionar Materiales
    </h1>
    <div>
      <a href="../mod_formulario.php" class="btn btn-light">
        <i class="fa-solid fa-arrow-left"></i> Volver
      </a>
      <button class="btn btn-primary" data-toggle="modal" data-target="#modalCrearMaterial">
        <i class="fa-solid fa-plus"></i> Crear material
      </button>
    </div>
  </div>

  <!-- Flash messages -->
  <?php if ($m = read_flash('success')): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      <i class="fa-solid fa-circle-check mr-1"></i> <?php echo $m; ?>
      <button type="button" class="close" data-dismiss="alert" aria-label="Cerrar">
        <span aria-hidden="true">&times;</span>
      </button>
    </div>
  <?php endif; ?>
  <?php if ($m = read_flash('error')): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
      <i class="fa-solid fa-triangle-exclamation mr-1"></i> <?php echo $m; ?>
      <button type="button" class="close" data-dismiss="alert" aria-label="Cerrar">
        <span aria-hidden="true">&times;</span>
      </button>
    </div>
  <?php endif; ?>

  <!-- Filtros -->
  <div class="card mb-3 filter-panel">
    <div class="card-body">
      <form id="filterForm" class="form-row" method="get" action="gestionar_materiales.php">
        <div class="form-group col-md-5">
          <label class="mb-1">Nombre</label>
          <input type="text"
                 name="f_nombre"
                 class="form-control"
                 placeholder="Buscar por nombre…"
                 value="<?php echo htmlspecialchars($fil_nombre, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div class="form-group col-md-4">
          <label class="mb-1">División</label>
        <select name="f_division" class="form-control">

            <option value="0">-- Todas --</option>
            <?php foreach ($divisiones as $d): ?>
              <option value="<?php echo intval($d['id']); ?>"
                <?php echo ($fil_division === intval($d['id'])) ? 'selected' : ''; ?>>
                <?php
                  $label = ($es_mentecreativa ? ($d['empresa'].' - ') : '') . $d['nombre'];
                  echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
                ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group col-md-3 d-flex align-items-end">
          <button class="btn btn-primary mr-2">
            <i class="fas fa-filter"></i> Filtrar
          </button>
          <a href="gestionar_materiales.php" class="btn btn-light">Limpiar</a>
        </div>
      </form>
    </div>
  </div>

  <!-- Tabla -->
  <div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
      <div>
        <strong>Materiales existentes</strong>
        <span class="badge badge-pill badge-muted ml-2" id="materialsCountBadge"><?php echo count($materiales); ?></span>
      </div>
      <small class="text-muted">Click en “Subir / Reemplazar foto” para administrar la imagen de referencia.</small>
    </div>
    <div class="card-body p-0" id="materialsTableWrap">
      <?php if (empty($materiales)): ?>
        <div class="empty">
          <i class="fa-regular fa-image fa-2x mb-2"></i>
          <div>No hay materiales que coincidan con los filtros.</div>
        </div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead>
              <tr>
                <th style="width:80px;">Foto</th>
                <th>Nombre</th>
                <th><?php echo $es_mentecreativa ? 'Empresa' : '—'; ?></th>
                <th>División</th>
                <th style="width:180px;" class="text-center">Acciones</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($materiales as $m): ?>
              <?php
                $imgRel = $m['ref_image'] ?? '';
                $imgUrl = $imgRel ? ($publicBase . $imgRel) : '';
              ?>
              <tr>
                <td>
                  <?php if ($imgUrl): ?>
                    <img src="<?php echo htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8'); ?>"
                         class="thumb"
                         alt="ref">
                  <?php else: ?>
                    <div class="thumb d-flex align-items-center justify-content-center text-muted">
                      <i class="fa-regular fa-image"></i>
                    </div>
                  <?php endif; ?>
                </td>
                <td class="align-middle">
                  <strong><?php echo htmlspecialchars($m['nombre'], ENT_QUOTES, 'UTF-8'); ?></strong>
                </td>
                <td class="align-middle">
                  <?php if ($es_mentecreativa): ?>
                    <span class="text-muted"><?php echo htmlspecialchars($m['empresa'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></span>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="align-middle">
                  <?php echo htmlspecialchars($m['division'] ?? 'Sin División', ENT_QUOTES, 'UTF-8'); ?>
                </td>
                <td class="align-middle text-center">
                <button
                  type="button"
                  class="btn btn-sm btn-outline-primary btn-upload-ref"
                  data-material-id="<?php echo intval($m['id']); ?>"
                  data-material-name="<?php echo htmlspecialchars($m['nombre'], ENT_QUOTES, 'UTF-8'); ?>"
                >
                  <i class="fa-solid fa-upload"></i> Subir / Reemplazar foto
                </button>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Modal: Crear Material -->
<div class="modal fade" id="modalCrearMaterial" tabindex="-1" role="dialog" aria-labelledby="modalCrearMaterialLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <form class="modal-content" method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="create_material">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" id="modalCrearMaterialLabel"><i class="fa-solid fa-plus mr-2"></i>Crear material</h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <div class="form-group">
          <label>Nombre del material</label>
          <input type="text" name="nombre" class="form-control" required placeholder="Ej: AFICHE ED+SF">
          <small class="form-text text-muted">No se permiten duplicados por división.</small>
        </div>
        <div class="form-group">
          <label>División</label>
          <select name="id_division" class="form-control" required>
            <option value="">-- Seleccione una división --</option>
            <?php foreach ($divisiones as $d): ?>
              <option value="<?php echo intval($d['id']); ?>">
                <?php
                  $label = ($es_mentecreativa ? ($d['empresa'].' - ') : '') . $d['nombre'];
                  echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
                ?>
              </option>
            <?php endforeach; ?>
            <option value="0">-- Sin división --</option>
          </select>
        </div>
            <div class="form-group">
              <label for="ref_image">Foto de referencia (opcional)</label>
              <div class="custom-file">
                <input type="file"
                       class="custom-file-input"
                       id="ref_image"
                       name="ref_image"
                       accept="image/*">
                <label class="custom-file-label" for="ref_image">Elegir imagen…</label>
              </div>
              <div id="refPreviewWrapper" class="mt-3 d-none">
                <div class="border rounded p-2 text-center">
                  <img id="refPreviewImg" src="" alt="Previsualización" class="img-fluid" style="max-height: 220px;">
                </div>
                <div class="d-flex align-items-center justify-content-between mt-2">
                  <small id="refMeta" class="text-muted"></small>
                  <button type="button" id="refClearBtn" class="btn btn-sm btn-outline-secondary">
                    Quitar imagen
                  </button>
                </div>
              </div>
  <small class="form-text text-muted">
    Formatos: JPG/PNG/GIF/WEBP. Tamaño máx. 5MB.
  </small>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" type="submit">Crear</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Subir/Reemplazar Foto -->
<div class="modal fade" id="modalUploadRef" tabindex="-1" role="dialog" aria-labelledby="modalUploadRefLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <form class="modal-content" method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="upload_ref_image">
      <input type="hidden" name="material_id" id="upload_material_id" value="">
      <div class="modal-header bg-info text-white">
        <h5 class="modal-title" id="modalUploadRefLabel"><i class="fa-solid fa-image mr-2"></i>Subir / Reemplazar foto</h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <p class="mb-2 text-muted" id="upload_material_name"></p>
        <div class="custom-file">
          <input type="file" class="custom-file-input" id="upload_ref_image" name="ref_image" accept="image/*" required>
          <label class="custom-file-label" for="upload_ref_image">Elegir archivo…</label>
        </div>
    <div id="uploadPreviewWrapper" class="mt-3 d-none">
        <div class="border rounded p-2 text-center">
          <img id="uploadPreviewImg" src="" alt="Previsualización" class="img-fluid" style="max-height: 220px;">
        </div>
        <div class="d-flex align-items-center justify-content-between mt-2">
          <small id="uploadMeta" class="text-muted"></small>
          <button type="button" id="uploadClearBtn" class="btn btn-sm btn-outline-secondary">Quitar imagen</button>
        </div>
      </div>
        <small class="form-text text-muted mt-2">
          La imagen se almacenará en <code>/visibility2/app/uploads/foto_ref_material/</code> y en BD quedará
          <code>uploads/foto_ref_material/archivo.ext</code>.
        </small>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
        <button class="btn btn-info" type="submit">Guardar</button>
      </div>
    </form>
  </div>
</div>

<!-- JS -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script>

  // Mostrar nombre de archivo en los inputs custom-file
  $(document).on('change', '.custom-file-input', function (e) {
    var fileName = e.target.files[0] ? e.target.files[0].name : 'Elegir archivo…';
    $(this).next('.custom-file-label').text(fileName);
  });
  
  
$(function () {
  // Asegurar que el modal está bajo <body> (evita problemas de stacking/z-index si el contenedor tiene transform/overflow)
  $('#modalUploadRef').appendTo('body');

  // Abrimos el modal por JS DELEGADO (soporta filas insertadas por AJAX)
  $(document).on('click', '.btn-upload-ref', function () {
    var $btn = $(this);
    var id   = $btn.data('material-id');
    var name = $btn.data('material-name') || '';

    // Si el modal de crear está abierto, ciérralo antes (por si acaso)
    $('#modalCrearMaterial').modal('hide');

    // Rellenamos campos
    $('#upload_material_id').val(id);
    $('#upload_material_name').text('Material: ' + name);

    // Reset input file y etiqueta
    var $file = $('#upload_ref_image');
    $file.val('');
    $file.next('.custom-file-label').text('Elegir archivo…');

    // Mostrar modal
    $('#modalUploadRef').modal('show');
  });

  // Seguridad extra: limpiar backdrop si el modal llega a cerrarse
  $('#modalUploadRef').on('hidden.bs.modal', function () {
    $('.modal-backdrop').remove();
  });
});
  
(function(){
  // Soporte de etiqueta de archivo de Bootstrap 4
  $(document).on('change', '#ref_image', function(){
    var file = this.files && this.files[0];
    var $label = $(this).next('.custom-file-label');
    $label.text(file ? file.name : 'Elegir imagen…');

    if (!file) {
      hidePreview();
      return;
    }

    // Validación mínima
    var okType = /^image\/(png|jpe?g|gif|webp)$/i.test(file.type || '');
    if (!okType) {
      alert('El archivo debe ser una imagen (JPG, PNG, GIF o WEBP).');
      this.value = '';
      $label.text('Elegir imagen…');
      hidePreview();
      return;
    }
    if (file.size > 5 * 1024 * 1024) {
      alert('La imagen supera los 5MB.');
      this.value = '';
      $label.text('Elegir imagen…');
      hidePreview();
      return;
    }

    // Previsualización
    var reader = new FileReader();
    reader.onload = function(e){
      $('#refPreviewImg').attr('src', e.target.result);
      $('#refMeta').text(formateaMeta(file));
      $('#refPreviewWrapper').removeClass('d-none');
    };
    reader.readAsDataURL(file);
  });

  // Botón "Quitar imagen"
  $(document).on('click', '#refClearBtn', function(){
    var $input = $('#ref_image');
    $input.val('');
    $input.next('.custom-file-label').text('Elegir imagen…');
    hidePreview();
  });

  function hidePreview(){
    $('#refPreviewImg').attr('src','');
    $('#refMeta').text('');
    $('#refPreviewWrapper').addClass('d-none');
  }

  function formateaMeta(file){
    var mb = (file.size/1024/1024).toFixed(2) + ' MB';
    var type = file.type || 'image/*';
    return file.name + ' • ' + mb + ' • ' + type;
  }
})();

$('#modalCrearMaterial').on('hidden.bs.modal', function(){
  $('#ref_image').val('').next('.custom-file-label').text('Elegir imagen…');
  $('#refPreviewImg').attr('src','');
  $('#refMeta').text('');
  $('#refPreviewWrapper').addClass('d-none');
});
  
  
 (function(){
  var $form        = $('#filterForm');
  var $nameInput   = $form.find('input[name="f_nombre"]');
  var $divisionSel = $form.find('select[name="f_division"]');
  var $wrap        = $('#materialsTableWrap');
  var $badge       = $('#materialsCountBadge');
  var debounceTimer = null;

  // Evitar submits "full page"
  $form.on('submit', function(e){
    e.preventDefault();
    applyFilters();
  });

  // Filtrado en vivo al escribir (con debounce)
  $nameInput.on('input', function(){
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(applyFilters, 250);
  });

  // Filtrado inmediato al cambiar división
  $divisionSel.on('change', function(){
    applyFilters();
  });

  function applyFilters(){
    var params = {
      f_nombre:   $nameInput.val(),
      f_division: $divisionSel.val()
    };

    // Spinner ligero
    var loading = $('<div class="p-4 text-center text-muted" id="materialsLoading">' +
                      '<div class="spinner-border spinner-border-sm mr-2" role="status"></div>' +
                      'Cargando…' +
                    '</div>');
    $wrap.css('min-height','120px').html(loading);

    // Actualiza la URL (sin agregar al historial)
    var url = new URL(window.location.href);
    url.searchParams.set('f_nombre', params.f_nombre || '');
    url.searchParams.set('f_division', params.f_division || 0);
    window.history.replaceState({}, '', url);

    // Pedimos la misma página y extraemos solo las partes a reemplazar
    $.get(window.location.pathname, params)
      .done(function(html){
        var $dom   = $(html);
        var $newWrap  = $dom.find('#materialsTableWrap');
        var $newBadge = $dom.find('#materialsCountBadge');

        if ($newWrap.length)  $wrap.replaceWith($newWrap);
        if ($newBadge.length) $badge.text($newBadge.text());

        // Reasignamos referencias porque reemplazamos el wrapper
        $wrap = $('#materialsTableWrap');
      })
      .fail(function(){
        $wrap.html('<div class="p-4 text-center text-danger">Error al cargar resultados.</div>');
      });
  }
})();

window.formateaMeta = window.formateaMeta || function(file){
  var mb = (file.size/1024/1024).toFixed(2) + ' MB';
  var type = file.type || 'image/*';
  return file.name + ' • ' + mb + ' • ' + type;
};

$(function(){
  // Helpers específicos del modal de Upload
  function hideUploadPreview(){
    $('#uploadPreviewImg').attr('src','');
    $('#uploadMeta').text('');
    $('#uploadPreviewWrapper').addClass('d-none');
  }

  // Cuando abrimos el modal por JS (tu handler delegado ya lo hace),
  // reseteamos también la previsualización
  $(document).on('click', '.btn-upload-ref', function(){
    hideUploadPreview();
  });

  // Cambio de archivo => validación + preview
  $(document).on('change', '#upload_ref_image', function(){
    var file   = this.files && this.files[0];
    var $label = $(this).next('.custom-file-label');
    $label.text(file ? file.name : 'Elegir archivo…');

    if (!file) { hideUploadPreview(); return; }

    var okType = /^image\/(png|jpe?g|gif|webp)$/i.test(file.type || '');
    if (!okType) {
      alert('El archivo debe ser una imagen (JPG, PNG, GIF o WEBP).');
      this.value = '';
      $label.text('Elegir archivo…');
      hideUploadPreview();
      return;
    }
    if (file.size > 5 * 1024 * 1024) {
      alert('La imagen supera los 5MB.');
      this.value = '';
      $label.text('Elegir archivo…');
      hideUploadPreview();
      return;
    }

    var reader = new FileReader();
    reader.onload = function(e){
      $('#uploadPreviewImg').attr('src', e.target.result);
      $('#uploadMeta').text(window.formateaMeta(file));
      $('#uploadPreviewWrapper').removeClass('d-none');
    };
    reader.readAsDataURL(file);
  });

  // Botón "Quitar imagen" en el modal de Upload
  $(document).on('click', '#uploadClearBtn', function(){
    var $input = $('#upload_ref_image');
    $input.val('');
    $input.next('.custom-file-label').text('Elegir archivo…');
    hideUploadPreview();
  });

  // Al cerrar el modal, limpia todo por si quedó algo
  $('#modalUploadRef').on('hidden.bs.modal', function () {
    hideUploadPreview();
    $('#upload_ref_image').val('').next('.custom-file-label').text('Elegir archivo…');
  });
});
  
</script>
</body>
</html>
<?php
$conn->close();
