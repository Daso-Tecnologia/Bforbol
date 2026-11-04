<?php
/**
 * api/configuracao/salvar.php
 * POST protegido (completo) - salva configurações no MySQL.
 * Nega o salvamento de chaves sensíveis via API.
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

if (empty($input['chave'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Chave é obrigatória']);
    exit;
}

$chave = trim($input['chave']);
$valor = (string) ($input['valor'] ?? '');
$descricao = (string) ($input['descricao'] ?? '');

// Nunca permitir gravar credenciais via API
$sensiveis = ['smtp_pass', 'mssql_pass', 'db_pass', 'jwt_secret'];
if (in_array($chave, $sensiveis, true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Esta chave não pode ser alterada via API. Use o .env.']);
    exit;
}

dbExecute(
    'INSERT INTO configuracao (chave, valor, descricao) VALUES (:chave, :valor, :descricao)
     ON DUPLICATE KEY UPDATE valor = VALUES(valor), descricao = VALUES(descricao)',
    ['chave' => $chave, 'valor' => $valor, 'descricao' => $descricao]
);

echo json_encode(['success' => true, 'message' => 'Configuração salva com sucesso']);