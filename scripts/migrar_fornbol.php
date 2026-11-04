<?php
/**
 * scripts/migrar_fornbol.php
 * Migra os fornecedores do FORNBOL.csv (legado FiveWin) para a tabela fornecedores.
 *
 * Uso:
 *   php scripts/migrar_fornbol.php [--arquivo=C:\php_boletos\FORNBOL.csv] [--somente-relatorio]
 *
 * Regras:
 *   - lê FORNBOL.csv (separador ';', cabeçalho com BOM UTF-8)
 *   - mapeia: COD_FOR->cod_for | FAN_FOR->fan_for | RAZ_FOR->raz_for
 *             EMAIL_FOR->email_for | EMAILFOR01..05->email_for_2..6
 *   - ignore DIR_BOL; regenera o caminho padrão storage/boleto/<FAN>-<COD>
 *   - ATIVO: VERDADEIRO->1 | FALSO->0
 *   - limpa e-mails: minúsculas + remove sujeira + inválido vira vazio
 *   - INSERT ... ON DUPLICATE KEY UPDATE em LOTES de 100 (rápido e com
 *     transação; PDO envia UTF-8 direto, sem conversão de arquivo)
 *
 * Com --somente-relatorio não grava nada, só reporta.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$arquivo = 'C:\php_boletos\FORNBOL.csv';
$somenteRelatorio = false;

foreach ($argv as $arg) {
    if (preg_match('/^--arquivo=(.+)$/', $arg, $m)) $arquivo = $m[1];
    if ($arg === '--somente-relatorio') $somenteRelatorio = true;
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

// ---- Leitura do CSV ----
$fh = fopen($arquivo, 'r');
if ($fh === false) { fwrite(STDERR, "Não foi possível abrir o arquivo.\n"); exit(1); }

$cabecalho = fgetcsv($fh, 0, ';');
$cabecalho[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cabecalho[0] ?? '');
$cols = array_flip($cabecalho);

$registros = [];
$dup = [];
while (($row = fgetcsv($fh, 0, ';')) !== false) {
    if (count($row) < count($cabecalho)) continue;
    $reg = [];
    foreach ($cols as $campo => $idx) $reg[$campo] = $row[$idx] ?? '';

    $cod = preg_replace('/\D/', '', $reg['COD_FOR'] ?? '');
    if (strlen($cod) !== 10) continue;
    if (isset($dup[$cod])) { fwrite(STDERR, "AVISO: COD duplicado {$cod}\n"); }
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
        if ($r['email_for'] !== '' && $r[$campo] !== '' && $r[$campo] === $r['email_for']) $r[$campo] = '';
    }
}
unset($r);

// Resumo
echo "Arquivo: {$arquivo}\n";
echo "Total de registros lidos: " . count($registros) . "\n";
echo "  com e-mail principal: " . count(array_filter($registros, fn($r) => $r['email_for'] !== '')) . "\n";
echo "  ativos:   " . count(array_filter($registros, fn($r) => $r['ativo'] === 1)) . "\n";
echo "  inativos: " . count(array_filter($registros, fn($r) => $r['ativo'] === 0)) . "\n";
echo "  CC1..5: " . count(array_filter($registros, fn($r) => $r['email_for_2'] !== '')) . ' / '
    . count(array_filter($registros, fn($r) => $r['email_for_3'] !== '')) . ' / '
    . count(array_filter($registros, fn($r) => $r['email_for_4'] !== '')) . ' / '
    . count(array_filter($registros, fn($r) => $r['email_for_5'] !== '')) . ' / '
    . count(array_filter($registros, fn($r) => $r['email_for_6'] !== '')) . "\n\n";

if ($somenteRelatorio) {
    echo "[MODO RELATÓRIO — nada foi gravado]\n";
    exit(0);
}

// ---- Carga via PDO (lotes de 100) ----
$pdo = getConnection();

// Garantir que a conexão usa UTF-8 (evita erros de acentos)
$pdo->exec("SET NAMES 'utf8mb4'");

$pdo->beginTransaction();
try {
    // Apaga a tabela inteira (controle do usuário: recargo limpa)
    $pdo->exec('DELETE FROM fornecedores');

    $chunks = array_chunk($registros, 100);
    $total = 0;

    foreach ($chunks as $chunk) {
        $vals = [];
        foreach ($chunk as $r) {
            $fan = $r['fan_for'] !== '' ? mb_strtoupper($r['fan_for']) : '';
            $raz = $r['raz_for'] !== '' ? mb_strtoupper($r['raz_for']) : '';
            $dirBol = 'storage/boleto/' . sanitizarNome($fan) . '-' . $r['cod_for'];

            $vals[] = $pdo->quote($r['cod_for']) . ', ' . $pdo->quote($fan) . ', ' . $pdo->quote($raz)
                . ', ' . $pdo->quote($dirBol) . ', ' . $pdo->quote($r['email_for'])
                . ', ' . $pdo->quote($r['email_for_2']) . ', ' . $pdo->quote($r['email_for_3'])
                . ', ' . $pdo->quote($r['email_for_4']) . ', ' . $pdo->quote($r['email_for_5'])
                . ', ' . $pdo->quote($r['email_for_6']) . ', ' . $r['ativo']
                . ', NOW(), NOW()';
        }

        $sql = 'INSERT INTO fornecedores'
            . ' (cod_for, fan_for, raz_for, dir_bol, email_for, email_for_2, email_for_3,'
            . '  email_for_4, email_for_5, email_for_6, ativo, created_at, updated_at)'
            . ' VALUES (' . implode('), (', $vals) . ')'
            . ' ON DUPLICATE KEY UPDATE'
            . ' fan_for = VALUES(fan_for), raz_for = VALUES(raz_for), dir_bol = VALUES(dir_bol),'
            . ' email_for = VALUES(email_for), email_for_2 = VALUES(email_for_2),'
            . ' email_for_3 = VALUES(email_for_3), email_for_4 = VALUES(email_for_4),'
            . ' email_for_5 = VALUES(email_for_5), email_for_6 = VALUES(email_for_6),'
            . ' ativo = VALUES(ativo), updated_at = NOW()';

        $n = $pdo->exec($sql);
        if ($n === false) {
            throw new RuntimeException('Falha na carga do lote');
        }
        $total += count($chunk);
    }

    $pdo->commit();
    echo "Carga concluída via PDO: {$total} registros (lotes de 100).\n";
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "ERRO durante a migração (rollback aplicado): " . $e->getMessage() . "\n");
    exit(1);
}