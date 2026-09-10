<?php
/**
 * Foodpang 외부 도매 매핑(fpwx = FoodPang Wholesale eXternal) 헬퍼 함수 모음.
 *
 * 이 모듈은 완전히 독립적이다:
 *  - Foodpang이 제공하는 원본 XLSX(Barcode/PMS_스냅샷 시트) 데이터는 fpwx_raw_* 테이블에
 *    "불변 스냅샷"으로만 쌓이며 절대 UPDATE/DELETE 하지 않는다.
 *  - 기존 products/inventory 등 HKM 상품/재고 테이블은 절대 변경하지 않는다.
 *    (products.id를 조회/참조만 함)
 *  - 매핑 변경은 fpwx_mapping_history에 append-only로 기록한다.
 *
 * 의존: vendor/autoload.php (PhpSpreadsheet) - XLSX 파싱 함수를 쓰기 전에 호출측에서 require 해야 함.
 */

require_once __DIR__ . '/../config/db_config.php';

/**
 * Barcode 시트 이름 후보 (대소문자/공백 무시하고 포함 여부로 판정).
 * 실제 Foodpang 원본 워크북은 시트명이 한글 "바코드"로 되어 있으므로 반드시 포함해야 한다.
 * 영문 "Barcode"/"barcode"도 계속 지원한다(과거 파일/다른 소스 호환).
 */
define('FPWX_BARCODE_SHEET_ALIASES', ['바코드', 'barcode']);
/** PMS_스냅샷 시트 이름 후보 */
define('FPWX_PMS_SHEET_ALIASES', ['pms_스냅샷', 'pms스냅샷', 'pms snapshot', 'pms']);
/**
 * 헤더/원본 열을 스캔할 최대 열 개수 (A열부터).
 * 본 프로젝트에 vendor 된 PhpSpreadsheet는 읽기 전용 축소 구현체라
 * getHighestColumn()/Coordinate 헬퍼가 없으므로, 고정 폭을 스캔한다.
 * T열(20번째)까지의 폴백 규칙을 충분히 덮고도 여유 있게 60열까지 스캔한다.
 */
define('FPWX_MAX_SCAN_COLUMNS', 60);

/**
 * 이 모듈 전용 PDO 커넥션(요청 단위 1회 생성/재사용).
 * @return PDO
 */
function fpwx_pdo() {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

/**
 * 시트별 필드 정의: 헤더명 후보로 우선 탐지하고, 못 찾으면 지정된 열로 폴백한다.
 * Barcode 시트: sales_code -> A열, barcode -> B열
 * PMS_스냅샷 시트: sales_code -> A열, base_code -> B열, product_name -> T열
 * @param string $sheet_type 'barcode' | 'pms'
 * @return array field => ['headers' => string[], 'fallback_col' => string]
 */
function fpwx_field_specs($sheet_type) {
    if ($sheet_type === 'barcode') {
        return [
            'sales_code' => ['headers' => ['판매코드', '판매 코드', 'sales code', 'salescode', 'sales_code'], 'fallback_col' => 'A'],
            'barcode'    => ['headers' => ['바코드', 'barcode'], 'fallback_col' => 'B'],
        ];
    }
    // pms
    return [
        'sales_code'   => ['headers' => ['판매코드', '판매 코드', 'sales code', 'salescode', 'sales_code'], 'fallback_col' => 'A'],
        'base_code'    => ['headers' => ['기본코드', '기준코드', '베이스코드', 'base code', 'basecode', 'base_code'], 'fallback_col' => 'B'],
        'product_name' => ['headers' => ['상품명', '품명', 'product name', 'item name', 'productname'], 'fallback_col' => 'T'],
    ];
}

/**
 * 헤더 문자열을 비교하기 쉽게 정규화한다 (공백/대소문자 제거).
 * @param string $s
 * @return string
 */
function fpwx_normalize_header($s) {
    $s = mb_strtolower(trim((string)$s));
    return preg_replace('/\s+/u', '', $s);
}

/**
 * 1-based 열 인덱스를 엑셀 열 문자(A, B, ..., Z, AA, ...)로 변환한다.
 * @param int $index
 * @return string
 */
function fpwx_col_index_to_letter($index) {
    $letter = '';
    while ($index > 0) {
        $index--;
        $letter = chr(65 + ($index % 26)) . $letter;
        $index = intdiv($index, 26);
    }
    return $letter;
}

/**
 * 워크시트의 헤더 행(1행)을 스캔하여 field => 열문자 매핑을 만든다.
 * 헤더에서 후보 문자열을 찾지 못한 필드는 fallback_col을 사용한다.
 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
 * @param array $field_specs fpwx_field_specs() 결과
 * @return array field => 열문자(예: 'A')
 */
function fpwx_build_column_map($sheet, array $field_specs) {
    $column_map = [];
    foreach ($field_specs as $field => $spec) {
        $found_col = null;
        for ($i = 1; $i <= FPWX_MAX_SCAN_COLUMNS; $i++) {
            $col_letter = fpwx_col_index_to_letter($i);
            $header_value = fpwx_normalize_header($sheet->getCell($col_letter . '1')->getValue());
            if ($header_value === '') {
                continue;
            }
            foreach ($spec['headers'] as $candidate) {
                if ($header_value === fpwx_normalize_header($candidate) || strpos($header_value, fpwx_normalize_header($candidate)) !== false) {
                    $found_col = $col_letter;
                    break 2;
                }
            }
        }
        $column_map[$field] = $found_col ?: $spec['fallback_col'];
    }
    return $column_map;
}

/**
 * 셀 값을 문자열로 안전하게 읽는다 (숫자/날짜 등도 문자열로 통일).
 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
 * @param string $col_letter
 * @param int $row
 * @return string
 */
function fpwx_cell_string($sheet, $col_letter, $row) {
    $value = $sheet->getCell($col_letter . $row)->getValue();
    if ($value === null) {
        return '';
    }
    return trim((string)$value);
}

/**
 * 시트에서 헤더(1행)를 제외한 데이터 행을 모두 읽어 [field => value] + raw(JSON용 배열)로 반환한다.
 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
 * @param array $field_specs
 * @return array[] 각 원소: ['row_number' => int, 'fields' => [...], 'raw' => [...]]
 */
function fpwx_extract_rows($sheet, array $field_specs) {
    $column_map = fpwx_build_column_map($sheet, $field_specs);
    $highest_row = $sheet->getHighestRow();

    // raw 스냅샷에 포함할 실제 사용 열 폭을 헤더 행 기준으로 파악한다 (getHighestColumn()이 없는 축소 구현체 대응).
    $raw_col_count = 0;
    for ($i = 1; $i <= FPWX_MAX_SCAN_COLUMNS; $i++) {
        if (fpwx_cell_string($sheet, fpwx_col_index_to_letter($i), 1) !== '') {
            $raw_col_count = $i;
        }
    }
    $raw_col_count = max($raw_col_count, 20); // T열(20번째) 폴백 규칙을 항상 포함

    $rows = [];
    for ($row = 2; $row <= $highest_row; $row++) {
        $fields = [];
        foreach ($field_specs as $field => $spec) {
            $fields[$field] = fpwx_cell_string($sheet, $column_map[$field], $row);
        }

        // 완전히 빈 행은 건너뜀
        if (implode('', $fields) === '') {
            continue;
        }

        $raw = [];
        for ($i = 1; $i <= $raw_col_count; $i++) {
            $col_letter = fpwx_col_index_to_letter($i);
            $header = fpwx_cell_string($sheet, $col_letter, 1);
            $key = $header !== '' ? $header : $col_letter;
            $raw[$key] = fpwx_cell_string($sheet, $col_letter, $row);
        }

        $rows[] = ['row_number' => $row, 'fields' => $fields, 'raw' => $raw];
    }
    return $rows;
}

/**
 * 업로드된 XLSX 파일에서 Barcode / PMS_스냅샷 시트를 찾는다.
 * @param string $file_path
 * @return array ['barcode_sheet' => Worksheet, 'pms_sheet' => Worksheet, 'barcode_sheet_name' => string, 'pms_sheet_name' => string]
 * @throws Exception 필요한 시트를 찾지 못한 경우
 */
function fpwx_load_workbook_sheets($file_path) {
    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file_path);
    $barcode_sheet = null;
    $pms_sheet = null;
    $barcode_sheet_name = null;
    $pms_sheet_name = null;

    $sheet_names = array_map(function ($s) { return $s->getTitle(); }, $spreadsheet->getAllSheets());

    foreach ($sheet_names as $name) {
        $normalized = fpwx_normalize_header($name);
        if ($barcode_sheet === null) {
            foreach (FPWX_BARCODE_SHEET_ALIASES as $alias) {
                if (strpos($normalized, fpwx_normalize_header($alias)) !== false) {
                    $barcode_sheet = $spreadsheet->getSheetByName($name);
                    $barcode_sheet_name = $name;
                    break;
                }
            }
        }
        if ($pms_sheet === null) {
            foreach (FPWX_PMS_SHEET_ALIASES as $alias) {
                if (strpos($normalized, fpwx_normalize_header($alias)) !== false) {
                    $pms_sheet = $spreadsheet->getSheetByName($name);
                    $pms_sheet_name = $name;
                    break;
                }
            }
        }
    }

    if (!$barcode_sheet || !$pms_sheet) {
        $missing = [];
        if (!$barcode_sheet) $missing[] = 'Barcode (' . implode('/', FPWX_BARCODE_SHEET_ALIASES) . ')';
        if (!$pms_sheet) $missing[] = 'PMS_스냅샷 (' . implode('/', FPWX_PMS_SHEET_ALIASES) . ')';
        throw new Exception(
            '필요한 시트를 찾을 수 없습니다: ' . implode(', ', $missing) .
            ' (업로드된 시트 목록: ' . implode(', ', $sheet_names) . ')'
        );
    }

    return [
        'barcode_sheet' => $barcode_sheet,
        'pms_sheet' => $pms_sheet,
        'barcode_sheet_name' => $barcode_sheet_name,
        'pms_sheet_name' => $pms_sheet_name,
    ];
}

/**
 * 업로드 파일의 SHA-256 해시를 계산한다 (동일 파일 재업로드 감지용).
 * @param string $tmp_path
 * @return string
 */
function fpwx_hash_file($tmp_path) {
    return hash_file('sha256', $tmp_path);
}

/**
 * 동일 해시의 배치가 이미 존재하는지 확인한다.
 * @param PDO $pdo
 * @param string $hash
 * @return array|null 배치 행 (없으면 null)
 */
function fpwx_find_batch_by_hash(PDO $pdo, $hash) {
    $stmt = $pdo->prepare('SELECT * FROM fpwx_import_batches WHERE batch_hash = ?');
    $stmt->execute([$hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * 배치 + 원본 스냅샷(raw rows)을 DB에 저장한다. 트랜잭션으로 처리한다.
 * @param PDO $pdo
 * @param array $args ['hash','filename','barcode_sheet_name','pms_sheet_name','barcode_rows','pms_rows','uploaded_by']
 * @return int 생성된 batch_id
 */
function fpwx_store_batch(PDO $pdo, array $args) {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO fpwx_import_batches
             (batch_hash, original_filename, barcode_sheet_name, pms_sheet_name, barcode_row_count, pms_row_count, status, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $args['hash'],
            $args['filename'],
            $args['barcode_sheet_name'],
            $args['pms_sheet_name'],
            count($args['barcode_rows']),
            count($args['pms_rows']),
            'uploaded',
            $args['uploaded_by'],
        ]);
        $batch_id = (int)$pdo->lastInsertId();

        $barcode_stmt = $pdo->prepare(
            'INSERT INTO fpwx_raw_barcode_rows (batch_id, `row_number`, sales_code, barcode, raw_data) VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($args['barcode_rows'] as $row) {
            $barcode_stmt->execute([
                $batch_id,
                $row['row_number'],
                $row['fields']['sales_code'] !== '' ? $row['fields']['sales_code'] : null,
                $row['fields']['barcode'] !== '' ? $row['fields']['barcode'] : null,
                json_encode($row['raw'], JSON_UNESCAPED_UNICODE),
            ]);
        }

        $pms_stmt = $pdo->prepare(
            'INSERT INTO fpwx_raw_pms_rows (batch_id, `row_number`, sales_code, base_code, product_name, raw_data) VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($args['pms_rows'] as $row) {
            $pms_stmt->execute([
                $batch_id,
                $row['row_number'],
                $row['fields']['sales_code'] !== '' ? $row['fields']['sales_code'] : null,
                $row['fields']['base_code'] !== '' ? $row['fields']['base_code'] : null,
                $row['fields']['product_name'] !== '' ? $row['fields']['product_name'] : null,
                json_encode($row['raw'], JSON_UNESCAPED_UNICODE),
            ]);
        }

        $pdo->commit();
        return $batch_id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * 배치의 원본 스냅샷(raw rows)을 병합하여 정규화 테이블(fpwx_base_products / fpwx_sales_products)을 갱신한다.
 * Barcode 시트의 barcode 값과 PMS 시트의 base_code/product_name 값을 sales_code 기준으로 병합한다.
 * raw 테이블 자체는 절대 수정하지 않고, 파생 테이블만 upsert 한다.
 * @param PDO $pdo
 * @param int $batch_id
 * @return string[] 이번 배치에서 갱신된 sales_code 목록
 */
function fpwx_rebuild_normalized_products(PDO $pdo, $batch_id) {
    $barcode_stmt = $pdo->prepare('SELECT sales_code, barcode FROM fpwx_raw_barcode_rows WHERE batch_id = ? AND sales_code IS NOT NULL');
    $barcode_stmt->execute([$batch_id]);
    $barcode_by_sales_code = [];
    foreach ($barcode_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $barcode_by_sales_code[$r['sales_code']] = $r['barcode'];
    }

    $pms_stmt = $pdo->prepare('SELECT sales_code, base_code, product_name FROM fpwx_raw_pms_rows WHERE batch_id = ? AND sales_code IS NOT NULL');
    $pms_stmt->execute([$batch_id]);
    $pms_rows = $pms_stmt->fetchAll(PDO::FETCH_ASSOC);

    $sales_codes = [];
    $base_code_counts = [];
    $name_by_base = [];
    foreach ($pms_rows as $r) {
        $base_code = $r['base_code'] ?? '';
        if ($base_code !== '') {
            $base_code_counts[$base_code] = ($base_code_counts[$base_code] ?? 0) + 1;
            if (!isset($name_by_base[$base_code])) {
                $name_by_base[$base_code] = $r['product_name'] ?: null;
            }
        }
    }

    // fpwx_sales_products.base_code가 fpwx_base_products.base_code를 FK로 참조하므로
    // base_products를 먼저 upsert해야 한다.
    if (!empty($base_code_counts)) {
        $upsert_base = $pdo->prepare(
            'INSERT INTO fpwx_base_products (base_code, product_name, sales_code_count, last_batch_id)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE sales_code_count = VALUES(sales_code_count), last_batch_id = VALUES(last_batch_id)'
        );
        foreach ($base_code_counts as $base_code => $count) {
            $upsert_base->execute([$base_code, $name_by_base[$base_code] ?? null, $count, $batch_id]);
        }
    }

    $upsert_sales = $pdo->prepare(
        'INSERT INTO fpwx_sales_products (sales_code, base_code, barcode, product_name, last_batch_id)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE base_code = VALUES(base_code), barcode = VALUES(barcode),
                                  product_name = VALUES(product_name), last_batch_id = VALUES(last_batch_id)'
    );

    foreach ($pms_rows as $r) {
        $sales_code = $r['sales_code'];
        $base_code = $r['base_code'] !== '' ? $r['base_code'] : null;
        $barcode = $barcode_by_sales_code[$sales_code] ?? null;
        $barcode = ($barcode !== null && $barcode !== '') ? $barcode : null;

        $upsert_sales->execute([$sales_code, $base_code, $barcode, $r['product_name'] ?: null, $batch_id]);
        $sales_codes[] = $sales_code;
    }

    return array_values(array_unique($sales_codes));
}

/**
 * 매핑 변경 이력을 append-only로 기록한다.
 * @param PDO $pdo
 * @param string $sales_code
 * @param int|null $hkm_product_id
 * @param string $action
 * @param array|null $old_value
 * @param array|null $new_value
 * @param string|null $note
 * @param int|null $changed_by
 */
function fpwx_log_history(PDO $pdo, $sales_code, $hkm_product_id, $action, $old_value, $new_value, $note, $changed_by) {
    $stmt = $pdo->prepare(
        'INSERT INTO fpwx_mapping_history (sales_code, hkm_product_id, action, old_value, new_value, note, changed_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $sales_code,
        $hkm_product_id,
        $action,
        $old_value !== null ? json_encode($old_value, JSON_UNESCAPED_UNICODE) : null,
        $new_value !== null ? json_encode($new_value, JSON_UNESCAPED_UNICODE) : null,
        $note,
        $changed_by,
    ]);
}

/**
 * 결정론적 매칭을 수행한다.
 * 규칙: sales_code == base_code 이고, barcode가 HKM products.sku와 정확히 일치하면 자동 매핑.
 * 그 외(1:N 변형, 바코드 누락/불일치)는 예외 검토 대기열(fpwx_match_candidates)로 보낸다.
 * 이미 활성 매핑(fpwx_hkm_mappings, status=active)이 있는 sales_code는 건드리지 않는다(자동 덮어쓰기 금지).
 * @param PDO $pdo
 * @param int $batch_id
 * @param string[] $sales_codes 이번 배치에서 갱신된 sales_code 목록
 * @return array ['auto_matched' => int, 'exceptions' => int]
 */
function fpwx_run_matching(PDO $pdo, $batch_id, array $sales_codes) {
    if (empty($sales_codes)) {
        return ['auto_matched' => 0, 'exceptions' => 0];
    }

    $auto_matched = 0;
    $exceptions = 0;

    $placeholders = implode(',', array_fill(0, count($sales_codes), '?'));
    $sales_stmt = $pdo->prepare("SELECT * FROM fpwx_sales_products WHERE sales_code IN ({$placeholders})");
    $sales_stmt->execute($sales_codes);
    $sales_rows = $sales_stmt->fetchAll(PDO::FETCH_ASSOC);

    $existing_stmt = $pdo->prepare('SELECT sales_code FROM fpwx_hkm_mappings WHERE sales_code = ? AND status = "active"');
    $product_stmt = $pdo->prepare('SELECT id FROM products WHERE sku = ? LIMIT 1');
    $insert_candidate = $pdo->prepare(
        'INSERT INTO fpwx_match_candidates (batch_id, sales_code, hkm_product_id, match_method, score, reason, status)
         VALUES (?, ?, ?, ?, ?, ?, "pending")'
    );
    $insert_mapping = $pdo->prepare(
        'INSERT INTO fpwx_hkm_mappings (sales_code, hkm_product_id, match_type, package_type, units_per_sale, confidence_score, status, mapped_by)
         VALUES (?, ?, "auto_exact", NULL, 1.00, 100.00, "active", NULL)'
    );

    foreach ($sales_rows as $row) {
        $sales_code = $row['sales_code'];

        $existing_stmt->execute([$sales_code]);
        if ($existing_stmt->fetch()) {
            continue; // 이미 확정된 매핑은 자동 재매칭하지 않음
        }

        $barcode = $row['barcode'];
        if ($barcode === null || $barcode === '') {
            $insert_candidate->execute([$batch_id, $sales_code, null, 'none', 0, 'missing_barcode']);
            $exceptions++;
            continue;
        }

        $product_stmt->execute([$barcode]);
        $product = $product_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            $insert_candidate->execute([$batch_id, $sales_code, null, 'none', 0, 'barcode_not_found']);
            $exceptions++;
            continue;
        }

        $is_primary_variant = ($row['base_code'] !== null && $row['base_code'] === $sales_code);
        if ($is_primary_variant) {
            $insert_mapping->execute([$sales_code, $product['id']]);
            fpwx_log_history($pdo, $sales_code, (int)$product['id'], 'auto_matched', null,
                ['hkm_product_id' => (int)$product['id'], 'match_type' => 'auto_exact'], '자동 매칭(sales_code==base_code, 바코드 일치)', null);
            $auto_matched++;
        } else {
            $insert_candidate->execute([$batch_id, $sales_code, $product['id'], 'exact_barcode_only', 80, 'one_to_many_variant']);
            $exceptions++;
        }
    }

    return ['auto_matched' => $auto_matched, 'exceptions' => $exceptions];
}

/**
 * 배치의 auto_matched_count / exception_count / status를 갱신한다.
 * @param PDO $pdo
 * @param int $batch_id
 * @param int $auto_matched
 * @param int $exceptions
 */
function fpwx_finalize_batch(PDO $pdo, $batch_id, $auto_matched, $exceptions) {
    $stmt = $pdo->prepare(
        'UPDATE fpwx_import_batches SET auto_matched_count = ?, exception_count = ?, status = "processed" WHERE id = ?'
    );
    $stmt->execute([$auto_matched, $exceptions, $batch_id]);
}

/**
 * 수동 매핑을 등록/갱신한다. package_type과 units_per_sale을 함께 지정한다.
 * @param PDO $pdo
 * @param string $sales_code
 * @param int $hkm_product_id
 * @param string|null $package_type
 * @param float $units_per_sale
 * @param int $user_id
 * @return void
 */
function fpwx_apply_manual_mapping(PDO $pdo, $sales_code, $hkm_product_id, $package_type, $units_per_sale, $user_id) {
    $existing_stmt = $pdo->prepare('SELECT * FROM fpwx_hkm_mappings WHERE sales_code = ?');
    $existing_stmt->execute([$sales_code]);
    $existing = $existing_stmt->fetch(PDO::FETCH_ASSOC);

    $new_value = [
        'hkm_product_id' => (int)$hkm_product_id,
        'package_type' => $package_type,
        'units_per_sale' => (float)$units_per_sale,
        'match_type' => 'manual',
    ];

    if ($existing) {
        $stmt = $pdo->prepare(
            'UPDATE fpwx_hkm_mappings
             SET hkm_product_id = ?, match_type = "manual", package_type = ?, units_per_sale = ?, status = "active", mapped_by = ?
             WHERE sales_code = ?'
        );
        $stmt->execute([$hkm_product_id, $package_type, $units_per_sale, $user_id, $sales_code]);
        fpwx_log_history($pdo, $sales_code, (int)$hkm_product_id, 'updated', $existing, $new_value, '수동 매핑 수정', $user_id);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO fpwx_hkm_mappings (sales_code, hkm_product_id, match_type, package_type, units_per_sale, status, mapped_by)
             VALUES (?, ?, "manual", ?, ?, "active", ?)'
        );
        $stmt->execute([$sales_code, $hkm_product_id, $package_type, $units_per_sale, $user_id]);
        fpwx_log_history($pdo, $sales_code, (int)$hkm_product_id, 'manual_mapped', null, $new_value, '수동 매핑 등록', $user_id);
    }

    $resolve_stmt = $pdo->prepare(
        'UPDATE fpwx_match_candidates SET status = "resolved", resolved_by = ?, resolved_at = NOW() WHERE sales_code = ? AND status = "pending"'
    );
    $resolve_stmt->execute([$user_id, $sales_code]);
}

/**
 * 활성 매핑을 거부(rejected) 처리한다. 이력에 남기고 상태만 바꾼다(삭제하지 않음).
 * @param PDO $pdo
 * @param string $sales_code
 * @param int $user_id
 * @param string|null $note
 */
function fpwx_reject_mapping(PDO $pdo, $sales_code, $user_id, $note = null) {
    $existing_stmt = $pdo->prepare('SELECT * FROM fpwx_hkm_mappings WHERE sales_code = ?');
    $existing_stmt->execute([$sales_code]);
    $existing = $existing_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        return;
    }
    $stmt = $pdo->prepare('UPDATE fpwx_hkm_mappings SET status = "rejected" WHERE sales_code = ?');
    $stmt->execute([$sales_code]);
    fpwx_log_history($pdo, $sales_code, (int)$existing['hkm_product_id'], 'rejected', $existing, null, $note, $user_id);
}

/**
 * 예외 검토 대기열(pending)을 배치/사유별로 조회한다.
 * @param PDO $pdo
 * @param array $filters ['batch_id' => int|null, 'reason' => string|null]
 * @return array
 */
function fpwx_get_pending_candidates(PDO $pdo, array $filters = []) {
    $where = ['mc.status = "pending"'];
    $params = [];
    if (!empty($filters['batch_id'])) {
        $where[] = 'mc.batch_id = ?';
        $params[] = $filters['batch_id'];
    }
    if (!empty($filters['reason'])) {
        $where[] = 'mc.reason = ?';
        $params[] = $filters['reason'];
    }

    $sql = 'SELECT mc.*, sp.base_code, sp.barcode AS sales_barcode, sp.product_name AS sales_product_name,
                   p.name_ko AS hkm_name_ko, p.sku AS hkm_sku
            FROM fpwx_match_candidates mc
            LEFT JOIN fpwx_sales_products sp ON sp.sales_code = mc.sales_code
            LEFT JOIN products p ON p.id = mc.hkm_product_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY mc.created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 이름/SKU로 HKM 상품을 검색한다 (수동 매핑용).
 * @param PDO $pdo
 * @param string $query
 * @return array
 */
function fpwx_search_hkm_products(PDO $pdo, $query) {
    $like = '%' . $query . '%';
    $stmt = $pdo->prepare(
        'SELECT id, sku, name_ko, name_en FROM products
         WHERE (name_ko LIKE ? OR name_en LIKE ? OR sku LIKE ?) AND is_active = 1
         ORDER BY name_ko LIMIT 20'
    );
    $stmt->execute([$like, $like, $like]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 활성 매핑 목록을 CSV로 내보낼 행 배열을 만든다.
 * @param PDO $pdo
 * @param array $filters ['status' => string|null]
 * @return array
 */
function fpwx_get_mappings_for_export(PDO $pdo, array $filters = []) {
    $where = [];
    $params = [];
    $status = $filters['status'] ?? 'active';
    if ($status !== 'all') {
        $where[] = 'm.status = ?';
        $params[] = $status;
    }
    $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql = "SELECT m.sales_code, sp.base_code, m.hkm_product_id, p.sku AS hkm_sku, p.name_ko AS hkm_name_ko,
                   m.match_type, m.package_type, m.units_per_sale, m.status, m.mapped_at, m.updated_at
            FROM fpwx_hkm_mappings m
            LEFT JOIN fpwx_sales_products sp ON sp.sales_code = m.sales_code
            LEFT JOIN products p ON p.id = m.hkm_product_id
            {$where_sql}
            ORDER BY m.sales_code";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 내보내기 이력을 기록한다.
 * @param PDO $pdo
 * @param int|null $exported_by
 * @param int $row_count
 * @param array $filters
 */
function fpwx_log_export(PDO $pdo, $exported_by, $row_count, array $filters) {
    $stmt = $pdo->prepare('INSERT INTO fpwx_export_logs (exported_by, row_count, filter_json) VALUES (?, ?, ?)');
    $stmt->execute([$exported_by, $row_count, json_encode($filters, JSON_UNESCAPED_UNICODE)]);
}
