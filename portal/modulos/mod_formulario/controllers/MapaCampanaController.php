<?php
session_start();
require_once __DIR__ . '/../models/Database.php';
require_once __DIR__ . '/../models/LocalModel.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

class MapaCampanaController
{
    private mysqli $conn;
    private LocalModel $localModel;

    public function __construct()
    {
        $this->conn = Database::getConnection();
        $this->localModel = new LocalModel($this->conn);
    }

    private function ensureCsrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf_token'];
    }

    private function validateSession(): void
    {
        if (!isset($_SESSION['usuario_id'])) {
            http_response_code(403);
            exit('Acceso denegado');
        }
    }

    private function readFilters(): array
    {
        $idCampana  = intval($_GET['id'] ?? ($_GET['campana'] ?? 0));
        $empresa_id = intval($_SESSION['empresa_id'] ?? 0);
        $filterCodigo   = trim($_GET['filter_codigo']  ?? '');
        $filterEstado   = trim($_GET['filter_estado']  ?? '');
        $filterUserId   = isset($_GET['filter_usuario_id']) && ctype_digit((string)$_GET['filter_usuario_id']) ? (int)$_GET['filter_usuario_id'] : 0;
        $filterDesdeRaw = trim($_GET['fdesde'] ?? '');
        $filterHastaRaw = trim($_GET['fhasta'] ?? '');

        $filterDesde = preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDesdeRaw) ? $filterDesdeRaw . ' 00:00:00' : null;
        $filterHasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterHastaRaw) ? $filterHastaRaw . ' 23:59:59' : null;

        $perPage = isset($_GET['per_page']) && ctype_digit((string)$_GET['per_page']) ? (int)$_GET['per_page'] : 50;
        $page    = max(1, intval($_GET['page'] ?? 1));

        return compact('idCampana','empresa_id','filterCodigo','filterEstado','filterUserId','filterDesde','filterHasta','perPage','page');
    }

    private function estadoLabel(string $estado): string
    {
        static $map = [
            'implementado_auditado' => 'Implementación + Encuesta',
            'solo_implementado'     => 'Solo implementación',
            'solo_auditoria'        => 'Encuesta (auditoría)',
            'solo_retirado'         => 'Retiro',
            'entregado'             => 'Entrega',
            'en proceso'            => 'En proceso',
            'cancelado'             => 'Cancelado',
        ];
        return $map[$estado] ?? $estado;
    }

    public function index(): void
    {
        $this->validateSession();
        $csrf = $this->ensureCsrfToken();
        $filters = $this->readFilters();
        if ($filters['idCampana'] <= 0 || $filters['empresa_id'] <= 0) {
            http_response_code(400);
            exit('Parámetros inválidos');
        }

        $campanaNombre = $this->localModel->getCampanaNombre($filters['idCampana'], $filters['empresa_id']);
        $usuarios = $this->localModel->getUsuariosByCampana($filters['idCampana'], $filters['empresa_id']);
        $estadosDisponibles = $this->localModel->getEstadosByCampana($filters['idCampana'], $filters['empresa_id']);
        $pageData = $this->localModel->getLocalesPage($filters);

        foreach ($pageData['locales'] as &$loc) {
            $loc['estadoLabel'] = $this->estadoLabel($loc['estadoGestion'] ?? '');
        }
        unset($loc);

        $allowedEstados = array_values(array_unique($estadosDisponibles));
        $allowedEstados[] = 'sin_datos';
        if ($filters['filterEstado'] !== '' && !in_array($filters['filterEstado'], $allowedEstados, true)) {
            $filters['filterEstado'] = '';
        }

        $viewData = [
            'campanaNombre' => $campanaNombre,
            'usuarios' => $usuarios,
            'estadosDisponibles' => $estadosDisponibles,
            'locales' => $pageData['locales'],
            'pagination' => [
                'totalPages' => $pageData['totalPages'],
                'currentPage' => $pageData['currentPage'],
                'perPage' => $pageData['perPage'],
                'totalRows' => $pageData['totalRows'],
            ],
            'filters' => $filters,
            'csrf' => $csrf,
            'mapKey' => 'AIzaSyDO0zLDNeEdLcQgkl7dF0C0Lgr3Wl1m3cw' ?: '',
        ];

        require __DIR__ . '/../views/mapa_campana.php';
    }

    public function apiLocales(): void
    {
        $this->validateSession();
        $filters = $this->readFilters();
        $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_GET['csrf_token'] ?? '');
        if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
            http_response_code(403);
            echo json_encode(['error' => 'Token CSRF inválido']);
            return;
        }
        if ($filters['idCampana'] <= 0 || $filters['empresa_id'] <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Parámetros inválidos']);
            return;
        }

        $pageData = $this->localModel->getLocalesPage($filters);
        foreach ($pageData['locales'] as &$loc) {
            $loc['estadoLabel'] = $this->estadoLabel($loc['estadoGestion'] ?? '');
        }
        unset($loc);

        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'locales' => $pageData['locales'],
            'pagination' => [
                'totalPages' => $pageData['totalPages'],
                'currentPage' => $pageData['currentPage'],
                'perPage' => $pageData['perPage'],
                'totalRows' => $pageData['totalRows'],
            ],
        ], JSON_UNESCAPED_UNICODE);
    }
}