<?php
/**
 * api/fornecedores/excluir.php
 * POST protegido (completo) - exclui fornecedor (lógico se houver envios).
 */

declare(strict_types=1);

require_once __DIR__ . '/../auth/validar_jwt.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

validarJWTCompleto();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

if (empty($input['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'ID é obrigatório']);
    exit;
}

$pdo = getConnection();

// Verificar se existem envios vinculados
$stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM envios_log WHERE fornecedor_id = :id");
$stmt->execute(['id' => (int) $input['id']]);
$result = $stmt->fetch();

if ((int) $result['total'] > 0) {
    // Exclusão lógica (desativa) para preservar histórico
    $stmt = $pdo->prepare("UPDATE fornecedores SET ativo = 0 WHERE id = :id");
    $stmt->execute(['id' => (int) $input['id']]);

    echo json_encode([
        'success' => true,
        'message' => 'Fornecedor possui histórico de envios. Foi desativado (exclusão lógica).',
        'logico'  => true,
    ]);
    exit;
}

// Exclusão física
$stmt = $pdo->prepare("DELETE FROM fornecedores WHERE id = :id");
$stmt->execute(['id' => (int) $input['id']]);

echo json_encode(['success' => true, 'message' => 'Fornecedor excluído com sucesso', 'logico' => false]);