<?php
/**
 * api/fornecedores/salvar.php
 * POST protegido (completo) - cria ou edita fornecedor.
 */

declare(strict_types=1);

require_once __DIR__ . '/../auth/validar_jwt.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/mssql.php';

header('Content-Type: application/json');

validarJWTCompleto();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

if (empty($input['cod_for']) || empty($input['fan_for'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Código (cod_for) e nome fantasia (fan_for) são obrigatórios']);
    exit;
}

$codFor = str_pad(preg_replace('/\D/', '', $input['cod_for']), 10, '0', STR_PAD_LEFT);
if (strlen($codFor) > 10) {
    $codFor = substr($codFor, -10);
}

// Corrigir mojibake de página de código (ex.: "HOT╔IS" -> "HOTÉIS")
$fanFor = mb_strtoupper(corregirMojibakeCP437(trim((string) ($input['fan_for'] ?? ''))));
$razFor = mb_strtoupper(corregirMojibakeCP437(trim((string) ($input['raz_for'] ?? $fanFor))));

// Normalizar e-mails (minúsculas)
$emails = [];
foreach (['email_for', 'email_for_2', 'email_for_3', 'email_for_4', 'email_for_5', 'email_for_6'] as $campo) {
    $emails[$campo] = mb_strtolower(corregirMojibakeCP437(trim((string) ($input[$campo] ?? ''))));
}

// Guardia anti-duplicado: uma cópia (CC) nunca deve repetir o e-mail principal.
// Os CC se preenchem só manualmente; si alguém/algún auto-preenchimento copió
// el principal, se limpia para não enviar duplicados.
$principal = $emails['email_for'];
foreach (['email_for_2', 'email_for_3', 'email_for_4', 'email_for_5', 'email_for_6'] as $campo) {
    if ($principal !== '' && $emails[$campo] === $principal) {
        $emails[$campo] = '';
    }
}

$ativo = isset($input['ativo']) ? (int) (bool) $input['ativo'] : 1;

$pdo = getConnection();

// O cadastro manual NÃO exige mais que o código conste na wg_ven (vendas) do GATE:
// o boleto enviado já prova que a cobrança existe.

// Verificar duplicidade
$sql = "SELECT id FROM fornecedores WHERE cod_for = :cod_for";
$params = ['cod_for' => $codFor];
if (!empty($input['id'])) {
    $sql .= " AND id != :id";
    $params['id'] = (int) $input['id'];
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
if ($stmt->fetch()) {
    http_response_code(409);
    echo json_encode(['error' => 'Já existe um fornecedor com este código (cod_for)']);
    exit;
}

$dirBol = BOLETO_PATH . '/' . preg_replace('/[^A-Za-z0-9 _.-]/', '', $fanFor) . '-' . $codFor;

if (!empty($input['id'])) {
    $sql = "UPDATE fornecedores SET
                cod_for = :cod_for,
                fan_for = :fan_for,
                raz_for = :raz_for,
                dir_bol = :dir_bol,
                email_for = :email_for,
                email_for_2 = :email_for_2,
                email_for_3 = :email_for_3,
                email_for_4 = :email_for_4,
                email_for_5 = :email_for_5,
                email_for_6 = :email_for_6,
                ativo = :ativo
            WHERE id = :id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($emails, [
        'cod_for' => $codFor,
        'fan_for' => $fanFor,
        'raz_for' => $razFor,
        'dir_bol' => $dirBol,
        'ativo'   => $ativo,
        'id'      => (int) $input['id'],
    ]));

    echo json_encode(['success' => true, 'message' => 'Fornecedor atualizado com sucesso']);
    exit;
}

$sql = "INSERT INTO fornecedores
            (cod_for, fan_for, raz_for, dir_bol, email_for, email_for_2, email_for_3,
             email_for_4, email_for_5, email_for_6, ativo)
        VALUES
            (:cod_for, :fan_for, :raz_for, :dir_bol, :email_for, :email_for_2, :email_for_3,
             :email_for_4, :email_for_5, :email_for_6, :ativo)";

$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge($emails, [
    'cod_for' => $codFor,
    'fan_for' => $fanFor,
    'raz_for' => $razFor,
    'dir_bol' => $dirBol,
    'ativo'   => $ativo,
]));

echo json_encode([
    'success' => true,
    'message' => 'Fornecedor criado com sucesso',
    'id'      => dbLastId(),
]);