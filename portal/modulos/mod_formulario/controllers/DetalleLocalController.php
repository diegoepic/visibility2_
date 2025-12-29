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
        $empresaId = intval($_SESSION['empresa_id'] ?? 0);
        if ($campanaId <= 0 || $localId <= 0 || $empresaId <= 0) {
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
        return [$empresaId, $campanaId, $localId];
    }

    public function apiDetalle(): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        [$empresaId, $campanaId, $localId] = $this->validate();
        $campanaNombre = $this->localModel->getCampanaNombre($campanaId, $empresaId);
        $detalle = $this->detalleModel->getDetalle($empresaId, $campanaId, $localId);
        echo json_encode([
            'campanaNombre' => $campanaNombre,
            'detalle' => $detalle,
        ], JSON_UNESCAPED_UNICODE);
    }
}