<?php
/**
 * api/fornecedores/sincronizar_gate.php
 * POST protegido (completo) - sincroniza o cadastro local de fornecedores com o GATE (wgfor).
 *
 * Regras (nada manual é sobrescrito):
 *  - E-mail principal local VAZIO + GATE com e-mail válido: completa (o 1º vira principal e
 *    os demais, separados por ; , ou espaço, entram nas cópias vazias).
 *  - E-mail principal preenchido: mantém e apenas INFORMA se o GATE tem e-mail diferente.
 *  - Nome: apenas INFORMA se o nome do GATE difere do cadastro local (não altera).
 *
 * Entrada (JSON): { "escopo": "sem_email" | "todos", "apos_id": 0 }
 * Trabalha em partes de ~40 s (limite de 60 s do balanceador); o navegador repete até "concluido".
 */

declare(strict_types=1);

require_once __DIR__ . '/../auth/validar_jwt.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/Services/FornecedorResolver.php';

use App\Services\FornecedorResolver;

header('Content-Type: application/json');

validarJWTCompleto();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

rateLimit(30, 'sincronizar_gate');
set_time_limit(90);

$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$escopo = ($input['escopo'] ?? 'sem_email') === 'todos' ? 'todos' : 'sem_email';
$aposId = max(0, (int) ($input['apos_id'] ?? 0));
$orcamento = 40.0;
$inicio = microtime(true);

$pdo = getConnection();

$filtro = $escopo === 'sem_email' ? "AND (email_for IS NULL OR TRIM(email_for) = '')" : '';
$stmt = $pdo->prepare("SELECT * FROM fornecedores WHERE ativo = 1 AND id > :apos {$filtro} ORDER BY id ASC LIMIT 1000");
$stmt->execute(['apos' => $aposId]);
$fornecedores = $stmt->fetchAll();

$res = [
    'completados'      => [],
    'gate_sem_email'   => [],
    'email_invalido'   => [],
    'nao_encontrados'  => [],
    'divergencias'     => [],
    'nomes_diferentes' => [],
];
$verificados = 0;
$ultimoId = $aposId;
$gateIndisponivel = false;
$interrompido = false;

foreach ($fornecedores as $f) {
    if ($verificados > 0 && (microtime(true) - $inicio) > $orcamento) {
        $interrompido = true;
        break;
    }

    $r = FornecedorResolver::sincronizarEmails($f, $escopo === 'todos');

    if ($r['estado'] === 'gate_indisponivel') {
        $gateIndisponivel = true;
        break;
    }

    $verificados++;
    $ultimoId = (int) $f['id'];
    $base = ['cod_for' => $f['cod_for'], 'fornecedor' => trim((string) $f['fan_for'])];

    switch ($r['estado']) {
        case 'completado':
            $res['completados'][] = $base + ['detalhe' => $r['mensagem']];
            break;
        case 'gate_sem_email':
            $res['gate_sem_email'][] = $base;
            break;
        case 'email_invalido':
            $res['email_invalido'][] = $base + ['detalhe' => $r['mensagem']];
            break;
        case 'nao_encontrado':
            $res['nao_encontrados'][] = $base;
            break;
    }

    foreach ($r['divergencias'] as $d) {
        $res['divergencias'][] = $base + ['local' => $d['local'], 'gate' => $d['gate']];
    }

    $fanLocal = trim((string) $f['fan_for']);
    if ($r['gate_nome'] !== '' && mb_strtoupper($r['gate_nome']) !== mb_strtoupper($fanLocal)) {
        $res['nomes_diferentes'][] = $base + ['nome_gate' => $r['gate_nome']];
    }
}

$concluido = !$gateIndisponivel && !$interrompido && count($fornecedores) < 1000;

echo json_encode([
    'success'           => true,
    'escopo'            => $escopo,
    'verificados'       => $verificados,
    'ultimo_id'         => $ultimoId,
    'concluido'         => $concluido,
    'gate_indisponivel' => $gateIndisponivel,
] + $res, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
