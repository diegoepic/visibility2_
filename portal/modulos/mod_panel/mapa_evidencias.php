<?php
// Funciones de lectura compartidas por el endpoint y sus pruebas.
function mapaFotoUrl(string $value): string
{
    $value = trim(html_entity_decode($value, ENT_QUOTES, 'UTF-8'));
    if ($value === '' || preg_match('/[\x00-\x1f\\\\|]/', $value)) return '';
    if (preg_match('#^https?://#i', $value)) {
        return filter_var($value, FILTER_VALIDATE_URL) ? $value : '';
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*:|^//#i', $value)) return '';
    $value = preg_replace('#^(?:\.\./)+app/#', '', $value);
    $value = ltrim($value, '/');
    if (strpos($value, 'visibility2/app/') === 0) $value = substr($value, 16);
    elseif (strpos($value, 'app/') === 0) $value = substr($value, 4);
    if (strpos($value, 'uploads/') !== 0 || strpos(rawurldecode($value), '..') !== false) return '';
    return '/visibility2/app/' . str_replace(' ', '%20', $value);
}

function mapaFotosExtraer(string $value, bool $esCampoFoto = false): array
{
    $value = trim($value);
    if ($value === '') return [];
    $decoded = json_decode($value, true);
    if (is_array($decoded)) {
        $urls = [];
        array_walk_recursive($decoded, function ($item) use (&$urls, $esCampoFoto) {
            if (is_string($item)) $urls = array_merge($urls, mapaFotosExtraer($item, $esCampoFoto));
        });
        return array_values(array_unique($urls));
    }
    $direct = mapaFotoUrl($value);
    if ($direct !== '' && ($esCampoFoto || preg_match('/\.(jpe?g|png|gif|webp)(?:[?#].*)?$/i', $direct))) return [$direct];
    preg_match_all('~(?:https?://|/(?:visibility2/)?app/|(?:\.\./)+app/|/?uploads/)[^\s<>"\'|]+?\.(?:jpe?g|png|gif|webp)(?:\?[^\s<>"\'|]*)?~i', $value, $matches);
    return array_values(array_unique(array_filter(array_map('mapaFotoUrl', $matches[0]))));
}

function mapaEvidenciaAgregar(array &$items, array &$seen, array $row, string $tipo, string $label, string $value, bool $esCampoFoto = false): void
{
    foreach (mapaFotosExtraer($value, $esCampoFoto) as $url) {
        $key = implode('|', [$row['id_formulario'], $row['id_usuario'], $label, $url]);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $items[] = [
            'tipo' => $tipo, 'etiqueta' => $label, 'url' => $url,
            'id_formulario' => (int)$row['id_formulario'],
            'id_usuario' => (int)$row['id_usuario'],
            'campana' => $row['nombre_campana'] ?? '',
            'usuario' => $row['usuario_local'] ?? '',
            'fecha' => $row['fecha'] ?? '',
            'visita' => $row['visita_id'] ?? null,
            'observacion' => $row['observacion'] ?? '',
        ];
    }
}

function mapaEstadoEtiqueta(string $estado, string $observacion): string
{
    $texto = strtolower(str_replace('_', ' ', $estado . ' ' . $observacion));
    if (strpos($texto, 'local cerrado') !== false) return 'Local cerrado';
    foreach (['no permit', 'no autoriza', 'sin autoriza', 'rechaz'] as $motivo) {
        if (strpos($texto, $motivo) !== false) return 'No permitieron realizar la gestión';
    }
    if (strpos($texto, 'local no existe') !== false) return 'Local no existe';
    return str_replace('_', ' ', $estado !== '' && $estado !== '-' ? $estado : 'Estado del local');
}
