<?php
declare(strict_types=1);

namespace PanelEncuesta\Services;

use PanelEncuesta\ValueObjects\FilterParams;
use PanelEncuesta\ValueObjects\WhereClause;

/**
 * Builds a WhereClause from FilterParams.
 *
 * Delegates to the global build_panel_encuesta_filters() which contains
 * all the tested filter logic (qfilters EXISTS, geospatial, date fallback, etc.).
 * This class is the OOP adapter — it does NOT duplicate the SQL logic.
 */
class FilterService
{
    public function build(FilterParams $p): WhereClause
    {
        [$whereSql, $types, $params, $meta] = build_panel_encuesta_filters(
            $p->empresaId,
            $p->userDiv,
            $p->src,
            $p->buildOpts
        );

        return new WhereClause($whereSql, $types, $params, $meta);
    }
}
