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
        ];
    }
}
