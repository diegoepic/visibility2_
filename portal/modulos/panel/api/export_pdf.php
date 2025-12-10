<?php
// panel/api/export_pdf.php
require __DIR__.'/_db.php';
require __DIR__.'/vendor/autoload.php';

use Dompdf\Dompdf;

$dataset = jread('dataset', null);
$dataset = is_string($dataset) ? json_decode($dataset, true) : $dataset;
if (!$dataset || !isset($dataset['meta'])) fail('Dataset inválido');

ob_start();
?>
<!DOCTYPE html>
<html><head><meta charset="utf-8">
<style>
body{font-family: DejaVu Sans, Arial, sans-serif; font-size:12px;}
h1{font-size:16px;margin:0 0 10px;}
table{border-collapse: collapse; width:100%; margin-bottom:20px;}
th,td{border:1px solid #aaa; padding:4px 6px; text-align:left;}
thead{background:#eee;}
</style>
</head><body>
<h1>Reporte de Encuestas (<?=htmlspecialchars($dataset['meta']['mode'])?>)</h1>

<h2>Conteos por opción</h2>
<table>
  <thead><tr>
    <?php
      $rows = $dataset['option_counts'] ?? [];
      $cols = array_keys($rows[0] ?? ['set_qid'=>'','option_set_id'=>'','cnt'=>'']);
      foreach ($cols as $c) echo '<th>'.htmlspecialchars($c).'</th>';
    ?>
  </tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
  <tr>
    <?php foreach ($cols as $c): ?>
      <td><?=htmlspecialchars((string)($r[$c] ?? ''))?></td>
    <?php endforeach; ?>
  </tr>
  <?php endforeach; ?>
</tbody></table>

<h2>Métricas numéricas</h2>
<table>
  <thead><tr>
    <?php
      $rows2 = $dataset['numeric_stats'] ?? [];
      $cols2 = array_keys($rows2[0] ?? ['set_qid'=>'','n'=>'','avg_val'=>'','sum_val'=>'','min_val'=>'','max_val'=>'']);
      foreach ($cols2 as $c) echo '<th>'.htmlspecialchars($c).'</th>';
    ?>
  </tr></thead><tbody>
  <?php foreach ($rows2 as $r): ?>
  <tr>
    <?php foreach ($cols2 as $c): ?>
      <td><?=htmlspecialchars((string)($r[$c] ?? ''))?></td>
    <?php endforeach; ?>
  </tr>
  <?php endforeach; ?>
</tbody></table>

</body></html>
<?php
$html = ob_get_clean();
$dompdf = new Dompdf(['isRemoteEnabled'=>false]);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$dompdf->stream('reporte_encuestas_'.date('Ymd_His').'.pdf', ['Attachment'=>true]);
exit;
