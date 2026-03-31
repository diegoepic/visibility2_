<?php
declare(strict_types=1);

namespace PanelEncuesta\ValueObjects;

/**
 * Inmutable: encapsula el resultado de build_panel_encuesta_filters().
 * Contiene la cláusula SQL WHERE + tipos + parámetros para bind_param().
 */
final class WhereClause
{
    public function __construct(
        public readonly string $sql,    // "WHERE f.id_empresa=? AND ..."
        public readonly string $types,  // "iiss..."
        public readonly array  $params, // [103, 14, '2026-01-01', ...]
        public readonly array  $meta,   // applied_30d_default, has_scope, range_days, etc.
    ) {}
}
