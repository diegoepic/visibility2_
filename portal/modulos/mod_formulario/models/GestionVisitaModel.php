<?php
class GestionVisitaModel
{
    private mysqli $conn;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    public function getGestionesMapa(int $empresaId, int $campanaId, int $localId): array
    {
        $sql = "
          SELECT
            gv.id                              AS idGV,
            gv.visita_id                       AS visitaId,
            gv.id_local                        AS localId,
            COALESCE(m.nombre, fq.material)    AS material,
            fq.valor_propuesto                 AS valorPropuesto,
            gv.valor_real                      AS valorImplementado,
            DATE_FORMAT(gv.fecha_visita, '%d/%m/%Y %H:%i') AS fechaVisita,
            COALESCE(gv.latitud, gv.lat_foto, fq.latGestion) AS lat,
            COALESCE(gv.longitud, gv.lng_foto, fq.lngGestion) AS lng,
            gv.estado_gestion                  AS estadoGestion,
            u.usuario                          AS usuario
          FROM gestion_visita gv
          JOIN formulario f   ON f.id = gv.id_formulario AND f.id_empresa = ?
          LEFT JOIN usuario u ON u.id = gv.id_usuario
          LEFT JOIN formularioQuestion fq ON fq.id = gv.id_formularioQuestion
          LEFT JOIN material m ON m.id = gv.id_material
          WHERE gv.id_formulario = ?
            AND gv.id_local      = ?
            AND (COALESCE(gv.latitud, gv.lat_foto, fq.latGestion) IS NOT NULL)
            AND (COALESCE(gv.longitud, gv.lng_foto, fq.lngGestion) IS NOT NULL)
          ORDER BY gv.fecha_visita ASC, gv.id ASC
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('iii', $empresaId, $campanaId, $localId);
        $stmt->execute();
        $res = $stmt->get_result();

        $gestiones = [];
        while ($r = $res->fetch_assoc()) {
            $gestiones[] = [
                'id'                => (int)$r['idGV'],
                'idFQ'              => (int)$r['idGV'],
                'visitaId'          => (int)$r['visitaId'],
                'localId'           => (int)$r['localId'],
                'material'          => $r['material'],
                'valorPropuesto'    => $r['valorPropuesto'],
                'valorImplementado' => $r['valorImplementado'],
                'fechaVisita'       => $r['fechaVisita'],
                'lat'               => (float)$r['lat'],
                'lng'               => (float)$r['lng'],
                'estado_gestion'    => $r['estadoGestion'],
                'estado'            => $r['estadoGestion'],
                'usuario'           => $r['usuario'],
            ];
        }
        $stmt->close();

        return $gestiones;
    }

    public function getGestionesMapaComplementaria(int $empresaId, int $campanaId, int $localId, int $visitaId, bool $requiereLocal): array
    {
        $sql = "
          SELECT
            v.id AS visitaId,
            v.id_local AS localId,
            DATE_FORMAT(v.fecha_inicio, '%d/%m/%Y %H:%i') AS fechaVisita,
            v.latitud AS lat,
            v.longitud AS lng,
            u.usuario AS usuario
          FROM visita v
          JOIN formulario f ON f.id = v.id_formulario AND f.id_empresa = ?
          LEFT JOIN usuario u ON u.id = v.id_usuario
          WHERE v.id_formulario = ?
        ";
        $params = [$empresaId, $campanaId];
        $types = 'ii';

        if ($requiereLocal) {
            $sql .= " AND v.id_local = ? ";
            $params[] = $localId;
            $types .= 'i';
        } else {
            $sql .= " AND v.id = ? ";
            $params[] = $visitaId;
            $types .= 'i';
        }

        $sql .= " AND v.latitud IS NOT NULL AND v.longitud IS NOT NULL ORDER BY v.fecha_inicio ASC, v.id ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();

        $gestiones = [];
        while ($r = $res->fetch_assoc()) {
            $gestiones[] = [
                'id' => (int)$r['visitaId'],
                'idFQ' => (int)$r['visitaId'],
                'visitaId' => (int)$r['visitaId'],
                'localId' => (int)$r['localId'],
                'fechaVisita' => $r['fechaVisita'],
                'lat' => $r['lat'] !== null ? (float)$r['lat'] : null,
                'lng' => $r['lng'] !== null ? (float)$r['lng'] : null,
                'usuario' => $r['usuario'],
            ];
        }
        $stmt->close();

        return $gestiones;
    }
}
