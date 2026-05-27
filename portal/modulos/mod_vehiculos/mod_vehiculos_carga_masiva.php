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
        
/* =========================================================
   MODERN UI - CARGA MASIVA VEHÍCULOS
========================================================= */

:root {
    --vz-bg: #f5f8fc;
    --vz-bg-2: #eef3f9;
    --vz-card: rgba(255,255,255,.88);
    --vz-border: rgba(215,228,246,.95);
    --vz-text: #172848;
    --vz-muted: #7285a4;
    --vz-blue: #4d7eff;
    --vz-blue-2: #6a73ff;
    --vz-green: #1fb57c;
    --vz-red: #ef6f6c;
    --vz-purple: #7d5fff;
    --vz-warning: #f6b93b;
    --vz-shadow: 0 24px 55px rgba(70,95,140,.12);
    --vz-soft-shadow: 0 12px 28px rgba(70,95,140,.08);
}

body {
    min-height: 100vh;
    background:
        radial-gradient(circle at 12% 8%, rgba(95,160,255,.12), transparent 34%),
        linear-gradient(180deg, var(--vz-bg) 0%, var(--vz-bg-2) 100%) !important;
    color: var(--vz-text);
    font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
}

.container-fluid.p-4 {
    max-width: 1480px;
    margin: 0 auto;
    padding: 30px 24px !important;
}

/* Header */

.page-header {
    position: relative;
    overflow: hidden;
    border-radius: 32px !important;
    padding: 30px !important;
    margin-bottom: 22px !important;
    border: 1px solid var(--vz-border);
    background:
        radial-gradient(circle at 10% 0%, rgba(95,160,255,.14), transparent 36%),
        linear-gradient(180deg, rgba(255,255,255,.92), rgba(245,249,255,.84)) !important;
    color: var(--vz-text) !important;
    box-shadow:
        var(--vz-shadow),
        inset 0 1px 0 rgba(255,255,255,.88) !important;
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
}

.page-header::after {
    content: "";
    position: absolute;
    width: 440px;
    height: 440px;
    right: -190px;
    top: -230px;
    border-radius: 50%;
    background: rgba(77,126,255,.08);
    pointer-events: none;
}

.page-header > div:first-child {
    position: relative;
    z-index: 2;
    padding-left: 84px;
    min-height: 66px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.page-header > div:first-child::before {
    content: "\f574";
    font-family: "Font Awesome 6 Free";
    font-weight: 900;
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    width: 66px;
    height: 66px;
    border-radius: 22px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(145deg, #f7fbff, #dcecff);
    color: var(--vz-blue);
    font-size: 26px;
    box-shadow:
        0 12px 26px rgba(70,110,180,.12),
        inset 0 1px 0 rgba(255,255,255,.9);
}

.page-header h3 {
    margin: 0 !important;
    font-size: 30px;
    line-height: 1.1;
    font-weight: 950 !important;
    color: var(--vz-text) !important;
    letter-spacing: .2px;
}

.page-header h3 i {
    display: none;
}

.page-header p {
    margin: 7px 0 0 !important;
    color: var(--vz-muted) !important;
    font-size: 14px;
    font-weight: 650;
}

.page-header .btn-light {
    position: relative;
    z-index: 2;
    min-height: 46px;
    border-radius: 15px !important;
    border: 1px solid rgba(200,215,238,.9) !important;
    background: rgba(255,255,255,.86) !important;
    color: #355277 !important;
    font-size: 13px !important;
    font-weight: 850 !important;
    padding: 0 18px !important;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    box-shadow:
        0 14px 28px rgba(70,95,140,.12),
        inset 0 1px 0 rgba(255,255,255,.32) !important;
}

.page-header .btn-light:hover {
    background: linear-gradient(135deg, var(--vz-blue), var(--vz-blue-2)) !important;
    color: #fff !important;
    transform: translateY(-2px);
}

/* Cards */

.card-modern {
    border: 1px solid var(--vz-border) !important;
    border-radius: 30px !important;
    background:
        radial-gradient(circle at 10% 0%, rgba(95,160,255,.08), transparent 36%),
        linear-gradient(180deg, rgba(255,255,255,.90), rgba(245,249,255,.82)) !important;
    box-shadow:
        0 24px 55px rgba(70,95,140,.12),
        inset 0 1px 0 rgba(255,255,255,.88) !important;
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    overflow: hidden;
}

.card-modern .card-body {
    padding: 24px !important;
}

.card-modern h5 {
    color: var(--vz-text);
    font-size: 18px;
    font-weight: 950 !important;
}

.card-modern h5 i {
    color: var(--vz-blue);
}

/* Upload box */

.upload-box {
    position: relative;
    border: 1px dashed rgba(77,126,255,.45) !important;
    border-radius: 26px !important;
    padding: 34px 24px !important;
    background:
        radial-gradient(circle at 50% 0%, rgba(77,126,255,.08), transparent 36%),
        rgba(255,255,255,.76) !important;
    text-align: center;
    box-shadow:
        inset 0 1px 0 rgba(255,255,255,.88),
        0 12px 28px rgba(70,95,140,.06);
}

.upload-box::before {
    content: "";
    position: absolute;
    inset: 12px;
    border-radius: 20px;
    border: 1px solid rgba(215,228,246,.65);
    pointer-events: none;
}

.upload-box i {
    width: 72px;
    height: 72px;
    border-radius: 24px;
    margin: 0 auto 16px !important;
    background: linear-gradient(135deg, var(--vz-blue), var(--vz-blue-2));
    color: #fff !important;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 30px !important;
    box-shadow: 0 18px 38px rgba(77,126,255,.26);
}

.upload-box h6 {
    color: var(--vz-text);
    font-size: 17px;
    font-weight: 950 !important;
}

.upload-box p {
    color: var(--vz-muted) !important;
    font-size: 13px;
    font-weight: 700;
}

.upload-box .form-control {
    min-height: 46px;
    border-radius: 15px !important;
    border: 1px solid rgba(185,202,230,.78) !important;
    background: rgba(255,255,255,.92) !important;
    color: #28446f !important;
    font-size: 13px !important;
    font-weight: 700;
    box-shadow:
        0 8px 18px rgba(70,95,140,.05),
        inset 0 1px 0 rgba(255,255,255,.8) !important;
}

/* Botones */

.btn-main {
    min-height: 46px;
    border-radius: 15px !important;
    border: 0 !important;
    padding: 0 18px !important;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    font-size: 13px !important;
    font-weight: 850 !important;
    box-shadow:
        0 14px 28px rgba(70,95,140,.12),
        inset 0 1px 0 rgba(255,255,255,.32) !important;
    transition: transform .18s ease, box-shadow .18s ease;
}

.btn-main:hover {
    transform: translateY(-2px);
}

.btn-dark {
    background: linear-gradient(135deg, var(--vz-blue), var(--vz-blue-2)) !important;
    color: #fff !important;
}

.btn-outline-primary {
    background: rgba(255,255,255,.86) !important;
    color: #355277 !important;
    border: 1px solid rgba(200,215,238,.9) !important;
}

.btn-outline-primary:hover {
    background: linear-gradient(135deg, var(--vz-purple), #5f47ff) !important;
    color: #fff !important;
}

/* Separador */

hr {
    border: 0;
    border-top: 1px solid rgba(205,218,238,.75);
    margin: 22px 0;
}

/* Formato requerido */

.code-sample {
    background:
        radial-gradient(circle at 12% 0%, rgba(95,160,255,.12), transparent 36%),
        #172848 !important;
    color: #eef5ff !important;
    border-radius: 20px !important;
    padding: 18px !important;
    font-size: 13px !important;
    line-height: 1.55;
    overflow-x: auto;
    border: 1px solid rgba(215,228,246,.14);
    box-shadow:
        0 18px 34px rgba(30,55,95,.16),
        inset 0 1px 0 rgba(255,255,255,.08);
}

.alert {
    border: 0 !important;
    border-radius: 18px !important;
    box-shadow: var(--vz-soft-shadow) !important;
    font-size: 13px;
    font-weight: 700;
}

.alert-info {
    background: rgba(229,242,255,.96) !important;
    color: #245b95 !important;
}

/* Resultado KPI */

#resultadoCarga .summary-card {
    border-radius: 24px !important;
    background: rgba(255,255,255,.86) !important;
    border: 1px solid rgba(220,230,245,.95) !important;
    box-shadow:
        0 14px 30px rgba(70,95,140,.08),
        inset 0 1px 0 rgba(255,255,255,.88);
    padding: 20px !important;
    min-height: 112px;
}

.summary-number {
    display: block;
    color: var(--vz-text) !important;
    font-size: 28px !important;
    line-height: 1;
    font-weight: 950 !important;
}

.summary-label {
    display: block;
    margin-top: 8px;
    color: #7285a4 !important;
    font-size: 12px !important;
    font-weight: 850 !important;
    text-transform: uppercase;
    letter-spacing: .45px;
}

/* Tabla resultado */

.table-responsive {
    border: 0 !important;
    border-radius: 24px;
    overflow-x: auto;
    box-shadow: none !important;
}

.table {
    border-collapse: separate !important;
    border-spacing: 0 12px !important;
    margin: 0 !important;
}

.table thead th {
    background: rgba(244,248,255,.96) !important;
    color: #172848 !important;
    border: 0 !important;
    font-size: 12px !important;
    font-weight: 950 !important;
    text-transform: uppercase;
    letter-spacing: .45px;
    padding: 16px !important;
    vertical-align: middle !important;
    white-space: nowrap;
}

.table thead th:first-child {
    border-top-left-radius: 18px !important;
    border-bottom-left-radius: 18px !important;
}

.table thead th:last-child {
    border-top-right-radius: 18px !important;
    border-bottom-right-radius: 18px !important;
}

.table tbody tr {
    background: rgba(255,255,255,.86) !important;
    box-shadow:
        0 10px 24px rgba(70,95,140,.05),
        inset 0 1px 0 rgba(255,255,255,.85) !important;
}

.table tbody td {
    background: transparent !important;
    color: #223a5d !important;
    font-size: 13px !important;
    font-weight: 700;
    padding: 16px !important;
    border-top: 1px solid rgba(228,236,247,.92) !important;
    border-bottom: 1px solid rgba(228,236,247,.92) !important;
    border-left: 0 !important;
    border-right: 0 !important;
    vertical-align: middle !important;
}

.table tbody td:first-child {
    border-left: 1px solid rgba(228,236,247,.92) !important;
    border-top-left-radius: 18px !important;
    border-bottom-left-radius: 18px !important;
}

.table tbody td:last-child {
    border-right: 1px solid rgba(228,236,247,.92) !important;
    border-top-right-radius: 18px !important;
    border-bottom-right-radius: 18px !important;
}

/* Badges */

.badge-soft {
    min-height: 32px;
    padding: 7px 14px !important;
    border-radius: 999px !important;
    font-size: 12px !important;
    font-weight: 900 !important;
    letter-spacing: .2px;
}

.badge-ok {
    background: #d7f2df !important;
    color: #0f8a4d !important;
}

.badge-error {
    background: #ffe1e1 !important;
    color: #d04a4a !important;
}

.badge-warning-soft {
    background: #fff0cc !important;
    color: #a86b00 !important;
}

/* Responsive */

@media (max-width: 992px) {
    .page-header {
        align-items: flex-start !important;
        flex-direction: column;
    }

    .page-header > div:first-child {
        padding-left: 0;
        padding-top: 82px;
    }

    .page-header > div:first-child::before {
        top: 0;
        transform: none;
    }

    .page-header .btn-light {
        width: 100%;
    }
}

@media (max-width: 768px) {
    .container-fluid.p-4 {
        padding: 18px 14px !important;
    }

    .page-header {
        border-radius: 24px !important;
        padding: 22px !important;
    }

    .page-header h3 {
        font-size: 23px;
    }

    .card-modern {
        border-radius: 24px !important;
    }

    .card-modern .card-body {
        padding: 18px !important;
    }

    .upload-box {
        padding: 26px 16px !important;
    }
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

                    <a href="plantilla_carga_vehiculos.csv"
                       download="plantilla_carga_vehiculos.csv"
                       class="btn btn-outline-primary btn-main w-100">
                        <i class="fa-solid fa-download me-1"></i> Descargar plantilla CSV
                    </a>

                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card card-modern">
                <div class="card-body">

                    <h5 class="fw-bold mb-3">
                        <i class="fa-solid fa-list-check me-2"></i> Formato requerido
                    </h5>

                    <div class="alert alert-info mt-3 mb-0">
                        <strong>Importante:</strong> El campo <b>merchan</b> solo es obligatorio cuando el vehículo viene con estado <b>1 / Activo</b>.
                        Para vehículos inactivos usa estado <b>0</b> y deja el campo merchan vacío.
                        El combustible debe ser <b>93</b>, <b>95</b>, <b>97</b> o <b>DIESEL</b>.
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