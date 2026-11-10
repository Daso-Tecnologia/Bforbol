<?php
/**
 * config/database.php
 * Conexão PDO singleton com o MySQL (fornecedores + logs).
 * Padrão idêntico ao usado no projeto financeiro (adaptável a ScriptCase).
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Retorna a conexão PDO singleton com o MySQL.
 */
function getConnection(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, 'Erro de conexão com o banco: ' . $e->getMessage() . PHP_EOL);
                exit(1);
            }
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Erro de conexão com o banco de dados']);
            exit;
        }
    }

    return $pdo;
}

/**
 * Roda uma consulta parametrizada e retorna todos os registros.
 */
function dbQuery(string $sql, array $params = []): array
{
    $stmt = getConnection()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Roda uma consulta e retorna apenas o primeiro registro (ou null).
 */
function dbRow(string $sql, array $params = []): ?array
{
    $stmt = getConnection()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/**
 * Roda um INSERT/UPDATE/DELETE e retorna o número de linhas afetadas.
 */
function dbExecute(string $sql, array $params = []): int
{
    $stmt = getConnection()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/**
 * Retorna o ID do último INSERT.
 */
function dbLastId(): int
{
    return (int) getConnection()->lastInsertId();
}

/**
 * Busca um valor da tabela de configuração.
 */
function configGet(string $chave, mixed $default = null): mixed
{
    $row = dbRow('SELECT valor FROM configuracao WHERE chave = :chave', ['chave' => $chave]);
    return $row ? $row['valor'] : $default;
}

/**
 * Grava/atualiza um valor na tabela de configuração.
 */
function configSet(string $chave, mixed $valor): void
{
    dbExecute(
        'INSERT INTO configuracao (chave, valor) VALUES (:chave, :valor)
         ON DUPLICATE KEY UPDATE valor = VALUES(valor)',
        ['chave' => $chave, 'valor' => (string) $valor]
    );
}