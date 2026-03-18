<?php
session_start();
date_default_timezone_set('America/Santiago');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
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
        body {
            background: #f4f6f9;
            font-family: 'Segoe UI', Tahoma, sans-serif;
        }
        .container {
            margin-top: 35px;
            margin-bottom: 35px;
        }
        .card {
            border: none;
            border-radius: 14px;
            box-shadow: 0 4px 12px rgba(0,0,0,.08);
        }
        .card-header {
            border-top-left-radius: 14px !important;
            border-top-right-radius: 14px !important;
            font-weight: 600;
        }
        .stat-box {
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 3px 10px rgba(0,0,0,.08);
            padding: 18px;
            text-align: center;
            height: 100%;
        }
        .stat-number {
            font-size: 1.8rem;
            font-weight: 700;
            line-height: 1;
        }
        .stat-label {
            color: #6c757d;
            margin-top: 8px;
        }
        .table-responsive {
            max-height: 420px;
            overflow: auto;
        }
        .table thead th {
            background: #004AAD;
            color: #fff;
            position: sticky;
            top: 0;
            z-index: 2;
        }
        .sample-code {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 10px;
            padding: 14px;
            font-family: Consolas, monospace;
            font-size: .92rem;
            white-space: pre-wrap;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="text-center mb-4">
        <h2 class="fw-bold text-primary">
            <i class="fa-solid fa-location-dot"></i> ETL Locales
        </h2>
        <p class="text-muted mb-0">
            Actualiza nombre, dirección, comuna y recalcula latitud/longitud usando Google Geocoding.
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
                            <div class="sample-code">codigo;nombre local;direccion;comuna;region</div>
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