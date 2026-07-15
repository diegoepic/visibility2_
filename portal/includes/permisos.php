<?php
declare(strict_types=1);

if (!function_exists('vc_permiso_normalizar')) {
    function vc_permiso_normalizar(?string $valor): string
    {
        return strtolower(trim((string)$valor));
    }
}

if (!function_exists('vc_usuario_contexto_permiso')) {
    function vc_usuario_contexto_permiso(): array
    {
        return [
            'id_usuario'      => (int)($_SESSION['usuario_id'] ?? 0),
            'id_perfil'       => (int)($_SESSION['usuario_perfil'] ?? 0),
            'perfil_nombre'   => vc_permiso_normalizar($_SESSION['perfil_nombre'] ?? ''),
            'id_empresa'      => (int)($_SESSION['empresa_id'] ?? 0),
            'id_division'     => (int)($_SESSION['division_id'] ?? 0),
            'id_subdivision'  => (int)($_SESSION['subdivision_id'] ?? ($_SESSION['id_subdivision'] ?? 0)),
        ];
    }
}

if (!function_exists('vc_permiso_fallback')) {
    function vc_permiso_fallback(string $claveModulo, array $ctx): bool
    {
        $perfil = $ctx['perfil_nombre'] ?? '';

        $modulosAdministrativos = [
            'usuarios',
            'usuarios.crear_editar',
            'usuarios.estadisticas',
            'usuarios.descargar',
            'flota',
            'flota.crear_editar',
            'flota.descargar',
            'locales',
            'locales.crear_editar',
            'locales.descargar',
            'locales.ultima_gestion',
            'locales.historico_gestiones',
            'reportes',
            'reportes.campanas',
            'reportes.adicionales',
            'reportes.ruta_planificada',
            'reportes.temporada_2024_2025',
            'reportes.temporada_2025_2026',
            'galeria',
            'galeria.campanas',
            'galeria.imagenes',
            'mapa',
            'mapa.panel_rutas',
            'mapa.visor_rutas',
            'formularios',
            'formularios.crear_editar',
            'formularios.seguimiento_etapas',
            'formularios.generador_rutas',
            'panel_control',
            'panel_control.merchan',
            'panel_control.campanas',
            'panel_control.trademarketing',
            'panel_control.recepcion_materiales',
            'permisos.admin',
            'referencias.editar',
        ];

        if (in_array($claveModulo, $modulosAdministrativos, true)) {
            return in_array($perfil, ['editor', 'coordinador'], true);
        }

        return true;
    }
}

if (!function_exists('vc_permiso_db_disponible')) {
    function vc_permiso_db_disponible(mysqli $conn): bool
    {
        static $disponible = null;

        if ($disponible !== null) {
            return $disponible;
        }

        $sql = "SELECT 1
                  FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name IN ('modulo', 'modulo_permiso', 'usuario_permiso_modulo')
              GROUP BY table_schema
                HAVING COUNT(*) = 3";

        $res = $conn->query($sql);
        $disponible = ($res instanceof mysqli_result && $res->num_rows > 0);

        if ($res instanceof mysqli_result) {
            $res->free();
        }

        return $disponible;
    }
}

if (!function_exists('puedeVerModulo')) {
    function puedeVerModulo(mysqli $conn, string $claveModulo, ?array $ctx = null): bool
    {
        static $cache = [];

        $ctx = $ctx ?? vc_usuario_contexto_permiso();
        $claveModulo = vc_permiso_normalizar($claveModulo);

        if ($claveModulo === '') {
            return false;
        }

        $cacheKey = $claveModulo . ':' . implode(':', [
            $ctx['id_usuario'] ?? 0,
            $ctx['id_perfil'] ?? 0,
            $ctx['id_empresa'] ?? 0,
            $ctx['id_division'] ?? 0,
            $ctx['id_subdivision'] ?? 0,
        ]);

        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        if (!vc_permiso_db_disponible($conn)) {
            $cache[$cacheKey] = vc_permiso_fallback($claveModulo, $ctx);
            return $cache[$cacheKey];
        }

        $sqlUsuario = "SELECT upm.permitido
                         FROM usuario_permiso_modulo upm
                         INNER JOIN modulo m ON m.id = upm.id_modulo
                        WHERE m.clave = ?
                          AND m.activo = 1
                          AND upm.activo = 1
                          AND upm.id_usuario = ?
                          AND (upm.fecha_inicio IS NULL OR upm.fecha_inicio <= NOW())
                          AND (upm.fecha_fin IS NULL OR upm.fecha_fin >= NOW())
                        ORDER BY upm.id DESC
                        LIMIT 1";

        $stmt = $conn->prepare($sqlUsuario);
        if ($stmt) {
            $stmt->bind_param('si', $claveModulo, $ctx['id_usuario']);
            if ($stmt->execute()) {
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row !== null) {
                    $cache[$cacheKey] = (int)$row['permitido'] === 1;
                    return $cache[$cacheKey];
                }
            } else {
                $stmt->close();
            }
        }

        $sqlPerfil = "SELECT mp.permitido
                        FROM modulo_permiso mp
                        INNER JOIN modulo m ON m.id = mp.id_modulo
                       WHERE m.clave = ?
                         AND m.activo = 1
                         AND mp.activo = 1
                         AND (mp.id_perfil IS NULL OR mp.id_perfil = ?)
                         AND (mp.id_empresa IS NULL OR mp.id_empresa = ?)
                         AND (mp.id_division IS NULL OR mp.id_division = ?)
                         AND (mp.id_subdivision IS NULL OR mp.id_subdivision = ?)
                    ORDER BY
                         ((mp.id_perfil IS NOT NULL) +
                          (mp.id_empresa IS NOT NULL) +
                          (mp.id_division IS NOT NULL) +
                          (mp.id_subdivision IS NOT NULL)) DESC,
                         mp.id DESC
                       LIMIT 1";

        $stmt = $conn->prepare($sqlPerfil);
        if (!$stmt) {
            $cache[$cacheKey] = vc_permiso_fallback($claveModulo, $ctx);
            return $cache[$cacheKey];
        }

        $stmt->bind_param(
            'siiii',
            $claveModulo,
            $ctx['id_perfil'],
            $ctx['id_empresa'],
            $ctx['id_division'],
            $ctx['id_subdivision']
        );

        if (!$stmt->execute()) {
            $stmt->close();
            $cache[$cacheKey] = vc_permiso_fallback($claveModulo, $ctx);
            return $cache[$cacheKey];
        }

        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row === null) {
            $partesClave = explode('.', $claveModulo);
            if (count($partesClave) > 1) {
                array_pop($partesClave);
                $clavePadre = implode('.', $partesClave);
                $cache[$cacheKey] = puedeVerModulo($conn, $clavePadre, $ctx);
                return $cache[$cacheKey];
            }

            $cache[$cacheKey] = vc_permiso_fallback($claveModulo, $ctx);
            return $cache[$cacheKey];
        }

        $cache[$cacheKey] = (int)$row['permitido'] === 1;
        return $cache[$cacheKey];
    }
}

if (!function_exists('requiereModulo')) {
    function requiereModulo(mysqli $conn, string $claveModulo): void
    {
        if (puedeVerModulo($conn, $claveModulo)) {
            return;
        }

        http_response_code(403);
        exit('No tienes permisos para acceder a este modulo.');
    }
}
