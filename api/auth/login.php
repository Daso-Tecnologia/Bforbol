<?php
/**
 * api/auth/login.php
 * Endpoint de autenticação - gera JWT.
 * Aceita e-mail corporativo + senha (tela de login) OU token da ABSTRA (SSO corporativo).
 * 
 * - Para relatórios PDF/XLSX, instale as bibliotecas:
  ```bash
  composer require tecnickcom/tcpdf phpoffice/phpspreadsheet
  ```
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/security.php';

header('Content-Type: application/json');

// Apenas POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

// Rate limit de login por IP (10 req/min)
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$dir = STORAGE_PATH . '/rate_limits';
if (!is_dir($dir)) @mkdir($dir, 0775, true);

$rateFile = $dir . '/login_ip_' . md5($ip) . '.json';
$now = time();
$loginData = ['requests' => []];
if (is_file($rateFile)) {
    $loginData = json_decode((string) file_get_contents($rateFile), true) ?: $loginData;
}
$loginData['requests'] = array_filter($loginData['requests'], fn($t) => ($now - $t) < 60);
if (count($loginData['requests']) >= 10) {
    http_response_code(429);
    echo json_encode(['error' => 'Muitas tentativas de login. Aguarde 1 minuto.']);
    exit;
}
$loginData['requests'][] = $now;
@file_put_contents($rateFile, json_encode($loginData));

// Receber dados (JSON)
$input = json_decode(file_get_contents('php://input'), true) ?: [];

// Modo 1: Login por e-mail corporativo + senha (tela de login)
$email = isset($input['email']) ? mb_strtolower(trim($input['email'])) : '';
$senha = isset($input['senha']) ? (string) $input['senha'] : '';

// Modo 2: Token corporativo ABSTRA (SSO) - envio do e-mail autenticado
$tokenAbstra = isset($input['abstra_token']) ? (string) $input['abstra_token'] : '';
$emailAbstra = isset($input['abstra_email']) ? mb_strtolower(trim($input['abstra_email'])) : '';

// Requer e-mail em ambos os modos
if ($email === '' && $emailAbstra === '') {
    http_response_code(400);
    echo json_encode(['error' => 'E-mail é obrigatório']);
    exit;
}

$emailFinal = $email !== '' ? $email : $emailAbstra;

// Verificar bloqueio por brute force
if (!checkLoginAttempt($emailFinal)) {
    http_response_code(429);
    echo json_encode(['error' => 'Conta bloqueada temporariamente. Tente novamente em ' . LOGIN_BLOCK_MINUTES . ' minutos.']);
    exit;
}

// Buscar usuário no banco
$pdo = getConnection();
$stmt = $pdo->prepare("SELECT id, nome, email, senha, nivel, ativo FROM usuarios WHERE email = :email LIMIT 1");
$stmt->execute(['email' => $emailFinal]);
$usuario = $stmt->fetch();

if (!$usuario) {
    // Usuário não existe no MySQL. Opção: permitir acesso corporativo ABSTRA se validado.
    // Aqui, para o login por senha, negamos.
    recordLoginFailure($emailFinal);
    http_response_code(401);
    echo json_encode(['error' => 'E-mail ou senha inválidos']);
    exit;
}

if ((int) ($usuario['ativo'] ?? 1) !== 1) {
    http_response_code(403);
    echo json_encode(['error' => 'Usuário inativo. Contate o administrador.']);
    exit;
}

// Autenticar conforme o modo
$autenticado = false;

if ($tokenAbstra !== '') {
    // Modo ABSTRA: validar token corporativo (placeholder - integrar API da ABSTRA)
    // Em produção: chamar endpoint ABSTRA para validar o token e obter e-mail oficial.
    if (substr($tokenAbstra, 0, 7) === 'ABSTRA_') {
        $autenticado = true;
    }
} elseif ($senha !== '') {
    // Modo senha: verificar hash bcrypt
    $autenticado = password_verify($senha, $usuario['senha'] ?? '');
}

if (!$autenticado) {
    recordLoginFailure($emailFinal);
    http_response_code(401);
    echo json_encode(['error' => 'E-mail ou senha inválidos']);
    exit;
}

// Login bem-sucedido
resetLoginAttempts($emailFinal);

// Gerar JWT
$token = jwtEncode([
    'id'    => (int) $usuario['id'],
    'email' => $usuario['email'],
    'nome'  => $usuario['nome'],
    'nivel' => $usuario['nivel'],
]);

echo json_encode([
    'success' => true,
    'token'   => $token,
    'usuario' => [
        'id'    => (int) $usuario['id'],
        'nome'  => $usuario['nome'],
        'email' => $usuario['email'],
        'nivel' => $usuario['nivel'],
    ],
]);