<?php
/**
 * app/Services/FornecedorExport.php
 * Exporta a tabela de fornecedores em CSV ou XLSX.
 *
 * Implementação NATIVA (sem dependências externas como PHPSpreadsheet):
 *  - XLSX: pacote OpenXML gerado via ZipArchive (requer extensão zip).
 *  - CSV:  padrão aberto com BOM UTF-8 e separador ';'.
 */

declare(strict_types=1);

namespace App\Services;

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

class FornecedorExport
{
    /**
     * Busca os fornecedores conforme os filtros (sem paginação).
     *
     * @param string|null $busca       texto livre (código, nome, razão social, e-mail)
     * @param bool        $apenasAtivos somente ativos
     */
    public static function buscarFornecedores(?string $busca = null, bool $apenasAtivos = false): array
    {
        $where = [];
        $params = [];

        if ($busca !== null && $busca !== '') {
            $where[] = "(cod_for LIKE :b1 OR fan_for LIKE :b2 OR raz_for LIKE :b3 OR email_for LIKE :b4)";
            $params['b1'] = '%' . $busca . '%';
            $params['b2'] = $params['b1'];
            $params['b3'] = $params['b1'];
            $params['b4'] = $params['b1'];
        }

        if ($apenasAtivos) {
            $where[] = 'ativo = 1';
        }

        $whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT cod_for, fan_for, raz_for, email_for,
                       email_for_2, email_for_3, email_for_4, email_for_5, email_for_6,
                       ativo, created_at
                FROM fornecedores
                {$whereClause}
                ORDER BY fan_for ASC";

        return dbQuery($sql, $params);
    }

    /**
     * Gera o arquivo conforme o formato solicitado e redireciona o download.
     */
    public static function download(string $formato, ?string $busca = null, bool $apenasAtivos = false): void
    {
        $fornecedores = self::buscarFornecedores($busca, $apenasAtivos);
        $nomeArquivo = 'fornecedores_' . date('Ymd_His');

        switch (strtolower($formato)) {
            case 'xlsx':
                self::gerarXlsx($fornecedores, $nomeArquivo);
                break;
            case 'csv':
                self::gerarCsv($fornecedores, $nomeArquivo);
                break;
            default:
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Formato não suportado. Use xlsx ou csv.']);
                exit;
        }
    }

    // ====================================================================
    //  XLSX (nativo - OpenXML via ZipArchive)
    // ====================================================================

    private static function gerarXlsx(array $fornecedores, string $nomeArquivo): void
    {
        if (!class_exists(\ZipArchive::class)) {
            http_response_code(500);
            echo json_encode(['error' => 'Extensão ZipArchive não disponível no servidor. Use o formato CSV.']);
            exit;
        }

        $conteudo = self::montarXlsxFornecedores($fornecedores);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $nomeArquivo . '.xlsx"');
        header('Content-Length: ' . strlen($conteudo));
        echo $conteudo;
        exit;
    }

    private static function montarXlsxFornecedores(array $fornecedores): string
    {
        $headers = ['Código', 'Nome Fantasia', 'Razão Social', 'E-mail', 'E-mail 2', 'E-mail 3', 'E-mail 4', 'E-mail 5', 'E-mail 6', 'Ativo', 'Criado em'];

        $linhas = [];
        foreach ($fornecedores as $f) {
            $linhas[] = [
                $f['cod_for'] ?? '',
                $f['fan_for'] ?? '',
                $f['raz_for'] ?? '',
                $f['email_for'] ?? '',
                $f['email_for_2'] ?? '',
                $f['email_for_3'] ?? '',
                $f['email_for_4'] ?? '',
                $f['email_for_5'] ?? '',
                $f['email_for_6'] ?? '',
                ($f['ativo'] ?? 0) ? 'Sim' : 'Não',
                $f['created_at'] ?? '',
            ];
        }

        // Planilha
        $sheetRows = '';
        $linhaIdx = 1;
        $sheetRows .= self::linhaXml($linhaIdx, $headers, true);
        $linhaIdx++;
        foreach ($linhas as $linha) {
            $sheetRows .= self::linhaXml($linhaIdx, $linha, false);
            $linhaIdx++;
        }

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . "\n" . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>' . $sheetRows . '</sheetData>'
            . '<autoFilter ref="A1:K' . max(1, $linhaIdx - 1) . '"/>'
            . '</worksheet>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . "\n" . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . "\n" . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . "\n" . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Fornecedores" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';

        $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . "\n" . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';

        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . "\n" . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';

        $arquivo = tempnam(sys_get_temp_dir(), 'fornecedorxlsx') . '.xlsx';

        $zip = new \ZipArchive();
        if ($zip->open($arquivo, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            http_response_code(500);
            echo json_encode(['error' => 'Falha ao criar o arquivo XLSX.']);
            exit;
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

    /**
     * Monta uma linha <row> da planilha com células de string inline.
     */
    private static function linhaXml(int $num, array $valores, bool $negrito): string
    {
        $cols = range('A', 'K');
        $s = $negrito ? ' s="1"' : '';
        $xml = '<row r="' . $num . '">';
        foreach ($valores as $i => $valor) {
            $xml .= '<c r="' . $cols[$i] . $num . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">'
                . htmlspecialchars((string) $valor, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                . '</t></is></c>';
        }
        $xml .= '</row>';
        return $xml;
    }

    // ====================================================================
    //  CSV
    // ====================================================================

    private static function gerarCsv(array $fornecedores, string $nomeArquivo): void
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $nomeArquivo . '.csv"');

        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF"); // BOM para UTF-8 no Excel

        fputcsv($out, ['Código', 'Nome Fantasia', 'Razão Social', 'E-mail', 'E-mail 2', 'E-mail 3', 'E-mail 4', 'E-mail 5', 'E-mail 6', 'Ativo', 'Criado em'], ';');

        foreach ($fornecedores as $f) {
            fputcsv($out, [
                $f['cod_for'] ?? '',
                $f['fan_for'] ?? '',
                $f['raz_for'] ?? '',
                $f['email_for'] ?? '',
                $f['email_for_2'] ?? '',
                $f['email_for_3'] ?? '',
                $f['email_for_4'] ?? '',
                $f['email_for_5'] ?? '',
                $f['email_for_6'] ?? '',
                ($f['ativo'] ?? 0) ? 'Sim' : 'Não',
                $f['created_at'] ?? '',
            ], ';');
        }

        fclose($out);
        exit;
    }
}
