<?php
session_start();
date_default_timezone_set('America/Santiago');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
$templateUrl = '/visibility2/portal/modulos/mod_etl/descargar_plantilla_etl_locales.php';

include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
$sessionEmpresaId = (int)($_SESSION['empresa_id'] ?? 0);
$divisionesEtl = [];
if ($sessionEmpresaId > 0) {
    $stmtDivisiones = $conn->prepare("SELECT id, nombre FROM division_empresa WHERE id_empresa = ? AND estado = 1 ORDER BY nombre ASC");
    if ($stmtDivisiones) {
        $stmtDivisiones->bind_param('i', $sessionEmpresaId);
        $stmtDivisiones->execute();
        $divisionesEtl = $stmtDivisiones->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtDivisiones->close();
    }
}
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
        --etl-bg: #f5f7fc;
        --etl-card: #ffffff;
        --etl-text: #16213e;
        --etl-muted: #6d7890;
        --etl-line: #e7ecf5;

        --etl-primary-1: #1f57e7;
        --etl-primary-2: #2e7df1;
        --etl-primary-3: #eaf2ff;

        --etl-dark-1: #1c2f59;
        --etl-dark-2: #16284a;

        --etl-success-1: #20c172;
        --etl-success-2: #18ad66;

        --etl-radius-xl: 28px;
        --etl-radius-lg: 22px;
        --etl-radius-md: 16px;
        --etl-radius-sm: 12px;

        --etl-shadow-card: 0 18px 45px rgba(15, 23, 42, 0.08);
        --etl-shadow-soft: 0 10px 28px rgba(15, 23, 42, 0.05);
        --etl-font-size: 0.72rem;
    }

    body{
        background:
            radial-gradient(circle at top left, rgba(71, 121, 255, 0.08) 0, rgba(71, 121, 255, 0) 220px),
            radial-gradient(circle at top right, rgba(120, 154, 255, 0.10) 0, rgba(120, 154, 255, 0) 260px),
            linear-gradient(180deg, #f9fbff 0%, var(--etl-bg) 100%);
        font-family: 'Segoe UI', Tahoma, sans-serif;
        color: var(--etl-text);
        font-size: var(--etl-font-size);
    }

    .etl-page{
        position: relative;
        max-width: 1480px;
        margin: 0 auto;
        padding: 36px 20px 40px;
    }

    .etl-page::before{
        content: "";
        position: absolute;
        top: 12px;
        left: 10px;
        width: 90px;
        height: 90px;
        background-image: radial-gradient(#d8e4ff 1.5px, transparent 1.5px);
        background-size: 14px 14px;
        opacity: .85;
        pointer-events: none;
    }

    .etl-page::after{
        content: "";
        position: absolute;
        top: 0;
        right: 0;
        width: 250px;
        height: 180px;
        border-bottom-left-radius: 120px;
        background: radial-gradient(circle at top right, rgba(117, 146, 255, 0.10), rgba(117, 146, 255, 0.02) 60%, transparent 70%);
        pointer-events: none;
    }

    .etl-hero{
        text-align: center;
        margin-bottom: 28px;
        position: relative;
        z-index: 2;
    }

    .etl-hero-icon{
        width: 64px;
        height: 64px;
        border-radius: 50%;
        margin: 0 auto 10px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #1f57e7;
        background: linear-gradient(180deg, #f0f5ff 0%, #e2ecff 100%);
        box-shadow: inset 0 1px 0 rgba(255,255,255,.8);
        font-size: 1.9rem;
    }

    .etl-hero h2{
        margin: 0 0 8px;
        font-size: 1rem;
        line-height: 1.05;
        font-weight: 800;
        letter-spacing: -0.03em;
        color: #19315f;
    }

    .etl-hero p{
        max-width: 920px;
        margin: 0 auto;
        font-size: 0.95rem;
        color: #667089;
        font-weight: 500;
    }

    .etl-grid{
        display: grid;
        grid-template-columns: minmax(0, 1.6fr) minmax(320px, 1fr);
        gap: 34px;
        align-items: start;
        position: relative;
        z-index: 2;
    }

    .etl-card{
        background: var(--etl-card);
        border: 1px solid rgba(226, 232, 240, 0.9);
        border-radius: var(--etl-radius-xl);
        box-shadow: var(--etl-shadow-card);
        overflow: hidden;
    }

    .etl-card-header{
        padding: 10px 30px;
        display: flex;
        align-items: center;
        gap: 16px;
        color: #fff;
        font-size: 0.9rem;
        font-weight: 800;
        letter-spacing: -0.02em;
    }

    .etl-card-header.primary{
        background: linear-gradient(90deg, var(--etl-primary-1) 0%, var(--etl-primary-2) 100%);
    }

    .etl-card-header.dark{
        background: linear-gradient(90deg, var(--etl-dark-1) 0%, var(--etl-dark-2) 100%);
    }

    .etl-card-header .header-icon{
        width: 40px;
        height: 40px;
        border-radius: 18px;
        background: rgba(255,255,255,0.15);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.95rem;
        flex: 0 0 auto;
        box-shadow: inset 0 1px 1px rgba(255,255,255,.16);
    }

    .etl-card-body{
        padding: 30px;
    }

    .etl-section{
        margin-bottom: 28px;
    }

    .etl-section:last-child{
        margin-bottom: 0;
    }

    .etl-section-title{
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 14px;
        font-size: 0.85rem;
        font-weight: 800;
        color: #1f3564;
        letter-spacing: -0.02em;
    }

    .etl-section-title .icon{
        color: #2a68f0;
        font-size: 1.35rem;
        width: 28px;
        text-align: center;
    }

    .etl-upload-box{
        border: 2px solid #dfe7f4;
        border-radius: 18px;
        background: #fff;
        padding: 8px;
        display: flex;
        align-items: center;
        gap: 18px;
        transition: .22s ease;
        min-height: 50px;
    }

    .etl-upload-box:hover{
        border-color: #cdd9ee;
        box-shadow: var(--etl-shadow-soft);
    }

    .etl-upload-btn{
        border: 1px solid #d8e4fb;
        background: #fff;
        color: #1f57e7;
        font-weight: 800;
        border-radius: 14px;
        min-width: 260px;
        min-height: 30px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
        cursor: pointer;
        padding: 0 20px;
        margin: 0;
        transition: .18s ease;
        box-shadow: inset 0 1px 0 rgba(255,255,255,.85);
    }

    .etl-upload-btn:hover{
        background: #f8fbff;
        border-color: #c8d8fa;
    }

    .etl-upload-btn i{
        font-size: 1.25rem;
    }

    .etl-file-name{
        font-size: 0.82rem;
        color: #677189;
        font-weight: 500;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        flex: 1;
        min-width: 0;
        padding-right: 10px;
    }

    .etl-help{
        margin-top: 10px;
        color: #7a859c;
        font-size: 0.92rem;
    }

    .etl-chip-box{
        border: 1px solid #d7e4fb;
        background: linear-gradient(180deg, #f8fbff 0%, #f2f7ff 100%);
        border-radius: 18px;
        padding: 10px;
    }

    .etl-chip{
        display: inline-block;
        background: #dfeafb;
        color: #334d86;
        font-family: Consolas, monospace;
        border-radius: 10px;
        padding: 9px 12px;
        font-size: 0.8rem;
        line-height: 1.4;
        box-shadow: inset 0 1px 0 rgba(255,255,255,.6);
        word-break: break-word;
    }

    .etl-template-box{
        border: 1px solid #dfe7f4;
        border-radius: 18px;
        background: #fff;
        padding: 14px;
        display: flex;
        align-items: center;
        gap: 18px;
        min-height: 60px;
    }

    .etl-template-btn{
        display: inline-flex;
        align-items: center;
        gap: 10px;
        text-decoration: none !important;
        border: 1px solid #c9d9fb;
        border-radius: 14px;
        color: #1f57e7;
        font-weight: 800;
        background: #fff;
        padding: 7px 22px;
        white-space: nowrap;
        transition: .18s ease;
    }

    .etl-template-btn:hover{
        background: #f7fbff;
        border-color: #afc8fb;
        color: #164bd1;
    }

    .etl-template-text{
        color: #6f7890;
        font-size: 0.88rem;
        line-height: 1.45;
    }

    .etl-submit{
        margin-top: 22px;
    }

    .etl-submit .btn{
        width: 100%;
        border: 0;
        min-height: 44px;
        border-radius: 18px;
        font-size: 0.9rem;
        font-weight: 800;
        background: linear-gradient(90deg, var(--etl-success-1) 0%, var(--etl-success-2) 100%);
        box-shadow: 0 14px 28px rgba(32, 193, 114, 0.18);
    }

    .etl-submit .btn:hover{
        filter: brightness(.98);
    }

    .etl-note{
        margin-top: 14px;
        text-align: center;
        color: #7a859c;
        font-size: 0.82rem;
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 8px;
    }

    .etl-note i{
        color: #6a7691;
    }

    .etl-info-list{
        list-style: none;
        margin: 0;
        padding: 0;
    }

    .etl-info-list li{
        display: flex;
        align-items: flex-start;
        gap: 16px;
        padding: 18px 0;
        border-bottom: 1px solid #e9edf5;
        color: #50607f;
        font-size: 0.88rem;
        line-height: 1.4;
    }

    .etl-info-list li:last-child{
        border-bottom: 0;
        padding-bottom: 0;
    }

    .etl-info-list .bullet{
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: linear-gradient(180deg, #f1f5ff 0%, #e7eefc 100%);
        color: #1f57e7;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 auto;
        font-size: 0.95rem;
    }

    .etl-info-list strong{
        color: #243b6a;
        font-weight: 800;
    }

    .etl-result-wrap{
        margin-top: 30px;
    }

    .etl-status-card{
        margin-bottom: 22px;
    }

    .etl-status-header{
        background: linear-gradient(90deg, #2490f3 0%, #4dacef 100%);
    }

    .etl-progress{
        width: 100%;
        height: 26px;
        background: #e8eef9;
        border-radius: 999px;
        overflow: hidden;
    }

    .etl-progress-bar{
        height: 100%;
        min-width: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        font-weight: 800;
        background: linear-gradient(90deg, #1f57e7 0%, #35a0ff 100%);
        transition: width .25s ease;
        white-space: nowrap;
    }

    .etl-progress-text,
    .etl-job-text{
        margin-top: 14px;
        color: #667089;
        font-size: 1rem;
    }

    .etl-stats{
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 18px;
        margin-bottom: 20px;
    }

    .etl-stat{
        background: linear-gradient(180deg, #fff 0%, #f8fbff 100%);
        border: 1px solid #e7ecf5;
        border-radius: 20px;
        box-shadow: var(--etl-shadow-soft);
        text-align: center;
        padding: 24px 18px;
    }

    .etl-stat-number{
        font-size: 2rem;
        line-height: 1;
        font-weight: 800;
        margin-bottom: 8px;
        letter-spacing: -0.03em;
    }

    .etl-stat-label{
        color: #73809a;
        font-weight: 700;
        font-size: 1rem;
    }

    .etl-stat-updated{ color: #18ad66; }
    .etl-stat-failed{ color: #dc3545; }
    .etl-stat-total{ color: #1f57e7; }

    .etl-alert{
        border-radius: 18px;
        padding: 16px 18px;
        box-shadow: var(--etl-shadow-soft);
        font-size: 1rem;
        border: 0;
    }

    .etl-report-box .alert{
        border-radius: 18px;
    }

    .hidden-file-input{
        position: absolute;
        opacity: 0;
        pointer-events: none;
        width: 1px;
        height: 1px;
    }

    @media (max-width: 1400px){
        .etl-hero h2{
            font-size: 2.55rem;
        }

        .etl-hero p{
            font-size: 1.22rem;
        }

        .etl-card-header{
            font-size: 1.65rem;
        }

        .etl-section-title{
            font-size: 1.45rem;
        }

        .etl-info-list li{
            font-size: 1.08rem;
        }

        .etl-submit .btn{
            font-size: 1.45rem;
        }
    }

    @media (max-width: 991.98px){
        .etl-grid{
            grid-template-columns: 1fr;
        }

        .etl-hero h2{
            font-size: 2.15rem;
        }

        .etl-hero p{
            font-size: 1.08rem;
        }

        .etl-upload-box,
        .etl-template-box{
            flex-direction: column;
            align-items: stretch;
        }

        .etl-upload-btn{
            min-width: 100%;
        }

        .etl-file-name{
            white-space: normal;
            padding-right: 0;
        }

        .etl-stats{
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 575.98px){
        .etl-page{
            padding: 24px 14px 30px;
        }

        .etl-card-header{
            padding: 18px 20px;
            font-size: 1.3rem;
        }

        .etl-card-header .header-icon{
            width: 46px;
            height: 46px;
            font-size: 1.15rem;
        }

        .etl-card-body{
            padding: 20px;
        }

        .etl-hero h2{
            font-size: 1.1rem;
        }

        .etl-hero p{
            font-size: 0.98rem;
        }

        .etl-section-title{
            font-size: 1.2rem;
        }

        .etl-submit .btn{
            min-height: 62px;
            font-size: 1.22rem;
        }

        .etl-info-list li{
            font-size: 1rem;
        }

        .etl-info-list .bullet{
            width: 48px;
            height: 48px;
            font-size: 1.1rem;
        }
    }
.etl-title-image{
    margin: 0 0 8px;
    text-align: center;
}

.etl-title-image img{
    max-width: 100%;
    width: 420px; /* ajusta el tamaño si quieres */
    height: auto;
    display: inline-block;
}
</style>

<div class="etl-page">
    <div class="etl-hero">
        <h2 class="etl-title-image">
            <img src="/visibility2/portal/images/logo/etl_head.webp" alt="ETL Locales">
        </h2>
        <p>
            Valida direcciones antiguas con Google, actualiza su propuesta y coordenadas dentro de la división seleccionada.
        </p>
    </div>

    <div class="etl-grid">
        <!-- IZQUIERDA -->
        <div class="etl-card">
            <div class="etl-card-header primary">
                <span class="header-icon">
                    <i class="fa-solid fa-arrow-up-from-bracket"></i>
                </span>
                <span>Carga de archivo</span>
            </div>

            <div class="etl-card-body">
                <form id="formEtlLocales" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="etl-section">
                        <div class="etl-section-title">
                            <span class="icon"><i class="fa-solid fa-code-branch"></i></span>
                            <span>División y criterio de actualización</span>
                        </div>

                        <label for="divisionId" class="form-label fw-semibold">División</label>
                        <select id="divisionId" name="division_id" class="form-select mb-3" required>
                            <option value="">Selecciona una división</option>
                            <?php foreach ($divisionesEtl as $divisionEtl): ?>
                                <option value="<?= (int)$divisionEtl['id']; ?>">
                                    <?= htmlspecialchars($divisionEtl['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                    </div>

                    <div class="etl-section">
                        <div class="etl-section-title">
                            <span class="icon"><i class="fa-regular fa-file-lines"></i></span>
                            <span>Archivo CSV</span>
                        </div>

                        <div class="etl-upload-box">
                            <input
                                type="file"
                                id="csvFile"
                                name="csvFile"
                                class="hidden-file-input"
                                accept=".csv"
                                required
                            >

                            <label for="csvFile" class="etl-upload-btn">
                                <i class="fa-solid fa-arrow-up-from-bracket"></i>
                                <span>Seleccionar archivo</span>
                            </label>

                            <div class="etl-file-name" id="fileNameLabel">
                                Sin archivos seleccionados
                            </div>
                        </div>

                        <div class="etl-help">
                            Selecciona un archivo CSV con la información de locales.
                        </div>
                    </div>

                    <div class="etl-section">
                        <div class="etl-section-title">
                            <span class="icon"><i class="fa-regular fa-rectangle-list"></i></span>
                            <span>Encabezados esperados</span>
                        </div>

                        <div class="etl-chip-box">
                            <span class="etl-chip">
                                Código Local;Nombre;Dirección;Comuna;Cadena;Cuenta
                            </span>
                        </div>

                        <div class="etl-help">
                            Distrito, zona y región se obtienen automáticamente desde la comuna.
                        </div>
                    </div>

                    <div class="etl-section">
                        <div class="etl-section-title">
                            <span class="icon"><i class="fa-regular fa-file-arrow-down"></i></span>
                            <span>Plantilla de carga</span>
                        </div>

                        <div class="etl-template-box">
                            <a
                                href="<?= htmlspecialchars($templateUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                class="etl-template-btn"
                                target="_blank"
                                download
                            >
                                <i class="fa-solid fa-download"></i>
                                <span>Descargar template CSV</span>
                            </a>

                            <div class="etl-template-text">
                                Descarga este archivo como base para respetar la estructura requerida.
                            </div>
                        </div>
                    </div>

                    <div class="etl-submit">
                        <button type="submit" class="btn btn-success" id="btnProcesar">
                            <i class="fa-solid fa-magnifying-glass-location me-2"></i> Validar y descargar revisión
                        </button>
                    </div>

                    <div class="etl-note">
                        <i class="fa-regular fa-shield-check"></i>
                        <span>El procesamiento puede tardar algunos minutos según el tamaño del archivo.</span>
                    </div>
                </form>
            </div>
        </div>

        <!-- DERECHA -->
        <div class="etl-card">
            <div class="etl-card-header dark">
                <span class="header-icon">
                    <i class="fa-solid fa-circle-info"></i>
                </span>
                <span>Qué hace este módulo</span>
            </div>

            <div class="etl-card-body">
                <ul class="etl-info-list">
                    <li>
                        <span class="bullet"><i class="fa-solid fa-magnifying-glass"></i></span>
                        <span>Busca el local por <strong>código y división</strong>.</span>
                    </li>
                    <li>
                        <span class="bullet"><i class="fa-solid fa-pen"></i></span>
                        <span>Actualiza <strong>nombre, dirección y comuna</strong>.</span>
                    </li>
                    <li>
                        <span class="bullet"><i class="fa-solid fa-sliders"></i></span>
                        <span>Obtiene automáticamente <strong>distrito, zona y región</strong> desde la comuna.</span>
                    </li>
                    <li>
                        <span class="bullet"><i class="fa-solid fa-crosshairs"></i></span>
                        <span>Guarda la dirección propuesta por Google y sus nuevas <strong>latitud y longitud</strong>.</span>
                    </li>
                    <li>
                        <span class="bullet"><i class="fa-regular fa-circle-xmark"></i></span>
                        <span><strong>No inserta</strong> locales nuevos.</span>
                    </li>
                    <li>
                        <span class="bullet"><i class="fa-solid fa-layer-group"></i></span>
                        <span>Primero genera una <strong>revisión descargable sin modificar la base</strong>.</span>
                    </li>
                    <li>
                        <span class="bullet"><i class="fa-regular fa-file-lines"></i></span>
                        <span>Genera <strong>reporte CSV</strong> con los fallidos.</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <!-- RESULTADOS -->
    <div id="resultadoBox" class="etl-result-wrap" style="display:none;">
        <div class="etl-card etl-status-card">
            <div class="etl-card-header etl-status-header">
                <span class="header-icon">
                    <i class="fa-solid fa-bars-progress"></i>
                </span>
                <span>Estado del procesamiento</span>
            </div>

            <div class="etl-card-body">
                <div class="etl-progress">
                    <div
                        id="etlProgressBar"
                        class="etl-progress-bar"
                        role="progressbar"
                        style="width:0%;"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        aria-valuenow="0"
                    >0%</div>
                </div>

                <div id="etlProgressText" class="etl-progress-text">
                    Esperando inicio...
                </div>

                <div id="etlJobText" class="etl-job-text"></div>
            </div>
        </div>

        <div class="etl-stats">
            <div class="etl-stat">
                <div class="etl-stat-number etl-stat-updated" id="statUpdated">0</div>
                <div class="etl-stat-label" id="statUpdatedLabel">Aprobadas</div>
            </div>

            <div class="etl-stat">
                <div class="etl-stat-number etl-stat-failed" id="statFailed">0</div>
                <div class="etl-stat-label" id="statFailedLabel">En revisión</div>
            </div>

            <div class="etl-stat">
                <div class="etl-stat-number etl-stat-total" id="statTotal">0</div>
                <div class="etl-stat-label" id="statTotalLabel">Rechazadas</div>
            </div>
        </div>

        <div id="reportLinkBox" class="etl-report-box mb-3" style="display:none;"></div>
        <div id="previewActionsBox" class="etl-report-box mb-3" style="display:none;"></div>

        <div class="alert alert-light etl-alert" id="etlResumenBox">
            El detalle masivo ya no se carga en pantalla. Los fallidos se descargan desde el reporte CSV.
        </div>
    </div>
</div>

<div class="modal fade" id="modalAplicarEtl" tabindex="-1" aria-labelledby="modalAplicarEtlLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalAplicarEtlLabel">Aplicar cambios revisados</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <p>Selecciona qué direcciones deseas actualizar:</p>
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-success" id="btnAplicarAprobadas">Aplicar solo aprobadas</button>
                    <button type="button" class="btn btn-warning" id="btnAplicarRevision">Aplicar aprobadas y en revisión</button>
                </div>
                <p class="small text-muted mt-3 mb-0">Las rechazadas nunca se actualizarán y conservarán su motivo en el reporte.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            </div>
        </div>
    </div>
</div>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    const $form = $('#formEtlLocales');
    const $btn = $('#btnProcesar');
    const $fileInput = $('#csvFile');
    const $fileNameLabel = $('#fileNameLabel');
    const originalBtnHtml = $btn.html();

    let currentJobId = null;
    let isRunning = false;
    let previewAccepted = 0;
    let previewReview = 0;
    const MAX_RETRIES = 3;
    const LOTE_SIZE = 100;

    function escapeHtml(text) {
        return String(text ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function updateFileName() {
        const input = $fileInput[0];
        if (input && input.files && input.files.length > 0) {
            $fileNameLabel.text(input.files[0].name);
        } else {
            $fileNameLabel.text('Sin archivos seleccionados');
        }
    }

    function setLoading(running) {
        isRunning = running;
        $btn.prop('disabled', running);
        $fileInput.prop('disabled', running);
        $('#divisionId').prop('disabled', running);

        if (running) {
            $btn.html('<i class="fa-solid fa-spinner fa-spin me-2"></i> Procesando...');
        } else {
            $btn.html(originalBtnHtml);
        }
    }

    function setProgress(percent, text) {
        const value = Math.max(0, Math.min(100, Number(percent || 0)));

        $('#etlProgressBar')
            .css('width', value + '%')
            .attr('aria-valuenow', value)
            .text(value.toFixed(0) + '%');

        $('#etlProgressText').html(text || '');
    }

    function updatePreviewStats(accepted, review, rejected) {
        $('#statUpdatedLabel').text('Aprobadas');
        $('#statFailedLabel').text('En revisión');
        $('#statTotalLabel').text('Rechazadas');
        $('#statUpdated').text(Number(accepted || 0));
        $('#statFailed').text(Number(review || 0));
        $('#statTotal').text(Number(rejected || 0));
    }

    function updateApplyStats(applied, failed, processed) {
        $('#statUpdatedLabel').text('Actualizadas');
        $('#statFailedLabel').text('No actualizadas');
        $('#statTotalLabel').text('Procesadas');
        $('#statUpdated').text(Number(applied || 0));
        $('#statFailed').text(Number(failed || 0));
        $('#statTotal').text(Number(processed || 0));
    }

    function showResultBox() {
        $('#resultadoBox').show();
    }

    function showReport(url) {
        if (url) {
            $('#reportLinkBox')
                .show()
                .html(`
                    <div class="alert alert-warning etl-alert mb-0">
                        <i class="fa-solid fa-file-circle-exclamation me-2"></i>
                        Se generó un reporte de direcciones no actualizadas con sus motivos:
                        <a href="${escapeHtml(url)}" target="_blank" class="fw-bold ms-1">
                            Descargar reporte CSV
                        </a>
                    </div>
                `);
        } else {
            $('#reportLinkBox').hide().empty();
        }
    }

    function showPreviewActions(previewUrl) {
        if (!previewUrl) {
            $('#previewActionsBox').hide().empty();
            return;
        }

        const applyDisabled = (previewAccepted + previewReview) <= 0 ? ' disabled' : '';
        $('#previewActionsBox').show().html(`
            <div class="d-flex flex-wrap gap-2">
                <a href="${escapeHtml(previewUrl)}" target="_blank" class="btn btn-primary">
                    <i class="fa-solid fa-file-arrow-down me-2"></i>Descargar revisión
                </a>
                <button type="button" id="btnAplicarCambios" class="btn btn-success"${applyDisabled}>
                    <i class="fa-solid fa-database me-2"></i>Aplicar cambios
                </button>
            </div>
        `);
    }

    function showError(message) {
        $('#etlResumenBox')
            .removeClass('alert-light alert-info alert-success alert-warning')
            .addClass('alert-danger')
            .html('<strong>Error:</strong> ' + escapeHtml(message || 'Ocurrió un problema.'));
    }

    function showInfo(message, type) {
        const klass = type || 'info';

        $('#etlResumenBox')
            .removeClass('alert-light alert-info alert-success alert-danger alert-warning')
            .addClass('alert-' + klass)
            .html(message);
    }

    function createJob(formData) {
        return $.ajax({
            url: '/visibility2/portal/modulos/mod_etl/subir_etl_locales_job.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            cache: false
        });
    }

    function processPreviewBatch(jobId, retryCount) {
        retryCount = retryCount || 0;

        const fd = new FormData();
        fd.append('csrf_token', $('input[name="csrf_token"]').val());
        fd.append('job_id', jobId);
        fd.append('limit', LOTE_SIZE);

        $.ajax({
            url: '/visibility2/portal/modulos/mod_etl/procesar_etl_locales_lote.php',
            type: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            dataType: 'json',
            cache: false,
            timeout: 0,
            success: function (resp) {
                if (!resp || resp.success !== true) {
                    showError(resp && resp.message ? resp.message : 'No fue posible validar el lote.');
                    setLoading(false);
                    return;
                }

                showResultBox();

                $('#etlJobText').html(
                    'Job ID: <strong>' + escapeHtml(resp.job_id) + '</strong>'
                );

                setProgress(
                    resp.progress || 0,
                    `Validadas <strong>${resp.processed_rows || 0}</strong> de <strong>${resp.total_rows || 0}</strong>
                    | Aprobadas: <strong>${resp.accepted_rows || 0}</strong>
                    | Revisión: <strong>${resp.review_rows || 0}</strong>
                    | Rechazadas: <strong>${resp.rejected_rows || 0}</strong>`
                );

                previewAccepted = Number(resp.accepted_rows || 0);
                previewReview = Number(resp.review_rows || 0);
                updatePreviewStats(previewAccepted, previewReview, resp.rejected_rows || 0);

                if (resp.reportUrl) {
                    showReport(resp.reportUrl);
                }

                if (resp.done) {
                    setLoading(false);
                    showPreviewActions(resp.previewReportUrl);
                    showInfo(
                        `<strong>Revisión terminada. La base de datos todavía no fue modificada.</strong><br>
                        Descarga el resultado y, cuando estés conforme, utiliza <strong>Aplicar cambios</strong>.`,
                        'info'
                    );
                    return;
                }

                setTimeout(function () {
                    processPreviewBatch(jobId, 0);
                }, 250);
            },
            error: function (xhr) {
                if (retryCount < MAX_RETRIES) {
                    const currentProgress = Number($('#etlProgressBar').attr('aria-valuenow') || 0);

                    setProgress(
                        currentProgress,
                        `Se detectó un corte durante la validación. Reintentando ${retryCount + 1} de ${MAX_RETRIES}...`
                    );

                    setTimeout(function () {
                        processPreviewBatch(jobId, retryCount + 1);
                    }, 1200 * (retryCount + 1));

                    return;
                }

                let msg = 'Ocurrió un error al procesar el lote.';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    msg = xhr.responseJSON.message;
                } else if (xhr.responseText) {
                    msg = xhr.responseText;
                }

                showError(msg);
                setLoading(false);
            }
        });
    }

    function processApplyBatch(jobId, retryCount) {
        retryCount = retryCount || 0;
        const fd = new FormData();
        fd.append('csrf_token', $('input[name="csrf_token"]').val());
        fd.append('job_id', jobId);
        fd.append('limit', LOTE_SIZE);

        $.ajax({
            url: '/visibility2/portal/modulos/mod_etl/aplicar_etl_locales_lote.php',
            type: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            dataType: 'json',
            cache: false,
            timeout: 0,
            success: function (resp) {
                if (!resp || resp.success !== true) {
                    showError(resp && resp.message ? resp.message : 'No fue posible aplicar el lote.');
                    setLoading(false);
                    return;
                }

                setProgress(
                    resp.progress || 0,
                    `Aplicadas <strong>${resp.applied_rows || 0}</strong> |
                    No actualizadas: <strong>${resp.failed_rows || 0}</strong> |
                    Procesadas: <strong>${resp.processed_rows || 0}</strong> de <strong>${resp.total_rows || 0}</strong>`
                );
                updateApplyStats(resp.applied_rows || 0, resp.failed_rows || 0, resp.processed_rows || 0);

                if (resp.reportUrl) {
                    showReport(resp.reportUrl);
                }
                if (resp.done) {
                    setLoading(false);
                    $('#btnAplicarCambios').prop('disabled', true).text('Cambios aplicados');
                    showInfo(
                        `<strong>Aplicación finalizada.</strong><br>
                        Locales actualizados: ${escapeHtml(resp.applied_rows || 0)}<br>
                        No actualizados: ${escapeHtml(resp.failed_rows || 0)}`,
                        Number(resp.failed_rows || 0) > 0 ? 'warning' : 'success'
                    );
                    return;
                }

                setTimeout(function () {
                    processApplyBatch(jobId, 0);
                }, 250);
            },
            error: function (xhr) {
                if (retryCount < MAX_RETRIES) {
                    setTimeout(function () {
                        processApplyBatch(jobId, retryCount + 1);
                    }, 1200 * (retryCount + 1));
                    return;
                }
                const message = xhr.responseJSON && xhr.responseJSON.message
                    ? xhr.responseJSON.message
                    : 'Ocurrió un error al aplicar los cambios.';
                showError(message);
                setLoading(false);
            }
        });
    }

    function startApply(scope) {
        const modalElement = document.getElementById('modalAplicarEtl');
        bootstrap.Modal.getOrCreateInstance(modalElement).hide();
        setLoading(true);
        $('#previewActionsBox button').prop('disabled', true);
        setProgress(0, 'Preparando la aplicación de los resultados revisados...');

        $.ajax({
            url: '/visibility2/portal/modulos/mod_etl/iniciar_aplicacion_etl_locales.php',
            type: 'POST',
            dataType: 'json',
            data: {
                csrf_token: $('input[name="csrf_token"]').val(),
                job_id: currentJobId,
                apply_scope: scope
            }
        }).done(function (resp) {
            if (!resp || resp.success !== true) {
                showError(resp && resp.message ? resp.message : 'No fue posible autorizar la aplicación.');
                setLoading(false);
                $('#previewActionsBox button').prop('disabled', false);
                return;
            }
            updateApplyStats(0, 0, 0);
            showInfo('Aplicando exclusivamente los resultados guardados en la revisión...', 'info');
            processApplyBatch(currentJobId, 0);
        }).fail(function (xhr) {
            const message = xhr.responseJSON && xhr.responseJSON.message
                ? xhr.responseJSON.message
                : 'No fue posible iniciar la aplicación.';
            showError(message);
            setLoading(false);
            $('#previewActionsBox button').prop('disabled', false);
        });
    }

    function clearCurrentPreview() {
        if (isRunning) {
            return;
        }
        currentJobId = null;
        previewAccepted = 0;
        previewReview = 0;
        $('#previewActionsBox').hide().empty();
        $('#reportLinkBox').hide().empty();
    }

    $fileInput.on('change', function () {
        updateFileName();
        clearCurrentPreview();
    });
    $('#divisionId').on('change', clearCurrentPreview);

    $form.on('submit', function (e) {
        e.preventDefault();

        if (isRunning) {
            return;
        }

        const fileInput = $fileInput[0];
        if (!fileInput || !fileInput.files || !fileInput.files.length) {
            alert('Debes seleccionar un archivo CSV.');
            return;
        }

        if (!$('#divisionId').val()) {
            alert('Debes seleccionar una división.');
            return;
        }

        const formData = new FormData(this);

        setLoading(true);
        showResultBox();
        updatePreviewStats(0, 0, 0);
        setProgress(0, 'Subiendo archivo y preparando la revisión...');
        $('#etlJobText').empty();
        $('#reportLinkBox').hide().empty();
        $('#previewActionsBox').hide().empty();
        showInfo('Preparando archivo para validación. En esta etapa no se modificará la base.', 'info');

        createJob(formData)
            .done(function (resp) {
                if (!resp || resp.success !== true) {
                    showError(resp && resp.message ? resp.message : 'No fue posible crear el job.');
                    setLoading(false);
                    return;
                }

                currentJobId = resp.job_id;

                $('#etlJobText').html(
                    'Job ID: <strong>' + escapeHtml(currentJobId) + '</strong>'
                );

                setProgress(
                    0,
                    `Archivo cargado correctamente. Total de filas detectadas: <strong>${resp.total_rows || 0}</strong>`
                );

                showInfo(
                    `Job creado correctamente. Se iniciará la validación en lotes de <strong>${LOTE_SIZE}</strong> registros.`,
                    'info'
                );

                processPreviewBatch(currentJobId, 0);
            })
            .fail(function (xhr) {
                let msg = 'No fue posible crear el job.';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    msg = xhr.responseJSON.message;
                } else if (xhr.responseText) {
                    msg = xhr.responseText;
                }

                showError(msg);
                setLoading(false);
            });
    });

    $(document).on('click', '#btnAplicarCambios', function () {
        $('#btnAplicarAprobadas').prop('disabled', previewAccepted <= 0);
        $('#btnAplicarRevision').prop('disabled', (previewAccepted + previewReview) <= 0);
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalAplicarEtl')).show();
    });

    $('#btnAplicarAprobadas').on('click', function () {
        startApply('accepted_only');
    });

    $('#btnAplicarRevision').on('click', function () {
        startApply('accepted_review');
    });

    updateFileName();
})();
</script>

</body>
</html>
