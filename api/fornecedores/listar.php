<?php
/**
 * api/fornecedores/listar.php
 * GET protegido - lista fornecedores com paginação e busca.
 */

declare(strict_types=1);

require_once __DIR__ . '/../auth/validar_jwt.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/Services/BoletoReader.php';

use App\Services\BoletoReader;

header('Content-Type: application/json');

validarJWT();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$pagina   = isset($_GET['pagina']) ? max(1, (int) $_GET['pagina']) : 1;
$porPagina = isset($_GET['por_pagina']) ? min(100, max(1, (int) $_GET['por_pagina'])) : 20;
$offset   = ($pagina - 1) * $porPagina;
$busca    = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$apenasAtivos = isset($_GET['apenas_ativos']) ? (bool) $_GET['apenas_ativos'] : false;

// Ordenação (indexação) por colunas seguras (whitelist) para evitar SQL injection.
$camposOrdenaveis = [
    'cod_for'   => 'f.cod_for',
    'fan_for'   => 'f.fan_for',
    'raz_for'   => 'f.raz_for',
    'email_for' => 'f.email_for',
];
$ordem   = (isset($_GET['ordem']) && array_key_exists($_GET['ordem'], $camposOrdenaveis)) ? $_GET['ordem'] : 'fan_for';
$direcao = (isset($_GET['direcao']) && strtoupper($_GET['direcao']) === 'DESC') ? 'DESC' : 'ASC';
$orderBy = $camposOrdenaveis[$ordem] . ' ' . $direcao;

// Busca direta por id ((usada pelo botão Editar)
if (isset($_GET['id']) && (int) $_GET['id'] > 0) {
    $stmt = getConnection()->prepare(
        "SELECT id, cod_for, fan_for, raz_for, dir_bol, email_for,
                email_for_2, email_for_3, email_for_4, email_for_5, email_for_6,
                ativo, created_at, updated_at
         FROM fornecedores WHERE id = :id LIMIT 1"
    );
    $stmt->execute(['id' => (int) $_GET['id']]);
    $fornecedor = $stmt->fetch();

    if (!$fornecedor) {
        http_response_code(404);
        echo json_encode(['error' => 'Fornecedor não encontrado']);
        exit;
    }

    echo json_encode(['data' => $fornecedor]);
    exit;
}

// Busca por código de fornecededor ((usada no auto-preenchimento do modal:
// prioriza o MySQL local; se não existir, consulta o GATE (wgfor) e devolve
// os dados sem cadastrar.

if (isset($_GET['cod_for'])) {
    $codFor = preg_replace('/\D/', '', $_GET['cod_for']);
    if (strlen($codFor) !== 10) {
        http_response_code(400);
        echo json_encode(['error' => 'Código inválido']);
        exit;
    }

    $stmt = getConnection()->prepare("SELECT id, cod_for, fan_for, raz_for, email_for, ativo FROM fornecedores WHERE cod_for = :c LIMIT 1");
    $stmt->execute(['c' => $codFor]);
    $local = $stmt->fetch();

    if ($local) {
        echo json_encode(['data' => $local, 'origem' => 'local']);
        exit;
    }

    require_once __DIR__ . '/../../config/mssql.php';

    $dados = mssqlBuscarFornecedor($codFor);
    if ($dados) {
        echo json_encode(['data' => $dados, 'origem' => 'gate']);
        exit;
    }

    http_response_code(404);
    echo json_encode(['error' => 'Código não encontrado no repositório GATE']);
    exit;
;
}

$pdo = getConnection();

$where = [];
$params = [];

if ($busca !== '') {
    $where[] = "(f.cod_for LIKE :b1 OR f.fan_for LIKE :b2 OR f.raz_for LIKE :b3 OR f.email_for LIKE :b4)";
    $params['b1'] = '%' . $busca . '%';
    $params['b2'] = $params['b1'];
    $params['b3'] = $params['b1'];
    $params['b4'] = $params['b1'];
}

if ($apenasAtivos) {
    $where[] = 'f.ativo = 1';
}

$whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

// Total
$stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM fornecedores f {$whereClause}");
$stmt->execute($params);
$total = (int) $stmt->fetch()['total'];
$totalPaginas = max(1, (int) ceil($total / $porPagina));

// Dados
$sql = "SELECT f.id, f.cod_for, f.fan_for, f.raz_for, f.dir_bol, f.email_for,
               f.email_for_2, f.email_for_3, f.email_for_4, f.email_for_5, f.email_for_6,
               f.ativo, f.created_at, f.updated_at
        FROM fornecedores f
        {$whereClause}
        ORDER BY {$orderBy}
        LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $porPagina, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

$dados = $stmt->fetchAll();

// Contar boletos pendentes e já enviados por fornecedor (padrão da pasta do cliente)
foreach ($dados as &$f) {
    $pasta = BoletoReader::obterPastaCliente($f);
    $f['boletos_pendentes'] = count(BoletoReader::listarArquivosDaPasta($pasta));

    $pastEnviado = $pasta . '/enviado';
    if (is_dir($pastEnviado)) {
        $f['boletos_enviados'] = count(BoletoReader::listarArquivosDaPasta($pastEnviado));
    } else {
        $f['boletos_enviados'] = 0;
    }
}
unset($f);

echo json_encode([
    'data'           => $dados,
    'pagina'         => $pagina,
    'por_pagina'     => $porPagina,
    'total_paginas'  => $totalPaginas,
    'total_registros'=> $total,
]);