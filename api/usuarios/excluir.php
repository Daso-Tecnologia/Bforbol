<?php
/**
 * api/usuarios/excluir.php
 * POST protegido (completo) - exclui um usuário.
 */

declare(strict_types=1);

require_once __DIR__ . '/../auth/validar_jwt.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

$usuarioLogado = validarJWTCompleto();

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

// Não permitir excluir o próprio usuário logado
if ((int) $input['id'] === (int) $usuarioLogado['id']) {
    http_response_code(403);
    echo json_encode(['error' => 'Não é possível excluir o próprio usuário']);
    exit;
}

$stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = :id");
$stmt->execute(['id' => (int) $input['id']]);

echo json_encode(['success' => true, 'message' => 'Usuário excluído com sucesso']);