<?php
session_start();
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
date_default_timezone_set('America/Santiago');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Vehículos - Visibility</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">    

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

        .table thead th {
            background: #111827;
            color: white;
            font-size: 13px;
            border: none;
        }

        .table tbody td {
            vertical-align: middle;
            font-size: 14px;
        }

        .badge-soft {
            padding: 7px 10px;
            border-radius: 999px;
            font-weight: 600;
            font-size: 12px;
        }

        .badge-activo {
            background: #dcfce7;
            color: #166534;
        }

        .badge-inactivo {
            background: #fee2e2;
            color: #991b1b;
        }

        .vehicle-plate {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #f9fafb;
            border: 1px solid #d1d5db;
            padding: 6px 12px;
            border-radius: 10px;
            font-weight: 800;
            letter-spacing: .7px;
        }

        .modal-content {
            border: none;
            border-radius: 22px;
        }

        .modal-header {
            background: #111827;
            color: white;
            border-radius: 22px 22px 0 0;
        }

        .form-label {
            font-weight: 600;
            font-size: 13px;
            color: #374151;
        }

        .form-control,
        .form-select {
            border-radius: 12px;
        }

        .download-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .68);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 22px;
        }

        .download-overlay.show {
            display: flex;
        }

        .download-card {
            width: min(420px, 94vw);
            background: #ffffff;
            border-radius: 22px;
            padding: 26px;
            box-shadow: 0 28px 70px rgba(0, 0, 0, .28);
            text-align: center;
        }

        .download-icon {
            width: 56px;
            height: 56px;
            border-radius: 18px;
            background: #dcfce7;
            color: #15803d;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 14px;
        }

        .download-progress {
            height: 10px;
            background: #e5e7eb;
            border-radius: 999px;
            overflow: hidden;
            margin-top: 18px;
        }

        .download-progress-bar {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, #16a34a, #22c55e);
            border-radius: 999px;
            transition: width .35s ease;
        }

        .download-progress-text {
            margin-top: 10px;
            color: #64748b;
            font-size: 13px;
            font-weight: 600;
        }

        .history-item {
            border-left: 4px solid #111827;
            background: #f9fafb;
            border-radius: 14px;
            padding: 14px;
            margin-bottom: 12px;
        }

        .history-date {
            font-size: 13px;
            color: #6b7280;
            font-weight: 600;
        }
        .select2-container--bootstrap-5 .select2-selection {
            border-radius: 12px;
            min-height: 38px;
            border-color: #dee2e6;
        }
        
        .select2-container--bootstrap-5 .select2-selection--single .select2-selection__rendered {
            padding-left: 0;
            font-size: 14px;
        }
        
        .select2-container--bootstrap-5 .select2-dropdown {
            border-radius: 12px;
            overflow: hidden;
        }
        
        .select2-container {
            width: 100% !important;
        }
        
        .select2-dropdown {
            z-index: 9999;
        }
.report-answer-box {
    max-height: 180px;
    overflow-y: auto;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    padding: 10px;
    font-size: 13px;
}

.report-answer-item {
    padding: 7px 0;
    border-bottom: 1px dashed #d1d5db;
}

.report-answer-item:last-child {
    border-bottom: none;
}

.report-question {
    font-weight: 700;
    color: #111827;
    margin-bottom: 2px;
}

.report-response {
    color: #374151;
}

.report-date {
    font-size: 12px;
    color: #6b7280;
    font-weight: 600;
}

.report-table thead th {
    white-space: nowrap;
    font-size: 12px;
    vertical-align: middle;
}

.report-table tbody td {
    vertical-align: top;
    font-size: 13px;
    min-width: 150px;
}

.report-cell-text {
    max-width: 260px;
    max-height: 120px;
    overflow-y: auto;
    white-space: pre-wrap;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 8px;
    color: #374151;
}

.report-photo-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    max-width: 230px;
}

.report-photo-thumb {
    width: 58px;
    height: 58px;
    object-fit: cover;
    border-radius: 10px;
    border: 1px solid #d1d5db;
    background: #f3f4f6;
    cursor: pointer;
    transition: .15s ease;
}

.report-photo-thumb:hover {
    transform: scale(1.05);
}

.report-photo-more {
    width: 58px;
    height: 58px;
    border-radius: 10px;
    background: #111827;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 700;
}

#modalReporte .modal-reporte-dialog {
    width: calc(100vw - 28px);
    max-width: calc(100vw - 28px);
    margin: 14px auto;
}

#modalReporte .modal-content {
    height: calc(100vh - 28px);
}

#modalReporte .modal-body {
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.report-table-scroll {
    flex: 1;
    overflow: auto;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    background: #fff;
}

.report-table {
    min-width: 1500px;
    margin-bottom: 0;
}

.report-table thead th {
    position: sticky;
    top: 0;
    z-index: 5;
    white-space: nowrap;
    font-size: 12px;
    vertical-align: middle;
    background: #111827 !important;
    color: #fff;
}

.report-table tbody td {
    vertical-align: top;
    font-size: 13px;
    min-width: 150px;
}

.report-cell-text {
    max-width: 260px;
    max-height: 90px;
    overflow-y: auto;
    white-space: pre-wrap;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 8px;
    color: #374151;
}

.report-photo-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    max-width: 240px;
}

.report-photo-thumb {
    width: 62px;
    height: 62px;
    object-fit: cover;
    border-radius: 10px;
    border: 1px solid #d1d5db;
    background: #f3f4f6;
    cursor: pointer;
    transition: .15s ease;
}

.report-photo-thumb:hover {
    transform: scale(1.06);
    box-shadow: 0 6px 14px rgba(15, 23, 42, .18);
}

.report-photo-more {
    width: 62px;
    height: 62px;
    border-radius: 10px;
    background: #111827;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 700;
}

.report-photo-large {
    max-width: 100%;
    max-height: calc(100vh - 95px);
    object-fit: contain;
    border-radius: 14px;
}

@media (min-width: 1200px) {
    .modal-xl {
        --bs-modal-width: 95%!important;
    }
}

.visor-foto-reporte {
    position: fixed;
    inset: 10px;
    background: rgba(3, 7, 18, .96);
    z-index: 30000;
    display: none;
    flex-direction: column;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0, 0, 0, .45);
}

.visor-foto-reporte.show {
    display: flex;
}

.visor-foto-reporte-header {
    height: 58px;
    padding: 0 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    color: white;
    border-bottom: 1px solid rgba(255, 255, 255, .12);
}

.visor-foto-reporte-title {
    font-weight: 700;
    font-size: 15px;
}

.visor-foto-reporte-close {
    background: transparent;
    border: none;
    color: white;
    font-size: 34px;
    line-height: 1;
    cursor: pointer;
}

.visor-foto-reporte-body {
    flex: 1;
    padding: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: auto;
}

.visor-foto-reporte-body img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
    border-radius: 14px;
}

#modalReporte .modal-reporte-dialog {
    width: calc(100vw - 20px);
    max-width: calc(100vw - 20px);
    margin: 10px auto;
}

#modalReporte .modal-content {
    height: calc(100vh - 20px);
}

#modalReporte .modal-body {
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.report-table-scroll {
    flex: 1;
    overflow: auto;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    background: #fff;
}

.report-table {
    min-width: 1700px;
    margin-bottom: 0;
}

/* =========================================================
   FLEET MODERN UI - VISIBILITY
   Solo visual / no cambia backend
========================================================= */

:root {
    --vz-bg: #f5f8fc;
    --vz-bg-2: #eef3f9;
    --vz-card: rgba(255,255,255,.88);
    --vz-card-strong: rgba(255,255,255,.96);
    --vz-border: rgba(215,228,246,.95);
    --vz-text: #172848;
    --vz-muted: #7285a4;
    --vz-blue: #4d7eff;
    --vz-blue-2: #6a73ff;
    --vz-green: #1fb57c;
    --vz-red: #ef6f6c;
    --vz-purple: #7d5fff;
    --vz-cyan: #2fb8d8;
    --vz-shadow: 0 24px 55px rgba(70,95,140,.12);
    --vz-soft-shadow: 0 12px 28px rgba(70,95,140,.08);
}

/* Fondo general */

body {
    min-height: 100vh;
    background:
        radial-gradient(circle at 12% 8%, rgba(95,160,255,.12), transparent 34%),
        linear-gradient(180deg, var(--vz-bg) 0%, var(--vz-bg-2) 100%) !important;
    color: var(--vz-text);
    font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
}

.container-fluid.p-4 {
    max-width: 1540px;
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
    content: "\f5e4";
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

.page-header .d-flex.gap-2 {
    position: relative;
    z-index: 2;
    flex-wrap: wrap;
}

/* Botones principales */

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

.page-header .btn-light {
    background: linear-gradient(135deg, var(--vz-blue), var(--vz-blue-2)) !important;
    color: #fff !important;
}

.page-header .btn-outline-light {
    background: rgba(255,255,255,.82) !important;
    color: #355277 !important;
    border: 1px solid rgba(200,215,238,.9) !important;
}

.btn-main:hover {
    transform: translateY(-2px);
    box-shadow:
        0 18px 34px rgba(77,126,255,.22),
        inset 0 1px 0 rgba(255,255,255,.35) !important;
}

/* KPI */

.fleet-kpi-grid {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 18px;
}

.fleet-kpi-card {
    border-radius: 24px;
    background: rgba(255,255,255,.86);
    border: 1px solid rgba(220,230,245,.95);
    box-shadow:
        0 14px 30px rgba(70,95,140,.08),
        inset 0 1px 0 rgba(255,255,255,.88);
    padding: 20px;
    display: flex;
    gap: 16px;
    align-items: center;
    min-height: 116px;
}

.fleet-kpi-icon {
    width: 66px;
    height: 66px;
    min-width: 66px;
    border-radius: 22px;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    box-shadow: 0 16px 34px rgba(70,95,140,.18);
}

.fleet-kpi-icon.blue { background: linear-gradient(135deg, #4d7eff, #6c78ff); }
.fleet-kpi-icon.green { background: linear-gradient(135deg, #60d5ae, #1fb57c); }
.fleet-kpi-icon.red { background: linear-gradient(135deg, #ef6f6c, #dc3545); }
.fleet-kpi-icon.purple { background: linear-gradient(135deg, #7d5fff, #5f47ff); }

.fleet-kpi-label {
    display: block;
    font-size: 12px;
    font-weight: 850;
    color: #6e809e;
    text-transform: uppercase;
    letter-spacing: .6px;
    margin-bottom: 5px;
}

.fleet-kpi-card strong {
    display: block;
    margin: 0;
    font-size: 26px;
    line-height: 1;
    font-weight: 950;
    color: var(--vz-text);
}

.fleet-kpi-card small {
    display: block;
    margin-top: 7px;
    color: #7e90aa;
    font-size: 12px;
    font-weight: 700;
}

/* Card tabla */

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
    padding: 22px !important;
}

/* Buscador */

#buscarVehiculo,
#buscarReporteVehiculo {
    min-height: 46px;
    border-radius: 16px !important;
    border: 1px solid rgba(185,202,230,.78) !important;
    background: rgba(255,255,255,.90) !important;
    color: #28446f !important;
    font-size: 13px !important;
    font-weight: 700;
    box-shadow:
        0 8px 18px rgba(70,95,140,.05),
        inset 0 1px 0 rgba(255,255,255,.8) !important;
}

#buscarVehiculo:focus,
#buscarReporteVehiculo:focus,
.form-control:focus,
.form-select:focus {
    border-color: #8eb7ff !important;
    box-shadow:
        0 0 0 4px rgba(90,142,255,.12),
        0 8px 18px rgba(76,108,163,.08) !important;
}

.btn-dark {
    background: linear-gradient(135deg, var(--vz-blue), var(--vz-blue-2)) !important;
    border: 0 !important;
    color: #fff !important;
}

/* Tabla principal */

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
    transition: transform .16s ease, box-shadow .16s ease;
}

.table tbody tr:hover {
    transform: translateY(-1px);
    box-shadow:
        0 14px 30px rgba(70,95,140,.10),
        inset 0 1px 0 rgba(255,255,255,.9) !important;
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

/* Patente */

.vehicle-plate {
    border: 1px solid rgba(200,215,238,.9) !important;
    border-radius: 15px !important;
    background: rgba(255,255,255,.88) !important;
    color: #1b3054 !important;
    padding: 9px 13px !important;
    font-size: 12px;
    font-weight: 950 !important;
    letter-spacing: .8px;
    box-shadow:
        0 10px 22px rgba(70,95,140,.07),
        inset 0 1px 0 rgba(255,255,255,.85);
}

.vehicle-plate i {
    color: var(--vz-blue);
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

.badge-activo {
    background: #d7f2df !important;
    color: #0f8a4d !important;
}

.badge-inactivo {
    background: #ffe1e1 !important;
    color: #d04a4a !important;
}

/* Botones tabla */

.table .btn-sm {
    width: 40px;
    height: 40px;
    border-radius: 14px !important;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0 !important;
    border: 0 !important;
    box-shadow:
        0 10px 20px rgba(70,95,140,.10),
        inset 0 1px 0 rgba(255,255,255,.35) !important;
    transition: transform .16s ease, box-shadow .16s ease;
}

.table .btn-sm:hover {
    transform: translateY(-2px);
    box-shadow:
        0 14px 26px rgba(70,95,140,.16),
        inset 0 1px 0 rgba(255,255,255,.40) !important;
}

.table .btn-outline-dark {
    background: linear-gradient(135deg, #f6b93b, #f59e0b) !important;
    color: #fff !important;
}

.table .btn-outline-primary {
    background: linear-gradient(135deg, var(--vz-blue), var(--vz-blue-2)) !important;
    color: #fff !important;
}

/* Modales */

.modal-content {
    border: 0 !important;
    border-radius: 28px !important;
    overflow: hidden;
    background:
        linear-gradient(180deg, rgba(255,255,255,.98), rgba(245,249,255,.96)) !important;
    box-shadow:
        0 34px 95px rgba(20,45,90,.30),
        inset 0 1px 0 rgba(255,255,255,.82) !important;
}

.modal-header {
    min-height: 66px;
    background:
        radial-gradient(circle at 12% 0%, rgba(95,160,255,.15), transparent 36%),
        rgba(248,251,255,.96) !important;
    border-bottom: 1px solid rgba(205,218,238,.78) !important;
    color: var(--vz-text) !important;
    align-items: center;
}

.modal-title {
    font-size: 18px;
    font-weight: 950;
    color: var(--vz-text);
}

.modal-header .btn-close {
    background-color: rgba(240,246,255,.95) !important;
    border-radius: 50%;
    opacity: 1;
    padding: 12px;
    box-shadow:
        0 10px 22px rgba(70,95,140,.08),
        inset 0 1px 0 rgba(255,255,255,.9);
}

.modal-body {
    background:
        radial-gradient(circle at 10% 0%, rgba(95,160,255,.08), transparent 36%),
        #fff !important;
    padding: 24px !important;
}

.modal-footer {
    background: rgba(248,251,255,.96);
    border-top: 1px solid rgba(205,218,238,.75) !important;
    padding: 16px 22px !important;
}

/* Formularios */

.form-label {
    color: #294469 !important;
    font-size: 13px !important;
    font-weight: 850 !important;
}

.form-control,
.form-select,
.select2-container--bootstrap-5 .select2-selection {
    min-height: 44px !important;
    border-radius: 15px !important;
    border: 1px solid rgba(185,202,230,.78) !important;
    background: rgba(255,255,255,.90) !important;
    color: #28446f !important;
    font-size: 13px !important;
    font-weight: 650;
    box-shadow:
        0 8px 18px rgba(70,95,140,.05),
        inset 0 1px 0 rgba(255,255,255,.8) !important;
}

.select2-container--bootstrap-5 .select2-dropdown {
    border-radius: 16px !important;
    overflow: hidden;
    border: 1px solid rgba(185,202,230,.78) !important;
    box-shadow: 0 18px 38px rgba(30,60,110,.18);
}

.select2-container--bootstrap-5 .select2-results__option--highlighted {
    background: var(--vz-blue) !important;
}

/* Alertas */

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

/* Historial */

.history-item {
    border-left: 0 !important;
    border-radius: 20px !important;
    padding: 16px !important;
    margin-bottom: 14px !important;
    background:
        radial-gradient(circle at 12% 0%, rgba(95,160,255,.08), transparent 36%),
        rgba(255,255,255,.90) !important;
    border: 1px solid rgba(215,228,246,.95) !important;
    box-shadow:
        0 12px 28px rgba(70,95,140,.08),
        inset 0 1px 0 rgba(255,255,255,.88) !important;
}

.history-date {
    color: #7285a4 !important;
    font-weight: 800;
}

/* Reporte */

#modalReporte .modal-xl {
    --bs-modal-width: 95vw;
}

#modalReporte .modal-content {
    height: 95vh;
}

#modalReporte .modal-body {
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.report-table-scroll,
#modalReporte .table-responsive {
    flex: 1;
    overflow: auto;
    border: 1px solid rgba(215,228,246,.95) !important;
    border-radius: 20px !important;
    background: rgba(255,255,255,.92) !important;
}

.report-table {
    min-width: 1700px;
}

.report-table thead th {
    position: sticky;
    top: 0;
    z-index: 5;
}

.report-cell-text {
    background: rgba(244,248,255,.96) !important;
    border: 1px solid rgba(215,228,246,.95) !important;
    border-radius: 14px !important;
    color: #28446f !important;
    font-size: 12px;
    font-weight: 650;
}

.report-photo-thumb {
    border-radius: 14px !important;
    border: 1px solid rgba(215,228,246,.95) !important;
    box-shadow: 0 10px 22px rgba(70,95,140,.08);
}

.report-photo-more {
    border-radius: 14px !important;
    background: linear-gradient(135deg, var(--vz-blue), var(--vz-blue-2)) !important;
}

/* Visor foto */

.visor-foto-reporte {
    inset: 16px !important;
    border-radius: 28px !important;
    background: rgba(7,18,38,.92) !important;
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    box-shadow: 0 34px 95px rgba(0,0,0,.42) !important;
}

.visor-foto-reporte-header {
    height: 64px !important;
    padding: 0 22px !important;
    background: rgba(15,26,48,.92);
}

.visor-foto-reporte-title {
    font-weight: 900 !important;
}

.visor-foto-reporte-close {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: rgba(255,255,255,.10) !important;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* Responsive */

@media (max-width: 1200px) {
    .fleet-kpi-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .page-header {
        align-items: flex-start !important;
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

    .page-header > div:first-child {
        padding-left: 0;
        padding-top: 82px;
    }

    .page-header > div:first-child::before {
        top: 0;
        transform: none;
    }

    .page-header h3 {
        font-size: 23px;
    }

    .page-header .d-flex.gap-2 {
        width: 100%;
        flex-direction: column;
    }

    .page-header .btn-main {
        width: 100%;
    }

    .fleet-kpi-grid {
        grid-template-columns: 1fr;
    }

    .card-modern {
        border-radius: 24px !important;
    }

    .card-modern .card-body {
        padding: 16px !important;
    }
}

/* =========================================================
   MODAL MODERNO PARA FOTOS DEL REPORTE
========================================================= */

.fleet-photo-modal {
    position: fixed;
    inset: 0;
    z-index: 40000;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 18px;
}

.fleet-photo-modal.show {
    display: flex;
}

.fleet-photo-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(7, 18, 38, .52);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
}

.fleet-photo-content {
    position: relative;
    z-index: 2;
    width: min(96vw, 1500px);
    height: 94vh;
    border-radius: 28px;
    overflow: hidden;
    background:
        radial-gradient(circle at 12% 0%, rgba(95,160,255,.12), transparent 36%),
        linear-gradient(180deg, rgba(255,255,255,.98), rgba(245,249,255,.96));
    border: 1px solid rgba(215,228,246,.95);
    box-shadow:
        0 34px 95px rgba(20,45,90,.34),
        inset 0 1px 0 rgba(255,255,255,.86);
    display: flex;
    flex-direction: column;
}

.fleet-photo-header {
    height: 68px;
    min-height: 68px;
    padding: 12px 18px 12px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    background:
        radial-gradient(circle at 12% 0%, rgba(95,160,255,.14), transparent 36%),
        rgba(248,251,255,.96);
    border-bottom: 1px solid rgba(205,218,238,.78);
}

.fleet-photo-title-wrap {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}

.fleet-photo-icon {
    width: 44px;
    height: 44px;
    min-width: 44px;
    border-radius: 15px;
    background: linear-gradient(145deg, #f7fbff, #dcecff);
    color: #4d7eff;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow:
        0 10px 22px rgba(70,110,180,.12),
        inset 0 1px 0 rgba(255,255,255,.88);
}

.fleet-photo-title-wrap h5 {
    margin: 0;
    max-width: 62vw;
    font-size: 15px;
    font-weight: 950;
    color: #172848;
    line-height: 1.15;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.fleet-photo-title-wrap p {
    margin: 4px 0 0;
    font-size: 11px;
    font-weight: 700;
    color: #7285a4;
}

.fleet-photo-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}

.fleet-photo-action-btn,
.fleet-photo-close {
    width: 42px;
    height: 42px;
    border: 0;
    border-radius: 50%;
    background: rgba(240,246,255,.95);
    color: #536782;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    font-size: 17px;
    cursor: pointer;
    box-shadow:
        0 10px 22px rgba(70,95,140,.08),
        inset 0 1px 0 rgba(255,255,255,.9);
    transition: all .18s ease;
}

.fleet-photo-close {
    font-size: 30px;
    line-height: 1;
}

.fleet-photo-action-btn:hover,
.fleet-photo-close:hover {
    background: #e8f1ff;
    color: #1d4f91;
    transform: scale(1.05);
}

.fleet-photo-body {
    flex: 1;
    padding: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: auto;
    background:
        radial-gradient(circle at 50% 0%, rgba(77,126,255,.08), transparent 34%),
        #f8fbff;
}

.fleet-photo-body img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
    border-radius: 20px;
    box-shadow: 0 22px 55px rgba(30,60,110,.22);
    background: #fff;
}

body.fleet-photo-open {
    overflow: hidden;
}

/* Miniaturas clickeables dentro del reporte */
.report-photo-thumb.js-report-photo {
    cursor: zoom-in;
}

.report-photo-thumb.js-report-photo:hover {
    transform: scale(1.08);
    box-shadow: 0 14px 30px rgba(70,95,140,.20);
}

@media (max-width: 768px) {
    .fleet-photo-modal {
        padding: 10px;
    }

    .fleet-photo-content {
        width: 98vw;
        height: 96vh;
        border-radius: 22px;
    }

    .fleet-photo-header {
        height: auto;
        min-height: 64px;
    }

    .fleet-photo-title-wrap h5 {
        max-width: 48vw;
    }

    .fleet-photo-body {
        padding: 10px;
    }
}

/* =========================================================
   DOCUMENTOS & MANTENCIONES
========================================================= */
.doc-badge-rojo   { background: #fee2e2 !important; color: #991b1b !important; }
.doc-badge-naranja { background: #fff7e6 !important; color: #92400e !important; }
.doc-badge-amarillo { background: #fffbeb !important; color: #b45309 !important; }
.doc-badge-verde  { background: #d7f2df !important; color: #0f8a4d !important; }

.tipo-doc-label {
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .4px;
}

.doc-alerta-item {
    border-radius: 14px;
    padding: 10px 14px;
    cursor: pointer;
    min-width: 140px;
    font-size: 13px;
    font-weight: 700;
    transition: transform .15s ease, box-shadow .15s ease;
}
.doc-alerta-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(70,95,140,.14);
}

.panel-alertas-card {
    border-radius: 28px;
    border: 1px solid rgba(215,228,246,.95);
    background: linear-gradient(180deg, rgba(255,255,255,.96), rgba(255,250,235,.88));
    box-shadow: 0 14px 34px rgba(70,95,140,.08), inset 0 1px 0 rgba(255,255,255,.9);
    padding: 20px 22px;
    margin-bottom: 22px;
}

/* =========================================================
   INFORME ESTATUS DEL VEHÍCULO (vista en pantalla)
========================================================= */
#modalEstatus .modal-xl { --bs-modal-width: 96vw; }

.ev-meta {
    font-size: 13px;
    color: #28446f;
    background: rgba(244,248,255,.96);
    border: 1px solid rgba(215,228,246,.95);
    border-radius: 12px;
    padding: 8px 14px;
}

.ev-kpi-row {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: 12px;
}
.ev-kpi {
    background: rgba(255,255,255,.9);
    border: 1px solid rgba(220,230,245,.95);
    border-radius: 16px;
    padding: 12px 14px;
    box-shadow: 0 8px 20px rgba(70,95,140,.06);
}
.ev-kpi span {
    display: block;
    font-size: 11px;
    font-weight: 850;
    color: #6e809e;
    text-transform: uppercase;
    letter-spacing: .5px;
    margin-bottom: 4px;
}
.ev-kpi strong { font-size: 22px; font-weight: 950; color: var(--vz-text); }

.ev-tab-content {
    flex: 1;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    border: 1px solid rgba(215,228,246,.95);
    border-top: 0;
    border-radius: 0 0 16px 16px;
    background: #fff;
}
#modalEstatus .tab-pane.active { display: flex; flex-direction: column; flex: 1; overflow: hidden; }
.ev-scroll { flex: 1; overflow: auto; }

.ev-tabla { margin: 0 !important; border-spacing: 0 !important; }
.ev-tabla thead th {
    position: sticky;
    top: 0;
    z-index: 5;
    background: #f4f8ff !important;
    font-size: 11px !important;
    white-space: nowrap;
    padding: 10px !important;
}
.ev-tabla tbody td {
    font-size: 12.5px !important;
    padding: 9px 10px !important;
    border-radius: 0 !important;
    white-space: nowrap;
}
.ev-tabla tbody tr { box-shadow: none !important; }

/* Semáforo de cumplimiento */
.ev-pct {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 999px;
    font-weight: 900;
    font-size: 12px;
}
.ev-ok    { background: #d7f2df; color: #0f8a4d; }
.ev-medio { background: #fff3cd; color: #8a6100; }
.ev-malo  { background: #ffe1e1; color: #c0392b; }
strong.ev-ok, strong.ev-medio, strong.ev-malo { background: none; padding: 0; }

/* Matriz día a día */
.ev-matriz .ev-col-dia { min-width: 62px; text-align: center; font-size: 10px !important; }
.ev-cel { text-align: center; font-weight: 900; }
.ev-cel-ok { background: #d7f2df !important; color: #0f8a4d; }
.ev-cel-no { background: #ffe1e1 !important; color: #c0392b; }

/* Subida en día no esperado */
.ev-fila-noesperada td { background: #fffbeb !important; }

/* ── Filtros por columna (estilo Excel) ── */
.ev-tabla thead th { position: sticky; top: 0; }
.ev-th-wrap {
    display: flex;
    align-items: center;
    gap: 6px;
    justify-content: space-between;
}
.ev-fbtn {
    cursor: pointer;
    color: #8aa0c0;
    font-size: 11px;
    padding: 2px 4px;
    border-radius: 5px;
    flex: 0 0 auto;
}
.ev-fbtn:hover { background: #dfeaff; color: #2c5fc7; }
.ev-fbtn.activo { color: #fff; background: var(--vz-blue); }

.ev-fpanel {
    position: fixed;
    z-index: 40050;
    width: 260px;
    max-height: 340px;
    display: flex;
    flex-direction: column;
    background: #fff;
    border: 1px solid rgba(185,202,230,.9);
    border-radius: 14px;
    box-shadow: 0 18px 42px rgba(20,45,90,.22);
    overflow: hidden;
}
.ev-fpanel-head { padding: 10px; border-bottom: 1px solid #eef2f9; }
.ev-fpanel-head input {
    width: 100%;
    font-size: 12px;
    padding: 6px 9px;
    border: 1px solid rgba(185,202,230,.9);
    border-radius: 9px;
}
.ev-fpanel-list { flex: 1; overflow-y: auto; padding: 6px 4px; }
.ev-fitem {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 5px 8px;
    font-size: 12px;
    border-radius: 7px;
    cursor: pointer;
    color: #28446f;
}
.ev-fitem:hover { background: #f2f7ff; }
.ev-fitem input { cursor: pointer; margin: 0; }
.ev-fitem span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.ev-fitem.ev-ftodos { font-weight: 800; border-bottom: 1px solid #eef2f9; border-radius: 0; }
.ev-fpanel-foot {
    display: flex;
    gap: 6px;
    padding: 9px 10px;
    border-top: 1px solid #eef2f9;
    background: #f8fbff;
}
.ev-fpanel-foot button {
    flex: 1;
    font-size: 12px;
    font-weight: 800;
    padding: 6px;
    border-radius: 9px;
    border: 0;
    cursor: pointer;
}
.ev-fbtn-ok  { background: var(--vz-blue); color: #fff; }
.ev-fbtn-off { background: #e9eef7; color: #4a5f80; }

.ev-sin-filas td {
    text-align: center;
    color: #8194b1;
    padding: 22px !important;
}

@media (max-width: 1200px) {
    .ev-kpi-row { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
@media (max-width: 768px) {
    .ev-kpi-row { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
    </style>
</head>

<body>

<div class="container-fluid p-4">

    <div class="page-header d-flex justify-content-between align-items-center">
        <div>
            <h3><i class="fa-solid fa-car-side me-2"></i> Gestión de Vehículos</h3>
            <p>Registro, asignación actual e historial de movimientos por vehículo.</p>
        </div>

        <div class="d-flex gap-2">
            <button class="btn btn-outline-light btn-main" onclick="abrirModalReporte()">
                <i class="fa-solid fa-chart-table me-1"></i> Reporte
            </button>

            <button class="btn btn-outline-light btn-main" onclick="abrirModalEstatus()">
                <i class="fa-solid fa-gauge-high me-1"></i> Estatus vehículo
            </button>

            <button class="btn btn-outline-light btn-main" onclick="abrirModalDescargaExcel()">
                <i class="fa-solid fa-file-excel me-1"></i> Descargar Excel
            </button>

            <a href="mod_vehiculos_carga_masiva.php" class="btn btn-outline-light btn-main">
                <i class="fa-solid fa-file-arrow-up me-1"></i> Carga masiva
            </a>
        
            <button class="btn btn-light btn-main" onclick="abrirModalNuevo()">
                <i class="fa-solid fa-plus me-1"></i> Nuevo vehículo
            </button>
        </div>
            </div>

<!-- PANEL ALERTAS DOCUMENTOS -->
<div id="panelAlertas" class="panel-alertas-card" style="display:none">
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <strong style="font-size:15px;color:#92400e">
            <i class="fa-solid fa-triangle-exclamation me-2" style="color:#f59e0b"></i>
            Alertas de documentos
        </strong>
        <button class="btn btn-sm btn-outline-secondary" style="border-radius:10px;font-size:12px" onclick="togglePanelAlertas()">
            <i class="fa-solid fa-eye-slash me-1"></i> Ocultar
        </button>
    </div>
    <div id="contenedorAlertas"></div>
</div>

<div class="fleet-kpi-grid mb-4">

    <div class="fleet-kpi-card">
        <div class="fleet-kpi-icon blue">
            <i class="fa-solid fa-car-side"></i>
        </div>
        <div>
            <span class="fleet-kpi-label">Total vehículos</span>
            <strong id="kpiTotalVehiculos">0</strong>
            <small>Registrados en flota</small>
        </div>
    </div>

    <div class="fleet-kpi-card">
        <div class="fleet-kpi-icon green">
            <i class="fa-solid fa-circle-check"></i>
        </div>
        <div>
            <span class="fleet-kpi-label">Activos</span>
            <strong id="kpiVehiculosActivos">0</strong>
            <small>Disponibles actualmente</small>
        </div>
    </div>

    <div class="fleet-kpi-card">
        <div class="fleet-kpi-icon red">
            <i class="fa-solid fa-circle-xmark"></i>
        </div>
        <div>
            <span class="fleet-kpi-label">Inactivos</span>
            <strong id="kpiVehiculosInactivos">0</strong>
            <small>Fuera de operación</small>
        </div>
    </div>

    <div class="fleet-kpi-card">
        <div class="fleet-kpi-icon purple">
            <i class="fa-solid fa-user-check"></i>
        </div>
        <div>
            <span class="fleet-kpi-label">Asignados</span>
            <strong id="kpiVehiculosAsignados">0</strong>
            <small>Con merchan actual</small>
        </div>
    </div>

    <div class="fleet-kpi-card" style="cursor:pointer" onclick="togglePanelAlertas()">
        <div class="fleet-kpi-icon" style="background: linear-gradient(135deg, #f6b93b, #f59e0b)">
            <i class="fa-solid fa-triangle-exclamation"></i>
        </div>
        <div>
            <span class="fleet-kpi-label">Alertas docs</span>
            <strong id="kpiAlertasDocs">0</strong>
            <small>Vencidos o por vencer</small>
        </div>
    </div>

</div>

    <div class="card card-modern">
        <div class="card-body">

            <div class="row mb-3 g-2">
                <div class="col-md-4">
                    <input type="text" id="buscarVehiculo" class="form-control" placeholder="Buscar por patente, modelo, merchan, división...">
                </div>

                <div class="col-md-2">
                    <button class="btn btn-dark w-100 btn-main" onclick="cargarVehiculos()">
                        <i class="fa-solid fa-rotate me-1"></i> Actualizar
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                    <tr>
                        <th>Patente</th>
                        <th>Modelo</th>
                        <th>Combustible</th>
                        <th>Origen</th>
                        <th>Empresa</th>
                        <th>División</th>
                        <th>Subdivisión</th>
                        <th>Merchan actual</th>
                        <th>Desde</th>
                        <th>Estado</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                    </thead>
                    <tbody id="tbodyVehiculos">
                    <tr>
                        <td colspan="13" class="text-center text-muted py-4">Cargando vehículos...</td>
                    </tr>
                    </tbody>
                </table>
            </div>

        </div>
    </div>

</div>

<!-- MODAL CREAR / EDITAR -->
<div class="modal fade" id="modalVehiculo" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title" id="tituloModalVehiculo">Nuevo vehículo</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <form id="formVehiculo">
                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    <div class="row g-3">

                        <div class="col-md-4">
                            <label class="form-label">Patente</label>
                            <input type="text" name="patente" id="patente" class="form-control" required placeholder="AAAA-11">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Modelo vehículo</label>
                            <input type="text" name="modelo" id="modelo" class="form-control" placeholder="Toyota Hilux, Peugeot Partner, etc.">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Combustible / Octanaje</label>
                            <select name="tipo_combustible" id="tipo_combustible" class="form-select">
                                <option value="">Seleccione...</option>
                                <option value="93">BENCINA 93</option>
                                <option value="95">BENCINA 95</option>
                                <option value="97">BENCINA 97</option>
                                <option value="DIESEL">DIESEL</option>
                            </select>
                        </div>
                        
                        <div class="col-md-12">
                            <label class="form-label">Dirección de origen / punto de partida</label>
                            <input type="text" name="direccion_origen" id="direccion_origen" class="form-control" placeholder="Ej: Av. Providencia 1234, Santiago">
                        </div>
                        
                        <input type="hidden" name="lat_origen" id="lat_origen">
                        <input type="hidden" name="lng_origen" id="lng_origen">

                        <div class="col-md-6">
                            <label class="form-label">Empresa</label>
                            <select name="id_empresa" id="id_empresa" class="form-select" required onchange="filtrarDivisiones()">
                                <option value="">Seleccione...</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">División</label>
                            <select name="id_division" id="id_division" class="form-select" required onchange="filtrarSubdivisiones(); filtrarMerchans();">
                                <option value="">Seleccione...</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Subdivisión</label>
                            <select name="id_subdivision" id="id_subdivision" class="form-select" onchange="filtrarMerchans()">
                                <option value="">Seleccione...</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Merchan asignado</label>
                                <select name="id_merchan" id="id_merchan" class="form-select">
                                <option value="">Seleccione...</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Fecha inicio asignación</label>
                            <input type="date" name="fecha_inicio" id="fecha_inicio" class="form-control" required value="<?= date('Y-m-d') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Estado vehículo</label>
                            <select name="estado" id="estado" class="form-select">
                                <option value="1">Activo</option>
                                <option value="0">Inactivo</option>
                            </select>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label">Observación del movimiento</label>
                            <textarea name="observacion" id="observacion" class="form-control" rows="2" placeholder="Ej: asignación inicial, cambio por reemplazo, devolución, etc."></textarea>
                        </div>

                    </div>

                    <div class="alert alert-info mt-4 mb-0">
                        <i class="fa-solid fa-circle-info me-1"></i>
                        Si cambias el merchan, empresa, división o subdivisión, el sistema cerrará la asignación anterior y creará un nuevo registro histórico.
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-main" data-bs-dismiss="modal">
                        Cancelar
                    </button>

                    <button type="submit" class="btn btn-dark btn-main">
                        <i class="fa-solid fa-floppy-disk me-1"></i> Guardar
                    </button>
                </div>
            </form>

        </div>
    </div>
</div>

<!-- MODAL HISTORIAL -->
<div class="modal fade" id="modalHistorial" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fa-solid fa-clock-rotate-left me-2"></i> Historial del vehículo
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <div id="contenedorHistorial">
                    <p class="text-muted">Cargando historial...</p>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- MODAL REPORTE -->
<div class="modal fade" id="modalReporte" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fa-solid fa-chart-table me-2"></i> Reporte
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">

                <div class="row mb-3 g-2">
                    <div class="col-md-5">
                        <input 
                            type="text" 
                            id="buscarReporteVehiculo" 
                            class="form-control" 
                            placeholder="Buscar por patente, RUT, nombre o respuesta..."
                        >
                    </div>

                    <div class="col-md-2">
                        <button class="btn btn-dark w-100 btn-main" onclick="cargarReporteVehiculos()">
                            <i class="fa-solid fa-rotate me-1"></i> Actualizar
                        </button>
                    </div>
                    <div class="col-md-2">
                        <button type="button" class="btn btn-success w-100 btn-main" onclick="abrirModalDescargaReporteVehiculos()">
                            <i class="fa-solid fa-file-excel me-1"></i> Descargar
                        </button>
                    </div>
                </div>

                <div class="alert alert-info">
                    <i class="fa-solid fa-circle-info me-1"></i>
                    Este reporte muestra la respuesta más actualizada por trabajador para la campaña/formulario ID 138.
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle report-table" id="tablaReporteVehiculos">
                        <thead id="theadReporteVehiculos">
                            <tr>
                                <th>Placa patente</th>
                                <th>RUT usuario</th>
                                <th>Nombre completo</th>
                                <th>Última respuesta</th>
                            </tr>
                        </thead>
                
                        <tbody id="tbodyReporteVehiculos">
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">
                                    Presiona actualizar para cargar el reporte.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            </div>

        </div>
    </div>
</div>

<!-- MODAL DESCARGA REPORTE VEHICULOS -->
<div class="modal fade" id="modalDescargaReporteVehiculos" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:460px">
        <div class="modal-content">

            <form
                id="formDescargaReporteVehiculos"
                method="get"
                action="/visibility2/portal/modulos/mod_vehiculos/exportar_vehiculos_reporte.php"
                target="iframeDescargaReporteVehiculos"
            >
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fa-solid fa-calendar-days me-2"></i> Descargar reporte
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Fecha desde</label>
                        <input type="date" name="fecha_desde" id="reporteFechaDesde" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Fecha hasta</label>
                        <input type="date" name="fecha_hasta" id="reporteFechaHasta" class="form-control" required>
                    </div>

                    <div class="alert alert-info mb-0">
                        <i class="fa-solid fa-circle-info me-1"></i>
                        El Excel incluirá solo las subidas registradas dentro del rango seleccionado.
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-main" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success btn-main">
                        <i class="fa-solid fa-file-excel me-1"></i> Descargar
                    </button>
                </div>
            </form>

        </div>
    </div>
</div>

<iframe
    id="iframeDescargaReporteVehiculos"
    name="iframeDescargaReporteVehiculos"
    style="display:none;width:0;height:0;border:0;"
    title="Descarga reporte vehiculos"
></iframe>

<div id="overlayDescargaReporte" class="download-overlay" aria-hidden="true">
    <div class="download-card">
        <div class="download-icon">
            <i class="fa-solid fa-file-excel"></i>
        </div>
        <h5 class="mb-1">Preparando descarga</h5>
        <p class="text-muted mb-0">Generando el reporte con el rango seleccionado.</p>
        <div class="download-progress">
            <div class="download-progress-bar" id="barraDescargaReporte"></div>
        </div>
        <div class="download-progress-text" id="textoDescargaReporte">Iniciando...</div>
    </div>
</div>

<!-- MODAL VISOR FOTO REPORTE -->
<div id="modalFotoReporte" class="fleet-photo-modal" aria-hidden="true">
    <div class="fleet-photo-backdrop" onclick="cerrarFotoReporte()"></div>

    <div class="fleet-photo-content">
        <div class="fleet-photo-header">
            <div class="fleet-photo-title-wrap">
                <div class="fleet-photo-icon">
                    <i class="fa-solid fa-image"></i>
                </div>

                <div>
                    <h5 id="visorFotoReporteTitulo">Foto reporte</h5>
                    <p>Vista ampliada de imagen registrada</p>
                </div>
            </div>

            <div class="fleet-photo-actions">
                <a id="visorFotoReporteDownload"
                   href="#"
                   target="_blank"
                   class="fleet-photo-action-btn"
                   title="Abrir imagen en una nueva pestaña">
                    <i class="fa-solid fa-up-right-from-square"></i>
                </a>

                <button type="button"
                        class="fleet-photo-close"
                        onclick="cerrarFotoReporte()"
                        title="Cerrar">
                    &times;
                </button>
            </div>
        </div>

        <div class="fleet-photo-body">
            <img id="visorFotoReporteImg" src="" alt="Foto reporte">
        </div>
    </div>
</div>

<!-- MODAL DOCUMENTOS LEGALES -->
<div class="modal fade" id="modalDocumentos" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fa-solid fa-file-lines me-2"></i>
                    Documentos — <span id="docPatente"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">

                <!-- Lista -->
                <div id="docListSection">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="text-muted small">Documentos legales del vehículo (SOAP, revisión técnica, permiso, etc.)</span>
                        <button class="btn btn-dark btn-main" onclick="mostrarFormDoc()">
                            <i class="fa-solid fa-plus me-1"></i> Nuevo documento
                        </button>
                    </div>
                    <div id="docLista"></div>
                </div>

                <!-- Formulario agregar/editar -->
                <div id="docFormSection" style="display:none">
                    <form id="formDocumento" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="id_vehiculo" id="docIdVehiculo">
                        <input type="hidden" name="id" id="docId" value="">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Tipo de documento <span class="text-danger">*</span></label>
                                <select name="tipo" class="form-select" required>
                                    <option value="">Seleccione...</option>
                                    <option value="soap">SOAP</option>
                                    <option value="revision_tecnica">Revisión técnica</option>
                                    <option value="permiso_circulacion">Permiso de circulación</option>
                                    <option value="seguro">Seguro</option>
                                    <option value="otro">Otro</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">N° documento</label>
                                <input type="text" name="numero_documento" class="form-control" placeholder="Opcional">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Fecha emisión</label>
                                <input type="date" name="fecha_emision" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Fecha vencimiento <span class="text-danger">*</span></label>
                                <input type="date" name="fecha_vencimiento" class="form-control" required>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label">Archivo (PDF, JPG, PNG — máx. 5 MB)</label>
                                <input type="file" name="archivo" id="docArchivoInput" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                                <small id="docArchivoActual" class="text-muted mt-1 d-block"></small>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label">Observación</label>
                                <textarea name="observacion" class="form-control" rows="2" placeholder="Opcional"></textarea>
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-4">
                            <button type="button" class="btn btn-outline-secondary btn-main" onclick="ocultarFormDoc()">
                                <i class="fa-solid fa-arrow-left me-1"></i> Volver
                            </button>
                            <button type="submit" class="btn btn-dark btn-main">
                                <i class="fa-solid fa-floppy-disk me-1"></i> Guardar
                            </button>
                        </div>
                    </form>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- MODAL MANTENCIONES -->
<div class="modal fade" id="modalMantenciones" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fa-solid fa-wrench me-2"></i>
                    Mantenciones — <span id="mantPatente"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">

                <!-- Lista -->
                <div id="mantListSection">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="text-muted small">Historial de mantenciones del vehículo</span>
                        <button class="btn btn-dark btn-main" onclick="mostrarFormMant()">
                            <i class="fa-solid fa-plus me-1"></i> Registrar mantención
                        </button>
                    </div>
                    <div id="mantLista"></div>
                </div>

                <!-- Formulario agregar/editar -->
                <div id="mantFormSection" style="display:none">
                    <form id="formMantencion">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="id_vehiculo" id="mantIdVehiculo">
                        <input type="hidden" name="id" id="mantId" value="">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Tipo <span class="text-danger">*</span></label>
                                <select name="tipo" class="form-select" required>
                                    <option value="">Seleccione...</option>
                                    <option value="preventiva">Preventiva</option>
                                    <option value="correctiva">Correctiva</option>
                                    <option value="revision">Revisión general</option>
                                    <option value="neumaticos">Neumáticos</option>
                                    <option value="frenos">Frenos</option>
                                    <option value="aceite">Cambio de aceite</option>
                                    <option value="otro">Otro</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Fecha <span class="text-danger">*</span></label>
                                <input type="date" name="fecha" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">KM al momento</label>
                                <input type="number" name="km_en_mantencion" class="form-control" placeholder="Opcional" min="0">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Costo (CLP)</label>
                                <input type="number" name="costo" class="form-control" placeholder="Opcional" min="0">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Proveedor / Taller</label>
                                <input type="text" name="proveedor" class="form-control" placeholder="Opcional">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label">Descripción</label>
                                <textarea name="descripcion" class="form-control" rows="2" placeholder="Trabajo realizado, repuestos, etc."></textarea>
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-4">
                            <button type="button" class="btn btn-outline-secondary btn-main" onclick="ocultarFormMant()">
                                <i class="fa-solid fa-arrow-left me-1"></i> Volver
                            </button>
                            <button type="submit" class="btn btn-dark btn-main">
                                <i class="fa-solid fa-floppy-disk me-1"></i> Guardar
                            </button>
                        </div>
                    </form>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- MODAL DESCARGA EXCEL ESTATUS VEHÍCULO -->
<div class="modal fade" id="modalDescargaExcel" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:460px">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fa-solid fa-file-excel me-2"></i> Descargar Informe Estatus Vehículo
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <form id="formDescargaExcel" method="POST"
                  action="/visibility2/portal/informes/descargar_excel_estatus_vehiculo.php"
                  target="_blank">
                <div class="modal-body">

                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label">Fecha inicio</label>
                            <input type="date" name="start_date" id="excelStartDate" class="form-control" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Fecha fin</label>
                            <input type="date" name="end_date" id="excelEndDate" class="form-control" required>
                        </div>
                    </div>

                    <div class="alert alert-info mt-3 mb-0">
                        <i class="fa-solid fa-circle-info me-1"></i>
                        El cumplimiento se evalúa <strong>por día hábil</strong>: se esperan dos
                        subidas, una antes de las 12:00 y otra desde las 12:00.
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-main"
                            data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-dark btn-main">
                        <i class="fa-solid fa-file-arrow-down me-1"></i> Descargar
                    </button>
                </div>
            </form>

        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     MODAL — INFORME "ESTATUS DEL VEHÍCULO" EN VIVO
     Réplica en pantalla del Excel. Mismo cálculo (lib_estatus_vehiculo.php),
     pero siempre con el dato del momento en que se consulta.
     ══════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalEstatus" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content" style="height:95vh">

            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fa-solid fa-gauge-high me-2"></i> Estatus del vehículo — cumplimiento
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body d-flex flex-column" style="overflow:hidden">

                <!-- Filtros -->
                <div class="row g-2 mb-3 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label">Desde</label>
                        <input type="date" id="evStart" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Hasta</label>
                        <input type="date" id="evEnd" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-dark w-100 btn-main" onclick="cargarEstatus()">
                            <i class="fa-solid fa-rotate me-1"></i> Consultar
                        </button>
                    </div>
                    <div class="col-md-4">
                        <input type="text" id="evBuscar" class="form-control"
                               placeholder="Buscar..." oninput="evFiltrar()">
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-outline-secondary w-100 btn-main"
                                onclick="evLimpiarFiltros()" title="Quitar todos los filtros de columna">
                            <i class="fa-solid fa-filter-circle-xmark me-1"></i> Limpiar
                        </button>
                    </div>
                </div>

                <div class="alert alert-info py-2 mb-2" style="font-size:12px">
                    <i class="fa-solid fa-circle-info me-1"></i>
                    Usa el ícono <i class="fa-solid fa-filter"></i> de cada columna para filtrar por
                    sus valores, igual que en Excel. Se pueden combinar varias columnas a la vez.
                </div>

                <div id="evMeta" class="ev-meta mb-2"></div>

                <div id="evLoading" class="text-center py-5" style="display:none">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="text-muted mt-3 mb-0">Calculando el informe con los datos actuales...</p>
                </div>

                <div id="evError" class="alert alert-danger" style="display:none">
                    <i class="fa-solid fa-circle-exclamation me-1"></i>
                    <span id="evErrorMsg"></span>
                </div>

                <div id="evContenido" class="d-flex flex-column" style="display:none;flex:1;overflow:hidden">

                    <div id="evKpis" class="ev-kpi-row mb-3"></div>

                    <ul class="nav nav-tabs" role="tablist">
                        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab"
                            data-bs-target="#evTabResumen" type="button">Resumen por ejecutor</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab"
                            data-bs-target="#evTabMatriz" type="button">Cumplimiento día a día</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab"
                            data-bs-target="#evTabDetalle" type="button">Detalle de subidas</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab"
                            data-bs-target="#evTabDup" type="button">
                            Fotos duplicadas <span class="badge bg-danger" id="evTabDupCount">0</span></button></li>
                    </ul>

                    <div class="tab-content ev-tab-content">

                        <div class="tab-pane fade show active" id="evTabResumen">
                            <div class="ev-scroll">
                                <table class="table table-hover align-middle ev-tabla">
                                    <thead><tr>
                                        <th>Usuario</th><th>RUT</th><th>Nombre</th><th>Patente</th><th>División</th>
                                        <th>Esperadas</th><th>Realizadas</th><th>Pendientes</th>
                                        <th>% Cumpl.</th><th>Duplicadas</th><th>Días patente distinta</th>
                                    </tr></thead>
                                    <tbody id="evTbodyResumen"></tbody>
                                </table>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="evTabMatriz">
                            <div class="ev-scroll">
                                <table class="table table-hover align-middle ev-tabla ev-matriz">
                                    <thead id="evTheadMatriz"></thead>
                                    <tbody id="evTbodyMatriz"></tbody>
                                </table>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="evTabDetalle">
                            <div class="ev-scroll">
                                <table class="table table-hover align-middle ev-tabla">
                                    <thead><tr>
                                        <th>Usuario</th><th>Nombre</th><th>Fecha</th><th>Día</th>
                                        <th>Patente</th><th>División</th>
                                        <th>Primera subida</th><th>Última subida</th><th>Fotos</th>
                                        <th>¿Esperado?</th><th>Patente ingresada</th><th>Respuestas</th>
                                    </tr></thead>
                                    <tbody id="evTbodyDetalle"></tbody>
                                </table>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="evTabDup">
                            <div class="ev-scroll">
                                <table class="table table-hover align-middle ev-tabla">
                                    <thead><tr>
                                        <th>Usuario</th><th>Nombre</th><th>División</th>
                                        <th>SHA1</th><th>Subidas</th>
                                        <th>Días distintos</th><th>Fechas</th><th>Primera</th><th>Última</th>
                                    </tr></thead>
                                    <tbody id="evTbodyDup"></tbody>
                                </table>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <span class="text-muted me-auto" style="font-size:12px">
                    <i class="fa-solid fa-circle-info me-1"></i>
                    Los datos se calculan al momento de consultar. Vuelve a presionar
                    <strong>Consultar</strong> para actualizarlos.
                </span>
                <button type="button" class="btn btn-success btn-main" onclick="evDescargarExcel()">
                    <i class="fa-solid fa-file-excel me-1"></i> Descargar este informe
                </button>
                <button type="button" class="btn btn-outline-secondary btn-main" data-bs-dismiss="modal">Cerrar</button>
            </div>

        </div>
    </div>
</div>

<!-- Descarga del Excel con los MISMOS filtros que se ven en pantalla -->
<form id="evFormExcel" method="POST" target="_blank"
      action="/visibility2/portal/informes/descargar_excel_estatus_vehiculo.php">
    <input type="hidden" name="start_date" id="evExcelStart">
    <input type="hidden" name="end_date"   id="evExcelEnd">
<!-- El informe siempre se evalúa en modo diario -->
</form>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>


<script>
let vehiculos = [];

let catalogos = {
    empresas: [],
    divisiones: [],
    subdivisiones: [],
    merchans: []
};

let modalVehiculo = null;
let modalHistorial = null;
let modalReporte = null;
let modalDescargaExcel = null;
let modalDescargaReporteVehiculos = null;
let modalDocumentos = null;
let modalMantenciones = null;

let vehiculosConAlerta = new Set();
let alertasData = { vencidos: [], proximos_15: [], proximos_30: [] };
let docVehiculoActual  = { id: null, patente: '' };
let mantVehiculoActual = { id: null, patente: '' };

let reporteVehiculos = [];
let reportePreguntas = [];
let descargaReporteTimer = null;
let descargaReporteTimeout = null;
let descargaReporteActiva = false;

$(document).ready(function () {
    modalVehiculo     = new bootstrap.Modal(document.getElementById('modalVehiculo'));
    modalHistorial    = new bootstrap.Modal(document.getElementById('modalHistorial'));
    modalDescargaExcel = new bootstrap.Modal(document.getElementById('modalDescargaExcel'));
    modalDescargaReporteVehiculos = new bootstrap.Modal(document.getElementById('modalDescargaReporteVehiculos'));
    modalDocumentos   = new bootstrap.Modal(document.getElementById('modalDocumentos'));
    modalMantenciones = new bootstrap.Modal(document.getElementById('modalMantenciones'));
    const modalReporteEl = document.getElementById('modalReporte');
    
    if (modalReporteEl) {
        modalReporte = new bootstrap.Modal(modalReporteEl);
    }

    $('#formDescargaReporteVehiculos').on('submit', function () {
        mostrarOverlayDescargaReporte();
        if (modalDescargaReporteVehiculos) {
            modalDescargaReporteVehiculos.hide();
        }
    });

    $('#iframeDescargaReporteVehiculos').on('load', function () {
        if (descargaReporteActiva) {
            completarOverlayDescargaReporte();
        }
    });

    cargarCatalogos();
    cargarVehiculos();
    cargarAlertas();

    $('#formDocumento').on('submit', function (e) {
        e.preventDefault();
        guardarDocumento();
    });

    $('#formMantencion').on('submit', function (e) {
        e.preventDefault();
        guardarMantencion();
    });

    $('#estado').on('change', function () {
        controlarMerchanPorEstado();
    });

    $('#buscarVehiculo').on('keyup', function () {
        renderVehiculos();
    });

    $('#buscarReporteVehiculo').on('keyup', function () {
        renderReporteVehiculos();
    });

    $('#formVehiculo').on('submit', function (e) {
        e.preventDefault();
        guardarVehiculo();
    });

    $(document).on('click', '.js-report-photo', function () {
        const url = $(this).attr('data-url') || '';
        const title = $(this).attr('data-title') || 'Foto';
    
        abrirFotoReporte(url, title);
    });
    
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') {
            cerrarFotoReporte();
        }
    });
    
    $(document).on('click', '#modalFotoReporte', function (e) {
        if (e.target.id === 'modalFotoReporte') {
            cerrarFotoReporte();
        }
    });
    
    inicializarSelect2Merchan();
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

function actualizarKpisVehiculos(data) {
    const lista = Array.isArray(data) ? data : [];

    const total = lista.length;
    const activos = lista.filter(v => Number(v.estado) === 1).length;
    const inactivos = total - activos;
    const asignados = lista.filter(v => {
        return (v.merchan && String(v.merchan).trim() !== '') ||
               (v.id_merchan && Number(v.id_merchan) > 0);
    }).length;

    $('#kpiTotalVehiculos').text(total);
    $('#kpiVehiculosActivos').text(activos);
    $('#kpiVehiculosInactivos').text(inactivos);
    $('#kpiVehiculosAsignados').text(asignados);
}

function inicializarSelect2Merchan() {
    const $merchan = $('#id_merchan');

    if (!$merchan.length) {
        return;
    }

    if (typeof $.fn.select2 === 'undefined') {
        return;
    }

    if ($merchan.hasClass('select2-hidden-accessible')) {
        $merchan.select2('destroy');
    }

    $merchan.select2({
        theme: 'bootstrap-5',
        width: '100%',
        placeholder: 'Buscar merchan...',
        allowClear: true,
        dropdownParent: $('#modalVehiculo')
    });
}

function controlarMerchanPorEstado() {
    const estado = $('#estado').val();
    const $merchan = $('#id_merchan');

    if (String(estado) === '0') {
        // Vehículo inactivo: limpiar y dejar sin asignar
        $merchan
            .val('')
            .trigger('change')
            .prop('required', false)
            .prop('disabled', true);

        if ($merchan.hasClass('select2-hidden-accessible')) {
            $merchan.trigger('change.select2');
        }

        $('label[for="id_merchan"]').text('Merchan asignado (sin asignar por inactividad)');
    } else {
        // Vehículo activo: vuelve a exigir merchan
        $merchan
            .prop('disabled', false)
            .prop('required', true);

        $('label[for="id_merchan"]').text('Merchan asignado');
    }
}

function destruirSelect2Merchan() {
    const $merchan = $('#id_merchan');

    if (
        $merchan.length &&
        typeof $.fn.select2 !== 'undefined' &&
        $merchan.hasClass('select2-hidden-accessible')
    ) {
        $merchan.select2('destroy');
    }
}

function cargarCatalogos() {
    $.getJSON('ajax_vehiculos_catalogos.php', function (resp) {
        if (!resp || !resp.ok) {
            alert(resp?.msg || 'No se pudieron cargar los catálogos.');
            return;
        }

        catalogos = resp.data || {
            empresas: [],
            divisiones: [],
            subdivisiones: [],
            merchans: []
        };

        llenarSelect('#id_empresa', catalogos.empresas, 'id', 'nombre');
        filtrarDivisiones();
        filtrarSubdivisiones();
        filtrarMerchans();
    }).fail(function () {
        alert('Error al cargar los catálogos.');
    });
}

function llenarSelect(selector, data, valueField, textField, selectedValue = '') {
    const $select = $(selector);

    if (!$select.length) {
        return;
    }

    $select.empty();
    $select.append('<option value="">Seleccione...</option>');

    if (!Array.isArray(data)) {
        data = [];
    }

    data.forEach(item => {
        const value = item[valueField] ?? '';
        const text = item[textField] ?? '';
        const selected = String(value) === String(selectedValue) ? 'selected' : '';

        $select.append(`
            <option value="${escapeHtml(value)}" ${selected}>
                ${escapeHtml(text)}
            </option>
        `);
    });
}

function filtrarDivisiones(selectedValue = '') {
    const idEmpresa = $('#id_empresa').val();

    const divisiones = catalogos.divisiones.filter(d => {
        return !idEmpresa || String(d.id_empresa) === String(idEmpresa);
    });

    llenarSelect('#id_division', divisiones, 'id', 'nombre', selectedValue);

    if (selectedValue !== '') {
        $('#id_division').val(selectedValue);
    }
}

function filtrarSubdivisiones(selectedValue = '') {
    const idDivision = $('#id_division').val();

    const subdivisiones = catalogos.subdivisiones.filter(s => {
        return !idDivision || String(s.id_division) === String(idDivision);
    });

    llenarSelect('#id_subdivision', subdivisiones, 'id', 'nombre', selectedValue);

    if (selectedValue !== '') {
        $('#id_subdivision').val(selectedValue);
    }
}

function filtrarMerchans(selectedValue = '') {
    destruirSelect2Merchan();

    // Se muestran todos los usuarios activos sin filtrar por división ni subdivisión,
    // porque un vehículo puede ser asignado temporalmente a alguien de otra división.
    llenarSelect('#id_merchan', catalogos.merchans, 'id', 'nombre_completo', selectedValue);

    $('#id_merchan').val(selectedValue || '').trigger('change');

    inicializarSelect2Merchan();
}

function cargarVehiculos() {
    $('#tbodyVehiculos').html(`
        <tr>
            <td colspan="11" class="text-center text-muted py-4">
                Cargando vehículos...
            </td>
        </tr>
    `);

    $.getJSON('ajax_vehiculos_listar.php', function (resp) {
        if (!resp || !resp.ok) {
            $('#tbodyVehiculos').html(`
                <tr>
                    <td colspan="11" class="text-center text-danger py-4">
                        ${escapeHtml(resp?.msg || 'Error al cargar vehículos.')}
                    </td>
                </tr>
            `);
            return;
        }

        vehiculos = Array.isArray(resp.data) ? resp.data : [];
        renderVehiculos();
    }).fail(function () {
        $('#tbodyVehiculos').html(`
            <tr>
                <td colspan="11" class="text-center text-danger py-4">
                    Error inesperado al cargar vehículos.
                </td>
            </tr>
        `);
    });
}

function renderVehiculos() {
    const filtro = $('#buscarVehiculo').val().toLowerCase().trim();

    const data = vehiculos.filter(v => {
        const estaActivo = Number(v.estado) === 1;
        const estadoTexto = estaActivo ? 'activo activos' : 'inactivo inactivos';

        const tieneMerchan =
            (v.merchan && String(v.merchan).trim() !== '') ||
            (v.id_merchan && Number(v.id_merchan) > 0);

        const asignacionTexto = tieneMerchan ? 'asignado asignados' : 'sin asignar no asignado';

        /*
        | Búsqueda exacta por estado para evitar que "activo"
        | también encuentre "inactivo", porque inactivo contiene la palabra activo.
        */
        if (filtro === 'activo' || filtro === 'activos') {
            return estaActivo;
        }

        if (filtro === 'inactivo' || filtro === 'inactivos') {
            return !estaActivo;
        }

        if (filtro === 'sin asignar' || filtro === 'no asignado') {
            return !tieneMerchan;
        }

        const texto = [
            v.patente,
            v.modelo,
            v.tipo_combustible,
            v.direccion_origen,
            v.empresa,
            v.division,
            v.subdivision,
            v.merchan,
            v.usuario_merchan,
            estadoTexto,
            asignacionTexto
        ]
            .map(valor => valor || '')
            .join(' ')
            .toLowerCase();

        return texto.includes(filtro);
    });

    actualizarKpisVehiculos(vehiculos);

    if (data.length === 0) {
        $('#tbodyVehiculos').html(`
            <tr>
                <td colspan="11" class="text-center text-muted py-4">
                    No se encontraron vehículos.
                </td>
            </tr>
        `);
        return;
    }

    let html = '';

    data.forEach(v => {
        const estadoBadge = Number(v.estado) === 1
            ? '<span class="badge-soft badge-activo">Activo</span>'
            : '<span class="badge-soft badge-inactivo">Inactivo</span>';

        const tieneAlerta = vehiculosConAlerta.has(Number(v.id));
        const alertaBadge = tieneAlerta
            ? `<span class="ms-1" style="color:#f59e0b;font-size:13px" title="Tiene documentos por vencer"><i class="fa-solid fa-triangle-exclamation"></i></span>`
            : '';

        html += `
            <tr>
                <td>
                    <span class="vehicle-plate">
                        <i class="fa-solid fa-car"></i>
                        ${escapeHtml(v.patente)}
                    </span>${alertaBadge}
                </td>

                <td>${escapeHtml(v.modelo || '-')}</td>
                <td>${escapeHtml(v.tipo_combustible || '-')}</td>
                <td>${escapeHtml(v.direccion_origen || '-')}</td>
                <td>${escapeHtml(v.empresa || '-')}</td>
                <td>${escapeHtml(v.division || '-')}</td>
                <td>${escapeHtml(v.subdivision || '-')}</td>
                <td>${escapeHtml(v.merchan || '-')}</td>
                <td>${escapeHtml(v.fecha_inicio || '-')}</td>
                <td>${estadoBadge}</td>

                <td>
                    <button
                        type="button"
                        class="btn btn-sm"
                        style="background:linear-gradient(135deg,#2fb8d8,#1a9db5);color:#fff"
                        onclick="verDocumentos(${Number(v.id)}, '${escapeHtml(v.patente)}')"
                        title="Documentos legales"
                    >
                        <i class="fa-solid fa-file-lines"></i>
                    </button>
                </td>

                <td>
                    <button
                        type="button"
                        class="btn btn-sm"
                        style="background:linear-gradient(135deg,#7d5fff,#5f47ff);color:#fff"
                        onclick="verMantenciones(${Number(v.id)}, '${escapeHtml(v.patente)}')"
                        title="Mantenciones"
                    >
                        <i class="fa-solid fa-wrench"></i>
                    </button>
                </td>

                <td class="text-end">
                    <button
                        type="button"
                        class="btn btn-sm btn-outline-dark"
                        onclick="abrirModalEditar(${Number(v.id)})"
                        title="Editar vehículo"
                    >
                        <i class="fa-solid fa-pen-to-square"></i>
                    </button>

                    <button
                        type="button"
                        class="btn btn-sm btn-outline-primary"
                        onclick="verHistorial(${Number(v.id)})"
                        title="Ver historial"
                    >
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </button>
                </td>
            </tr>
        `;
    });

    $('#tbodyVehiculos').html(html);
}

function abrirModalNuevo() {
    $('#formVehiculo')[0].reset();

    $('#id').val('');
    $('#patente').val('');
    $('#modelo').val('');
    $('#tipo_combustible').val('');
    $('#direccion_origen').val('');
    $('#lat_origen').val('');
    $('#lng_origen').val('');
    $('#estado').val('1');
    controlarMerchanPorEstado();
    $('#fecha_inicio').val('<?= date('Y-m-d') ?>');
    $('#observacion').val('');

    $('#tituloModalVehiculo').text('Nuevo vehículo');

    llenarSelect('#id_empresa', catalogos.empresas, 'id', 'nombre');
    $('#id_empresa').val('');

    filtrarDivisiones();
    $('#id_division').val('');

    filtrarSubdivisiones();
    $('#id_subdivision').val('');

    filtrarMerchans();
    $('#id_merchan').val('').trigger('change');

    modalVehiculo.show();
}

function abrirModalEditar(id) {
    const v = vehiculos.find(item => Number(item.id) === Number(id));

    if (!v) {
        alert('No se encontró el vehículo seleccionado.');
        return;
    }

    $('#formVehiculo')[0].reset();

    $('#id').val(v.id || '');
    $('#patente').val(v.patente || '');
    $('#modelo').val(v.modelo || '');
    $('#tipo_combustible').val(v.tipo_combustible || '');
    $('#direccion_origen').val(v.direccion_origen || '');
    $('#lat_origen').val(v.lat_origen || '');
    $('#lng_origen').val(v.lng_origen || '');
    $('#estado').val(v.estado ?? '1');
    $('#fecha_inicio').val('<?= date('Y-m-d') ?>');
    $('#observacion').val('');

    llenarSelect('#id_empresa', catalogos.empresas, 'id', 'nombre', v.id_empresa);
    $('#id_empresa').val(v.id_empresa || '');

    filtrarDivisiones(v.id_division);
    $('#id_division').val(v.id_division || '');

    filtrarSubdivisiones(v.id_subdivision);
    $('#id_subdivision').val(v.id_subdivision || '');

    filtrarMerchans(v.id_merchan);
    $('#id_merchan').val(v.id_merchan || '').trigger('change');

    $('#tituloModalVehiculo').text('Editar vehículo');

    controlarMerchanPorEstado();
    modalVehiculo.show();
    
}

function guardarVehiculo() {
    const form = document.getElementById('formVehiculo');
    const formData = new FormData(form);

    $.ajax({
        url: 'ajax_vehiculos_guardar.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        beforeSend: function () {
            $('#formVehiculo button[type="submit"]')
                .prop('disabled', true)
                .html('<i class="fa-solid fa-spinner fa-spin me-1"></i> Guardando...');
        },
        success: function (resp) {
            if (!resp || !resp.ok) {
                alert(resp?.msg || 'No se pudo guardar el vehículo.');
                return;
            }

            modalVehiculo.hide();
            cargarVehiculos();
        },
        error: function (xhr) {
            console.error(xhr.responseText);
            alert('Error inesperado al guardar el vehículo.');
        },
        complete: function () {
            $('#formVehiculo button[type="submit"]')
                .prop('disabled', false)
                .html('<i class="fa-solid fa-floppy-disk me-1"></i> Guardar');
        }
    });
}

function verHistorial(idVehiculo) {
    $('#contenedorHistorial').html(`
        <p class="text-muted">Cargando historial...</p>
    `);

    modalHistorial.show();

    $.getJSON('ajax_vehiculos_historial.php', { id_vehiculo: idVehiculo }, function (resp) {
        if (!resp || !resp.ok) {
            $('#contenedorHistorial').html(`
                <p class="text-danger">
                    ${escapeHtml(resp?.msg || 'Error al cargar historial.')}
                </p>
            `);
            return;
        }

        const historial = Array.isArray(resp.data) ? resp.data : [];

        if (historial.length === 0) {
            $('#contenedorHistorial').html(`
                <p class="text-muted">Este vehículo aún no tiene historial.</p>
            `);
            return;
        }

        let html = '';

        historial.forEach(h => {
            html += `
                <div class="history-item">
                    <div class="d-flex justify-content-between gap-3 flex-wrap">
                        <strong>${escapeHtml(h.merchan || 'Sin merchan')}</strong>

                        <span class="history-date">
                            ${escapeHtml(h.fecha_inicio || '-')} hasta ${escapeHtml(h.fecha_termino || 'Actual')}
                        </span>
                    </div>

                    <div class="mt-2 text-muted">
                        Empresa: ${escapeHtml(h.empresa || '-')} |
                        División: ${escapeHtml(h.division || '-')} |
                        Subdivisión: ${escapeHtml(h.subdivision || '-')}
                    </div>

                    ${
                        h.observacion
                            ? `<div class="mt-2">${escapeHtml(h.observacion)}</div>`
                            : ''
                    }
                </div>
            `;
        });

        $('#contenedorHistorial').html(html);
    }).fail(function () {
        $('#contenedorHistorial').html(`
            <p class="text-danger">Error inesperado al cargar historial.</p>
        `);
    });
}

function abrirModalReporte() {
    if (!modalReporte) {
        alert('No se encontró el modal de reporte. Revisa que exista el HTML con id="modalReporte".');
        return;
    }

    $('#buscarReporteVehiculo').val('');

    $('#tbodyReporteVehiculos').html(`
        <tr>
            <td colspan="5" class="text-center text-muted py-4">
                Cargando reporte...
            </td>
        </tr>
    `);

    modalReporte.show();
    cargarReporteVehiculos();
}

function abrirModalDescargaReporteVehiculos() {
    if (!modalDescargaReporteVehiculos) {
        alert('No se encontró el modal de descarga del reporte.');
        return;
    }

    const hoy = new Date();
    const primerDia = new Date(hoy.getFullYear(), hoy.getMonth(), 1);
    const formatoFecha = fecha => {
        const year = fecha.getFullYear();
        const month = String(fecha.getMonth() + 1).padStart(2, '0');
        const day = String(fecha.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    };

    $('#reporteFechaDesde').val(formatoFecha(primerDia));
    $('#reporteFechaHasta').val(formatoFecha(hoy));
    modalDescargaReporteVehiculos.show();
}

function mostrarOverlayDescargaReporte() {
    const overlay = document.getElementById('overlayDescargaReporte');
    const barra = document.getElementById('barraDescargaReporte');
    const texto = document.getElementById('textoDescargaReporte');

    if (!overlay || !barra || !texto) {
        return;
    }

    if (descargaReporteTimer) {
        clearInterval(descargaReporteTimer);
    }
    if (descargaReporteTimeout) {
        clearTimeout(descargaReporteTimeout);
    }

    descargaReporteActiva = true;
    let progreso = 12;
    barra.style.width = progreso + '%';
    texto.textContent = 'Preparando consulta...';
    overlay.classList.add('show');
    overlay.setAttribute('aria-hidden', 'false');

    descargaReporteTimer = setInterval(function () {
        progreso = Math.min(progreso + 14, 92);
        barra.style.width = progreso + '%';

        if (progreso >= 70) {
            texto.textContent = 'Armando archivo Excel...';
        } else if (progreso >= 35) {
            texto.textContent = 'Procesando registros...';
        }
    }, 420);

    descargaReporteTimeout = setTimeout(function () {
        completarOverlayDescargaReporte();
    }, 9000);
}

function completarOverlayDescargaReporte() {
    const barra = document.getElementById('barraDescargaReporte');
    const texto = document.getElementById('textoDescargaReporte');

    if (!descargaReporteActiva || !barra || !texto) {
        return;
    }

    descargaReporteActiva = false;
    barra.style.width = '100%';
    texto.textContent = 'Descarga iniciada.';

    setTimeout(cerrarOverlayDescargaReporte, 900);
}

function cerrarOverlayDescargaReporte() {
    const overlay = document.getElementById('overlayDescargaReporte');
    const barra = document.getElementById('barraDescargaReporte');
    const texto = document.getElementById('textoDescargaReporte');

    if (descargaReporteTimer) {
        clearInterval(descargaReporteTimer);
        descargaReporteTimer = null;
    }

    if (descargaReporteTimeout) {
        clearTimeout(descargaReporteTimeout);
        descargaReporteTimeout = null;
    }

    descargaReporteActiva = false;

    if (overlay) {
        overlay.classList.remove('show');
        overlay.setAttribute('aria-hidden', 'true');
    }

    if (barra) {
        barra.style.width = '0%';
    }

    if (texto) {
        texto.textContent = 'Iniciando...';
    }
}

function cargarReporteVehiculos() {
    $('#tbodyReporteVehiculos').html(`
        <tr>
            <td colspan="4" class="text-center text-muted py-4">
                <i class="fa-solid fa-spinner fa-spin me-1"></i> Cargando reporte...
            </td>
        </tr>
    `);

    $.getJSON('/visibility2/portal/modulos/mod_vehiculos/ajax_vehiculos_reporte.php', function (resp) {
        if (!resp || !resp.ok) {
            $('#tbodyReporteVehiculos').html(`
                <tr>
                    <td colspan="4" class="text-center text-danger py-4">
                        ${escapeHtml(resp?.msg || 'No se pudo cargar el reporte.')}
                    </td>
                </tr>
            `);
            return;
        }

        reportePreguntas = Array.isArray(resp.questions) ? resp.questions : [];
        reporteVehiculos = Array.isArray(resp.data) ? resp.data : [];

        renderReporteVehiculos();

    }).fail(function (xhr) {
        console.error(xhr.responseText);

        $('#tbodyReporteVehiculos').html(`
            <tr>
                <td colspan="4" class="text-center text-danger py-4">
                    Error inesperado al cargar el reporte.
                </td>
            </tr>
        `);
    });
}

function renderReporteVehiculos() {
    renderHeaderReporte();

    const filtro = $('#buscarReporteVehiculo').val().toLowerCase().trim();

    const data = reporteVehiculos.filter(item => {
        const texto = obtenerTextoBusquedaReporte(item);
        return texto.includes(filtro);
    });

    const colspan = 4 + reportePreguntas.length;

    if (data.length === 0) {
        $('#tbodyReporteVehiculos').html(`
            <tr>
                <td colspan="${colspan}" class="text-center text-muted py-4">
                    No se encontraron datos para el reporte.
                </td>
            </tr>
        `);
        return;
    }

    let html = '';

    data.forEach(item => {
        html += `
            <tr>
                <td>
                    <span class="vehicle-plate">
                        <i class="fa-solid fa-car"></i>
                        ${escapeHtml(item.patente || '-')}
                    </span>
                </td>

                <td>${escapeHtml(item.rut_usuario || '-')}</td>

                <td>
                    <strong>${escapeHtml(item.nombre_completo || '-')}</strong>
                    ${
                        item.usuario_merchan
                            ? `<div class="text-muted small">${escapeHtml(item.usuario_merchan)}</div>`
                            : ''
                    }
                </td>

                <td>
                    <div>${escapeHtml(item.fecha_ultima_respuesta || '-')}</div>
                    <div class="report-date">${escapeHtml(item.hora_ultima_respuesta || '')}</div>
                </td>
        `;

        reportePreguntas.forEach(pregunta => {
            const key = 'q_' + pregunta.id;
            const respuesta = item.answers ? item.answers[key] : null;

            html += `
                <td>
                    ${renderCeldaRespuestaReporte(respuesta, pregunta)}
                </td>
            `;
        });

        html += `</tr>`;
    });

    $('#tbodyReporteVehiculos').html(html);
}

function renderHeaderReporte() {
    let html = `
        <tr>
            <th>Placa patente</th>
            <th>RUT usuario</th>
            <th>Nombre completo</th>
            <th>Última respuesta</th>
    `;

    reportePreguntas.forEach(p => {
        html += `
            <th title="${escapeHtml(p.question_text || '')}">
                ${escapeHtml(p.question_text || '-')}
            </th>
        `;
    });

    html += `</tr>`;

    $('#theadReporteVehiculos').html(html);
}

function renderCeldaRespuestaReporte(respuesta, pregunta) {
    if (!respuesta) {
        return `<span class="text-muted small">-</span>`;
    }

    const esFoto = Number(pregunta.id_question_type) === 7 || respuesta.type === 'photo';

    if (esFoto) {
        const fotos = Array.isArray(respuesta.photos) ? respuesta.photos : [];

        if (fotos.length === 0) {
            return `<span class="text-muted small">Sin foto</span>`;
        }

        let html = `<div class="report-photo-grid">`;

        fotos.slice(0, 5).forEach(foto => {
            const fotoUrl = foto.url || '';
            const fotoTitulo = foto.name || pregunta.question_text || 'Foto reporte';
        
            html += `
                <img 
                    src="${escapeHtml(fotoUrl)}" 
                    data-url="${escapeHtml(fotoUrl)}"
                    data-title="${escapeHtml(fotoTitulo)}"
                    class="report-photo-thumb js-report-photo" 
                    alt="${escapeHtml(fotoTitulo)}"
                    title="${escapeHtml(fotoTitulo)}"
                    loading="lazy"
                >
            `;
        });

        if (fotos.length > 5) {
            html += `
                <div class="report-photo-more">
                    +${fotos.length - 5}
                </div>
            `;
        }

        html += `</div>`;

        return html;
    }

    const valor = respuesta.value || '';

    if (!valor) {
        return `<span class="text-muted small">-</span>`;
    }

    return `
        <div class="report-cell-text">
            ${escapeHtml(valor)}
        </div>
    `;
}

function obtenerTextoBusquedaReporte(item) {
    let partes = [
        item.patente,
        item.rut_usuario,
        item.nombre_completo,
        item.usuario_merchan,
        item.fecha_ultima_respuesta,
        item.hora_ultima_respuesta
    ];

    if (item.answers) {
        Object.values(item.answers).forEach(resp => {
            if (!resp) return;

            if (resp.value) {
                partes.push(resp.value);
            }

            if (Array.isArray(resp.photos)) {
                resp.photos.forEach(foto => {
                    partes.push(foto.name || '');
                    partes.push(foto.url || '');
                });
            }
        });
    }

    return partes
        .map(v => v || '')
        .join(' ')
        .toLowerCase();
}

function abrirFotoReporte(url, title) {
    if (!url) {
        return;
    }

    $('#visorFotoReporteImg').attr('src', url);
    $('#visorFotoReporteTitulo').text(title || 'Foto reporte');
    $('#visorFotoReporteDownload').attr('href', url);

    $('#modalFotoReporte')
        .addClass('show')
        .attr('aria-hidden', 'false');

    $('body').addClass('fleet-photo-open');
}

function cerrarFotoReporte() {
    $('#modalFotoReporte')
        .removeClass('show')
        .attr('aria-hidden', 'true');

    $('#visorFotoReporteImg').attr('src', '');
    $('#visorFotoReporteDownload').attr('href', '#');

    $('body').removeClass('fleet-photo-open');
}

$(document).on('keydown', function (e) {
    if (e.key === 'Escape') {
        cerrarFotoReporte();
    }
});

$(document).on('click', '#visorFotoReporte', function (e) {
    if (e.target.id === 'visorFotoReporte') {
        cerrarFotoReporte();
    }
});

// ═══════════════════════════════════════════════
// ALERTAS
// ═══════════════════════════════════════════════
function cargarAlertas() {
    $.getJSON('ajax_vehiculos_alertas.php', function (resp) {
        if (!resp || !resp.ok) return;

        alertasData = resp.data;
        const total = alertasData.vencidos.length + alertasData.proximos_15.length + alertasData.proximos_30.length;

        $('#kpiAlertasDocs').text(total);

        vehiculosConAlerta = new Set();
        [...alertasData.vencidos, ...alertasData.proximos_15, ...alertasData.proximos_30].forEach(a => {
            vehiculosConAlerta.add(Number(a.id_vehiculo));
        });

        renderAlertas(alertasData);

        if (total > 0) {
            $('#panelAlertas').show();
        }

        renderVehiculos();
    });
}

function togglePanelAlertas() {
    const $panel = $('#panelAlertas');
    if ($panel.is(':visible')) {
        $panel.hide();
    } else {
        $panel.show();
    }
}

const TIPO_DOC_LABELS = {
    soap: 'SOAP',
    revision_tecnica: 'Rev. Técnica',
    permiso_circulacion: 'Permiso Circ.',
    seguro: 'Seguro',
    otro: 'Otro',
};

function renderAlertas(data) {
    let html = '';

    function buildGroup(items, cssClass, icono, titulo) {
        if (!items.length) return '';
        let g = `<div class="mb-3">
            <strong class="d-block mb-2" style="font-size:13px">${icono} ${titulo} (${items.length})</strong>
            <div class="d-flex flex-wrap gap-2">`;
        items.forEach(a => {
            const dias = parseInt(a.dias_restantes);
            const diasTxt = dias < 0
                ? `Venció hace ${Math.abs(dias)}d`
                : `Vence: ${escapeHtml(a.fecha_vencimiento)} (${dias}d)`;
            const merchan = (a.nombre && a.apellido) ? `${escapeHtml(a.nombre)} ${escapeHtml(a.apellido)}` : '';
            g += `<div class="doc-alerta-item ${cssClass}" onclick="verDocumentos(${a.id_vehiculo}, '${escapeHtml(a.patente)}')">
                <strong>${escapeHtml(a.patente)}</strong>
                <span class="d-block">${escapeHtml(TIPO_DOC_LABELS[a.tipo] || a.tipo)}</span>
                <span class="d-block" style="font-size:11px">${diasTxt}</span>
                ${merchan ? `<span class="d-block" style="font-size:11px;opacity:.8">${merchan}</span>` : ''}
            </div>`;
        });
        g += `</div></div>`;
        return g;
    }

    html += buildGroup(data.vencidos,    'doc-badge-rojo',     '<i class="fa-solid fa-circle-xmark" style="color:#991b1b"></i>', 'Vencidos');
    html += buildGroup(data.proximos_15, 'doc-badge-naranja',  '<i class="fa-solid fa-triangle-exclamation" style="color:#92400e"></i>', 'Vencen en ≤ 15 días');
    html += buildGroup(data.proximos_30, 'doc-badge-amarillo', '<i class="fa-solid fa-clock" style="color:#b45309"></i>', 'Vencen en ≤ 30 días');

    if (!html) {
        html = `<p class="text-muted text-center py-2 mb-0">
            <i class="fa-solid fa-circle-check me-1 text-success"></i>
            No hay documentos con alertas activas.
        </p>`;
    }

    $('#contenedorAlertas').html(html);
}

// ═══════════════════════════════════════════════
// DOCUMENTOS
// ═══════════════════════════════════════════════
function verDocumentos(id, patente) {
    docVehiculoActual = { id, patente };
    document.getElementById('docPatente').textContent = patente;
    document.getElementById('docIdVehiculo').value = id;
    ocultarFormDoc();
    cargarDocumentos(id);
    modalDocumentos.show();
}

function cargarDocumentos(idVehiculo) {
    $('#docLista').html('<p class="text-muted text-center py-3"><i class="fa-solid fa-spinner fa-spin me-1"></i> Cargando...</p>');

    $.getJSON('ajax_vehiculos_documentos.php?action=list&id_vehiculo=' + idVehiculo, function (resp) {
        if (!resp.ok) {
            $('#docLista').html(`<p class="text-danger">${escapeHtml(resp.msg || 'Error.')}</p>`);
            return;
        }
        renderDocumentos(resp.data);
    }).fail(function () {
        $('#docLista').html('<p class="text-danger">Error inesperado al cargar documentos.</p>');
    });
}

function renderDocumentos(docs) {
    if (!docs.length) {
        $('#docLista').html('<p class="text-muted text-center py-4">Este vehículo no tiene documentos registrados.</p>');
        return;
    }

    let html = `<div class="table-responsive">
        <table class="table align-middle">
            <thead><tr>
                <th>Tipo</th><th>N° Documento</th><th>Emisión</th>
                <th>Vencimiento</th><th>Estado</th><th>Archivo</th>
                <th class="text-end">Acciones</th>
            </tr></thead><tbody>`;

    docs.forEach(d => {
        const dias = parseInt(d.dias_restantes);
        let badgeHtml;
        if (dias < 0) {
            badgeHtml = `<span class="badge-soft doc-badge-rojo">Vencido (${Math.abs(dias)}d)</span>`;
        } else if (dias <= 15) {
            badgeHtml = `<span class="badge-soft doc-badge-naranja">${dias}d restantes</span>`;
        } else if (dias <= 30) {
            badgeHtml = `<span class="badge-soft doc-badge-amarillo">${dias}d restantes</span>`;
        } else {
            badgeHtml = `<span class="badge-soft doc-badge-verde">${dias}d restantes</span>`;
        }

        const archivoHtml = d.archivo
            ? `<a href="/visibility2/portal/uploads/vehiculos_docs/${docVehiculoActual.id}/${escapeHtml(d.archivo)}"
                  target="_blank"
                  class="btn btn-sm"
                  style="background:linear-gradient(135deg,#4d7eff,#6a73ff);color:#fff"
                  title="Ver archivo">
                    <i class="fa-solid fa-file-arrow-down"></i>
               </a>`
            : '<span class="text-muted small">—</span>';

        html += `<tr>
            <td><span class="tipo-doc-label">${escapeHtml(TIPO_DOC_LABELS[d.tipo] || d.tipo)}</span></td>
            <td>${escapeHtml(d.numero_documento || '—')}</td>
            <td>${escapeHtml(d.fecha_emision || '—')}</td>
            <td>${escapeHtml(d.fecha_vencimiento)}</td>
            <td>${badgeHtml}</td>
            <td>${archivoHtml}</td>
            <td class="text-end">
                <button class="btn btn-sm btn-outline-dark" onclick="editarDocumento(${d.id})" title="Editar">
                    <i class="fa-solid fa-pen-to-square"></i>
                </button>
                <button class="btn btn-sm"
                        style="background:linear-gradient(135deg,#ef6f6c,#dc3545);color:#fff"
                        onclick="eliminarDocumento(${d.id})" title="Eliminar">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </td>
        </tr>`;
    });

    html += '</tbody></table></div>';
    $('#docLista').html(html);
}

function mostrarFormDoc() {
    document.getElementById('docId').value = '';
    document.getElementById('formDocumento').reset();
    document.getElementById('docIdVehiculo').value = docVehiculoActual.id;
    document.getElementById('docArchivoActual').textContent = '';
    $('#docListSection').hide();
    $('#docFormSection').show();
}

function ocultarFormDoc() {
    $('#docFormSection').hide();
    $('#docListSection').show();
}

function editarDocumento(id) {
    $.getJSON('ajax_vehiculos_documentos.php?action=list&id_vehiculo=' + docVehiculoActual.id, function (resp) {
        if (!resp.ok) return;
        const doc = resp.data.find(d => Number(d.id) === Number(id));
        if (!doc) return;

        mostrarFormDoc();
        document.getElementById('docId').value = doc.id;
        document.querySelector('#formDocumento [name="tipo"]').value              = doc.tipo;
        document.querySelector('#formDocumento [name="numero_documento"]').value  = doc.numero_documento || '';
        document.querySelector('#formDocumento [name="fecha_emision"]').value     = doc.fecha_emision || '';
        document.querySelector('#formDocumento [name="fecha_vencimiento"]').value = doc.fecha_vencimiento;
        document.querySelector('#formDocumento [name="observacion"]').value       = doc.observacion || '';

        if (doc.archivo) {
            document.getElementById('docArchivoActual').textContent =
                'Archivo actual: ' + doc.archivo + ' — sube uno nuevo para reemplazarlo';
        }
    });
}

function guardarDocumento() {
    const formData = new FormData(document.getElementById('formDocumento'));

    $.ajax({
        url: 'ajax_vehiculos_documentos.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        beforeSend() {
            $('#formDocumento button[type="submit"]')
                .prop('disabled', true)
                .html('<i class="fa-solid fa-spinner fa-spin me-1"></i> Guardando...');
        },
        success(resp) {
            if (!resp.ok) { alert(resp.msg || 'Error al guardar.'); return; }
            ocultarFormDoc();
            cargarDocumentos(docVehiculoActual.id);
            cargarAlertas();
        },
        error() { alert('Error inesperado al guardar el documento.'); },
        complete() {
            $('#formDocumento button[type="submit"]')
                .prop('disabled', false)
                .html('<i class="fa-solid fa-floppy-disk me-1"></i> Guardar');
        },
    });
}

function eliminarDocumento(id) {
    if (!confirm('¿Eliminar este documento?')) return;

    $.post('ajax_vehiculos_documentos.php', { action: 'delete', id }, function (resp) {
        if (!resp.ok) { alert(resp.msg); return; }
        cargarDocumentos(docVehiculoActual.id);
        cargarAlertas();
    }, 'json');
}

// ═══════════════════════════════════════════════
// MANTENCIONES
// ═══════════════════════════════════════════════
function verMantenciones(id, patente) {
    mantVehiculoActual = { id, patente };
    document.getElementById('mantPatente').textContent = patente;
    document.getElementById('mantIdVehiculo').value = id;
    ocultarFormMant();
    cargarMantenciones(id);
    modalMantenciones.show();
}

function cargarMantenciones(idVehiculo) {
    $('#mantLista').html('<p class="text-muted text-center py-3"><i class="fa-solid fa-spinner fa-spin me-1"></i> Cargando...</p>');

    $.getJSON('ajax_vehiculos_mantenciones.php?action=list&id_vehiculo=' + idVehiculo, function (resp) {
        if (!resp.ok) {
            $('#mantLista').html(`<p class="text-danger">${escapeHtml(resp.msg || 'Error.')}</p>`);
            return;
        }
        renderMantenciones(resp.data);
    }).fail(function () {
        $('#mantLista').html('<p class="text-danger">Error inesperado al cargar mantenciones.</p>');
    });
}

const TIPO_MANT_LABELS = {
    preventiva:  'Preventiva',
    correctiva:  'Correctiva',
    revision:    'Revisión General',
    neumaticos:  'Neumáticos',
    frenos:      'Frenos',
    aceite:      'Cambio Aceite',
    otro:        'Otro',
};

function renderMantenciones(mantenciones) {
    if (!mantenciones.length) {
        $('#mantLista').html('<p class="text-muted text-center py-4">No hay mantenciones registradas para este vehículo.</p>');
        return;
    }

    let html = `<div class="table-responsive">
        <table class="table align-middle">
            <thead><tr>
                <th>Tipo</th><th>Fecha</th><th>KM</th>
                <th>Proveedor</th><th>Costo</th><th>Descripción</th>
                <th class="text-end">Acciones</th>
            </tr></thead><tbody>`;

    mantenciones.forEach(m => {
        const costoFmt = m.costo
            ? '$' + Number(m.costo).toLocaleString('es-CL')
            : '—';
        html += `<tr>
            <td><span class="tipo-doc-label">${escapeHtml(TIPO_MANT_LABELS[m.tipo] || m.tipo)}</span></td>
            <td>${escapeHtml(m.fecha)}</td>
            <td>${m.km_en_mantencion ? Number(m.km_en_mantencion).toLocaleString('es-CL') + ' km' : '—'}</td>
            <td>${escapeHtml(m.proveedor || '—')}</td>
            <td>${escapeHtml(costoFmt)}</td>
            <td>
                <div class="report-cell-text" style="max-width:220px">
                    ${escapeHtml(m.descripcion || '—')}
                </div>
            </td>
            <td class="text-end">
                <button class="btn btn-sm btn-outline-dark" onclick="editarMantencion(${m.id})" title="Editar">
                    <i class="fa-solid fa-pen-to-square"></i>
                </button>
                <button class="btn btn-sm"
                        style="background:linear-gradient(135deg,#ef6f6c,#dc3545);color:#fff"
                        onclick="eliminarMantencion(${m.id})" title="Eliminar">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </td>
        </tr>`;
    });

    html += '</tbody></table></div>';
    $('#mantLista').html(html);
}

function mostrarFormMant() {
    document.getElementById('mantId').value = '';
    document.getElementById('formMantencion').reset();
    document.getElementById('mantIdVehiculo').value = mantVehiculoActual.id;

    const hoy = new Date();
    const y = hoy.getFullYear();
    const m = String(hoy.getMonth() + 1).padStart(2, '0');
    const d = String(hoy.getDate()).padStart(2, '0');
    document.querySelector('#formMantencion [name="fecha"]').value = `${y}-${m}-${d}`;

    $('#mantListSection').hide();
    $('#mantFormSection').show();
}

function ocultarFormMant() {
    $('#mantFormSection').hide();
    $('#mantListSection').show();
}

function editarMantencion(id) {
    $.getJSON('ajax_vehiculos_mantenciones.php?action=list&id_vehiculo=' + mantVehiculoActual.id, function (resp) {
        if (!resp.ok) return;
        const mant = resp.data.find(m => Number(m.id) === Number(id));
        if (!mant) return;

        mostrarFormMant();
        document.getElementById('mantId').value = mant.id;
        document.querySelector('#formMantencion [name="tipo"]').value              = mant.tipo;
        document.querySelector('#formMantencion [name="fecha"]').value             = mant.fecha;
        document.querySelector('#formMantencion [name="km_en_mantencion"]').value  = mant.km_en_mantencion || '';
        document.querySelector('#formMantencion [name="costo"]').value             = mant.costo || '';
        document.querySelector('#formMantencion [name="proveedor"]').value         = mant.proveedor || '';
        document.querySelector('#formMantencion [name="descripcion"]').value       = mant.descripcion || '';
    });
}

function guardarMantencion() {
    const formData = new FormData(document.getElementById('formMantencion'));

    $.ajax({
        url: 'ajax_vehiculos_mantenciones.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        beforeSend() {
            $('#formMantencion button[type="submit"]')
                .prop('disabled', true)
                .html('<i class="fa-solid fa-spinner fa-spin me-1"></i> Guardando...');
        },
        success(resp) {
            if (!resp.ok) { alert(resp.msg || 'Error al guardar.'); return; }
            ocultarFormMant();
            cargarMantenciones(mantVehiculoActual.id);
        },
        error() { alert('Error inesperado al guardar la mantención.'); },
        complete() {
            $('#formMantencion button[type="submit"]')
                .prop('disabled', false)
                .html('<i class="fa-solid fa-floppy-disk me-1"></i> Guardar');
        },
    });
}

function eliminarMantencion(id) {
    if (!confirm('¿Eliminar este registro de mantención?')) return;

    $.post('ajax_vehiculos_mantenciones.php', { action: 'delete', id }, function (resp) {
        if (!resp.ok) { alert(resp.msg); return; }
        cargarMantenciones(mantVehiculoActual.id);
    }, 'json');
}

function abrirModalDescargaExcel() {
    const hoy = new Date();
    const y   = hoy.getFullYear();
    const m   = String(hoy.getMonth() + 1).padStart(2, '0');
    const d   = String(hoy.getDate()).padStart(2, '0');

    document.getElementById('excelStartDate').value = `${y}-${m}-01`;
    document.getElementById('excelEndDate').value   = `${y}-${m}-${d}`;

    modalDescargaExcel.show();
}

/* =========================================================================
   INFORME "ESTATUS DEL VEHÍCULO" EN PANTALLA
   Mismo cálculo que el Excel (informes/lib_estatus_vehiculo.php). Existe para
   que los coordinadores consulten el dato en vivo en vez de trabajar todo el
   día sobre un Excel bajado en la mañana.
   ========================================================================= */
let modalEstatus = null;
let evDatos      = null;   // último payload recibido

function evEsc(s){
    return String(s ?? '').replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    })[c]);
}

function evClasePct(pct){
    if (pct >= 80) return 'ev-ok';
    if (pct >= 50) return 'ev-medio';
    return 'ev-malo';
}

function abrirModalEstatus(){
    if (!modalEstatus) {
        modalEstatus = new bootstrap.Modal(document.getElementById('modalEstatus'));
    }
    // Por defecto: mes en curso hasta hoy
    const hoy = new Date();
    const y = hoy.getFullYear();
    const m = String(hoy.getMonth() + 1).padStart(2, '0');
    const d = String(hoy.getDate()).padStart(2, '0');
    if (!document.getElementById('evStart').value) {
        document.getElementById('evStart').value = `${y}-${m}-01`;
        document.getElementById('evEnd').value   = `${y}-${m}-${d}`;
    }
    modalEstatus.show();
    if (!evDatos) cargarEstatus();
}

async function cargarEstatus(){
    const start = document.getElementById('evStart').value;
    const end   = document.getElementById('evEnd').value;
    // Evaluación siempre diaria: ya no hay selector de modo.

    if (!start || !end) { alert('Selecciona el rango de fechas.'); return; }
    if (start > end)    { alert('La fecha inicial no puede ser mayor a la final.'); return; }

    document.getElementById('evLoading').style.display = '';
    document.getElementById('evContenido').style.display = 'none';
    document.getElementById('evError').style.display = 'none';

    try {
        const params = new URLSearchParams({ start_date:start, end_date:end });
        const resp = await fetch('/visibility2/portal/modulos/mod_vehiculos/ajax_estatus_vehiculo.php?' + params,
                                 { credentials:'same-origin' });
        const txt = await resp.text();
        let r;
        try { r = JSON.parse(txt); }
        catch(e){ throw new Error('Respuesta inesperada del servidor (HTTP ' + resp.status + ')'); }

        if (!r.ok) throw new Error(r.message || 'No se pudo generar el informe.');

        evDatos = r;
        evPintar(r);
        document.getElementById('evContenido').style.display = '';
    } catch (e) {
        document.getElementById('evErrorMsg').textContent = e.message || String(e);
        document.getElementById('evError').style.display = '';
    } finally {
        document.getElementById('evLoading').style.display = 'none';
    }
}

function evPintar(r){
    // Cabecera
    document.getElementById('evMeta').innerHTML =
        '<strong>' + evEsc(r.meta.periodo) + '</strong> · ' + evEsc(r.meta.modo_label) +
        ' · <span class="text-muted">Datos al ' + evEsc(r.meta.generado) + '</span>';

    // KPIs
    const k = r.kpis;
    document.getElementById('evKpis').innerHTML = `
        <div class="ev-kpi"><span>Ejecutores</span><strong>${k.ejecutores}</strong></div>
        <div class="ev-kpi"><span>Cumplimiento promedio</span>
            <strong class="${evClasePct(k.cumplimiento_prom)}">${k.cumplimiento_prom}%</strong></div>
        <div class="ev-kpi"><span>Subidas esperadas</span><strong>${k.subidas_esperadas}</strong></div>
        <div class="ev-kpi"><span>Sin ninguna subida</span>
            <strong class="${k.sin_subidas ? 'ev-malo' : ''}">${k.sin_subidas}</strong></div>
        <div class="ev-kpi"><span>Con fotos duplicadas</span>
            <strong class="${k.con_duplicados ? 'ev-malo' : ''}">${k.con_duplicados}</strong></div>
        <div class="ev-kpi"><span>Fotos subidas</span><strong>${k.total_fotos}</strong></div>`;

    /* --- Resumen --- */
    let h = '';
    if (!r.resumen.length) {
        h = '<tr><td colspan="11" class="text-center text-muted py-4">Sin ejecutores para el período.</td></tr>';
    } else {
        r.resumen.forEach(u => {
            h += `<tr>
                <td>${evEsc(u.usuario)}</td>
                <td>${evEsc(u.rut)}</td>
                <td>${evEsc(u.nombre)}</td>
                <td><span class="vehicle-plate">${evEsc(u.patente)}</span></td>
                <td>${evEsc(u.division)}</td>
                <td class="text-center">${u.expected}</td>
                <td class="text-center">${u.complied}</td>
                <td class="text-center">${u.missed}</td>
                <td class="text-center"><span class="ev-pct ${evClasePct(u.pct)}">${u.pct}%</span></td>
                <td class="text-center">${u.has_dups
                    ? '<span class="ev-pct ev-malo">Sí</span>' : 'No'}</td>
                <td class="text-center">${u.dias_patente_distinta > 0
                    ? '<span class="ev-pct ev-malo">' + u.dias_patente_distinta + '</span>'
                    : '0'}</td>
            </tr>`;
        });
    }
    document.getElementById('evTbodyResumen').innerHTML = h;

    /* --- Matriz de cumplimiento --- */
    let th = '<tr><th>Usuario</th><th>Nombre</th><th>Patente</th><th>División</th>';
    r.matriz.cols.forEach(c => { th += '<th class="ev-col-dia">' + evEsc(c.label) + '</th>'; });
    th += '<th>%</th></tr>';
    document.getElementById('evTheadMatriz').innerHTML = th;

    let hm = '';
    if (!r.matriz.filas.length) {
        hm = '<tr><td class="text-center text-muted py-4">Sin datos.</td></tr>';
    } else {
        r.matriz.filas.forEach(f => {
            hm += `<tr><td>${evEsc(f.usuario)}</td><td>${evEsc(f.nombre)}</td>
                       <td><span class="vehicle-plate">${evEsc(f.patente)}</span></td>
                       <td>${evEsc(f.division)}</td>`;
            f.celdas.forEach(ok => {
                hm += ok ? '<td class="ev-cel ev-cel-ok">✓</td>' : '<td class="ev-cel ev-cel-no">✗</td>';
            });
            hm += `<td class="text-center"><span class="ev-pct ${evClasePct(f.pct)}">${f.pct}%</span></td></tr>`;
        });
    }
    document.getElementById('evTbodyMatriz').innerHTML = hm;

    /* --- Detalle de subidas --- */
    let hd = '';
    if (!r.detalle.length) {
        hd = '<tr><td colspan="12" class="text-center text-muted py-4">Sin subidas en el período.</td></tr>';
    } else {
        r.detalle.forEach(d => {
            const extra = d.respuestas
                .filter(x => x.valor !== '—')
                .map(x => evEsc(x.pregunta) + ': <strong>' + evEsc(x.valor) + '</strong>')
                .join('<br>');
            let coincide = '—';
            if (d.coincide === true)  coincide = '<span class="ev-pct ev-ok">✓</span>';
            if (d.coincide === false) coincide = '<span class="ev-pct ev-malo">✗</span>';

            hd += `<tr class="${d.esperado ? '' : 'ev-fila-noesperada'}">
                <td>${evEsc(d.usuario)}</td>
                <td>${evEsc(d.nombre)}</td>
                <td>${evEsc(d.fecha)}</td>
                <td>${evEsc(d.dia)}</td>
                <td><span class="vehicle-plate">${evEsc(d.patente)}</span></td>
                <td>${evEsc(d.division)}</td>
                <td>${evEsc(d.primera)}</td>
                <td>${evEsc(d.ultima)}</td>
                <td class="text-center">${d.fotos}</td>
                <td class="text-center">${d.esperado ? 'Sí' : 'No'}</td>
                <td>${evEsc(d.patente_ing)} ${coincide}</td>
                <td style="font-size:12px">${extra || '—'}</td>
            </tr>`;
        });
    }
    document.getElementById('evTbodyDetalle').innerHTML = hd;

    /* --- Duplicadas --- */
    let hx = '';
    if (!r.duplicadas.length) {
        hx = '<tr><td colspan="9" class="text-center text-muted py-4">Sin fotos duplicadas detectadas en el período.</td></tr>';
    } else {
        r.duplicadas.forEach(x => {
            hx += `<tr>
                <td>${evEsc(x.usuario)}</td>
                <td>${evEsc(x.nombre)}</td>
                <td>${evEsc(x.division)}</td>
                <td style="font-family:monospace;font-size:12px">${evEsc(x.sha1)}</td>
                <td class="text-center">${x.subidas}</td>
                <td class="text-center"><span class="ev-pct ev-malo">${x.dias}</span></td>
                <td style="font-size:12px">${evEsc(x.fechas)}</td>
                <td>${evEsc(x.primera)}</td>
                <td>${evEsc(x.ultima)}</td>
            </tr>`;
        });
    }
    document.getElementById('evTbodyDup').innerHTML = hx;

    document.getElementById('evTabDupCount').textContent = r.duplicadas.length;

    // Los datos cambiaron: se reconstruyen los filtros de columna con los
    // valores nuevos y se reaplica el buscador general.
    evInitFiltrosCol();
    evAplicarFiltros();
}

/* =========================================================================
   FILTROS POR COLUMNA (estilo Excel)
   Cada encabezado abre un desplegable con TODOS los valores distintos de esa
   columna. Se combinan así: dentro de una columna, los valores marcados suman
   (OR); entre columnas distintas, se acumulan (AND). El buscador general se
   aplica encima de eso.

   Los valores se leen del DOM ya renderizado, así funciona igual en las cuatro
   pestañas sin depender de la forma de cada payload — incluida la matriz, cuyas
   columnas son dinámicas (una por día).
   ========================================================================= */
const evFiltrosCol = {};      // { idTabla: { indiceColumna: Set(valores) } }
let   evPanelAbierto = null;

function evTextoCelda(td){
    const t = (td?.textContent || '').replace(/\s+/g, ' ').trim();
    return t === '' ? '(vacío)' : t;
}

/** Reconstruye los botones de filtro. Se llama tras cada render. */
function evInitFiltrosCol(){
    document.querySelectorAll('#modalEstatus table.ev-tabla').forEach(tabla => {
        const id = tabla.closest('.tab-pane')?.id || 'tabla';
        tabla.dataset.evId = id;
        evFiltrosCol[id] = {};      // los datos cambiaron: filtros previos ya no aplican

        const ths = tabla.tHead ? tabla.tHead.rows[0]?.cells : null;
        if (!ths) return;

        Array.from(ths).forEach((th, idx) => {
            const texto = th.textContent.replace(/\s+/g, ' ').trim();
            th.innerHTML = '<div class="ev-th-wrap"><span>' + evEsc(texto) +
                           '</span><i class="fa-solid fa-filter ev-fbtn" title="Filtrar columna"></i></div>';
            th.querySelector('.ev-fbtn').addEventListener('click', e => {
                e.stopPropagation();
                evAbrirPanelFiltro(tabla, idx, e.currentTarget);
            });
        });
    });
}

function evCerrarPanelFiltro(){
    if (evPanelAbierto) { evPanelAbierto.remove(); evPanelAbierto = null; }
}

function evAbrirPanelFiltro(tabla, idx, btn){
    const yaAbierto = evPanelAbierto && evPanelAbierto.dataset.col === String(idx)
                      && evPanelAbierto.dataset.tabla === tabla.dataset.evId;
    evCerrarPanelFiltro();
    if (yaAbierto) return;

    const id = tabla.dataset.evId;

    // Valores distintos: se leen de TODAS las filas, no solo de las visibles,
    // si no sería imposible volver a incluir un valor ya filtrado.
    const valores = new Set();
    Array.from(tabla.tBodies[0]?.rows || []).forEach(tr => {
        if (tr.classList.contains('ev-sin-filas')) return;
        if (tr.cells[idx]) valores.add(evTextoCelda(tr.cells[idx]));
    });

    const orden = Array.from(valores).sort((a, b) =>
        a.localeCompare(b, 'es', { numeric: true, sensitivity: 'base' }));

    const activos = evFiltrosCol[id]?.[idx] || null;   // null = sin filtro (todos)

    const panel = document.createElement('div');
    panel.className = 'ev-fpanel';
    panel.dataset.col = String(idx);
    panel.dataset.tabla = id;
    panel.innerHTML = `
        <div class="ev-fpanel-head">
            <input type="text" placeholder="Buscar valor..." class="ev-fbuscar">
        </div>
        <div class="ev-fpanel-list">
            <label class="ev-fitem ev-ftodos">
                <input type="checkbox" class="ev-ftodos-chk" ${!activos ? 'checked' : ''}>
                <span>(Seleccionar todo)</span>
            </label>
            ${orden.map((v, i) => `
                <label class="ev-fitem" data-val="${evEsc(v)}">
                    <input type="checkbox" value="${evEsc(v)}"
                        ${(!activos || activos.has(v)) ? 'checked' : ''}>
                    <span title="${evEsc(v)}">${evEsc(v)}</span>
                </label>`).join('')}
        </div>
        <div class="ev-fpanel-foot">
            <button type="button" class="ev-fbtn-off">Quitar filtro</button>
            <button type="button" class="ev-fbtn-ok">Aplicar</button>
        </div>`;

    document.body.appendChild(panel);
    evPanelAbierto = panel;

    // Posicionado respecto al botón; se corrige si se sale de la pantalla
    const r = btn.getBoundingClientRect();
    panel.style.top  = Math.min(r.bottom + 4, window.innerHeight - 350) + 'px';
    panel.style.left = Math.min(r.left, window.innerWidth - 270) + 'px';

    panel.addEventListener('click', e => e.stopPropagation());

    panel.querySelector('.ev-fbuscar').addEventListener('input', function(){
        const q = this.value.toLowerCase();
        panel.querySelectorAll('.ev-fitem[data-val]').forEach(it => {
            it.style.display = it.dataset.val.toLowerCase().includes(q) ? '' : 'none';
        });
    });

    panel.querySelector('.ev-ftodos-chk').addEventListener('change', function(){
        panel.querySelectorAll('.ev-fitem[data-val] input').forEach(chk => {
            if (chk.closest('.ev-fitem').style.display !== 'none') chk.checked = this.checked;
        });
    });

    panel.querySelector('.ev-fbtn-ok').addEventListener('click', () => {
        const sel = new Set();
        panel.querySelectorAll('.ev-fitem[data-val] input:checked').forEach(c => sel.add(c.value));

        if (!evFiltrosCol[id]) evFiltrosCol[id] = {};
        // Si quedaron todos marcados, es lo mismo que no filtrar
        if (sel.size === orden.length || sel.size === 0) delete evFiltrosCol[id][idx];
        else evFiltrosCol[id][idx] = sel;

        evCerrarPanelFiltro();
        evAplicarFiltros();
    });

    panel.querySelector('.ev-fbtn-off').addEventListener('click', () => {
        if (evFiltrosCol[id]) delete evFiltrosCol[id][idx];
        evCerrarPanelFiltro();
        evAplicarFiltros();
    });
}

document.addEventListener('click', evCerrarPanelFiltro);
document.addEventListener('keydown', e => { if (e.key === 'Escape') evCerrarPanelFiltro(); });

/** Aplica buscador general + filtros de columna sobre las cuatro tablas. */
function evAplicarFiltros(){
    const q = (document.getElementById('evBuscar')?.value || '').trim().toLowerCase();

    document.querySelectorAll('#modalEstatus table.ev-tabla').forEach(tabla => {
        const id      = tabla.dataset.evId || '';
        const filtros = evFiltrosCol[id] || {};
        const cols    = Object.keys(filtros);
        let visibles  = 0;

        Array.from(tabla.tBodies[0]?.rows || []).forEach(tr => {
            if (tr.classList.contains('ev-sin-filas')) { tr.remove(); return; }

            let ok = !q || tr.textContent.toLowerCase().includes(q);
            if (ok) {
                for (const c of cols) {
                    const celda = tr.cells[c];
                    if (!celda || !filtros[c].has(evTextoCelda(celda))) { ok = false; break; }
                }
            }
            tr.style.display = ok ? '' : 'none';
            if (ok) visibles++;
        });

        // Marca visualmente qué columnas están filtradas
        Array.from(tabla.tHead?.rows[0]?.cells || []).forEach((th, idx) => {
            th.querySelector('.ev-fbtn')?.classList.toggle('activo', !!filtros[idx]);
        });

        if (visibles === 0 && (tabla.tBodies[0]?.rows.length || 0) > 0) {
            const tr = tabla.tBodies[0].insertRow();
            tr.className = 'ev-sin-filas';
            const td = tr.insertCell();
            td.colSpan = tabla.tHead?.rows[0]?.cells.length || 1;
            td.textContent = 'Ningún registro coincide con los filtros aplicados.';
        }
    });
}

/* Buscador general (mantiene el nombre por el oninput del HTML) */
function evFiltrar(){ evAplicarFiltros(); }

/** Limpia todos los filtros de columna del informe. */
function evLimpiarFiltros(){
    Object.keys(evFiltrosCol).forEach(k => evFiltrosCol[k] = {});
    const b = document.getElementById('evBuscar');
    if (b) b.value = '';
    evAplicarFiltros();
}

/* Envía los mismos filtros de pantalla al generador de Excel */
function evDescargarExcel(){
    const f = document.getElementById('evFormExcel');
    document.getElementById('evExcelStart').value = document.getElementById('evStart').value;
    document.getElementById('evExcelEnd').value   = document.getElementById('evEnd').value;
    // (el modo ya no se envía: el informe es siempre diario)
    f.submit();
}
</script>

</body>
</html>
