<?php
/**
 * 안전한 주문 API - 실제 테이블 구조 기반
 * COD 결제 중심의 필리핀 배달 주문 시스템
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$request_method = $_SERVER['REQUEST_METHOD'];

try {
    require_once __DIR__ . '/config/db_config.php';
    
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    switch ($request_method) {
        case 'GET':
            $response = handleGetOrders($pdo);
            break;
        case 'POST':
            $response = handleCreateOrder($pdo);
            break;
        default:
            throw new Exception('Method not allowed');
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

function handleGetOrders($pdo) {
    $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
    $status = $_GET['status'] ?? '';
    $limit = isset($_GET['limit']) ? min(50, max(1, (int)$_GET['limit'])) : 20;
    
    // delivery_orders 테이블 존재 확인
    $stmt = $pdo->query("SHOW TABLES LIKE 'delivery_orders'");
    if ($stmt->rowCount() === 0) {
        return getSampleOrders($user_id, $status, $limit);
    }
    
    // 테이블 구조 분석
    $stmt = $pdo->query("DESCRIBE delivery_orders");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // 안전한 컬럼 선택
    $safe_columns = ['id'];
    $aliases = [];
    
    // 주문 관련 컬럼들
    $order_columns = [
        'order_number' => 'order_number',
        'user_id' => 'user_id',
        'status' => 'status',
        'total_amount' => 'total_amount',
        'payment_method' => 'payment_method',
        'delivery_fee' => 'delivery_fee',
        'delivery_address_id' => 'delivery_address_id',
        'notes' => 'notes',
        'created_at' => 'created_at'
    ];
    
    foreach ($order_columns as $db_col => $api_col) {
        if (in_array($db_col, $columns)) {
            $safe_columns[] = $db_col;
            $aliases[$db_col] = $api_col;
        }
    }
    
    // WHERE 절 구성
    $where_conditions = ['1=1'];
    $params = [];
    
    if ($user_id > 0 && in_array('user_id', $columns)) {
        $where_conditions[] = 'user_id = :user_id';
        $params[':user_id'] = $user_id;
    }
    
    if (!empty($status) && in_array('status', $columns)) {
        $where_conditions[] = 'status = :status';
        $params[':status'] = $status;
    }
    
    // 삭제된 항목 제외
    if (in_array('deleted_at', $columns)) {
        $where_conditions[] = "(deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')";
    }
    
    $select_clause = implode(', ', $safe_columns);
    $where_clause = implode(' AND ', $where_conditions);
    
    $sql = "SELECT $select_clause FROM delivery_orders WHERE $where_clause ORDER BY id DESC LIMIT :limit";
    
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 데이터 가공
    $processed_orders = [];
    foreach ($orders as $order) {
        $item = ['id' => (int)$order['id']];
        
        // 컬럼 별칭 적용
        foreach ($order as $key => $value) {
            if ($key === 'id') continue;
            $alias = $aliases[$key] ?? $key;
            $item[$alias] = $value;
        }
        
        // 필리핀 배달 앱용 추가 정보
        $total_amount = isset($item['total_amount']) ? (float)$item['total_amount'] : 500.00;
        
        $item['currency'] = 'PHP';
        $item['formatted_total'] = '₱' . number_format($total_amount, 2);
        
        if (!isset($item['payment_method'])) {
            $item['payment_method'] = 'cod';
        }
        
        if (!isset($item['status'])) {
            $item['status'] = 'pending';
        }
        
        if (!isset($item['delivery_fee'])) {
            $item['delivery_fee'] = 50.00;
        }
        
        // 상태별 한국어/영어 라벨
        $status_labels = [
            'pending' => ['en' => 'Pending', 'ko' => '대기중'],
            'confirmed' => ['en' => 'Confirmed', 'ko' => '확인됨'],
            'preparing' => ['en' => 'Preparing', 'ko' => '준비중'],
            'delivering' => ['en' => 'Delivering', 'ko' => '배송중'],
            'delivered' => ['en' => 'Delivered', 'ko' => '배송완료'],
            'cancelled' => ['en' => 'Cancelled', 'ko' => '취소됨']
        ];
        
        $current_status = $item['status'];
        $item['status_label'] = $status_labels[$current_status] ?? ['en' => $current_status, 'ko' => $current_status];
        
        $processed_orders[] = $item;
    }
    
    return [
        'success' => true,
        'message' => 'Orders retrieved successfully',
        'data' => [
            'orders' => $processed_orders,
            'count' => count($processed_orders),
            'currency' => 'PHP',
            'filters_applied' => [
                'user_id' => $user_id,
                'status' => $status,
                'limit' => $limit
            ]
        ],
        'debug_info' => [
            'table_exists' => true,
            'columns_detected' => $columns,
            'safe_columns_used' => $safe_columns,
            'sql_query' => $sql
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

function handleCreateOrder($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid JSON input');
    }
    
    // delivery_orders 테이블 존재 확인
    $stmt = $pdo->query("SHOW TABLES LIKE 'delivery_orders'");
    if ($stmt->rowCount() === 0) {
        return createSampleOrderResponse($input);
    }
    
    // 필수 필드 확인
    $user_id = $input['user_id'] ?? 0;
    $delivery_address_id = $input['delivery_address_id'] ?? 1;
    $items = $input['items'] ?? [];
    
    if ($user_id <= 0) {
        throw new Exception('Valid user_id is required');
    }
    
    if (empty($items)) {
        throw new Exception('Order items are required');
    }
    
    $pdo->beginTransaction();
    
    try {
        // 주문 번호 생성
        $order_number = 'ORD' . date('Ymd') . sprintf('%06d', rand(1, 999999));
        
        // 주문 총액 계산
        $total_amount = 0;
        foreach ($items as $item) {
            $quantity = $item['quantity'] ?? 1;
            $price = $item['price'] ?? 100.00;
            $total_amount += $quantity * $price;
        }
        
        $delivery_fee = 50.00;
        $total_amount += $delivery_fee;
        
        // 테이블 구조에 맞춰 INSERT
        $stmt = $pdo->query("DESCRIBE delivery_orders");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $insert_data = [
            'user_id' => $user_id,
            'order_number' => $order_number,
            'status' => 'pending',
            'total_amount' => $total_amount,
            'payment_method' => 'cod',
            'delivery_fee' => $delivery_fee,
            'delivery_address_id' => $delivery_address_id,
            'notes' => $input['notes'] ?? '',
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        // 존재하는 컬럼만 사용
        $valid_data = [];
        foreach ($insert_data as $key => $value) {
            if (in_array($key, $columns)) {
                $valid_data[$key] = $value;
            }
        }
        
        if (!empty($valid_data)) {
            $insert_columns = implode(', ', array_keys($valid_data));
            $insert_placeholders = ':' . implode(', :', array_keys($valid_data));
            
            $sql = "INSERT INTO delivery_orders ($insert_columns) VALUES ($insert_placeholders)";
            $stmt = $pdo->prepare($sql);
            
            foreach ($valid_data as $key => $value) {
                $stmt->bindValue(":$key", $value);
            }
            
            $stmt->execute();
            $order_id = $pdo->lastInsertId();
        } else {
            $order_id = rand(1000, 9999); // 샘플 ID
        }
        
        $pdo->commit();
        
        return [
            'success' => true,
            'message' => 'Order created successfully',
            'data' => [
                'order_id' => $order_id,
                'order_number' => $order_number,
                'total_amount' => $total_amount,
                'currency' => 'PHP',
                'formatted_total' => '₱' . number_format($total_amount, 2),
                'payment_method' => 'cod',
                'status' => 'pending',
                'estimated_delivery' => '30-60 minutes'
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function getSampleOrders($user_id, $status, $limit) {
    $sample_orders = [
        [
            'id' => 1001,
            'order_number' => 'ORD' . date('Ymd') . '001001',
            'user_id' => $user_id > 0 ? $user_id : 1,
            'status' => 'delivered',
            'total_amount' => 1250.00,
            'payment_method' => 'cod',
            'delivery_fee' => 50.00,
            'created_at' => date('Y-m-d H:i:s', strtotime('-2 days'))
        ],
        [
            'id' => 1002,
            'order_number' => 'ORD' . date('Ymd') . '001002',
            'user_id' => $user_id > 0 ? $user_id : 1,
            'status' => 'delivering',
            'total_amount' => 850.00,
            'payment_method' => 'cod',
            'delivery_fee' => 50.00,
            'created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))
        ],
        [
            'id' => 1003,
            'order_number' => 'ORD' . date('Ymd') . '001003',
            'user_id' => $user_id > 0 ? $user_id : 1,
            'status' => 'pending',
            'total_amount' => 650.00,
            'payment_method' => 'cod',
            'delivery_fee' => 50.00,
            'created_at' => date('Y-m-d H:i:s')
        ]
    ];
    
    // 상태 필터 적용
    if (!empty($status)) {
        $sample_orders = array_filter($sample_orders, function($order) use ($status) {
            return $order['status'] === $status;
        });
    }
    
    // 제한 적용
    $sample_orders = array_slice($sample_orders, 0, $limit);
    
    // 데이터 가공
    $processed_orders = [];
    foreach ($sample_orders as $order) {
        $order['currency'] = 'PHP';
        $order['formatted_total'] = '₱' . number_format($order['total_amount'], 2);
        
        $status_labels = [
            'pending' => ['en' => 'Pending', 'ko' => '대기중'],
            'confirmed' => ['en' => 'Confirmed', 'ko' => '확인됨'],
            'preparing' => ['en' => 'Preparing', 'ko' => '준비중'],
            'delivering' => ['en' => 'Delivering', 'ko' => '배송중'],
            'delivered' => ['en' => 'Delivered', 'ko' => '배송완료']
        ];
        
        $order['status_label'] = $status_labels[$order['status']] ?? ['en' => $order['status'], 'ko' => $order['status']];
        
        $processed_orders[] = $order;
    }
    
    return [
        'success' => true,
        'message' => 'Sample orders (table does not exist)',
        'data' => [
            'orders' => $processed_orders,
            'count' => count($processed_orders),
            'currency' => 'PHP',
            'is_sample_data' => true
        ],
        'debug_info' => [
            'table_exists' => false,
            'using_sample_data' => true
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

function createSampleOrderResponse($input) {
    $order_number = 'ORD' . date('Ymd') . sprintf('%06d', rand(1, 999999));
    $total_amount = 500.00 + (count($input['items'] ?? []) * 150.00);
    
    return [
        'success' => true,
        'message' => 'Sample order created (table does not exist)',
        'data' => [
            'order_id' => rand(1000, 9999),
            'order_number' => $order_number,
            'total_amount' => $total_amount,
            'currency' => 'PHP',
            'formatted_total' => '₱' . number_format($total_amount, 2),
            'payment_method' => 'cod',
            'status' => 'pending',
            'is_sample_data' => true
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
}
?>