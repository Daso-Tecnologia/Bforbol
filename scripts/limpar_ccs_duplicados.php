<?php
/**
 * scripts/limpar_ccs_duplicados.php
 * Limpa os e-mails de cópia (CC) duplicados ou iguais ao principal na tabela fornecedores.
 *
 * Uso:
 *   php scripts/limpar_ccs_duplicados.php [--somente-relatorio]
 *
 * Regras (mantém apenas o que é manual/legítimo):
 *   - CC igual ao e-mail principal (email_for) -> vazio.
 *   - CCs duplicados entre si (2..6) -> mantém apenas a primeira ocorrência,
 *     os demais duplicados viram vazio.
 *   - O e-mail principal NUNCA é alterado.
 *   - E-mails são normalizados (minúsculas + limpeza de sujeira).
 *
 * Com --somente-relatorio não grava nada, só reporta o que seria alterado.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$somenteRelatorio = false;
foreach ($argv as $arg) {
    if ($arg === '--somente-relatorio') $somenteRelatorio = true;
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

$pdo = getConnection();

$stmt = $pdo->query(
    'SELECT id, cod_for, fan_for, email_for, email_for_2, email_for_3, email_for_4, email_for_5, email_for_6
     FROM fornecedores ORDER BY id ASC'
);
$fornecedores = $stmt->fetchAll();

$campos = ['email_for_2', 'email_for_3', 'email_for_4', 'email_for_5', 'email_for_6'];

$alterados = 0;
$totais = count($fornecedores);

if ($somenteRelatorio) {
    fwrite(STDOUT, "MODO RELATÓRIO (nada será gravado)\n\n");
}

foreach ($fornecedores as $f) {
    $principal = limparEmail((string) $f['email_for']);
    $novos = [];

    // Normaliza e limpa CCs
    foreach ($campos as $campo) {
        $novos[$campo] = limparEmail((string) $f[$campo]);
    }

    // 1) CC igual ao principal -> vazio
    if ($principal !== '') {
        foreach ($campos as $campo) {
            if ($novos[$campo] !== '' && $novos[$campo] === $principal) {
                $novos[$campo] = '';
            }
        }
    }

    // 2) Deduplicar CCs entre si (mantém a primeira ocorrência de cada valor)
    $vistos = [];
    foreach ($campos as $campo) {
        $email = $novos[$campo];
        if ($email === '') {
            continue;
        }
        if (isset($vistos[$email])) {
            $novos[$campo] = '';
        } else {
            $vistos[$email] = true;
        }
    }

    // Verificar se houve mudança
    $mudou = false;
    foreach ($campos as $campo) {
        if ($novos[$campo] !== $f[$campo]) {
            $mudou = true;
            break;
        }
    }

    if (!$mudou) {
        continue;
    }

    $alterados++;
    $resumo = [];
    foreach ($campos as $campo) {
        $resumo[] = $campo . ': ' . ($f[$campo] !== '' ? $f[$campo] : '(vazio)') . ' -> ' . ($novos[$campo] !== '' ? $novos[$campo] : '(vazio)');
    }

    fwrite(STDOUT, sprintf(
        "[%s] id=%d cod=%s %s\n    %s\n",
        $alterados,
        (int) $f['id'],
        $f['cod_for'],
        $f['fan_for'],
        implode(' | ', $resumo)
    ));

    if (!$somenteRelatorio) {
        $sql = 'UPDATE fornecedores SET
                    email_for_2 = :email_for_2,
                    email_for_3 = :email_for_3,
                    email_for_4 = :email_for_4,
                    email_for_5 = :email_for_5,
                    email_for_6 = :email_for_6
                WHERE id = :id';
        $stmtUpd = $pdo->prepare($sql);
        $stmtUpd->execute([
            'email_for_2' => $novos['email_for_2'],
            'email_for_3' => $novos['email_for_3'],
            'email_for_4' => $novos['email_for_4'],
            'email_for_5' => $novos['email_for_5'],
            'email_for_6' => $novos['email_for_6'],
            'id'           => (int) $f['id'],
        ]);
    }
}

fwrite(STDOUT, "\nTotal de fornecedores: {$totais}\n");
fwrite(STDOUT, ($somenteRelatorio ? 'A alterar: ' : 'Alterados: ') . "{$alterados}\n");

if ($somenteRelatorio) {
    fwrite(STDOUT, "\nPara aplicar, rode sem --somente-relatorio.\n");
}
