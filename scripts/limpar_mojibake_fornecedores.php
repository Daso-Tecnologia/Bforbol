<?php
/**
 * scripts/limpar_mojibake_fornecedores.php
 * Roda a correção de mojibake (CP437 -> CP1252) NOS REGISTROS JÁ GRAVADOS
 * da tabela fornecedores (host MySQL).
 *
 * Uso:
 *   php scripts/limpar_mojibake_fornecedores.php            -> aplica UPDATE
 *   php scripts/limpar_mojibake_fornecedores.php --somente-relatorio -> só reporta
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$somenteRelatorio = false;
foreach ($argv as $arg) {
    if ($arg === '--somente-relatorio') $somenteRelatorio = true;
}

$pdo = getConnection();

$campos = ['fan_for', 'raz_for', 'email_for', 'email_for_2', 'email_for_3', 'email_for_4', 'email_for_5', 'email_for_6'];

$rows = $pdo->query("SELECT id, {$campos[0]}, {$campos[1]}, {$campos[2]}, {$campos[3]}, {$campos[4]}, {$campos[5]}, {$campos[6]}, {$campos[7]} FROM fornecedores")->fetchAll();

$afetados = 0;
$corrigidos = [];

$stmt = $pdo->prepare(
    "UPDATE fornecedores SET
        fan_for = :fan_for, raz_for = :raz_for,
        email_for = :email_for, email_for_2 = :email_for_2, email_for_3 = :email_for_3,
        email_for_4 = :email_for_4, email_for_5 = :email_for_5, email_for_6 = :email_for_6
     WHERE id = :id"
);

foreach ($rows as $row) {
    $valores = [];
    $mudou = false;

    foreach ($campos as $campo) {
        $orig = (string) ($row[$campo] ?? '');
        $novo = corregirMojibakeCP437($orig);
        $valores[$campo] = $novo;
        if ($orig !== $novo) {
            $mudou = true;
        }
    }

    if ($mudou) {
        $afetados++;
        if (!($row['fan_for'] === $valores['fan_for'])) {
            $corrigidos[] = "  {$row['id']} | FAN: '{$row['fan_for']}' -> '{$valores['fan_for']}'";
        } else {
            $corrigidos[] = "  {$row['id']} | outros campos corrigidos";
        }

        if (!$somenteRelatorio) {
            $stmt->execute([
                'fan_for'   => $valores['fan_for'],
                'raz_for'   => $valores['raz_for'],
                'email_for' => $valores['email_for'],
                'email_for_2' => $valores['email_for_2'],
                'email_for_3' => $valores['email_for_3'],
                'email_for_4' => $valores['email_for_4'],
                'email_for_5' => $valores['email_for_5'],
                'email_for_6' => $valores['email_for_6'],
                'id'        => (int) $row['id'],
            ]);
        }
    }
}

echo "Total de fornecedores lidos: " . count($rows) . "\n";
echo "Registros com mojibake (afetados): {$afetados}\n";

if ($afetados > 0) {
    echo "\nExemplos (primeiros até 15):\n";
    echo implode("\n", array_slice($corrigidos, 0, 15)) . "\n";
}

if ($somenteRelatorio) {
    echo "\n[MODO RELATÓRIO — nada foi gravado]\n";
} else {
    echo "\nGRAVAÇÃO concluída.\n";
}