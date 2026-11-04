<?php
/**
 * config/security.php
 * Funções de segurança: JWT, rate limiting, headers de segurança e brute-force.
 * Baseado no padrão do projeto financeiro (adaptável a ScriptCase).
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Aplica headers de segurança básicos.
 */
function applySecurityHeaders(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: same-origin');
}

/**
 * Rate limit por IP usando arquivos em storage/rate_limits.
 * Permite N requisições por minuto.
 */
function rateLimit(int $maxPerMinute = null, string $namespace = 'global'): void
{
    $max = $maxPerMinute ?? RATE_LIMIT_PER_MINUTE;
    $ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    $dir = STORAGE_PATH . '/rate_limits';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $file = $dir . '/' . $namespace . '_' . md5($ip) . '.json';
    $now  = time();

    $data = ['requests' => []];
    if (is_file($file)) {
        $data = json_decode((string) file_get_contents($file), true) ?: $data;
    }

    // Manter apenas requisições do último minuto
    $data['requests'] = array_values(array_filter($data['requests'], fn($t) => ($now - $t) < 60));

    if (count($data['requests']) >= $max) {
        http_response_code(429);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Muitas requisições. Aguarde um minuto.']);
        exit;
    }

    $data['requests'][] = $now;
    @file_put_contents($file, json_encode($data));
}

/**
 * Registra uma falha de login para o e-mail informado.
 */
function recordLoginFailure(string $email): void
{
    $dir = STORAGE_PATH . '/rate_limits';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $file = $dir . '/login_' . md5(strtolower($email)) . '.json';
    $data = ['failures' => [], 'blocked_until' => 0];

    if (is_file($file)) {
        $data = json_decode((string) file_get_contents($file), true) ?: $data;
    }

    $now = time();
    // Se estiver bloqueado, mantém o estado
    if ($data['blocked_until'] > $now) {
        return;
    }

    $data['failures'] = array_values(array_filter($data['failures'], fn($t) => ($now - $t) < (LOGIN_BLOCK_MINUTES * 60)));
    $data['failures'][] = $now;

    if (count($data['failures']) >= LOGIN_MAX_ATTEMPTS) {
        $data['blocked_until'] = $now + (LOGIN_BLOCK_MINUTES * 60);
        $data['failures'] = [];
    }

    @file_put_contents($file, json_encode($data));
}

/**
 * Verifica se o e-mail está bloqueado por tentativas excessivas.
 */
function checkLoginAttempt(string $email): bool
{
    $dir = STORAGE_PATH . '/rate_limits';
    $file = $dir . '/login_' . md5(strtolower($email)) . '.json';

    if (!is_file($file)) {
        return true;
    }

    $data = json_decode((string) file_get_contents($file), true) ?: ['failures' => [], 'blocked_until' => 0];
    $now  = time();

    if (($data['blocked_until'] ?? 0) > $now) {
        return false;
    }

    return true;
}

/**
 * Reseta as tentativas de login após sucesso.
 */
function resetLoginAttempts(string $email): void
{
    $file = STORAGE_PATH . '/rate_limits/login_' . md5(strtolower($email)) . '.json';
    if (is_file($file)) {
        @unlink($file);
    }
}

// ---------------- JWT ----------------

/**
 * Codifica payload em base64url.
 */
function base64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Decodifica base64url.
 */
function base64UrlDecode(string $data): string
{
    return base64_decode(strtr($data, '-_', '+/'));
}

/**
 * Gera um token JWT (HS256).
 */
function jwtEncode(array $payload): string
{
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $payload['iat'] = time();
    $payload['exp'] = time() + JWT_EXPIRY;

    $segments = [
        base64UrlEncode(json_encode($header)),
        base64UrlEncode(json_encode($payload)),
    ];

    $signingInput = implode('.', $segments);
    $signature = hash_hmac('sha256', $signingInput, JWT_SECRET, true);

    return $signingInput . '.' . base64UrlEncode($signature);
}

/**
 * Valida e decodifica um token JWT. Retorna o payload ou null.
 */
function jwtDecode(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }

    [$headerB64, $payloadB64, $signatureB64] = $parts;

    $signingInput = $headerB64 . '.' . $payloadB64;
    $signature = base64UrlDecode($signatureB64);

    $expected = hash_hmac('sha256', $signingInput, JWT_SECRET, true);
    if (!hash_equals($expected, $signature)) {
        return null;
    }

    $payload = json_decode(base64UrlDecode($payloadB64), true);
    if (!is_array($payload) || ($payload['exp'] ?? 0) < time()) {
        return null;
    }

    return $payload;
}