<?php
/**
 * api/envios/listar.php
 * GET protegido - lista envios com filtros (estado, período, busca) e paginação.
 */

declare(strict_types=1);

require_once __DIR__ . '/../auth/validar_jwt.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

validarJWT();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$pagina    = isset($_GET['pagina']) ? max(1, (int) $_GET['pagina']) : 1;
$porPagina = isset($_GET['por_pagina']) ? min(100, max(1, (int) $_GET['por_pagina'])) : 20;
$offset    = ($pagina - 1) * $porPagina;

$estado      = $_GET['estado'] ?? '';
$dataInicial = $_GET['data_inicial'] ?? '';
$dataFinal   = $_GET['data_final'] ?? '';
$busca       = trim($_GET['busca'] ?? '');

$where = [];
$params = [];

if ($estado !== '' && in_array($estado, ['pendente', 'enviado', 'erro'], true)) {
    $where[] = 'e.estado = :estado';
    $params['estado'] = $estado;
}

if ($dataInicial !== '') {
    $where[] = 'e.data_tentativa >= :data_inicial';
    $params['data_inicial'] = $dataInicial . ' 00:00:00';
}

if ($dataFinal !== '') {
    $where[] = 'e.data_tentativa <= :data_final';
    $params['data_final'] = $dataFinal . ' 23:59:59';
}

if ($busca !== '') {
    $where[] = "(e.cod_for LIKE :b1 OR e.fan_for LIKE :b2 OR e.destino LIKE :b3 OR e.assunto LIKE :b4)";
    $params['b1'] = '%' . $busca . '%';
    $params['b2'] = $params['b1'];
    $params['b3'] = $params['b1'];
    $params['b4'] = $params['b1'];
}

$whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

$pdo = getConnection();

// Total
$stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM envios_log e {$whereClause}");
$stmt->execute($params);
$total = (int) $stmt->fetch()['total'];
$totalPaginas = max(1, (int) ceil($total / $porPagina));

// Dados
$sql = "SELECT e.*, f.fan_for AS fornecedor_fan, f.email_for AS email_actual
        FROM envios_log e
        LEFT JOIN fornecedores f ON e.fornecedor_id = f.id
        {$whereClause}
        ORDER BY e.id DESC
        LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $porPagina, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

// Reflejar em tempo real o e-mail atual do fornecedor (si foi modificado depois
// de criar o lote). O envio real já usa o e-mail atual; aqui corrigimos a lista.
$rows = $stmt->fetchAll();
foreach ($rows as &$r) {
    $emailActual = trim((string) ($r['email_actual'] ?? ''));
    if ($emailActual !== '') {
        $r['destino'] = $emailActual;
    }
    unset($r['email_actual']);
}
unset($r);

// Contadores resumidos
$resumo = $pdo->query(
    "SELECT estado, COUNT(*) AS total FROM envios_log GROUP BY estado"
)->fetchAll();
$contadores = ['pendente' => 0, 'enviado' => 0, 'erro' => 0];
foreach ($resumo as $r) {
    $contadores[$r['estado']] = (int) $r['total'];
}

echo json_encode([
    'data'           => $rows,
    'pagina'         => $pagina,
    'por_pagina'     => $porPagina,
    'total_paginas'  => $totalPaginas,
    'total_registros'=> $total,
    'resumo'         => $contadores,
]);