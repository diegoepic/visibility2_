<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

$mensaje = "";
$tipo_mensaje = "info";

$default_empresa  = $_SESSION['empresa_id'];
$default_division = $_SESSION['division_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear'])) {
    $empresa_id  = $conn->real_escape_string($_POST['empresa']);
    $division_id = $conn->real_escape_string($_POST['division']);

    $image_url = '';

    if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
        $allowed = array(
            "jpg"  => "image/jpeg",
            "jpeg" => "image/jpeg",
            "png"  => "image/png",
            "gif"  => "image/gif"
        );

        $filename = $_FILES['image']['name'];
        $filetype = $_FILES['image']['type'];
        $filesize = $_FILES['image']['size'];

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (!array_key_exists($ext, $allowed)) {
            $mensaje = "Por favor selecciona un formato válido: JPG, JPEG, PNG o GIF.";
            $tipo_mensaje = "danger";
        } elseif ($filesize > 5 * 1024 * 1024) {
            $mensaje = "La imagen supera el tamaño máximo permitido de 5 MB.";
            $tipo_mensaje = "danger";
        } else {
            if (in_array($filetype, $allowed)) {
                $newFilename = uniqid() . "." . $ext;
                $destination = $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/uploads/dashboard/' . $newFilename;

                if (move_uploaded_file($_FILES['image']['tmp_name'], $destination)) {
                    $image_url = '/visibility2/portal/uploads/dashboard/' . $newFilename;
                } else {
                    $mensaje = "Error al subir el archivo.";
                    $tipo_mensaje = "danger";
                }
            } else {
                $mensaje = "Formato de archivo no permitido.";
                $tipo_mensaje = "danger";
            }
        }
    } else {
        $mensaje = "Debes subir una imagen.";
        $tipo_mensaje = "danger";
    }

    $sqlMax = "
        SELECT COALESCE(MAX(orden), 0) AS maxo
        FROM dashboard_items
        WHERE id_empresa = '$empresa_id'
          AND id_division = '$division_id'
    ";
    $resMax = $conn->query($sqlMax);

    if ($resMax) {
        $rowMax = $resMax->fetch_assoc();
        $nextOrden = intval($rowMax['maxo']) + 1;
    } else {
        $nextOrden = 1;
    }

    if ($mensaje == "") {
        $target_url  = $conn->real_escape_string($_POST['target_url']);
        $main_label  = $conn->real_escape_string($_POST['main_label']);
        $sub_label   = $conn->real_escape_string($_POST['sub_label']);
        $icon_class  = $conn->real_escape_string($_POST['icon_class']);
        $is_active   = isset($_POST['is_active']) ? 1 : 0;

        $sql = "
            INSERT INTO dashboard_items
                (id_empresa, id_division, image_url, target_url,
                 main_label, sub_label, icon_class, is_active, orden)
            VALUES
                (
                    '$empresa_id',
                    '$division_id',
                    '$image_url',
                    '$target_url',
                    '$main_label',
                    '$sub_label',
                    '$icon_class',
                    '$is_active',
                    '$nextOrden'
                )
        ";

        if ($conn->query($sql) === TRUE) {
            $mensaje = "Dashboard creado exitosamente.";
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
    <title>Gestión de Dashboards</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/8.0.0/normalize.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:400,500,700">
    <link rel="stylesheet" href="dist/css/adminlte.min.css">
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

        .form-control,
        .custom-file-input,
        .custom-select {
            height: 46px;
            border-radius: 12px !important;
            border: 1px solid #dbe2ea;
            box-shadow: none !important;
        }

        textarea.form-control {
            height: auto;
            min-height: 110px;
            padding-top: 12px;
        }

        .form-control:focus,
        .custom-select:focus {
            border-color: #60a5fa;
            box-shadow: 0 0 0 0.2rem rgba(96,165,250,.15) !important;
        }

        .filters-grid .filter-box {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 16px;
            height: 100%;
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
            max-height: 260px;
            object-fit: cover;
            border-radius: 16px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 8px 20px rgba(15,23,42,.08);
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
            background: #e5e7eb center center / cover no-repeat;
            display: flex;
            align-items: flex-end;
            justify-content: flex-end;
            padding: 14px;
            position: relative;
        }

        .preview-image::before {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(to top, rgba(0,0,0,.30), rgba(0,0,0,.02));
        }

        .preview-icon-badge {
            position: relative;
            z-index: 2;
            width: 46px;
            height: 46px;
            border-radius: 50%;
            background: rgba(255,255,255,.92);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #111827;
            font-size: 1rem;
            box-shadow: 0 8px 20px rgba(0,0,0,.12);
        }

        .preview-content {
            padding: 18px 18px 16px 18px;
        }

        .preview-main {
            font-size: 1.15rem;
            font-weight: 700;
            color: #111827;
            margin-bottom: 6px;
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

    <!-- Header principal -->
    <div class="module-header">
        <div class="row align-items-center">
            <div class="col-lg-8">
                <h1><i class="fas fa-chart-line mr-2"></i>Gestión de Dashboards</h1>
                <p>Administra accesos, miniaturas, enlaces y visibilidad de dashboards por empresa y división.</p>
            </div>
            <div class="col-lg-4 text-lg-right mt-3 mt-lg-0">
                <span class="module-badge">
                    <i class="fas fa-link"></i>
                    Módulo de enlaces Power BI
                </span>
            </div>
        </div>
    </div>

    <!-- Barra de filtros -->
    <div class="modern-card">
        <div class="card-header-custom">
            <div>
                <h3><i class="fas fa-filter mr-2 text-primary"></i>Filtros de visualización</h3>
                <p>Selecciona la empresa y división para trabajar sobre los dashboards correspondientes.</p>
            </div>
            <div class="soft-chip">
                <i class="fas fa-sync-alt"></i>
                Actualización dinámica
            </div>
        </div>
        <div class="card-body">
            <div class="row filters-grid">
                <div class="col-lg-4 mb-3">
                    <div class="filter-box">
                        <label for="empresa" class="form-label-modern">Empresa</label>
                        <select class="form-control" name="empresa" id="empresa">
                            <?php
                            $queryEmpresa = "SELECT * FROM empresa WHERE activo = 1";
                            $resultEmpresa = $conn->query($queryEmpresa);
                            while ($empresa = $resultEmpresa->fetch_assoc()):
                                $selected = ($empresa['id'] == $default_empresa) ? "selected" : "";
                            ?>
                                <option value="<?php echo $empresa['id']; ?>" <?php echo $selected; ?>>
                                    <?php echo htmlspecialchars($empresa['nombre']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>

                <div class="col-lg-4 mb-3">
                    <div class="filter-box">
                        <label for="division" class="form-label-modern">División</label>
                        <select class="form-control" name="division" id="division">
                            <?php
                            $queryDivision = "SELECT * FROM division_empresa WHERE id_empresa = '$default_empresa' AND estado = 1";
                            $resultDivision = $conn->query($queryDivision);
                            while ($division = $resultDivision->fetch_assoc()):
                                $selected = ($division['id'] == $default_division) ? "selected" : "";
                            ?>
                                <option value="<?php echo $division['id']; ?>" <?php echo $selected; ?>>
                                    <?php echo htmlspecialchars($division['nombre']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>

                <div class="col-lg-4 mb-3">
                    <div class="stats-box">
                        <i class="fas fa-th-large"></i>
                        <div>
                            <strong>Dashboards registrados</strong>
                            <span>La tabla inferior se actualiza según la selección.</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Formulario + preview -->
    <div class="modern-card">
        <div class="card-header-custom">
            <div>
                <h3><i class="fas fa-plus-circle mr-2 text-success"></i>Crear nuevo dashboard</h3>
                <p>Completa los datos del dashboard y revisa la vista previa antes de guardarlo.</p>
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

            <form action="" method="post" enctype="multipart/form-data" id="dashboardForm">
                <input type="hidden" name="empresa" id="form_empresa" value="<?php echo $default_empresa; ?>">
                <input type="hidden" name="division" id="form_division" value="<?php echo $default_division; ?>">
                <input type="hidden" name="crear" value="1">

                <div class="row">
                    <!-- Columna formulario -->
                    <div class="col-lg-7">
                        <div class="row">
                            <div class="col-12 mb-4">
                                <label class="form-label-modern">Imagen del dashboard</label>
                                <div class="upload-box">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                    <h5>Arrastra una imagen o haz clic para subir</h5>
                                    <p>Formatos permitidos: JPG, JPEG, PNG, GIF · máximo 5 MB</p>
                                    <input type="file" id="image" name="image" accept=".jpg,.jpeg,.png,.gif" required>
                                </div>
                                <div class="image-preview-wrapper" id="imagePreviewWrapper">
                                    <img id="imagePreview" src="" alt="Vista previa de imagen">
                                </div>
                            </div>

                            <div class="col-md-12 mb-3">
                                <label for="target_url" class="form-label-modern">URL de destino</label>
                                <input type="text" id="target_url" name="target_url" class="form-control" required placeholder="https://app.powerbi.com/...">
                                <small class="small-help">Pega aquí el enlace del dashboard o reporte embebido.</small>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="main_label" class="form-label-modern">Etiqueta principal</label>
                                <input type="text" id="main_label" name="main_label" class="form-control" required placeholder="Ej: Dashboard Comercial">
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="sub_label" class="form-label-modern">Etiqueta secundaria</label>
                                <input type="text" id="sub_label" name="sub_label" class="form-control" required placeholder="Ej: Seguimiento diario">
                            </div>

                            <div class="col-md-8 mb-3">
                                <label for="icon_class" class="form-label-modern">Clase del ícono</label>
                                <input type="text" id="icon_class" name="icon_class" class="form-control" required placeholder="Ej: fas fa-chart-bar">
                                <small class="small-help">Usa clases de Font Awesome, por ejemplo: <strong>fas fa-chart-pie</strong>.</small>
                            </div>

                            <div class="col-md-4 mb-3">
                                <label class="form-label-modern">Estado</label>
                                <div class="status-switch">
                                    <input type="checkbox" class="form-check-input mt-0" id="is_active" name="is_active" checked style="position:relative; margin-left:0;">
                                    <label class="form-check-label mb-0" for="is_active">Activo</label>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap gap-2 mt-2">
                            <button type="submit" class="btn btn-modern-primary mr-2">
                                <i class="fas fa-save mr-2"></i>Crear Dashboard
                            </button>

                            <button type="reset" class="btn btn-modern-light" id="btnResetPreview">
                                <i class="fas fa-undo mr-2"></i>Limpiar formulario
                            </button>
                        </div>
                    </div>

                    <!-- Columna preview -->
                    <div class="col-lg-5 mt-4 mt-lg-0">
                        <label class="form-label-modern">Vista previa</label>
                        <div class="preview-card">
                            <div class="preview-image" id="previewImage" style="background-image:url('https://via.placeholder.com/800x450/e5e7eb/6b7280?text=Preview+Dashboard');">
                                <div class="preview-icon-badge" id="previewIcon">
                                    <i class="fas fa-chart-bar"></i>
                                </div>
                            </div>

                            <div class="preview-content">
                                <div class="preview-main" id="previewMain">Etiqueta principal</div>
                                <div class="preview-sub" id="previewSub">Etiqueta secundaria</div>

                                <div class="preview-meta">
                                    <span class="preview-pill" id="previewStatus">Activo</span>
                                    <span class="preview-pill">Power BI</span>
                                    <span class="preview-pill">Dashboard Item</span>
                                </div>

                                <div class="mt-3 text-muted" style="font-size:.84rem; word-break: break-word;" id="previewUrl">
                                    https://app.powerbi.com/...
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Tabla/listado -->
    <div class="modern-card">
        <div class="card-header-custom">
            <div>
                <h3><i class="fas fa-table mr-2 text-warning"></i>Dashboards registrados</h3>
                <p>Listado dinámico de dashboards creados para la empresa y división seleccionadas.</p>
            </div>
            <div class="soft-chip" style="background:#fff7ed;color:#c2410c;">
                <i class="fas fa-layer-group"></i>
                Gestión centralizada
            </div>
        </div>
        <div class="card-body">
            <div class="table-zone" id="dashboard_table_container">
                Cargando dashboards...
            </div>
        </div>
    </div>

</div>

<script>
    function updateDashboardTable() {
        var empresa_id = $("#empresa").val();
        var division_id = $("#division").val();

        $("#dashboard_table_container").html("Cargando dashboards...");

        $.ajax({
            url: 'modulos/obtener_dashboard_items.php',
            type: 'GET',
            data: { empresa_id: empresa_id, division_id: division_id },
            success: function(data) {
                $("#dashboard_table_container").html(data);
            },
            error: function() {
                $("#dashboard_table_container").html('<div class="alert alert-danger mb-0">No fue posible cargar los dashboards.</div>');
            }
        });
    }

    function syncHiddenFilters() {
        $("#form_empresa").val($("#empresa").val());
        $("#form_division").val($("#division").val());
    }

    function updatePreview() {
        const mainLabel = $("#main_label").val().trim() || "Etiqueta principal";
        const subLabel  = $("#sub_label").val().trim() || "Etiqueta secundaria";
        const url       = $("#target_url").val().trim() || "https://app.powerbi.com/...";
        const iconClass = $("#icon_class").val().trim() || "fas fa-chart-bar";
        const isActive  = $("#is_active").is(":checked");

        $("#previewMain").text(mainLabel);
        $("#previewSub").text(subLabel);
        $("#previewUrl").text(url);
        $("#previewStatus").text(isActive ? "Activo" : "Inactivo");
        $("#previewIcon").html('<i class="' + iconClass + '"></i>');
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

    $(document).ready(function() {
        updateDashboardTable();
        updatePreview();

        $("#empresa").on("change", function() {
            const empresa_id = $(this).val();
            $("#form_empresa").val(empresa_id);

            $.ajax({
                url: 'modulos/obtener_divisiones.php',
                type: 'GET',
                data: { empresa_id: empresa_id },
                success: function(data) {
                    $("#division").html(data);
                    const firstDivision = $("#division option:first").val();
                    $("#form_division").val(firstDivision);
                    updateDashboardTable();
                }
            });
        });

        $("#division").on("change", function() {
            syncHiddenFilters();
            updateDashboardTable();
        });

        $("#target_url, #main_label, #sub_label, #icon_class").on("input", function() {
            updatePreview();
        });

        $("#is_active").on("change", function() {
            updatePreview();
        });

        $("#image").on("change", function() {
            previewImage(this);
        });

        $("#btnResetPreview").on("click", function() {
            setTimeout(function() {
                $("#imagePreviewWrapper").hide();
                $("#imagePreview").attr("src", "");
                $("#previewImage").css("background-image", "url('https://via.placeholder.com/800x450/e5e7eb/6b7280?text=Preview+Dashboard')");
                updatePreview();
            }, 50);
        });
    });

    $(document).on('submit', 'form[action="modulos/actualizar_dashboard_item.php"]', function(e) {
        e.preventDefault();

        var $form = $(this);
        var formData = new FormData(this);

        $.ajax({
            url: $form.attr('action'),
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json'
        })
        .done(function(resp) {
            if (resp.success) {
                updateDashboardTable();
            } else {
                alert('Error al guardar: ' + resp.error);
            }
        })
        .fail(function() {
            alert('Error de red o servidor al guardar.');
        });
    });
</script>

</body>
</html>