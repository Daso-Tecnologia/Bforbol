<?php
/**
 * api/auth/validar_jwt.php
 * Helper reutilizável - valida token JWT e retorna os dados do usuário.
 * Incluye funciones validarJWT() y validarJWTCompleto().
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../config/database.php';

// Qualquer fatal em um endpoint de API deve retornar JSON (nunca corpo vazio).
// O detalhe técnico (caminhos, classes, SQL) NÃO vai ao cliente: fica no log, com uma referência
// curta que o usuário pode informar ao suporte para localizar a linha no log de erros do PHP/Apache.
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $ref = substr(bin2hex(random_bytes(4)), 0, 8);
        error_log('[api-fatal ' . $ref . '] ' . ($error['message'] ?? '') . ' em ' . ($error['file'] ?? '?') . ':' . ($error['line'] ?? '?'));
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code(500);
        }
        echo json_encode(['error' => 'Erro interno do servidor (ref ' . $ref . ')']);
    }
});

/**
 * Extrae el token del header Authorization (tolerante a CGI/FastCGI).
 */
function extrairToken(): ?string
{
    // 1. Header customizado (nunca filtrado por CGI)
    if (!empty($_SERVER['HTTP_X_AUTH_TOKEN'])) {
        return $_SERVER['HTTP_X_AUTH_TOKEN'];
    }

    // 2. Header Authorization padrão
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return $_SERVER['HTTP_AUTHORIZATION'];
    }

    // (O token NUNCA é aceito pela URL: iria parar em logs e no cabeçalho Referer.)

    // 4. Redirect Authorization (Apache)
    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }

    // 5. apache_request_headers()
    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (isset($headers['Authorization'])) return $headers['Authorization'];
        if (isset($headers['authorization'])) return $headers['authorization'];
    }

    // 6. getallheaders()
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (isset($headers['Authorization'])) return $headers['Authorization'];
        if (isset($headers['authorization'])) return $headers['authorization'];
    }

    return null;
}

/**
 * Valida JWT y retorna los datos del usuario.
 */
function validarJWT(): array
{
    $token = extrairToken();

    if (!$token) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Token no fornecido']);
        exit;
    }

    // Quitar prefijo Bearer si está presente
    if (preg_match('/^Bearer\s+(.+)$/i', $token, $m)) {
        $token = $m[1];
    }

    $payload = jwtDecode($token);

    if (!$payload) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Token inválido o expirado']);
        exit;
    }

    // Reconfirma o usuário NO BANCO a cada requisição: quem foi desativado ou teve o nível
    // alterado perde/ajusta o acesso na hora (e não só quando o token expira). Os dados
    // (nível, nome, e-mail) valem os do banco, nunca os que estão dentro do token.
    $id = (int) ($payload['id'] ?? 0);
    $stmt = getConnection()->prepare('SELECT id, nome, email, nivel, ativo FROM usuarios WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $u = $stmt->fetch();

    if (!$u || (int) ($u['ativo'] ?? 1) !== 1) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Sessão inválida. Faça login novamente.']);
        exit;
    }

    return [
        'id'    => (int) $u['id'],
        'email' => (string) $u['email'],
        'nome'  => (string) $u['nome'],
        'nivel' => (string) ($u['nivel'] ?? 'read'),
    ];
}

/**
 * Valida JWT y verifica que el nivel sea "completo".
 */
function validarJWTCompleto(): array
{
    $usuario = validarJWT();

    if ($usuario['nivel'] !== 'completo') {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Acesso negado. Nivel completo requerido.']);
        exit;
    }

    return $usuario;
}