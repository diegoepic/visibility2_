<?php
class LocalModel
{
    private mysqli $conn;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    public function getCampanaNombre(int $idCampana, int $empresaId): string
    {
        $stmt = $this->conn->prepare(
            "SELECT COALESCE(nombre, CONCAT('Campaña #', id)) FROM formulario WHERE id = ? AND id_empresa = ? LIMIT 1"
        );
        $stmt->bind_param('ii', $idCampana, $empresaId);
        $stmt->execute();
        $stmt->bind_result($nombre);
        $stmt->fetch();
        $stmt->close();

        return $nombre ?: 'Campaña #' . $idCampana;
    }

    public function getUsuariosByCampana(int $idCampana, int $empresaId): array
    {
        $usuarios = [];
        $stmt = $this->conn->prepare(
            "SELECT DISTINCT u.id, COALESCE(NULLIF(TRIM(u.usuario),''), CONCAT('user#',u.id)) AS usuario,
                    TRIM(CONCAT(COALESCE(u.nombre,''), ' ', COALESCE(u.apellido,''))) AS nombre
             FROM gestion_visita gv
             JOIN usuario u ON u.id = gv.id_usuario
             JOIN formulario f ON f.id = gv.id_formulario AND f.id_empresa = ?
             WHERE gv.id_formulario = ?
             ORDER BY usuario ASC"
        );
        $stmt->bind_param('ii', $empresaId, $idCampana);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $usuarios[] = $r;
        }
        $stmt->close();

        return $usuarios;
    }

    public function getEstadosByCampana(int $idCampana, int $empresaId): array
    {
        $estados = [];
        $stmt = $this->conn->prepare(
            "SELECT DISTINCT gv.estado_gestion
             FROM gestion_visita gv
             JOIN formulario f ON f.id = gv.id_formulario AND f.id_empresa = ?
             WHERE gv.id_formulario = ?
               AND gv.estado_gestion IS NOT NULL
               AND TRIM(gv.estado_gestion) <> ''
             ORDER BY gv.estado_gestion ASC"
        );
        $stmt->bind_param('ii', $empresaId, $idCampana);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $estados[] = $r['estado_gestion'];
        }
        $stmt->close();

        return $estados;
    }

    public function getLocalesPage(array $filters): array
    {
        $idCampana  = (int)$filters['idCampana'];
        $empresaId  = (int)($filters['empresaId'] ?? $filters['empresa_id'] ?? 0);
        $filterCodigo = $filters['filterCodigo'] ?? '';
        $filterEstado = $filters['filterEstado'] ?? '';
        $filterUserId = (int)($filters['filterUserId'] ?? 0);
        $filterDesde  = $filters['filterDesde'] ?? null;
        $filterHasta  = $filters['filterHasta'] ?? null;
        $perPage      = (int)($filters['perPage'] ?? 50);
        $page         = max(1, (int)($filters['page'] ?? 1));
        $offset       = ($page - 1) * $perPage;

        $baseSql = "
          SELECT
            l.id AS idLocal,
            MIN(l.codigo) AS cod_min,
            MAX(fq.is_priority) AS is_priority,
            last.fecha_visita AS last_fecha,
            CASE
              WHEN agg.has_impl_aud = 1 THEN 'implementado_auditado'
              WHEN agg.has_impl_any = 1 THEN 'solo_implementado'
              WHEN agg.has_audit    = 1 THEN 'solo_auditoria'
              ELSE last.estado_gestion
            END AS estado_agg
          FROM formularioQuestion fq
          JOIN local      l ON l.id = fq.id_local
          JOIN formulario f ON f.id = fq.id_formulario AND f.id_empresa = ?
          LEFT JOIN (
            SELECT gv2.*
            FROM gestion_visita gv2
            JOIN (
              SELECT id_local,
                     MAX(CONCAT(DATE_FORMAT(fecha_visita,'%Y%m%d%H%i%s'), LPAD(id,10,'0'))) AS max_key
              FROM gestion_visita
              WHERE id_formulario = ?
              GROUP BY id_local
            ) s ON s.id_local = gv2.id_local
               AND CONCAT(DATE_FORMAT(gv2.fecha_visita,'%Y%m%d%H%i%s'), LPAD(gv2.id,10,'0')) = s.max_key
            WHERE gv2.id_formulario = ?
          ) last ON last.id_local = l.id
          LEFT JOIN (
            SELECT id_local,
                   MAX(estado_gestion = 'implementado_auditado')                        AS has_impl_aud,
                   MAX(estado_gestion IN ('solo_implementado','implementado_auditado')) AS has_impl_any,
                   MAX(estado_gestion = 'solo_auditoria')                               AS has_audit
            FROM gestion_visita
            WHERE id_formulario = ?
            GROUP BY id_local
          ) agg ON agg.id_local = l.id
          WHERE fq.id_formulario = ?
        ";

        $baseParams = [$empresaId, $idCampana, $idCampana, $idCampana, $idCampana];
        $baseTypes  = 'iiiii';

        if ($filterCodigo !== '') {
            $baseSql     .= ' AND l.codigo LIKE ? ';
            $baseParams[] = "%{$filterCodigo}%";
            $baseTypes   .= 's';
        }

        if ($filterUserId > 0) {
            $baseSql     .= ' AND EXISTS (
              SELECT 1 FROM gestion_visita gx
              WHERE gx.id_formulario = ? AND gx.id_local = l.id AND gx.id_usuario = ?
            )';
            $baseParams[] = $idCampana;  $baseTypes .= 'i';
            $baseParams[] = $filterUserId; $baseTypes .= 'i';
        }

        $baseSql .= ' GROUP BY l.id ';

        $countSql = "SELECT COUNT(*) FROM ( $baseSql ) t WHERE 1=1";
        $countParams = $baseParams; $countTypes = $baseTypes;

        if ($filterEstado !== '') {
            if ($filterEstado === 'sin_datos') {
                $countSql .= ' AND t.last_fecha IS NULL ';
            } else {
                $countSql .= ' AND t.estado_agg = ? ';
                $countParams[] = $filterEstado; $countTypes .= 's';
            }
        }
        if ($filterDesde) { $countSql .= ' AND t.last_fecha >= ? '; $countParams[] = $filterDesde; $countTypes .= 's'; }
        if ($filterHasta) { $countSql .= ' AND t.last_fecha <= ? '; $countParams[] = $filterHasta; $countTypes .= 's'; }

        $stCount = $this->conn->prepare($countSql);
        $stCount->bind_param($countTypes, ...$countParams);
        $stCount->execute();
        $stCount->bind_result($totalRows);
        $stCount->fetch();
        $stCount->close();

        $totalPages = max(1, (int)ceil($totalRows / $perPage));

        $sqlIds = "
          SELECT idLocal, MAX(is_priority) AS is_priority, MIN(cod_min) AS cod_min
          FROM (
            $baseSql
          ) t
          WHERE 1=1
        ";
        $paramsIds = $baseParams; $typesIds = $baseTypes;

        if ($filterEstado !== '') {
            if ($filterEstado === 'sin_datos') {
                $sqlIds .= ' AND t.last_fecha IS NULL ';
            } else {
                $sqlIds .= ' AND t.estado_agg = ? ';
                $paramsIds[] = $filterEstado; $typesIds .= 's';
            }
        }
        if ($filterDesde) { $sqlIds .= ' AND t.last_fecha >= ? '; $paramsIds[] = $filterDesde; $typesIds .= 's'; }
        if ($filterHasta) { $sqlIds .= ' AND t.last_fecha <= ? '; $paramsIds[] = $filterHasta; $typesIds .= 's'; }

        $sqlIds .= ' GROUP BY idLocal ORDER BY MAX(is_priority) DESC, MIN(cod_min) ASC LIMIT ? OFFSET ? ';
        $paramsIds[] = $perPage; $paramsIds[] = $offset; $typesIds .= 'ii';

        $stmtIds = $this->conn->prepare($sqlIds);
        $stmtIds->bind_param($typesIds, ...$paramsIds);
        $stmtIds->execute();
        $resIds = $stmtIds->get_result();

        $ids = [];
        $prioById = [];
        while ($row = $resIds->fetch_assoc()) {
            $idL = (int)$row['idLocal'];
            $ids[] = $idL;
            $prioById[$idL] = (int)$row['is_priority'];
        }
        $stmtIds->close();

        $locales = [];
        if (!empty($ids)) {
            $place = implode(',', array_fill(0, count($ids), '?'));
            $sql = "
              SELECT
                l.id        AS idLocal,
                l.codigo    AS codigoLocal,
                l.nombre    AS nombreLocal,
                l.direccion AS direccionLocal,
                l.lat, l.lng,
                u.usuario AS usuarioGestion,
                DATE_FORMAT(last.fecha_visita,'%d/%m/%Y %H:%i') AS fechaVisita,
                CASE
                  WHEN agg.has_impl_aud = 1 THEN 'implementado_auditado'
                  WHEN agg.has_impl_any = 1 THEN 'solo_implementado'
                  WHEN agg.has_audit    = 1 THEN 'solo_auditoria'
                  ELSE last.estado_gestion
                END AS estadoGestion,
                last.lastLat AS lastLat,
                last.lastLng AS lastLng,
                fv.url AS fotoRef,
                last.foto_url AS fotoURLGV,
                fr.encuesta_foto AS encuestaFoto,
                fv_fq.url AS fotoRefFQ,
                cnt.visitas_count AS visitasCount,
                cnt.gestiones_count AS gestionesCount
              FROM local l
              LEFT JOIN (
                SELECT gv1.id_local,
                       gv1.id_usuario,
                       gv1.estado_gestion,
                       gv1.visita_id,
                       gv1.fecha_visita,
                       COALESCE(gv1.latitud, gv1.lat_foto)  AS lastLat,
                       COALESCE(gv1.longitud, gv1.lng_foto) AS lastLng,
                       gv1.foto_url
                FROM gestion_visita gv1
                JOIN (
                  SELECT id_local,
                         MAX(CONCAT(DATE_FORMAT(fecha_visita,'%Y%m%d%H%i%s'), LPAD(id,10,'0'))) AS max_key
                  FROM gestion_visita
                  WHERE id_formulario = ? AND id_local IN ($place)
                  GROUP BY id_local
                ) sel
                  ON sel.id_local = gv1.id_local
                 AND CONCAT(DATE_FORMAT(gv1.fecha_visita,'%Y%m%d%H%i%s'), LPAD(gv1.id,10,'0')) = sel.max_key
                WHERE gv1.id_formulario = ?
              ) last ON last.id_local = l.id
              LEFT JOIN usuario u ON u.id = last.id_usuario
              LEFT JOIN (
                SELECT r.visita_id,
                       SUBSTRING_INDEX(
                         MAX(CONCAT(DATE_FORMAT(r.created_at,'%Y%m%d%H%i%s'),'|', r.answer_text)),
                         '|', -1
                       ) AS encuesta_foto
                FROM form_question_responses r
                JOIN form_questions q ON q.id = r.id_form_question
                JOIN formulario f     ON f.id = q.id_formulario AND f.id_empresa = ?
                WHERE q.id_formulario = ?
                  AND r.answer_text <> ''
                  AND (
                    LOWER(r.answer_text) LIKE '%.jpg%'  OR
                    LOWER(r.answer_text) LIKE '%.jpeg%' OR
                    LOWER(r.answer_text) LIKE '%.png%'  OR
                    LOWER(r.answer_text) LIKE '%.gif%'  OR
                    LOWER(r.answer_text) LIKE '%.webp%'
                  )
                GROUP BY r.visita_id
              ) fr ON fr.visita_id = last.visita_id
              LEFT JOIN (
                SELECT fv2.visita_id, MAX(fv2.id) AS max_foto
                FROM fotoVisita fv2
                WHERE fv2.id_formulario = ? AND fv2.id_local IN ($place)
                GROUP BY fv2.visita_id
              ) fmax ON fmax.visita_id = last.visita_id
              LEFT JOIN fotoVisita fv ON fv.id = fmax.max_foto
              LEFT JOIN (
                SELECT fq3.id_local, MAX(fv3.id) AS max_foto_fq
                FROM formularioQuestion fq3
                JOIN fotoVisita fv3
                  ON fv3.id_formularioQuestion = fq3.id
                 AND fv3.id_formulario        = fq3.id_formulario
                 AND fv3.id_local             = fq3.id_local
                WHERE fq3.id_formulario = ?
                  AND fv3.id_formulario = ?
                GROUP BY fq3.id_local
              ) fqx ON fqx.id_local = l.id
              LEFT JOIN fotoVisita fv_fq ON fv_fq.id = fqx.max_foto_fq
              LEFT JOIN (
                SELECT id_local,
                       MAX(estado_gestion = 'implementado_auditado')                                   AS has_impl_aud,
                       MAX(estado_gestion IN ('solo_implementado','implementado_auditado'))            AS has_impl_any,
                       MAX(estado_gestion = 'solo_auditoria')                                          AS has_audit
                FROM gestion_visita
                WHERE id_formulario = ? AND id_local IN ($place)
                GROUP BY id_local
              ) agg ON agg.id_local = l.id
              LEFT JOIN (
                SELECT id_local,
                       COUNT(DISTINCT visita_id) AS visitas_count,
                       COUNT(*)                  AS gestiones_count
                FROM gestion_visita
                WHERE id_formulario = ? AND id_local IN ($place)
                GROUP BY id_local
              ) cnt ON cnt.id_local = l.id
              WHERE l.id IN ($place)
              ORDER BY FIELD(l.id, $place)
            ";

            $params = [];
            $types  = '';
            $params[] = $idCampana; $types .= 'i';
            foreach ($ids as $v){ $params[] = $v; $types .= 'i'; }
            $params[] = $idCampana; $types .= 'i';
            $params[] = $empresaId; $types .= 'i';
            $params[] = $idCampana;  $types .= 'i';
            $params[] = $idCampana; $types .= 'i';
            foreach ($ids as $v){ $params[] = $v; $types .= 'i'; }
            $params[] = $idCampana; $types .= 'i';
            $params[] = $idCampana; $types .= 'i';
            $params[] = $idCampana; $types .= 'i';
            foreach ($ids as $v){ $params[] = $v; $types .= 'i'; }
            $params[] = $idCampana; $types .= 'i';
            foreach ($ids as $v){ $params[] = $v; $types .= 'i'; }
            foreach ($ids as $v){ $params[] = $v; $types .= 'i'; }
            foreach ($ids as $v){ $params[] = $v; $types .= 'i'; }

            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $locales = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $baseURL = 'https://visibility.cl/visibility2/app/';
            foreach ($locales as &$loc) {
                $raw = $loc['fotoRef'] ?? '';
                if (!$raw) {
                    $estado = $loc['estadoGestion'] ?? '';
                    if ($estado === 'solo_auditoria' && !empty($loc['encuestaFoto'])) {
                        $raw = $loc['encuestaFoto'];
                    } elseif (($estado === 'en proceso' || $estado === 'cancelado') && !empty($loc['fotoURLGV'])) {
                        $parts = preg_split('/\s+/', trim($loc['fotoURLGV']));
                        $raw = $parts[0] ?? '';
                    }
                }
                if (!$raw && !empty($loc['fotoRefFQ'])) {
                    $raw = $loc['fotoRefFQ'];
                }
                if ($raw) {
                    if (preg_match('#^https?://#i', $raw)) {
                        $loc['fotoRef'] = $raw;
                    } elseif (preg_match('#^/visibility2/app/#', $raw)) {
                        $loc['fotoRef'] = 'https://visibility.cl' . $raw;
                    } else {
                        $loc['fotoRef'] = $baseURL . ltrim($raw,'./');
                    }
                } else {
                    $loc['fotoRef'] = $baseURL . 'assets/images/placeholder.png';
                }
                $idL = (int)$loc['idLocal'];
                $loc['is_priority']     = (int)($prioById[$idL] ?? 0);
                $loc['usuarioGestion']  = $loc['usuarioGestion'] ?: '—';
                $loc['fechaVisita']     = $loc['fechaVisita']    ?: '—';
                $loc['visitasCount']    = (int)($loc['visitasCount']    ?? 0);
                $loc['gestionesCount']  = (int)($loc['gestionesCount']  ?? 0);
                $loc['lastLat']         = isset($loc['lastLat']) ? (float)$loc['lastLat'] : null;
                $loc['lastLng']         = isset($loc['lastLng']) ? (float)$loc['lastLng'] : null;
            }
            unset($loc);
        }

        return [
            'locales'     => $locales,
            'totalRows'   => (int)$totalRows,
            'totalPages'  => (int)$totalPages,
            'currentPage' => (int)$page,
            'perPage'     => (int)$perPage,
            'ids'         => $ids,
            'prioById'    => $prioById,
        ];
    }
}
