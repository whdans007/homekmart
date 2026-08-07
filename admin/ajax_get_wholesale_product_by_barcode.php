<?php
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../lib/wholesale_pricing_helper.php'; // Design Ref: wholesale-cost-price-fix.design.md §4.2

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit();
}

// 도매판매 권한 확인
if (!has_permission('wholesale_management')) {
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit();
}

$barcode = $_GET['barcode'] ?? $_POST['barcode'] ?? '';
$margin_rate = (float)($_GET['margin_rate'] ?? $_POST['margin_rate'] ?? 15.0); // 기본 15% 마진

if (empty($barcode)) {
    echo json_encode(['success' => false, 'message' => '바코드를 입력해주세요.']);
    exit();
}

// 마진율 유효성 검사
if ($margin_rate < 0 || $margin_rate > 100) {
    echo json_encode(['success' => false, 'message' => '마진율은 0-100% 사이여야 합니다.']);
    exit();
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 사용자의 점포 ID 확인
    $store_id = $_SESSION['role'] === 'super_admin' ? ($_GET['store_id'] ?? $_POST['store_id'] ?? null) : ($_SESSION['store_id'] ?? null);

    if (!$store_id) {
        echo json_encode(['success' => false, 'message' => '점포 정보가 없습니다. 사용자 설정을 확인해주세요.']);
        exit();
    }

    // wholesale_products 테이블의 스키마 확인
    $check_columns = $pdo->query("SHOW COLUMNS FROM wholesale_products LIKE 'wholesale_name_ko'");
    $has_wholesale_name_columns = $check_columns->rowCount() > 0;

    // 박스/낱개 원가 표현식 (Design Ref: wholesale-cost-price-fix.design.md §4.2 — 버그 수정 A)
    // 현재 점포 원가가 0(또는 없음)이면 KIMS MALL(store_id=6) 지점 원가로 폴백 — ajax_search_wholesale_products.php와 동일 규칙
    $kims_store_id = 6;
    $cost_box_expr = "COALESCE(NULLIF(wp.cost_price,0), (SELECT kb.cost_price FROM wholesale_products kb WHERE kb.product_id = wp.product_id AND kb.store_id = {$kims_store_id} AND kb.is_active = 1 LIMIT 1), 0)";
    $cost_piece_expr = "COALESCE(NULLIF(wp.cost_price_piece,0), (SELECT kp.cost_price_piece FROM wholesale_products kp WHERE kp.product_id = wp.product_id AND kp.store_id = {$kims_store_id} AND kp.is_active = 1 LIMIT 1), 0)";

    // 바코드로 상품 조회 - 도매상품 우선, 미등록 상품도 반환
    // 1차 시도: 기본 SKU로 검색
    if ($has_wholesale_name_columns) {
        $stmt = $pdo->prepare("
            SELECT
                p.id as product_id,
                p.name_ko,
                p.name_en,
                p.sku,
                p.pieces_per_box,
                i.cost_price,
                i.selling_price,
                wp.id as wholesale_product_id,
                COALESCE(wp.wholesale_name_ko, p.name_ko) as display_name_ko,
                COALESCE(wp.wholesale_name_en, p.name_en) as display_name_en,
                wp.wholesale_price,
                COALESCE(wp.wholesale_price_piece, 0) as wholesale_price_piece,
                {$cost_box_expr} as wp_cost_box,
                {$cost_piece_expr} as wp_cost_piece,
                COALESCE(p.pieces_per_box, wp.min_quantity, 1) as min_quantity,
                wp.wholesale_skus,
                CASE
                    WHEN wp.id IS NOT NULL AND wp.is_active = 1 THEN 'registered'
                    ELSE 'unregistered'
                END as status
            FROM products p
            LEFT JOIN inventory i ON p.id = i.product_id AND i.store_id = ?
            LEFT JOIN wholesale_products wp ON p.id = wp.product_id AND wp.store_id = ? AND wp.is_active = 1
            WHERE p.is_active = 1 AND p.sku = ?
            LIMIT 1
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT
                p.id as product_id,
                p.name_ko,
                p.name_en,
                p.sku,
                p.pieces_per_box,
                i.cost_price,
                i.selling_price,
                wp.id as wholesale_product_id,
                p.name_ko as display_name_ko,
                p.name_en as display_name_en,
                wp.wholesale_price,
                COALESCE(wp.wholesale_price_piece, 0) as wholesale_price_piece,
                {$cost_box_expr} as wp_cost_box,
                {$cost_piece_expr} as wp_cost_piece,
                COALESCE(p.pieces_per_box, wp.min_quantity, 1) as min_quantity,
                NULL as wholesale_skus,
                CASE
                    WHEN wp.id IS NOT NULL AND wp.is_active = 1 THEN 'registered'
                    ELSE 'unregistered'
                END as status
            FROM products p
            LEFT JOIN inventory i ON p.id = i.product_id AND i.store_id = ?
            LEFT JOIN wholesale_products wp ON p.id = wp.product_id AND wp.store_id = ? AND wp.is_active = 1
            WHERE p.is_active = 1 AND p.sku = ?
            LIMIT 1
        ");
    }

    $stmt->execute([$store_id, $store_id, $barcode]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2차 시도: 기본 SKU로 못 찾으면 도매 SKU JSON 배열에서 검색 (새 스키마만)
    if (!$product && $has_wholesale_name_columns) {
        $stmt = $pdo->prepare("
            SELECT
                p.id as product_id,
                p.name_ko,
                p.name_en,
                p.sku,
                p.pieces_per_box,
                i.cost_price,
                i.selling_price,
                wp.id as wholesale_product_id,
                COALESCE(wp.wholesale_name_ko, p.name_ko) as display_name_ko,
                COALESCE(wp.wholesale_name_en, p.name_en) as display_name_en,
                wp.wholesale_price,
                COALESCE(wp.wholesale_price_piece, 0) as wholesale_price_piece,
                {$cost_box_expr} as wp_cost_box,
                {$cost_piece_expr} as wp_cost_piece,
                COALESCE(p.pieces_per_box, wp.min_quantity, 1) as min_quantity,
                wp.wholesale_skus,
                'registered' as status
            FROM wholesale_products wp
            INNER JOIN products p ON wp.product_id = p.id
            LEFT JOIN inventory i ON p.id = i.product_id AND i.store_id = ?
            WHERE wp.is_active = 1
                AND wp.store_id = ?
                AND p.is_active = 1
                AND wp.wholesale_skus IS NOT NULL
                AND (
                    JSON_CONTAINS(wp.wholesale_skus, ?, '$')
                    OR wp.wholesale_skus LIKE ?
                )
            LIMIT 1
        ");
        $like_barcode = '%"' . $barcode . '"%';
        $stmt->execute([$store_id, $store_id, json_encode($barcode), $like_barcode]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$product) {
        echo json_encode(['success' => false, 'message' => '해당 바코드의 상품을 찾을 수 없습니다.']);
        exit();
    }

    // 도매상품 등록 여부에 따라 가격 결정
    $is_registered = $product['status'] === 'registered';

    if ($is_registered) {
        // 등록된 도매상품 - 도매가 사용
        $unit_price = (float)$product['wholesale_price'];
        $unit_price_piece = (float)$product['wholesale_price_piece'];
    } else {
        // 미등록 상품 - 원가에 마진율 적용 또는 판매가 7% 할인
        $cost_price = (float)$product['cost_price'];
        $selling_price = (float)$product['selling_price'];

        if ($cost_price <= 0) {
            // 원가가 0이면 판매가의 7% 할인으로 계산
            if ($selling_price <= 0) {
                echo json_encode([
                    'success' => false,
                    'message' => '해당 상품의 원가와 판매가 정보가 없습니다. 인벤토리에서 가격을 먼저 등록해주세요.',
                    'debug' => [
                        'product_id' => $product['product_id'],
                        'store_id' => $store_id,
                        'cost_price' => $product['cost_price'],
                        'selling_price' => $product['selling_price']
                    ]
                ]);
                exit();
            }
            $unit_price = ceil($selling_price * 0.93); // 판매가의 7% 할인 (소숫점 이하 올림)
        } else {
            // 원가가 있으면 원가 + 마진율
            $unit_price = ceil($cost_price * (1 + $margin_rate / 100)); // 원가 + 마진율 (소숫점 이하 올림)
        }

        $unit_price_piece = 0; // 미등록 상품은 낱개가 없음

        // 미등록 상품은 원가가 낱개(inventory.cost_price) 기준 1개 값으로만 존재하므로,
        // 박스원가는 박스당 개수를 곱해 계산한다. (버그: 이전에는 이 낱개원가가 그대로 "박스원가" 자리에 채워졌음)
        // Design Ref: wholesale-cost-price-fix.design.md §3.1 원가 계산 원칙
        $product['wp_cost_piece'] = $cost_price;
        $product['wp_cost_box'] = $cost_price * (float)($product['min_quantity'] ?: 1);
    }

    // 거래처(업체)별 예외가 적용 — 등록 도매상품 + 거래처 선택 시
    $customer_id = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
    if ($is_registered && $customer_id > 0 && !empty($product['wholesale_product_id'])) {
        try {
            if ($pdo->query("SHOW TABLES LIKE 'wholesale_customer_prices'")->fetchColumn()) {
                $cps = $pdo->prepare("SELECT wholesale_price, wholesale_price_piece FROM wholesale_customer_prices WHERE customer_id = ? AND wholesale_product_id = ?");
                $cps->execute([$customer_id, (int)$product['wholesale_product_id']]);
                if ($ov = $cps->fetch(PDO::FETCH_ASSOC)) {
                    if ($ov['wholesale_price'] !== null)       $unit_price       = (float)$ov['wholesale_price'];
                    if ($ov['wholesale_price_piece'] !== null) $unit_price_piece = (float)$ov['wholesale_price_piece'];
                }
            }
        } catch (PDOException $e) { /* 예외가 미적용 — 기본가 유지 */ }
    }

    // 정상도매가/기존판매가(판매단위별)/도매등록가 3종 참고가 계산
    // Design Ref: wholesale-cost-price-fix.design.md §4.2, §4.3
    $price_ref = wp_build_price_ref(
        $pdo,
        $product['product_id'],
        $customer_id,
        $product['wp_cost_box'] ?? 0,
        $product['wp_cost_piece'] ?? 0,
        $product['selling_price'] ?? 0,
        $margin_rate,
        $product['wholesale_price'] ?? null,
        $product['wholesale_price_piece'] ?? null
    );

    // 이 거래처에 대한 과거 납품 이력 (취소 제외, 최신) — 검색/스캔 결과에 바로 노출용 (하위 호환 필드, box 우선)
    $last_sale_date = $price_ref['existing']['sale_date'];
    $last_sale_price = $price_ref['existing']['box'] ?? $price_ref['existing']['piece'];

    // 도매 SKU들 처리
    $display_skus = $product['sku'];
    if ($is_registered && $product['wholesale_skus']) {
        try {
            $skuArray = json_decode($product['wholesale_skus'], true);
            if (is_array($skuArray)) {
                $display_skus = implode(', ', $skuArray);
            }
        } catch (Exception $e) {
            // JSON 파싱 실패 시 기본 SKU 사용
        }
    }

    // 성공 응답
    echo json_encode([
        'success' => true,
        'data' => [
            'product_id' => $product['product_id'],
            'sku' => $display_skus,
            'name_ko' => $product['display_name_ko'],
            'name_en' => $product['display_name_en'],
            'cost_price' => $product['cost_price'],
            'cost_box' => (float)($product['wp_cost_box'] ?? 0),   // Design Ref: wholesale-cost-price-fix.design.md §4.2 — 원가(박스)
            'cost_piece' => (float)($product['wp_cost_piece'] ?? 0), // Design Ref: wholesale-cost-price-fix.design.md §4.2 — 원가(낱개)
            'selling_price' => $product['selling_price'],
            'wholesale_price' => $unit_price,
            'wholesale_price_piece' => $unit_price_piece,
            'min_quantity' => $product['min_quantity'] ?: 1,
            'status' => $product['status'],
            'is_registered' => $is_registered,
            'margin_rate' => $margin_rate,
            'last_sale_date' => $last_sale_date,
            'last_sale_price' => $last_sale_price,
            'price_ref' => $price_ref // Design Ref: wholesale-cost-price-fix.design.md §3.1 — 정상도매가/기존판매가/도매등록가
        ]
    ]);

} catch (PDOException $e) {
    error_log("Database error in ajax_get_wholesale_product_by_barcode.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '데이터베이스 오류가 발생했습니다.']);
} catch (Exception $e) {
    error_log("Error in ajax_get_wholesale_product_by_barcode.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '오류가 발생했습니다.']);
}
