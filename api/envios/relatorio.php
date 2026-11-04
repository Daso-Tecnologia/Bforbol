<?php
/**
 * api/envios/relatorio.php
 * GET protegido - gera relatório de envios em PDF, XLSX ou CSV.
 *
 * Parâmetros:
 *   formato        = pdf | xlsx | csv
 *   estado         = pendente | enviado | erro
 *   data_inicial   = YYYY-MM-DD
 *   data_final     = YYYY-MM-DD
 */

declare(strict_types=1);

require_once __DIR__ . '/../auth/validar_jwt.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/Services/RelatorioGenerator.php';

use App\Services\RelatorioGenerator;

validarJWT();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$formato      = $_GET['formato'] ?? 'pdf';
$estado       = $_GET['estado'] ?? null;
$dataInicial  = $_GET['data_inicial'] ?? null;
$dataFinal    = $_GET['data_final'] ?? null;

// Validação básica das datas
if ($dataInicial && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataInicial)) {
    http_response_code(400);
    echo json_encode(['error' => 'data_inicial deve estar no formato YYYY-MM-DD']);
    exit;
}
if ($dataFinal && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFinal)) {
    http_response_code(400);
    echo json_encode(['error' => 'data_final deve estar no formato YYYY-MM-DD']);
    exit;
}

// Rate limit específico para download de relatórios (10/min)
rateLimit(10, 'relatorio');

RelatorioGenerator::download($formato, $estado, $dataInicial, $dataFinal);