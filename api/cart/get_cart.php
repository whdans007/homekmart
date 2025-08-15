<?php
/**
 * 장바구니 조회 API
 */

// GET 요청만 허용
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error('Method not allowed', 405);
}

// 인증 필요
$auth = require_auth();

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 장바구니 아이템 조회
    $sql = "
        SELECT 
            sc.id as cart_id,
            sc.product_id,
            sc.store_id,
            sc.quantity,
            sc.added_at,
            p.name as product_name,
            p.description as product_description,
            p.image_url as product_image,
            p.barcode,
            c.name as category_name,
            b.name_ko as brand_name,
            s.name as store_name,
            i.selling_price,
            i.quantity as available_quantity
        FROM shopping_cart sc
        JOIN products p ON sc.product_id = p.id
        JOIN stores s ON sc.store_id = s.id
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id
        LEFT JOIN inventory i ON p.id = i.product_id AND sc.store_id = i.store_id
        WHERE sc.user_id = ?
        AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
        ORDER BY sc.added_at DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$auth['user_id']]);
    $cart_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $total_amount = 0;
    $total_items = 0;
    $stores = [];
    
    // 장바구니 아이템 처리
    foreach ($cart_items as &$item) {
        // 타입 변환
        $item['cart_id'] = (int)$item['cart_id'];
        $item['product_id'] = (int)$item['product_id'];
        $item['store_id'] = (int)$item['store_id'];
        $item['quantity'] = (int)$item['quantity'];
        $item['available_quantity'] = (int)$item['available_quantity'];
        $item['selling_price'] = (float)$item['selling_price'];
        
        // 소계 계산
        $subtotal = $item['quantity'] * $item['selling_price'];
        $item['subtotal'] = $subtotal;
        $item['formatted_price'] = format_php_currency($item['selling_price']);
        $item['formatted_subtotal'] = format_php_currency($subtotal);
        
        // 재고 확인
        $item['in_stock'] = $item['available_quantity'] >= $item['quantity'];
        $item['stock_status'] = $item['in_stock'] ? 'available' : 'insufficient';
        
        if ($item['in_stock']) {
            $total_amount += $subtotal;
        }
        
        $total_items += $item['quantity'];
        
        // 점포별 그룹화
        $store_id = $item['store_id'];
        if (!isset($stores[$store_id])) {
            $stores[$store_id] = [
                'store_id' => $store_id,
                'store_name' => $item['store_name'],
                'items' => [],
                'subtotal' => 0,
                'item_count' => 0
            ];
        }
        
        $stores[$store_id]['items'][] = $item;
        if ($item['in_stock']) {
            $stores[$store_id]['subtotal'] += $subtotal;
        }
        $stores[$store_id]['item_count'] += $item['quantity'];
    }
    
    // 점포별 배달비 계산 (기본값 사용)
    $default_delivery_fee = get_delivery_setting('default_delivery_fee', 50.0);
    $free_delivery_threshold = get_delivery_setting('free_delivery_threshold', 1000.0);
    
    foreach ($stores as &$store) {
        $store['delivery_fee'] = $store['subtotal'] >= $free_delivery_threshold ? 0 : $default_delivery_fee;
        $store['total'] = $store['subtotal'] + $store['delivery_fee'];
        $store['formatted_subtotal'] = format_php_currency($store['subtotal']);
        $store['formatted_delivery_fee'] = format_php_currency($store['delivery_fee']);
        $store['formatted_total'] = format_php_currency($store['total']);
        $store['free_delivery_remaining'] = max(0, $free_delivery_threshold - $store['subtotal']);
    }
    
    // 전체 배달비 계산
    $total_delivery_fee = array_sum(array_column($stores, 'delivery_fee'));
    $grand_total = $total_amount + $total_delivery_fee;
    
    // 응답 데이터 구성
    $response_data = [
        'cart' => [
            'items' => array_values($cart_items),
            'stores' => array_values($stores),
            'summary' => [
                'total_items' => $total_items,
                'unique_products' => count($cart_items),
                'subtotal' => $total_amount,
                'delivery_fee' => $total_delivery_fee,
                'grand_total' => $grand_total,
                'formatted_subtotal' => format_php_currency($total_amount),
                'formatted_delivery_fee' => format_php_currency($total_delivery_fee),
                'formatted_grand_total' => format_php_currency($grand_total)
            ],
            'has_out_of_stock' => count(array_filter($cart_items, function($item) {
                return !$item['in_stock'];
            })) > 0
        ],
        'settings' => [
            'currency' => [
                'code' => 'PHP',
                'symbol' => '₱'
            ],
            'delivery' => [
                'default_fee' => $default_delivery_fee,
                'free_threshold' => $free_delivery_threshold,
                'formatted_default_fee' => format_php_currency($default_delivery_fee),
                'formatted_free_threshold' => format_php_currency($free_delivery_threshold)
            ]
        ]
    ];
    
    api_success($response_data, 'Cart retrieved successfully');
    
} catch (PDOException $e) {
    log_api_error("Database error retrieving cart", [
        'user_id' => $auth['user_id'],
        'error' => $e->getMessage()
    ]);
    api_error('Failed to retrieve cart', 500, 'DATABASE_ERROR');
    
} catch (Exception $e) {
    log_api_error("Unexpected error retrieving cart", [
        'user_id' => $auth['user_id'],
        'error' => $e->getMessage()
    ]);
    api_error('Failed to retrieve cart', 500, 'UNEXPECTED_ERROR');
}
?>