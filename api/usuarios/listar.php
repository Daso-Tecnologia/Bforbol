<?php
/**
 * api/usuarios/listar.php
 * GET protegido - lista usuários conforme o nível de acesso:
 *   - nível 'completo' (admin): vê todos os usuários.
 *   - nível 'read': vê apenas o próprio registro.
 */

declare(strict_types=1);

require_once __DIR__ . '/../auth/validar_jwt.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

$usuarioLogado = validarJWT();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$pdo = getConnection();

if ($usuarioLogado['nivel'] === 'completo') {
    $stmt = $pdo->query("SELECT id, nome, email, nivel, ativo, created_at FROM usuarios ORDER BY nome ASC");
    $usuarios = $stmt->fetchAll();
} else {
    // Nível 'read': somente o próprio registro
    $stmt = $pdo->prepare("SELECT id, nome, email, nivel, ativo, created_at FROM usuarios WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $usuarioLogado['id']]);
    $usuarios = $stmt->fetchAll();
}

echo json_encode($usuarios);
