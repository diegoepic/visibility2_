<?php
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}

require_once __DIR__ . '/con_.php';

$usuario_id = intval($_SESSION['usuario_id']);
$empresa_id = intval($_SESSION['empresa_id']);
$nombre     = htmlspecialchars(trim(($_SESSION['usuario_nombre'] ?? '') . ' ' . ($_SESSION['usuario_apellido'] ?? '')), ENT_QUOTES, 'UTF-8');

$id_formulario = intval($_GET['id_formulario'] ?? 0);
if ($id_formulario <= 0) {
    http_response_code(400);
    exit('Campaña no especificada.');
}

/* ── Verificar que la campaña existe, pertenece a la empresa,
       es tipo=1 y tiene modalidad de implementación ── */
$stmtF = $conn->prepare("
    SELECT f.id, f.nombre, f.modalidad, f.fechaInicio, f.fechaTermino, f.id_division
    FROM formulario f
    WHERE f.id         = ?
      AND f.id_empresa = ?
      AND f.tipo       = 1
      AND f.modalidad  IN ('implementacion_auditoria','solo_implementacion')
      AND f.deleted_at IS NULL
    LIMIT 1
");
$stmtF->bind_param('ii', $id_formulario, $empresa_id);
$stmtF->execute();
$campana = $stmtF->get_result()->fetch_assoc();
$stmtF->close();

if (!$campana) {
    http_response_code(404);
    exit('Campaña no encontrada o sin acceso.');
}

$id_division = (int)$campana['id_division'];

/* ── Verificar que el ejecutor tiene preguntas asignadas en esta campaña ── */
$stmtPerm = $conn->prepare("
    SELECT COUNT(*) AS cnt
    FROM formularioQuestion
    WHERE id_formulario = ? AND id_usuario = ?
    LIMIT 1
");
$stmtPerm->bind_param('ii', $id_formulario, $usuario_id);
$stmtPerm->execute();
$permRow = $stmtPerm->get_result()->fetch_assoc();
$stmtPerm->close();

if ((int)$permRow['cnt'] === 0) {
    http_response_code(403);
    exit('No tienes locales asignados en esta campaña.');
}

/* ── Materiales únicos con cantidad propuesta total ── */
$stmtM = $conn->prepare("
    SELECT
        MIN(fq.id)              AS id_formularioQuestion,
        fq.material             AS nombre_material,
        m.id                    AS id_material,
        m.ref_image,
        SUM(fq.valor_propuesto) AS cantidad_propuesta
    FROM formularioQuestion fq
    LEFT JOIN material m ON m.nombre = fq.material AND m.id_division = ?
    WHERE fq.id_formulario = ?
      AND fq.id_usuario    = ?
      AND fq.material IS NOT NULL
      AND TRIM(fq.material) != ''
    GROUP BY fq.material, m.id, m.ref_image
    HAVING SUM(fq.valor_propuesto) > 0
    ORDER BY fq.material
");
$stmtM->bind_param('iii', $id_division, $id_formulario, $usuario_id);
$stmtM->execute();
$materiales = $stmtM->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtM->close();

/* ── Historial de recepciones previas de este ejecutor ── */
$stmtH = $conn->prepare("
    SELECT
        mr.id,
        mr.fecha_recepcion,
        mr.numero_guia,
        mr.foto_guia_url,
        mr.observacion,
        mr.created_at,
        GROUP_CONCAT(
            CONCAT(mrd.nombre_material, ' (', mrd.cantidad_recibida, ')')
            ORDER BY mrd.nombre_material
            SEPARATOR ', '
        ) AS resumen_materiales
    FROM material_recepcion mr
    LEFT JOIN material_recepcion_detalle mrd ON mrd.id_recepcion = mr.id
    WHERE mr.id_formulario = ? AND mr.id_usuario = ?
    GROUP BY mr.id
    ORDER BY mr.fecha_recepcion DESC, mr.created_at DESC
");
$stmtH->bind_param('ii', $id_formulario, $usuario_id);
$stmtH->execute();
$historial = $stmtH->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtH->close();

$csrf = $_SESSION['csrf_token'] ?? '';
$nombreCampana  = htmlspecialchars($campana['nombre'], ENT_QUOTES, 'UTF-8');
$fechaInicio    = $campana['fechaInicio'] ? date('d/m/Y', strtotime($campana['fechaInicio'])) : '—';
$fechaTermino   = $campana['fechaTermino'] ? date('d/m/Y', strtotime($campana['fechaTermino'])) : '—';
$tieneHist      = count($historial) > 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>Recepción de Materiales</title>
    <link rel="stylesheet" href="assets/plugins/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/plugins/font-awesome/css/font-awesome.min.css">
    <link rel="stylesheet" href="assets/css/main.css">
    <style>
        body { background: #f5f7fa; font-family: 'Open Sans', sans-serif; }
        .page-header-bar {
            background: #217346;
            color: #fff;
            padding: 14px 16px 10px;
            display: flex;
            align-items: center;
            gap: 10px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 6px rgba(0,0,0,.25);
        }
        .page-header-bar a { color: #c8ecd4; }
        .page-header-bar h1 { font-size: 16px; margin: 0; flex: 1; font-weight: 600; }
        .content-wrap { max-width: 640px; margin: 0 auto; padding: 16px; }
        .card-block {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,.1);
            padding: 16px;
            margin-bottom: 16px;
        }
        .card-block h3 { margin-top: 0; font-size: 14px; color: #555; text-transform: uppercase; letter-spacing: .5px; }
        .campana-meta { font-size: 13px; color: #666; margin-bottom: 4px; }
        .mat-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .mat-row:last-child { border-bottom: none; }
        .mat-thumb {
            width: 48px; height: 48px;
            object-fit: cover;
            border-radius: 6px;
            background: #eee;
            flex-shrink: 0;
        }
        .mat-thumb-placeholder {
            width: 48px; height: 48px;
            border-radius: 6px;
            background: #e8f5ee;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #217346;
            font-size: 20px;
            flex-shrink: 0;
        }
        .mat-info { flex: 1; }
        .mat-name { font-size: 14px; font-weight: 600; color: #333; }
        .mat-prop { font-size: 12px; color: #888; margin-top: 2px; }
        .mat-input { width: 90px; text-align: center; }
        .foto-grid { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
        .foto-thumb-wrap { position: relative; width: 90px; height: 90px; }
        .foto-thumb-wrap img { width: 90px; height: 90px; object-fit: cover; border-radius: 8px; border: 2px solid #217346; }
        .btn-submit {
            background: #217346;
            color: #fff;
            border: none;
            padding: 14px;
            font-size: 16px;
            font-weight: 600;
            border-radius: 8px;
            width: 100%;
            cursor: pointer;
        }
        .btn-submit:disabled { background: #aaa; cursor: not-allowed; }
        .hist-item {
            padding: 10px 0;
            border-bottom: 1px solid #f0f0f0;
            font-size: 13px;
        }
        .hist-item:last-child { border-bottom: none; }
        .hist-fecha { color: #888; font-size: 12px; }
        .hist-mat { color: #333; margin: 4px 0; }
        .hist-guia { color: #555; }
        .badge-hist { background: #217346; color: #fff; padding: 2px 8px; border-radius: 10px; font-size: 11px; }
        #msgExito { display: none; }
        .alert-success-custom {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            border-radius: 8px;
            padding: 14px;
            text-align: center;
            font-weight: 600;
        }
        .no-materiales { color: #888; text-align: center; padding: 20px; }
    </style>
</head>
<body>

<div class="page-header-bar">
    <a href="index_pruebas.php"><i class="fa fa-arrow-left fa-lg"></i></a>
    <h1><i class="fa fa-cubes"></i> Recepción de Materiales</h1>
</div>

<div class="content-wrap">

    <!-- Info campaña -->
    <div class="card-block">
        <h3><i class="fa fa-tag"></i> Campaña</h3>
        <div class="mat-name" style="font-size:15px;"><?= $nombreCampana ?></div>
        <div class="campana-meta"><?= $fechaInicio ?> — <?= $fechaTermino ?></div>
        <div class="campana-meta" style="margin-top:4px;">
            <?php if ($tieneHist): ?>
                <span class="badge-hist"><?= count($historial) ?> recepci<?= count($historial) === 1 ? 'ón' : 'ones' ?> registrada<?= count($historial) === 1 ? '' : 's' ?></span>
            <?php else: ?>
                <span style="color:#c0392b; font-size:12px;"><i class="fa fa-exclamation-circle"></i> Sin recepciones registradas</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($materiales)): ?>
        <div class="card-block">
            <div class="no-materiales">
                <i class="fa fa-info-circle fa-2x" style="color:#aaa;"></i><br>
                Esta campaña no tiene materiales propuestos asociados.
            </div>
        </div>
    <?php else: ?>

    <!-- Formulario de nueva recepción -->
    <div id="msgExito">
        <div class="alert-success-custom">
            <i class="fa fa-check-circle fa-lg"></i> ¡Recepción registrada exitosamente!
            <div style="margin-top:8px;">
                <button class="btn btn-default btn-sm" onclick="window.location.reload()">Registrar otra recepción</button>
                <a href="index_pruebas.php" class="btn btn-default btn-sm">Volver al inicio</a>
            </div>
        </div>
    </div>

    <form id="formRecepcion" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="id_formulario" value="<?= $id_formulario ?>">

        <!-- Materiales -->
        <div class="card-block">
            <h3><i class="fa fa-cubes"></i> Materiales — ingresa cantidades recibidas</h3>

            <?php foreach ($materiales as $mat): ?>
                <?php
                $nomMat   = htmlspecialchars($mat['nombre_material'], ENT_QUOTES, 'UTF-8');
                $cantProp = (float)$mat['cantidad_propuesta'];
                $idMat    = (int)$mat['id_material'];
                $idFQ     = (int)$mat['id_formularioQuestion'];
                $refImg   = $mat['ref_image'] ?? '';
                ?>
                <div class="mat-row">
                    <?php if ($refImg): ?>
                        <img src="<?= htmlspecialchars('/visibility2' . $refImg, ENT_QUOTES, 'UTF-8') ?>"
                             alt="<?= $nomMat ?>" class="mat-thumb">
                    <?php else: ?>
                        <div class="mat-thumb-placeholder"><i class="fa fa-cube"></i></div>
                    <?php endif; ?>

                    <div class="mat-info">
                        <div class="mat-name"><?= $nomMat ?></div>
                        <div class="mat-prop">Propuesto: <strong><?= number_format($cantProp, 0) ?></strong> unid.</div>
                        <input type="hidden" name="id_formularioQuestion[]" value="<?= $idFQ ?>">
                        <input type="hidden" name="id_material[]"           value="<?= $idMat ?>">
                        <input type="hidden" name="nombre_material[]"        value="<?= $nomMat ?>">
                        <input type="hidden" name="cantidad_propuesta[]"     value="<?= $cantProp ?>">
                    </div>

                    <input type="number"
                           name="cantidad_recibida[]"
                           class="form-control mat-input"
                           min="0"
                           step="1"
                           placeholder="0"
                           required>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Foto guía de despacho -->
        <div class="card-block">
            <h3><i class="fa fa-camera"></i> Foto Guía de Despacho <span style="color:#c0392b">*</span></h3>
            <div class="form-group">
                <label for="foto_guia" style="display:block; padding: 40px; border: 2px dashed #ccc; border-radius: 8px; text-align:center; cursor:pointer; color:#888;" id="fotoLabel">
                    <i class="fa fa-upload fa-2x"></i><br>
                    <span id="fotoLabelText">Toca para seleccionar fotos (puedes elegir varias)</span>
                </label>
                <input type="file" id="foto_guia" name="foto_guia[]"
                       accept="image/*"
                       style="display:none;" required multiple>
                <div id="fotoPreviewGrid" class="foto-grid"></div>
            </div>

            <!-- Fecha real de recepción -->
            <div class="form-group" style="margin-top:12px;">
                <label style="font-size:13px; color:#555; font-weight:600;">
                    Fecha de recepción del material <span style="color:#c0392b">*</span>
                </label>
                <input type="date" name="fecha_recepcion" id="fecha_recepcion"
                       class="form-control"
                       max="<?= date('Y-m-d') ?>"
                       value="<?= date('Y-m-d') ?>"
                       required>
                <small style="color:#888; font-size:11px;">Si retiraste el material en una fecha anterior, corrígela aquí.</small>
            </div>

            <!-- Número de guía (obligatorio) -->
            <div class="form-group" style="margin-top:12px;">
                <label style="font-size:13px; color:#555; font-weight:600;">
                    N° Guía de Despacho <span style="color:#c0392b">*</span>
                </label>
                <input type="text" name="numero_guia" id="numero_guia"
                       class="form-control"
                       placeholder="Ej: GD-20250601-001"
                       maxlength="100"
                       required>
            </div>

            <!-- Observación -->
            <div class="form-group">
                <label style="font-size:13px; color:#555;">Observación (opcional)</label>
                <textarea name="observacion" class="form-control" rows="2"
                          placeholder="Ej: Se recibió en bodega central, faltaron 2 unidades de X..."></textarea>
            </div>
        </div>

        <button type="submit" class="btn-submit" id="btnSubmit">
            <i class="fa fa-check-circle"></i> Registrar Recepción
        </button>
        <div id="msgError" style="display:none; margin-top:10px;" class="alert alert-danger"></div>
    </form>

    <?php endif; ?>

    <!-- Historial de recepciones -->
    <?php if ($tieneHist): ?>
    <div class="card-block" style="margin-top:16px;">
        <h3><i class="fa fa-history"></i> Historial de Recepciones</h3>
        <?php foreach ($historial as $h): ?>
            <div class="hist-item">
                <div class="hist-fecha">
                    <i class="fa fa-calendar"></i> Retirado el <?= date('d/m/Y', strtotime($h['fecha_recepcion'])) ?>
                    <span style="color:#bbb; font-size:11px;"> (registrado <?= date('d/m/Y H:i', strtotime($h['created_at'])) ?>)</span>
                </div>
                <div class="hist-mat"><?= htmlspecialchars($h['resumen_materiales'] ?? '—', ENT_QUOTES, 'UTF-8') ?></div>
                <?php if ($h['numero_guia']): ?>
                    <div class="hist-guia"><i class="fa fa-file-text-o"></i> Guía: <?= htmlspecialchars($h['numero_guia'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <?php if ($h['foto_guia_url']):
                    $fotos = json_decode($h['foto_guia_url'], true);
                    if (!is_array($fotos)) $fotos = [$h['foto_guia_url']];
                ?>
                    <div style="margin-top:6px; display:flex; flex-wrap:wrap; gap:6px;">
                        <?php foreach ($fotos as $fotoUrl): ?>
                            <a href="<?= htmlspecialchars($fotoUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank">
                                <img src="<?= htmlspecialchars($fotoUrl, ENT_QUOTES, 'UTF-8') ?>"
                                     style="max-height:120px; border-radius:6px; border:1px solid #ddd;" alt="Guía">
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($h['observacion']): ?>
                    <div style="font-size:12px; color:#888; margin-top:4px;"><i class="fa fa-comment-o"></i> <?= htmlspecialchars($h['observacion'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div><!-- /.content-wrap -->

<script>
(function () {
    /* Vista previa de fotos */
    const inputFoto = document.getElementById('foto_guia');
    const grid      = document.getElementById('fotoPreviewGrid');
    const lblText   = document.getElementById('fotoLabelText');
    const fotoLabel = document.getElementById('fotoLabel');

    if (inputFoto) {
        inputFoto.addEventListener('change', function () {
            const files = Array.from(this.files);
            if (!files.length) return;
            const n = files.length;
            lblText.textContent = n === 1 ? files[0].name : n + ' fotos seleccionadas';
            fotoLabel.style.borderColor = '#217346';
            fotoLabel.style.color       = '#217346';
            if (grid) {
                grid.innerHTML = '';
                files.forEach(function (file) {
                    const reader = new FileReader();
                    reader.onload = function (e) {
                        const wrap = document.createElement('div');
                        wrap.className = 'foto-thumb-wrap';
                        const img = document.createElement('img');
                        img.src = e.target.result;
                        wrap.appendChild(img);
                        grid.appendChild(wrap);
                    };
                    reader.readAsDataURL(file);
                });
            }
        });
    }

    /* Submit AJAX */
    const form    = document.getElementById('formRecepcion');
    const btnSub  = document.getElementById('btnSubmit');
    const msgErr  = document.getElementById('msgError');
    const msgOk   = document.getElementById('msgExito');

    if (!form) return;

    form.addEventListener('submit', async function (e) {
        e.preventDefault();

        /* Validar que al menos un material tenga cantidad > 0 */
        const cantidades = form.querySelectorAll('input[name="cantidad_recibida[]"]');
        const algunaPos = Array.from(cantidades).some(i => parseFloat(i.value) > 0);
        if (!algunaPos) {
            showErr('Debes ingresar la cantidad recibida de al menos un material.');
            return;
        }

        btnSub.disabled = true;
        btnSub.textContent = 'Guardando…';
        msgErr.style.display = 'none';

        const fd = new FormData(form);
        try {
            const res = await fetch('guardar_recepcion_materiales.php', {
                method: 'POST',
                body: fd
            });
            const data = await res.json();
            if (data.status === 'success') {
                form.style.display = 'none';
                msgOk.style.display = 'block';
            } else {
                showErr(data.message || 'Error al guardar. Intenta de nuevo.');
                btnSub.disabled = false;
                btnSub.innerHTML = '<i class="fa fa-check-circle"></i> Registrar Recepción';
            }
        } catch (err) {
            showErr('Error de conexión. Verifica tu red e intenta de nuevo.');
            btnSub.disabled = false;
            btnSub.innerHTML = '<i class="fa fa-check-circle"></i> Registrar Recepción';
        }
    });

    function showErr(msg) {
        msgErr.textContent = msg;
        msgErr.style.display = 'block';
        msgErr.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

})();
</script>
</body>
</html>
