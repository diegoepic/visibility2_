<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

$mensaje = "";
$tipo_mensaje = "info";

$default_empresa  = $_SESSION['empresa_id'] ?? 0;
$default_division = $_SESSION['division_id'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_carousel_item'])) {
    $id_empresa  = intval($_POST['empresa'] ?? 0);
    $id_division = intval($_POST['division'] ?? 0);
    $titulo      = trim($_POST['titulo'] ?? '');
    $subtitulo   = trim($_POST['subtitulo'] ?? '');
    $target_url  = trim($_POST['target_url'] ?? '');
    $orden       = intval($_POST['orden'] ?? 1);
    $is_active   = isset($_POST['is_active']) ? 1 : 0;
    $image_url   = '';

    if ($titulo === '' || $target_url === '' || $id_empresa <= 0 || $id_division <= 0) {
        $mensaje = "Completa los campos obligatorios.";
        $tipo_mensaje = "danger";
    }

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
            $mensaje = "Formato no permitido.";
            $tipo_mensaje = "danger";
        } elseif ($filesize > 5 * 1024 * 1024) {
            $mensaje = "La imagen supera 5 MB.";
            $tipo_mensaje = "danger";
        } elseif (!in_array($filetype, $allowed)) {
            $mensaje = "Tipo MIME no permitido.";
            $tipo_mensaje = "danger";
        } else {
            $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/uploads/carousel/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0775, true);
            }

            $newFilename = uniqid('carousel_', true) . '.' . $ext;
            $destination = $uploadDir . $newFilename;

            if (move_uploaded_file($_FILES['image']['tmp_name'], $destination)) {
                $image_url = '/visibility2/portal/uploads/carousel/' . $newFilename;
            } else {
                $mensaje = "No fue posible subir la imagen.";
                $tipo_mensaje = "danger";
            }
        }
    } else if ($mensaje === "") {
        $mensaje = "Debes subir una imagen.";
        $tipo_mensaje = "danger";
    }

    if ($mensaje === "") {
        $tituloEsc    = $conn->real_escape_string($titulo);
        $subtituloEsc = $conn->real_escape_string($subtitulo);
        $targetEsc    = $conn->real_escape_string($target_url);
        $imageEsc     = $conn->real_escape_string($image_url);

        $sql = "
            INSERT INTO dashboard_carousel_items
                (id_empresa, id_division, titulo, subtitulo, image_url, target_url, orden, is_active)
            VALUES
                ($id_empresa, $id_division, '$tituloEsc', '$subtituloEsc', '$imageEsc', '$targetEsc', $orden, $is_active)
        ";

        if ($conn->query($sql)) {
            $mensaje = "Item del carrusel creado correctamente.";
            $tipo_mensaje = "success";
        } else {
            $mensaje = "Error al guardar: " . $conn->error;
            $tipo_mensaje = "danger";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión Carrusel Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/8.0.0/normalize.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:400,500,700">
    <link rel="stylesheet" href="../dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/4.6.2/js/bootstrap.bundle.min.js"></script>

    <style>
        body { background:#f4f6f9; font-family:'Roboto',sans-serif; }
        .module-header{background:linear-gradient(135deg,#0f172a,#1e293b);color:#fff;border-radius:18px;padding:24px 28px;box-shadow:0 10px 30px rgba(15,23,42,.18);margin-bottom:20px}
        .module-header h1{font-size:1.8rem;margin:0 0 6px 0;font-weight:700}
        .module-header p{margin:0;color:rgba(255,255,255,.82);font-size:.96rem}
        .module-badge{display:inline-flex;align-items:center;gap:8px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.12);padding:10px 14px;border-radius:999px;font-size:.9rem;font-weight:500}
        .modern-card{background:#fff;border:0;border-radius:18px;box-shadow:0 8px 24px rgba(15,23,42,.06);overflow:hidden;margin-bottom:20px}
        .modern-card .card-header-custom{padding:18px 22px;border-bottom:1px solid #eef2f7;display:flex;align-items:center;justify-content:space-between;gap:12px}
        .modern-card .card-header-custom h3{margin:0;font-size:1.05rem;font-weight:700;color:#1f2937}
        .modern-card .card-header-custom p{margin:3px 0 0 0;color:#6b7280;font-size:.88rem}
        .modern-card .card-body{padding:22px}
        .soft-chip{display:inline-flex;align-items:center;gap:7px;background:#eef6ff;color:#0f5db8;border-radius:999px;padding:7px 12px;font-size:.83rem;font-weight:600}
        .form-label-modern{font-size:.88rem;font-weight:700;color:#344054;margin-bottom:8px}
        .form-control{height:46px;border-radius:12px!important;border:1px solid #dbe2ea;box-shadow:none!important}
        .form-control:focus{border-color:#60a5fa;box-shadow:0 0 0 .2rem rgba(96,165,250,.15)!important}
        .upload-box{border:2px dashed #cfd8e3;border-radius:18px;padding:26px 18px;text-align:center;background:linear-gradient(180deg,#fafcff,#f8fbff);transition:.25s ease;cursor:pointer;position:relative}
        .upload-box:hover{border-color:#60a5fa;background:#f4f9ff}
        .upload-box i{font-size:2rem;color:#3b82f6;margin-bottom:12px}
        .upload-box h5{font-size:1rem;font-weight:700;color:#1f2937;margin-bottom:6px}
        .upload-box p{margin:0;color:#6b7280;font-size:.88rem}
        .upload-box input[type="file"]{position:absolute;inset:0;opacity:0;cursor:pointer}
        .image-preview-wrapper{margin-top:16px;display:none}
        .image-preview-wrapper img{width:100%;max-height:260px;object-fit:cover;border-radius:16px;border:1px solid #e5e7eb;box-shadow:0 8px 20px rgba(15,23,42,.08)}
        .preview-card{background:linear-gradient(180deg,#ffffff,#f8fafc);border:1px solid #e8edf3;border-radius:22px;overflow:hidden;box-shadow:0 10px 28px rgba(15,23,42,.08)}
        .preview-image{height:240px;background:#e5e7eb center center / cover no-repeat;position:relative}
        .preview-content{padding:18px}
        .preview-main{font-size:1.15rem;font-weight:700;color:#111827;margin-bottom:6px}
        .preview-sub{font-size:.92rem;color:#6b7280;margin-bottom:14px}
        .preview-meta{display:flex;flex-wrap:wrap;gap:8px}
        .preview-pill{font-size:.78rem;font-weight:600;background:#eef2ff;color:#4338ca;border-radius:999px;padding:7px 10px}
        .status-switch{display:flex;align-items:center;gap:10px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:12px;padding:12px 14px}
        .btn-modern-primary{background:linear-gradient(135deg,#2563eb,#1d4ed8);border:0;color:#fff;border-radius:12px;padding:12px 18px;font-weight:700;box-shadow:0 10px 20px rgba(37,99,235,.18)}
        .btn-modern-primary:hover{color:#fff;opacity:.95}
        .btn-modern-light{background:#f8fafc;border:1px solid #dbe2ea;color:#334155;border-radius:12px;padding:12px 18px;font-weight:600}
        .table-zone{min-height:180px;border:1px dashed #d9e2ec;border-radius:16px;background:#fcfdff;padding:14px}
        .alert-modern{border:0;border-radius:14px;padding:14px 16px;font-weight:500}
    </style>
</head>
<body>

<div class="container-fluid mt-4 mb-4">

    <div class="module-header">
        <div class="row align-items-center">
            <div class="col-lg-8">
                <h1><i class="fas fa-images mr-2"></i>Gestión Carrusel Dashboard</h1>
                <p>Administra imágenes del carrusel, orden, destino y visibilidad por empresa y división.</p>
            </div>
            <div class="col-lg-4 text-lg-right mt-3 mt-lg-0">
                <span class="module-badge">
                    <i class="fas fa-mouse-pointer"></i>
                    Slides clickeables
                </span>
            </div>
        </div>
    </div>

    <div class="modern-card">
        <div class="card-header-custom">
            <div>
                <h3><i class="fas fa-plus-circle mr-2 text-success"></i>Crear item del carrusel</h3>
                <p>Sube una imagen y define a qué vista o informe debe enviar.</p>
            </div>
        </div>
        <div class="card-body">

            <?php if ($mensaje != ""): ?>
                <div class="alert alert-<?= $tipo_mensaje ?> alert-modern mb-4">
                    <?= htmlspecialchars($mensaje) ?>
                </div>
            <?php endif; ?>

            <form action="" method="post" enctype="multipart/form-data" id="carouselForm">
                <input type="hidden" name="crear_carousel_item" value="1">

                <div class="row">
                    <div class="col-lg-7">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label-modern">Empresa</label>
                                <select class="form-control" id="empresa" name="empresa" required>
                                    <option value="">Selecciona empresa</option>
                                    <?php
                                    $qEmp = $conn->query("SELECT id, nombre FROM empresa WHERE activo = 1 ORDER BY nombre ASC");
                                    while ($emp = $qEmp->fetch_assoc()):
                                    ?>
                                        <option value="<?= (int)$emp['id'] ?>" <?= ((int)$emp['id'] === (int)$default_empresa ? 'selected' : '') ?>>
                                            <?= htmlspecialchars($emp['nombre']) ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label-modern">División</label>
                                <select class="form-control" id="division" name="division" required>
                                    <?php
                                    $qDiv = $conn->query("SELECT id, nombre FROM division_empresa WHERE id_empresa = $default_empresa AND estado = 1 ORDER BY nombre ASC");
                                    while ($div = $qDiv->fetch_assoc()):
                                    ?>
                                        <option value="<?= (int)$div['id'] ?>" <?= ((int)$div['id'] === (int)$default_division ? 'selected' : '') ?>>
                                            <?= htmlspecialchars($div['nombre']) ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label-modern">Título</label>
                                <input type="text" class="form-control" id="titulo" name="titulo" required>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label-modern">Subtítulo</label>
                                <input type="text" class="form-control" id="subtitulo" name="subtitulo">
                            </div>

                            <div class="col-md-8 mb-3">
                                <label class="form-label-modern">URL destino</label>
                                <input type="text" class="form-control" id="target_url" name="target_url" required placeholder="/visibility2/portal/modulos/mi_visual.php">
                            </div>

                            <div class="col-md-4 mb-3">
                                <label class="form-label-modern">Orden</label>
                                <input type="number" class="form-control" id="orden" name="orden" value="1" min="1" required>
                            </div>

                            <div class="col-md-12 mb-4">
                                <label class="form-label-modern">Imagen</label>
                                <div class="upload-box">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                    <h5>Haz clic para subir la imagen</h5>
                                    <p>JPG, PNG, GIF, WEBP · máximo 5 MB</p>
                                    <input type="file" id="image" name="image" accept=".jpg,.jpeg,.png,.gif,.webp" required>
                                </div>
                                <div class="image-preview-wrapper" id="imagePreviewWrapper">
                                    <img id="imagePreview" src="" alt="Vista previa">
                                </div>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label-modern">Estado</label>
                                <div class="status-switch">
                                    <input type="checkbox" class="form-check-input mt-0" id="is_active" name="is_active" checked style="position:relative; margin-left:0;">
                                    <label class="form-check-label mb-0" for="is_active">Activo</label>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-modern-primary">
                            <i class="fas fa-save mr-2"></i>Guardar item
                        </button>
                    </div>

                    <div class="col-lg-5 mt-4 mt-lg-0">
                        <label class="form-label-modern">Vista previa</label>
                        <div class="preview-card">
                            <div class="preview-image" id="previewImage" style="background-image:url('https://via.placeholder.com/800x450/e5e7eb/6b7280?text=Preview');"></div>
                            <div class="preview-content">
                                <div class="preview-main" id="previewMain">Título del slide</div>
                                <div class="preview-sub" id="previewSub">Subtítulo</div>
                                <div class="preview-meta">
                                    <span class="preview-pill" id="previewStatus">Activo</span>
                                    <span class="preview-pill" id="previewOrden">Orden 1</span>
                                </div>
                                <div class="mt-3 text-muted" id="previewUrl" style="font-size:.84rem;word-break:break-word;">
                                    /visibility2/portal/modulos/mi_visual.php
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
                <h3><i class="fas fa-table mr-2 text-warning"></i>Items registrados</h3>
                <p>Listado de imágenes del carrusel por empresa y división.</p>
            </div>
        </div>
        <div class="card-body">
            <div class="table-zone" id="carousel_table_container">Cargando...</div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalEditarCarousel" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
    <div class="modal-content" style="border-radius:18px;overflow:hidden;border:0;">
      <div class="modal-header" style="background:linear-gradient(135deg,#0f172a,#1e293b);color:#fff;border-bottom:0;">
        <h5 class="modal-title"><i class="fas fa-edit mr-2"></i>Editar item del carrusel</h5>
        <button type="button" class="close text-white" data-dismiss="modal" style="opacity:1;"><span>&times;</span></button>
      </div>

      <form id="formEditarCarousel" enctype="multipart/form-data">
        <div class="modal-body p-4">
          <input type="hidden" name="id" id="edit_id">

          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label-modern">Empresa</label>
              <select class="form-control" name="empresa" id="edit_empresa" required>
                <option value="">Selecciona empresa</option>
                <?php
                $qEmp2 = $conn->query("SELECT id, nombre FROM empresa WHERE activo = 1 ORDER BY nombre ASC");
                while ($emp2 = $qEmp2->fetch_assoc()):
                ?>
                    <option value="<?= (int)$emp2['id'] ?>"><?= htmlspecialchars($emp2['nombre']) ?></option>
                <?php endwhile; ?>
              </select>
            </div>

            <div class="col-md-6 mb-3">
              <label class="form-label-modern">División</label>
              <select class="form-control" name="division" id="edit_division" required></select>
            </div>

            <div class="col-md-6 mb-3">
              <label class="form-label-modern">Título</label>
              <input type="text" class="form-control" name="titulo" id="edit_titulo" required>
            </div>

            <div class="col-md-6 mb-3">
              <label class="form-label-modern">Subtítulo</label>
              <input type="text" class="form-control" name="subtitulo" id="edit_subtitulo">
            </div>

            <div class="col-md-8 mb-3">
              <label class="form-label-modern">URL destino</label>
              <input type="text" class="form-control" name="target_url" id="edit_target_url" required>
            </div>

            <div class="col-md-4 mb-3">
              <label class="form-label-modern">Orden</label>
              <input type="number" class="form-control" name="orden" id="edit_orden" min="1" required>
            </div>

            <div class="col-md-12 mb-3">
              <label class="form-label-modern">Nueva imagen</label>
              <div class="upload-box" style="padding:20px 16px;">
                <i class="fas fa-image"></i>
                <h5>Haz clic para reemplazar la imagen</h5>
                <p>Si no subes una nueva, se mantendrá la actual.</p>
                <input type="file" name="image" id="edit_image" accept=".jpg,.jpeg,.png,.gif,.webp">
              </div>
            </div>

            <div class="col-md-6 mb-3">
              <label class="form-label-modern">Estado</label>
              <div class="status-switch">
                <input type="checkbox" class="form-check-input mt-0" id="edit_is_active" name="is_active" style="position:relative;margin-left:0;">
                <label class="form-check-label mb-0" for="edit_is_active">Activo</label>
              </div>
            </div>

            <div class="col-md-6 mb-3">
              <label class="form-label-modern">Vista previa</label>
              <div class="image-preview-wrapper" id="editImagePreviewWrapper" style="display:block;margin-top:0;">
                <img id="editImagePreview" src="" alt="Vista previa">
              </div>
            </div>
          </div>

          <div id="editCarouselMsg"></div>
        </div>

        <div class="modal-footer bg-light" style="border-top:1px solid #eef2f7;">
          <button type="button" class="btn btn-modern-light" data-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-modern-primary">
            <i class="fas fa-save mr-2"></i>Guardar cambios
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function loadDivisiones(empresaId, targetSelect, selectedValue = '') {
    $.ajax({
        url: 'obtener_divisiones.php',
        type: 'GET',
        data: { empresa_id: empresaId },
        success: function(data) {
            $(targetSelect).html(data);
            if (selectedValue) {
                $(targetSelect).val(selectedValue);
            }
        }
    });
}

function loadCarouselItems() {
    const empresa = $("#empresa").val();
    const division = $("#division").val();

    $.ajax({
        url: 'obtener_dashboard_carousel_items.php',
        type: 'GET',
        data: { empresa_id: empresa, division_id: division },
        success: function(data) {
            $("#carousel_table_container").html(data);
        },
        error: function() {
            $("#carousel_table_container").html('<div class="alert alert-danger mb-0">No fue posible cargar los items.</div>');
        }
    });
}

function updatePreview() {
    $("#previewMain").text($("#titulo").val().trim() || "Título del slide");
    $("#previewSub").text($("#subtitulo").val().trim() || "Subtítulo");
    $("#previewUrl").text($("#target_url").val().trim() || "/visibility2/portal/modulos/mi_visual.php");
    $("#previewOrden").text("Orden " + ($("#orden").val() || "1"));
    $("#previewStatus").text($("#is_active").is(":checked") ? "Activo" : "Inactivo");
}

function previewImage(input, imgId, boxId) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            $(imgId).attr("src", e.target.result);
            $(boxId).show();
            if (imgId === '#imagePreview') {
                $("#previewImage").css("background-image", "url('" + e.target.result + "')");
            }
        };
        reader.readAsDataURL(input.files[0]);
    }
}

$(document).ready(function() {
    loadCarouselItems();
    updatePreview();

    $("#empresa").on("change", function() {
        loadDivisiones($(this).val(), "#division");
        setTimeout(loadCarouselItems, 300);
    });

    $("#division").on("change", loadCarouselItems);

    $("#titulo, #subtitulo, #target_url, #orden").on("input", updatePreview);
    $("#is_active").on("change", updatePreview);
    $("#image").on("change", function(){ previewImage(this, "#imagePreview", "#imagePreviewWrapper"); });

    $(document).on("click", ".btn-editar-carousel", function() {
        $("#edit_id").val($(this).data("id"));
        $("#edit_empresa").val($(this).data("empresa"));
        $("#edit_titulo").val($(this).data("titulo"));
        $("#edit_subtitulo").val($(this).data("subtitulo"));
        $("#edit_target_url").val($(this).data("target"));
        $("#edit_orden").val($(this).data("orden"));
        $("#edit_is_active").prop("checked", parseInt($(this).data("active"), 10) === 1);
        $("#editImagePreview").attr("src", $(this).data("image"));

        const selectedDivision = $(this).data("division");
        loadDivisiones($(this).data("empresa"), "#edit_division", selectedDivision);

        $("#editCarouselMsg").html("");
        $("#modalEditarCarousel").modal("show");
    });

    $("#edit_empresa").on("change", function() {
        loadDivisiones($(this).val(), "#edit_division");
    });

    $("#edit_image").on("change", function() {
        previewImage(this, "#editImagePreview", "#editImagePreviewWrapper");
    });

    $("#formEditarCarousel").on("submit", function(e) {
        e.preventDefault();

        const formData = new FormData(this);

        $.ajax({
            url: 'actualizar_dashboard_carousel_item.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    $("#editCarouselMsg").html('<div class="alert alert-success alert-modern mb-0">Actualizado correctamente.</div>');
                    loadCarouselItems();
                    setTimeout(function(){ $("#modalEditarCarousel").modal("hide"); }, 900);
                } else {
                    $("#editCarouselMsg").html('<div class="alert alert-danger alert-modern mb-0">' + resp.error + '</div>');
                }
            },
            error: function() {
                $("#editCarouselMsg").html('<div class="alert alert-danger alert-modern mb-0">Error de red o servidor.</div>');
            }
        });
    });

    $(document).on("click", ".btn-eliminar-carousel", function() {
        const id = $(this).data("id");
        if (!confirm("¿Deseas eliminar este item del carrusel?")) return;

        $.ajax({
            url: 'eliminar_dashboard_carousel_item.php',
            type: 'POST',
            data: { id: id },
            dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    loadCarouselItems();
                } else {
                    alert(resp.error);
                }
            },
            error: function() {
                alert("Error de red o servidor al eliminar.");
            }
        });
    });
});
</script>

</body>
</html>