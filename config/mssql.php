<?php
/**
 * config/mssql.php
 * Conexão PDO singleton com o repositório corporativo "GATE" (MSSQL - somente leitura).
 *
 * Suporta vários drivers:
 *   - 'sqlsrv'        -> requer extensão pdo_sqlsrv
 *   - 'dblib'        -> requer pdo_dblib (FreeTDS) — comum em hospedagens Linux
 *   - 'ODBC Driver..'-> requer pdo_odbc + driver ODBC instalado
 *
 * Se o driver configurado no .env (MSSQL_DRIVER) não estiver disponível no
 * servidor, tenta automaticamente outros drivers PDO MSSQL. Isso torna o
 * sistema resiliente à configuração da hospedagem.

 * As credenciais reais devem vir do .env (MSSQL_HOST, MSSQL_DB, MSSQL_USER, MSSQL_PASS).
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Retorna a conexão PDO singleton com o MSSQL.
 */
function getMssqlConnection(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        if (MSSQL_HOST === '' || MSSQL_DB === '') {
            throw new RuntimeException('Credenciais MSSQL não configuradas no .env');
        }

        $disponiveis = PDO::getAvailableDrivers();
        $candidatos  = [];
        $configurado = trim((string) MSSQL_DRIVER);
        if ($configurado !== '') {
            $candidatos[] = $configurado;
        }

        // Fallbacks (driver nativo primeiro, depois dblib/FreeTDS — comum e leve
        // em hospedagens Linux — e só então os ODBC de SQL Server).
        foreach (['sqlsrv', 'dblib', 'ODBC Driver 18 for SQL Server', 'ODBC Driver 17 for SQL Server', 'ODBC Driver 13 for SQL Server', 'SQL Server'] as $alt) {
            if (!in_array($alt, $candidatos, true)) {
                $candidatos[] = $alt;
            }
        }

        $tentativas = [];

        foreach ($candidatos as $driver) {
            $nomeDriver = $driver;
            $tipo       = strtolower($driver) === 'sqlsrv' ? 'sqlsrv' : (strtolower($driver) === 'dblib' ? 'dblib' : 'odbc');

            // Para sqlsrv, o driver precisa estar listado em PDO::getAvailableDrivers().

            if ($tipo === 'sqlsrv' && !in_array('sqlsrv', $disponiveis, true)) {
                continue;
            }
            if ($tipo === 'dblib' && !in_array('dblib', $disponiveis, true)) {
                continue;
            }
            if ($tipo === 'odbc' && !in_array('odbc', $disponiveis, true)) {
                continue;
            }

            try {
                $pdo = abrirConexaoMssql($driver);
                return $pdo;

            } catch (Throwable $e) {
                $tentativas[] = "{$nomeDriver}: " . $e->getMessage();
            }
        }

        $msg = 'Falha na conexión MSSQL (GATE).';
        if ($tentativas) {
            $msg .= ' Tentativas: ' . implode('; ', $tentativas);
        }
        $msg .= ' Drivers PDO disponíveis: ' . implode(', ', $disponiveis);
        throw new RuntimeException($msg);
    }

    return $pdo;
}

/**
 * Abre a conexão PDO MSSQL com um driver específico.
 *
 * @param string $driver 'sqlsrv' | 'dblib' | nome ODBC (ex.: "ODBC Driver 17 for SQL Server").
 */
function abrirConexaoMssql(string $driver): PDO
{
    $driverLower = strtolower($driver);

    if ($driverLower === 'sqlsrv') {
        $dsn = sprintf(
            'sqlsrv:Server=%s,%d;Database=%s;Encrypt=0;TrustServerCertificate=1',
            MSSQL_HOST,
            MSSQL_PORT,
            MSSQL_DB
        );
    } elseif ($driverLower === 'dblib') {
        // FreeTDS (pdo_dblib) — comum em hospedagens Linux compartilhadas
        $dsn = sprintf(
            'dblib:host=%s;dbname=%s;charset=UTF-8',
            MSSQL_HOST . ',' . MSSQL_PORT,
            MSSQL_DB
        );
    } else {
        $dsn = sprintf(
            'odbc:Driver={%s};Server=%s,%d;Database=%s;TrustServerCertificate=yes',
            $driver,
            MSSQL_HOST,
            MSSQL_PORT,
            MSSQL_DB
        );
    }

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    // pdo_sqlsrv (driver nativo da Microsoft) NÃO aceita PDO::ATTR_TIMEOUT
    // ("An unsupported attribute was designated on the PDO object") — aplica
    // o timeout apenas em dblib/ODBC, que o suportam.
    if ($driverLower !== 'sqlsrv') {
        $options[PDO::ATTR_TIMEOUT] = 10;
    }

    try {
        return new PDO($dsn, MSSQL_USER, MSSQL_PASS, $options);
    } catch (PDOException $e) {
        throw new RuntimeException('driver ' . $driver . ': ' . $e->getMessage());
    }
}

/**
 * Verifica se um código de fornecedor existe na tabela de vendas do repositório
 * corporativo (wg_ven). Usado para validar o cadastro de fornecedores.
 *
 * Retorna:
 *   - bool true  -> código encontrado no GATE
 *   - bool false -> código NÃO existe no GATE
 *   - null       -> GATE indisponível/falha na consulta (não é possível afirmar)
 */
function mssqlCodForExiste(string $codFor): ?bool
{
    $tabela = MSSQL_TABELA_VENDA;

    try {
        // CAST(... AS VARCHAR): as colunas do GATE são VARCHAR; o padrão do driver (parâmetro
        // NVARCHAR) faz o SQL Server converter a COLUNA e varrer a tabela inteira (wg_ven tem
        // milhões de linhas). Mesmo resultado, só que usando o índice. (Visto no projeto Furk.)
        $sql = "SELECT TOP 1 COD_FOR FROM {$tabela} WHERE COD_FOR = CAST(:cod_for AS VARCHAR(50))";
        $stmt = getMssqlConnection()->prepare($sql);
        $stmt->execute(['cod_for' => $codFor]);
        return $stmt->fetch() !== false;
    } catch (\Throwable $e) {
        error_log('[mssql] Validação wg_ven falhou: ' . $e->getMessage());
        // GATE indisponível (não dá para afirmar que o código não existe).
        return null;
    }
}

/**
 * Versão bool da validação: retorna false para "não existe" E para
 * "GATE indisponível" (usada onde a distinção não é necessária).
 */
function mssqlCodForExisteBool(string $codFor): bool
{
    return mssqlCodForExiste($codFor) === true;
}

/**
 * Consulta um fornecedor no MSSQL pela tabela wgfor (repositório corporativo).
 * Retorna os dados mapeados ou null caso não encontre.
 */
function mssqlBuscarFornecedor(string $codFor): ?array
{
    $tabela = MSSQL_TABELA;

    $sql = "SELECT TOP 1
                COD_FOR,
                FAN_FOR,
                RAZ_FOR,
                EMAIL_FOR,
                ATIVO
            FROM {$tabela}
            WHERE COD_FOR = CAST(:cod_for AS VARCHAR(50))";

    $stmt = getMssqlConnection()->prepare($sql);
    $stmt->execute(['cod_for' => $codFor]);
    $row = $stmt->fetch();

    if ($row === false) {
        return null;
    }

    return [
        'cod_for'      => trim($row['COD_FOR'] ?? ''),
        'fan_for'      => trim($row['FAN_FOR'] ?? ''),
        'raz_for'      => trim($row['RAZ_FOR'] ?? ''),
        'email_for'    => strtolower(trim($row['EMAIL_FOR'] ?? '')),
        // 1 = ativo no GATE, 0 = inativo, null = não informado. SÓ INFORMATIVO (nunca bloqueia envio).
        'ativo_gate'   => isset($row['ATIVO']) && $row['ATIVO'] !== ''
            ? ((is_numeric($row['ATIVO']) ? (int) $row['ATIVO'] : (in_array(strtoupper(trim((string) $row['ATIVO'])), ['N', 'NAO', 'NÃO', 'F', 'FALSE'], true) ? 0 : 1)) === 0 ? 0 : 1)
            : null,
        'email_for_2'  => '',
        'email_for_3'  => '',
        'email_for_4'  => '',
        'email_for_5'  => '',
        'email_for_6'  => '',
    ];
}