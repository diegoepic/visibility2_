<?php
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require __DIR__ . '/lib.php';
function territorioRespond(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
$profile = strtolower(trim((string)($_SESSION['perfil_nombre'] ?? '')));
if ($profile === '') territorioRespond(['ok'=>false,'message'=>'Sesión no válida o expirada.'],401);
$canEdit = in_array($profile,['editor','coordinador','administrador','admin'],true);
$transaction = false;
try {
    territorioScope($_SESSION);
    $input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
    $action = (string)($input['action'] ?? 'catalog');
    if (!in_array($action,['catalog','locals','rename','preview','apply'],true)) throw new InvalidArgumentException('Acción inválida.');
    if (in_array($action,['rename','preview','apply'],true)) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') territorioRespond(['ok'=>false,'message'=>'Esta acción requiere POST.'],405);
        if (!$canEdit) territorioRespond(['ok'=>false,'message'=>'El perfil sólo tiene acceso de lectura.'],403);
        $csrf = (string)($_SESSION['csrf_token'] ?? '');
        if ($csrf === '' || !hash_equals($csrf,(string)($input['csrf_token'] ?? ''))) territorioRespond(['ok'=>false,'message'=>'La sesión de edición expiró. Recarga la página.'],403);
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    require $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
    $db = $conexion ?? $conn ?? $mysqli ?? null;
    if (!($db instanceof mysqli)) throw new RuntimeException('Conexión no disponible.');
    $db->set_charset('utf8mb4');
    if ($action === 'catalog') {
        [$scope,$types,$params] = territorioScope($_SESSION);
        $catalog = [];
        foreach (['region','comuna','zona','distrito','vendedor','jefe_venta'] as $type) {
            $cfg=territorioConfig($type);
            $parent=$cfg['parent'] ? $cfg['parent'] : 'NULL';
            $rows=territorioQuery($db,"SELECT id, {$cfg['name']} AS nombre, $parent AS parent_id FROM {$cfg['table']} ORDER BY {$cfg['name']},id");
            $counts=territorioQuery($db,'SELECT COALESCE('.$cfg['key'].',0) AS id,COUNT(*) AS total'.territorioJoins()." WHERE $scope GROUP BY ".$cfg['key'],$types,$params);
            $totals=array_column($counts,'total','id');
            foreach($rows as &$row) $row['total']=(int)($totals[$row['id']]??0);
            unset($row);
            $catalog[$type]=['rows'=>$rows,'missing'=>(int)($totals[0]??0)];
        }
        territorioRespond(['ok'=>true,'catalog'=>$catalog,'can_edit'=>$canEdit]);
    }
    if ($action === 'locals') {
        [$where,$types,$params]=territorioFilter($_SESSION,$input);
        $total=(int)territorioQuery($db,'SELECT COUNT(*) AS n'.territorioJoins()." WHERE $where",$types,$params)[0]['n'];
        $page=max(1,(int)($input['page']??1));$page=min($page,max(1,(int)ceil($total/100)));$offset=($page-1)*100;
        $rows=territorioQuery($db,'SELECT l.id,l.codigo,l.nombre,l.direccion,l.id_comuna,l.id_zona,l.id_distrito,l.id_vendedor,l.id_jefe_venta,
            vd.nombre_vendedor AS vendedor,jv.nombre AS jefe_venta,c.comuna,r.region,z.nombre_zona AS zona,d.nombre_distrito AS distrito,d.id_zona AS distrito_zona,
            (c.id IS NULL OR r.id IS NULL OR z.id IS NULL OR d.id IS NULL OR NOT(l.id_zona <=> d.id_zona)) AS inconsistente'
            .territorioJoins()." WHERE $where ORDER BY l.nombre,l.id LIMIT 100 OFFSET $offset",$types,$params);
        territorioRespond(['ok'=>true,'rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/100))]);
    }
    if ($action === 'rename') {
        $cfg=territorioConfig((string)($input['tipo']??''));$id=(int)($input['id']??0);
        $name=preg_replace('/[\p{Z}\s]+/u',' ',trim((string)($input['nombre']??'')));
        if (!$name) throw new InvalidArgumentException('El nombre no puede estar vacío.');
        $column=territorioQuery($db,"SHOW COLUMNS FROM {$cfg['table']} LIKE '{$cfg['name']}'")[0]??[];
        $max=preg_match('/(?:var)?char\((\d+)\)/i',(string)($column['Type']??''),$m)?(int)$m[1]:255;
        $length=preg_match_all('/./us',$name);
        if ($length===false || $length>$max) throw new InvalidArgumentException("El nombre admite como máximo $max caracteres.");
        $db->begin_transaction();$transaction=true;
        $parent=$cfg['parent']??'NULL';
        $old=territorioQuery($db,"SELECT {$cfg['name']} AS nombre,$parent AS parent_id FROM {$cfg['table']} WHERE id=? FOR UPDATE",'i',[$id])[0]??null;
        if (!$old || $old['nombre']!==(string)($input['original']??'')) throw new DomainException('El registro cambió. Actualiza el listado y vuelve a intentarlo.');
        $dupSql="SELECT id FROM {$cfg['table']} WHERE LOWER(TRIM({$cfg['name']}))=LOWER(TRIM(?)) AND id<>?";
        $dupParams=[$name,$id];$dupTypes='si';
        if ($cfg['parent']) {$dupSql.=" AND {$cfg['parent']} <=> ?";$dupTypes.='i';$dupParams[]=$old['parent_id'];}
        if (territorioQuery($db,$dupSql,$dupTypes,$dupParams)) throw new InvalidArgumentException('Ya existe ese nombre en el mismo catálogo y región/zona. Reasigna los locales al registro correcto.');
        territorioQuery($db,"UPDATE {$cfg['table']} SET {$cfg['name']}=? WHERE id=?",'si',[$name,$id]);
        $db->commit();$transaction=false;
        territorioRespond(['ok'=>true,'message'=>'Nombre del catálogo actualizado.']);
    }
    if ($action === 'preview') {
        $cfg=territorioConfig((string)($input['tipo']??''));
        $target=territorioTarget($db,$cfg['family'],(int)($input['target_id']??0));
        [$where,$types,$params]=territorioFilter($_SESSION,$input);
        $ids=null;
        if (($input['all']??'0')!=='1') {
            $ids=territorioIds(json_decode((string)($input['ids']??''),true));
            $where.=' AND l.id IN ('.implode(',',array_fill(0,count($ids),'?')).')';$types.=str_repeat('i',count($ids));$params=array_merge($params,$ids);
        }
        $rows=territorioQuery($db,'SELECT l.id,l.id_comuna,l.id_zona,l.id_distrito,l.id_vendedor,l.id_jefe_venta'.territorioJoins()." WHERE $where ORDER BY l.id LIMIT 5001",$types,$params);
        if (!$rows || count($rows)>5000) throw new InvalidArgumentException('La operación admite entre 1 y 5000 locales. Acota la búsqueda o selecciona locales.');
        if ($ids!==null && count($rows)!==count($ids)) throw new DomainException('Algunos locales cambiaron o están fuera de tu alcance. Recarga el listado.');
        $changes=0;
        foreach($rows as $r) if (in_array($cfg['family'],['vendedor','jefe_venta'],true) ? (int)$r['id_'.$cfg['family']]!==(int)$target['id'] : ($cfg['family']==='comuna' ? (int)$r['id_comuna']!==(int)$target['id'] : ((int)$r['id_distrito']!==(int)$target['id'] || (int)$r['id_zona']!==(int)$target['parent_id']))) $changes++;
        $token=bin2hex(random_bytes(24));
        $_SESSION['territorio_preview']=['token'=>$token,'expires'=>time()+600,'scope'=>territorioScope($_SESSION),
            'snapshot'=>territorioSnapshot($rows),'family'=>$cfg['family'],'target'=>$target,'changes'=>$changes];
        territorioRespond(['ok'=>true,'token'=>$token,'total'=>count($rows),'changes'=>$changes,'target'=>$target,'family'=>$cfg['family']]);
    }
    $preview=$_SESSION['territorio_preview']??null;
    if (!$preview || $preview['expires']<time() || !hash_equals($preview['token'],(string)($input['token']??'')) || $preview['scope']!==territorioScope($_SESSION)) throw new DomainException('La revisión expiró. Prepara nuevamente la corrección.');
    $db->begin_transaction();$transaction=true;
    $target=territorioTarget($db,$preview['family'],(int)$preview['target']['id'],true);
    if ($target!==$preview['target']) throw new DomainException('El destino cambió. Revisa nuevamente la corrección.');
    $ids=array_keys($preview['snapshot']);$place=implode(',',array_fill(0,count($ids),'?'));
    [$scope,$types,$params]=territorioScope($_SESSION);
    $rows=territorioQuery($db,"SELECT l.id,l.id_comuna,l.id_zona,l.id_distrito,l.id_vendedor,l.id_jefe_venta FROM local l WHERE $scope AND l.id IN ($place) ORDER BY l.id FOR UPDATE",$types.str_repeat('i',count($ids)),array_merge($params,$ids));
    if (territorioSnapshot($rows)!==$preview['snapshot']) throw new DomainException('Algún local cambió después de la revisión. No se guardó nada; vuelve a revisar.');
    [$assign,$destParams]=territorioAsignacion($preview['family'],$target);
    territorioQuery($db,"UPDATE local l SET $assign WHERE $scope AND l.id IN ($place)",str_repeat('i',count($destParams)).$types.str_repeat('i',count($ids)),array_merge($destParams,$params,$ids));
    $db->commit();$transaction=false;unset($_SESSION['territorio_preview']);
    territorioRespond(['ok'=>true,'message'=>$preview['changes'].' locales corregidos.']);
} catch (Throwable $e) {
    if ($transaction && isset($db)) $db->rollback();
    $known=$e instanceof InvalidArgumentException || $e instanceof DomainException;
    if (!$known) error_log('[territorios] '.$e->getMessage());
    territorioRespond(['ok'=>false,'message'=>$known?$e->getMessage():'No se pudo completar la operación. Inténtalo nuevamente.'], $e instanceof DomainException?409:($known?422:500));
}
