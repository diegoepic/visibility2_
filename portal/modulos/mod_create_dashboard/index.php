<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$templates = [
    ['id' => 'almacen', 'name' => 'Almacén', 'eyebrow' => 'Tradicional', 'description' => 'Disponibilidad, frío, precio y ejecución del punto de venta.', 'image' => 'render/almacen.jpg', 'tone' => '#e7573f'],
    ['id' => 'supermercado', 'name' => 'Supermercado', 'eyebrow' => 'Moderno', 'description' => 'Visibilidad, exhibiciones, lineal y cumplimiento por categoría.', 'image' => 'render/supermercado.png', 'tone' => '#4f72d8'],
    ['id' => 'restaurant', 'name' => 'Restaurant', 'eyebrow' => 'Consumo', 'description' => 'Menú, equipos de frío, disponibilidad y activación de marca.', 'image' => 'render/restaurant.png', 'tone' => '#db704a'],
    ['id' => 'playa', 'name' => 'Playa', 'eyebrow' => 'Temporada', 'description' => 'Ejecución de temporada, cobertura, equipos y materiales POP.', 'image' => 'render/playa.jpg', 'tone' => '#3a9e9b'],
    ['id' => 'kiosko', 'name' => 'Kiosko', 'eyebrow' => 'Impulso', 'description' => 'Frentes visibles, stock, frío y presencia en zona de caja.', 'image' => 'render/kiosko.jpg', 'tone' => '#b775ba'],
    ['id' => 'sport', 'name' => 'Sport', 'eyebrow' => 'Eventos', 'description' => 'Disponibilidad, branding, espacios clave y activación deportiva.', 'image' => 'render/sport.png', 'tone' => '#3f86c8'],
];

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Creador visual de dashboards Perfect Store.">
    <title>Perfect Store Studio</title>
    <link rel="stylesheet" href="assets/styles.css?v=18">
</head>
<body>
<main class="studio-shell" id="top">
    <header class="topbar">
        <button class="brand brand-button" type="button" id="go-home" aria-label="Volver a las plantillas">
            <span class="brand-mark" aria-hidden="true">PS</span>
            <span><strong>Perfect Store</strong><small>Dashboard builder · PHP</small></span>
        </button>
        <div class="topbar-meta">
            <span class="status-dot" aria-hidden="true"></span>
            <span id="topbar-status"><?= count($templates) ?> plantillas disponibles</span>
            <button class="quiet-button" type="button" aria-label="Ayuda" title="Selecciona una plantilla para comenzar">?</button>
        </div>
    </header>

    <section class="template-view" id="template-view">
        <div class="workspace">
            <aside class="selector-panel" aria-label="Tipos de dashboard">
                <div class="step-label"><span>01</span> Selecciona el canal</div>
                <h1>Crea tu próximo<br>Perfect Store.</h1>
                <p class="intro">Elige el tipo de punto de venta. Luego podrás definir su alcance y construir el dashboard sobre un lienzo flexible.</p>
                <div class="template-list" role="listbox" aria-label="Plantillas disponibles">
                    <?php foreach ($templates as $index => $template): ?>
                        <button type="button" class="template-option<?= $index === 0 ? ' is-active' : '' ?>" role="option" aria-selected="<?= $index === 0 ? 'true' : 'false' ?>" data-template-id="<?= htmlspecialchars($template['id'], ENT_QUOTES, 'UTF-8') ?>" style="--template-tone: <?= htmlspecialchars($template['tone'], ENT_QUOTES, 'UTF-8') ?>">
                            <span class="option-number"><?= str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) ?></span>
                            <span class="option-copy"><strong><?= htmlspecialchars($template['name'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($template['eyebrow'], ENT_QUOTES, 'UTF-8') ?></small></span>
                            <span class="option-arrow" aria-hidden="true">→</span>
                        </button>
                    <?php endforeach; ?>
                    <div class="template-option is-disabled" aria-disabled="true"><span class="option-number">07</span><span class="option-copy"><strong>Retail</strong><small>Próximamente</small></span><span class="coming-badge">En diseño</span></div>
                </div>
            </aside>

            <section class="preview-section" aria-live="polite">
                <div class="preview-heading">
                    <div><div class="step-label"><span>02</span> Previsualiza la plantilla</div><h2 id="preview-title">Perfect Store · Almacén</h2><p id="preview-description"><?= htmlspecialchars($templates[0]['description'], ENT_QUOTES, 'UTF-8') ?></p></div>
                    <div class="view-switch" aria-label="Ajuste de la vista"><button type="button" class="active" data-fit="contain">Completa</button><button type="button" data-fit="cover">Detalle</button></div>
                </div>
                <div class="preview-frame">
                    <div class="browser-bar"><div class="window-dots" aria-hidden="true"><i></i><i></i><i></i></div><div class="preview-url" id="preview-url">preview / perfect-store / almacen</div><span class="live-chip"><i></i> LIVE</span></div>
                    <div class="image-stage" id="image-stage" style="--accent: <?= $templates[0]['tone'] ?>"><img id="dashboard-image" class="dashboard-image fit-contain" src="<?= $templates[0]['image'] ?>" alt="Vista previa del dashboard Perfect Store para Almacén"><div class="preview-watermark" id="preview-watermark">Almacén</div></div>
                    <div class="preview-footer"><div class="preview-facts"><span><i></i> Imagen conectada</span><span><i></i> Lista para configurar</span></div><button class="primary-button" id="use-template" type="button">Usar esta plantilla <span aria-hidden="true">→</span></button></div>
                </div>
            </section>
        </div>
    </section>

    <section class="editor-view" id="editor-view" hidden>
        <div class="editor-toolbar">
            <div class="editor-title"><button type="button" class="back-button" id="editor-back" aria-label="Volver">←</button><div><small>EDITANDO PLANTILLA</small><strong id="editor-template-name">Almacén</strong></div></div>
            <div class="toolbar-actions">
                <button type="button" class="tool-button" id="add-text">+ Texto</button>
                <button type="button" class="tool-button chart-tool-button" id="add-chart">+ Gráfico</button>
                <div class="shape-picker">
                    <button type="button" class="tool-button" id="shape-menu-button" aria-haspopup="true" aria-expanded="false">+ Formas <span>⌄</span></button>
                    <div class="shape-menu" id="shape-menu" hidden>
                        <button type="button" data-shape="rectangle"><i class="shape-swatch swatch-rectangle"></i><span>Rectángulo</span></button>
                        <button type="button" data-shape="rounded"><i class="shape-swatch swatch-rounded"></i><span>Redondeado</span></button>
                        <button type="button" data-shape="ellipse"><i class="shape-swatch swatch-ellipse"></i><span>Círculo / elipse</span></button>
                        <button type="button" data-shape="line"><i class="shape-swatch swatch-line"></i><span>Línea</span></button>
                        <button type="button" data-shape="arrow"><i class="shape-swatch swatch-arrow"></i><span>Flecha</span></button>
                        <button type="button" data-shape="triangle"><i class="shape-swatch swatch-triangle"></i><span>Triángulo</span></button>
                        <button type="button" data-shape="diamond"><i class="shape-swatch swatch-diamond"></i><span>Rombo</span></button>
                    </div>
                </div>
                <button type="button" class="tool-button" id="add-date-filter">+ Filtro fecha</button>
                <label class="tool-button upload-button">+ Imagen<input type="file" id="image-upload" accept="image/jpeg,image/png,image/webp" hidden></label>
                <button type="button" class="tool-button danger" id="delete-element" disabled>Eliminar</button>
                <button type="button" class="primary-button" id="download-design">Descargar diseño <span>↓</span></button>
            </div>
        </div>

        <div class="editor-layout">
            <aside class="question-panel">
                <div class="panel-kicker">ALCANCE ACTUAL</div>
                <div class="scope-summary"><strong id="scope-division">—</strong><span id="scope-subdivision">—</span><button type="button" id="change-scope">Cambiar</button></div>
                <div class="panel-heading"><div><strong>Fuentes y variables KPI</strong><small id="question-count">Selecciona un alcance</small></div></div>
                <label class="search-field"><span>⌕</span><input id="question-search" type="search" placeholder="Buscar estado o encuesta..." autocomplete="off"></label>
                <div class="question-list source-catalog" id="question-list"><div class="empty-state"><span>01</span><strong>Define el alcance</strong><small>Los estados y encuestas aparecerán aquí.</small></div></div>
            </aside>

            <section class="canvas-workspace">
                <div class="canvas-controls">
                    <label>Ancho <input id="canvas-width" type="number" min="640" max="2400" step="20" value="1200"><span>px</span></label>
                    <label>Alto <input id="canvas-height" type="number" min="480" max="4000" step="20" value="780"><span>px</span></label>
                    <label>Zoom <input id="canvas-zoom" type="range" min="35" max="100" value="70"><output id="zoom-output">70%</output></label>
                    <span class="save-state" id="save-state"><i></i> Borrador local</span>
                </div>
                <div class="canvas-scroll" id="canvas-scroll">
                    <div class="dashboard-canvas" id="dashboard-canvas" style="width:1200px;height:780px;transform:scale(.7)"></div>
                </div>
            </section>

            <aside class="properties-panel">
                <div class="panel-kicker">PROPIEDADES</div>
                <div class="no-selection" id="no-selection"><span>↖</span><strong>Selecciona un elemento</strong><small>Podrás ajustar posición, tamaño, color y tipografía.</small></div>
                <form id="properties-form" hidden autocomplete="off">
                    <label class="wide">Contenido<input id="prop-content" type="text"></label>
                    <div class="field-grid"><label>X<input id="prop-x" type="number"></label><label>Y<input id="prop-y" type="number"></label><label>Ancho<input id="prop-width" type="number" min="20"></label><label>Alto<input id="prop-height" type="number" min="20"></label></div>
                    <div class="field-grid date-filter-property"><label>Fecha desde<input id="prop-date-from" type="date"></label><label>Fecha hasta<input id="prop-date-to" type="date"></label></div>
                    <div class="chart-property">
                        <label class="wide">Tipo de gráfico<select id="prop-chart-kind"><option value="donut">Dona</option><option value="bar">Barras</option><option value="line">Lineal</option></select></label>
                        <label class="wide">Posición de leyenda<select id="prop-legend-position"><option value="right">Derecha</option><option value="left">Izquierda</option><option value="top">Arriba</option><option value="bottom">Abajo</option><option value="none">Ocultar</option></select></label>
                        <div class="chart-color-grid"><label>Color 1<input id="prop-chart-color-1" type="color"></label><label>Color 2<input id="prop-chart-color-2" type="color"></label><label>Color 3<input id="prop-chart-color-3" type="color"></label><label>Color 4<input id="prop-chart-color-4" type="color"></label></div>
                    </div>
                    <div class="shape-property">
                        <div class="field-grid"><label>Relleno<input id="prop-shape-fill" type="color"></label><label>Contorno<input id="prop-shape-stroke" type="color"></label></div>
                        <div class="field-grid"><label>Grosor<input id="prop-shape-stroke-width" type="number" min="1" max="30"></label><label>Estilo<select id="prop-shape-stroke-style"><option value="solid">Sólido</option><option value="dashed">Guiones</option><option value="dotted">Puntos</option></select></label></div>
                        <label class="wide">Rotación<input id="prop-shape-rotation" type="range" min="-180" max="180" value="0"><output id="shape-rotation-output">0°</output></label>
                    </div>
                    <label class="wide text-property">Fuente<select id="prop-font"><option value="Arial, sans-serif">Arial</option><option value="'Segoe UI', sans-serif">Segoe UI</option><option value="Georgia, serif">Georgia</option><option value="'Trebuchet MS', sans-serif">Trebuchet</option><option value="Impact, sans-serif">Impact</option></select></label>
                    <div class="field-grid text-property"><label>Tamaño<input id="prop-font-size" type="number" min="8" max="120"></label><label>Peso<select id="prop-font-weight"><option value="400">Regular</option><option value="600">Semibold</option><option value="700">Bold</option><option value="900">Black</option></select></label></div>
                    <div class="field-grid text-property"><label>Texto<input id="prop-color" type="color"></label><label>Fondo<input id="prop-background" type="color"></label></div>
                    <label class="wide text-property">Alineación<select id="prop-align"><option value="left">Izquierda</option><option value="center">Centro</option><option value="right">Derecha</option></select></label>
                    <label class="wide image-property">Ajuste de imagen<select id="prop-object-fit"><option value="contain">Completa</option><option value="cover">Recortar para llenar</option><option value="fill">Estirar</option></select></label>
                    <label class="wide">Redondeado<input id="prop-radius" type="range" min="0" max="60" value="0"><output id="radius-output">0 px</output></label>
                    <div class="layer-actions"><button type="button" id="layer-front">Traer al frente</button><button type="button" id="layer-back">Enviar atrás</button></div>
                </form>
            </aside>
        </div>
    </section>
</main>

<div class="modal-backdrop" id="scope-modal" hidden>
    <section class="scope-modal" role="dialog" aria-modal="true" aria-labelledby="scope-title">
        <div class="modal-step">PASO 1 DE 2</div><h2 id="scope-title">Define el alcance</h2><p>Selecciona la división, subdivisión y la fecha desde la cual se buscarán estados, materiales, gestiones y respuestas del dashboard.</p>
        <div class="scope-fields"><label>División<select id="division-select"><option value="">Cargando divisiones...</option></select></label><label>Subdivisión<select id="subdivision-select" disabled><option value="">Selecciona una división</option></select></label><label class="scope-date">Buscar gestiones y respuestas desde<input id="scope-date-from" type="date" value="<?= date('Y-01-01') ?>" required></label><label class="scope-activity">Actividad / gestión<select id="activity-select" disabled><option value="">Selecciona primero una subdivisión</option></select></label></div>
        <div class="modal-feedback" id="scope-feedback" role="status"></div>
        <div class="modal-actions"><button type="button" class="secondary-button" id="cancel-scope">Cancelar</button><button type="button" class="primary-button" id="continue-scope" disabled>Crear espacio de trabajo <span>→</span></button></div>
    </section>
</div>

<div class="modal-backdrop" id="kpi-modal" hidden>
    <section class="scope-modal kpi-modal" role="dialog" aria-modal="true" aria-labelledby="kpi-modal-title">
        <div class="modal-step">CONFIGURAR FICHA KPI</div>
        <div class="kpi-modal-heading"><div><h2 id="kpi-modal-title">¿Cómo quieres cuantificar?</h2><p id="kpi-question-label">—</p></div><span class="question-type-badge" id="kpi-type-badge">Detectando</span></div>
        <div class="kpi-loading" id="kpi-loading">Analizando las respuestas de esta pregunta...</div>
        <div id="kpi-config-fields" hidden>
            <label class="kpi-field">Método de cálculo<select id="kpi-metric"></select></label>
            <fieldset class="answer-selector" id="kpi-answer-fieldset"><legend>Respuesta que deseas cuantificar</legend><div id="kpi-answer-options"></div></fieldset>
            <div class="counting-note" id="kpi-counting-note"><span>◎</span><div><strong>Conteo distintivo</strong><small>Cada local se cuenta una sola vez mediante <code>id_local</code>.</small></div></div>
        </div>
        <div class="modal-feedback" id="kpi-feedback" role="status"></div>
        <div class="modal-actions"><button type="button" class="secondary-button" id="cancel-kpi">Cancelar</button><button type="button" class="primary-button" id="create-kpi" disabled>Calcular y agregar ficha <span>→</span></button></div>
    </section>
</div>

<div class="modal-backdrop" id="chart-modal" hidden>
    <section class="scope-modal chart-modal" role="dialog" aria-modal="true" aria-labelledby="chart-modal-title">
        <div class="modal-step">CREAR VISUALIZACIÓN</div>
        <div class="kpi-modal-heading">
            <div><h2 id="chart-modal-title">Agrega un gráfico</h2><p>Selecciona el tipo de gráfico y los KPI de Estados o Encuestas que deseas comparar.</p></div>
            <span class="question-type-badge">KPI</span>
        </div>
        <label class="kpi-field chart-title-field">Título del gráfico<input id="chart-title" type="text" value="Análisis por región" maxlength="120"></label>
        <div class="chart-config-grid">
            <label class="kpi-field">Fuente del indicador<select id="chart-source-kind"><option value="state">Estados y gestiones</option><option value="survey">Preguntas de encuesta</option></select></label>
            <label class="kpi-field" id="chart-source-item-field">Pregunta<select id="chart-source-item"></select></label>
            <label class="kpi-field">Agrupar resultados por<select id="chart-dimension"><option value="region">Región</option><option value="date">Fecha de visita / respuesta</option></select></label>
            <label class="kpi-field">Cálculo<select id="chart-metric"><option value="distinct_local">Locales distintos</option></select></label>
        </div>
        <fieldset class="answer-selector chart-state-selector" id="chart-state-fieldset">
            <legend>KPI disponibles · selecciona uno o más</legend>
            <div class="chart-state-toolbar"><label><span>⌕</span><input id="chart-state-search" type="search" placeholder="Buscar KPI..." autocomplete="off"></label><button type="button" id="chart-select-all">Seleccionar visibles</button><strong id="chart-state-count">0 seleccionados</strong></div>
            <div id="chart-state-options"></div>
        </fieldset>
        <fieldset class="answer-selector chart-answer-selector" id="chart-answer-fieldset" hidden>
            <legend>Respuesta que deseas cuantificar</legend>
            <div id="chart-answer-options"></div>
        </fieldset>
        <fieldset class="chart-type-selector">
            <legend>Tipo de gráfico</legend>
            <label><input type="radio" name="chart-type" value="donut" checked><span><i class="chart-icon chart-icon-donut"></i><strong>Dona</strong><small>Participación entre KPI</small></span></label>
            <label><input type="radio" name="chart-type" value="bar"><span><i class="chart-icon chart-icon-bar"></i><strong>Barras</strong><small>Comparación directa</small></span></label>
            <label><input type="radio" name="chart-type" value="line"><span><i class="chart-icon chart-icon-line"></i><strong>Lineal</strong><small>Tendencia o secuencia</small></span></label>
        </fieldset>
        <div class="counting-note"><span>◎</span><div><strong>Cálculo directo</strong><small>El gráfico consultará la API y contará cada <code>id_local</code> una sola vez por región o fecha, sin crear una ficha KPI intermedia.</small></div></div>
        <div class="modal-feedback" id="chart-feedback" role="status"></div>
        <div class="modal-actions"><button type="button" class="secondary-button" id="cancel-chart">Cancelar</button><button type="button" class="primary-button" id="create-chart" disabled>Agregar gráfico <span>→</span></button></div>
    </section>
</div>

<div class="toast" id="toast" role="status"></div>
<script type="application/json" id="template-data"><?= json_encode($templates, $jsonFlags) ?></script>
<script>window.PERFECT_STORE_CONFIG = <?= json_encode(['api' => 'api/editor.php', 'csrf' => $_SESSION['csrf_token']], $jsonFlags) ?>;</script>
<script src="assets/app.js?v=18" defer></script>
</body>
</html>
