<?php
/**
 * scripts/gerar_csv_fornbol.php
 * Gera FORNBOL_MIGRADO.csv (UTF-8, separador ';') pronto para importar via
 * HeidiSQL na tabela fornecedores do host MySQL.
 *
 * Regras aplicadas (idênticas ao sistema):
 *   - cod_for: 10 dígitos
 *   - fan_for / raz_for: MAIÚSCULAS (regra do cadastro manual)
 *   - dir_bol: caminho padrão storage/boleto/<FAN>-<COD> (o sistema recalcula
 *              ao processar, este campo é referência)
 *   - e-mails: minúsculas, sujeira removida, inválido vira vazio
 *   - CC igual ao principal vira vazio (guarda anti-duplicado)
 *   - ativo: 1 = VERDADEIRO, 0 = FALSO
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

$arquivo = 'C:\php_boletos\FORNBOL.csv';
$saida   = 'C:\php_boletos\FORNBOL_MIGRADO.csv';

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

// ---- Ler CSV fonte ----
$fh = fopen($arquivo, 'r');
if ($fh === false) { fwrite(STDERR, "Não abriu arquivo fonte.\n"); exit(1); }
$header = fgetcsv($fh, 0, ';');
$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0] ?? '');
$cols = array_flip($header);

$registros = [];
while (($row = fgetcsv($fh, 0, ';')) !== false) {
    if (count($row) < count($header)) continue;
    $reg = [];
    foreach ($cols as $campo => $idx) $reg[$campo] = $row[$idx] ?? '';

    $cod = preg_replace('/\D/', '', $reg['COD_FOR'] ?? '');
    if (strlen($cod) !== 10) continue;

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

// Guarda anti-duplicado
foreach ($registros as &$r) {
    foreach (['email_for_2','email_for_3','email_for_4','email_for_5','email_for_6'] as $campo) {
        if ($r['email_for'] !== '' && $r[$campo] !== '' && $r[$campo] === $r['email_for']) {
            $r[$campo] = '';
        }
    }
}
unset($r);

// ---- Gerar CSV de saída (UTF-8 sem BOM, ';') ----
$out = fopen($saida, 'w');
if ($out === false) { fwrite(STDERR, "Não abriu arquivo de saída.\n"); exit(1); }

$colunas = ['cod_for','fan_for','raz_for','dir_bol','email_for','email_for_2','email_for_3','email_for_4','email_for_5','email_for_6','ativo'];
fputcsv($out, $colunas, ';');

foreach ($registros as $r) {
    $fan = $r['fan_for'] !== '' ? mb_strtoupper($r['fan_for']) : '';
    $raz = $r['raz_for'] !== '' ? mb_strtoupper($r['raz_for']) : '';
    $dirBol = 'storage/boleto/' . sanitizarNome($fan) . '-' . $r['cod_for'];

    fputcsv($out, [
        $r['cod_for'],
        $fan,
        $raz,
        $dirBol,
        $r['email_for'],
        $r['email_for_2'],
        $r['email_for_3'],
        $r['email_for_4'],
        $r['email_for_5'],
        $r['email_for_6'],
        $r['ativo'],
    ], ';');
}
fclose($out);

echo "Arquivo gerado: {$saida}\n";
echo "Registros: " . count($registros) . "\n";