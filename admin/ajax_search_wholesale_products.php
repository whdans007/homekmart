<?php
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/wholesale_pricing_helper.php'; // Design Ref: wholesale-cost-price-fix.design.md §4.2

// 세션 및 권한 확인
ensure_logged_in();
if (!has_permission('wholesale_management') && !has_permission('product_management')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit;
}

header('Content-Type: application/json');

$response = ['success' => false, 'products' => []];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $query = trim($_POST['q'] ?? '');
    $limit = min(max(1, (int)($_POST['limit'] ?? 10)), 100); // 1-100 범위로 제한
    $show_all = $_POST['show_all'] ?? '';
    $margin_rate = (float)($_POST['margin_rate'] ?? 15.0); // Design Ref: wholesale-cost-price-fix.design.md §4.2 — 정상도매가 계산용
    
    // 전체 목록 요청이 아니고 검색어가 너무 짧으면 종료
    if (!$show_all && strlen($query) < 2) {
        echo json_encode($response);
        exit;
    }
    
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // 현재 사용자의 점포 ID 확인
        $store_id = $_SESSION['role'] === 'super_admin' ? null : $_SESSION['store_id'] ?? null;

        // KIMS MALL (킴스몰) 지점 ID 고정 - 현재 점포 원가가 0일 때 폴백용
        $kims_store_id = 6;
        // 원가 표현식: 현재 점포 원가가 0(또는 없음)이면 KIMS MALL 지점 원가로 폴백
        $cost_box_expr = "COALESCE(NULLIF(wp.cost_price,0), (SELECT kb.cost_price FROM wholesale_products kb WHERE kb.product_id = wp.product_id AND kb.store_id = {$kims_store_id} AND kb.is_active = 1 LIMIT 1), 0)";
        $cost_piece_expr = "COALESCE(NULLIF(wp.cost_price_piece,0), (SELECT kp.cost_price_piece FROM wholesale_products kp WHERE kp.product_id = wp.product_id AND kp.store_id = {$kims_store_id} AND kp.is_active = 1 LIMIT 1), 0)";

        // 거래처(업체)별 예외가 적용 준비
        $customer_id = (int)($_POST['customer_id'] ?? 0);
        $has_cust_price = false;
        try {
            $has_cust_price = (bool)$pdo->query("SHOW TABLES LIKE 'wholesale_customer_prices'")->fetchColumn();
        } catch (PDOException $e) { /* 무시 */ }
        $use_cust = ($customer_id > 0 && $has_cust_price);
        // 거래처가 선택되면 예외가(wcp) 우선, 없으면 기본가(wp)
        $price_box_expr   = $use_cust ? "COALESCE(wcp.wholesale_price, wp.wholesale_price)" : "wp.wholesale_price";
        $price_piece_expr = $use_cust ? "COALESCE(wcp.wholesale_price_piece, wp.wholesale_price_piece, 0)" : "COALESCE(wp.wholesale_price_piece, 0)";
        $cust_join        = $use_cust ? "LEFT JOIN wholesale_customer_prices wcp ON wcp.wholesale_product_id = wp.id AND wcp.customer_id = ?" : "";

        $search_query = "%{$query}%";
        
        // 스키마 호환성 확인 후 적절한 쿼리 선택
        try {
            $check_columns = $pdo->query("SHOW COLUMNS FROM wholesale_products LIKE 'wholesale_name_ko'");
            $has_new_columns = $check_columns->rowCount() > 0;
        } catch (PDOException $e) {
            $has_new_columns = false;
        }
        
        if ($has_new_columns) {
            // 새로운 스키마 사용
            if ($show_all && empty($query)) {
                // 전체 목록 요청 - 도매상품만
                $sql = "
                    SELECT DISTINCT
                        wp.id as wholesale_product_id,
                        p.id,
                        p.sku,
                        p.name_ko,
                        p.name_en,
                        COALESCE(wp.wholesale_name_ko, p.name_ko) as display_name_ko,
                        COALESCE(wp.wholesale_name_en, p.name_en) as display_name_en,
                        wp.wholesale_skus,
                        {$price_box_expr} as wholesale_price,
                        {$price_piece_expr} as wholesale_price_piece,
                        {$cost_box_expr} as wp_cost_box,
                        {$cost_piece_expr} as wp_cost_piece,
                        COALESCE(wp.margin_rate, 0) as wp_margin_rate,
                        COALESCE(p.pieces_per_box, wp.min_quantity, 1) as min_quantity,
                        wp.wholesale_description,
                        wp.memo,
                        'registered' as status
                    FROM wholesale_products wp
                    INNER JOIN products p ON p.id = wp.product_id
                    {$cust_join}
                    WHERE wp.is_active = 1
                        AND p.is_active = 1
                        " . ($store_id ? "AND wp.store_id = ?" : "") . "
                    ORDER BY COALESCE(wp.wholesale_name_en, p.name_en) ASC,
                             COALESCE(wp.wholesale_name_ko, p.name_ko) ASC
                    LIMIT " . (int)$limit . "
                ";

                $params = [];
                if ($use_cust) {
                    $params[] = $customer_id;
                }
                if ($store_id) {
                    $params[] = $store_id;
                }
            } else {
                // 검색 요청 - 도매상품 + 미등록 일반상품
                // 거래처의 해당 상품 최근 납품 이력 (취소 제외, 최신 1건)
                $last_sale_date_expr = "
                    (SELECT ws_ls.sale_date FROM wholesale_sale_items wsi_ls
                     JOIN wholesale_sales ws_ls ON wsi_ls.sale_id = ws_ls.id
                     WHERE wsi_ls.product_id = p.id AND ws_ls.customer_id = ? AND ws_ls.status != 'cancelled'
                     ORDER BY ws_ls.sale_date DESC, ws_ls.id DESC LIMIT 1)";
                $last_sale_price_expr = "
                    (SELECT wsi_ls.unit_price FROM wholesale_sale_items wsi_ls
                     JOIN wholesale_sales ws_ls ON wsi_ls.sale_id = ws_ls.id
                     WHERE wsi_ls.product_id = p.id AND ws_ls.customer_id = ? AND ws_ls.status != 'cancelled'
                     ORDER BY ws_ls.sale_date DESC, ws_ls.id DESC LIMIT 1)";

                $sql = "
                    (
                        SELECT DISTINCT
                            wp.id as wholesale_product_id,
                            p.id,
                            p.sku,
                            p.name_ko,
                            p.name_en,
                            COALESCE(wp.wholesale_name_ko, p.name_ko) as display_name_ko,
                            COALESCE(wp.wholesale_name_en, p.name_en) as display_name_en,
                            wp.wholesale_skus,
                            {$price_box_expr} as wholesale_price,
                            {$price_piece_expr} as wholesale_price_piece,
                            {$cost_box_expr} as wp_cost_box,
                            {$cost_piece_expr} as wp_cost_piece,
                            COALESCE(wp.margin_rate, 0) as wp_margin_rate,
                            COALESCE(p.pieces_per_box, wp.min_quantity, 1) as min_quantity,
                            wp.wholesale_description,
                            wp.memo,
                            'registered' as status,
                            i.cost_price,
                            i.selling_price,
                            p.pieces_per_box as product_pieces_per_box,
                            1 as sort_priority,
                            {$last_sale_date_expr} as last_sale_date,
                            {$last_sale_price_expr} as last_sale_price
                        FROM wholesale_products wp
                        INNER JOIN products p ON p.id = wp.product_id
                        LEFT JOIN inventory i ON wp.product_id = i.product_id AND wp.store_id = i.store_id
                        {$cust_join}
                        WHERE wp.is_active = 1
                            AND p.is_active = 1
                            " . ($store_id ? "AND wp.store_id = ?" : "") . "
                            AND (
                                p.sku LIKE ? OR p.name_ko LIKE ? OR p.name_en LIKE ?
                                OR wp.wholesale_name_ko LIKE ? OR wp.wholesale_name_en LIKE ?
                                OR wp.wholesale_skus LIKE ?
                            )
                    )
                    UNION ALL
                    (
                        SELECT
                            NULL as wholesale_product_id,
                            p.id,
                            p.sku,
                            p.name_ko,
                            p.name_en,
                            p.name_ko as display_name_ko,
                            p.name_en as display_name_en,
                            NULL as wholesale_skus,
                            NULL as wholesale_price,
                            NULL as wholesale_price_piece,
                            NULL as wp_cost_box,
                            NULL as wp_cost_piece,
                            NULL as wp_margin_rate,
                            p.pieces_per_box as min_quantity,
                            NULL as wholesale_description,
                            NULL as memo,
                            'unregistered' as status,
                            MAX(i.cost_price) as cost_price,
                            MAX(i.selling_price) as selling_price,
                            p.pieces_per_box as product_pieces_per_box,
                            2 as sort_priority,
                            {$last_sale_date_expr} as last_sale_date,
                            {$last_sale_price_expr} as last_sale_price
                        FROM products p
                        LEFT JOIN inventory i ON p.id = i.product_id" . ($store_id ? " AND i.store_id = ?" : "") . "
                        WHERE p.is_active = 1
                            AND (p.sku LIKE ? OR p.name_ko LIKE ? OR p.name_en LIKE ?)
                            AND NOT EXISTS (
                                SELECT 1 FROM wholesale_products wp2
                                WHERE wp2.product_id = p.id
                                AND wp2.is_active = 1
                                " . ($store_id ? "AND wp2.store_id = ?" : "") . "
                            )
                        GROUP BY p.id, p.sku, p.name_ko, p.name_en, p.pieces_per_box
                    )
                    ORDER BY
                        sort_priority ASC,
                        CASE
                            WHEN sku LIKE ? THEN 1
                            WHEN display_name_en LIKE ? THEN 2
                            WHEN display_name_ko LIKE ? THEN 3
                            ELSE 4
                        END,
                        display_name_en ASC,
                        display_name_ko ASC
                    LIMIT " . (int)$limit . "
                ";

                $params = [];
                $params[] = $customer_id; // 첫 SELECT 의 last_sale_date 서브쿼리
                $params[] = $customer_id; // 첫 SELECT 의 last_sale_price 서브쿼리
                if ($use_cust) {
                    $params[] = $customer_id; // 첫 SELECT 의 wcp JOIN
                }
                if ($store_id) {
                    $params[] = $store_id;
                }
                $params = array_merge($params, [
                    $search_query, $search_query, $search_query, // 등록된 도매상품 검색 - 기본 상품
                    $search_query, $search_query, $search_query, // 등록된 도매상품 검색 - 도매 상품
                ]);
                $params[] = $customer_id; // 두번째 SELECT 의 last_sale_date 서브쿼리
                $params[] = $customer_id; // 두번째 SELECT 의 last_sale_price 서브쿼리
                if ($store_id) {
                    $params[] = $store_id; // 미등록 상품 inventory JOIN store_id
                }
                $params = array_merge($params, [
                    $search_query, $search_query, $search_query, // 미등록 일반상품 검색
                ]);
                if ($store_id) {
                    $params[] = $store_id; // 미등록 상품 store_id 조건
                }
                $params = array_merge($params, [
                    $search_query, $search_query, $search_query  // ORDER BY 조건
                ]);
            }
        } else {
            // 기존 스키마 사용 (하위 호환성)
            if ($show_all && empty($query)) {
                // 전체 목록 요청
                $sql = "
                    SELECT DISTINCT
                        wp.id as wholesale_product_id,
                        p.id,
                        p.sku,
                        p.name_ko,
                        p.name_en,
                        p.name_ko as display_name_ko,
                        p.name_en as display_name_en,
                        JSON_ARRAY(p.sku) as wholesale_skus,
                        wp.wholesale_price,
                        COALESCE(wp.wholesale_price_piece, 0) as wholesale_price_piece,
                        COALESCE(p.pieces_per_box, wp.min_quantity, 1) as min_quantity,
                        NULL as wholesale_description
                    FROM wholesale_products wp
                    INNER JOIN products p ON p.id = wp.product_id 
                    WHERE wp.is_active = 1 
                        AND p.is_active = 1 
                        " . ($store_id ? "AND wp.store_id = ?" : "") . "
                    ORDER BY p.name_en ASC, p.name_ko ASC
                    LIMIT " . (int)$limit . "
                ";
                
                $params = [];
                if ($store_id) {
                    $params[] = $store_id;
                }
            } else {
                // 검색 요청
                $sql = "
                    SELECT DISTINCT
                        wp.id as wholesale_product_id,
                        p.id,
                        p.sku,
                        p.name_ko,
                        p.name_en,
                        p.name_ko as display_name_ko,
                        p.name_en as display_name_en,
                        JSON_ARRAY(p.sku) as wholesale_skus,
                        wp.wholesale_price,
                        COALESCE(wp.wholesale_price_piece, 0) as wholesale_price_piece,
                        COALESCE(p.pieces_per_box, wp.min_quantity, 1) as min_quantity,
                        NULL as wholesale_description
                    FROM wholesale_products wp
                    INNER JOIN products p ON p.id = wp.product_id 
                    WHERE wp.is_active = 1 
                        AND p.is_active = 1 
                        " . ($store_id ? "AND wp.store_id = ?" : "") . "
                        AND (p.sku LIKE ? OR p.name_ko LIKE ? OR p.name_en LIKE ?)
                    ORDER BY 
                        CASE 
                            WHEN p.sku LIKE ? THEN 1 
                            WHEN p.name_en LIKE ? THEN 2 
                            WHEN p.name_ko LIKE ? THEN 3 
                            ELSE 4 
                        END,
                        p.name_en ASC, p.name_ko ASC
                    LIMIT " . (int)$limit . "
                ";
                
                $params = [];
                if ($store_id) {
                    $params[] = $store_id;
                }
                $params = array_merge($params, [
                    $search_query, $search_query, $search_query, // WHERE 조건
                    $search_query, $search_query, $search_query  // ORDER BY 조건
                ]);
            }
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 정상도매가/기존판매가/도매등록가 3종 참고가 계산 — 검색 결과(전체목록 아님)에만 적용
        // Design Ref: wholesale-cost-price-fix.design.md §4.2, §11.2 (module-2)
        if (!($show_all && empty($query))) {
            foreach ($products as &$__p) {
                // 미등록 일반상품은 원가가 낱개(inventory.cost_price) 기준 1개 값으로만 존재하므로,
                // 박스원가는 박스당 개수를 곱해 계산한다 (버그: 이전에는 낱개원가가 그대로 박스원가 자리에 채워졌음)
                // Design Ref: wholesale-cost-price-fix.design.md §3.1 원가 계산 원칙
                if (($__p['status'] ?? '') === 'unregistered') {
                    $piece_cost = (float)($__p['cost_price'] ?? 0);
                    $pieces_per_box = (float)($__p['product_pieces_per_box'] ?? $__p['min_quantity'] ?? 1) ?: 1;
                    $__p['wp_cost_piece'] = $piece_cost;
                    $__p['wp_cost_box'] = $piece_cost * $pieces_per_box;
                }

                $__p['price_ref'] = wp_build_price_ref(
                    $pdo,
                    $__p['id'] ?? 0,
                    $customer_id,
                    $__p['wp_cost_box'] ?? 0,
                    $__p['wp_cost_piece'] ?? 0,
                    $__p['selling_price'] ?? 0,
                    $margin_rate,
                    $__p['wholesale_price'] ?? null,
                    $__p['wholesale_price_piece'] ?? null
                );
            }
            unset($__p);
        }

        if (!empty($products)) {
            $response['success'] = true;
            $response['products'] = $products;
        }
        
    } catch (PDOException $e) {
        $response['message'] = '검색 중 오류가 발생했습니다.';
    }
}

echo json_encode($response);
?>