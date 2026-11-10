<?php
/**
 * api/boletos/pendencias.php
 * GET protegido - relatório dos arquivos aguardando processamento em storage/enviar,
 * com a explicação (e a ação necessária) de cada um. SOMENTE LEITURA.
 *
 * Parâmetros:
 *   formato = json (padrão) | pdf | xlsx | csv
 */

declare(strict_types=1);

require_once __DIR__ . '/../auth/validar_jwt.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/Services/RelatorioPendencias.php';

use App\Services\RelatorioPendencias;

validarJWT();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

rateLimit(20, 'pendencias');
set_time_limit(90);

$formato = strtolower((string) ($_GET['formato'] ?? 'json'));

try {
    $dados = RelatorioPendencias::analisar(40.0);
} catch (\Throwable $e) {
    error_log('[pendencias] ' . get_class($e) . ': ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Não foi possível gerar o relatório: ' . $e->getMessage()]);
    exit;
}

if ($formato === 'json') {
    header('Content-Type: application/json');
    echo json_encode(['success' => true] + $dados, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

RelatorioPendencias::download($formato, $dados);
