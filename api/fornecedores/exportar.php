<?php
/**
 * api/fornecedores/exportar.php
 * GET protegido - exporta os fornecedores em CSV ou XLSX.
 *
 * Parâmetros:
 *   formato        = csv | xlsx
 *   busca          = texto livre (código, nome, razão social, e-mail)
 *   apenas_ativos  = 0 | 1
 */

declare(strict_types=1);

require_once __DIR__ . '/../auth/validar_jwt.php';
require_once __DIR__ . '/../../app/Services/FornecedorExport.php';

use App\Services\FornecedorExport;

validarJWT();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$formato       = $_GET['formato'] ?? 'csv';
$busca         = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$apenasAtivos  = isset($_GET['apenas_ativos']) ? (bool) $_GET['apenas_ativos'] : false;

// Rate limit específico para downloads de fornecedores (10/min)
rateLimit(10, 'fornecedores_export');

FornecedorExport::download($formato, $busca, $apenasAtivos);
