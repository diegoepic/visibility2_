<?php
session_start();
date_default_timezone_set('America/Santiago');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
$templateUrl = 'https://visibility.cl/visibility2/portal/repositorio/ETL/template_etl.csv';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>ETL Locales - Visibility 2</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

<style>
    :root{
        --bg: #f4f7fb;
        --card: #ffffff;
        --text: #1f2937;
        --muted: #6b7280;
        --line: #e5e7eb;
        --primary: #0d6efd;
        --primary-dark: #0b5ed7;
        --success: #198754;
        --danger: #dc3545;
        --dark: #111827;
        --shadow-soft: 0 10px 30px rgba(15, 23, 42, 0.07);
        --shadow-card: 0 12px 32px rgba(15, 23, 42, 0.08);
        --radius-xl: 22px;
        --radius-lg: 18px;
        --radius-md: 14px;

        /* tamaño general máximo 85% */
        --ui-font: 0.85rem;
    }

    body {
        background: linear-gradient(180deg, #f8fafc 0%, var(--bg) 100%);
        font-family: 'Segoe UI', Tahoma, sans-serif;
        color: var(--text);
        font-size: var(--ui-font);
    }

    .container {
        margin-top: 36px;
        margin-bottom: 36px;
        max-width: 1320px;
    }

    /* -------- TITULOS PRINCIPALES: sí pueden ser mayores -------- */
    .text-center.mb-4 h2,
    .module-title {
        font-size: 1.85rem !important;
        font-weight: 800 !important;
        letter-spacing: -.02em;
        color: #0f3d91 !important;
        margin-bottom: 10px;
    }

    .text-center.mb-4 p,
    .module-subtitle {
        font-size: 0.98rem !important;
        color: var(--muted) !important;
        max-width: 760px;
        margin: 0 auto;
    }

    .card {
        border: 1px solid rgba(226, 232, 240, 0.9);
        border-radius: var(--radius-xl);
        background: rgba(255, 255, 255, 0.95);
        box-shadow: var(--shadow-card);
        backdrop-filter: blur(8px);
        overflow: hidden;
    }

    .card-header {
        border: 0;
        padding: 16px 20px;
        font-size: 1rem;
        font-weight: 700;
        letter-spacing: .01em;
    }

    .card-body {
        padding: 22px;
        font-size: var(--ui-font);
    }

    .form-label,
    .form-control,
    .btn,
    .table,
    .table td,
    .table th,
    .stat-label,
    .sample-code,
    .alert,
    .text-muted,
    .small,
    small,
    ul,
    li {
        font-size: var(--ui-font) !important;
    }

    .form-control {
        border-radius: 14px;
        border: 1px solid #dbe3ee;
        padding: 12px 14px;
        box-shadow: none;
        transition: .2s ease;
    }

    .form-control:focus {
        border-color: rgba(13, 110, 253, 0.35);
        box-shadow: 0 0 0 0.18rem rgba(13, 110, 253, 0.12);
    }

    .btn {
        border-radius: 14px;
        font-weight: 700;
        padding: 12px 16px;
        box-shadow: none;
    }

    .btn-success {
        background: linear-gradient(135deg, #1fa971 0%, #198754 100%);
        border: none;
    }

    .btn-success:hover {
        background: linear-gradient(135deg, #1c9966 0%, #157347 100%);
    }

    .btn-outline-primary {
        border-radius: 12px;
        font-weight: 700;
    }

    .sample-code {
        background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
        border: 1px solid #dbe3ee;
        border-radius: 16px;
        padding: 14px 16px;
        font-family: Consolas, monospace;
        white-space: pre-wrap;
        color: #334155;
    }

    .stat-box {
        border-radius: 20px;
        background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        border: 1px solid #e5e7eb;
        box-shadow: var(--shadow-soft);
        padding: 22px 18px;
        text-align: center;
        height: 100%;
    }

    .stat-number {
        font-size: 1.7rem;
        font-weight: 800;
        line-height: 1;
        letter-spacing: -.02em;
        margin-bottom: 8px;
    }

    .stat-label {
        color: var(--muted);
        font-weight: 600;
    }

    .table-responsive {
        max-height: 430px;
        overflow: auto;
        border-radius: 16px;
    }

    .table {
        margin-bottom: 0;
    }

    .table thead th {
        background: linear-gradient(135deg, #163d86 0%, #0f2f6b 100%);
        color: #fff;
        position: sticky;
        top: 0;
        z-index: 2;
        border: none;
        padding: 14px 12px;
        font-weight: 700;
        white-space: nowrap;
        box-shadow: inset 0 -1px 0 rgba(255,255,255,.08);
    }

    .table tbody td {
        padding: 12px;
        vertical-align: middle;
        border-color: #eef2f7;
        color: #334155;
        background: #fff;
    }

    .table-hover tbody tr:hover td {
        background: #f8fbff;
    }

    .alert {
        border: 0;
        border-radius: 16px;
        padding: 14px 16px;
        box-shadow: var(--shadow-soft);
    }

    .row.g-4,
    .row.g-3 {
        --bs-gutter-y: 1.25rem;
    }

    ul.mb-0 {
        padding-left: 1.15rem;
    }

    ul.mb-0 li {
        margin-bottom: 10px;
        color: #334155;
    }

    ul.mb-0 li:last-child {
        margin-bottom: 0;
    }

    .text-success,
    .text-danger,
    .text-primary {
        font-weight: 800;
    }

    @media (max-width: 768px) {
        .container {
            margin-top: 22px;
            margin-bottom: 22px;
        }

        .card-body {
            padding: 18px;
        }

        .text-center.mb-4 h2,
        .module-title {
            font-size: 1.45rem !important;
        }

        .stat-number {
            font-size: 1.45rem;
        }
    }
</style>
</head>
<body>

<div class="container">
<div class="text-center mb-4">
    <h2 class="module-title">
        <i class="fa-solid fa-location-dot"></i> ETL Locales
    </h2>
    <p class="module-subtitle mb-0">
        Actualiza nombre, dirección, comuna, distrito, zona, región, cadena y cuenta, recalculando latitud y longitud.
    </p>
</div>

    <div class="row g-4 mb-4">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <i class="fa-solid fa-file-arrow-up"></i> Carga de archivo
                </div>
                <div class="card-body">
                    <form id="formEtlLocales" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                        <div class="mb-3">
                            <label for="csvFile" class="form-label fw-semibold">Archivo CSV</label>
                            <input type="file" class="form-control" id="csvFile" name="csvFile" accept=".csv" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Encabezados esperados</label>
                            <div class="sample-code">codigo;nombre local;direccion;comuna;distrito;zona;region;cadena;cuenta</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Plantilla de carga</label>
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <a href="<?= htmlspecialchars($templateUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                   class="btn btn-outline-primary"
                                   target="_blank"
                                   download>
                                    <i class="fa-solid fa-download"></i> Descargar template CSV
                                </a>
                                <small class="text-muted">
                                    Descarga este archivo como base para respetar la estructura requerida.
                                </small>
                            </div>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-success" id="btnProcesar">
                                <i class="fa-solid fa-gears"></i> Procesar ETL
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-header bg-dark text-white">
                    <i class="fa-solid fa-circle-info"></i> Qué hace este módulo
                </div>
                <div class="card-body">
                    <ul class="mb-0">
                        <li>Busca el local por <strong>código</strong>.</li>
                        <li>Actualiza <strong>nombre</strong>, <strong>dirección</strong> y <strong>comuna</strong>.</li>
                        <li>Recalcula <strong>latitud</strong> y <strong>longitud</strong>.</li>
                        <li>No inserta locales nuevos.</li>
                        <li>Genera reporte CSV con los fallidos.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div id="resultadoBox" style="display:none;">
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="stat-box">
                    <div class="stat-number text-success" id="statUpdated">0</div>
                    <div class="stat-label">Locales actualizados</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-box">
                    <div class="stat-number text-danger" id="statFailed">0</div>
                    <div class="stat-label">Fallidos</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-box">
                    <div class="stat-number text-primary" id="statTotal">0</div>
                    <div class="stat-label">Total procesados</div>
                </div>
            </div>
        </div>

        <div id="reportLinkBox" class="mb-4" style="display:none;"></div>

        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <i class="fa-solid fa-check"></i> Actualizados
                    </div>
                    <div class="card-body table-responsive">
                        <table class="table table-sm table-hover mb-0" id="tablaSuccess">
                            <thead>
                                <tr>
                                    <th>Línea</th>
                                    <th>Código</th>
                                    <th>Nombre nuevo</th>
                                    <th>Direccion nueva</th>                                    
                                    <th>Comuna nueva</th>
                                    <th>Lat</th>
                                    <th>Lng</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header bg-danger text-white">
                        <i class="fa-solid fa-triangle-exclamation"></i> Fallidos
                    </div>
                    <div class="card-body table-responsive">
                        <table class="table table-sm table-hover mb-0" id="tablaFail">
                            <thead>
                                <tr>
                                    <th>Línea</th>
                                    <th>Código</th>
                                    <th>Nombre</th>
                                    <th>Motivo</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script>
function escapeHtml(text) {
    return String(text ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

$('#formEtlLocales').on('submit', function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    const btn = $('#btnProcesar');
    const originalText = btn.html();

    btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Procesando...');

    $.ajax({
        url: 'mod_etl_locales_procesar.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(resp) {
            btn.prop('disabled', false).html(originalText);

            if (!resp || resp.success === false && !resp.updated && !resp.failed) {
                alert(resp?.message || 'No fue posible procesar el archivo.');
                return;
            }

            $('#resultadoBox').show();

            $('#statUpdated').text(resp.updated || 0);
            $('#statFailed').text(resp.failed || 0);
            $('#statTotal').text((Number(resp.updated || 0) + Number(resp.failed || 0)));

            const tbodySuccess = $('#tablaSuccess tbody').empty();
            const tbodyFail = $('#tablaFail tbody').empty();

            (resp.successes || []).forEach(row => {
                tbodySuccess.append(`
                    <tr>
                        <td>${escapeHtml(row.line)}</td>
                        <td>${escapeHtml(row.codigo)}</td>
                        <td>${escapeHtml(row.nombre_nuevo)}</td>
                        <td>${escapeHtml(row.direccion_nueva)}</td>                        
                        <td>${escapeHtml(row.comuna_nueva)}</td>
                        <td>${escapeHtml(row.lat)}</td>
                        <td>${escapeHtml(row.lng)}</td>
                    </tr>
                `);
            });

            (resp.failures || []).forEach(row => {
                tbodyFail.append(`
                    <tr>
                        <td>${escapeHtml(row.line)}</td>
                        <td>${escapeHtml(row.codigo)}</td>
                        <td>${escapeHtml(row.nombre)}</td>
                        <td>${escapeHtml(row.reason)}</td>
                    </tr>
                `);
            });

            if (resp.reportUrl) {
                $('#reportLinkBox')
                    .show()
                    .html(`
                        <div class="alert alert-warning mb-0">
                            Se generó un reporte de fallidos:
                            <a href="${escapeHtml(resp.reportUrl)}" target="_blank" class="fw-bold ms-1">Descargar reporte CSV</a>
                        </div>
                    `);
            } else {
                $('#reportLinkBox').hide().empty();
            }
        },
        error: function(xhr) {
            btn.prop('disabled', false).html(originalText);

            let msg = 'Ocurrió un error al procesar el ETL.';
            if (xhr.responseJSON?.message) {
                msg = xhr.responseJSON.message;
            } else if (xhr.responseText) {
                msg = xhr.responseText;
            }

            alert(msg);
        }
    });
});
</script>

</body>
</html>