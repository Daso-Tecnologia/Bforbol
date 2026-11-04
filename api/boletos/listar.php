<?php
/**
 * api/boletos/listar.php
 * GET protegido - lista os PDFs presentes na pasta de entrada (storage/enviar)
 * e/ou as pastas dos clientes.
 */

declare(strict_types=1);

require_once __DIR__ . '/../auth/validar_jwt.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/Services/BoletoReader.php';

use App\Services\BoletoReader;

header('Content-Type: application/json');

validarJWT();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$local = $_GET['local'] ?? 'entrada'; // 'entrada' | 'cliente'

if ($local === 'cliente') {
    // Listar arquivos da pasta de um cliente
    $fornecedorId = isset($_GET['fornecedor_id']) ? (int) $_GET['fornecedor_id'] : 0;

    if ($fornecedorId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'fornecedor_id é obrigatório para listar a pasta do cliente']);
        exit;
    }

    $pdo = getConnection();
    $stmt = $pdo->prepare("SELECT id, cod_for, fan_for FROM fornecedores WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $fornecedorId]);
    $fornecedor = $stmt->fetch();

    if (!$fornecedor) {
        http_response_code(404);
        echo json_encode(['error' => 'Fornecedor não encontrado']);
        exit;
    }

    $pastaCliente = BoletoReader::obterPastaCliente($fornecedor);

    echo json_encode([
        'fornecedor' => $fornecedor,
        'pasta'      => $pastaCliente,
        'arquivos'   => BoletoReader::listarArquivosDaPasta($pastaCliente),
        'enviados'   => BoletoReader::listarArquivosDaPasta($pastaCliente . '/enviado'),
    ]);
    exit;
}

// Padrão: listar entrada (storage/enviar)
$arquivosEntrada = BoletoReader::listarArquivosNaEntrada();

// Enriquecer com cod_for e fornecedor
$dados = [];
foreach ($arquivosEntrada as $pdf) {
    $codFor = BoletoReader::extrairCodFor($pdf);
    $nf     = BoletoReader::extrairNF($pdf);

    $fornecedor = null;
    if ($codFor) {
        $stmt = getConnection()->prepare("SELECT id, fan_for, ativo FROM fornecedores WHERE cod_for = :cod_for LIMIT 1");
        $stmt->execute(['cod_for' => $codFor]);
        $fornecedor = $stmt->fetch() ?: null;
    }

    $dados[] = [
        'arquivo'     => $pdf,
        'tamanho'     => is_file(UPLOAD_PATH . '/' . $pdf) ? filesize(UPLOAD_PATH . '/' . $pdf) : 0,
        'cod_for'     => $codFor,
        'nf'          => $nf,
        'fornecedor'  => $fornecedor,
    ];
}

echo json_encode([
    'arquivos' => $dados,
    'total'    => count($dados),
]);