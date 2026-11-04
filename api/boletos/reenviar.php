<?php
/**
 * api/boletos/reenviar.php
 * POST protegido (completo) - reenvia boletos que falharam.
 * Regra do legado: o reenvio reativa temporariamente só os fornecedores que falharam.
 */

declare(strict_types=1);

require_once __DIR__ . '/../auth/validar_jwt.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/Services/BoletoReader.php';
require_once __DIR__ . '/../../app/Services/MailSender.php';

use App\Services\BoletoReader;
use App\Services\MailSender;

header('Content-Type: application/json');

validarJWTCompleto();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

$id = isset($input['id']) ? (int) $input['id'] : 0;

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ID do envio é obrigatório']);
    exit;
}

$pdo = getConnection();

// Buscar o envio com erro
$stmt = $pdo->prepare("SELECT * FROM envios_log WHERE id = :id AND estado = 'erro' LIMIT 1");
$stmt->execute(['id' => $id]);
$envio = $stmt->fetch();

if (!$envio) {
    http_response_code(404);
    echo json_encode(['error' => 'Envio com erro não encontrado']);
    exit;
}

// Buscar fornecedor
$stmt = $pdo->prepare("SELECT * FROM fornecedores WHERE id = :id LIMIT 1");
$stmt->execute(['id' => (int) $envio['fornecedor_id']]);
$fornecedor = $stmt->fetch();

if (!$fornecedor) {
    http_response_code(404);
    echo json_encode(['error' => 'Fornecedor não encontrado']);
    exit;
}

// Anexos (JSON salvo no log)
$anexos = json_decode($envio['anexos'] ?? '[]', true) ?: [];
if (!is_array($anexos)) $anexos = [];

// Manter apenas os arquivos que ainda existem
$anexosExistentes = array_values(array_filter($anexos, 'is_file'));

if (count($anexosExistentes) === 0) {
    // Verificar se ainda estão na pasta do cliente
    $pastaCliente = BoletoReader::obterPastaCliente($fornecedor);
    $anexosExistentes = BoletoReader::listarArquivosDaPasta($pastaCliente);
    $anexosExistentes = array_map(fn($a) => $pastaCliente . '/' . $a, $anexosExistentes);
}

if (count($anexosExistentes) === 0) {
    http_response_code(409);
    echo json_encode(['error' => 'Não há documentos disponíveis para reenvio']);
    exit;
}

// Enviar
try {
    $mail = new MailSender();
    $resultado = $mail->enviarBoleto($fornecedor, $anexosExistentes, $envio['assunto'] ?? '');
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Falha no reenvio: ' . $e->getMessage()]);
    exit;
}

$novoEstado = $resultado['ok'] ? 'enviado' : 'erro';

if ($resultado['ok']) {
    // Mover para a pasta "enviado"
    $pastaCliente = BoletoReader::obterPastaCliente($fornecedor);
    $nomesArquivos = array_map('basename', $anexosExistentes);
    BoletoReader::moverParaEnviado($pastaCliente, $nomesArquivos);
}

// Atualizar log
$stmt = $pdo->prepare(
    'UPDATE envios_log SET estado = :estado, mensagem_erro = :mensagem, data_tentativa = :data
     WHERE id = :id'
);
$stmt->execute([
    'estado'   => $novoEstado,
    'mensagem' => $resultado['ok'] ? null : $resultado['mensagem'],
    'data'     => date('Y-m-d H:i:s'),
    'id'       => $id,
]);

if (!$resultado['ok']) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Falha no reenvio: ' . $resultado['mensagem'],
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Boleto reenviado com sucesso',
    'id'      => $id,
]);