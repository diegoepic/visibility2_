<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/modulos/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/modulos/session_data.php';

if (!isset($_SESSION['usuario_id'])) { http_response_code(403); exit('Acceso denegado'); }
if (!isset($_GET['idCampana'], $_GET['idLocal']) || !ctype_digit($_GET['idCampana']) || !ctype_digit($_GET['idLocal'])) {
  http_response_code(400); exit('Parámetros inválidos');
}

$idCampana  = (int)$_GET['idCampana'];
$idLocal    = (int)$_GET['idLocal'];
$empresa_id = (int)($_SESSION['empresa_id'] ?? 0);
$baseURL    = 'https://visibility.cl/visibility2/app/';

function estado_label($s){
  static $map = [
    'implementado_auditado' => 'Implementación + Encuesta',
    'solo_implementado'     => 'Solo implementación',
    'solo_auditoria'        => 'Encuesta (auditoría)',
    'solo_retirado'         => 'Retiro',
    'entregado'             => 'Entrega',
    'en proceso'            => 'En proceso',
    'cancelado'             => 'Cancelado',
  ];
  return $map[$s] ?? $s;
}
function normalize_url(string $url, string $baseURL): string {
  $u = trim($url);
  if ($u === '') return $baseURL . 'assets/images/placeholder.png';
  if (preg_match('#^https?://#i', $u)) return $u;
  if (strpos($u, '/visibility2/app/') === 0) return 'https://visibility.cl' . $u;
  return $baseURL . ltrim($u, './');
}
function toDec($v){ $v = trim((string)$v); if($v==='') return 0.0; return (float)str_replace(',', '.', $v); }
function haversine_m($lat1,$lon1,$lat2,$lon2){
  if ($lat1===null||$lon1===null||$lat2===null||$lon2===null) return null;
  $R=6371000; $dLat=deg2rad($lat2-$lat1); $dLon=deg2rad($lon2-$lon1);
  $a=sin($dLat/2)**2+cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLon/2)**2;
  return (int)round($R*2*atan2(sqrt($a),sqrt(1-$a)));
}

$campanaNombre = null;
$st = $conn->prepare("SELECT COALESCE(nombre, CONCAT('Campaña #', id)) FROM formulario WHERE id=? AND id_empresa=? LIMIT 1");
$st->bind_param("ii",$idCampana,$empresa_id);
$st->execute(); $st->bind_result($campanaNombre); $st->fetch(); $st->close();
if (!$campanaNombre) $campanaNombre = 'Campaña #'.$idCampana;

// Local + coords (vía relación con FQ para asegurar empresa)
$localCodigo=$localNombre=$localDireccion=null; $latLocal=null; $lngLocal=null;
$stL = $conn->prepare("
  SELECT l.codigo,l.nombre,l.direccion,l.lat,l.lng
  FROM local l
  JOIN formularioQuestion fq ON fq.id_local=l.id AND fq.id_formulario=?
  JOIN formulario f ON f.id=fq.id_formulario AND f.id_empresa=?
  WHERE l.id=? LIMIT 1
");
$stL->bind_param("iii",$idCampana,$empresa_id,$idLocal);
$stL->execute(); $stL->bind_result($localCodigo,$localNombre,$localDireccion,$latLocal,$lngLocal);
$stL->fetch(); $stL->close();
// Fallback directo al local si no hubo FQ por alguna razón
if(!$localCodigo || !$localNombre){
  $tmp=$conn->prepare("SELECT codigo,nombre,direccion,lat,lng FROM local WHERE id=? LIMIT 1");
  $tmp->bind_param("i",$idLocal); $tmp->execute();
  $tmp->bind_result($localCodigo,$localNombre,$localDireccion,$latLocal,$lngLocal);
  $tmp->fetch(); $tmp->close();
}
$localCodigo    = $localCodigo    ?: '#'.$idLocal;
$localNombre    = $localNombre    ?: '';
$localDireccion = $localDireccion ?: '—';

/* -------------------------
   2) Estado (modo) y KPIs
   ------------------------- */

// Modo (último estado) desde gestion_visita
$modo='sin_datos';
$stM=$conn->prepare("
  SELECT gv.estado_gestion
  FROM gestion_visita gv
  JOIN formulario f ON f.id = gv.id_formulario AND f.id_empresa=?
  WHERE gv.id_formulario=? AND gv.id_local=?
  ORDER BY gv.fecha_visita DESC, gv.id DESC LIMIT 1
");
$stM->bind_param("iii",$empresa_id,$idCampana,$idLocal);
$stM->execute(); $stM->bind_result($modoTmp); if($stM->fetch()) $modo=$modoTmp; $stM->close();

$has_impl_aud = 0; $has_impl_any = 0; $has_audit = 0;
$stAgg = $conn->prepare("
  SELECT
    MAX(gv.estado_gestion = 'implementado_auditado')                                AS has_impl_aud,
    MAX(gv.estado_gestion IN ('solo_implementado','implementado_auditado'))         AS has_impl_any,
    MAX(gv.estado_gestion = 'solo_auditoria')                                       AS has_audit
  FROM gestion_visita gv
  JOIN formulario f ON f.id = gv.id_formulario AND f.id_empresa = ?
  WHERE gv.id_formulario = ? AND gv.id_local = ?
");
$stAgg->bind_param("iii", $empresa_id, $idCampana, $idLocal);
$stAgg->execute();
$stAgg->bind_result($has_impl_aud, $has_impl_any, $has_audit);
$stAgg->fetch();
$stAgg->close();

if ((int)$has_impl_aud === 1) {
  $modo = 'implementado_auditado';
} elseif ((int)$has_impl_any === 1) {
  $modo = 'solo_implementado';
} elseif ((int)$has_audit === 1) {
  $modo = 'solo_auditoria';
}

// KPIs base
$visitasTot=0; $lastUsuario='—'; $lastFecha='—'; $lastLat=null; $lastLng=null;

// Visitas reales = DISTINCT visita_id
$stC=$conn->prepare("
  SELECT COUNT(DISTINCT gv.visita_id)
  FROM gestion_visita gv
  JOIN formulario f ON f.id=gv.id_formulario AND f.id_empresa=?
  WHERE gv.id_formulario=? AND gv.id_local=?
");
$stC->bind_param("iii",$empresa_id,$idCampana,$idLocal);
$stC->execute(); $stC->bind_result($visitasTot); $stC->fetch(); $stC->close();

$stLast=$conn->prepare("
  SELECT COALESCE(u.usuario,'—') AS usuario,
         DATE_FORMAT(gv.fecha_visita,'%d/%m/%Y %H:%i') AS fecha,
         COALESCE(gv.latitud, gv.lat_foto) AS lat,
         COALESCE(gv.longitud, gv.lng_foto) AS lng
  FROM gestion_visita gv
  LEFT JOIN usuario u ON u.id=gv.id_usuario
  JOIN formulario f ON f.id=gv.id_formulario AND f.id_empresa=?
  WHERE gv.id_formulario=? AND gv.id_local=?
  ORDER BY gv.fecha_visita DESC, gv.id DESC
  LIMIT 1
");
$stLast->bind_param("iii",$empresa_id,$idCampana,$idLocal);
$stLast->execute(); $stLast->bind_result($lastUsuario,$lastFecha,$lastLat,$lastLng); $stLast->fetch(); $stLast->close();

$distUltima = ($latLocal!==null && $lastLat!==null) ? haversine_m((float)$latLocal,(float)$lngLocal,(float)$lastLat,(float)$lastLng) : null;

/* --------------------------------------
   3) Implementaciones (evidencia y métricas)
   -------------------------------------- */
$implementaciones=[];

$sqlImp = "
  /* A) Implementaciones con gestion_visita (modelo nuevo) */
  SELECT
    gv.id                    AS id,
    gv.visita_id             AS visita_id,
    gv.id_formularioQuestion AS id_fq,
    gv.id_material           AS id_material,
    gv.estado_gestion        AS estado_gestion,
    gv.observacion           AS observacion,
    DATE_FORMAT(gv.fecha_visita,'%d/%m/%Y %H:%i') AS fechaVisita,
    COALESCE(m.nombre, fq.material) AS material,
    fq.valor_propuesto       AS valor_propuesto,
    gv.valor_real            AS valor_real,
    COALESCE(gv.latitud, gv.lat_foto)  AS latitud,
    COALESCE(gv.longitud, gv.lng_foto) AS longitud,
    COALESCE(u.usuario,'—')  AS usuario,
    'GV'                     AS source
  FROM gestion_visita gv
  JOIN formulario f ON f.id=gv.id_formulario AND f.id_empresa=?
  LEFT JOIN usuario u             ON u.id=gv.id_usuario
  LEFT JOIN formularioQuestion fq ON fq.id=gv.id_formularioQuestion
  LEFT JOIN material m            ON m.id=gv.id_material
  WHERE gv.id_formulario=? AND gv.id_local=?
    AND gv.estado_gestion IN ('solo_implementado','implementado_auditado')

  UNION ALL

  /* B) Implementaciones legacy en formularioQuestion (sin gestion_visita) */
  SELECT
    NULL                     AS id,
    NULL                     AS visita_id,
    fq.id                    AS id_fq,
    NULL                     AS id_material,
    fq.pregunta              AS estado_gestion,
    fq.observacion           AS observacion,
    DATE_FORMAT(COALESCE(fq.fechaVisita, fq.created_at),'%d/%m/%Y %H:%i') AS fechaVisita,
    fq.material              AS material,
    fq.valor_propuesto       AS valor_propuesto,
    fq.valor                 AS valor_real,
    fq.latGestion            AS latitud,
    fq.lngGestion            AS longitud,
    COALESCE(u.usuario,'—')  AS usuario,
    'FQ'                     AS source
  FROM formularioQuestion fq
  JOIN formulario f ON f.id=fq.id_formulario AND f.id_empresa=?
  LEFT JOIN usuario u ON u.id=fq.id_usuario
  WHERE fq.id_formulario=? AND fq.id_local=?
    AND fq.pregunta IN ('solo_implementado','implementado_auditado')
    AND NOT EXISTS (
      SELECT 1
      FROM gestion_visita gv
      WHERE gv.id_formularioQuestion = fq.id
        AND gv.id_formulario = fq.id_formulario
        AND gv.id_local = fq.id_local
    )
  ORDER BY fechaVisita ASC
";

$stImp=$conn->prepare($sqlImp);
$stImp->bind_param("iiiiii", $empresa_id, $idCampana, $idLocal, $empresa_id, $idCampana, $idLocal);
$stImp->execute();
$implementaciones=$stImp->get_result()->fetch_all(MYSQLI_ASSOC);
$stImp->close();

/* -------------------------
   4) Historial de gestiones
   ------------------------- */
$historial=[];
$stH=$conn->prepare("
  SELECT gv.id, gv.visita_id, gv.estado_gestion,
         DATE_FORMAT(gv.fecha_visita,'%d/%m/%Y %H:%i') AS fechaVisita,
         COALESCE(u.usuario,'—') AS usuario,
         COALESCE(m.nombre, fq.material) AS material,
         fq.valor_propuesto, gv.valor_real,
         COALESCE(gv.latitud, gv.lat_foto) AS lat,
         COALESCE(gv.longitud, gv.lng_foto) AS lng
  FROM gestion_visita gv
  JOIN formulario f ON f.id=gv.id_formulario AND f.id_empresa=?
  LEFT JOIN usuario u             ON u.id=gv.id_usuario
  LEFT JOIN formularioQuestion fq ON fq.id=gv.id_formularioQuestion
  LEFT JOIN material m            ON m.id=gv.id_material
  WHERE gv.id_formulario=? AND gv.id_local=?
  ORDER BY gv.fecha_visita DESC, gv.id DESC
");
$stH->bind_param("iii",$empresa_id,$idCampana,$idLocal);
$stH->execute(); $historial=$stH->get_result()->fetch_all(MYSQLI_ASSOC);
$stH->close();

// Lista total de visita_id para evidencias/encuesta
$visitIds = [];
foreach ($historial as $h) { if (!empty($h['visita_id'])) $visitIds[] = (int)$h['visita_id']; }
$visitIds = array_values(array_unique($visitIds));

/* -------------------------
   5) FOTOS CORRECTAS POR IMPLEMENTACIÓN
   ------------------------- */
// a) Fotos linkeadas por id_formularioQuestion
$fotosPorFQ = []; // id_fq => [urls]
$idFQs = array_values(array_filter(array_unique(array_column($implementaciones,'id_fq'))));
if ($idFQs) {
  $ph = implode(',', array_fill(0,count($idFQs),'?'));
  $types = str_repeat('i', count($idFQs));
  $sqlFq = "SELECT id_formularioQuestion, url
            FROM fotoVisita
            WHERE id_formulario=? AND id_local=? AND id_formularioQuestion IN ($ph)
            ORDER BY id ASC";
  $stFq = $conn->prepare($sqlFq);
  $params = array_merge([$idCampana,$idLocal], $idFQs);
  $refs=[]; foreach($params as $i=>$v){ $refs[$i]=&$params[$i]; }
  $stFq->bind_param("ii{$types}", ...$refs);
  $stFq->execute();
  $res = $stFq->get_result();
  while($r=$res->fetch_assoc()){
    $fotosPorFQ[(int)$r['id_formularioQuestion']][] = $r['url'];
  }
  $stFq->close();
}

// b) Para implementaciones con gestion_visita (por visita + material) y sin id_formularioQuestion
$fotosPorVM = []; // visita_id => id_material => [urls]
if ($visitIds) {
  $ph = implode(',', array_fill(0,count($visitIds),'?'));
  $types = str_repeat('i', count($visitIds));
  $sqlVm = "SELECT visita_id, id_material, url
            FROM fotoVisita
            WHERE id_formulario=? AND id_local=? 
              AND id_formularioQuestion IS NULL
              AND visita_id IN ($ph)
            ORDER BY id ASC";
  $stVm = $conn->prepare($sqlVm);
  $params = array_merge([$idCampana,$idLocal], $visitIds);
  $refs=[]; foreach($params as $i=>$v){ $refs[$i]=&$params[$i]; }
  $stVm->bind_param("ii{$types}", ...$refs);
  $stVm->execute();
  $res = $stVm->get_result();
  while($r=$res->fetch_assoc()){
    $vId = (int)$r['visita_id']; $matId = (int)($r['id_material'] ?? 0);
    if ($matId<=0) continue;
    $fotosPorVM[$vId][$matId][] = $r['url'];
  }
  $stVm->close();
}

/* -------------------------
   6) Cumplimiento y resumen por material
   ------------------------- */
$sumProp=0.0; $sumImpl=0.0; $byMaterial=[];
foreach($implementaciones as $imp){
  $sumProp += toDec($imp['valor_propuesto']);
  $sumImpl += toDec($imp['valor_real']);
  $mat=$imp['material'] ?: '—';
  if(!isset($byMaterial[$mat])) $byMaterial[$mat]=['prop'=>0.0,'impl'=>0.0];
  $byMaterial[$mat]['prop'] += toDec($imp['valor_propuesto']);
  $byMaterial[$mat]['impl'] += toDec($imp['valor_real']);
}
uksort($byMaterial, fn($a,$b)=>strcmp($a,$b));
$porcCumpl = $sumProp>0 ? (int)round(($sumImpl/$sumProp)*100) : null;

/* -------------------------
   7) Encuesta (por visitas) + score
   ------------------------- */
$fotoPreguntas=[]; $textoPreguntas=[]; $scoreEncuesta=0.0;
if($visitIds){
  $ph = implode(',', array_fill(0,count($visitIds),'?'));
  $types = str_repeat('i', count($visitIds));
  $sqlE="
    SELECT r.visita_id, q.question_text, q.is_valued, r.answer_text, r.valor,
           DATE_FORMAT(r.created_at,'%d/%m/%Y %H:%i') AS fecha
    FROM form_question_responses r
    JOIN form_questions q ON q.id=r.id_form_question
    JOIN formulario f     ON f.id=q.id_formulario AND f.id_empresa=?
    WHERE q.id_formulario=? AND r.id_local=? AND r.visita_id IN ($ph)
      AND r.answer_text <> ''
    ORDER BY q.sort_order ASC, r.created_at ASC
  ";
  $stE=$conn->prepare($sqlE);
  $params = array_merge([$empresa_id,$idCampana,$idLocal], $visitIds);
  $refs=[]; foreach($params as $i=>$v){ $refs[$i]=&$params[$i]; }
  $stE->bind_param("iii{$types}", ...$refs);
  $stE->execute();
  $resE=$stE->get_result();
  while($a=$resE->fetch_assoc()){
    $resp=trim($a['answer_text']);
    if (preg_match('/\.(jpe?g|png|gif|webp)(?:\?.*)?$/i',$resp)) {
      $fotoPreguntas[$a['question_text']][]=$resp;
    } else {
      $textoPreguntas[]=$a;
    }
    if ((int)$a['is_valued']===1) $scoreEncuesta += (float)($a['valor'] ?? 0);
  }
  $stE->close();
}

/* -------------------------
   8) Fotos generales de visita (sin id_formularioQuestion ni id_material)
   ------------------------- */
$fotosGenerales = [];
if ($visitIds) {
  $ph = implode(',', array_fill(0,count($visitIds),'?'));
  $types = str_repeat('i', count($visitIds));
  $sqlGen = "SELECT visita_id, url
             FROM fotoVisita
             WHERE id_formulario=? AND id_local=?
               AND id_formularioQuestion IS NULL
               AND (id_material IS NULL OR id_material=0)
               AND visita_id IN ($ph)
             ORDER BY id ASC";
  $stGen = $conn->prepare($sqlGen);
  $params = array_merge([$idCampana,$idLocal], $visitIds);
  $refs=[]; foreach($params as $i=>$v){ $refs[$i]=&$params[$i]; }
  $stGen->bind_param("ii{$types}", ...$refs);
  $stGen->execute();
  $resGen = $stGen->get_result();
  while($r=$resGen->fetch_assoc()){
    $fotosGenerales[(int)$r['visita_id']][] = $r['url'];
  }
  $stGen->close();
}

$conn->close();

// Mini-mapa data (local vs última gestión)
$miniMap=null;
if($latLocal!==null && $lngLocal!==null && $lastLat!==null && $lastLng!==null){
  $miniMap=['latLocal'=>(float)$latLocal,'lngLocal'=>(float)$lngLocal,'latGest'=>(float)$lastLat,'lngGest'=>(float)$lastLng,'dist'=>$distUltima];
}
?>
<style>
  .modal-header { background:#007bff; color:#fff; border-bottom:none; }
  .modal-title { font-weight:600; font-size:1.1rem; }
  #detalleLocalContent { max-height: 80vh; overflow-y: auto; }
  .kpi { border:1px solid #e3e3e3; border-radius:8px; padding:.75rem .9rem; background:#fff; box-shadow:0 1px 4px rgba(0,0,0,.05); }
  .kpi .label { font-size:.75rem; color:#666; margin-bottom:.25rem; }
  .kpi .value { font-size:1.05rem; font-weight:600; }
  .progress { height:10px; }
  .badge-pill { padding:.35rem .6rem; }
  .timeline { border-left:3px solid #e9ecef; margin-left:.5rem; padding-left:1rem; }
  .timeline .item { position:relative; margin-bottom:.9rem; }
  .timeline .item::before { content:""; position:absolute; left:-1.2rem; top:.2rem; width:.8rem; height:.8rem; border-radius:50%; background:#6c757d; }
  .timeline .item.ok::before { background:#28a745; }
  .timeline .item.impl::before { background:#17a2b8; }
  .timeline .item.audit::before { background:#ffc107; }
  .card { border:1px solid #e3e3e3; border-radius:8px; box-shadow:0 2px 6px rgba(0,0,0,.05); margin-bottom:1rem; }
  .card-header { background:#f8f9fa; border-bottom:1px solid #e3e3e3; font-weight:500; }
  .img-thumb { width:100px; height:100px; object-fit:cover; border-radius:6px; margin:.25rem; cursor:pointer; }
  .mini-map { width:100%; height:220px; border-radius:6px; }
  .table-sm td, .table-sm th { padding:.35rem .5rem; }
</style>

<div class="modal-header d-flex flex-column flex-sm-row align-items-sm-center">
  <div class="flex-grow-1">
    <div class="d-flex align-items-center">
      <h5 class="modal-title mb-0"><?= htmlspecialchars($campanaNombre, ENT_QUOTES) ?></h5>
      <span class="badge badge-success ml-2">
        <?= htmlspecialchars(estado_label($modo), ENT_QUOTES) ?>
      </span>
    </div>
    <small class="d-block mt-1 text-white" style="opacity:.95">
      <strong>Local:</strong> <?= htmlspecialchars($localCodigo, ENT_QUOTES) ?> — <?= htmlspecialchars($localNombre, ENT_QUOTES) ?><br>
      <span style="opacity:.9"><?= htmlspecialchars($localDireccion, ENT_QUOTES) ?></span>
    </small>
  </div>
  <button type="button" class="close text-white ml-sm-3" data-dismiss="modal" aria-label="Cerrar">&times;</button>
</div>

<div class="modal-body" id="detalleLocalContent">
  <!-- KPIs -->
  <div class="row">
    <div class="col-6 col-md-3 mb-2">
      <div class="kpi">
        <div class="label">Última visita</div>
        <div class="value"><?= htmlspecialchars($lastFecha, ENT_QUOTES) ?></div>
        <div class="text-muted" style="font-size:.8rem">por <?= htmlspecialchars($lastUsuario, ENT_QUOTES) ?></div>
      </div>
    </div>
    <div class="col-6 col-md-2 mb-2">
      <div class="kpi">
        <div class="label">Visitas</div>
        <div class="value"><?= (int)$visitasTot ?></div>
      </div>
    </div>
    <div class="col-12 col-md-4 mb-2">
      <div class="kpi">
        <div class="label mb-1">Cumplimiento (Implementado / Propuesto)</div>
        <div class="value">
          <?= number_format($sumImpl,0,',','.') ?> / <?= number_format($sumProp,0,',','.') ?>
          <?= $porcCumpl!==null ? "({$porcCumpl}%)" : "" ?>
        </div>
        <?php if ($porcCumpl!==null):
          $cls = $porcCumpl>=100 ? 'bg-success' : ($porcCumpl>=75 ? 'bg-info' : 'bg-warning'); ?>
          <div class="progress mt-2">
            <div class="progress-bar <?= $cls ?>"
                 role="progressbar" style="width: <?= max(0,min(100,$porcCumpl)) ?>%"></div>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <div class="col-6 col-md-3 mb-2">
      <div class="kpi">
        <div class="label">Distancia última gestión</div>
        <?php if ($distUltima!==null): ?>
          <div class="value">
            <span class="badge badge-<?= $distUltima<=150 ? 'success':'danger' ?> badge-pill">
              <?= $distUltima ?> m <?= $distUltima>150 ? '• fuera de rango':'' ?>
            </span>
          </div>
        <?php else: ?>
          <div class="text-muted">Sin coordenadas</div>
        <?php endif; ?>
        <?php if ($scoreEncuesta>0): ?>
          <div class="label mt-2">Score encuesta</div>
          <div class="value"><?= number_format($scoreEncuesta,1,',','.') ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="row">
    <!-- Columna izquierda: Evidencia + Encuesta + Fotos Generales -->
    <div class="col-lg-7">
      <?php if ($implementaciones): ?>
      <div class="card">
        <div class="card-header">Evidencias de implementaciones</div>
        <div class="card-body">
          <?php foreach ($implementaciones as $imp): ?>
            <?php
              // ID legible por fuente
              $labelId = !empty($imp['id']) ? ('GV-'.(int)$imp['id']) : ('FQ-'.(int)$imp['id_fq']);

              // Selección de fotos SOLO de esta implementación:
              $pics = [];
              if (!empty($imp['id_fq'])) {
                $pics = $fotosPorFQ[$imp['id_fq']] ?? [];
              }
              if (!$pics && !empty($imp['id_material']) && !empty($imp['visita_id'])) {
                $pics = $fotosPorVM[$imp['visita_id']][$imp['id_material']] ?? [];
              }
              $hasFotos = !empty($pics);

              $badge = ($imp['estado_gestion']==='implementado_auditado') ? 'success' : 'info';
              $dist = ($imp['latitud']!==null && $latLocal!==null)
                        ? haversine_m((float)$latLocal,(float)$lngLocal,(float)$imp['latitud'],(float)$imp['longitud'])
                        : null;
            ?>
            <div class="mb-3 p-2" style="border:1px dashed #e3e3e3;border-radius:8px;">
              <div class="d-flex justify-content-between align-items-center">
                <div>
                  <strong><?= htmlspecialchars($labelId, ENT_QUOTES) ?></strong>
                  <span class="badge badge-<?= $badge ?> ml-1"><?= htmlspecialchars(estado_label($imp['estado_gestion']), ENT_QUOTES) ?></span>
                  <div class="text-muted small mt-1">
                    <?= htmlspecialchars($imp['fechaVisita'] ?? '—', ENT_QUOTES) ?> ·
                    <?= htmlspecialchars($imp['usuario'] ?? '—', ENT_QUOTES) ?>
                  </div>
                </div>
                <div class="text-right">
                  <div class="small"><strong>Material:</strong> <?= htmlspecialchars($imp['material'] ?? '—', ENT_QUOTES) ?></div>
                  <div class="small">Prop: <?= htmlspecialchars($imp['valor_propuesto'] ?? '0', ENT_QUOTES) ?> → Impl: <strong><?= htmlspecialchars($imp['valor_real'] ?? '0', ENT_QUOTES) ?></strong></div>
                  <div class="small"><?= $dist!==null ? "Dist.: {$dist} m" : "Dist.: —" ?></div>
                </div>
              </div>

              <?php if ($hasFotos): ?>
                <div class="d-flex flex-wrap mt-2">
                  <?php foreach ($pics as $url):
                        $fullUrl = normalize_url($url, $baseURL); ?>
                    <img src="<?= htmlspecialchars($fullUrl, ENT_QUOTES) ?>" class="img-thumb"
                         alt="Foto de implementación"
                         onclick="verImagenGrande('<?= htmlspecialchars($fullUrl, ENT_QUOTES) ?>')">
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <div class="text-muted small mt-2">Sin fotos asociadas.</div>
              <?php endif; ?>

              <?php if (!empty($imp['observacion'])): ?>
                <div class="mt-2"><strong>Obs.:</strong> <?= nl2br(htmlspecialchars($imp['observacion'], ENT_QUOTES)) ?></div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if (!empty($fotosGenerales)): ?>
      <div class="card">
        <div class="card-header">Fotos generales de la visita</div>
        <div class="card-body">
          <?php foreach ($fotosGenerales as $vId=>$urls): ?>
            <div class="mb-2">
              <div class="small text-muted">Visita #<?= (int)$vId ?></div>
              <div class="d-flex flex-wrap mt-1">
              <?php foreach ($urls as $u): $full = normalize_url($u,$baseURL); ?>
                <img src="<?= htmlspecialchars($full, ENT_QUOTES) ?>" class="img-thumb"
                     alt="Foto general de la visita"
                     onclick="verImagenGrande('<?= htmlspecialchars($full, ENT_QUOTES) ?>')">
              <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($fotoPreguntas || $textoPreguntas): ?>
      <div class="card">
        <div class="card-header">Encuesta</div>
        <div class="card-body">
          <?php foreach ($fotoPreguntas as $preg => $urls): ?>
            <div class="mb-2">
              <strong><?= htmlspecialchars($preg, ENT_QUOTES) ?></strong><br>
              <div class="d-flex flex-wrap mt-1">
                <?php foreach ($urls as $u): $full = normalize_url($u,$baseURL); ?>
                  <img src="<?= htmlspecialchars($full, ENT_QUOTES) ?>" class="img-thumb"
                       alt="Foto respuesta de encuesta"
                       onclick="verImagenGrande('<?= htmlspecialchars($full, ENT_QUOTES) ?>')">
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>

          <?php if ($textoPreguntas): ?>
          <table class="table table-sm mt-2">
            <thead><tr><th>Pregunta</th><th>Respuesta</th><th class="text-right">Fecha</th></tr></thead>
            <tbody>
            <?php foreach ($textoPreguntas as $ans): ?>
              <tr>
                <td style="width:45%"><?= htmlspecialchars($ans['question_text'], ENT_QUOTES) ?></td>
                <td>
                  <?= nl2br(htmlspecialchars($ans['answer_text'], ENT_QUOTES)) ?>
                  <?php if ((int)($ans['is_valued'] ?? 0) === 1 && $ans['valor'] !== null && $ans['valor'] !== ''): ?>
                    <span class="badge badge-success ml-1">
                      valor: <?= rtrim(rtrim(number_format((float)$ans['valor'], 2, ',', '.'), '0'), ',') ?>
                    </span>
                  <?php endif; ?>
                </td>
                <td class="text-right"><small><?= htmlspecialchars($ans['fecha'] ?? '—', ENT_QUOTES) ?></small></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Columna derecha: Mini-mapa, Timeline, Resumen por material -->
    <div class="col-lg-5">
      <?php if ($miniMap): ?>
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span>Ubicación de última gestión</span>
          <?php if ($distUltima!==null): ?>
            <span class="badge badge-<?= $distUltima<=150 ? 'success':'danger' ?> badge-pill"><?= $distUltima ?> m</span>
          <?php endif; ?>
        </div>
        <div class="card-body">
          <div id="miniMap" class="mini-map" aria-label="Mini mapa última gestión"></div>
          <?php if ($latLocal!==null && $lngLocal!==null): ?>
          <div class="mt-2">
            <a target="_blank" rel="noopener"
               href="https://www.google.com/maps/search/?api=1&query=<?= rawurlencode($latLocal.','.$lngLocal) ?>"
               class="btn btn-sm btn-outline-primary">Abrir en Google Maps</a>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($historial): ?>
      <div class="card">
        <div class="card-header">Historial de gestiones</div>
        <div class="card-body">
          <div class="timeline">
            <?php foreach ($historial as $h):
              $cls = ($h['estado_gestion']==='implementado_auditado')?'ok':(($h['estado_gestion']==='solo_implementado')?'impl':'audit');
              $d = ($h['lat']!==null && $latLocal!==null) ? haversine_m((float)$latLocal,(float)$lngLocal,(float)$h['lat'],(float)$h['lng']) : null;
            ?>
            <div class="item <?= $cls ?>">
              <div><strong><?= htmlspecialchars($h['fechaVisita'], ENT_QUOTES) ?></strong> — <?= htmlspecialchars($h['usuario'] ?? '—', ENT_QUOTES) ?></div>
              <div class="small text-muted">
                <?= htmlspecialchars(estado_label($h['estado_gestion']), ENT_QUOTES) ?>
                <?php if ($h['material']): ?> · Mat.: <?= htmlspecialchars($h['material'], ENT_QUOTES) ?><?php endif; ?>
                <?php if ($h['valor_propuesto']!==null || $h['valor_real']!==null): ?>
                  · Prop: <?= htmlspecialchars($h['valor_propuesto'] ?? '0', ENT_QUOTES) ?> → Impl: <?= htmlspecialchars($h['valor_real'] ?? '0', ENT_QUOTES) ?>
                <?php endif; ?>
                · Dist.: <?= $d!==null ? ($d.' m') : '—' ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($byMaterial): ?>
      <div class="card">
        <div class="card-header">Resumen por material</div>
        <div class="card-body p-0">
          <table class="table table-sm mb-0">
            <thead><tr><th>Material</th><th class="text-right">Propuesto</th><th class="text-right">Implementado</th></tr></thead>
            <tbody>
              <?php foreach ($byMaterial as $mat=>$vals): ?>
                <tr>
                  <td><?= htmlspecialchars($mat, ENT_QUOTES) ?></td>
                  <td class="text-right"><?= number_format($vals['prop'],0,',','.') ?></td>
                  <td class="text-right"><?= number_format($vals['impl'],0,',','.') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="modal-footer">
  <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
</div>

<!-- Modal imagen -->
<div class="modal fade" id="imageModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content bg-transparent border-0">
    <div class="modal-body p-0"><img src="" id="imageModalSrc" class="img-fluid rounded" alt="Imagen"></div>
  </div></div>
</div>

<script>
function verImagenGrande(src){
  document.getElementById('imageModalSrc').src = src || '';
  $('#imageModal').modal('show');
}

// Mini-mapa (usa Google Maps del parent)
<?php if ($miniMap): ?>
(function(){
  const data = <?= json_encode($miniMap) ?>;
  function initMini(){
    try{
      if (!window.google || !google.maps) { setTimeout(initMini, 150); return; }
      const el = document.getElementById('miniMap'); if (!el) return;
      const map = new google.maps.Map(el, { zoom: 15, center:{lat:data.latLocal, lng:data.lngLocal}, mapTypeControl:false, streetViewControl:false });
      const mkLocal = new google.maps.Marker({ position:{lat:data.latLocal, lng:data.lngLocal}, map, label:'L' });
      const mkGest  = new google.maps.Marker({ position:{lat:data.latGest,  lng:data.lngGest },  map, label:'G' });
      const line = new google.maps.Polyline({ path:[{lat:data.latLocal,lng:data.lngLocal},{lat:data.latGest,lng:data.lngGest}], geodesic:true, strokeOpacity:0.7, strokeWeight:3 });
      line.setMap(map);
      const b = new google.maps.LatLngBounds(); b.extend(mkLocal.getPosition()); b.extend(mkGest.getPosition()); map.fitBounds(b);
    }catch(e){ /* noop */ }
  }
  setTimeout(initMini, 0);
})();
<?php endif; ?>
</script>
