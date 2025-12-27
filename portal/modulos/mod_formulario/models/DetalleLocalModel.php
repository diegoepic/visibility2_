<?php
class DetalleLocalModel
{
    private mysqli $conn;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    private function haversine(float $lat1, float $lon1, float $lat2, float $lon2): int
    {
        $R = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat/2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) ** 2;
        return (int)round($R * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    public function getDetalle(int $empresaId, int $campanaId, int $localId): array
    {
        $localCodigo = $localNombre = $localDireccion = null;
        $latLocal = $lngLocal = null;

        $stL = $this->conn->prepare(
            "SELECT l.codigo,l.nombre,l.direccion,l.lat,l.lng
             FROM local l
             JOIN formularioQuestion fq ON fq.id_local=l.id AND fq.id_formulario=?
             JOIN formulario f ON f.id=fq.id_formulario AND f.id_empresa=?
             WHERE l.id=? LIMIT 1"
        );
        $stL->bind_param('iii', $campanaId, $empresaId, $localId);
        $stL->execute();
        $stL->bind_result($localCodigo, $localNombre, $localDireccion, $latLocal, $lngLocal);
        $stL->fetch();
        $stL->close();

        if(!$localCodigo || !$localNombre){
            $tmp=$this->conn->prepare('SELECT codigo,nombre,direccion,lat,lng FROM local WHERE id=? LIMIT 1');
            $tmp->bind_param('i',$localId); $tmp->execute();
            $tmp->bind_result($localCodigo,$localNombre,$localDireccion,$latLocal,$lngLocal);
            $tmp->fetch(); $tmp->close();
        }
        $localCodigo    = $localCodigo    ?: '#'.$localId;
        $localNombre    = $localNombre    ?: '';
        $localDireccion = $localDireccion ?: '—';

        $modo='sin_datos';
        $stM=$this->conn->prepare(
            "SELECT gv.estado_gestion
             FROM gestion_visita gv
             JOIN formulario f ON f.id = gv.id_formulario AND f.id_empresa=?
             WHERE gv.id_formulario=? AND gv.id_local=?
             ORDER BY gv.fecha_visita DESC, gv.id DESC LIMIT 1"
        );
        $stM->bind_param('iii',$empresaId,$campanaId,$localId);
        $stM->execute(); $stM->bind_result($modoTmp); if($stM->fetch()) $modo=$modoTmp; $stM->close();

        $has_impl_aud = 0; $has_impl_any = 0; $has_audit = 0;
        $stAgg = $this->conn->prepare(
            "SELECT
                MAX(gv.estado_gestion = 'implementado_auditado') AS has_impl_aud,
                MAX(gv.estado_gestion IN ('solo_implementado','implementado_auditado')) AS has_impl_any,
                MAX(gv.estado_gestion = 'solo_auditoria') AS has_audit
             FROM gestion_visita gv
             JOIN formulario f ON f.id = gv.id_formulario AND f.id_empresa = ?
             WHERE gv.id_formulario = ? AND gv.id_local = ?"
        );
        $stAgg->bind_param('iii', $empresaId, $campanaId, $localId);
        $stAgg->execute();
        $stAgg->bind_result($has_impl_aud, $has_impl_any, $has_audit);
        $stAgg->fetch();
        $stAgg->close();

        if ((int)$has_impl_aud === 1) {
            $modo = 'implementado_auditado';
        } elseif ((int)$has_impl_any === 1) {
            $modo = 'solo_implementado';
        } elseif ((int)$has_audit === 1) {
            $modo = 'solo_auditoria';
        }

        $visitasTot=0; $lastUsuario='—'; $lastFecha='—'; $lastLat=null; $lastLng=null;

        $stC=$this->conn->prepare(
            "SELECT COUNT(DISTINCT gv.visita_id)
             FROM gestion_visita gv
             JOIN formulario f ON f.id=gv.id_formulario AND f.id_empresa=?
             WHERE gv.id_formulario=? AND gv.id_local=?"
        );
        $stC->bind_param('iii',$empresaId,$campanaId,$localId);
        $stC->execute(); $stC->bind_result($visitasTot); $stC->fetch(); $stC->close();

        $stLast=$this->conn->prepare(
            "SELECT COALESCE(u.usuario,'—') AS usuario,
                    DATE_FORMAT(gv.fecha_visita,'%d/%m/%Y %H:%i') AS fecha,
                    COALESCE(gv.latitud, gv.lat_foto) AS lat,
                    COALESCE(gv.longitud, gv.lng_foto) AS lng
             FROM gestion_visita gv
             LEFT JOIN usuario u ON u.id=gv.id_usuario
             JOIN formulario f ON f.id=gv.id_formulario AND f.id_empresa=?
             WHERE gv.id_formulario=? AND gv.id_local=?
             ORDER BY gv.fecha_visita DESC, gv.id DESC
             LIMIT 1"
        );
        $stLast->bind_param('iii',$empresaId,$campanaId,$localId);
        $stLast->execute(); $stLast->bind_result($lastUsuario,$lastFecha,$lastLat,$lastLng); $stLast->fetch(); $stLast->close();

        $distUltima = ($latLocal!==null && $lastLat!==null) ? $this->haversine((float)$latLocal,(float)$lngLocal,(float)$lastLat,(float)$lastLng) : null;

        $sqlImp = "
          SELECT
            gv.id                    AS id,
            gv.visita_id             AS visita_id,
            gv.id_formularioQuestion AS id_fq,
            gv.id_material           AS id_material,
            gv.estado_gestion        AS estado_gestion,
            gv.observacion           AS observacion,
            DATE_FORMAT(gv.fecha_visita,'%d/%m/%Y %H:%i') AS fechaVisita,
            COALESCE(m.nombre, fq.material) AS material,
            fq.valor_propuesto       AS valor_propuesto,
            gv.valor_real            AS valor_real,
            COALESCE(gv.latitud, gv.lat_foto)  AS latitud,
            COALESCE(gv.longitud, gv.lng_foto) AS longitud,
            COALESCE(u.usuario,'—')  AS usuario,
            'GV'                     AS source
          FROM gestion_visita gv
          JOIN formulario f ON f.id=gv.id_formulario AND f.id_empresa=?
          LEFT JOIN usuario u             ON u.id=gv.id_usuario
          LEFT JOIN formularioQuestion fq ON fq.id=gv.id_formularioQuestion
          LEFT JOIN material m            ON m.id=gv.id_material
          WHERE gv.id_formulario=? AND gv.id_local=?
            AND gv.estado_gestion IN ('solo_implementado','implementado_auditado')

          UNION ALL

          SELECT
            NULL                     AS id,
            NULL                     AS visita_id,
            fq.id                    AS id_fq,
            NULL                     AS id_material,
            fq.pregunta              AS estado_gestion,
            fq.observacion           AS observacion,
            DATE_FORMAT(COALESCE(fq.fechaVisita, fq.created_at),'%d/%m/%Y %H:%i') AS fechaVisita,
            fq.material              AS material,
            fq.valor_propuesto       AS valor_propuesto,
            fq.valor                 AS valor_real,
            fq.latGestion            AS latitud,
            fq.lngGestion            AS longitud,
            COALESCE(u.usuario,'—')  AS usuario,
            'FQ'                     AS source
          FROM formularioQuestion fq
          JOIN formulario f ON f.id=fq.id_formulario AND f.id_empresa=?
          LEFT JOIN usuario u ON u.id=fq.id_usuario
          WHERE fq.id_formulario=? AND fq.id_local=?
            AND fq.pregunta IN ('solo_implementado','implementado_auditado')
            AND NOT EXISTS (
              SELECT 1 FROM gestion_visita gv
              WHERE gv.id_formularioQuestion = fq.id
                AND gv.id_formulario = fq.id_formulario
                AND gv.id_local = fq.id_local
            )
          ORDER BY fechaVisita ASC
        ";

        $stImp=$this->conn->prepare($sqlImp);
        $stImp->bind_param('iiiiii', $empresaId, $campanaId, $localId, $empresaId, $campanaId, $localId);
        $stImp->execute();
        $implementaciones=$stImp->get_result()->fetch_all(MYSQLI_ASSOC);
        $stImp->close();

        $historial=[];
        $stH=$this->conn->prepare(
            "SELECT gv.id, gv.visita_id, gv.estado_gestion,
                    DATE_FORMAT(gv.fecha_visita,'%d/%m/%Y %H:%i') AS fechaVisita,
                    COALESCE(u.usuario,'—') AS usuario,
                    COALESCE(m.nombre, fq.material) AS material,
                    fq.valor_propuesto, gv.valor_real,
                    COALESCE(gv.latitud, gv.lat_foto) AS lat,
                    COALESCE(gv.longitud, gv.lng_foto) AS lng
             FROM gestion_visita gv
             JOIN formulario f ON f.id=gv.id_formulario AND f.id_empresa=?
             LEFT JOIN usuario u             ON u.id=gv.id_usuario
             LEFT JOIN formularioQuestion fq ON fq.id=gv.id_formularioQuestion
             LEFT JOIN material m            ON m.id=gv.id_material
             WHERE gv.id_formulario=? AND gv.id_local=?
             ORDER BY gv.fecha_visita DESC, gv.id DESC"
        );
        $stH->bind_param('iii',$empresaId,$campanaId,$localId);
        $stH->execute(); $historial=$stH->get_result()->fetch_all(MYSQLI_ASSOC); $stH->close();

        $visitas = $this->buildVisitasDetalle($empresaId, $campanaId, $localId);

        return [
            'local' => [
                'id' => $localId,
                'codigo' => $localCodigo,
                'nombre' => $localNombre,
                'direccion' => $localDireccion,
                'lat' => $latLocal,
                'lng' => $lngLocal,
            ],
            'modo' => $modo,
            'flags' => [
                'has_impl_aud' => (int)$has_impl_aud,
                'has_impl_any' => (int)$has_impl_any,
                'has_audit' => (int)$has_audit,
            ],
            'resumen' => [
                'visitas_totales' => (int)$visitasTot,
                'ultima_usuario' => $lastUsuario,
                'ultima_fecha' => $lastFecha,
                'ultima_lat' => $lastLat,
                'ultima_lng' => $lastLng,
                'distancia_metros' => $distUltima,
            ],
            'implementaciones' => $implementaciones,
            'historial' => $historial,
            'visitas' => $visitas,
        ];
    }

    private function buildVisitasDetalle(int $empresaId, int $campanaId, int $localId): array
    {
        $visitas = [];

        // Visitas registradas
        $stmtV = $this->conn->prepare(
            "SELECT v.id, v.fecha_inicio, v.fecha_fin, v.latitud, v.longitud, COALESCE(u.usuario,'—') AS usuario
             FROM visita v
             LEFT JOIN usuario u ON u.id = v.id_usuario
             JOIN formulario f ON f.id = v.id_formulario AND f.id_empresa = ?
             WHERE v.id_formulario = ? AND v.id_local = ?
             ORDER BY v.fecha_inicio DESC"
        );
        $stmtV->bind_param('iii', $empresaId, $campanaId, $localId);
        $stmtV->execute();
        $resV = $stmtV->get_result();
        while ($row = $resV->fetch_assoc()) {
            $visitas[] = $row;
        }
        $stmtV->close();

        // Visitas solo auditoría (gestion_visita sin visita)
        $existingIds = array_column($visitas, 'id');
        $stmtExtra = $this->conn->prepare(
            "SELECT DISTINCT gv.visita_id AS id, COALESCE(u.usuario,'—') AS usuario
             FROM gestion_visita gv
             JOIN usuario u ON u.id = gv.id_usuario
             JOIN formulario f ON f.id = gv.id_formulario AND f.id_empresa = ?
             WHERE gv.id_formulario = ? AND gv.id_local = ? AND gv.visita_id IS NOT NULL"
        );
        $stmtExtra->bind_param('iii', $empresaId, $campanaId, $localId);
        $stmtExtra->execute();
        $resE = $stmtExtra->get_result();
        while ($row = $resE->fetch_assoc()) {
            $vid = (int)$row['id'];
            if (!in_array($vid, $existingIds, true)) {
                $visitas[] = [
                    'id' => $vid,
                    'fecha_inicio' => null,
                    'fecha_fin' => null,
                    'latitud' => null,
                    'longitud' => null,
                    'usuario' => $row['usuario'] . ' (solo auditoría)',
                ];
            }
        }
        $stmtExtra->close();

        usort($visitas, function ($a, $b) {
            $ta = $a['fecha_inicio'] ? strtotime($a['fecha_inicio']) : PHP_INT_MIN;
            $tb = $b['fecha_inicio'] ? strtotime($b['fecha_inicio']) : PHP_INT_MIN;
            return $tb <=> $ta;
        });

        $totalVisitas = count($visitas);
        foreach ($visitas as $idx => &$v) {
            $vid = (int)$v['id'];

            $stmtClass = $this->conn->prepare(
                "SELECT gv.estado_gestion, gv.observacion, gv.foto_url
                 FROM gestion_visita gv
                 JOIN formulario f ON f.id = gv.id_formulario AND f.id_empresa = ?
                 WHERE gv.visita_id = ? AND gv.id_formulario = ? AND gv.id_local = ? AND gv.id_formularioQuestion = 0"
            );
            $stmtClass->bind_param('iiii', $empresaId, $vid, $campanaId, $localId);
            $stmtClass->execute();
            $v['estado_local'] = $stmtClass->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmtClass->close();

            $stmtImpOk = $this->conn->prepare(
                "SELECT gv.id, gv.id_formularioQuestion, m.nombre AS material, gv.valor_real, gv.observacion
                 FROM gestion_visita gv
                 LEFT JOIN material m ON m.id = gv.id_material
                 JOIN formulario f ON f.id = gv.id_formulario AND f.id_empresa = ?
                 WHERE gv.visita_id = ? AND gv.id_formulario = ? AND gv.id_local = ? AND gv.valor_real > 0
                 ORDER BY gv.fecha_visita ASC"
            );
            $stmtImpOk->bind_param('iiii', $empresaId, $vid, $campanaId, $localId);
            $stmtImpOk->execute();
            $v['implementaciones_ok'] = $stmtImpOk->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmtImpOk->close();

            foreach ($v['implementaciones_ok'] as &$impl) {
                $stmtF = $this->conn->prepare(
                    "SELECT url FROM fotoVisita
                     WHERE visita_id = ? AND id_formulario = ? AND id_local = ? AND id_formularioQuestion = ?"
                );
                $stmtF->bind_param('iiii', $vid, $campanaId, $localId, $impl['id_formularioQuestion']);
                $stmtF->execute();
                $impl['fotos'] = $stmtF->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmtF->close();
            }
            unset($impl);

            $stmtImpNo = $this->conn->prepare(
                "SELECT gv.id, gv.id_formularioQuestion, m.nombre AS material, gv.observacion AS observacion_no_impl
                 FROM gestion_visita gv
                 LEFT JOIN material m ON m.id = gv.id_material
                 JOIN formulario f ON f.id = gv.id_formulario AND f.id_empresa = ?
                 WHERE gv.visita_id = ? AND gv.id_formulario = ? AND gv.id_local = ?
                   AND gv.valor_real = 0 AND gv.id_formularioQuestion <> 0
                 ORDER BY gv.fecha_visita ASC"
            );
            $stmtImpNo->bind_param('iiii', $empresaId, $vid, $campanaId, $localId);
            $stmtImpNo->execute();
            $v['implementaciones_no'] = $stmtImpNo->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmtImpNo->close();

            $stmtR = $this->conn->prepare(
                "SELECT fqr.id, fq.question_text, fqr.answer_text, fqr.valor, fqr.created_at
                 FROM form_question_responses fqr
                 JOIN form_questions fq ON fq.id = fqr.id_form_question
                 JOIN formulario f ON f.id = fq.id_formulario AND f.id_empresa = ?
                 WHERE fqr.visita_id = ? AND fqr.id_local = ? AND fq.id_formulario = ?
                 ORDER BY fqr.created_at ASC"
            );
            $stmtR->bind_param('iiii', $empresaId, $vid, $localId, $campanaId);
            $stmtR->execute();
            $v['respuestas'] = $stmtR->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmtR->close();

            $v['secuencia'] = $totalVisitas - $idx;
        }
        unset($v);

        return $visitas;
    }
}
