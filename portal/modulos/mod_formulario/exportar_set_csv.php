<?php
// exportar_set_csv.php — Exporta set a Excel/CSV o vista HTML para PDF

session_start();
if (!isset($_SESSION['usuario_id'])) { http_response_code(403); echo "No autorizado."; exit(); }

include_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/modulos/db.php';
include_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/modulos/session_data.php';

$formato = ($_GET['format'] ?? '') === 'html' ? 'html' : 'csv';
$idSet   = isset($_GET['idSet']) ? (int)$_GET['idSet'] : 0;
if ($idSet <= 0) { http_response_code(400); echo "Parámetros inválidos."; exit(); }

$st = $conn->prepare("SELECT id, nombre_set, description FROM question_set WHERE id=?");
$st->bind_param("i", $idSet); $st->execute();
$set = $st->get_result()->fetch_assoc(); $st->close();
if (!$set) { http_response_code(404); echo "Set no encontrado."; exit(); }

$st = $conn->prepare("
    SELECT id, question_text, id_question_type, sort_order, is_required, id_dependency_option, is_valued
    FROM question_set_questions
    WHERE id_question_set = ? ORDER BY sort_order ASC, id ASC
");
$st->bind_param("i", $idSet); $st->execute();
$qs = []; $r = $st->get_result();
while ($row = $r->fetch_assoc()) $qs[] = $row;
$st->close();

if (!$qs) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo "El set no tiene preguntas."; exit();
}

// Opciones por pregunta
$optsByQ = []; $optTextById = [];
$qids  = array_column($qs, 'id');
$in    = implode(',', array_fill(0, count($qids), '?'));
$types = str_repeat('i', count($qids));
$st    = $conn->prepare("SELECT * FROM question_set_options WHERE id_question_set_question IN ($in) ORDER BY id_question_set_question, sort_order");
$refs  = []; foreach ($qids as $k => $v) { $qids[$k] = (int)$v; $refs[$k] = &$qids[$k]; }
$st->bind_param($types, ...$refs); $st->execute(); $res = $st->get_result();
while ($row = $res->fetch_assoc()) {
    $qid = (int)$row['id_question_set_question'];
    $optsByQ[$qid][]              = $row;
    $optTextById[(int)$row['id']] = $row['option_text'];
}
$st->close();

// Mapas auxiliares
$optionParents = [];
foreach ($optsByQ as $qid => $opts)
    foreach ($opts as $op)
        $optionParents[(int)$op['id']] = (int)$qid;

$qTextById = array_column($qs, 'question_text', 'id');

// Construir árbol
$byId = [];
foreach ($qs as $q) {
    $q['children'] = [];
    $q['options']  = $optsByQ[(int)$q['id']] ?? [];
    $byId[(int)$q['id']] = $q;
}
$roots = [];
foreach ($byId as $id => &$q) {
    $dep = isset($q['id_dependency_option']) ? (int)$q['id_dependency_option'] : 0;
    if ($dep && isset($optionParents[$dep], $byId[$optionParents[$dep]])) {
        $byId[$optionParents[$dep]]['children'][] = &$q;
    } else {
        $roots[] = &$q;
    }
}
unset($q);

function tipoTexto(int $t): string {
    return match($t) {
        1 => 'Sí / No',
        2 => 'Selección única',
        3 => 'Selección múltiple',
        4 => 'Texto libre',
        5 => 'Numérico',
        6 => 'Fecha',
        7 => 'Foto',
        default => 'Otro',
    };
}

// ── Generar filas CSV en orden DFS con numeración jerárquica ──────────
$rows = [];

function collectRows(array &$q, string $prefix, int $idx,
    array $optTextById, array $optionParents, array $qTextById,
    array &$rows): void
{
    $num  = $prefix === '' ? (string)$idx : "$prefix.$idx";
    $dep  = isset($q['id_dependency_option']) ? (int)$q['id_dependency_option'] : 0;
    $opts = $q['options'];

    // Condición en lenguaje natural
    $condicion = '(siempre visible)';
    if ($dep && isset($optTextById[$dep])) {
        $parentId  = $optionParents[$dep] ?? 0;
        $parentTxt = $parentId && isset($qTextById[$parentId]) ? $qTextById[$parentId] : '';
        $condicion = $parentTxt
            ? '"' . $parentTxt . '" = "' . $optTextById[$dep] . '"'
            : 'Cuando se responde "' . $optTextById[$dep] . '"';
    }

    // Opciones como lista simple
    $opcionesTxt = '';
    if ($opts) {
        $opcionesTxt = implode(' / ', array_map(fn($o) => $o['option_text'], $opts));
    }

    $rows[] = [
        $num,
        $q['question_text'],
        tipoTexto((int)$q['id_question_type']),
        (int)$q['is_required'] ? 'Sí' : 'No',
        (int)$q['is_valued']   ? 'Sí' : 'No',
        $condicion,
        $opcionesTxt,
    ];

    foreach ($q['children'] as $i => &$child)
        collectRows($child, $num, $i + 1, $optTextById, $optionParents, $qTextById, $rows);
}

foreach ($roots as $i => &$root)
    collectRows($root, '', $i + 1, $optTextById, $optionParents, $qTextById, $rows);

// ── Salida HTML (vista para PDF desde modal) ─────────────────────────
if ($formato === 'html') {
    header('Content-Type: text/html; charset=UTF-8');

    function tipoBadge(int $t): array {
        return match($t) {
            1 => ['Sí / No',             '#546e7a'],
            2 => ['Selección única',     '#1565c0'],
            3 => ['Selección múltiple',  '#00838f'],
            4 => ['Texto libre',         '#6a1b9a'],
            5 => ['Numérico',            '#e65100'],
            6 => ['Fecha',               '#2e7d32'],
            7 => ['Foto',                '#c62828'],
            default => ['Otro',          '#757575'],
        };
    }

    function renderHtmlQuestion(array &$q, int $depth, int &$counter,
        array $optTextById, array $optionParents, array $qTextById): void
    {
        $counter++;
        [$tipoLabel, $tipoColor] = tipoBadge((int)$q['id_question_type']);
        $dep   = isset($q['id_dependency_option']) ? (int)$q['id_dependency_option'] : 0;
        $opts  = $q['options'];
        $ml    = $depth * 36;
        $numBg = $depth === 0 ? '#1a237e' : '#78909c';
        $acc   = $depth === 0 ? '#1a237e' : '#546e7a';

        $condHtml = '';
        if ($dep && isset($optTextById[$dep])) {
            $pid = $optionParents[$dep] ?? 0;
            $pt  = $pid && isset($qTextById[$pid]) ? htmlspecialchars($qTextById[$pid]) : '';
            $condHtml = '<div class="q-cond">'
                . ($pt ? '<em>' . $pt . '</em> &rarr; ' : '')
                . 'Solo aparece si se responde <strong>"' . htmlspecialchars($optTextById[$dep]) . '"</strong>'
                . '</div>';
        }

        $optsHtml = '';
        if ($opts) {
            $optsHtml = '<div class="q-opts"><span class="opts-lbl">Opciones</span><div class="chips">';
            foreach ($opts as $op)
                $optsHtml .= '<span class="chip">' . htmlspecialchars($op['option_text']) . '</span>';
            $optsHtml .= '</div></div>';
        }

        $badges = '<span class="btipo" style="background:' . $tipoColor . '">' . $tipoLabel . '</span>';
        if ((int)$q['is_required']) $badges .= '<span class="breq">Requerida</span>';
        if ((int)$q['is_valued'])   $badges .= '<span class="bval">Valorizada</span>';

        echo '<div class="qr" style="margin-left:' . $ml . 'px;--acc:' . $acc . '">';
        if ($depth > 0) echo '<div class="branch"></div>';
        echo '<div class="qnum" style="background:' . $numBg . '">' . $counter . '</div>';
        echo '<div class="qcard" style="border-left-color:' . $acc . '">';
        echo   '<div class="qtxt">' . htmlspecialchars($q['question_text']) . '</div>';
        echo   '<div class="qmeta">' . $badges . '</div>';
        echo   $condHtml . $optsHtml;
        echo '</div></div>';

        foreach ($q['children'] as &$child)
            renderHtmlQuestion($child, $depth + 1, $counter, $optTextById, $optionParents, $qTextById);
    }

    $c = 0;
    ?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
<title><?= htmlspecialchars($set['nombre_set']) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Arial,sans-serif;background:#eef1f7;color:#222;padding:24px;font-size:13px}
.hdr{background:linear-gradient(135deg,#1a237e,#283593);color:#fff;padding:22px 28px;border-radius:10px;margin-bottom:20px;box-shadow:0 4px 16px rgba(26,35,126,.25)}
.hdr h1{font-size:1.35rem;font-weight:700}.hdr .desc{margin-top:5px;opacity:.75;font-size:.88rem}
.hdr .meta{margin-top:10px;opacity:.5;font-size:.75rem}
.legend{display:flex;flex-wrap:wrap;gap:7px;align-items:center;background:#fff;border-radius:7px;padding:10px 16px;margin-bottom:18px;border:1px solid #dde1ea;font-size:.75rem}
.legend-ttl{color:#888;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-right:4px}
.questions{display:flex;flex-direction:column;gap:9px}
.qr{display:flex;align-items:flex-start;gap:9px;position:relative}
.branch{position:absolute;left:-25px;top:0;bottom:-9px;width:2px;background:#cfd8dc}
.branch::after{content:'';position:absolute;top:18px;left:0;width:18px;height:2px;background:#cfd8dc}
.qnum{width:28px;height:28px;border-radius:50%;color:#fff;font-size:.75rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:5px;box-shadow:0 2px 5px rgba(0,0,0,.2)}
.qcard{flex:1;background:#fff;border-radius:7px;padding:12px 14px;border:1px solid #e0e4ed;border-left:4px solid var(--acc,#1a237e);box-shadow:0 1px 3px rgba(0,0,0,.06)}
.qtxt{font-size:.93rem;font-weight:600;color:#1a1a2e;line-height:1.4;margin-bottom:7px}
.qmeta{display:flex;flex-wrap:wrap;gap:5px;margin-bottom:7px}
.btipo{display:inline-block;padding:2px 9px;border-radius:20px;color:#fff;font-size:.68rem;font-weight:700}
.breq{display:inline-block;padding:2px 9px;border-radius:20px;background:#c62828;color:#fff;font-size:.68rem;font-weight:700}
.bval{display:inline-block;padding:2px 9px;border-radius:20px;background:#2e7d32;color:#fff;font-size:.68rem;font-weight:700}
.q-cond{font-size:.78rem;color:#5d4037;background:#fff8e1;border-left:3px solid #ffb300;padding:4px 9px;border-radius:4px;margin-bottom:7px;line-height:1.4}
.q-cond em{font-style:normal;font-weight:600;color:#6d4c41}.q-cond strong{color:#4e342e}
.q-opts{margin-top:5px}.opts-lbl{display:block;font-size:.67rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#aaa;margin-bottom:4px}
.chips{display:flex;flex-wrap:wrap;gap:4px}
.chip{background:#f0f4ff;border:1px solid #c5cae9;color:#3949ab;padding:2px 10px;border-radius:20px;font-size:.76rem;font-weight:500}
.ftr{margin-top:28px;text-align:center;font-size:.72rem;color:#bbb}
@media print{body{background:#fff;padding:10px}
.hdr{background:#1a237e!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.qr{break-inside:avoid}.qcard{box-shadow:none}}
</style></head><body>
<div class="hdr">
  <h1><?= htmlspecialchars($set['nombre_set']) ?></h1>
  <?php if (trim($set['description'] ?? '')): ?><div class="desc"><?= htmlspecialchars($set['description']) ?></div><?php endif; ?>
  <div class="meta"><?= count($qs) ?> pregunta<?= count($qs) !== 1 ? 's' : '' ?> &middot; <?= date('d/m/Y') ?></div>
</div>
<div class="legend">
  <span class="legend-ttl">Tipos</span>
  <?php foreach([[1,'Sí / No','#546e7a'],[2,'Selección única','#1565c0'],[3,'Selección múltiple','#00838f'],[4,'Texto libre','#6a1b9a'],[5,'Numérico','#e65100'],[6,'Fecha','#2e7d32'],[7,'Foto','#c62828']] as [,$l,$col]): ?>
    <span class="btipo" style="background:<?= $col ?>"><?= $l ?></span>
  <?php endforeach; ?>
  <span style="color:#ccc">|</span>
  <span class="breq">Requerida</span><span class="bval">Valorizada</span>
</div>
<div class="questions">
<?php foreach ($roots as &$root) renderHtmlQuestion($root, 0, $c, $optTextById, $optionParents, $qTextById); ?>
</div>
<div class="ftr">Generado el <?= date('d/m/Y H:i') ?> &middot; <?= htmlspecialchars($set['nombre_set']) ?></div>
</body></html>
    <?php
    exit;
}

// ── Salida CSV (Excel) ────────────────────────────────────────────────
$fnameBase = preg_replace('~[^\w\-\.\(\) ]+~u', '_', $set['nombre_set'] ?: "set_$idSet");
$fname     = "set_{$idSet}_{$fnameBase}.csv";

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
echo "\xEF\xBB\xBF"; // BOM para que Excel abra en UTF-8

$out = fopen('php://output', 'w');
$sep = ';';

fputcsv($out, [$set['nombre_set']], $sep);
if (trim($set['description'] ?? '')) fputcsv($out, [$set['description']], $sep);
fputcsv($out, [''], $sep);

fputcsv($out, ['N°', 'Pregunta', 'Tipo de respuesta', 'Requerida', 'Valorizada', 'Solo aparece cuando...', 'Opciones de respuesta'], $sep);

foreach ($rows as $row)
    fputcsv($out, $row, $sep);

fclose($out);
exit;
