<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

$mensaje = "";
$tipo_mensaje = "info";

$default_empresa = $_SESSION['empresa_id'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_division'])) {
    $nombre     = trim($_POST['nombre'] ?? '');
    $id_empresa = intval($_POST['id_empresa'] ?? 0);
    $estado     = isset($_POST['estado']) ? 1 : 0;
    $image_url  = '';

    $nombreEsc = $conn->real_escape_string($nombre);

    if ($nombre === '') {
        $mensaje = "Debes ingresar el nombre de la división.";
        $tipo_mensaje = "danger";
    }

    // Subida de imagen opcional
    if ($mensaje === "" && isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
        $allowed = [
            "jpg"  => "image/jpeg",
            "jpeg" => "image/jpeg",
            "png"  => "image/png",
            "gif"  => "image/gif",
            "webp" => "image/webp"
        ];

        $filename = $_FILES['image']['name'];
        $filetype = $_FILES['image']['type'];
        $filesize = $_FILES['image']['size'];
        $ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (!array_key_exists($ext, $allowed)) {
            $mensaje = "Formato de imagen no válido. Usa JPG, JPEG, PNG, GIF o WEBP.";
            $tipo_mensaje = "danger";
        } elseif ($filesize > 5 * 1024 * 1024) {
            $mensaje = "La imagen no puede superar los 5 MB.";
            $tipo_mensaje = "danger";
        } elseif (!in_array($filetype, $allowed)) {
            $mensaje = "El tipo MIME del archivo no es válido.";
            $tipo_mensaje = "danger";
        } else {
            $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/uploads/divisiones/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0775, true);
            }

            $newFilename = uniqid('division_', true) . '.' . $ext;
            $destination = $uploadDir . $newFilename;

            if (move_uploaded_file($_FILES['image']['tmp_name'], $destination)) {
                $image_url = '/visibility2/portal/uploads/divisiones/' . $newFilename;
            } else {
                $mensaje = "No fue posible subir la imagen.";
                $tipo_mensaje = "danger";
            }
        }
    }

    if ($mensaje === "") {
        $imageEsc = $conn->real_escape_string($image_url);

        $sql = "
            INSERT INTO division_empresa (nombre, id_empresa, image_url, estado)
            VALUES ('$nombreEsc', $id_empresa, '$imageEsc', $estado)
        ";

        if ($conn->query($sql)) {
            $mensaje = "División creada exitosamente.";
            $tipo_mensaje = "success";
        } else {
            $mensaje = "Error al guardar la división: " . $conn->error;
            $tipo_mensaje = "danger";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Divisiones</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/8.0.0/normalize.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:400,500,700">
    <link rel="stylesheet" href="../dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>

    <style>
        body {
            background: #f4f6f9;
            font-family: 'Roboto', sans-serif;
        }

        .module-header {
            background: linear-gradient(135deg, #0f172a, #1e293b);
            color: #fff;
            border-radius: 18px;
            padding: 24px 28px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.18);
            margin-bottom: 20px;
        }

        .module-header h1 {
            font-size: 1.8rem;
            margin: 0 0 6px 0;
            font-weight: 700;
        }

        .module-header p {
            margin: 0;
            color: rgba(255,255,255,.82);
            font-size: .96rem;
        }

        .module-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.12);
            padding: 10px 14px;
            border-radius: 999px;
            font-size: .9rem;
            font-weight: 500;
        }

        .modern-card {
            background: #fff;
            border: 0;
            border-radius: 18px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
            overflow: hidden;
            margin-bottom: 20px;
        }

        .modern-card .card-header-custom {
            padding: 18px 22px;
            border-bottom: 1px solid #eef2f7;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .modern-card .card-header-custom h3 {
            margin: 0;
            font-size: 1.05rem;
            font-weight: 700;
            color: #1f2937;
        }

        .modern-card .card-header-custom p {
            margin: 3px 0 0 0;
            color: #6b7280;
            font-size: .88rem;
        }

        .modern-card .card-body {
            padding: 22px;
        }

        .soft-chip {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: #eef6ff;
            color: #0f5db8;
            border-radius: 999px;
            padding: 7px 12px;
            font-size: .83rem;
            font-weight: 600;
        }

        .form-label-modern {
            font-size: .88rem;
            font-weight: 700;
            color: #344054;
            margin-bottom: 8px;
        }

        .form-control {
            height: 46px;
            border-radius: 12px !important;
            border: 1px solid #dbe2ea;
            box-shadow: none !important;
        }

        .form-control:focus {
            border-color: #60a5fa;
            box-shadow: 0 0 0 0.2rem rgba(96,165,250,.15) !important;
        }

        .upload-box {
            border: 2px dashed #cfd8e3;
            border-radius: 18px;
            padding: 26px 18px;
            text-align: center;
            background: linear-gradient(180deg, #fafcff, #f8fbff);
            transition: .25s ease;
            cursor: pointer;
            position: relative;
        }

        .upload-box:hover {
            border-color: #60a5fa;
            background: #f4f9ff;
        }

        .upload-box i {
            font-size: 2rem;
            color: #3b82f6;
            margin-bottom: 12px;
        }

        .upload-box h5 {
            font-size: 1rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 6px;
        }

        .upload-box p {
            margin: 0;
            color: #6b7280;
            font-size: .88rem;
        }

        .upload-box input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
        }

        .image-preview-wrapper {
            margin-top: 16px;
            display: none;
        }

        .image-preview-wrapper img {
            width: 100%;
            max-height: 220px;
            object-fit: contain;
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 8px 20px rgba(15,23,42,.08);
            padding: 20px;
        }

        .preview-card {
            background: linear-gradient(180deg, #ffffff, #f8fafc);
            border: 1px solid #e8edf3;
            border-radius: 22px;
            overflow: hidden;
            box-shadow: 0 10px 28px rgba(15,23,42,.08);
        }

        .preview-image {
            height: 240px;
            background: #eef2f7 center center / contain no-repeat;
            position: relative;
            border-bottom: 1px solid #eef2f7;
        }

        .preview-content {
            padding: 18px;
        }

        .preview-main {
            font-size: 1.15rem;
            font-weight: 700;
            color: #111827;
            margin-bottom: 8px;
        }

        .preview-sub {
            font-size: .92rem;
            color: #6b7280;
            margin-bottom: 14px;
        }

        .preview-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .preview-pill {
            font-size: .78rem;
            font-weight: 600;
            background: #eef2ff;
            color: #4338ca;
            border-radius: 999px;
            padding: 7px 10px;
        }

        .status-switch {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 12px 14px;
        }

        .btn-modern-primary {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            border: 0;
            color: #fff;
            border-radius: 12px;
            padding: 12px 18px;
            font-weight: 700;
            box-shadow: 0 10px 20px rgba(37,99,235,.18);
        }

        .btn-modern-primary:hover {
            color: #fff;
            opacity: .95;
        }

        .btn-modern-light {
            background: #f8fafc;
            border: 1px solid #dbe2ea;
            color: #334155;
            border-radius: 12px;
            padding: 12px 18px;
            font-weight: 600;
        }

        .table-zone {
            min-height: 180px;
            border: 1px dashed #d9e2ec;
            border-radius: 16px;
            background: #fcfdff;
            padding: 14px;
        }

        .alert-modern {
            border: 0;
            border-radius: 14px;
            padding: 14px 16px;
            font-weight: 500;
        }

        .small-help {
            display: block;
            margin-top: 6px;
            color: #6b7280;
            font-size: .8rem;
        }

        .stats-box {
            display: flex;
            align-items: center;
            gap: 12px;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 12px 14px;
            height: 100%;
        }

        .stats-box i {
            width: 42px;
            height: 42px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            background: #e0ecff;
            color: #2563eb;
        }

        .stats-box strong {
            display: block;
            color: #111827;
            font-size: .95rem;
        }

        .stats-box span {
            color: #6b7280;
            font-size: .82rem;
        }

        @media (max-width: 991px) {
            .module-header {
                padding: 20px;
            }

            .preview-image {
                height: 210px;
            }
        }
    </style>
</head>
<body>

<div class="container-fluid mt-4 mb-4">

    <div class="module-header">
        <div class="row align-items-center">
            <div class="col-lg-8">
                <h1><i class="fas fa-sitemap mr-2"></i>Gestión de Divisiones</h1>
                <p>Administra divisiones por empresa, su estado e imagen corporativa asociada.</p>
            </div>
            <div class="col-lg-4 text-lg-right mt-3 mt-lg-0">
                <span class="module-badge">
                    <i class="fas fa-image"></i>
                    Logos por división
                </span>
            </div>
        </div>
    </div>

    <div class="modern-card">
        <div class="card-header-custom">
            <div>
                <h3><i class="fas fa-plus-circle mr-2 text-success"></i>Crear nueva división</h3>
                <p>Registra una división y asígnale una imagen o logotipo representativo.</p>
            </div>
            <div class="soft-chip" style="background:#ecfdf3;color:#027a48;">
                <i class="fas fa-eye"></i>
                Preview en vivo
            </div>
        </div>

        <div class="card-body">
            <?php if ($mensaje != ""): ?>
                <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-modern mb-4">
                    <?php echo htmlspecialchars($mensaje); ?>
                </div>
            <?php endif; ?>

            <form action="" method="post" enctype="multipart/form-data" id="divisionForm">
                <input type="hidden" name="crear_division" value="1">

                <div class="row">
                    <div class="col-lg-7">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="id_empresa" class="form-label-modern">Empresa</label>
                                <select class="form-control" id="id_empresa" name="id_empresa" required>
                                    <option value="">Selecciona una empresa</option>
                                    <?php
                                    $qEmp = $conn->query("SELECT id, nombre FROM empresa WHERE activo = 1 ORDER BY nombre ASC");
                                    while ($emp = $qEmp->fetch_assoc()):
                                        $selected = ((int)$emp['id'] === (int)$default_empresa) ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo (int)$emp['id']; ?>" <?php echo $selected; ?>>
                                            <?php echo htmlspecialchars($emp['nombre']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="nombre" class="form-label-modern">Nombre de la división</label>
                                <input type="text" id="nombre" name="nombre" class="form-control" required placeholder="Ej: Nestlé, Hasbro, Clorox">
                                <small class="small-help">Ingresa el nombre visible con el que se identificará la división.</small>
                            </div>

                            <div class="col-md-12 mb-4">
                                <label class="form-label-modern">Logo o imagen</label>
                                <div class="upload-box">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                    <h5>Haz clic para subir el logotipo</h5>
                                    <p>Formatos permitidos: JPG, JPEG, PNG, GIF, WEBP · máximo 5 MB</p>
                                    <input type="file" id="image" name="image" accept=".jpg,.jpeg,.png,.gif,.webp">
                                </div>
                                <div class="image-preview-wrapper" id="imagePreviewWrapper">
                                    <img id="imagePreview" src="" alt="Vista previa del logo">
                                </div>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label-modern">Estado</label>
                                <div class="status-switch">
                                    <input type="checkbox" class="form-check-input mt-0" id="estado" name="estado" checked style="position:relative; margin-left:0;">
                                    <label class="form-check-label mb-0" for="estado">Activo</label>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap gap-2 mt-2">
                            <button type="submit" class="btn btn-modern-primary mr-2">
                                <i class="fas fa-save mr-2"></i>Guardar División
                            </button>

                            <button type="reset" class="btn btn-modern-light" id="btnResetPreview">
                                <i class="fas fa-undo mr-2"></i>Limpiar formulario
                            </button>
                        </div>
                    </div>

                    <div class="col-lg-5 mt-4 mt-lg-0">
                        <label class="form-label-modern">Vista previa</label>
                        <div class="preview-card">
                            <div class="preview-image" id="previewImage" style="background-image:url('https://via.placeholder.com/800x450/f3f4f6/9ca3af?text=Logo+Divisi%C3%B3n');"></div>

                            <div class="preview-content">
                                <div class="preview-main" id="previewMain">Nombre de la división</div>
                                <div class="preview-sub" id="previewSub">Empresa asociada</div>

                                <div class="preview-meta">
                                    <span class="preview-pill" id="previewStatus">Activo</span>
                                    <span class="preview-pill">División</span>
                                    <span class="preview-pill">Identidad visual</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="modern-card">
        <div class="card-header-custom">
            <div>
                <h3><i class="fas fa-table mr-2 text-warning"></i>Divisiones registradas</h3>
                <p>Listado de divisiones con empresa asociada, logo y estado.</p>
            </div>
            <div class="soft-chip" style="background:#fff7ed;color:#c2410c;">
                <i class="fas fa-layer-group"></i>
                Gestión centralizada
            </div>
        </div>
        <div class="card-body">
            <div class="table-zone" id="division_table_container">
                Cargando divisiones...
            </div>
        </div>
    </div>

</div>

<div class="modal fade" id="modalEditarDivision" tabindex="-1" role="dialog" aria-labelledby="modalEditarDivisionLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
    <div class="modal-content" style="border-radius:18px; overflow:hidden; border:0;">
      
      <div class="modal-header" style="background:linear-gradient(135deg,#0f172a,#1e293b); color:#fff; border-bottom:0;">
        <h5 class="modal-title" id="modalEditarDivisionLabel">
          <i class="fas fa-edit mr-2"></i>Editar división
        </h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Cerrar" style="opacity:1;">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <form id="formEditarDivision" enctype="multipart/form-data">
        <div class="modal-body p-4">
          <input type="hidden" name="id" id="edit_id">

          <div class="row">
            <div class="col-lg-7">
              <div class="form-group">
                <label class="form-label-modern">Empresa</label>
                <select class="form-control" name="id_empresa" id="edit_id_empresa" required>
                  <option value="">Selecciona una empresa</option>
                  <?php
                  $qEmp2 = $conn->query("SELECT id, nombre FROM empresa WHERE activo = 1 ORDER BY nombre ASC");
                  while ($emp2 = $qEmp2->fetch_assoc()):
                  ?>
                    <option value="<?php echo (int)$emp2['id']; ?>">
                      <?php echo htmlspecialchars($emp2['nombre']); ?>
                    </option>
                  <?php endwhile; ?>
                </select>
              </div>

              <div class="form-group">
                <label class="form-label-modern">Nombre de la división</label>
                <input type="text" class="form-control" name="nombre" id="edit_nombre" required>
              </div>

              <div class="form-group">
                <label class="form-label-modern">Nueva imagen</label>
                <div class="upload-box" style="padding:20px 16px;">
                  <i class="fas fa-image"></i>
                  <h5>Haz clic para reemplazar el logotipo</h5>
                  <p>Si no subes una nueva imagen, se mantendrá la actual.</p>
                  <input type="file" id="edit_image" name="image" accept=".jpg,.jpeg,.png,.gif,.webp">
                </div>
              </div>

              <div class="form-group">
                <label class="form-label-modern">Estado</label>
                <div class="status-switch">
                  <input type="checkbox" class="form-check-input mt-0" id="edit_estado" name="estado" style="position:relative; margin-left:0;">
                  <label class="form-check-label mb-0" for="edit_estado">Activo</label>
                </div>
              </div>
            </div>

            <div class="col-lg-5">
              <label class="form-label-modern">Vista previa</label>
              <div class="preview-card">
                <div class="preview-image" id="edit_previewImage" style="background-image:url('https://via.placeholder.com/800x450/f3f4f6/9ca3af?text=Logo+Divisi%C3%B3n');"></div>

                <div class="preview-content">
                  <div class="preview-main" id="edit_previewMain">Nombre de la división</div>
                  <div class="preview-sub" id="edit_previewSub">Empresa asociada</div>

                  <div class="preview-meta">
                    <span class="preview-pill" id="edit_previewStatus">Activo</span>
                    <span class="preview-pill">División</span>
                    <span class="preview-pill">Logo</span>
                  </div>
                </div>
              </div>

              <div class="image-preview-wrapper mt-3" id="editImagePreviewWrapper" style="display:none;">
                <img id="editImagePreview" src="" alt="Vista previa">
              </div>
            </div>
          </div>

          <div id="editDivisionMsg" class="mt-3"></div>
        </div>

        <div class="modal-footer bg-light" style="border-top:1px solid #eef2f7;">
          <button type="button" class="btn btn-modern-light" data-dismiss="modal">
            Cancelar
          </button>
          <button type="submit" class="btn btn-modern-primary">
            <i class="fas fa-save mr-2"></i>Guardar cambios
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/4.6.2/js/bootstrap.bundle.min.js"></script>

<script>
    function updatePreview() {
        const nombre = $("#nombre").val().trim() || "Nombre de la división";
        const empresa = $("#id_empresa option:selected").text() || "Empresa asociada";
        const activa = $("#estado").is(":checked");

        $("#previewMain").text(nombre);
        $("#previewSub").text(empresa);
        $("#previewStatus").text(activa ? "Activo" : "Inactivo");
    }

    function previewImage(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();

            reader.onload = function(e) {
                $("#imagePreview").attr("src", e.target.result);
                $("#imagePreviewWrapper").fadeIn(150);
                $("#previewImage").css("background-image", "url('" + e.target.result + "')");
            };

            reader.readAsDataURL(input.files[0]);
        }
    }

    function loadDivisiones() {
        $.ajax({
            url: 'obtener_divisiones_admin.php',
            type: 'GET',
            success: function(data) {
                $("#division_table_container").html(data);
            },
            error: function() {
                $("#division_table_container").html('<div class="alert alert-danger mb-0">No fue posible cargar las divisiones.</div>');
            }
        });
    }

    $(document).ready(function() {
        updatePreview();
        loadDivisiones();

        $("#nombre").on("input", updatePreview);
        $("#id_empresa").on("change", updatePreview);
        $("#estado").on("change", updatePreview);

        $("#image").on("change", function() {
            previewImage(this);
        });

        $("#btnResetPreview").on("click", function() {
            setTimeout(function() {
                $("#imagePreviewWrapper").hide();
                $("#imagePreview").attr("src", "");
                $("#previewImage").css("background-image", "url('https://via.placeholder.com/800x450/f3f4f6/9ca3af?text=Logo+Divisi%C3%B3n')");
                updatePreview();
            }, 50);
        });
    });
</script>

<script>
function updateEditPreview() {
    const nombre = $("#edit_nombre").val().trim() || "Nombre de la división";
    const empresa = $("#edit_id_empresa option:selected").text() || "Empresa asociada";
    const activa = $("#edit_estado").is(":checked");

    $("#edit_previewMain").text(nombre);
    $("#edit_previewSub").text(empresa);
    $("#edit_previewStatus").text(activa ? "Activo" : "Inactivo");
}

function previewEditImage(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();

        reader.onload = function(e) {
            $("#editImagePreview").attr("src", e.target.result);
            $("#editImagePreviewWrapper").show();
            $("#edit_previewImage").css("background-image", "url('" + e.target.result + "')");
        };

        reader.readAsDataURL(input.files[0]);
    }
}

$(document).on("click", ".btn-editar-division", function() {
    const id = $(this).data("id");
    const nombre = $(this).data("nombre");
    const idEmpresa = $(this).data("id_empresa");
    const imageUrl = $(this).data("image_url");
    const estado = parseInt($(this).data("estado"), 10);

    $("#edit_id").val(id);
    $("#edit_nombre").val(nombre);
    $("#edit_id_empresa").val(idEmpresa);
    $("#edit_estado").prop("checked", estado === 1);
    $("#edit_image").val("");
    $("#editDivisionMsg").html("");
    $("#editImagePreviewWrapper").hide();
    $("#editImagePreview").attr("src", "");

    if (imageUrl) {
        $("#edit_previewImage").css("background-image", "url('" + imageUrl + "')");
    } else {
        $("#edit_previewImage").css("background-image", "url('https://via.placeholder.com/800x450/f3f4f6/9ca3af?text=Logo+Divisi%C3%B3n')");
    }

    updateEditPreview();
    $("#modalEditarDivision").modal("show");
});

$("#edit_nombre, #edit_id_empresa").on("input change", function() {
    updateEditPreview();
});

$("#edit_estado").on("change", function() {
    updateEditPreview();
});

$("#edit_image").on("change", function() {
    previewEditImage(this);
});

$("#formEditarDivision").on("submit", function(e) {
    e.preventDefault();

    const formData = new FormData(this);

    $.ajax({
        url: 'actualizar_division_admin.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(resp) {
            if (resp.success) {
                $("#editDivisionMsg").html(
                    '<div class="alert alert-success alert-modern mb-0">División actualizada correctamente.</div>'
                );

                loadDivisiones();

                setTimeout(function() {
                    $("#modalEditarDivision").modal("hide");
                }, 900);
            } else {
                $("#editDivisionMsg").html(
                    '<div class="alert alert-danger alert-modern mb-0">' + resp.error + '</div>'
                );
            }
        },
        error: function() {
            $("#editDivisionMsg").html(
                '<div class="alert alert-danger alert-modern mb-0">Error de red o servidor al actualizar.</div>'
            );
        }
    });
});
</script>


</body>
</html>