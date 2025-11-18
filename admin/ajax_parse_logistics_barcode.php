<?php
/**
 * 물류바코드 파싱 및 상품 검색 AJAX 엔드포인트
 *
 * 박스 물류바코드를 분석하여 개별 상품 바코드를 추출하고 해당 상품을 찾습니다.
 * 데이터베이스 컬럼 추가 없이 바코드 패턴 분석으로 처리합니다.
 *
 * 지원하는 바코드 패턴:
 * 1. ITF-14 (14자리): 상품 EAN-13 앞에 포장 지시자(1자리) 추가
 *    예: 18801234567897 → 8801234567890
 *
 * 2. 접두사 패턴: BOX-, CASE-, CTN- 등
 *    예: BOX-8801234567890 → 8801234567890
 *
 * 3. 접미사 패턴: 수량 표시
 *    예: 8801234567890-10 → 8801234567890 (10개입)
 *
 * 4. GS1-128: AI(01) 포함 GTIN
 *    예: (01)18801234567897 → 8801234567890
 */

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';

header('Content-Type: application/json');

// 로그인 체크
if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit();
}

// 물류바코드 파라미터 확인
$logistics_barcode = $_GET['logistics_barcode'] ?? $_POST['logistics_barcode'] ?? '';

if (empty($logistics_barcode)) {
    echo json_encode(['success' => false, 'message' => '물류바코드를 입력해주세요.']);
    exit();
}

/**
 * 물류바코드에서 상품 바코드를 추출하는 함수
 *
 * @param string $barcode 물류바코드
 * @return array 가능한 상품 바코드 배열
 */
function extractProductBarcodes($barcode) {
    $candidates = [];
    $barcode = trim($barcode);

    // 1. 원본 바코드도 후보에 추가 (물류바코드가 곧 상품바코드인 경우)
    $candidates[] = $barcode;

    // 2. ITF-14 패턴 (14자리 → 13자리 EAN-13)
    if (strlen($barcode) === 14 && ctype_digit($barcode)) {
        // ITF-14 = [포장지시자 1자리][EAN-13 13자리]
        // 첫 자리만 제거하여 13자리 EAN-13 추출
        $ean13 = substr($barcode, 1, 13);
        $candidates[] = $ean13;

        // 추가로 마지막 체크 디지트가 다를 수 있으므로 12자리도 시도
        $middle12 = substr($barcode, 1, 12);
        $candidates[] = $middle12;
    }

    // 3. 접두사 제거 패턴 (BOX-, CASE-, CTN-, PACK- 등)
    $prefixes = ['BOX-', 'CASE-', 'CTN-', 'PACK-', 'BX-', 'CS-'];
    foreach ($prefixes as $prefix) {
        if (stripos($barcode, $prefix) === 0) {
            $extracted = substr($barcode, strlen($prefix));
            $candidates[] = $extracted;
        }
    }

    // 4. 접미사 제거 패턴 (수량 표시: -10, -12, -24 등)
    if (preg_match('/^(.+)-(\d+)$/', $barcode, $matches)) {
        $candidates[] = $matches[1]; // 수량 앞부분
    }

    // 5. GS1-128 패턴: (01)으로 시작하는 GTIN
    if (preg_match('/\(01\)(\d{14})/', $barcode, $matches)) {
        $gtin14 = $matches[1];
        // GTIN-14를 GTIN-13으로 변환 (첫 자리 제거)
        $candidates[] = substr($gtin14, 1, 13);
        $candidates[] = $gtin14;
    }

    // 6. 괄호나 특수문자 제거
    $cleaned = preg_replace('/[^0-9]/', '', $barcode);
    if ($cleaned !== $barcode && strlen($cleaned) >= 8) {
        $candidates[] = $cleaned;

        // 숫자만 남긴 후 14자리면 13자리로 변환 시도
        if (strlen($cleaned) === 14) {
            $candidates[] = substr($cleaned, 1, 13);
        }
    }

    // 7. 13자리 숫자 추출 (어디든 13자리 연속 숫자가 있으면)
    if (preg_match('/(\d{13})/', $barcode, $matches)) {
        $candidates[] = $matches[1];
    }

    // 중복 제거 및 유효성 검사
    $candidates = array_unique($candidates);
    $valid_candidates = [];

    foreach ($candidates as $candidate) {
        // 최소 8자리 이상의 숫자형 바코드만 유효
        if (strlen($candidate) >= 8 && preg_match('/^\d+$/', $candidate)) {
            $valid_candidates[] = $candidate;
        }
    }

    return $valid_candidates;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 물류바코드에서 가능한 상품 바코드들 추출
    $product_barcodes = extractProductBarcodes($logistics_barcode);

    if (empty($product_barcodes)) {
        echo json_encode([
            'success' => false,
            'message' => '물류바코드에서 상품 바코드를 추출할 수 없습니다.',
            'barcode_input' => $logistics_barcode
        ]);
        exit();
    }

    // 추출된 바코드들로 상품 검색 (SKU 컬럼에서)
    error_log('물류바코드 검색 - 추출된 바코드: ' . implode(', ', $product_barcodes));

    // 1차 시도: 정확히 일치하는 SKU 검색
    $placeholders = str_repeat('?,', count($product_barcodes) - 1) . '?';
    $sql = "
        SELECT
            p.id,
            p.name_ko,
            p.name_en,
            p.sku,
            p.pieces_per_box,
            p.brand_id,
            p.category_id,
            p.is_vat_applicable,
            c.name as category_name,
            b.name_ko as brand_name_ko,
            b.name_en as brand_name_en
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id
        WHERE p.sku IN ($placeholders)
        LIMIT 1
    ";

    error_log('물류바코드 검색 SQL (정확 매칭): ' . $sql);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($product_barcodes);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2차 시도: 정확 매칭 실패 시 LIKE 패턴 검색
    if (!$product) {
        // 13자리 바코드에서 마지막 체크 디지트 부분만 변경 가능하므로 앞 12자리로 LIKE 검색
        $thirteen_digit_codes = array_filter($product_barcodes, function($code) {
            return strlen($code) === 13 && ctype_digit($code);
        });

        if (!empty($thirteen_digit_codes)) {
            error_log('물류바코드 검색 - 13자리 코드로 LIKE 검색 (체크디지트 변형): ' . implode(', ', $thirteen_digit_codes));

            $like_conditions = [];
            $like_params = [];
            foreach ($thirteen_digit_codes as $code) {
                $like_conditions[] = "p.sku LIKE ?";
                // 앞 12자리는 동일하고 마지막 1자리(체크디지트)만 다를 수 있음
                $like_params[] = substr($code, 0, 12) . '_';
            }

            $like_sql = "
                SELECT
                    p.id,
                    p.name_ko,
                    p.name_en,
                    p.sku,
                    p.pieces_per_box,
                    p.brand_id,
                    p.category_id,
                    p.is_vat_applicable,
                    c.name as category_name,
                    b.name_ko as brand_name_ko,
                    b.name_en as brand_name_en
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN brands b ON p.brand_id = b.id
                WHERE " . implode(' OR ', $like_conditions) . "
                LIMIT 1
            ";

            error_log('물류바코드 검색 SQL (LIKE 패턴): ' . $like_sql);
            $like_stmt = $pdo->prepare($like_sql);
            $like_stmt->execute($like_params);
            $product = $like_stmt->fetch(PDO::FETCH_ASSOC);
        }
    }

    if ($product) {
        // 성공: 상품 정보 반환
        echo json_encode([
            'success' => true,
            'data' => $product,
            'message' => '물류바코드 분석 성공: ' . $product['name_ko'],
            'barcode_input' => $logistics_barcode,
            'extracted_barcodes' => $product_barcodes,
            'matched_sku' => $product['sku']
        ]);
    } else {
        // 실패: 해당 바코드의 상품이 없음
        echo json_encode([
            'success' => false,
            'message' => '물류바코드로 상품을 찾을 수 없습니다.',
            'barcode_input' => $logistics_barcode,
            'extracted_barcodes' => $product_barcodes,
            'hint' => '추출된 바코드: ' . implode(', ', $product_barcodes)
        ]);
    }

} catch (PDOException $e) {
    // 데이터베이스 오류
    error_log('물류바코드 검색 오류: ' . $e->getMessage());
    error_log('SQL State: ' . $e->getCode());
    error_log('Stack trace: ' . $e->getTraceAsString());

    echo json_encode([
        'success' => false,
        'message' => '데이터베이스 오류가 발생했습니다.',
        'error_details' => $e->getMessage(),
        'barcode_input' => $logistics_barcode
    ]);
} catch (Exception $e) {
    // 일반 오류
    error_log('물류바코드 처리 오류: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());

    echo json_encode([
        'success' => false,
        'message' => '오류가 발생했습니다: ' . $e->getMessage(),
        'barcode_input' => $logistics_barcode
    ]);
}
