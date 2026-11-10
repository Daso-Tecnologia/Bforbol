<?php
/**
 * config/config.php
 * Carrega o arquivo .env e define as constantes globais do sistema.
 */

declare(strict_types=1);

// Fuso horário fixo do sistema (Brasília) — evita timestamps em UTC
// ao migrar entre hosts (Linux/ScriptCase -> Windows/XAMPP).
date_default_timezone_set('America/Sao_Paulo');

// ==============================================================
//  CAMINHOS BASE
// ==============================================================
const BASE_PATH       = __DIR__ . '/..';
const APP_PATH        = BASE_PATH . '/app';
const CONFIG_PATH     = BASE_PATH . '/config';
const API_PATH        = BASE_PATH . '/api';
const PUBLIC_PATH     = BASE_PATH . '/public';
const STORAGE_PATH    = BASE_PATH . '/storage';
const UPLOAD_PATH     = STORAGE_PATH . '/enviar';
const BOLETO_PATH     = STORAGE_PATH . '/boleto';
const RELATORIO_PATH  = STORAGE_PATH . '/relatorios';

// ==============================================================
//  AUTOLOAD DO COMPOSER
// ==============================================================
// Carregado de forma centralizada (PHPMailer, firebase/php-jwt e classes App\).
// Necessário porque os endpoints de API são servidos diretamente (sem passar
// pelo front controller index.php, que já carrega o autoload).
if (is_file(BASE_PATH . '/vendor/autoload.php')) {
    require_once BASE_PATH . '/vendor/autoload.php';
}

// ==============================================================
//  CARREGAMENTO DO .env
// ==============================================================

/**
 * Carrega o arquivo .env para as variáveis de ambiente.
 *
 * Regras:
 *  - Ignora linhas vazias e comentários (#).
 *  - Aceita KEY=value, KEY="value", KEY='value'.
 *  - Aspas só são removidas quando envolvem TODO o valor.
 *  - Não sobrescreve variáveis já definidas no ambiente do processo
 *    (importante em produção, onde o host pode injetar segredos).
 */
function loadEnvFile(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        // Ignora vazias, comentários e linhas sem '='
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);

        // Remove aspas SOMENTE se envolverem todo o valor
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last  = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        if ($key === '') {
            continue;
        }

        // Respeita variáveis já exportadas pelo ambiente (produção)
        if (getenv($key) !== false) {
            continue;
        }

        putenv("$key=$value");
        $_ENV[$key]    = $value;
        $_SERVER[$key] = $value;
    }
}

loadEnvFile(BASE_PATH . '/.env');

// ==============================================================
//  HELPERS
// ==============================================================

/**
 * Lê variável de ambiente com valor padrão.
 * Converte automaticamente 'true'/'false'/'null' (case-insensitive).
 */
function env(string $key, mixed $default = null): mixed
{
    $value = getenv($key);
    if ($value === false) {
        // Fallback para $_ENV/$_SERVER caso putenv esteja desabilitado
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? false;
    }

    if ($value === false || $value === '') {
        return $default;
    }

    $lower = strtolower((string) $value);
    if ($lower === 'true')  return true;
    if ($lower === 'false') return false;
    if ($lower === 'null')  return null;

    return $value;
}

/**
 * Lê variável booleana (aceita 1/0, true/false, on/off, yes/no).
 */
function envBool(string $key, bool $default = false): bool
{
    $value = env($key, $default ? '1' : '0');
    if (is_bool($value)) {
        return $value;
    }
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

/**
 * Lê variável inteira.
 */
function envInt(string $key, int $default = 0): int
{
    $value = env($key, $default);
    return is_numeric($value) ? (int) $value : $default;
}

/**
 * Define uma constante apenas se ainda não existir,
 * evitando warnings em dupla inclusão do arquivo.
 */
function defineConst(string $name, mixed $value): void
{
    if (!defined($name)) {
        define($name, $value);
    }
}

// ==============================================================
//  CONSTANTES DE AMBIENTE
// ==============================================================

defineConst('APP_ENV',   (string) env('APP_ENV', 'production'));
defineConst('APP_DEBUG', envBool('APP_DEBUG', false));

// -------- Banco de Dados MySQL --------
defineConst('DB_HOST',    (string) env('DB_HOST', 'localhost'));
defineConst('DB_PORT',    envInt('DB_PORT', 3306));
defineConst('DB_NAME',    (string) env('DB_NAME', 'saas_boletos'));
defineConst('DB_USER',    (string) env('DB_USER', 'root'));
defineConst('DB_PASS',    (string) env('DB_PASS', ''));
defineConst('DB_CHARSET', (string) env('DB_CHARSET', 'utf8mb4'));

// -------- MSSQL (GATE - repositório corporativo) --------
defineConst('MSSQL_DRIVER', (string) env('MSSQL_DRIVER', 'sqlsrv'));
defineConst('MSSQL_HOST',   (string) env('MSSQL_HOST', ''));
defineConst('MSSQL_PORT',   envInt('MSSQL_PORT', 1433));
defineConst('MSSQL_DB',     (string) env('MSSQL_DB', ''));
defineConst('MSSQL_USER',   (string) env('MSSQL_USER', ''));
defineConst('MSSQL_PASS',   (string) env('MSSQL_PASS', ''));
defineConst('MSSQL_TABELA', (string) env('MSSQL_TABELA', 'wgfor'));
defineConst('MSSQL_TABELA_VENDA', (string) env('MSSQL_TABELA_VENDA', 'wg_ven'));

// -------- SMTP --------
defineConst('SMTP_HOST',      (string) env('SMTP_HOST', ''));
defineConst('SMTP_PORT',      envInt('SMTP_PORT', 587));
defineConst('SMTP_SECURE',    (string) env('SMTP_SECURE', 'tls'));
defineConst('SMTP_USER',      (string) env('SMTP_USER', ''));
defineConst('SMTP_PASS',      (string) env('SMTP_PASS', ''));
defineConst('SMTP_FROM',      (string) env('SMTP_FROM', ''));
defineConst('SMTP_FROM_NAME', (string) env('SMTP_FROM_NAME', 'Sua Empresa'));
defineConst('CC_FIXO',        (string) env('CC_FIXO', ''));
defineConst('ENVIO_EMAIL_COPIA', envBool('ENVIO_EMAIL_COPIA', true));
defineConst('SMTP_TIMEOUT',   envInt('SMTP_TIMEOUT', 20));

// -------- JWT --------
defineConst('JWT_SECRET', (string) env('JWT_SECRET', ''));
defineConst('JWT_EXPIRY', envInt('JWT_EXPIRY', 7200));

// -------- Login / Segurança --------
defineConst('LOGIN_MAX_ATTEMPTS',    envInt('LOGIN_MAX_ATTEMPTS', 5));
defineConst('LOGIN_BLOCK_MINUTES',   envInt('LOGIN_BLOCK_MINUTES', 30));
defineConst('RATE_LIMIT_PER_MINUTE', envInt('RATE_LIMIT_PER_MINUTE', 120));

// -------- Upload --------
defineConst('UPLOAD_MAX_SIZE',     envInt('UPLOAD_MAX_SIZE', 10485760));
defineConst('ALLOWED_EXTENSIONS',  (string) env('ALLOWED_EXTENSIONS', 'pdf'));

// -------- Worker / Fila --------
defineConst('WORKER_LIMITE',             envInt('WORKER_LIMITE', 20));
defineConst('WORKER_INTERVALO_SEGUNDOS', envInt('WORKER_INTERVALO_SEGUNDOS', 5));
defineConst('WORKER_MAX_TENTATIVAS',     envInt('WORKER_MAX_TENTATIVAS', 3));

// -------- Limite de anexos por e-mail (MB) --------
defineConst('ENVIO_LIMITE_TOTAL_MB', envInt('ENVIO_LIMITE_TOTAL_MB', 10));

// ==============================================================
//  VALIDAÇÃO DE SEGREDOS OBRIGATÓRIOS EM PRODUÇÃO
// ==============================================================

if (APP_ENV === 'production') {
    $obrigatorios = [
        'DB_HOST'    => DB_HOST,
        'DB_NAME'    => DB_NAME,
        'DB_USER'    => DB_USER,
        'JWT_SECRET' => JWT_SECRET,
    ];

    $faltando = [];
    foreach ($obrigatorios as $nome => $valor) {
        if ($valor === '' || $valor === null) {
            $faltando[] = $nome;
        }
    }

    // Bloqueia segredos padrão conhecidos
    if (JWT_SECRET === 'chave-secreta-padrao-troque-no-env'
        || JWT_SECRET === 'troque-por-uma-chave-aleatoria-longa-de-seguranca') {
        $faltando[] = 'JWT_SECRET (valor padrão detectado)';
    }

    if (JWT_SECRET !== '' && strlen(JWT_SECRET) < 32) {
        $faltando[] = 'JWT_SECRET (mínimo de 32 caracteres)';
    }

    if (!empty($faltando)) {
        http_response_code(500);
        error_log('[config] Variáveis obrigatórias ausentes: ' . implode(', ', $faltando));
        exit('Configuração incompleta. Contate o administrador. Faltando: ' . implode(', ', $faltando));
    }

    // Em produção, nunca exibir erros na tela
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL);
} else {
    // Desenvolvimento: exibe tudo
    ini_set('display_errors', APP_DEBUG ? '1' : '0');
    error_reporting(E_ALL);
}

// ==============================================================
//  GARANTIA DOS DIRETÓRIOS DE STORAGE
// ==============================================================

foreach ([STORAGE_PATH, UPLOAD_PATH, BOLETO_PATH, RELATORIO_PATH] as $dir) {
    if (is_dir($dir)) {
        continue;
    }

    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
        error_log("[config] Falha ao criar diretório: {$dir}");
        if (APP_ENV !== 'production') {
            throw new RuntimeException("Não foi possível criar diretório: {$dir}");
        }
    }
}

// ==============================================================
//  CORREÇÃO DE MOJIBAKE DE PÁGINA DE CÓDIGO (CP437/DOS -> CP1252)
// ==============================================================
// Dados vindos do GATE (MSSQL) às vezes chegam com caracteres "fantasma":
// bytes de acentos (CP1252/latino) lidos como se fossem CP437/DOS viram
// símbolos de caixa ("box drawing"), ex.: "HOTÉIS" -> "HOT╔IS".
// Esta função converte esses glifos de volta à letra correta, sem tocar
// em texto já válido (só mapeia glifos que nunca aparecem em texto comum).

/**
 * Corrige mojibake CP437->CP1252 em uma string.
 *
 * @return string texto com os glifos "fantasma" reconvertidos
 */
function corregirMojibakeCP437(string $s): string
{
    static $mapa = null;

    if ($mapa === null) {
        // glifo fantasma (CP437) => byte código que representa no latin/CP1252
        $glifoParaByte = [
            // box-drawing / blocos (0xB0-0xDF) — os "fantasmas" típicos
            "\u{2591}"=>0xB0, "\u{2592}"=>0xB1, "\u{2593}"=>0xB2, "\u{2502}"=>0xB3,
            "\u{2524}"=>0xB4, "\u{2561}"=>0xB5, "\u{2562}"=>0xB6, "\u{2556}"=>0xB7,
            "\u{2555}"=>0xB8, "\u{2563}"=>0xB9, "\u{2551}"=>0xBA, "\u{2557}"=>0xBB,
            "\u{255D}"=>0xBC, "\u{255C}"=>0xBD, "\u{255B}"=>0xBE, "\u{2510}"=>0xBF,
            "\u{2514}"=>0xC0, "\u{2534}"=>0xC1, "\u{252C}"=>0xC2, "\u{251C}"=>0xC3,
            "\u{2500}"=>0xC4, "\u{253C}"=>0xC5, "\u{255E}"=>0xC6, "\u{255F}"=>0xC7,
            "\u{255A}"=>0xC8, "\u{2554}"=>0xC9, "\u{2569}"=>0xCA, "\u{2566}"=>0xCB,
            "\u{2560}"=>0xCC, "\u{2550}"=>0xCD, "\u{256C}"=>0xCE, "\u{2567}"=>0xCF,
            "\u{2568}"=>0xD0, "\u{2564}"=>0xD1, "\u{2565}"=>0xD2, "\u{2559}"=>0xD3,
            "\u{2558}"=>0xD4, "\u{2552}"=>0xD5, "\u{2553}"=>0xD6, "\u{256B}"=>0xD7,
            "\u{256A}"=>0xD8, "\u{2518}"=>0xD9, "\u{250C}"=>0xDA, "\u{2588}"=>0xDB,
            "\u{2584}"=>0xDC, "\u{258C}"=>0xDD, "\u{2590}"=>0xDE, "\u{2580}"=>0xDF,
        ];

        $mapa = [];
        foreach ($glifoParaByte as $glifo => $byte) {
            $alvo = @mb_convert_encoding(chr($byte), 'UTF-8', 'CP1252');
            if ($alvo !== false && $alvo !== '') {
                $mapa[$glifo] = $alvo;
            }
        }
    }

    // Aplica a 1ª camada: glifos "fantasma" (box) de volta à letra correta
    $s = strtr($s, $mapa);

    // 2ª camada: mojibake duplo (um único acento virou dois caracteres).
    // Ex.: "ADMINISTRAÇÃO" -> "ADMINISTRAÃÃO" | "SERVIÇOS" -> "SERVIÃOS"
    $mapaDuplo = [
        "ADMINISTRA\u{00C3}\u{00C3}O" => "ADMINISTRAÇÃO",
        "ADMINISTRA\u{00C3}\u{251C}O" => "ADMINISTRAÇÃO",
        "SERVI\u{00C3}OS" => "SERVIÇOS",
        "\u{00C3}\u{251C}" => "Ç",
    ];

    return strtr($s, $mapaDuplo);
}