<?php
declare(strict_types=1);

namespace PanelEncuesta\Config;

/**
 * Definiciones estáticas de factory presets por división de usuario.
 *
 * Cada grupo se filtra por empresa_id y user_div.
 * null = aplica a todas las empresas / todas las divisiones.
 *
 * Estructura de cada preset:
 *   name         – nombre visible en el menú
 *   defaultScope – {subdivision, tipo, form_id} a aplicar en el formulario antes de cargar.
 *                  0 = sin filtro (subdivision/tipo).
 *   autofillUser – user_id que recibe autofill automático al abrir el panel (null = ninguno).
 *   items        – [{mode, qset_id, label}]  (solo mode='set' soportado actualmente)
 *
 * Los qset_id corresponden a question_set_questions.id en el formulario
 * "CANAL TRADICIONAL 2.0 (set_id=66)" de la división Red Bull.
 */
class FactoryPresets
{
    private static array $definitions = [
        // ---- Red Bull (División 14) ----
        [
            'empresa_id' => null,   // todas las empresas
            'user_div'   => 14,     // División Red Bull
            'presets'    => [

                // Preset 1: Cooler Red Bull – contaminación
                // Se autofill para el usuario 103 al abrir el panel.
                [
                    'name'         => 'RB – Cooler Red Bull',
                    'defaultScope' => ['subdivision' => 0, 'tipo' => 0, 'form_id' => 0],
                    'autofillUser' => 103,
                    'items'        => [
                        ['mode' => 'set', 'qset_id' => 1370, 'label' => '¿Existe cooler Red Bull en el local?'],
                        ['mode' => 'set', 'qset_id' => 1393, 'label' => '¿El cooler Red Bull se encuentra contaminado?'],
                        ['mode' => 'set', 'qset_id' => 1395, 'label' => '¿Qué productos contaminan el cooler Red Bull?'],
                        ['mode' => 'set', 'qset_id' => 1396, 'label' => 'Toma una foto del cooler Red Bull'],
                    ],
                ],

                // Preset 2: Energy Checkout
                // Disponible en el menú de presets para todos los usuarios Div 14.
                [
                    'name'         => 'RB – Energy Checkout',
                    'defaultScope' => ['subdivision' => 0, 'tipo' => 0, 'form_id' => 0],
                    'autofillUser' => null,
                    'items'        => [
                        ['mode' => 'set', 'qset_id' => 1426, 'label' => '¿El local cuenta con Energy Checkout?'],
                        ['mode' => 'set', 'qset_id' => 1427, 'label' => '¿El Cooler del Energy Checkout se encuentra contaminado?'],
                        ['mode' => 'set', 'qset_id' => 1428, 'label' => 'Señala nivel de llenado de Energy Checkout'],
                        ['mode' => 'set', 'qset_id' => 1429, 'label' => 'Toma una foto del Energy Checkout'],
                    ],
                ],

            ],
        ],
    ];

    /**
     * Retorna los presets aplicables para el usuario actual (sin resolver metadata).
     * PanelController luego llama resolvePresetItems() para obtener metadata del servidor.
     *
     * @return array  Flat array de presets con sus items crudos.
     */
    public static function get(int $empresaId, int $userDiv): array
    {
        $result = [];
        foreach (self::$definitions as $def) {
            $matchEmpresa = $def['empresa_id'] === null || $def['empresa_id'] === $empresaId;
            $matchDiv     = $def['user_div']   === null || $def['user_div']   === $userDiv;
            if ($matchEmpresa && $matchDiv) {
                foreach ($def['presets'] as $preset) {
                    $result[] = $preset;
                }
            }
        }
        return $result;
    }
}
