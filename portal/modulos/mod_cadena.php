<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$profile = strtolower(trim((string)($_SESSION['perfil_nombre'] ?? '')));
$canEdit = in_array($profile, ['editor', 'coordinador', 'administrador', 'admin'], true);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Administración de cadenas y cuentas</title>
    <link rel="stylesheet" href="../plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="../dist/css/adminlte.min.css">
    <style>
        body { background:#f4f6f9; color:#263238; }
        .content-wrapper { margin-left:0 !important; min-height:100vh; background:#f4f6f9; }
        .page-shell { padding:22px; }
        .page-title { font-size:22px; font-weight:700; margin:0; }
        .page-subtitle { color:#6c757d; margin-top:4px; }
        .developer-pill { font-family:Consolas, monospace; font-size:12px; background:#edf2f7; color:#334155; border-radius:5px; padding:3px 7px; }
        .chain-table tbody tr { cursor:pointer; }
        .chain-table tbody tr:hover { background:#f1f7ff; }
        .chain-table tbody tr.selected { background:#dcecff; }
        .chain-name { font-weight:600; color:#1f2d3d; }
        .account-name { color:#667085; font-size:12px; }
        .detail-empty { min-height:390px; display:flex; align-items:center; justify-content:center; text-align:center; color:#8a94a3; }
        .asset-box { border:1px solid #dee2e6; border-radius:8px; min-height:105px; display:flex; align-items:center; justify-content:center; background:#fafbfc; overflow:hidden; }
        .asset-box img { max-width:100%; max-height:96px; object-fit:contain; }
        .asset-placeholder { color:#adb5bd; text-align:center; font-size:12px; }
        .asset-url { display:block; margin-top:5px; font-size:10px; color:#7b8794; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .sticky-detail { position:sticky; top:15px; }
        #loadingRows td { padding:45px 15px; }
        .locals-table tbody tr:hover { background:#f8fbff; }
        .locals-table td { vertical-align:middle; }
        .bulk-toolbar { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px; }
        .scope-button.active { box-shadow:0 0 0 2px rgba(0,123,255,.18); }
        @media (max-width:991px) { .sticky-detail { position:static; } }
    </style>
</head>
<body class="iframe-mode">
<div class="content-wrapper">
    <main class="page-shell">
        <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
            <div>
                <h1 class="page-title"><i class="fas fa-store-alt mr-2 text-primary"></i>Cadenas y cuentas</h1>
                <div class="page-subtitle">Administración de nombres, asociaciones e identificadores internos.</div>
            </div>
            <span id="totalBadge" class="badge badge-primary badge-pill px-3 py-2">0 cadenas</span>
        </div>

        <div id="alertArea"></div>

        <?php if (!$canEdit): ?>
            <div class="alert alert-info"><i class="fas fa-eye mr-1"></i> Tiene acceso de lectura. La edición está disponible para perfiles autorizados.</div>
        <?php endif; ?>

        <div class="row">
            <div class="col-lg-7">
                <div class="card card-outline card-primary">
                    <div class="card-header border-0">
                        <div class="input-group">
                            <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-search"></i></span></div>
                            <input id="searchInput" type="search" class="form-control" placeholder="Buscar por ID, cadena o cuenta..." autocomplete="off">
                            <div class="input-group-append"><button id="refreshButton" type="button" class="btn btn-outline-secondary" title="Actualizar"><i class="fas fa-sync-alt"></i></button></div>
                        </div>
                        <?php if ($canEdit): ?>
                        <div class="d-flex flex-wrap align-items-center justify-content-between mt-2">
                            <small class="text-muted"><span id="selectedChainsCount">0</span> cadenas vacías seleccionadas</small>
                            <button id="bulkDeleteChains" type="button" class="btn btn-sm btn-outline-danger" disabled>
                                <i class="fas fa-trash-alt mr-1"></i>Eliminar cadenas seleccionadas
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-body table-responsive p-0" style="max-height:70vh">
                        <table class="table table-hover table-sm chain-table mb-0">
                            <thead class="thead-light" style="position:sticky;top:0;z-index:2">
                                <tr>
                                    <th class="text-center" style="width:42px"><?php if ($canEdit): ?><input id="selectAllChains" type="checkbox" title="Seleccionar todas las cadenas vacías visibles"><?php endif; ?></th>
                                    <th style="width:80px">ID</th>
                                    <th>Cadena</th>
                                    <th style="width:90px">Cuenta ID</th>
                                    <th>Cuenta asociada</th>
                                    <th class="text-right" style="width:110px">Locales activos</th>
                                </tr>
                            </thead>
                            <tbody id="chainRows">
                                <tr id="loadingRows"><td colspan="6" class="text-center text-muted"><i class="fas fa-spinner fa-spin mr-2"></i>Cargando cadenas...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card card-outline card-secondary sticky-detail">
                    <div class="card-header"><h3 class="card-title"><i class="fas fa-code-branch mr-2"></i>Detalle seleccionado</h3></div>
                    <div id="emptyDetail" class="card-body detail-empty">
                        <div><i class="far fa-hand-pointer fa-3x mb-3"></i><br>Seleccione una cadena para revisar y editar sus datos.</div>
                    </div>
                    <div id="detailContent" class="card-body" style="display:none">
                        <section class="mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h5 class="mb-0">Cadena</h5>
                                <span>ID <span id="chainId" class="developer-pill"></span></span>
                            </div>
                            <form id="chainForm">
                                <input type="hidden" id="chainIdInput">
                                <div class="form-group">
                                    <label for="chainName">Nombre de cadena</label>
                                    <div class="input-group">
                                        <input id="chainName" class="form-control" maxlength="45" required <?= $canEdit ? '' : 'disabled' ?>>
                                        <?php if ($canEdit): ?><div class="input-group-append"><button class="btn btn-primary" type="submit"><i class="fas fa-save mr-1"></i>Guardar</button></div><?php endif; ?>
                                    </div>
                                </div>
                            </form>
                            <?php if ($canEdit): ?>
                            <div id="chainDeleteArea" class="border-top pt-3 mb-3" style="display:none">
                                <div class="d-flex flex-wrap align-items-center justify-content-between">
                                    <small id="chainDeleteHelp" class="text-muted mr-2"></small>
                                    <button id="deleteChainButton" type="button" class="btn btn-sm btn-outline-danger">
                                        <i class="fas fa-trash-alt mr-1"></i>Eliminar cadena definitivamente
                                    </button>
                                </div>
                            </div>
                            <?php endif; ?>
                            <div class="row">
                                <div class="col-6"><small class="text-muted">Logo actual</small><div id="chainLogo" class="asset-box mt-1"></div></div>
                                <div class="col-6"><small class="text-muted">Icono actual</small><div id="chainIcon" class="asset-box mt-1"></div></div>
                            </div>
                        </section>

                        <hr>

                        <section>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h5 class="mb-0">Cuenta asociada</h5>
                                <span>ID <span id="accountId" class="developer-pill"></span></span>
                            </div>
                            <form id="accountForm">
                                <input type="hidden" id="accountIdInput">
                                <div class="form-group">
                                    <label for="accountName">Nombre de cuenta</label>
                                    <div class="input-group">
                                        <input id="accountName" class="form-control" maxlength="45" required <?= $canEdit ? '' : 'disabled' ?>>
                                        <?php if ($canEdit): ?><div class="input-group-append"><button class="btn btn-secondary" type="submit"><i class="fas fa-save mr-1"></i>Guardar</button></div><?php endif; ?>
                                    </div>
                                    <small class="form-text text-muted">El cambio se reflejará en todas las cadenas asociadas a esta cuenta.</small>
                                </div>
                            </form>
                            <div class="row">
                                <div class="col-6"><small class="text-muted">Logo actual</small><div id="accountLogo" class="asset-box mt-1"></div></div>
                                <div class="col-6"><small class="text-muted">Icono actual</small><div id="accountIcon" class="asset-box mt-1"></div></div>
                            </div>
                        </section>
                    </div>
                </div>
            </div>
        </div>

        <div id="localsPanel" class="card card-outline card-info mt-2" style="display:none">
            <div class="card-header d-flex flex-wrap align-items-center justify-content-between">
                <h3 class="card-title mb-2 mb-md-0"><i class="fas fa-map-marker-alt mr-2"></i>Locales asociados a <strong id="localsSourceName"></strong></h3>
                <span id="localsTotal" class="badge badge-info badge-pill px-3 py-2">0 locales</span>
            </div>
            <div class="card-body">
                <div class="d-flex flex-wrap align-items-center mb-3" style="gap:8px">
                    <span class="text-muted mr-1">Alcance:</span>
                    <button type="button" class="btn btn-sm btn-outline-primary scope-button active" data-scope="id">Sólo este ID de cadena</button>
                    <button type="button" class="btn btn-sm btn-outline-primary scope-button" data-scope="name">Todos los IDs con el mismo nombre</button>
                    <small id="scopeHelp" class="text-muted ml-1"></small>
                </div>

                <?php if ($canEdit): ?>
                <div class="bulk-toolbar mb-3">
                    <div class="row align-items-end">
                        <div class="col-lg-7">
                            <label for="targetChain" class="mb-1">Cadena destino</label>
                            <select id="targetChain" class="form-control">
                                <option value="">-- Seleccione cadena destino --</option>
                            </select>
                            <small class="text-muted">La cuenta destino se obtiene automáticamente desde la cadena elegida.</small>
                        </div>
                        <div class="col-lg-5 mt-2 mt-lg-0 text-lg-right">
                            <button id="updateSelected" type="button" class="btn btn-warning"><i class="fas fa-check-square mr-1"></i>Actualizar seleccionados</button>
                            <button id="updateAll" type="button" class="btn btn-danger"><i class="fas fa-layer-group mr-1"></i>Actualizar todo el alcance</button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div id="localsLimitWarning" class="alert alert-warning" style="display:none"></div>
                <div class="table-responsive" style="max-height:58vh">
                    <table class="table table-sm table-bordered locals-table mb-0">
                        <thead class="thead-light" style="position:sticky;top:0;z-index:2">
                            <tr>
                                <th class="text-center" style="width:42px"><input id="selectAllLocals" type="checkbox" title="Seleccionar todos los visibles"></th>
                                <th style="width:80px">Local ID</th>
                                <th style="width:100px">Código</th>
                                <th>Local</th>
                                <th style="width:85px">Cadena ID</th>
                                <th>Cadena actual</th>
                                <th style="width:85px">Cuenta ID</th>
                                <th>Cuenta actual</th>
                                <th style="width:80px">Empresa</th>
                                <th style="width:80px">División</th>
                            </tr>
                        </thead>
                        <tbody id="localRows"><tr><td colspan="10" class="text-center text-muted py-4">Seleccione una cadena.</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>

<script src="../plugins/jquery/jquery.min.js"></script>
<script src="../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../dist/js/adminlte.min.js"></script>
<script>
(function () {
    'use strict';

    const csrfToken = <?= json_encode($_SESSION['csrf_token']) ?>;
    const canEdit = <?= json_encode($canEdit) ?>;
    let rows = [];
    let localRows = [];
    let selectedId = null;
    let currentScope = 'id';
    let searchTimer = null;

    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function showAlert(type, message) {
        $('#alertArea').html('<div class="alert alert-' + type + ' alert-dismissible fade show">' + escapeHtml(message) + '<button type="button" class="close" data-dismiss="alert">&times;</button></div>');
    }

    function assetHtml(url, label) {
        if (!url) return '<div class="asset-placeholder"><i class="far fa-image fa-2x mb-1"></i><br>Sin ' + label + '</div>';
        const safe = escapeHtml(url);
        return '<div class="w-100 text-center"><img src="' + safe + '" alt="' + label + '"><span class="asset-url" title="' + safe + '">' + safe + '</span></div>';
    }

    function renderRows() {
        if (!rows.length) {
            $('#chainRows').html('<tr><td colspan="6" class="text-center text-muted py-5">No se encontraron cadenas.</td></tr>');
            updateChainSelectionCount();
            return;
        }
        const html = rows.map(function (row) {
            const selected = Number(row.id) === Number(selectedId) ? ' selected' : '';
            const deletable = Number(row.locales_referencias || 0) === 0;
            return '<tr class="chain-row' + selected + '" data-id="' + Number(row.id) + '">' +
                '<td class="text-center">' + (canEdit && deletable
                    ? '<input type="checkbox" class="chain-delete-check" value="' + Number(row.id) + '" title="Seleccionar para eliminar">'
                    : '<span class="text-muted" title="Tiene referencias">—</span>') + '</td>' +
                '<td><span class="developer-pill">' + Number(row.id) + '</span></td>' +
                '<td><div class="chain-name">' + escapeHtml(row.nombre) + '</div></td>' +
                '<td>' + (row.id_cuenta ? '<span class="developer-pill">' + Number(row.id_cuenta) + '</span>' : '—') + '</td>' +
                '<td><div class="account-name">' + escapeHtml(row.cuenta_nombre || 'Cuenta no encontrada') + '</div></td>' +
                '<td class="text-right"><span class="badge badge-' + (Number(row.locales_total) > 0 ? 'info' : 'light') + '">' + Number(row.locales_total || 0) + '</span></td>' +
            '</tr>';
        }).join('');
        $('#chainRows').html(html);
        updateChainSelectionCount();
    }

    function updateChainSelectionCount() {
        const count = $('.chain-delete-check:checked').length;
        $('#selectedChainsCount').text(count);
        $('#bulkDeleteChains').prop('disabled', count === 0);
        const available = $('.chain-delete-check').length;
        $('#selectAllChains').prop('checked', available > 0 && count === available);
    }

    function selectRow(id) {
        const row = rows.find(function (item) { return Number(item.id) === Number(id); });
        if (!row) return;
        selectedId = Number(row.id);
        renderRows();
        $('#emptyDetail').hide();
        $('#detailContent').show();
        $('#chainId').text(row.id);
        $('#chainIdInput').val(row.id);
        $('#chainName').val(row.nombre);
        $('#accountId').text(row.id_cuenta || '—');
        $('#accountIdInput').val(row.id_cuenta || '');
        $('#accountName').val(row.cuenta_nombre || '').prop('disabled', !canEdit || !row.id_cuenta);
        $('#chainLogo').html(assetHtml(row.logo_url, 'logo'));
        $('#chainIcon').html(assetHtml(row.icono_url, 'icono'));
        $('#accountLogo').html(assetHtml(row.cuenta_logo_url, 'logo'));
        $('#accountIcon').html(assetHtml(row.cuenta_icono_url, 'icono'));
        const references = Number(row.locales_referencias || 0);
        if (canEdit) {
            $('#chainDeleteArea').show();
            if (references === 0) {
                $('#deleteChainButton').show().prop('disabled', false);
                $('#chainDeleteHelp').text('Sin referencias en local. Se permite eliminación física.');
            } else {
                $('#deleteChainButton').hide();
                const active = Number(row.locales_total || 0);
                const historical = Math.max(0, references - active);
                $('#chainDeleteHelp').text('No eliminable: ' + references + ' referencias totales' + (historical ? ' (' + historical + ' históricas)' : '') + '.');
            }
        }
        $('#localsPanel').show();
        loadLocals();
    }

    function loadCatalog() {
        $.getJSON('mod_cadena/api.php', { action: 'catalog' }).done(function (response) {
            if (!response.ok) return;
            const options = (response.data || []).map(function (item) {
                const label = (item.cuenta_nombre || 'Cuenta no encontrada') + ' [cuenta ' + (item.id_cuenta || '—') + '] → ' + item.nombre + ' [cadena ' + item.id + ']';
                return '<option value="' + Number(item.id) + '">' + escapeHtml(label) + '</option>';
            }).join('');
            $('#targetChain').html('<option value="">-- Seleccione cadena destino --</option>' + options);
        });
    }

    function renderLocalRows() {
        $('#selectAllLocals').prop('checked', false);
        if (!localRows.length) {
            $('#localRows').html('<tr><td colspan="10" class="text-center text-muted py-4">No hay locales asociados en este alcance.</td></tr>');
            return;
        }
        const html = localRows.map(function (local) {
            return '<tr>' +
                '<td class="text-center"><input type="checkbox" class="local-check" value="' + Number(local.id) + '"></td>' +
                '<td><span class="developer-pill">' + Number(local.id) + '</span></td>' +
                '<td>' + escapeHtml(local.codigo) + '</td>' +
                '<td><strong>' + escapeHtml(local.nombre) + '</strong><br><small class="text-muted">' + escapeHtml(local.direccion || '') + '</small></td>' +
                '<td><span class="developer-pill">' + Number(local.id_cadena) + '</span></td>' +
                '<td>' + escapeHtml(local.cadena_nombre) + '</td>' +
                '<td><span class="developer-pill">' + Number(local.id_cuenta) + '</span></td>' +
                '<td>' + escapeHtml(local.cuenta_nombre || 'Cuenta no encontrada') + '</td>' +
                '<td><span class="developer-pill">' + Number(local.id_empresa || 0) + '</span></td>' +
                '<td><span class="developer-pill">' + Number(local.id_division || 0) + '</span></td>' +
            '</tr>';
        }).join('');
        $('#localRows').html(html);
    }

    function loadLocals() {
        if (!selectedId) return;
        const selected = rows.find(function (item) { return Number(item.id) === Number(selectedId); });
        $('#localsSourceName').text(selected ? selected.nombre : '');
        $('#scopeHelp').text(currentScope === 'name'
            ? 'Incluye todos los registros de cadena cuyo nombre coincida, aunque tengan IDs o cuentas distintas.'
            : 'Trabaja únicamente con cadena.id = ' + selectedId + '.');
        $('#localRows').html('<tr><td colspan="10" class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin mr-2"></i>Cargando locales...</td></tr>');
        $.getJSON('mod_cadena/api.php', { action: 'locals', chain_id: selectedId, scope: currentScope })
            .done(function (response) {
                if (!response.ok) { showAlert('danger', response.message || 'No se pudieron cargar los locales.'); return; }
                localRows = response.data || [];
                $('#localsTotal').text(response.total + (response.total === 1 ? ' local' : ' locales'));
                if (response.total > response.shown) {
                    $('#localsLimitWarning').text('Se muestran los primeros ' + response.shown + ' de ' + response.total + ' locales. La opción “todo el alcance” igualmente procesa los ' + response.total + '.').show();
                } else {
                    $('#localsLimitWarning').hide();
                }
                renderLocalRows();
            })
            .fail(function (xhr) {
                const message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'No se pudieron cargar los locales.';
                showAlert('danger', message);
            });
    }

    function loadRows(keepSelection) {
        $('#refreshButton i').addClass('fa-spin');
        $.getJSON('mod_cadena/api.php', { action: 'list', search: $('#searchInput').val().trim() })
            .done(function (response) {
                if (!response.ok) { showAlert('danger', response.message || 'No se pudo cargar el listado.'); return; }
                rows = response.data || [];
                $('#totalBadge').text(response.total + (response.total === 1 ? ' cadena' : ' cadenas'));
                renderRows();
                if (keepSelection && selectedId) selectRow(selectedId);
            })
            .fail(function (xhr) {
                const message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Error al comunicarse con el servidor.';
                showAlert('danger', message);
                $('#chainRows').html('<tr><td colspan="6" class="text-center text-danger py-5">No se pudo cargar la información.</td></tr>');
            })
            .always(function () { $('#refreshButton i').removeClass('fa-spin'); });
    }

    function updateName(action, id, name, button) {
        button.prop('disabled', true);
        return $.ajax({
            url: 'mod_cadena/api.php',
            method: 'POST',
            dataType: 'json',
            data: { action: action, id: id, nombre: name, csrf_token: csrfToken }
        }).done(function (response) {
            if (!response.ok) { showAlert('danger', response.message || 'No se pudo guardar.'); return; }
            showAlert('success', response.message);
            loadRows(true);
        }).fail(function (xhr) {
            const message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'No se pudo guardar el cambio.';
            showAlert('danger', message);
        }).always(function () { button.prop('disabled', false); });
    }

    function bulkReassign(applyAll) {
        const targetChainId = Number($('#targetChain').val());
        if (!targetChainId) {
            showAlert('warning', 'Seleccione una cadena destino.');
            return;
        }

        const selectedIds = $('.local-check:checked').map(function () { return Number(this.value); }).get();
        if (!applyAll && !selectedIds.length) {
            showAlert('warning', 'Seleccione al menos un local.');
            return;
        }

        const targetLabel = $('#targetChain option:selected').text();
        const quantity = applyAll ? $('#localsTotal').text() : selectedIds.length + ' locales seleccionados';
        const warning = 'Se actualizarán ' + quantity + '.\n\nDestino: ' + targetLabel + '\n\nSe cambiarán juntos id_cadena e id_cuenta. ¿Desea continuar?';
        if (!window.confirm(warning)) return;

        $('#updateSelected, #updateAll').prop('disabled', true);
        $.ajax({
            url: 'mod_cadena/api.php',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'bulk_reassign',
                csrf_token: csrfToken,
                source_chain_id: selectedId,
                target_chain_id: targetChainId,
                scope: currentScope,
                apply_all: applyAll ? '1' : '0',
                local_ids: JSON.stringify(selectedIds)
            }
        }).done(function (response) {
            if (!response.ok) { showAlert('danger', response.message || 'No se pudo completar la actualización.'); return; }
            showAlert('success', response.message + ' Destino: ' + response.target_account + ' → ' + response.target_chain + '.');
            loadRows(true);
        }).fail(function (xhr) {
            const message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'No se pudo completar la actualización masiva.';
            showAlert('danger', message);
        }).always(function () {
            $('#updateSelected, #updateAll').prop('disabled', false);
        });
    }

    function deleteSelectedChain() {
        const row = rows.find(function (item) { return Number(item.id) === Number(selectedId); });
        if (!row || Number(row.locales_referencias || 0) !== 0) {
            showAlert('warning', 'La cadena tiene referencias y no puede eliminarse.');
            return;
        }

        const typedId = window.prompt(
            'Esta operación ejecutará DELETE definitivo sobre la cadena “' + row.nombre + '”.\n' +
            'La cuenta asociada no será eliminada.\n\nEscriba el ID ' + row.id + ' para confirmar:'
        );
        if (typedId === null) return;
        if (String(typedId).trim() !== String(row.id)) {
            showAlert('warning', 'El ID ingresado no coincide. No se eliminó la cadena.');
            return;
        }

        $('#deleteChainButton').prop('disabled', true);
        $.ajax({
            url: 'mod_cadena/api.php',
            method: 'POST',
            dataType: 'json',
            data: { action: 'delete_chain', id: row.id, csrf_token: csrfToken }
        }).done(function (response) {
            if (!response.ok) { showAlert('danger', response.message || 'No se pudo eliminar la cadena.'); return; }
            showAlert('success', response.message);
            selectedId = null;
            localRows = [];
            $('#detailContent, #localsPanel').hide();
            $('#emptyDetail').show();
            loadCatalog();
            loadRows(false);
        }).fail(function (xhr) {
            const message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'No se pudo eliminar la cadena.';
            showAlert('danger', message);
        }).always(function () {
            $('#deleteChainButton').prop('disabled', false);
        });
    }

    function bulkDeleteSelectedChains() {
        const ids = $('.chain-delete-check:checked').map(function () { return Number(this.value); }).get();
        if (!ids.length) {
            showAlert('warning', 'Seleccione al menos una cadena vacía.');
            return;
        }

        const confirmationText = 'ELIMINAR ' + ids.length;
        const typed = window.prompt(
            'Se ejecutará DELETE definitivo sobre ' + ids.length + ' cadenas.\n' +
            'Las cuentas asociadas no serán eliminadas.\n' +
            'Si una cadena tiene cualquier referencia, se cancelará el lote completo.\n\n' +
            'Escriba “' + confirmationText + '” para confirmar:'
        );
        if (typed === null) return;
        if (String(typed).trim().toUpperCase() !== confirmationText) {
            showAlert('warning', 'El texto de confirmación no coincide. No se eliminó ninguna cadena.');
            return;
        }

        $('#bulkDeleteChains').prop('disabled', true);
        $.ajax({
            url: 'mod_cadena/api.php',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'bulk_delete_chains',
                ids: JSON.stringify(ids),
                csrf_token: csrfToken
            }
        }).done(function (response) {
            if (!response.ok) { showAlert('danger', response.message || 'No se pudieron eliminar las cadenas.'); return; }
            showAlert('success', response.message);
            if (ids.indexOf(Number(selectedId)) !== -1) {
                selectedId = null;
                localRows = [];
                $('#detailContent, #localsPanel').hide();
                $('#emptyDetail').show();
            }
            loadCatalog();
            loadRows(false);
        }).fail(function (xhr) {
            const message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'No se pudieron eliminar las cadenas.';
            showAlert('danger', message);
        }).always(function () {
            updateChainSelectionCount();
        });
    }

    $('#chainRows').on('click', '.chain-row', function () { selectRow($(this).data('id')); });
    $('#chainRows').on('click', '.chain-delete-check', function (event) { event.stopPropagation(); });
    $('#chainRows').on('change', '.chain-delete-check', updateChainSelectionCount);
    $('#selectAllChains').on('change', function () {
        $('.chain-delete-check').prop('checked', this.checked);
        updateChainSelectionCount();
    });
    $('#bulkDeleteChains').on('click', bulkDeleteSelectedChains);
    $('#refreshButton').on('click', function () { loadRows(true); });
    $('.scope-button').on('click', function () {
        currentScope = $(this).data('scope');
        $('.scope-button').removeClass('active');
        $(this).addClass('active');
        loadLocals();
    });
    $('#selectAllLocals').on('change', function () { $('.local-check').prop('checked', this.checked); });
    $('#updateSelected').on('click', function () { bulkReassign(false); });
    $('#updateAll').on('click', function () { bulkReassign(true); });
    $('#deleteChainButton').on('click', deleteSelectedChain);
    $('#searchInput').on('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () {
            selectedId = null;
            localRows = [];
            $('#localsPanel, #detailContent').hide();
            $('#emptyDetail').show();
            loadRows(false);
        }, 250);
    });
    $('#chainForm').on('submit', function (event) {
        event.preventDefault();
        if (!canEdit) return;
        updateName('update_chain', Number($('#chainIdInput').val()), $('#chainName').val().trim(), $(this).find('button[type="submit"]'));
    });
    $('#accountForm').on('submit', function (event) {
        event.preventDefault();
        if (!canEdit) return;
        updateName('update_account', Number($('#accountIdInput').val()), $('#accountName').val().trim(), $(this).find('button[type="submit"]'));
    });

    loadCatalog();
    loadRows(false);
}());
</script>
</body>
</html>
