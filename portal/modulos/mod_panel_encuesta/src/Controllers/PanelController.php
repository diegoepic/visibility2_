<?php
declare(strict_types=1);

namespace PanelEncuesta\Controllers;

use PanelEncuesta\Config\FactoryPresets;
use PanelEncuesta\Repositories\LookupRepository;
use PanelEncuesta\Repositories\PreguntaRepository;

/**
 * Maneja panel_encuesta.php — vista principal del panel.
 * Carga los catálogos iniciales y renderiza views/panel.php.
 */
class PanelController
{
    public function __construct(private \mysqli $conn) {}

    public function handle(): void
    {
        // Sesión (redirige si no hay sesión, no JSON porque es vista HTML)
        if (!isset($_SESSION['usuario_id'])) {
            header("Location: /visibility2/portal/login.php");
            exit;
        }

        $csrf_token = panel_encuesta_get_csrf_token();

        $user_id    = (int)($_SESSION['usuario_id']  ?? 0);
        $user_div   = (int)($_SESSION['division_id'] ?? 0);
        $empresa_id = (int)($_SESSION['empresa_id']  ?? 0);
        $is_mc      = ($user_div === 1);

        $repo = new LookupRepository($this->conn);

        // Divisiones (solo MC ve todas)
        $divisiones = $is_mc ? $repo->getDivisiones($empresa_id) : [];

        // Parámetros GET de inicialización
        $sel_div  = $is_mc ? (int)($_GET['division']    ?? 0) : $user_div;
        $sel_sub  = (int)($_GET['subdivision'] ?? 0);
        $sel_tipo = (int)($_GET['tipo']        ?? 0); // 0=1+3, 1=programadas, 3=ruta IPT

        // Subdivisiones (si hay división seleccionada)
        $subdivisiones = ($sel_div > 0)
            ? $repo->getSubdivisiones($sel_div, $empresa_id)
            : [];

        // Campañas iniciales (tipo 1 y 3)
        $formularios = $repo->getCampanas($empresa_id, $sel_div, $sel_sub, $sel_tipo, $is_mc);

        // Usuarios
        $usuarios = $repo->getUsuarios($sel_div, $empresa_id, $is_mc, $user_div);

        // Jefes
        $jefes = (($sel_div > 0) || !$is_mc)
            ? $repo->getJefesPanel($sel_div, $user_div, $empresa_id, $is_mc)
            : [];

        // Distritos
        $distritos = [];
        if ($sel_div > 0 || !$is_mc) {
            $divRef = $sel_div > 0 ? $sel_div : $user_div;
            $distritos = $repo->getDistritos($divRef, $empresa_id);
        }

        // Factory presets: pre-fetch metadata con el scope correcto del preset
        $preguntaRepo   = new PreguntaRepository($this->conn);
        $rawPresets     = FactoryPresets::get($empresa_id, $user_div);
        $factory_presets = [];
        foreach ($rawPresets as $preset) {
            $resolved = $preguntaRepo->resolvePresetItems(
                $preset['items'],
                $empresa_id,
                $user_div,
                $is_mc,
                $sel_div,
                $preset['defaultScope']
            );
            if (!empty($resolved)) {
                $factory_presets[] = [
                    'name'         => $preset['name'],
                    'defaultScope' => $preset['defaultScope'],
                    'autofillUser' => $preset['autofillUser'] ?? null,
                    'items'        => $resolved,
                ];
            }
        }

        // Pasar variables a la vista via extract + require
        extract([
            'csrf_token'        => $csrf_token,
            'user_id'           => $user_id,
            'user_div'          => $user_div,
            'empresa_id'        => $empresa_id,
            'is_mc'             => $is_mc,
            'divisiones'        => $divisiones,
            'subdivisiones'     => $subdivisiones,
            'formularios'       => $formularios,
            'usuarios'          => $usuarios,
            'jefes'             => $jefes,
            'distritos'         => $distritos,
            'sel_div'           => $sel_div,
            'sel_sub'           => $sel_sub,
            'sel_tipo'          => $sel_tipo,
            'factory_presets'   => $factory_presets,
            'abs_base'          => panel_encuesta_abs_base(),
            'default_range_days' => 7,
        ]);

        require __DIR__ . '/../../views/panel.php';
    }
}
