<?php
declare(strict_types=1);

function territorioConfig(string $type): array {
    $types = [
        'vendedor' => ['table'=>'vendedor','name'=>'nombre_vendedor','parent'=>null,'key'=>'vd.id','family'=>'vendedor'],
        'jefe_venta' => ['table'=>'jefe_venta','name'=>'nombre','parent'=>null,'key'=>'jv.id','family'=>'jefe_venta'],
        'region' => ['table'=>'region', 'name'=>'region', 'parent'=>null, 'key'=>'r.id', 'family'=>'comuna'],
        'comuna' => ['table'=>'comuna', 'name'=>'comuna', 'parent'=>'id_region', 'key'=>'c.id', 'family'=>'comuna'],
        'zona' => ['table'=>'zona', 'name'=>'nombre_zona', 'parent'=>null, 'key'=>'z.id', 'family'=>'distrito'],
        'distrito' => ['table'=>'distrito', 'name'=>'nombre_distrito', 'parent'=>'id_zona', 'key'=>'d.id', 'family'=>'distrito'],
    ];
    if (!isset($types[$type])) throw new InvalidArgumentException('Tipo de territorio inválido.');
    return $types[$type];
}

function territorioScope(array $session): array {
    $company = (int)($session['empresa_id'] ?? 0);
    $division = (int)($session['division_id'] ?? 0);
    if ($company <= 0 || $division <= 0) throw new InvalidArgumentException('La sesión no tiene empresa o división válida.');
    return $division === 1
        ? ['l.deleted_at IS NULL AND l.id_empresa = ?', 'i', [$company]]
        : ['l.deleted_at IS NULL AND l.id_empresa = ? AND l.id_division = ?', 'ii', [$company, $division]];
}

function territorioIds($values): array {
    if (!is_array($values) || !$values || count($values) > 5000) throw new InvalidArgumentException('Selecciona entre 1 y 5000 locales.');
    $ids = [];
    foreach ($values as $id) {
        if (!(is_int($id) || (is_string($id) && ctype_digit($id))) || (int)$id <= 0) throw new InvalidArgumentException('La selección contiene IDs inválidos.');
        $ids[] = (int)$id;
    }
    $ids = array_values(array_unique($ids)); sort($ids);
    return $ids;
}

function territorioSnapshot(array $rows): array {
    $snapshot = [];
    foreach ($rows as $r) {
        $snapshot[(int)$r['id']] = [
            'id_vendedor'=>isset($r['id_vendedor'])?(int)$r['id_vendedor']:null,
            'id_jefe_venta'=>isset($r['id_jefe_venta'])?(int)$r['id_jefe_venta']:null,
            'id_comuna'=>$r['id_comuna'] === null ? null : (int)$r['id_comuna'],
            'id_zona'=>$r['id_zona'] === null ? null : (int)$r['id_zona'],
            'id_distrito'=>$r['id_distrito'] === null ? null : (int)$r['id_distrito'],
        ];
    }
    ksort($snapshot);
    return $snapshot;
}

function territorioQuery(mysqli $db, string $sql, string $types = '', array $params = []): array {
    $stmt = $db->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function territorioJoins(): string {
    return ' FROM local l LEFT JOIN comuna c ON c.id=l.id_comuna
        LEFT JOIN region r ON r.id=c.id_region LEFT JOIN distrito d ON d.id=l.id_distrito
        LEFT JOIN zona z ON z.id=l.id_zona LEFT JOIN vendedor vd ON vd.id=l.id_vendedor LEFT JOIN jefe_venta jv ON jv.id=l.id_jefe_venta ';
}

function territorioFilter(array $session, array $input): array {
    $config = territorioConfig((string)($input['tipo'] ?? 'region'));
    [$where, $types, $params] = territorioScope($session);
    $id = filter_var($input['source_id'] ?? null, FILTER_VALIDATE_INT);
    if ($id === false || $id === null || $id < 0) throw new InvalidArgumentException('Selecciona un territorio válido.');
    if ($id === 0) $where .= ' AND ' . $config['key'] . ' IS NULL';
    else { $where .= ' AND ' . $config['key'] . ' = ?'; $types .= 'i'; $params[] = $id; }
    $q = trim((string)($input['search'] ?? ''));
    if ($q !== '') {
        $where .= " AND (l.nombre LIKE ? OR l.codigo LIKE ? OR l.direccion LIKE ? OR CAST(l.id AS CHAR) = ?)";
        $types .= 'ssss'; array_push($params, '%'.$q.'%', '%'.$q.'%', '%'.$q.'%', $q);
    }
    if (!empty($input['issues'])) {
        $where .= ' AND (c.id IS NULL OR r.id IS NULL OR z.id IS NULL OR d.id IS NULL OR NOT (l.id_zona <=> d.id_zona))';
    }
    return [$where, $types, $params];
}

function territorioTarget(mysqli $db, string $family, int $id, bool $lock = false): array {
    if ($id <= 0) throw new InvalidArgumentException('Selecciona una comuna o distrito destino.');
    if (in_array($family,['vendedor','jefe_venta'],true)) {
        $cfg=territorioConfig($family);
        $row=territorioQuery($db,"SELECT id, {$cfg['name']} AS nombre, NULL AS parent_id, NULL AS parent_nombre FROM {$cfg['table']} WHERE id=?".($lock?' FOR UPDATE':''),'i',[$id])[0]??null;
        if (!$row) throw new InvalidArgumentException('El destino ya no existe.');
        return $row;
    }
    $sql = $family === 'comuna'
        ? 'SELECT c.id, c.comuna AS nombre, r.id AS parent_id, r.region AS parent_nombre FROM comuna c JOIN region r ON r.id=c.id_region WHERE c.id=?'
        : 'SELECT d.id, d.nombre_distrito AS nombre, z.id AS parent_id, z.nombre_zona AS parent_nombre FROM distrito d JOIN zona z ON z.id=d.id_zona WHERE d.id=?';
    $row = territorioQuery($db, $sql . ($lock ? ' FOR UPDATE' : ''), 'i', [$id])[0] ?? null;
    if (!$row) throw new InvalidArgumentException('El destino o su región/zona ya no existe.');
    return $row;
}

function territorioAsignacion(string $family,array $target): array {
    if ($family==='comuna') return ['id_comuna=?',[(int)$target['id']]];
    if ($family==='distrito') return ['id_distrito=?,id_zona=?',[(int)$target['id'],(int)$target['parent_id']]];
    if (in_array($family,['vendedor','jefe_venta'],true)) return ['id_'.$family.'=?',[(int)$target['id']]];
    throw new InvalidArgumentException('Destino inválido.');
}
