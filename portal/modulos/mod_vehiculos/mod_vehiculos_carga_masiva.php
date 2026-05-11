<?php
session_start();
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
date_default_timezone_set('America/Santiago');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Carga Masiva Vehículos - Visibility</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

    <style>
        body {
            background: #f4f6f9;
            font-family: 'Segoe UI', sans-serif;
        }

        .page-header {
            background: linear-gradient(135deg, #1f2937, #111827);
            color: white;
            border-radius: 22px;
            padding: 28px;
            margin-bottom: 24px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, .18);
        }

        .page-header h3 {
            margin: 0;
            font-weight: 700;
        }

        .page-header p {
            margin: 8px 0 0;
            color: #d1d5db;
        }

        .card-modern {
            border: none;
            border-radius: 20px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, .08);
        }

        .btn-main {
            border-radius: 14px;
            padding: 10px 18px;
            font-weight: 600;
        }

        .upload-box {
            border: 2px dashed #cbd5e1;
            border-radius: 20px;
            padding: 30px;
            background: #f8fafc;
            text-align: center;
        }

        .upload-box i {
            font-size: 42px;
            color: #111827;
            margin-bottom: 12px;
        }

        .table thead th {
            background: #111827;
            color: white;
            font-size: 13px;
            border: none;
        }

        .table tbody td {
            font-size: 13px;
            vertical-align: middle;
        }

        .badge-soft {
            padding: 7px 10px;
            border-radius: 999px;
            font-weight: 700;
            font-size: 12px;
        }

        .badge-ok {
            background: #dcfce7;
            color: #166534;
        }

        .badge-error {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-warning-soft {
            background: #fef3c7;
            color: #92400e;
        }

        .summary-card {
            border-radius: 18px;
            background: #f9fafb;
            padding: 18px;
            border: 1px solid #e5e7eb;
        }

        .summary-number {
            font-size: 26px;
            font-weight: 800;
            color: #111827;
        }

        .summary-label {
            color: #6b7280;
            font-size: 13px;
            font-weight: 600;
        }

        .code-sample {
            background: #111827;
            color: #e5e7eb;
            border-radius: 14px;
            padding: 16px;
            font-size: 13px;
            overflow-x: auto;
        }
    </style>
</head>

<body>

<div class="container-fluid p-4">

    <div class="page-header d-flex justify-content-between align-items-center">
        <div>
            <h3><i class="fa-solid fa-file-arrow-up me-2"></i> Carga Masiva de Vehículos</h3>
            <p>Importa vehículos y asignaciones históricas desde un archivo CSV.</p>
        </div>

        <a href="mod_vehiculos.php" class="btn btn-light btn-main">
            <i class="fa-solid fa-arrow-left me-1"></i> Volver
        </a>
    </div>

    <div class="row g-4">

        <div class="col-lg-5">
            <div class="card card-modern">
                <div class="card-body">

                    <h5 class="fw-bold mb-3">
                        <i class="fa-solid fa-cloud-arrow-up me-2"></i> Subir archivo
                    </h5>

                    <form id="formCargaMasiva" enctype="multipart/form-data">
                        <div class="upload-box mb-3">
                            <i class="fa-solid fa-file-csv"></i>
                            <h6 class="fw-bold">Selecciona archivo CSV</h6>
                            <p class="text-muted mb-3">
                                Separador permitido: punto y coma ; o coma ,
                            </p>

                            <input type="file" name="archivo_csv" id="archivo_csv" class="form-control" accept=".csv,text/csv" required>
                        </div>

                        <button type="submit" class="btn btn-dark btn-main w-100">
                            <i class="fa-solid fa-play me-1"></i> Procesar carga
                        </button>
                    </form>

                    <hr>

                    <button type="button" class="btn btn-outline-primary btn-main w-100" onclick="descargarPlantilla()">
                        <i class="fa-solid fa-download me-1"></i> Descargar plantilla CSV
                    </button>

                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card card-modern">
                <div class="card-body">

                    <h5 class="fw-bold mb-3">
                        <i class="fa-solid fa-list-check me-2"></i> Formato requerido
                    </h5>

                    <div class="code-sample">
patente;modelo;tipo_combustible;direccion_origen;empresa;division;subdivision;merchan;fecha_inicio;estado;observacion<br>
AAAA-11;Toyota Hilux;DIESEL;Av. Providencia 1234;MENTE CREATIVA;RED BULL;RM;frojas;2026-05-07;1;Asignación inicial
                    </div>

                    <div class="alert alert-info mt-3 mb-0">
                        <strong>Importante:</strong> El campo <b>merchan</b> solo aceptará usuarios activos con <b>id_perfil = 3</b>.
                        El combustible debe ser <b>93</b>, <b>95</b>, <b>97</b> o <b>DIESEL</b>.
                    </div>

                </div>
            </div>
        </div>

    </div>

    <div id="resultadoCarga" class="mt-4" style="display:none;">

        <div class="row g-3 mb-4">
            <div class="col-md-2">
                <div class="summary-card">
                    <div class="summary-number" id="sumTotal">0</div>
                    <div class="summary-label">Total filas</div>
                </div>
            </div>

            <div class="col-md-2">
                <div class="summary-card">
                    <div class="summary-number" id="sumInsertados">0</div>
                    <div class="summary-label">Insertados</div>
                </div>
            </div>

            <div class="col-md-2">
                <div class="summary-card">
                    <div class="summary-number" id="sumActualizados">0</div>
                    <div class="summary-label">Actualizados</div>
                </div>
            </div>

            <div class="col-md-2">
                <div class="summary-card">
                    <div class="summary-number" id="sumHistorial">0</div>
                    <div class="summary-label">Historial creado</div>
                </div>
            </div>

            <div class="col-md-2">
                <div class="summary-card">
                    <div class="summary-number" id="sumSinCambio">0</div>
                    <div class="summary-label">Sin cambio</div>
                </div>
            </div>

            <div class="col-md-2">
                <div class="summary-card">
                    <div class="summary-number" id="sumErrores">0</div>
                    <div class="summary-label">Errores</div>
                </div>
            </div>
        </div>

        <div class="card card-modern">
            <div class="card-body">
                <h5 class="fw-bold mb-3">
                    <i class="fa-solid fa-table me-2"></i> Resultado por fila
                </h5>

                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                        <tr>
                            <th>Fila</th>
                            <th>Patente</th>
                            <th>Acción</th>
                            <th>Estado</th>
                            <th>Mensaje</th>
                        </tr>
                        </thead>
                        <tbody id="tbodyResultado">
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<script>
$('#formCargaMasiva').on('submit', function(e) {
    e.preventDefault();

    const formData = new FormData(this);

    $.ajax({
        url: 'ajax_vehiculos_carga_masiva.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        beforeSend: function() {
            $('#resultadoCarga').hide();
            $('#tbodyResultado').html('');

            $('#formCargaMasiva button[type="submit"]')
                .prop('disabled', true)
                .html('<i class="fa-solid fa-spinner fa-spin me-1"></i> Procesando...');
        },
        success: function(resp) {
            if (!resp || !resp.ok) {
                alert(resp?.msg || 'No se pudo procesar la carga.');
                return;
            }

            renderResultado(resp);
        },
        error: function(xhr) {
            console.error(xhr.responseText);
            alert('Error inesperado al procesar la carga masiva.');
        },
        complete: function() {
            $('#formCargaMasiva button[type="submit"]')
                .prop('disabled', false)
                .html('<i class="fa-solid fa-play me-1"></i> Procesar carga');
        }
    });
});

function escapeHtml(text) {
    if (text === null || text === undefined) return '';

    return String(text)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function renderResultado(resp) {
    const resumen = resp.resumen || {};
    const filas = resp.filas || [];

    $('#sumTotal').text(resumen.total || 0);
    $('#sumInsertados').text(resumen.insertados || 0);
    $('#sumActualizados').text(resumen.actualizados || 0);
    $('#sumHistorial').text(resumen.historial_creado || 0);
    $('#sumSinCambio').text(resumen.sin_cambio || 0);
    $('#sumErrores').text(resumen.errores || 0);

    let html = '';

    filas.forEach(row => {
        let badge = '';

        if (row.estado === 'ok') {
            badge = '<span class="badge-soft badge-ok">OK</span>';
        } else if (row.estado === 'warning') {
            badge = '<span class="badge-soft badge-warning-soft">Aviso</span>';
        } else {
            badge = '<span class="badge-soft badge-error">Error</span>';
        }

        html += `
            <tr>
                <td>${escapeHtml(row.fila)}</td>
                <td>${escapeHtml(row.patente || '-')}</td>
                <td>${escapeHtml(row.accion || '-')}</td>
                <td>${badge}</td>
                <td>${escapeHtml(row.mensaje || '')}</td>
            </tr>
        `;
    });

    $('#tbodyResultado').html(html);
    $('#resultadoCarga').show();
}

function descargarPlantilla() {
    const contenido = [
        'patente;modelo;tipo_combustible;direccion_origen;empresa;division;subdivision;merchan;fecha_inicio;estado;observacion',
        'AAAA-11;Toyota Hilux;DIESEL;Av. Providencia 1234;MENTE CREATIVA;RED BULL;RM;frojas;2026-05-07;1;Asignación inicial'
    ].join('\n');

    const blob = new Blob([contenido], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);

    const link = document.createElement('a');
    link.href = url;
    link.download = 'plantilla_carga_vehiculos.csv';
    link.click();

    URL.revokeObjectURL(url);
}
</script>

</body>
</html>