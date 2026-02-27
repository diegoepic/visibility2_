<?php
declare(strict_types=1);

namespace PanelEncuesta\Services;

use PanelEncuesta\Repositories\StatsRepository;

/**
 * Orquesta las estadísticas de preguntas según su tipo.
 * Recibe los parámetros ya validados y delega al StatsRepository.
 */
class StatsService
{
    public function __construct(private StatsRepository $repo) {}

    /**
     * Computa estadísticas para una pregunta dado su tipo y el WHERE ya construido.
     *
     * @param int    $tipo      id_question_type (1=Sí/No, 2=única, 3=múltiple, 5=num, 7=foto)
     * @param string $whereSql  "WHERE ..." ya construido (incluye filtro de pregunta)
     * @param string $types     tipos para bind_param
     * @param array  $params    valores para bind_param
     * @return array            [total, buckets, numeric, meta]
     */
    public function compute(int $tipo, string $whereSql, string $types, array $params): array
    {
        $result = match ($tipo) {
            1       => $this->repo->fetchBinary($whereSql, $types, $params),
            2, 3    => $this->repo->fetchOptions($whereSql, $types, $params),
            5       => $this->repo->fetchNumeric($whereSql, $types, $params),
            7       => $this->repo->fetchPhoto($whereSql, $types, $params),
            default => $this->repo->fetchFallback($whereSql, $types, $params),
        };

        return $result;
    }

    /**
     * Construye el WHERE para stats dado el modo de pregunta (exact/set/vset).
     * Agrega el filtro de pregunta al WHERE base que viene de los parámetros del endpoint.
     *
     * @param string $whereBase  WHERE base ya construido (empresa, fechas, local, etc.)
     * @param string $typesBase  tipos base
     * @param array  $paramsBase params base
     * @param string $mode       'exact'|'set'|'vset'
     * @param string $qidRaw     ID o hash de la pregunta
     * @return array             [$whereSql, $types, $params]
     */
    public static function appendQuestionScope(
        string $whereBase,
        string $typesBase,
        array $paramsBase,
        string $mode,
        string $qidRaw
    ): array {
        if ($mode === 'exact') {
            $extra  = ' AND fq.id=?';
            $types  = $typesBase . 'i';
            $params = array_merge($paramsBase, [(int)$qidRaw]);
        } elseif ($mode === 'set') {
            $extra  = ' AND fq.id_question_set_question=?';
            $types  = $typesBase . 'i';
            $params = array_merge($paramsBase, [(int)$qidRaw]);
        } else {
            // vset: usa v_signature
            $extra  = " AND ((fq.id_question_set_question IS NULL OR fq.id_question_set_question=0) AND fq.v_signature=?)";
            $types  = $typesBase . 's';
            $params = array_merge($paramsBase, [strtolower($qidRaw)]);
        }

        // Inject into WHERE (replace "WHERE" with "WHERE ... AND extra" or append)
        $whereSql = rtrim($whereBase);
        if (str_starts_with(strtolower(ltrim($whereSql)), 'where')) {
            $whereSql = $whereSql . $extra;
        } else {
            $whereSql = 'WHERE 1=1' . $extra;
        }

        return [$whereSql, $types, $params];
    }
}
