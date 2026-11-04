<?php
/**
 * api/configuracao/listar.php
 * GET protegido (completo) - lista as configurações do sistema.
 * Retorna as configurações armazenadas em MySQL, sem expor credenciais sensíveis.
 */

declare(strict_types=1);

require_once __DIR__ . '/../auth/validar_jwt.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

validarJWTCompleto();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

// Chaves consideradas sensíveis (nunca expostas via API)
$sensiveis = ['smtp_pass', 'mssql_pass', 'db_pass', 'jwt_secret'];

$registros = dbQuery("SELECT chave, valor, descricao FROM configuracao ORDER BY chave ASC");

$configuracoes = array_values(array_filter($registros, fn($r) => !in_array($r['chave'], $sensiveis, true)));

echo json_encode([
    'data'  => $configuracoes,
    'total' => count($configuracoes),
]);