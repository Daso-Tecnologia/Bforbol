<?php
/**
 * api/usuarios/salvar.php
 * POST protegido - cria ou edita usuário, conforme o nível de acesso:
 *   - nível 'completo' (admin): inclui, edita e altera qualquer usuário.
 *   - nível 'read': edita APENAS o próprio registro e somente nome e senha
 *     (não pode alterar nível, ativo nem o e-mail/login).
 */

declare(strict_types=1);

require_once __DIR__ . '/../auth/validar_jwt.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

$usuarioLogado = validarJWT();
$ehAdmin = $usuarioLogado['nivel'] === 'completo';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

if (empty($input['nome'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Nome é obrigatório']);
    exit;
}

$nome = trim($input['nome']);
$pdo = getConnection();

// ---------- Criação (novo registro) ----------
if (empty($input['id'])) {
    // Somente administrador pode incluir novos usuários
    if (!$ehAdmin) {
        http_response_code(403);
        echo json_encode(['error' => 'Acesso negado. Apenas administradores podem incluir usuários.']);
        exit;
    }

    if (empty($input['email'])) {
        http_response_code(400);
        echo json_encode(['error' => 'E-mail é obrigatório']);
        exit;
    }

    $email = mb_strtolower(trim($input['email']));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'E-mail inválido']);
        exit;
    }

    $nivel = isset($input['nivel']) ? $input['nivel'] : 'read';
    if (!in_array($nivel, ['completo', 'read'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Nível inválido']);
        exit;
    }

    $ativo = isset($input['ativo']) ? (int) (bool) $input['ativo'] : 1;

    if (empty($input['senha'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Senha é obrigatória para novo usuário']);
        exit;
    }

    if (strlen($input['senha']) < 6) {
        http_response_code(400);
        echo json_encode(['error' => 'Senha deve ter no mínimo 6 caracteres']);
        exit;
    }

    // Verificar duplicidade de e-mail
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = :email");
    $stmt->execute(['email' => $email]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'Já existe um usuário com este e-mail']);
        exit;
    }

    $hash = password_hash($input['senha'], PASSWORD_BCRYPT);

    $stmt = $pdo->prepare("INSERT INTO usuarios (nome, email, senha, nivel, ativo) VALUES (:nome, :email, :senha, :nivel, :ativo)");
    $stmt->execute([
        'nome'  => $nome,
        'email' => $email,
        'senha' => $hash,
        'nivel' => $nivel,
        'ativo' => $ativo,
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Usuário criado com sucesso',
        'id'      => dbLastId(),
    ]);
    exit;
}

// ---------- Edição ----------
$id = (int) $input['id'];

// Verificar se o registro existe
$stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = :id");
$stmt->execute(['id' => $id]);
$alvo = $stmt->fetch();
if (!$alvo) {
    http_response_code(404);
    echo json_encode(['error' => 'Usuário não encontrado']);
    exit;
}

if (!$ehAdmin) {
    // Usuário 'read' só pode editar o próprio registro
    if ($id !== (int) $usuarioLogado['id']) {
        http_response_code(403);
        echo json_encode(['error' => 'Acesso negado. Você só pode editar o próprio usuário.']);
        exit;
    }

    // Usuário 'read' não altera nível, ativo nem e-mail; mantém os valores atuais
    $email = $alvo['email'];
    $nivel = $alvo['nivel'];
    $ativo = $alvo['ativo'];
} else {
    // Admin: pode alterar e-mail, nível e ativo
    if (empty($input['email'])) {
        http_response_code(400);
        echo json_encode(['error' => 'E-mail é obrigatório']);
        exit;
    }

    $email = mb_strtolower(trim($input['email']));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'E-mail inválido']);
        exit;
    }

    $nivel = isset($input['nivel']) ? $input['nivel'] : $alvo['nivel'];
    if (!in_array($nivel, ['completo', 'read'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Nível inválido']);
        exit;
    }

    $ativo = isset($input['ativo']) ? (int) (bool) $input['ativo'] : (int) $alvo['ativo'];

    // Verificar duplicidade de e-mail
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = :email AND id != :id");
    $stmt->execute(['email' => $email, 'id' => $id]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'Já existe um usuário com este e-mail']);
        exit;
    }

    // Impedir rebaixar/desativar o próprio usuário logado
    if ($id === (int) $usuarioLogado['id']) {
        if ($nivel !== 'completo' || (int) $ativo !== 1) {
            http_response_code(403);
            echo json_encode(['error' => 'Você não pode rebaixar o próprio nível nem desativar a própria conta']);
            exit;
        }
    }
}

$sql = "UPDATE usuarios SET nome = :nome, email = :email, nivel = :nivel, ativo = :ativo";
$params = ['nome' => $nome, 'email' => $email, 'nivel' => $nivel, 'ativo' => $ativo];

if (!empty($input['senha'])) {
    if (strlen($input['senha']) < 6) {
        http_response_code(400);
        echo json_encode(['error' => 'Senha deve ter no mínimo 6 caracteres']);
        exit;
    }
    $sql .= ", senha = :senha";
    $params['senha'] = password_hash($input['senha'], PASSWORD_BCRYPT);
}

$sql .= " WHERE id = :id";
$params['id'] = $id;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

echo json_encode(['success' => true, 'message' => 'Usuário atualizado com sucesso']);
