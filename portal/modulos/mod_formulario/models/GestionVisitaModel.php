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
}