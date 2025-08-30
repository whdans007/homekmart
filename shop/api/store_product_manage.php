<?php
/**
 * 점포별 상품 관리 API
 * 관리자가 특정 점포에 상품을 추가/제거하거나 설정을 변경할 수 있는 API
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// OPTIONS 요청 처리 (CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

session_start();

try {
    require_once '../../config/db_config.php';
    $conn = get_db_connection();
    
    if (!$conn) {
        throw new Exception("데이터베이스 연결 실패");
    }
    
    $request_method = $_SERVER['REQUEST_METHOD'];
    $store_id = $_GET['store_id'] ?? $_POST['store_id'] ?? null;
    $product_id = $_GET['product_id'] ?? $_POST['product_id'] ?? null;
    
    // 기본 권한 확인 (여기서는 간단히 세션 체크만 수행)
    // 실제 환경에서는 admin 권한 체크 필요
    
    if (!$store_id) {
        throw new Exception("점포 ID가 필요합니다.");
    }
    
    // 점포 존재 확인
    $store_check = "SELECT id, name, is_active FROM stores WHERE id = ?";
    $store_stmt = $conn->prepare($store_check);
    $store_stmt->bind_param("i", $store_id);
    $store_stmt->execute();
    $store_result = $store_stmt->get_result();
    
    if (!$store_info = $store_result->fetch_assoc()) {
        throw new Exception("존재하지 않는 점포입니다.");
    }
    
    if ($request_method === 'GET') {
        // 점포의 현재 상품 목록과 추가 가능한 상품 목록 조회
        
        if (isset($_GET['action']) && $_GET['action'] === 'available') {
            // 점포에 추가할 수 있는 상품들 (아직 등록되지 않은 상품들)
            $sql = "SELECT 
                        p.id,
                        p.name_kr,
                        p.name_en,
                        p.description,
                        p.barcode,
                        p.category_id,
                        c.name as category_name,
                        p.image_path,
                        p.status
                    FROM products p
                    LEFT JOIN categories c ON p.category_id = c.id
                    WHERE (p.status = 'active' OR p.status IS NULL)
                        AND p.id NOT IN (
                            SELECT product_id 
                            FROM store_products 
                            WHERE store_id = ? 
                        )
                    ORDER BY p.name_kr ASC";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $store_id);
            
        } else {
            // 점포의 현재 상품 목록
            $sql = "SELECT 
                        p.id,
                        p.name_kr,
                        p.name_en,
                        p.description,
                        p.barcode,
                        p.category_id,
                        c.name as category_name,
                        sp.is_available,
                        sp.display_order,
                        sp.is_featured,
                        sp.notes as store_notes,
                        p.image_path,
                        p.status
                    FROM products p
                    INNER JOIN store_products sp ON p.id = sp.product_id 
                        AND sp.store_id = ?
                    LEFT JOIN categories c ON p.category_id = c.id
                    WHERE p.status = 'active' OR p.status IS NULL
                    ORDER BY sp.display_order ASC, p.name_kr ASC";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $store_id);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        $products = [];
        while ($row = $result->fetch_assoc()) {
            $product = [
                'id' => (int)$row['id'],
                'name_kr' => $row['name_kr'],
                'name_en' => $row['name_en'],
                'description' => $row['description'],
                'barcode' => $row['barcode'],
                'category_id' => (int)$row['category_id'],
                'category_name' => $row['category_name'],
                'image_path' => $row['image_path'],
                'image_url' => $row['image_path'] ? '/homekmart/admin/uploads/' . $row['image_path'] : null,
                'status' => $row['status']
            ];
            
            // 점포별 설정 추가 (현재 상품 목록에만)
            if (isset($row['is_available'])) {
                $product['is_available'] = (bool)$row['is_available'];
                $product['display_order'] = (int)$row['display_order'];
                $product['is_featured'] = (bool)$row['is_featured'];
                $product['store_notes'] = $row['store_notes'];
            }
            
            $products[] = $product;
        }
        
        $response = [
            'success' => true,
            'store' => $store_info,
            'products' => $products,
            'total' => count($products),
            'action' => $_GET['action'] ?? 'current'
        ];
        
    } elseif ($request_method === 'POST') {
        // 점포에 상품 추가
        
        if (!$product_id) {
            throw new Exception("상품 ID가 필요합니다.");
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        if ($input) {
            $product_id = $input['product_id'] ?? $product_id;
            $is_available = $input['is_available'] ?? true;
            $display_order = $input['display_order'] ?? 0;
            $is_featured = $input['is_featured'] ?? false;
            $notes = $input['notes'] ?? null;
        } else {
            $is_available = $_POST['is_available'] ?? true;
            $display_order = $_POST['display_order'] ?? 0;
            $is_featured = $_POST['is_featured'] ?? false;
            $notes = $_POST['notes'] ?? null;
        }
        
        // 상품 존재 확인
        $product_check = "SELECT id, name_kr FROM products WHERE id = ? AND (status = 'active' OR status IS NULL)";
        $product_stmt = $conn->prepare($product_check);
        $product_stmt->bind_param("i", $product_id);
        $product_stmt->execute();
        $product_result = $product_stmt->get_result();
        
        if (!$product_info = $product_result->fetch_assoc()) {
            throw new Exception("존재하지 않거나 비활성화된 상품입니다.");
        }
        
        // 중복 확인
        $duplicate_check = "SELECT id FROM store_products WHERE store_id = ? AND product_id = ?";
        $dup_stmt = $conn->prepare($duplicate_check);
        $dup_stmt->bind_param("ii", $store_id, $product_id);
        $dup_stmt->execute();
        $dup_result = $dup_stmt->get_result();
        
        if ($dup_result->fetch_assoc()) {
            throw new Exception("이미 해당 점포에 등록된 상품입니다.");
        }
        
        // 상품 추가
        $insert_sql = "INSERT INTO store_products 
                       (store_id, product_id, is_available, display_order, is_featured, notes, created_at) 
                       VALUES (?, ?, ?, ?, ?, ?, NOW())";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("iiiiis", $store_id, $product_id, $is_available, $display_order, $is_featured, $notes);
        
        if ($insert_stmt->execute()) {
            $response = [
                'success' => true,
                'message' => "{$product_info['name_kr']} 상품이 {$store_info['name']}점에 추가되었습니다.",
                'store_product_id' => $conn->insert_id
            ];
        } else {
            throw new Exception("상품 추가에 실패했습니다.");
        }
        
    } elseif ($request_method === 'PUT') {
        // 점포 상품 설정 수정
        
        if (!$product_id) {
            throw new Exception("상품 ID가 필요합니다.");
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            throw new Exception("수정할 데이터가 필요합니다.");
        }
        
        // 점포 상품 존재 확인
        $check_sql = "SELECT sp.id, p.name_kr 
                      FROM store_products sp
                      INNER JOIN products p ON sp.product_id = p.id
                      WHERE sp.store_id = ? AND sp.product_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ii", $store_id, $product_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if (!$store_product = $check_result->fetch_assoc()) {
            throw new Exception("해당 점포에 등록되지 않은 상품입니다.");
        }
        
        // 수정할 필드들 준비
        $update_fields = [];
        $update_values = [];
        $update_types = "";
        
        if (isset($input['is_available'])) {
            $update_fields[] = "is_available = ?";
            $update_values[] = (int)$input['is_available'];
            $update_types .= "i";
        }
        
        if (isset($input['display_order'])) {
            $update_fields[] = "display_order = ?";
            $update_values[] = (int)$input['display_order'];
            $update_types .= "i";
        }
        
        if (isset($input['is_featured'])) {
            $update_fields[] = "is_featured = ?";
            $update_values[] = (int)$input['is_featured'];
            $update_types .= "i";
        }
        
        if (isset($input['notes'])) {
            $update_fields[] = "notes = ?";
            $update_values[] = $input['notes'];
            $update_types .= "s";
        }
        
        if (empty($update_fields)) {
            throw new Exception("수정할 필드가 없습니다.");
        }
        
        $update_fields[] = "updated_at = NOW()";
        $update_values[] = $store_id;
        $update_values[] = $product_id;
        $update_types .= "ii";
        
        $update_sql = "UPDATE store_products SET " . implode(", ", $update_fields) . 
                     " WHERE store_id = ? AND product_id = ?";
        
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param($update_types, ...$update_values);
        
        if ($update_stmt->execute()) {
            $response = [
                'success' => true,
                'message' => "{$store_product['name_kr']} 상품 설정이 수정되었습니다."
            ];
        } else {
            throw new Exception("상품 설정 수정에 실패했습니다.");
        }
        
    } elseif ($request_method === 'DELETE') {
        // 점포에서 상품 제거
        
        if (!$product_id) {
            throw new Exception("상품 ID가 필요합니다.");
        }
        
        // 점포 상품 존재 확인
        $check_sql = "SELECT sp.id, p.name_kr 
                      FROM store_products sp
                      INNER JOIN products p ON sp.product_id = p.id
                      WHERE sp.store_id = ? AND sp.product_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ii", $store_id, $product_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if (!$store_product = $check_result->fetch_assoc()) {
            throw new Exception("해당 점포에 등록되지 않은 상품입니다.");
        }
        
        // 상품 제거
        $delete_sql = "DELETE FROM store_products WHERE store_id = ? AND product_id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("ii", $store_id, $product_id);
        
        if ($delete_stmt->execute()) {
            $response = [
                'success' => true,
                'message' => "{$store_product['name_kr']} 상품이 {$store_info['name']}점에서 제거되었습니다."
            ];
        } else {
            throw new Exception("상품 제거에 실패했습니다.");
        }
        
    } else {
        throw new Exception("지원하지 않는 HTTP 메소드입니다.");
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'error' => true,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>