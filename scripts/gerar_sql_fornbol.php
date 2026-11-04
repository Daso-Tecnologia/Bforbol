<?php
/**
 * scripts/gerar_sql_fornbol.php
 * Gera FORNBOL_MIGRADO.sql com INSERTs prontos (colunas nomeadas) para
 * importar via HeidiSQL com 100% de garantia (sem mapeamento manual).
 *
 * Uso:
 *   php scripts/gerar_sql_fornbol.php
 *   -> gera C:\php_boletos\FORNBOL_MIGRADO.sql
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

$arquivo = 'C:\php_boletos\FORNBOL.csv';
$saida   = 'C:\php_boletos\FORNBOL_MIGRADO.sql';

foreach ($argv as $arg) {
    if (preg_match('/^--arquivo=(.+)$/', $arg, $m)) $arquivo = $m[1];
    if (preg_match('/^--saida=(.+)$/', $arg, $m))    $saida   = $m[1];
}

if (!is_file($arquivo)) {
    fwrite(STDERR, "Arquivo não encontrado: {$arquivo}\n");
    exit(1);
}

function limparEmail(string $email): string
{
    $email = trim($email);
    if (preg_match('/([A-Za-z0-9._%+\-]+@[A-Za-z0-9._\-]+\.[A-Za-z]{2,})/', $email, $m)) {
        $email = $m[1];
    }
    $email = mb_strtolower($email);
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

function sanitizarNome(string $nome): string
{
    return preg_replace('/[^A-Za-z0-9 _.-]/', '', trim($nome));
}

// ---- Ler CSV ----
$fh = fopen($arquivo, 'r');
if ($fh === false) { fwrite(STDERR, "Não abriu arquivo fonte.\n"); exit(1); }
$header = fgetcsv($fh, 0, ';');
$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0] ?? '');
$cols = array_flip($header);

$registros = [];
$dup = [];
while (($row = fgetcsv($fh, 0, ';')) !== false) {
    if (count($row) < count($header)) continue;
    $reg = [];
    foreach ($cols as $campo => $idx) $reg[$campo] = $row[$idx] ?? '';

    $cod = preg_replace('/\D/', '', $reg['COD_FOR'] ?? '');
    if (strlen($cod) !== 10) continue;
    if (isset($dup[$cod])) { fwrite(STDERR, "COD duplicado no CSV: {$cod}\n"); }
    $dup[$cod] = true;

    $registros[] = [
        'cod_for'     => $cod,
        'fan_for'     => trim($reg['FAN_FOR'] ?? ''),
        'raz_for'     => trim($reg['RAZ_FOR'] ?? ''),
        'email_for'   => limparEmail((string) ($reg['EMAIL_FOR'] ?? '')),
        'email_for_2' => limparEmail((string) ($reg['EMAILFOR01'] ?? '')),
        'email_for_3' => limparEmail((string) ($reg['EMAILFOR02'] ?? '')),
        'email_for_4' => limparEmail((string) ($reg['EMAILFOR03'] ?? '')),
        'email_for_5' => limparEmail((string) ($reg['EMAILFOR04'] ?? '')),
        'email_for_6' => limparEmail((string) ($reg['EMAILFOR05'] ?? '')),
        'ativo' => (strtoupper(trim((string) ($reg['ATIVO'] ?? 'VERDADEIRO'))) === 'VERDADEIRO') ? 1 : 0,
    ];
}
fclose($fh);

// Guarda anti-duplicado CC == principal
foreach ($registros as &$r) {
    foreach (['email_for_2','email_for_3','email_for_4','email_for_5','email_for_6'] as $campo) {
        if ($r['email_for'] !== '' && $r[$campo] !== '' && $r[$campo] === $r['email_for']) {
            $r[$campo] = '';
        }
    }
}
unset($r);

// ---- Gerar SQL ----
$agora = date('Y-m-d H:i:s');

function esc(string $v): string
{
    return str_replace(["\\", "'", "\0", "\n", "\r"], ["\\\\", "''", "\\0", "\\n", "\\r"], $v);
}

$linhas = [];
$linhas[] = '-- Migração FORNBOL (gerado automaticamente)';
$linhas[] = '-- Fonte: FORNBOL.csv | Total: ' . count($registros);
$linhas[] = 'USE `iintegra_boletos`;';
$linhas[] = '';
$linhas[] = '-- Limpa a tabela (controle do usuário)';
$linhas[] = 'DELETE FROM `fornecedores`;';
$linhas[] = '';
$linhas[] = '-- Insert inicial de mensagens por lote (100 por INSERT)';

$chunks = array_chunk($registros, 100);
$cont = 0;
foreach ($chunks as $chunk) {
    $vals = [];
    foreach ($chunk as $r) {
        $fan = $r['fan_for'] !== '' ? mb_strtoupper($r['fan_for']) : '';
        $raz = $r['raz_for'] !== '' ? mb_strtoupper($r['raz_for']) : '';
        $dirBol = 'storage/boleto/' . sanitizarNome($fan) . '-' . $r['cod_for'];

        $vals[] = sprintf(
            "('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', %d, '%s', '%s')",
            esc($r['cod_for']),
            esc($fan),
            esc($raz),
            esc($dirBol),
            esc($r['email_for']),
            esc($r['email_for_2']),
            esc($r['email_for_3']),
            esc($r['email_for_4']),
            esc($r['email_for_5']),
            esc($r['email_for_6']),
            $r['ativo'],
            $agora,
            $agora
        );
    }

    $linhas[] = 'INSERT INTO `fornecedores`'
        . ' (`cod_for`, `fan_for`, `raz_for`, `dir_bol`, `email_for`, `email_for_2`, `email_for_3`,'
        . '  `email_for_4`, `email_for_5`, `email_for_6`, `ativo`, `created_at`, `updated_at`)'
        . ' VALUES' . "\n" . implode(",\n", $vals) . ';';
    $linhas[] = '';
    $cont += count($chunk);
}

$linhas[] = '-- Total inserido: ' . $cont;

$out = fopen($saida, 'w');
if ($out === false) { fwrite(STDERR, "Não abriu arquivo de saída.\n"); exit(1); }
// BOM UTF-8 para o HeidiSQL detectar acentos corretamente
fwrite($out, "\xEF\xBB\xBF");
fwrite($out, implode("\n", $linhas));
fclose($out);

echo "SQL gerado: {$saida}\n";
echo "Registros: {$cont}\n";
echo "INSERTs em lotes de 100\n";