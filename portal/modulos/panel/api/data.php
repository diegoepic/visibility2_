<?php
// panel/api/data.php
require __DIR__.'/_db.php';
require __DIR__.'/dataset_core.php';

// Acepta el mismo payload que el front envía (filters + selected questions)
$payload = jraw() ?: $_POST;
if (!$payload) fail('Payload vacío');

$data = build_dataset($mysqli, $payload);
ok($data);
