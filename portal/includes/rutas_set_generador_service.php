<?php
declare(strict_types=1);

class RutasSetGeneradorService
{
    private mysqli $conn;
    private int $idEmpresa;
    private int $idUsuarioSesion;

    public function __construct(mysqli $conn, int $idEmpresa, int $idUsuarioSesion)
    {
        $this->conn = $conn;
        $this->idEmpresa = $idEmpresa;
        $this->idUsuarioSesion = $idUsuarioSesion;
    }

    public function generarPreview(array $data): array
    {
        $idDivision = (int)($data['id_division'] ?? 0);
        $idCategoria = (int)($data['id_categoria'] ?? 0);

        $idSubdivision = (int)($data['subdivision_usuario'] ?? 0);
        $clasificacion = trim((string)($data['clasificacion_usuario'] ?? 'todos'));

        $objetivoDiarioBase = max(1, (int)($data['objetivo_diario_base'] ?? 18));
        $diasFrecuenciaObjetivo = max(1, (int)($data['dias_frecuencia_objetivo'] ?? 10));

        $maxKmEntreLocales = isset($data['max_km_entre_locales']) && is_numeric($data['max_km_entre_locales'])
            ? max(1.0, (float)$data['max_km_entre_locales'])
            : 80.0;

        $priorizarComunas = (int)($data['priorizar_comunas'] ?? 1) === 1;

        if ($idDivision <= 0) {
            throw new RuntimeException('Debes seleccionar una división.');
        }

        if ($idCategoria <= 0) {
            throw new RuntimeException('Debes seleccionar una categoría activa.');
        }

        $categoria = $this->validarCategoria($idCategoria, $idDivision);
        
        $fechaInicio = trim((string)($data['fecha_inicio'] ?? ''));
        $fechaTermino = trim((string)($data['fecha_termino'] ?? ''));
        
        if ($fechaInicio === '') {
            throw new RuntimeException('Debes indicar la fecha de inicio de la ruta.');
        }
        
        if ($fechaTermino === '') {
            throw new RuntimeException('Debes indicar la fecha término de la ruta.');
        }
        
        $dtFechaInicio = DateTime::createFromFormat('Y-m-d', $fechaInicio);
        if (!$dtFechaInicio || $dtFechaInicio->format('Y-m-d') !== $fechaInicio) {
            throw new RuntimeException('La fecha de inicio de ruta no es válida.');
        }
        
        $dtFechaTermino = DateTime::createFromFormat('Y-m-d', $fechaTermino);
        if (!$dtFechaTermino || $dtFechaTermino->format('Y-m-d') !== $fechaTermino) {
            throw new RuntimeException('La fecha término de ruta no es válida.');
        }
        
        $dtFechaInicio->setTime(0, 0, 0);
        $dtFechaTermino->setTime(0, 0, 0);
        
        if ($dtFechaTermino < $dtFechaInicio) {
            throw new RuntimeException('La fecha término no puede ser menor que la fecha de inicio.');
        }
        
        $diasHabilesPlanificacion = $this->contarDiasHabilesIncluyente($dtFechaInicio, $dtFechaTermino);
        
        if ($diasHabilesPlanificacion <= 0) {
            throw new RuntimeException('El rango seleccionado no contiene días hábiles.');
        }       

        $trabajadores = $this->obtenerTrabajadoresFiltrados(
            $idDivision,
            $idSubdivision,
            $clasificacion
        );
        

        if (!$trabajadores) {
            return [
                'categoria' => $categoria,
                'parametros' => $this->buildParametrosPreview(
                    $fechaInicio,
                    $fechaTermino,
                    $diasHabilesPlanificacion,
                    $objetivoDiarioBase,
                    $diasFrecuenciaObjetivo,
                    $maxKmEntreLocales,
                    $priorizarComunas
                ),
                'totales' => [
                    'trabajadores_filtrados' => 0,
                    'trabajadores_con_set' => 0,
                    'trabajadores_sin_set' => 0,
                    'trabajadores_con_conflicto' => 0,
                    'locales_total' => 0,
                    'locales_con_coordenadas' => 0,
                    'locales_sin_coordenadas' => 0,
                    'cumplen_frecuencia' => 0,
                    'no_cumplen_frecuencia' => 0,
                ],
                'data' => [],
                'sin_set' => [],
                'conflictos' => [],
                'ok_para_generar' => false,
            ];
        }

        $idsTrabajadores = array_map(
            static fn($t) => (int)$t['id_usuario'],
            $trabajadores
        );

        $setsIndividuales = $this->obtenerSetsIndividualesPorUsuario($idCategoria, $idDivision, $idsTrabajadores);
        $setsMasivos = $this->obtenerSetsMasivosCategoria($idCategoria, $idDivision);

        $idSetMasivo = null;
        $conflictos = [];

        if (count($setsMasivos) > 1) {
            $conflictos[] = [
                'tipo' => 'set_masivo_multiple',
                'mensaje' => 'Existe más de un set masivo activo para esta categoría. Debe quedar solo uno activo para generar masivamente.',
                'sets' => array_values($setsMasivos),
            ];
        } elseif (count($setsMasivos) === 1) {
            $idSetMasivo = (int)$setsMasivos[0]['id'];
        }

        $rows = [];
        $sinSet = [];

        $totales = [
            'trabajadores_filtrados' => count($trabajadores),
            'trabajadores_con_set' => 0,
            'trabajadores_sin_set' => 0,
            'trabajadores_con_conflicto' => 0,
            'locales_total' => 0,
            'locales_con_coordenadas' => 0,
            'locales_sin_coordenadas' => 0,
            'cumplen_frecuencia' => 0,
            'no_cumplen_frecuencia' => 0,
        ];

        foreach ($trabajadores as $trabajador) {
            $idUsuario = (int)$trabajador['id_usuario'];

            $setsUsuario = $setsIndividuales[$idUsuario] ?? [];
            $origenSet = '';
            $idRutaSet = null;
            $nombreSet = '';

            if (count($setsUsuario) > 1) {
                $totales['trabajadores_con_conflicto']++;

                $conflictos[] = [
                    'tipo' => 'set_individual_multiple',
                    'id_usuario' => $idUsuario,
                    'usuario' => $trabajador['usuario'],
                    'nombre' => $trabajador['nombre_completo'],
                    'mensaje' => 'El trabajador tiene más de un set individual activo en esta categoría.',
                    'sets' => array_values($setsUsuario),
                ];

                $rows[] = $this->buildRowSinSet(
                    $trabajador,
                    'conflicto',
                    'Tiene más de un set individual activo en esta categoría.'
                );

                continue;
            }

            if (count($setsUsuario) === 1) {
                $set = $setsUsuario[0];
                $idRutaSet = (int)$set['id'];
                $nombreSet = (string)$set['nombre'];
                $origenSet = 'individual';
            } elseif ($idSetMasivo !== null) {
                $idRutaSet = $idSetMasivo;
                $nombreSet = (string)$setsMasivos[0]['nombre'];
                $origenSet = 'masiva';
            }

            if ($idRutaSet === null) {
                $totales['trabajadores_sin_set']++;

                $sinSet[] = [
                    'id_usuario' => $idUsuario,
                    'usuario' => $trabajador['usuario'],
                    'nombre' => $trabajador['nombre_completo'],
                    'motivo' => 'No tiene set individual y no existe set masivo activo para la categoría.',
                ];

                $rows[] = $this->buildRowSinSet(
                    $trabajador,
                    'sin_set',
                    'No tiene set individual y no existe set masivo activo para la categoría.'
                );

                continue;
            }

            $resumenLocales = $this->obtenerResumenLocalesSetUsuario($idRutaSet, $idUsuario);

            if ($resumenLocales['total_locales'] <= 0) {
                $totales['trabajadores_sin_set']++;

                $sinSet[] = [
                    'id_usuario' => $idUsuario,
                    'usuario' => $trabajador['usuario'],
                    'nombre' => $trabajador['nombre_completo'],
                    'motivo' => 'El set existe, pero no tiene locales asociados para este trabajador.',
                ];

                $rows[] = $this->buildRowSinSet(
                    $trabajador,
                    'sin_locales',
                    'El set existe, pero no tiene locales asociados para este trabajador.',
                    $idRutaSet,
                    $nombreSet,
                    $origenSet
                );

                continue;
            }

            $meta = $this->calcularObjetivoDiarioUsuario(
                (int)$resumenLocales['total_locales'],
                $objetivoDiarioBase,
                $diasFrecuenciaObjetivo
            );

            $rutasEstimadasTotales = $meta['objetivo_diario'] > 0
                ? (int)ceil((int)$resumenLocales['locales_con_coordenadas'] / $meta['objetivo_diario'])
                : 0;
            
            $rutasDentroPeriodo = min($rutasEstimadasTotales, $diasHabilesPlanificacion);
            
            $localesProgramablesPeriodo = min(
                (int)$resumenLocales['locales_con_coordenadas'],
                $rutasDentroPeriodo * max(1, (int)$meta['objetivo_diario'])
            );
            
            $localesFueraPeriodo = max(
                0,
                (int)$resumenLocales['locales_con_coordenadas'] - $localesProgramablesPeriodo
            );

            $estadoPreview = 'ok';
            if ((int)$resumenLocales['locales_con_coordenadas'] <= 0) {
                $estadoPreview = 'sin_coordenadas';
            } elseif (!$meta['cumple_frecuencia']) {
                $estadoPreview = 'no_cumple_frecuencia';
            }

            $totales['trabajadores_con_set']++;
            $totales['locales_total'] += (int)$resumenLocales['total_locales'];
            $totales['locales_con_coordenadas'] += (int)$resumenLocales['locales_con_coordenadas'];
            $totales['locales_sin_coordenadas'] += (int)$resumenLocales['locales_sin_coordenadas'];

            if ($meta['cumple_frecuencia']) {
                $totales['cumplen_frecuencia']++;
            } else {
                $totales['no_cumplen_frecuencia']++;
            }

            $rows[] = [
                'id_usuario' => $idUsuario,
                'usuario' => $trabajador['usuario'],
                'nombre' => $trabajador['nombre_completo'],
                'division_usuario' => $trabajador['division_usuario'],
                'subdivision_usuario' => $trabajador['subdivision_usuario'],
                'clasificacion_usuario' => $trabajador['clasificacion_usuario'],

                'estado_preview' => $estadoPreview,
                'motivo' => $meta['motivo'],

                'id_ruta_set' => $idRutaSet,
                'nombre_set' => $nombreSet,
                'origen_set' => $origenSet,

                'total_locales' => (int)$resumenLocales['total_locales'],
                'locales_con_coordenadas' => (int)$resumenLocales['locales_con_coordenadas'],
                'locales_sin_coordenadas' => (int)$resumenLocales['locales_sin_coordenadas'],

                'fecha_termino' => $fechaTermino,
                'dias_habiles_planificacion' => $diasHabilesPlanificacion,
                
                'objetivo_diario_base' => $objetivoDiarioBase,
                'objetivo_por_frecuencia' => $meta['objetivo_por_frecuencia'],
                'objetivo_diario_calculado' => $meta['objetivo_diario'],
                'dias_frecuencia_objetivo' => $diasFrecuenciaObjetivo,
                'dias_ciclo_estimado' => $meta['dias_ciclo_estimado'],
                'cumple_frecuencia' => $meta['cumple_frecuencia'],

                'rutas_estimadas' => $rutasEstimadasTotales,
                'rutas_dentro_periodo' => $rutasDentroPeriodo,
                'locales_programables_periodo' => $localesProgramablesPeriodo,
                'locales_fuera_periodo' => $localesFueraPeriodo,
                'max_km_entre_locales' => $maxKmEntreLocales,
                'priorizar_comunas' => $priorizarComunas,
            ];
        }

        $okParaGenerar = $totales['trabajadores_con_set'] > 0
            && $totales['trabajadores_con_conflicto'] === 0
            && count($conflictos) === 0;

        return [
            'categoria' => $categoria,
            'parametros' => $this->buildParametrosPreview(
                $fechaInicio,
                $fechaTermino,
                $diasHabilesPlanificacion,
                $objetivoDiarioBase,
                $diasFrecuenciaObjetivo,
                $maxKmEntreLocales,
                $priorizarComunas
            ),
            'totales' => $totales,
            'data' => $rows,
            'sin_set' => $sinSet,
            'conflictos' => $conflictos,
            'ok_para_generar' => $okParaGenerar,
        ];
    }

        private function buildParametrosPreview(
            string $fechaInicio,
            string $fechaTermino,
            int $diasHabilesPlanificacion,
            int $objetivoDiarioBase,
            int $diasFrecuenciaObjetivo,
            float $maxKmEntreLocales,
            bool $priorizarComunas
        ): array {
            return [
                'fecha_inicio' => $fechaInicio,
                'fecha_termino' => $fechaTermino,
                'dias_habiles_planificacion' => $diasHabilesPlanificacion,
                'objetivo_diario_base' => $objetivoDiarioBase,
                'dias_frecuencia_objetivo' => $diasFrecuenciaObjetivo,
                'max_km_entre_locales' => $maxKmEntreLocales,
                'priorizar_comunas' => $priorizarComunas,
            ];
        }

    private function validarCategoria(int $idCategoria, int $idDivision): array
    {
        $sql = "
            SELECT
                c.id,
                c.nombre,
                c.descripcion,
                c.id_division,
                d.nombre AS division_nombre
            FROM ruta_set_categoria c
            INNER JOIN division_empresa d
                ON d.id = c.id_division
            WHERE c.id = ?
              AND c.id_empresa = ?
              AND c.id_division = ?
              AND c.estado = 'activa'
            LIMIT 1
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('iii', $idCategoria, $this->idEmpresa, $idDivision);
        $stmt->execute();

        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new RuntimeException('La categoría seleccionada no existe, no está activa o no pertenece a la división seleccionada.');
        }

        return $row;
    }

    private function obtenerTrabajadoresFiltrados(
        int $idDivision,
        int $idSubdivision,
        string $clasificacion
    ): array {
        $types = 'ii';
        $params = [$this->idEmpresa, $idDivision];

        $where = "
            WHERE u.id_empresa = ?
              AND u.id_division = ?
              AND u.id_perfil = 3
              AND u.activo = 1
        ";

        if ($idSubdivision > 0) {
            $where .= " AND u.id_subdivision = ? ";
            $types .= 'i';
            $params[] = $idSubdivision;
        }

        if ($clasificacion === 'interno' || $clasificacion === 'externo') {
            $where .= " AND u.clasificacion_usuario = ? ";
            $types .= 's';
            $params[] = $clasificacion;
        }

        $sql = "
            SELECT
                u.id AS id_usuario,
                u.usuario,
                UPPER(CONCAT(COALESCE(u.nombre, ''), ' ', COALESCE(u.apellido, ''))) AS nombre_completo,
                COALESCE(u.clasificacion_usuario, 'sin_clasificacion') AS clasificacion_usuario,
                du.nombre AS division_usuario,
                COALESCE(su.nombre, '') AS subdivision_usuario
            FROM usuario u
            LEFT JOIN division_empresa du
                ON du.id = u.id_division
            LEFT JOIN subdivision su
                ON su.id = u.id_subdivision
            {$where}
            ORDER BY u.nombre ASC, u.apellido ASC, u.usuario ASC
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        $res = $stmt->get_result();
        $data = [];

        while ($row = $res->fetch_assoc()) {
            $data[] = $row;
        }

        $stmt->close();

        return $data;
    }

    private function obtenerSetsIndividualesPorUsuario(
        int $idCategoria,
        int $idDivision,
        array $idsTrabajadores
    ): array {
        if (!$idsTrabajadores) {
            return [];
        }

        $idsTrabajadores = array_values(array_unique(array_map('intval', $idsTrabajadores)));
        $placeholders = implode(',', array_fill(0, count($idsTrabajadores), '?'));

        $types = 'iii' . str_repeat('i', count($idsTrabajadores));
        $params = array_merge(
            [$this->idEmpresa, $idDivision, $idCategoria],
            $idsTrabajadores
        );

        $sql = "
            SELECT
                rs.id,
                rs.nombre,
                rs.id_usuario,
                rs.tipo_scope,
                rs.estado,
                rs.created_at
            FROM ruta_set rs
            WHERE rs.id_empresa = ?
              AND rs.id_division = ?
              AND rs.id_categoria = ?
              AND rs.tipo_scope = 'individual'
              AND rs.id_usuario IN ($placeholders)
              AND rs.origen = 'archivo'
              AND rs.estado IN ('borrador', 'validada')
              AND COALESCE(rs.es_set_activo, 1) = 1
            ORDER BY rs.id_usuario ASC, rs.created_at DESC, rs.id DESC
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        $res = $stmt->get_result();
        $data = [];

        while ($row = $res->fetch_assoc()) {
            $idUsuario = (int)$row['id_usuario'];

            if (!isset($data[$idUsuario])) {
                $data[$idUsuario] = [];
            }

            $data[$idUsuario][] = $row;
        }

        $stmt->close();

        return $data;
    }

private function contarDiasHabilesIncluyente(DateTime $inicio, DateTime $termino): int
{
    $cursor = clone $inicio;
    $fin = clone $termino;

    $total = 0;

    while ($cursor <= $fin) {
        $diaSemana = (int)$cursor->format('N'); // 1 lunes, 7 domingo

        if ($diaSemana <= 5) {
            $total++;
        }

        $cursor->modify('+1 day');
    }

    return $total;
}

    private function obtenerSetsMasivosCategoria(int $idCategoria, int $idDivision): array
    {
        $sql = "
            SELECT
                rs.id,
                rs.nombre,
                rs.tipo_scope,
                rs.estado,
                rs.created_at
            FROM ruta_set rs
            WHERE rs.id_empresa = ?
              AND rs.id_division = ?
              AND rs.id_categoria = ?
              AND rs.tipo_scope = 'masiva'
              AND rs.origen = 'archivo'
              AND rs.estado IN ('borrador', 'validada')
              AND COALESCE(rs.es_set_activo, 1) = 1
            ORDER BY rs.created_at DESC, rs.id DESC
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('iii', $this->idEmpresa, $idDivision, $idCategoria);
        $stmt->execute();

        $res = $stmt->get_result();

        $data = [];
        while ($row = $res->fetch_assoc()) {
            $data[] = $row;
        }

        $stmt->close();

        return $data;
    }

    private function obtenerResumenLocalesSetUsuario(int $idRutaSet, int $idUsuario): array
    {
        $sql = "
            SELECT
                COUNT(DISTINCT rsd.id_local) AS total_locales,

                COUNT(DISTINCT CASE
                    WHEN l.lat IS NOT NULL
                     AND l.lng IS NOT NULL
                     AND l.lat <> ''
                     AND l.lng <> ''
                     AND l.lat REGEXP '^-?[0-9]+(\\.[0-9]+)?$'
                     AND l.lng REGEXP '^-?[0-9]+(\\.[0-9]+)?$'
                    THEN rsd.id_local
                END) AS locales_con_coordenadas,

                COUNT(DISTINCT CASE
                    WHEN l.id IS NULL
                      OR l.lat IS NULL
                      OR l.lng IS NULL
                      OR l.lat = ''
                      OR l.lng = ''
                      OR NOT (l.lat REGEXP '^-?[0-9]+(\\.[0-9]+)?$')
                      OR NOT (l.lng REGEXP '^-?[0-9]+(\\.[0-9]+)?$')
                    THEN rsd.id_local
                END) AS locales_sin_coordenadas

            FROM ruta_set_detalle rsd
            LEFT JOIN local l
                ON l.id = rsd.id_local
               AND l.id_empresa = ?
               AND (
                    l.deleted_at IS NULL
                    OR CAST(l.deleted_at AS CHAR(19)) = '0000-00-00 00:00:00'
                    OR CAST(l.deleted_at AS CHAR(10)) = '0000-00-00'
               )
            WHERE rsd.id_ruta_set = ?
              AND rsd.id_usuario = ?
              AND rsd.estado <> 'omitido'
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('iii', $this->idEmpresa, $idRutaSet, $idUsuario);
        $stmt->execute();

        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return [
            'total_locales' => (int)($row['total_locales'] ?? 0),
            'locales_con_coordenadas' => (int)($row['locales_con_coordenadas'] ?? 0),
            'locales_sin_coordenadas' => (int)($row['locales_sin_coordenadas'] ?? 0),
        ];
    }

    private function calcularObjetivoDiarioUsuario(
        int $totalLocalesUsuario,
        int $objetivoDiarioBase,
        int $diasFrecuenciaObjetivo
    ): array {
        $totalLocalesUsuario = max(0, $totalLocalesUsuario);
        $objetivoDiarioBase = max(1, $objetivoDiarioBase);
        $diasFrecuenciaObjetivo = max(1, $diasFrecuenciaObjetivo);

        if ($totalLocalesUsuario <= 0) {
            return [
                'objetivo_diario' => 0,
                'objetivo_por_frecuencia' => 0,
                'dias_ciclo_estimado' => 0,
                'cumple_frecuencia' => false,
                'motivo' => 'Sin locales asignados',
            ];
        }

        $objetivoPorFrecuencia = (int)ceil($totalLocalesUsuario / $diasFrecuenciaObjetivo);

        /*
            Si la cartera es pequeña, bajamos la meta diaria.
            Si la cartera es grande, respetamos el objetivo operativo base.
        */
        $objetivoDiario = min($objetivoDiarioBase, max(1, $objetivoPorFrecuencia));

        $diasCicloEstimado = (int)ceil($totalLocalesUsuario / max(1, $objetivoDiario));
        $cumpleFrecuencia = $diasCicloEstimado <= $diasFrecuenciaObjetivo;

        return [
            'objetivo_diario' => $objetivoDiario,
            'objetivo_por_frecuencia' => $objetivoPorFrecuencia,
            'dias_ciclo_estimado' => $diasCicloEstimado,
            'cumple_frecuencia' => $cumpleFrecuencia,
            'motivo' => $cumpleFrecuencia
                ? 'Cumple frecuencia objetivo'
                : 'No cumple frecuencia objetivo con el objetivo diario base definido',
        ];
    }

    private function buildRowSinSet(
        array $trabajador,
        string $estado,
        string $motivo,
        ?int $idRutaSet = null,
        string $nombreSet = '',
        string $origenSet = ''
    ): array {
        return [
            'id_usuario' => (int)$trabajador['id_usuario'],
            'usuario' => $trabajador['usuario'],
            'nombre' => $trabajador['nombre_completo'],
            'division_usuario' => $trabajador['division_usuario'],
            'subdivision_usuario' => $trabajador['subdivision_usuario'],
            'clasificacion_usuario' => $trabajador['clasificacion_usuario'],

            'estado_preview' => $estado,
            'motivo' => $motivo,

            'id_ruta_set' => $idRutaSet,
            'nombre_set' => $nombreSet,
            'origen_set' => $origenSet,

            'total_locales' => 0,
            'locales_con_coordenadas' => 0,
            'locales_sin_coordenadas' => 0,

            'fecha_inicio' => '',
            'fecha_termino' => '',
            'dias_habiles_planificacion' => 0,
            'rutas_dentro_periodo' => 0,
            'locales_programables_periodo' => 0,
            'locales_fuera_periodo' => 0,
            'objetivo_diario_base' => 0,
            'objetivo_por_frecuencia' => 0,
            'objetivo_diario_calculado' => 0,
            'dias_frecuencia_objetivo' => 0,
            'dias_ciclo_estimado' => 0,
            'cumple_frecuencia' => false,

            'rutas_estimadas' => 0,
            'max_km_entre_locales' => 0,
            'priorizar_comunas' => false,
        ];
    }
}