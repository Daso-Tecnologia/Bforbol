<?php
/**
 * app/Services/RelatorioPendencias.php
 * Relatório dos arquivos que estão na pasta de entrada (storage/enviar) aguardando
 * processamento, explicando com mensagens explícitas o que ocorre com cada um e o que
 * o usuário precisa fazer. SOMENTE LEITURA: não cadastra, não atualiza e não move nada.
 *
 * A classificação espelha a ordem de decisão de BoletoReader::processarEntrada().
 */

declare(strict_types=1);

namespace App\Services;

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/mssql.php';
require_once __DIR__ . '/BoletoReader.php';
require_once __DIR__ . '/FornecedorResolver.php';
require_once __DIR__ . '/RelatorioGenerator.php';

class RelatorioPendencias
{
    /** situação => [rótulo, gravidade, ordem] */
    public const SITUACOES = [
        'sem_codigo'        => ['Sem código no nome', 'erro', 1],
        'gate_indisponivel' => ['GATE indisponível', 'erro', 2],
        'sem_fornecedor'    => ['Fornecedor não cadastrado', 'erro', 3],
        'sem_email'         => ['Sem e-mail principal', 'erro', 4],
        'inativo'           => ['Fornecedor inativo', 'erro', 5],
        'acima_limite'      => ['Acima do limite de tamanho', 'erro', 6],
        'nao_verificado'    => ['Não verificado (tempo)', 'aviso', 7],
        'duplicado'         => ['Já processado antes', 'aviso', 8],
        'pronto'            => ['Pronto para ler', 'ok', 9],
    ];

    /**
     * Analisa a pasta de entrada e devolve ['linhas' => [...], 'resumo' => [...], ...].
     * Cada chamada ao GATE leva ~0,3 s; $orcamentoSegundos evita estourar o limite de 60 s.
     */
    public static function analisar(float $orcamentoSegundos = 40.0, ?\PDO $pdo = null): array
    {
        $inicio = microtime(true);
        $arquivos = BoletoReader::listarArquivosNaEntrada();
        $pdo ??= getConnection();

        $cacheGate = [];
        $cacheLocal = [];
        $totalBytes = [];

        // 1ª passada: código de cada arquivo e tamanho total por fornecedor.
        $codigos = [];
        foreach ($arquivos as $arq) {
            $cod = BoletoReader::extrairCodFor($arq);
            $codigos[$arq] = $cod;
            if ($cod) {
                $caminho = UPLOAD_PATH . '/' . $arq;
                $totalBytes[$cod] = ($totalBytes[$cod] ?? 0) + (is_file($caminho) ? (int) filesize($caminho) : 0);
            }
        }

        $linhas = [];
        foreach ($arquivos as $arq) {
            $cod = $codigos[$arq];
            $nomeExibido = preg_replace('/^\d{14}_/', '', $arq);

            if (!$cod) {
                $linhas[] = self::linha($arq, $nomeExibido, '', '', '', 'sem_codigo',
                    'O nome do arquivo não contém o código do fornecedor (10 dígitos) no padrão esperado, por isso o sistema não sabe para quem enviar.',
                    'Renomear o arquivo no padrão FCOMP-<código de 10 dígitos>-... e enviá-lo novamente.');
                continue;
            }

            if (!array_key_exists($cod, $cacheGate)) {
                if ((microtime(true) - $inicio) > $orcamentoSegundos) {
                    $linhas[] = self::linha($arq, $nomeExibido, $cod, '', '', 'nao_verificado',
                        'A verificação deste código no GATE não coube no tempo limite desta consulta.',
                        'Gerar o relatório novamente; os códigos já verificados serão refeitos e os restantes serão examinados.');
                    continue;
                }
                $cacheGate[$cod] = self::consultarGate($cod);
            }
            $gate = $cacheGate[$cod];

            if (!isset($cacheLocal[$cod])) {
                $st = $pdo->prepare('SELECT id, cod_for, fan_for, email_for, ativo FROM fornecedores WHERE cod_for = :c LIMIT 1');
                $st->execute(['c' => $cod]);
                $cacheLocal[$cod] = $st->fetch() ?: null;
            }
            $local = $cacheLocal[$cod];

            $fanLocal = $local ? trim((string) $local['fan_for']) : '';
            $fanGate = is_array($gate) ? trim((string) $gate['fan_for']) : '';
            $fornecedor = $fanLocal !== '' ? $fanLocal : $fanGate;
            $emailGateBruto = is_array($gate) ? trim((string) $gate['email_for']) : '';
            $emailsGate = FornecedorResolver::extrairEmails($emailGateBruto);
            $emailGate = $emailsGate[0] ?? '';
            $emailLocal = $local ? trim((string) $local['email_for']) : '';

            $nota = '';
            if ($local && $fanGate !== '' && mb_strtoupper($fanGate) !== mb_strtoupper($fanLocal)) {
                $nota = " Atenção: no GATE o nome atual é \"{$fanGate}\" (o cadastro local está com \"{$fanLocal}\").";
            }
            if ($local && $emailLocal !== '' && $emailsGate !== [] && !in_array(mb_strtolower($emailLocal), $emailsGate, true)) {
                $nota .= ' Atenção: o e-mail no GATE (' . implode(', ', $emailsGate) . ") é diferente do cadastrado ({$emailLocal}); o cadastro local foi mantido.";
            }

            if (is_array($gate) && ($gate['ativo_gate'] ?? null) === 0) {
                $nota .= ' Aviso: o fornecedor está INATIVO no GATE; isso não impede o envio (só o cadastro local manda).';
            }

            // O upload prova que a cobrança existe: a wg_ven NÃO é consultada. O fornecedor vem
            // do cadastro local ou, se não existir, do cadastro oficial do GATE (wgfor).

            // 1) Fornecedor desconhecido
            if (!$local) {
                if ($gate === null) {
                    $linhas[] = self::linha($arq, $nomeExibido, $cod, '', '', 'gate_indisponivel',
                        "O fornecedor {$cod} ainda não está cadastrado localmente e não foi possível consultar o GATE (MSSQL) agora para cadastrá-lo.",
                        'Verificar a rede/VPN do servidor com a TI e executar "Ler boletos" de novo. O arquivo continua guardado.');
                    continue;
                }
                if ($gate === false) {
                    $linhas[] = self::linha($arq, $nomeExibido, $cod, '', '', 'sem_fornecedor',
                        "O código {$cod} não existe no cadastro local de fornecedores nem no cadastro do GATE (wgfor), então o sistema não sabe o nome nem o e-mail do destinatário.",
                        'Conferir o código no nome do arquivo. Se estiver correto, cadastrar o fornecedor manualmente na tela Fornecedores e executar "Ler boletos". Não é preciso subir o arquivo de novo.');
                    continue;
                }
            }

            // 2) Inativo
            if ($local && (int) $local['ativo'] !== 1) {
                $linhas[] = self::linha($arq, $nomeExibido, $cod, $fornecedor, $emailLocal, 'inativo',
                    "O fornecedor \"{$fanLocal}\" ({$cod}) está INATIVO no cadastro. Boletos só são enviados para fornecedores ativos." . $nota,
                    'Se ele deve voltar a receber boletos, reativar na tela Fornecedores e executar "Ler boletos". Caso contrário, o arquivo deve ser descartado.');
                continue;
            }

            // 3) Sem e-mail principal (considera o que o "Ler boletos" completaria do GATE)
            $emailEfetivo = $emailLocal !== '' ? $emailLocal : $emailGate;
            if ($emailEfetivo === '' && $gate === null) {
                $linhas[] = self::linha($arq, $nomeExibido, $cod, $fornecedor, '', 'gate_indisponivel',
                    "O fornecedor \"{$fornecedor}\" ({$cod}) está sem e-mail principal e não foi possível consultar o GATE (MSSQL) agora para completá-lo.",
                    'Verificar a rede/VPN do servidor com a TI e executar "Ler boletos" de novo, ou cadastrar o e-mail manualmente na tela Fornecedores.');
                continue;
            }
            if ($emailEfetivo === '') {
                if ($emailGateBruto === '') {
                    $motivoGate = 'o cadastro do GATE (wgfor) também está sem e-mail';
                } else {
                    $motivoGate = "o e-mail do GATE (\"{$emailGateBruto}\") não é um endereço válido";
                }
                $situacaoCad = $local ? 'não tem e-mail principal cadastrado' : 'ainda não está cadastrado localmente e será criado a partir do GATE, porém sem e-mail';
                $linhas[] = self::linha($arq, $nomeExibido, $cod, $fornecedor, '', 'sem_email',
                    "O fornecedor \"{$fornecedor}\" ({$cod}) {$situacaoCad}, e {$motivoGate}. Sem e-mail principal o sistema não cria a fila de envio." . $nota,
                    'Cadastrar o e-mail na tela Fornecedores (ou pedir que o GATE seja preenchido) e executar "Ler boletos". Não é preciso subir o arquivo de novo.');
                continue;
            }

            // 6) Limite de tamanho
            $limiteBytes = (int) ENVIO_LIMITE_TOTAL_MB * 1024 * 1024;
            if (($totalBytes[$cod] ?? 0) > $limiteBytes) {
                $mb = round($totalBytes[$cod] / 1048576, 2);
                $linhas[] = self::linha($arq, $nomeExibido, $cod, $fornecedor, $emailEfetivo, 'acima_limite',
                    "Os arquivos deste fornecedor somam {$mb} MB e o limite por e-mail é " . (int) ENVIO_LIMITE_TOTAL_MB . ' MB. O envio não é criado.' . $nota,
                    'Dividir os documentos em envios menores ou ajustar o limite (ENVIO_LIMITE_TOTAL_MB).');
                continue;
            }

            // 7) Já existe na pasta do fornecedor
            if ($local) {
                $pasta = BoletoReader::obterPastaCliente($local);
                if (is_file($pasta . '/' . $nomeExibido) || is_file($pasta . '/enviado/' . $nomeExibido)) {
                    $linhas[] = self::linha($arq, $nomeExibido, $cod, $fornecedor, $emailEfetivo, 'duplicado',
                        'Este arquivo já existe na pasta do fornecedor (já foi processado ou enviado antes). Será ignorado e removido da entrada para evitar cobrança duplicada.' . $nota,
                        'Nenhuma ação necessária.');
                    continue;
                }
            }

            // 8) Pronto
            $obs = [];
            if (!$local) {
                $obs[] = 'o fornecedor será cadastrado automaticamente a partir do GATE';
            }
            if ($local && $emailLocal === '' && $emailGate !== '') {
                $obs[] = 'o e-mail será completado a partir do GATE';
            }
            if (count($emailsGate) > 1 && $emailLocal === '') {
                $obs[] = 'o GATE tem ' . count($emailsGate) . ' e-mails (' . implode(', ', $emailsGate) . '); o primeiro será o principal e os demais ficarão como cópia';
            }
            $extra = $obs ? ' Observação: ' . implode('; ', $obs) . '.' : '';
            $linhas[] = self::linha($arq, $nomeExibido, $cod, $fornecedor, $emailEfetivo, 'pronto',
                "Fornecedor ativo, com e-mail ({$emailEfetivo}). Será incluído na fila de envio ao executar \"Ler boletos\"." . $extra . $nota,
                'Executar "Ler boletos".');
        }

        usort($linhas, static function (array $a, array $b): int {
            return [$a['ordem'], $a['cod_for'], $a['arquivo']] <=> [$b['ordem'], $b['cod_for'], $b['arquivo']];
        });

        return [
            'gerado_em'    => date('d/m/Y H:i:s'),
            'total'        => count($linhas),
            'resumo'       => self::resumir($linhas),
            'linhas'       => $linhas,
        ];
    }

    /**
     * Consulta somente leitura ao cadastro oficial do GATE (wgfor). A wg_ven não é consultada.
     * Retorna: array (dados) | false (código não existe na wgfor) | null (GATE indisponível).
     */
    private static function consultarGate(string $cod): array|false|null
    {
        try {
            $dados = mssqlBuscarFornecedor($cod);
        } catch (\Throwable $e) {
            error_log('[RelatorioPendencias] wgfor falhou: ' . get_class($e));
            return null;
        }
        if (!$dados || trim((string) ($dados['fan_for'] ?? '')) === '') {
            return false;
        }
        return array_map(static fn ($v) => is_string($v) ? corregirMojibakeCP437($v) : $v, $dados);
    }

    private static function linha(string $arqEntrada, string $arquivo, string $cod, string $fornecedor, string $email, string $situacao, string $ocorrencia, string $acao): array
    {
        [$rotulo, $gravidade, $ordem] = self::SITUACOES[$situacao];
        return [
            'arquivo_entrada' => $arqEntrada,
            'arquivo'         => $arquivo,
            'cod_for'         => $cod,
            'fornecedor'      => $fornecedor,
            'email'           => $email,
            'situacao'        => $situacao,
            'situacao_rotulo' => $rotulo,
            'gravidade'       => $gravidade,
            'ordem'           => $ordem,
            'ocorrencia'      => $ocorrencia,
            'acao'            => $acao,
        ];
    }

    private static function resumir(array $linhas): array
    {
        $cont = [];
        foreach ($linhas as $l) {
            $cont[$l['situacao']] = ($cont[$l['situacao']] ?? 0) + 1;
        }
        $resumo = [];
        foreach (self::SITUACOES as $chave => [$rotulo, $gravidade]) {
            if (isset($cont[$chave])) {
                $resumo[] = ['situacao' => $chave, 'rotulo' => $rotulo, 'gravidade' => $gravidade, 'quantidade' => $cont[$chave]];
            }
        }
        return $resumo;
    }

    // ====================================================================
    //  Exportação (PDF / XLSX / CSV)
    // ====================================================================

    private const COLUNAS = ['Arquivo', 'Código', 'Fornecedor', 'Situação', 'O que está ocorrendo', 'O que fazer'];

    public static function download(string $formato, array $dados): void
    {
        $nome = 'relatorio_arquivos_pendentes_' . date('Ymd_His');
        $linhas = $dados['linhas'];

        switch (strtolower($formato)) {
            case 'pdf':
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="' . $nome . '.pdf"');
                echo self::montarPdf($dados);
                break;
            case 'xlsx':
                if (!class_exists(\ZipArchive::class)) {
                    http_response_code(500);
                    header('Content-Type: application/json');
                    echo json_encode(['error' => 'Extensão ZipArchive não disponível no servidor. Use o formato CSV.']);
                    break;
                }
                $conteudo = self::montarXlsx($linhas);
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . $nome . '.xlsx"');
                header('Content-Length: ' . strlen($conteudo));
                echo $conteudo;
                break;
            case 'csv':
                header('Content-Type: text/csv; charset=UTF-8');
                header('Content-Disposition: attachment; filename="' . $nome . '.csv"');
                $out = fopen('php://output', 'w');
                fputs($out, "\xEF\xBB\xBF");
                fputcsv($out, self::COLUNAS, ';');
                foreach ($linhas as $l) {
                    fputcsv($out, self::valoresLinha($l), ';');
                }
                fclose($out);
                break;
            default:
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Formato não suportado. Use pdf, xlsx ou csv.']);
        }
        exit;
    }

    private static function valoresLinha(array $l): array
    {
        return [$l['arquivo'], $l['cod_for'], $l['fornecedor'], $l['situacao_rotulo'], $l['ocorrencia'], $l['acao']];
    }

    /** Quebra o texto em linhas de no máximo $max caracteres (quebra palavras muito longas). */
    private static function quebrar(string $texto, int $max): array
    {
        $max = max(4, $max);
        $saida = [];
        $atual = '';
        foreach (preg_split('/\s+/u', trim($texto)) ?: [] as $palavra) {
            while (mb_strlen($palavra) > $max) {
                if ($atual !== '') {
                    $saida[] = $atual;
                    $atual = '';
                }
                $saida[] = mb_substr($palavra, 0, $max);
                $palavra = mb_substr($palavra, $max);
            }
            if ($atual === '') {
                $atual = $palavra;
            } elseif (mb_strlen($atual) + 1 + mb_strlen($palavra) <= $max) {
                $atual .= ' ' . $palavra;
            } else {
                $saida[] = $atual;
                $atual = $palavra;
            }
        }
        if ($atual !== '') {
            $saida[] = $atual;
        }
        return $saida ?: [''];
    }

    private static function montarPdf(array $dados): string
    {
        $mm = 2.83465;
        $W = 841.89;
        $H = 595.28;
        $ml = 10 * $mm;
        $mt = 10 * $mm;
        $limiteY = $H - 14 * $mm;

        $larguras = [50, 17, 41, 27, 77, 65];
        $tam = 7.0;
        $hLinha = 3.4 * $mm;
        $maxChars = array_map(static fn (int $w): int => (int) floor(($w * $mm - 4) / (0.6 * $tam)), $larguras);

        $esc = static function (string $t): string {
            $t = mb_convert_encoding($t, 'CP1252', 'UTF-8');
            return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $t);
        };

        $paginas = [];
        $conteudo = '';
        $y = $mt;

        $texto = static function (string $t, float $x, float $topo, float $tamanho) use (&$conteudo, $H, $esc): void {
            $conteudo .= sprintf("BT /F1 %.1f Tf %.2f %.2f Td (%s) Tj ET\n", $tamanho, $x, $H - $topo - $tamanho, $esc($t));
        };

        $cabecalho = function () use (&$y, $texto, $mm, $ml, $larguras, $dados): void {
            $texto('Relatório de Arquivos Aguardando Processamento - Sua Empresa', $ml, $y, 14);
            $y += 7 * $mm;
            $texto('Gerado em: ' . $dados['gerado_em'] . '   |   Total de arquivos: ' . $dados['total'], $ml, $y, 9);
            $y += 5 * $mm;
            if ($dados['resumo']) {
                $partes = array_map(static fn (array $r): string => $r['rotulo'] . ': ' . $r['quantidade'], $dados['resumo']);
                $texto(implode('   |   ', $partes), $ml, $y, 8);
                $y += 5 * $mm;
            }
            $x = $ml;
            foreach (self::COLUNAS as $i => $titulo) {
                $texto($titulo, $x + 2, $y, 8.5);
                $x += $larguras[$i] * $mm;
            }
            $y += 6 * $mm;
        };

        $cabecalho();

        foreach ($dados['linhas'] as $l) {
            $valores = self::valoresLinha($l);
            $quebrados = [];
            $nLinhas = 1;
            foreach ($valores as $i => $v) {
                $quebrados[$i] = self::quebrar((string) $v, $maxChars[$i]);
                $nLinhas = max($nLinhas, count($quebrados[$i]));
            }
            $altura = $nLinhas * $hLinha + 2.2 * $mm;

            if ($y + $altura > $limiteY) {
                $paginas[] = $conteudo;
                $conteudo = '';
                $y = $mt;
                $cabecalho();
            }

            $conteudo .= match ($l['gravidade']) {
                'erro' => "0.75 0.05 0.05 rg\n",
                'ok'   => "0.10 0.50 0.15 rg\n",
                default => "0.55 0.45 0.10 rg\n",
            };
            $x = $ml;
            foreach ($quebrados as $i => $partes) {
                foreach ($partes as $k => $parte) {
                    $texto($parte, $x + 2, $y + $k * $hLinha, $tam);
                }
                $x += $larguras[$i] * $mm;
            }
            $conteudo .= "0 0 0 rg\n";
            $y += $altura;
        }

        if (!$dados['linhas']) {
            $texto('Nenhum arquivo aguardando processamento na pasta de entrada.', $ml, $y, 10);
        }

        $paginas[] = $conteudo;
        return RelatorioGenerator::montarArquivoPdf($paginas, $W, $H);
    }

    private static function montarXlsx(array $linhas): string
    {
        $linhaXml = static function (int $n, array $valores, bool $negrito): string {
            $cols = range('A', 'F');
            $s = $negrito ? ' s="1"' : '';
            $xml = '<row r="' . $n . '">';
            foreach ($valores as $i => $v) {
                $xml .= '<c r="' . $cols[$i] . $n . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">'
                    . htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>';
            }
            return $xml . '</row>';
        };

        $rows = $linhaXml(1, self::COLUNAS, true);
        $n = 2;
        foreach ($linhas as $l) {
            $rows .= $linhaXml($n++, self::valoresLinha($l), false);
        }

        $cab = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $sheet = $cab . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<cols><col min="1" max="1" width="48" customWidth="1"/><col min="2" max="2" width="13" customWidth="1"/>'
            . '<col min="3" max="3" width="34" customWidth="1"/><col min="4" max="4" width="26" customWidth="1"/>'
            . '<col min="5" max="5" width="90" customWidth="1"/><col min="6" max="6" width="70" customWidth="1"/></cols>'
            . '<sheetData>' . $rows . '</sheetData><autoFilter ref="A1:F' . max(1, $n - 1) . '"/></worksheet>';

        $contentTypes = $cab . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
        $rels = $cab . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
        $workbook = $cab . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Arquivos pendentes" sheetId="1" r:id="rId1"/></sheets></workbook>';
        $workbookRels = $cab . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $styles = $cab . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';

        $arquivo = tempnam(sys_get_temp_dir(), 'pendxlsx') . '.xlsx';
        $zip = new \ZipArchive();
        if ($zip->open($arquivo, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Falha ao criar o arquivo XLSX.');
        }
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->addFromString('xl/styles.xml', $styles);
        $zip->close();

        $conteudo = (string) file_get_contents($arquivo);
        @unlink($arquivo);
        return $conteudo;
    }
}
