<?php
/**
 * Front Controller - Sistema de Envio de Boletos
 * Roteia requisições para a API e serve arquivos estáticos
 *
 * Estrutura: PHP puro, adaptável ao ScriptCase 10.1
 */

declare(strict_types=1);

// Tratamento de erros: fatais retornam JSON para chamadas de API
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($uri, '/api/') !== false) {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['error' => 'Erro interno do servidor']);
        }
    }
});

ini_set('display_errors', '0');
error_reporting(E_ALL);

// Autoload do Composer (JWT, PHPMailer e classes App)
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Carregar configuração (.env)
require_once __DIR__ . '/config/config.php';

// Segurança (headers + rate limit)
require_once __DIR__ . '/config/security.php';

// Recuperar token de autorização quando o servidor filtra o header
if (empty($_SERVER['HTTP_AUTHORIZATION'])) {
    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (!empty($_SERVER['HTTP_X_AUTH_TOKEN'])) {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $_SERVER['HTTP_X_AUTH_TOKEN'];
    } elseif (function_exists('apache_request_headers')) {
        $h = apache_request_headers();
        $_SERVER['HTTP_AUTHORIZATION'] = $h['Authorization'] ?? '';
    }
}

// Aplicar headers de segurança e rate limit global (exceto login)
applySecurityHeaders();
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?: '/';

if (strpos($path, '/api/') !== false && strpos($path, '/api/auth/login.php') === false) {
    rateLimit();
}

// Determinar base path
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$basePath = rtrim(str_replace('index.php', '', $scriptName), '/');

// Redirecionar raiz para a tela de login
if ($path === '/' || $path === $basePath || $path === $basePath . '/') {
    header('Location: ' . ($basePath ?: '') . '/public/login.html');
    exit;
}

// Rota: API
if (preg_match('#^' . preg_quote($basePath, '#') . '/api/(.+)$#', $path, $matches)) {
    $apiFile = __DIR__ . '/api/' . $matches[1];
    $realApi = realpath($apiFile);
    $realDir = realpath(__DIR__ . '/api');

    if ($realApi && $realDir && strpos($realApi, $realDir) === 0 && is_file($realApi)) {
        require_once $realApi;
        exit;
    }

    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Endpoint não encontrado']);
    exit;
}

// Rota: arquivos públicos (html, css, js)
$publicFile = __DIR__ . '/public' . $path;
$realPublic = realpath($publicFile);
$realPublicDir = realpath(__DIR__ . '/public');

if ($realPublic && $realPublicDir && strpos($realPublic, $realPublicDir) === 0 && is_file($realPublic)) {
    $ext = strtolower(pathinfo($realPublic, PATHINFO_EXTENSION));
    $mimes = [
        'html' => 'text/html; charset=UTF-8',
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'json' => 'application/json',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon',
        'pdf'  => 'application/pdf',
    ];
    if (isset($mimes[$ext])) {
        header('Content-Type: ' . $mimes[$ext]);
    }
    readfile($realPublic);
    exit;
}

http_response_code(404);
echo '404 - Página não encontrada';