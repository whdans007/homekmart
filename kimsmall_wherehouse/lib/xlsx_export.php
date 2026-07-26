<?php
// 의존성 없는 최소 XLSX 다운로드 헬퍼 (vendor의 PhpSpreadsheet에는 Writer가 없음)

function kw_xml_escape(string $v): string {
    return htmlspecialchars($v, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

/**
 * @param string   $filename  다운로드 파일명 (확장자 제외, 타임스탬프 자동 추가)
 * @param string[] $headers   헤더 행
 * @param array[]  $rows      데이터 행 (각 행은 헤더와 같은 개수의 값 배열)
 * @param int[]    $textCols  텍스트 서식(@)을 적용할 1-based 컬럼 번호 목록 (바코드 등)
 */
function kw_export_xlsx(string $filename, array $headers, array $rows, array $textCols = []): void {
    $colCount = count($headers);
    $colLetters = [];
    for ($i = 0; $i < $colCount; $i++) {
        $n = $i; $letter = '';
        do {
            $letter = chr(65 + ($n % 26)) . $letter;
            $n = intdiv($n, 26) - 1;
        } while ($n >= 0);
        $colLetters[] = $letter;
    }

    // styles: 0=기본, 1=헤더(굵게), 2=텍스트 서식(@)
    $styleSheet = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="2">
    <font><sz val="11"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><name val="Calibri"/></font>
  </fonts>
  <fills count="2">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
  </fills>
  <borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="3">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>
    <xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>
  </cellXfs>
  <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>
XML;

    $sheetXml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $sheetXml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    $sheetXml .= '<cols>';
    foreach ($colLetters as $i => $l) {
        $idx = $i + 1;
        $width = in_array($idx, $textCols, true) ? 16 : 14;
        $sheetXml .= '<col min="' . $idx . '" max="' . $idx . '" width="' . $width . '" customWidth="1"/>';
    }
    $sheetXml .= '</cols>';
    $sheetXml .= '<sheetData>';

    // 헤더 행
    $sheetXml .= '<row r="1">';
    foreach ($headers as $i => $h) {
        $ref = $colLetters[$i] . '1';
        $sheetXml .= '<c r="' . $ref . '" t="inlineStr" s="1"><is><t xml:space="preserve">' . kw_xml_escape((string)$h) . '</t></is></c>';
    }
    $sheetXml .= '</row>';

    // 데이터 행
    foreach ($rows as $rIdx => $row) {
        $r = $rIdx + 2;
        $sheetXml .= '<row r="' . $r . '">';
        foreach (array_values($row) as $i => $val) {
            $ref = $colLetters[$i] . $r;
            $isText = in_array($i + 1, $textCols, true);
            $style  = $isText ? ' s="2"' : '';
            $sheetXml .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . kw_xml_escape((string)$val) . '</t></is></c>';
        }
        $sheetXml .= '</row>';
    }
    $sheetXml .= '</sheetData></worksheet>';

    $contentTypes = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>
XML;

    $rootRels = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML;

    $workbookXml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Sheet1" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>
XML;

    $workbookRels = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>
XML;

    $tmpFile = tempnam(sys_get_temp_dir(), 'lcxlsx');

    $zip = new ZipArchive();
    $zip->open($tmpFile, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/styles.xml', $styleSheet);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();

    $downloadName = $filename . '_' . date('Ymd_His') . '.xlsx';

    while (ob_get_level() > 0) { ob_end_clean(); }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $downloadName . '"');
    header('Content-Length: ' . filesize($tmpFile));
    header('Cache-Control: max-age=0');

    readfile($tmpFile);
    unlink($tmpFile);
    exit;
}
