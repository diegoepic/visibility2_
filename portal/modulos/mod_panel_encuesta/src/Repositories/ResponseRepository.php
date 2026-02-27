<?php
declare(strict_types=1);

namespace PanelEncuesta\Repositories;

use PanelEncuesta\ValueObjects\WhereClause;

/**
 * Acceso a datos de form_question_responses con todos sus JOINs.
 * Fuente de datos principal del Panel de Encuesta.
 */
class ResponseRepository
{
    private const SQL_FROM = "
      FROM form_question_responses fqr
      JOIN form_questions fq  ON fq.id = fqr.id_form_question
      JOIN formulario f       ON f.id  = fq.id_formulario
      JOIN local l            ON l.id  = fqr.id_local
      LEFT JOIN cadena c      ON c.id  = l.id_cadena
      LEFT JOIN distrito d    ON d.id  = l.id_distrito
      LEFT JOIN jefe_venta jv ON jv.id = l.id_jefe_venta
      JOIN usuario u          ON u.id  = fqr.id_usuario
      LEFT JOIN form_question_options o ON o.id = fqr.id_option
      JOIN visita v           ON v.id  = fqr.visita_id
    ";

    public function __construct(private \mysqli $conn) {}

    /**
     * Página de resultados ordenada por fecha desc.
     */
    public function fetchPage(WhereClause $w, int $limit, int $offset): array
    {
        $sql = "
          SELECT
            fqr.id, fqr.visita_id, fqr.created_at, fqr.valor,
            f.id AS form_id, f.nombre AS campana,
            fq.id AS pregunta_id, fq.question_text AS pregunta, fq.id_question_type AS tipo,
            COALESCE(o.option_text, fqr.answer_text) AS respuesta,
            l.id AS local_id, l.codigo AS local_codigo, l.nombre AS local_nombre, l.direccion,
            l.id_distrito AS distrito_id, l.id_jefe_venta AS jefe_venta_id,
            d.nombre_distrito AS distrito, jv.nombre AS jefe_venta,
            u.id AS usuario_id, u.usuario AS usuario,
            v.fecha_fin AS visita_fin,
            c.nombre AS cadena
          " . self::SQL_FROM . "
          " . $w->sql . "
          ORDER BY
            COALESCE(v.fecha_fin, fqr.created_at) DESC,
            fqr.visita_id DESC,
            CASE WHEN fq.id_question_type = 7 THEN 2 ELSE 1 END,
            fq.id,
            fqr.created_at DESC, fqr.id DESC
          LIMIT ? OFFSET ?
        ";

        $types  = $w->types . 'ii';
        $params = array_merge($w->params, [$limit, $offset]);

        $st = $this->conn->prepare($sql);
        $st->bind_param($types, ...$params);
        $st->execute();
        $rs   = $st->get_result();
        $rows = [];

        while ($r = $rs->fetch_assoc()) {
            $fechaBase = $r['visita_fin'] ?: $r['created_at'];
            $rows[] = [
                'id'            => (int)$r['id'],
                'visita_id'     => $r['visita_id'] !== null ? (int)$r['visita_id'] : null,
                'fecha'         => $fechaBase ? date('d/m/Y H:i:s', strtotime($fechaBase)) : '',
                'campana'       => $r['campana'],
                'form_id'       => (int)$r['form_id'],
                'pregunta_id'   => (int)$r['pregunta_id'],
                'pregunta'      => $r['pregunta'],
                'tipo'          => (int)$r['tipo'],
                'tipo_texto'    => $this->tipoTexto((int)$r['tipo']),
                'respuesta'     => $r['respuesta'],
                'valor'         => $r['valor'] !== null ? (float)$r['valor'] : null,
                'local_id'      => (int)$r['local_id'],
                'local_codigo'  => $r['local_codigo'],
                'local_nombre'  => $r['local_nombre'],
                'direccion'     => $r['direccion'],
                'cadena'        => $r['cadena'],
                'distrito_id'   => $r['distrito_id'] !== null ? (int)$r['distrito_id'] : null,
                'distrito'      => $r['distrito'],
                'jefe_venta_id' => $r['jefe_venta_id'] !== null ? (int)$r['jefe_venta_id'] : null,
                'jefe_venta'    => $r['jefe_venta'],
                'usuario_id'    => (int)$r['usuario_id'],
                'usuario'       => $r['usuario'],
            ];
        }
        $st->close();
        return $rows;
    }

    /**
     * COUNT capped — lee como máximo $cap filas para no escanear tablas enteras.
     */
    public function countCapped(WhereClause $w, int $cap): int
    {
        $sql = "
          SELECT COUNT(*) AS c
          FROM (
            SELECT 1
            " . self::SQL_FROM . "
            " . $w->sql . "
            LIMIT ?
          ) AS sub
        ";

        $types  = $w->types . 'i';
        $params = array_merge($w->params, [$cap]);

        $st = $this->conn->prepare($sql);
        $st->bind_param($types, ...$params);
        $st->execute();
        $st->bind_result($count);
        $st->fetch();
        $st->close();
        return (int)$count;
    }

    /**
     * Facets: listas distintas de usuarios, jefes y distritos dentro del filtro actual.
     */
    public function fetchFacets(WhereClause $w, int $limit = 1000): array
    {
        $facets = ['usuarios' => [], 'jefes' => [], 'distritos' => []];

        // Usuarios
        $sqlU = "SELECT DISTINCT u.id, u.usuario " . self::SQL_FROM . " " . $w->sql . " ORDER BY u.usuario LIMIT $limit";
        $st   = $this->conn->prepare($sqlU);
        if ($w->types) {
            $st->bind_param($w->types, ...$w->params);
        }
        $st->execute();
        $ru = $st->get_result();
        while ($u = $ru->fetch_assoc()) {
            $facets['usuarios'][] = ['id' => (int)$u['id'], 'nombre' => $u['usuario']];
        }
        $st->close();

        // Jefes
        $sqlJ = "SELECT DISTINCT jv.id, jv.nombre " . self::SQL_FROM . " " . $w->sql . " ORDER BY jv.nombre LIMIT $limit";
        $st   = $this->conn->prepare($sqlJ);
        if ($w->types) {
            $st->bind_param($w->types, ...$w->params);
        }
        $st->execute();
        $rj = $st->get_result();
        while ($j = $rj->fetch_assoc()) {
            if ($j['id']) {
                $facets['jefes'][] = ['id' => (int)$j['id'], 'nombre' => $j['nombre']];
            }
        }
        $st->close();

        // Distritos
        $sqlD = "SELECT DISTINCT d.id, d.nombre_distrito " . self::SQL_FROM . " " . $w->sql . " ORDER BY d.nombre_distrito LIMIT $limit";
        $st   = $this->conn->prepare($sqlD);
        if ($w->types) {
            $st->bind_param($w->types, ...$w->params);
        }
        $st->execute();
        $rd = $st->get_result();
        while ($d = $rd->fetch_assoc()) {
            if ($d['id']) {
                $facets['distritos'][] = ['id' => (int)$d['id'], 'nombre' => $d['nombre_distrito']];
            }
        }
        $st->close();

        return $facets;
    }

    /**
     * Generator for streaming export (CSV, ZIP). Yields one row at a time.
     */
    public function fetchForExport(WhereClause $w, int $limit = 50000): \Generator
    {
        $sql = "
          SELECT
            v.id AS visita_id,
            fqr.id AS resp_id,
            fqr.id_local,
            fq.id AS pregunta_id,
            CASE
              WHEN v.fecha_fin IS NOT NULL
                THEN DATE_FORMAT(v.fecha_fin, '%d/%m/%Y %H:%i:%s')
              ELSE DATE_FORMAT(fqr.created_at, '%d/%m/%Y %H:%i:%s')
            END AS fecha,
            DATE_FORMAT(fqr.created_at,'%d/%m/%Y %H:%i:%s') AS fecha_respuesta,
            f.nombre             AS campana,
            fq.question_text     AS pregunta,
            fq.id_question_type  AS tipo,
            COALESCE(o.option_text, fqr.answer_text) AS respuesta,
            fqr.valor,
            l.codigo             AS local_codigo,
            l.nombre             AS local_nombre,
            l.direccion          AS direccion,
            c.nombre             AS cadena,
            d.nombre_distrito    AS distrito,
            jv.nombre            AS jefe_venta,
            u.usuario            AS usuario
          " . self::SQL_FROM . "
          " . $w->sql . "
          ORDER BY
            COALESCE(v.fecha_fin, fqr.created_at) DESC,
            fqr.visita_id DESC,
            CASE WHEN fq.id_question_type = 7 THEN 2 ELSE 1 END,
            fq.id,
            fqr.created_at DESC, fqr.id DESC
          LIMIT ?
        ";

        $types  = $w->types . 'i';
        $params = array_merge($w->params, [$limit]);

        $st = $this->conn->prepare($sql);
        $st->bind_param($types, ...$params);
        $st->execute();
        $rs = $st->get_result();

        while ($r = $rs->fetch_assoc()) {
            yield $r;
        }
        $st->close();
    }

    // ----------------------------------------------------------------

    private function tipoTexto(int $t): string
    {
        return [
            1 => 'Sí/No',
            2 => 'Selección única',
            3 => 'Selección múltiple',
            4 => 'Texto',
            5 => 'Numérico',
            6 => 'Fecha',
            7 => 'Foto',
        ][$t] ?? 'Otro';
    }
}
