<?php
// Design Ref: §6 — 파싱 엔진 + 주문서 생성 로직
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../config/db.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * 엑셀 셀에서 만료일 읽기 — 날짜 시리얼 숫자와 텍스트 모두 처리
 */
function ord_read_expiry_cell(object $sheet, string $col, int $row): ?string {
    $cell  = $sheet->getCell($col . $row);
    $value = $cell->getValue();
    if ($value === null || $value === '') return null;

    // 엑셀 날짜 시리얼 → Y-m-d 변환 (순수 PHP, PhpSpreadsheet API 불필요)
    // 25569 = 1970-01-01, 73050 = 2100-01-01
    if (is_numeric($value) && $value >= 25569 && $value <= 73050) {
        return date('Y-m-d', (int)(($value - 25569) * 86400));
    }

    return trim((string)$value);
}

/**
 * 컬럼 인덱스(1-base) → 컬럼 문자열 변환 (A, B, ..., Z, AA, ...)
 */
function ord_col_index_to_letter(int $idx): string {
    $letter = '';
    while ($idx > 0) {
        $idx--;
        $letter = chr(65 + ($idx % 26)) . $letter;
        $idx    = (int)($idx / 26);
    }
    return $letter;
}

/**
 * 엑셀 파일을 파싱하여 재고 아이템 배열 반환
 * (커스텀 스텁: getRowIterator/getRowDimension 미지원 → getHighestRow 루프 사용)
 */
function ord_parse_visible_rows(string $filepath, object $colMap): array {
    $spreadsheet = IOFactory::load($filepath);
    $sheetCount  = $spreadsheet->getSheetCount();
    $sheetIndex  = (int)$colMap->sheet_index;

    // 인덱스 범위 초과 시 이름으로 찾고, 그것도 없으면 첫 번째 시트 사용
    if ($sheetIndex >= $sheetCount) {
        $byName = ($colMap->sheet_name ?? '') !== ''
            ? $spreadsheet->getSheetByName($colMap->sheet_name)
            : null;
        $sheet = $byName ?? $spreadsheet->getSheet(0);
    } else {
        $sheet = $spreadsheet->getSheet($sheetIndex);
    }

    $items       = [];
    $highestRow  = $sheet->getHighestRow();

    for ($idx = (int)$colMap->header_row + 1; $idx <= $highestRow; $idx++) {
        if ($sheet->isRowHidden($idx)) continue;
        $name = trim((string)$sheet->getCell($colMap->product_name_col . $idx)->getValue());
        if ($name === '') continue;

        $items[] = [
            'row_number'      => $idx,
            'product_name'    => $name,
            'product_name_en' => ($colMap->product_name_en_col ?? null)
                ? trim((string)$sheet->getCell($colMap->product_name_en_col . $idx)->getValue())
                : null,
            'product_code'    => ($colMap->product_code_col ?? null)
                ? trim((string)$sheet->getCell($colMap->product_code_col . $idx)->getValue())
                : null,
            'unit_price'      => ($colMap->unit_price_col ?? null)
                ? (float)$sheet->getCell($colMap->unit_price_col . $idx)->getValue()
                : null,
            'unit_price_pcs'  => ($colMap->unit_price_pcs_col ?? null)
                ? (float)$sheet->getCell($colMap->unit_price_pcs_col . $idx)->getValue()
                : null,
            'order_unit'      => ($colMap->order_unit_col ?? null)
                ? trim((string)$sheet->getCell($colMap->order_unit_col . $idx)->getValue())
                : null,
            'unit_qty'        => ($colMap->unit_qty_col ?? null)
                ? (int)$sheet->getCell($colMap->unit_qty_col . $idx)->getValue()
                : null,
            'brand'           => ($colMap->brand_col ?? null)
                ? trim((string)$sheet->getCell($colMap->brand_col . $idx)->getValue())
                : null,
            'remark'          => ($colMap->remark_col ?? null)
                ? trim((string)$sheet->getCell($colMap->remark_col . $idx)->getValue())
                : null,
            'expiry_date'     => ($colMap->expiry_col ?? null)
                ? ord_read_expiry_cell($sheet, $colMap->expiry_col, $idx)
                : null,
        ];
    }
    return $items;
}

/**
 * 엑셀 헤더 행 미리보기 반환 (컬럼 설정 UI용)
 */
function ord_get_header_preview(string $filepath, int $sheetIndex, int $headerRow): array {
    $spreadsheet = IOFactory::load($filepath);
    $sheetCount  = $spreadsheet->getSheetCount();
    $sheet       = $spreadsheet->getSheet(min($sheetIndex, max(0, $sheetCount - 1)));
    $preview     = [];

    for ($colIdx = 1; $colIdx <= 20; $colIdx++) {
        $colLetter = ord_col_index_to_letter($colIdx);
        $value = trim((string)$sheet->getCell($colLetter . $headerRow)->getValue());
        $preview[$colLetter] = $value;
    }
    return $preview;
}

/**
 * 원본 엑셀 파일 복사 후 수량 컬럼만 채워서 임시 파일 경로 반환
 * Plan SC: 업체별 주문서 다운로드 시 원본 서식 100% 유지
 */
function ord_generate_order_file(string $originalPath, object $colMap, array $cartItems): string {
    $qtyMap = [];
    foreach ($cartItems as $item) {
        $qtyMap[(int)$item['row_number']] = $item['quantity'];
    }

    // 원본 파일을 임시 파일로 복사 (서식 100% 유지)
    $tmp = sys_get_temp_dir() . '/ord_' . uniqid() . '.xlsx';
    copy($originalPath, $tmp);

    $zip = new \ZipArchive();
    if ($zip->open($tmp) !== true) {
        throw new \Exception('주문서 파일을 열 수 없습니다.');
    }

    $sheetIndex = (int)$colMap->sheet_index;
    $sheetPath  = 'xl/worksheets/sheet' . ($sheetIndex + 1) . '.xml';
    if (!$zip->locateName($sheetPath)) {
        $sheetPath = 'xl/worksheets/sheet1.xml';
    }

    $sheetXml = $zip->getFromName($sheetPath);
    $dom = new \DOMDocument();
    $dom->loadXML($sheetXml);

    $qtyCol = strtoupper(trim($colMap->quantity_col));
    foreach ($dom->getElementsByTagName('row') as $rowEl) {
        $rowNum = (int)$rowEl->getAttribute('r');
        if (!isset($qtyMap[$rowNum])) continue;

        $cellRef    = $qtyCol . $rowNum;
        $targetCell = null;

        foreach ($rowEl->getElementsByTagName('c') as $cellEl) {
            if ($cellEl->getAttribute('r') === $cellRef) {
                $targetCell = $cellEl;
                break;
            }
        }

        if ($targetCell === null) {
            $targetCell = $dom->createElement('c');
            $targetCell->setAttribute('r', $cellRef);
            $rowEl->appendChild($targetCell);
        }

        // 수식·문자 타입 제거 후 숫자 값으로 설정
        $targetCell->removeAttribute('t');
        foreach (iterator_to_array($targetCell->getElementsByTagName('f')) as $n) $targetCell->removeChild($n);
        foreach (iterator_to_array($targetCell->getElementsByTagName('v')) as $n) $targetCell->removeChild($n);

        $vEl = $dom->createElement('v', (string)$qtyMap[$rowNum]);
        $targetCell->appendChild($vEl);
    }

    $zip->addFromString($sheetPath, $dom->saveXML());
    $zip->close();

    return $tmp;
}

/**
 * 업로드 파일 저장 경로 생성
 */
function ord_get_upload_path(int $vendorId, string $originalFilename): array {
    $uploadDir = dirname(__DIR__, 2) . '/uploads/order_inventories/' . $vendorId . '/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $date = date('Ymd');
    $safe = preg_replace('/[^a-zA-Z0-9가-힣._-]/', '_', $originalFilename);
    $filename = $date . '_' . uniqid() . '_' . $safe;
    return ['dir' => $uploadDir, 'filename' => $filename, 'path' => $uploadDir . $filename];
}

/**
 * order_cart_items 테이블에 added_by 컬럼 자동 생성 (최초 1회)
 */
function ord_ensure_cart_schema(mysqli $conn): void {
    static $done = false;
    if ($done) return;
    $res = $conn->query("SHOW COLUMNS FROM order_cart_items LIKE 'added_by'");
    if ($res && $res->num_rows === 0) {
        $conn->query("ALTER TABLE order_cart_items ADD COLUMN added_by INT NULL AFTER quantity");
    }
    $done = true;
}

/**
 * 장바구니 아이템 + 업체별 그룹 조회
 */
function ord_get_cart_grouped(int $storeId): array {
    $conn = get_ord_db();
    ord_ensure_cart_schema($conn);
    $stmt = $conn->prepare("
        SELECT c.id, c.vendor_id, c.inventory_item_id, c.quantity,
               c.added_by, u.full_name AS added_by_name,
               v.name AS vendor_name,
               i.product_name, i.product_name_en, i.unit_price, i.unit_price_pcs, i.remark, i.expiry_date, i.`row_number`,
               inv.id AS inventory_id, inv.stored_filepath
        FROM order_cart_items c
        JOIN order_vendors v ON c.vendor_id = v.id
        JOIN order_vendor_inventory_items i ON c.inventory_item_id = i.id
        JOIN order_vendor_inventories inv ON i.inventory_id = inv.id
        LEFT JOIN users u ON c.added_by = u.id
        WHERE c.store_id = ?
        ORDER BY v.name, i.product_name
    ");
    $stmt->bind_param('i', $storeId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();

    $grouped = [];
    foreach ($rows as $row) {
        $vid = $row['vendor_id'];
        if (!isset($grouped[$vid])) {
            $grouped[$vid] = [
                'vendor_id'   => $vid,
                'vendor_name' => $row['vendor_name'],
                'items'       => [],
            ];
        }
        $grouped[$vid]['items'][] = $row;
    }
    return array_values($grouped);
}

/**
 * 장바구니 총 아이템 수
 */
function ord_cart_count(int $storeId): int {
    $conn = get_ord_db();
    $stmt = $conn->prepare("SELECT COUNT(*) FROM order_cart_items WHERE store_id = ?");
    $stmt->bind_param('i', $storeId);
    $stmt->execute();
    $stmt->bind_result($cnt);
    $stmt->fetch();
    $stmt->close();
    $conn->close();
    return (int)$cnt;
}
