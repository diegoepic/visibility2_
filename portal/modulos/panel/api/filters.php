<?php
// panel/api/filters.php
require __DIR__.'/_db.php';

$empresa_id    = intval($_SESSION['empresa_id'] ?? 0);
$division_ids  = ints((array) jread('division_ids', []));
$subdiv_ids    = ints((array) jread('subdivision_ids', []));
$tipos_form    = ints((array) jread('tipos', []));
$fecha_desde   = jread('fecha_desde', null);
$fecha_hasta   = jread('fecha_hasta', null);

$w = ['1=1']; $types=''; $args=[];
if ($empresa_id > 0) { $w[]='f.id_empresa=?'; $types.='i'; $args[]=$empresa_id; }
if ($division_ids) { $w[]='f.id_division IN ('.inClause($division_ids).')'; $types.=str_repeat('i', count($division_ids)); $args=array_merge($args,$division_ids); }
if ($subdiv_ids)   { $w[]='f.id_subdivision IN ('.inClause($subdiv_ids).')'; $types.=str_repeat('i', count($subdiv_ids));   $args=array_merge($args,$subdiv_ids); }
if ($tipos_form)   { $w[]='f.tipo IN ('.inClause($tipos_form).')';           $types.=str_repeat('i', count($tipos_form));   $args=array_merge($args,$tipos_form); }
if ($fecha_desde)  { $w[]='f.fechaInicio >= ?'; $types.='s'; $args[]=$fecha_desde.' 00:00:00'; }
if ($fecha_hasta)  { $w[]='(f.fechaTermino IS NULL OR f.fechaTermino <= ?)'; $types.='s'; $args[]=$fecha_hasta.' 23:59:59'; }
$where = implode(' AND ', $w);

// Divisiones
$sqlDiv = "SELECT DISTINCT f.id_division AS id, de.nombre AS nombre
           FROM formulario f
           LEFT JOIN division_empresa de ON de.id=f.id_division
           WHERE $where
           ORDER BY nombre";
$stmt=$mysqli->prepare($sqlDiv); if($types) bindMany($stmt, $types, $args); $stmt->execute();
$divs=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

// Subdivisiones
$sqlSub = "SELECT DISTINCT f.id_subdivision AS id, sd.nombre AS nombre
           FROM formulario f
           LEFT JOIN subdivision sd ON sd.id=f.id_subdivision
           WHERE $where
           ORDER BY sd.nombre";
$stmt=$mysqli->prepare($sqlSub); if($types) bindMany($stmt, $types, $args); $stmt->execute();
$subs=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

// Tipos disponibles
$sqlTipos = "SELECT DISTINCT f.tipo FROM formulario f WHERE $where ORDER BY f.tipo";
$stmt=$mysqli->prepare($sqlTipos); if($types) bindMany($stmt, $types, $args); $stmt->execute();
$tipos=array_map(fn($r)=>intval($r['tipo']), $stmt->get_result()->fetch_all(MYSQLI_ASSOC)); $stmt->close();

// Campañas
$sqlCamp = "SELECT f.id, f.nombre, f.fechaInicio, f.fechaTermino, f.id_division, f.id_subdivision, f.tipo
            FROM formulario f
            WHERE $where
            ORDER BY f.fechaInicio DESC, f.id DESC
            LIMIT 1000";
$stmt=$mysqli->prepare($sqlCamp); if($types) bindMany($stmt, $types, $args); $stmt->execute();
$campanias=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

// Distritos
$sqlDist = "SELECT DISTINCT d.id, d.nombre_distrito AS nombre
            FROM formulario f
            JOIN visita v ON v.id_formulario=f.id
            JOIN local l ON l.id=v.id_local
            JOIN distrito d ON d.id=l.id_distrito
            WHERE $where
            ORDER BY d.nombre_distrito";
$stmt=$mysqli->prepare($sqlDist); if($types) bindMany($stmt, $types, $args); $stmt->execute();
$distritos=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

// Jefes venta
$sqlJV = "SELECT DISTINCT j.id, j.nombre
          FROM formulario f
          JOIN visita v ON v.id_formulario=f.id
          JOIN local l ON l.id=v.id_local
          JOIN jefe_venta j ON j.id=l.id_jefe_venta
          WHERE $where
          ORDER BY j.nombre";
$stmt=$mysqli->prepare($sqlJV); if($types) bindMany($stmt, $types, $args); $stmt->execute();
$jefes=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

// Usuarios
$sqlUsr = "SELECT DISTINCT u.id, CONCAT(u.nombre,' ',u.apellido) AS nombre
           FROM formulario f
           JOIN visita v ON v.id_formulario=f.id
           JOIN usuario u ON u.id=v.id_usuario
           WHERE $where
           ORDER BY nombre";
$stmt=$mysqli->prepare($sqlUsr); if($types) bindMany($stmt, $types, $args); $stmt->execute();
$usuarios=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

// Tipos de pregunta
$qtypes = $mysqli->query("SELECT id, name FROM question_type ORDER BY id")->fetch_all(MYSQLI_ASSOC);

ok([
  'divisiones'     => $divs,
  'subdivisiones'  => $subs,
  'tipos_form'     => $tipos,
  'campanias'      => $campanias,
  'distritos'      => $distritos,
  'jefes_venta'    => $jefes,
  'usuarios'       => $usuarios,
  'question_types' => $qtypes
]);
