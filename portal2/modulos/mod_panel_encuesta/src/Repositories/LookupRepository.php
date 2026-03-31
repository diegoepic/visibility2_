<?php
declare(strict_types=1);

namespace PanelEncuesta\Repositories;

/**
 * Catálogos para dropdowns / lookups del panel.
 * Cubre todos los endpoints ajax_*.php excepto preguntas (PreguntaRepository) y stats.
 */
class LookupRepository
{
    public function __construct(private \mysqli $conn) {}

    // ------------------------------------------------------------------
    // Campañas / formularios
    // ------------------------------------------------------------------

    public function getCampanas(int $empresaId, int $division, int $subdivision, int $tipo, bool $isMc): array
    {
        $sql    = "SELECT id, nombre FROM formulario WHERE id_empresa=? AND deleted_at IS NULL";
        $types  = 'i';
        $params = [$empresaId];

        if ($isMc) {
            if ($division > 0) { $sql .= " AND id_division=?";    $types .= 'i'; $params[] = $division; }
        } else {
            $sql .= " AND id_division=?"; $types .= 'i'; $params[] = $division;
        }
        if ($subdivision > 0) { $sql .= " AND id_subdivision=?"; $types .= 'i'; $params[] = $subdivision; }

        if (in_array($tipo, [1, 3], true)) {
            $sql .= " AND tipo=?"; $types .= 'i'; $params[] = $tipo;
        } else {
            $sql .= " AND tipo IN (1,3)";
        }

        $sql .= " ORDER BY fechaInicio DESC, id DESC";

        $st = $this->conn->prepare($sql);
        $st->bind_param($types, ...$params);
        $st->execute();
        $rs  = $st->get_result();
        $out = [];
        while ($r = $rs->fetch_assoc()) {
            $out[] = $r;
        }
        $st->close();
        return $out;
    }

    // ------------------------------------------------------------------
    // Distritos
    // ------------------------------------------------------------------

    public function getDistritos(int $division, int $empresaId): array
    {
        if ($division <= 0) {
            return [];
        }
        $sql = "
          SELECT DISTINCT d.id, d.nombre_distrito
            FROM local l
            JOIN distrito d ON d.id = l.id_distrito
           WHERE l.id_division=? AND l.id_empresa=? AND d.id IS NOT NULL
           ORDER BY d.nombre_distrito
        ";
        $st = $this->conn->prepare($sql);
        $st->bind_param('ii', $division, $empresaId);
        $st->execute();
        $rs  = $st->get_result();
        $out = [];
        while ($r = $rs->fetch_assoc()) {
            $out[] = $r;
        }
        $st->close();
        return $out;
    }

    // ------------------------------------------------------------------
    // Jefes de venta
    // ------------------------------------------------------------------

    public function getJefes(int $division, int $empresaId): array
    {
        if ($division <= 0) {
            return [];
        }
        $sql = "
          SELECT DISTINCT jv.id, jv.nombre
            FROM local l
            JOIN jefe_venta jv ON jv.id = l.id_jefe_venta
           WHERE l.id_division=? AND l.id_empresa=? AND jv.id IS NOT NULL
           ORDER BY jv.nombre
        ";
        $st = $this->conn->prepare($sql);
        $st->bind_param('ii', $division, $empresaId);
        $st->execute();
        $rs  = $st->get_result();
        $out = [];
        while ($r = $rs->fetch_assoc()) {
            $out[] = $r;
        }
        $st->close();
        return $out;
    }

    // ------------------------------------------------------------------
    // Subdivisiones
    // ------------------------------------------------------------------

    public function getSubdivisiones(int $division, int $empresaId): array
    {
        if ($division <= 0) {
            return [];
        }
        $sql = "
          SELECT s.id, s.nombre
            FROM subdivision s
            JOIN division_empresa de ON de.id = s.id_division
           WHERE s.id_division=? AND de.id_empresa=?
           ORDER BY s.nombre
        ";
        $st = $this->conn->prepare($sql);
        $st->bind_param('ii', $division, $empresaId);
        $st->execute();
        $rs  = $st->get_result();
        $out = [];
        while ($r = $rs->fetch_assoc()) {
            $out[] = $r;
        }
        $st->close();
        return $out;
    }

    // ------------------------------------------------------------------
    // Usuarios
    // ------------------------------------------------------------------

    public function getUsuarios(int $division, int $empresaId, bool $isMc, int $userDiv): array
    {
        if ($isMc && $division === 0) {
            $st = $this->conn->prepare("SELECT id, usuario FROM usuario WHERE activo=1 AND id_empresa=? ORDER BY usuario");
            $st->bind_param('i', $empresaId);
        } else {
            $divRef = $division > 0 ? $division : $userDiv;
            $st = $this->conn->prepare("SELECT id, usuario FROM usuario WHERE activo=1 AND id_division=? AND id_empresa=? ORDER BY usuario");
            $st->bind_param('ii', $divRef, $empresaId);
        }
        $st->execute();
        $rs  = $st->get_result();
        $out = [];
        while ($r = $rs->fetch_assoc()) {
            $out[] = $r;
        }
        $st->close();
        return $out;
    }

    // ------------------------------------------------------------------
    // Jefes para el panel principal (igual que getJefes pero con divRef)
    // ------------------------------------------------------------------

    public function getJefesPanel(int $division, int $userDiv, int $empresaId, bool $isMc): array
    {
        if (!$isMc && $division <= 0) {
            $division = $userDiv;
        }
        if ($division <= 0) {
            return [];
        }
        $sql = "
          SELECT DISTINCT jv.id, jv.nombre
            FROM local l
            JOIN jefe_venta jv ON jv.id = l.id_jefe_venta
           WHERE l.id_division=? AND l.id_empresa=?
           ORDER BY jv.nombre
        ";
        $st = $this->conn->prepare($sql);
        $st->bind_param('ii', $division, $empresaId);
        $st->execute();
        $rs  = $st->get_result();
        $out = [];
        while ($r = $rs->fetch_assoc()) {
            $out[] = $r;
        }
        $st->close();
        return $out;
    }

    // ------------------------------------------------------------------
    // Divisiones
    // ------------------------------------------------------------------

    public function getDivisiones(int $empresaId): array
    {
        $st = $this->conn->prepare("SELECT id, nombre FROM division_empresa WHERE estado=1 AND id_empresa=? ORDER BY nombre");
        $st->bind_param('i', $empresaId);
        $st->execute();
        $rs  = $st->get_result();
        $out = [];
        while ($r = $rs->fetch_assoc()) {
            $out[] = $r;
        }
        $st->close();
        return $out;
    }
}
