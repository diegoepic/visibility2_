<?php
session_start();
require_once __DIR__ . '/../models/Database.php';
require_once __DIR__ . '/../models/DetalleLocalModel.php';
require_once __DIR__ . '/../models/LocalModel.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

class DetalleLocalController
{
    private mysqli $conn;
    private DetalleLocalModel $detalleModel;
    private LocalModel $localModel;

    public function __construct()
    {
        $this->conn = Database::getConnection();
        $this->detalleModel = new DetalleLocalModel($this->conn);
        $this->localModel = new LocalModel($this->conn);
    }

    private function validate(): array
    {
        if (!isset($_SESSION['usuario_id'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Acceso denegado']);
            exit();
        }
        $campanaId = intval($_GET['idCampana'] ?? ($_GET['campana'] ?? 0));
        $localId   = intval($_GET['idLocal'] ?? ($_GET['local'] ?? 0));
        $visitaId  = intval($_GET['idVisita'] ?? ($_GET['visita'] ?? 0));
        $empresaId = intval($_SESSION['empresa_id'] ?? 0);
        if ($campanaId <= 0 || $empresaId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Parámetros inválidos']);
            exit();
        }
        $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_GET['csrf_token'] ?? '');
        if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
            http_response_code(403);
            echo json_encode(['error' => 'Token CSRF inválido']);
            exit();
        }
        return [$empresaId, $campanaId, $localId, $visitaId];
    }

    public function apiDetalle(): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        [$empresaId, $campanaId, $localId, $visitaId] = $this->validate();
        $campanaInfo = $this->localModel->getCampanaInfo($campanaId, $empresaId);
        $isComplementaria = ($campanaInfo['modalidad'] ?? '') === 'complementaria';
        $iwRequiereLocal = (int)($campanaInfo['iw_requiere_local'] ?? 0) === 1;
        $campanaNombre = $this->localModel->getCampanaNombre($campanaId, $empresaId);

        if ($isComplementaria) {
            if ($iwRequiereLocal && $localId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Parámetros inválidos']);
                return;
            }
            if (!$iwRequiereLocal && $visitaId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Parámetros inválidos']);
                return;
            }
            $detalle = $this->detalleModel->getDetalleComplementaria($empresaId, $campanaId, $localId, $visitaId, $iwRequiereLocal);
        } else {
            if ($localId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Parámetros inválidos']);
                return;
            }
            $detalle = $this->detalleModel->getDetalle($empresaId, $campanaId, $localId);
        }
        echo json_encode([
            'campanaNombre' => $campanaNombre,
            'detalle' => $detalle,
            'campanaInfo' => $campanaInfo,
        ], JSON_UNESCAPED_UNICODE);
    }
}