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
    grid-template-columns: repeat(4, minmax(0, 1fr));
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
        
            <a href="mod_vehiculos_carga_masiva.php" class="btn btn-outline-light btn-main">
                <i class="fa-solid fa-file-arrow-up me-1"></i> Carga masiva
            </a>
        
            <button class="btn btn-light btn-main" onclick="abrirModalNuevo()">
                <i class="fa-solid fa-plus me-1"></i> Nuevo vehículo
            </button>
        </div>
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
                        <td colspan="11" class="text-center text-muted py-4">Cargando vehículos...</td>
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

let reporteVehiculos = [];
let reportePreguntas = [];

$(document).ready(function () {
    modalVehiculo = new bootstrap.Modal(document.getElementById('modalVehiculo'));
    modalHistorial = new bootstrap.Modal(document.getElementById('modalHistorial'));
    const modalReporteEl = document.getElementById('modalReporte');
    
    if (modalReporteEl) {
        modalReporte = new bootstrap.Modal(modalReporteEl);
    }

    cargarCatalogos();
    cargarVehiculos();

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
    const idDivision = $('#id_division').val();
    const idSubdivision = $('#id_subdivision').val();

    destruirSelect2Merchan();

    const merchans = catalogos.merchans.filter(u => {
        const matchDivision =
            !idDivision ||
            !u.id_division ||
            String(u.id_division) === String(idDivision);

        const matchSubdivision =
            !idSubdivision ||
            !u.id_subdivision ||
            String(u.id_subdivision) === String(idSubdivision);

        return matchDivision && matchSubdivision;
    });

    llenarSelect('#id_merchan', merchans, 'id', 'nombre_completo', selectedValue);

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

        html += `
            <tr>
                <td>
                    <span class="vehicle-plate">
                        <i class="fa-solid fa-car"></i>
                        ${escapeHtml(v.patente)}
                    </span>
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

</script>

</body>
</html>