<?php
// 오류 로깅 활성화
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 상품명 업데이트 처리 - 다른 HTML 출력 전에 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_product_name') {
    session_start();
    require_once __DIR__ . '/../config/db_config.php';
    
    header('Content-Type: application/json');
    
    $purchase_id = $_POST['purchase_id'] ?? $_GET['id'] ?? null;
    $product_id = (int)$_POST['product_id'];
    $item_id = (int)$_POST['item_id'];  
    $new_name = trim($_POST['new_name']);
    $field_type = $_POST['field_type'] ?? 'ko'; // 'ko' 또는 'en'
    
    // 입력 검증
    if (empty($new_name)) {
        $error_msg = $field_type === 'en' ? '영문 상품명을 입력해주세요.' : '상품명을 입력해주세요.';
        echo json_encode(['success' => false, 'error' => $error_msg]);
        exit;
    }
    
    if (strlen($new_name) > 255) {
        $error_msg = $field_type === 'en' ? '영문 상품명이 너무 깁니다. (최대 255자)' : '상품명이 너무 깁니다. (최대 255자)';
        echo json_encode(['success' => false, 'error' => $error_msg]);
        exit;
    }
    
    try {
        $conn = get_db_connection();
        $conn->begin_transaction();
        
        // 권한 검증 - 해당 매입 아이템이 존재하고 올바른 매입에 속하는지 확인
        $auth_stmt = $conn->prepare("
            SELECT pi.item_id 
            FROM purchase_items pi 
            JOIN purchases p ON pi.purchase_id = p.purchase_id
            WHERE pi.item_id = ? AND p.purchase_id = ?
        ");
        $auth_stmt->bind_param("ii", $item_id, $purchase_id);
        $auth_stmt->execute();
        $auth_result = $auth_stmt->get_result();
        $auth_stmt->close();
        
        if ($auth_result->num_rows === 0) {
            throw new Exception('권한이 없습니다.');
        }
        
        // 상품명 업데이트 (한글 또는 영문)
        $field_name = $field_type === 'en' ? 'name_en' : 'name_ko';
        $update_stmt = $conn->prepare("UPDATE products SET {$field_name} = ? WHERE id = ?");
        $update_stmt->bind_param("si", $new_name, $product_id);
        
        if ($update_stmt->execute()) {
            $conn->commit();
            $update_stmt->close();
            $conn->close();
            $success_msg = $field_type === 'en' ? '영문 상품명이 성공적으로 수정되었습니다.' : '상품명이 성공적으로 수정되었습니다.';
            echo json_encode(['success' => true, 'message' => $success_msg]);
        } else {
            $error_msg = $field_type === 'en' ? '영문 상품명 업데이트에 실패했습니다.' : '상품명 업데이트에 실패했습니다.';
            throw new Exception($error_msg);
        }
        
    } catch (Exception $e) {
        if (isset($conn)) {
            $conn->rollback();
            $conn->close();
        }
        if (isset($update_stmt)) $update_stmt->close();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('purchase.edit_purchase_title');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

if (!is_logged_in() || !in_array($_SESSION['role'], ['super_admin', 'admin'])) {
    echo "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative' role='alert'><strong class='font-bold'>" . t('purchase.access_denied_title') . ":</strong><span class='block sm:inline'> " . t('purchase.access_denied') . "</span></div>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

$purchase_id = $_GET['id'] ?? null;
if (!$purchase_id) {
    header('Location: purchase_management.php');
    exit();
}

$conn = get_db_connection();
$message = '';
$message_type = '';

// 할인 관련 컬럼 자동 추가 (한번만 실행)
try {
    // discount_rate 컬럼 체크 및 추가
    $check_discount_rate = $conn->query("SHOW COLUMNS FROM purchase_items LIKE 'discount_rate'");
    if ($check_discount_rate->num_rows == 0) {
        $conn->query("ALTER TABLE purchase_items ADD COLUMN discount_rate DECIMAL(5,2) DEFAULT 0.00 COMMENT 'Discount Rate (%)' AFTER unit_price");
    }
    
    // discounted_total 컬럼 체크 및 추가
    $check_discounted_total = $conn->query("SHOW COLUMNS FROM purchase_items LIKE 'discounted_total'");
    if ($check_discounted_total->num_rows == 0) {
        $conn->query("ALTER TABLE purchase_items ADD COLUMN discounted_total DECIMAL(10,2) DEFAULT NULL COMMENT 'Discounted Total' AFTER discount_rate");
        // 기존 데이터 초기화
        $conn->query("UPDATE purchase_items SET discounted_total = (quantity * unit_price) WHERE discounted_total IS NULL");
    }
} catch (Exception $e) {
}

// 전체 매입 내역 삭제 처리 (Soft Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_purchase') {
    try {
        $conn->begin_transaction();
        
        // 현재 사용자 정보 확인
        $user_stmt = $conn->prepare("SELECT store_id FROM users WHERE id = ?");
        $user_stmt->bind_param("i", $_SESSION['user_id']);
        $user_stmt->execute();
        $user_info = $user_stmt->get_result()->fetch_assoc();
        $user_stmt->close();
        
        if ($user_info) {
            // 모든 매입 상품들의 재고를 원복
            $items_stmt = $conn->prepare("
                SELECT pi.*, pr.pieces_per_box 
                FROM purchase_items pi 
                JOIN products pr ON pi.product_id = pr.id
                WHERE pi.purchase_id = ?
            ");
            $items_stmt->bind_param("i", $purchase_id);
            $items_stmt->execute();
            $items_result = $items_stmt->get_result();
            
            // 디버깅: 아이템 수 로그
            $total_items = $items_result->num_rows;
            
            $item_count = 0;
            while ($item = $items_result->fetch_assoc()) {
                $item_count++;
                try {
                    // 실제 입고 수량 계산
                    $actual_quantity = (int)$item['quantity'];
                    if ($item['purchase_type'] === 'box') {
                        $pieces_per_box = $item['pieces_per_box'] ?? 1;
                        $actual_quantity = (int)$item['quantity'] * $pieces_per_box;
                    }
                    
                    // 재고에서 해당 수량 차감 (매입 삭제이므로 입고된 수량을 다시 빼야 함)
                    if ($user_info['store_id'] && $actual_quantity > 0) {
                        // inventory 테이블에 해당 레코드가 존재하는지 먼저 확인
                        $check_inv_stmt = $conn->prepare("SELECT id, quantity FROM inventory WHERE product_id = ? AND store_id = ?");
                        $check_inv_stmt->bind_param("ii", $item['product_id'], $user_info['store_id']);
                        $check_inv_stmt->execute();
                        $inv_result = $check_inv_stmt->get_result();
                        $inventory_record = $inv_result->fetch_assoc();
                        $check_inv_stmt->close();
                        
                        if ($inventory_record) {
                            // 재고 업데이트 (음수가 되지 않도록 처리)
                            $new_quantity = max(0, $inventory_record['quantity'] - $actual_quantity);
                            $inv_stmt = $conn->prepare("UPDATE inventory SET quantity = ? WHERE product_id = ? AND store_id = ?");
                            $inv_stmt->bind_param("iii", $new_quantity, $item['product_id'], $user_info['store_id']);
                            
                            if (!$inv_stmt->execute()) {
                                throw new Exception("재고 업데이트 실패 - Product ID: {$item['product_id']}, Error: " . $inv_stmt->error);
                            }
                            $inv_stmt->close();
                            
                            // 재고 트랜잭션 로그 기록
                            try {
                                $trans_stmt = $conn->prepare("INSERT INTO inventory_transactions (inventory_id, user_id, transaction_type, quantity_change, remarks) VALUES (?, ?, 'OUT', ?, ?)");
                                $remarks = "매입 내역 삭제 (Purchase ID: {$purchase_id}, Item: {$item_count})";
                                $quantity_change = -$actual_quantity;
                                $trans_stmt->bind_param("iiss", $inventory_record['id'], $_SESSION['user_id'], $quantity_change, $remarks);
                                $trans_stmt->execute();
                                $trans_stmt->close();
                            } catch (Exception $log_error) {
                                // 로그 실패는 전체 트랜잭션을 중단하지 않음
                            }
                        } else {
                            // 재고 레코드가 없어도 매입 삭제는 계속 진행
                        }
                    }
                } catch (Exception $item_error) {
                    throw new Exception("아이템 #{$item_count} 처리 중 오류: " . $item_error->getMessage());
                }
            }
            $items_stmt->close();
            
            // purchases 테이블에 deleted_at 컬럼이 있는지 확인하고 없으면 추가
            $check_deleted_at = $conn->query("SHOW COLUMNS FROM purchases LIKE 'deleted_at'");
            $has_deleted_at_col = $check_deleted_at->num_rows > 0;
            
            if (!$has_deleted_at_col) {
                $conn->query("ALTER TABLE purchases ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL");
            }
            
            // deleted_by_user_id 컬럼이 없으면 추가
            $check_deleted_by = $conn->query("SHOW COLUMNS FROM purchases LIKE 'deleted_by_user_id'");
            $has_deleted_by_col = $check_deleted_by->num_rows > 0;
            
            if (!$has_deleted_by_col) {
                $conn->query("ALTER TABLE purchases ADD COLUMN deleted_by_user_id INT DEFAULT NULL");
            }
            
            // Soft Delete: deleted_at 컬럼에 현재 시간 설정
            // 컬럼 추가 후 다시 확인
            $check_deleted_at_final = $conn->query("SHOW COLUMNS FROM purchases LIKE 'deleted_at'");
            $check_deleted_by_final = $conn->query("SHOW COLUMNS FROM purchases LIKE 'deleted_by_user_id'");
            
            if ($check_deleted_at_final->num_rows > 0 && $check_deleted_by_final->num_rows > 0) {
                $soft_delete_stmt = $conn->prepare("UPDATE purchases SET deleted_at = NOW(), deleted_by_user_id = ? WHERE purchase_id = ?");
                $soft_delete_stmt->bind_param("ii", $_SESSION['user_id'], $purchase_id);
            } else if ($check_deleted_at_final->num_rows > 0) {
                $soft_delete_stmt = $conn->prepare("UPDATE purchases SET deleted_at = NOW() WHERE purchase_id = ?");
                $soft_delete_stmt->bind_param("i", $purchase_id);
            } else {
                throw new Exception('deleted_at 컬럼을 생성할 수 없습니다.');
            }
            $soft_delete_stmt->execute();
            $soft_delete_stmt->close();
            
            $conn->commit();
            $message = t('purchase.js_delete_success');
            $message_type = 'success';
            
            // 삭제 후 목록으로 리다이렉트
            header('Location: purchase_management.php?message=' . urlencode($message));
            exit();
        } else {
            throw new Exception('사용자 정보를 찾을 수 없습니다.');
        }
    } catch (Exception $e) {
        $conn->rollback();
        $error_msg = $e->getMessage();
        
        // 사용자에게 더 자세한 오류 정보 제공
        if (strpos($error_msg, '아이템') !== false) {
            $message = t('purchase.js_delete_error') . ': ' . $error_msg;
        } else if (strpos($error_msg, 'deleted_at') !== false) {
            $message = '데이터베이스 구조 오류입니다. Soft Delete 설정을 다시 실행해 주세요.';
        } else {
            $message = t('purchase.js_delete_error') . ': ' . $error_msg;
        }
        $message_type = 'error';
    }
}

// 여러 매입 상품 일괄 수정 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_multiple_items') {
    if (isset($_POST['items']) && is_array($_POST['items'])) {
        try {
            $conn->begin_transaction();
            
            $updated_count = 0;
            $errors = [];
            
            foreach ($_POST['items'] as $item_data) {
                $item_id = (int)$item_data['item_id'];
                $new_quantity = (int)$item_data['quantity'];
                $new_unit_price = (float)$item_data['unit_price'];
                $new_purchase_type = $item_data['purchase_type'] ?? 'piece';
                $new_pieces_per_box = isset($item_data['pieces_per_box']) ? (int)$item_data['pieces_per_box'] : null;
                $discount_rate = isset($item_data['discount_rate']) ? (float)$item_data['discount_rate'] : 0.00;
                
                // 기존 아이템 정보 조회
                $item_stmt = $conn->prepare("
                    SELECT pi.*, pr.pieces_per_box, u.store_id 
                    FROM purchase_items pi 
                    JOIN products pr ON pi.product_id = pr.id
                    JOIN purchases p ON pi.purchase_id = p.purchase_id
                    JOIN users u ON u.id = ?
                    WHERE pi.item_id = ? AND pi.purchase_id = ?
                ");
                $item_stmt->bind_param("iii", $_SESSION['user_id'], $item_id, $purchase_id);
                $item_stmt->execute();
                $old_info = $item_stmt->get_result()->fetch_assoc();
                $item_stmt->close();
                
                if ($old_info) {
                    // 기존 실제 입고 수량 계산
                    $old_actual_quantity = (int)$old_info['quantity'];
                    if ($old_info['purchase_type'] === 'box') {
                        $old_actual_quantity = (int)$old_info['quantity'] * ($old_info['pieces_per_box'] ?? 1);
                    }
                    
                    // 새로운 실제 입고 수량 계산
                    $new_actual_quantity = $new_quantity;
                    if ($new_purchase_type === 'box') {
                        $new_actual_quantity = $new_quantity * ($old_info['pieces_per_box'] ?? 1);
                    }
                    
                    // 재고 수량 조정
                    if ($old_info['store_id']) {
                        $quantity_diff = $new_actual_quantity - $old_actual_quantity;
                        
                        if ($quantity_diff != 0) {
                            $inv_stmt = $conn->prepare("UPDATE inventory SET quantity = quantity + ? WHERE product_id = ? AND store_id = ?");
                            $inv_stmt->bind_param("iii", $quantity_diff, $old_info['product_id'], $old_info['store_id']);
                            $inv_stmt->execute();
                            $inv_stmt->close();
                            
                            // 재고 트랜잭션 로그 기록
                            try {
                                $inv_id_stmt = $conn->prepare("SELECT id FROM inventory WHERE product_id = ? AND store_id = ?");
                                $inv_id_stmt->bind_param("ii", $old_info['product_id'], $old_info['store_id']);
                                $inv_id_stmt->execute();
                                $inv_id_result = $inv_id_stmt->get_result();
                                
                                if ($inv_row = $inv_id_result->fetch_assoc()) {
                                    $trans_type = $quantity_diff > 0 ? 'IN' : 'OUT';
                                    $trans_stmt = $conn->prepare("INSERT INTO inventory_transactions (inventory_id, user_id, transaction_type, quantity_change, remarks) VALUES (?, ?, ?, ?, ?)");
                                    $remarks = "매입 상품 일괄 수정 (Purchase Item ID: {$item_id})";
                                    $trans_stmt->bind_param("iisis", $inv_row['id'], $_SESSION['user_id'], $trans_type, $quantity_diff, $remarks);
                                    $trans_stmt->execute();
                                    $trans_stmt->close();
                                }
                                $inv_id_stmt->close();
                            } catch (Exception $log_error) {
                            }
                        }
                    }
                    
                    // 상품의 박스 수량도 업데이트 (제공된 경우)
                    if ($new_pieces_per_box !== null && $new_pieces_per_box > 0) {
                        $update_product_stmt = $conn->prepare("UPDATE products SET pieces_per_box = ? WHERE id = ?");
                        $update_product_stmt->bind_param("ii", $new_pieces_per_box, $old_info['product_id']);
                        $update_product_stmt->execute();
                        $update_product_stmt->close();
                    }
                    
                    // 할인 정보 계산
                    $original_total = $new_quantity * $new_unit_price;
                    $discounted_total = $original_total * (1 - $discount_rate / 100);
                    
                    // 매입 상품 정보 업데이트
                    $update_item_stmt = $conn->prepare("UPDATE purchase_items SET quantity = ?, unit_price = ?, purchase_type = ?, discount_rate = ?, discounted_total = ? WHERE item_id = ?");
                    $update_item_stmt->bind_param("idsidi", $new_quantity, $new_unit_price, $new_purchase_type, $discount_rate, $discounted_total, $item_id);
                    $update_item_stmt->execute();
                    $update_item_stmt->close();
                    
                    $updated_count++;
                } else {
                    $errors[] = "상품 ID {$item_id}를 찾을 수 없습니다.";
                }
            }
            
            // 매입 전체 합계 재계산 및 업데이트
            $update_stmt = $conn->prepare("
                UPDATE purchases SET 
                    total_amount = (SELECT COALESCE(SUM(CASE WHEN discounted_total IS NOT NULL THEN discounted_total ELSE quantity * unit_price END), 0) FROM purchase_items WHERE purchase_id = ?),
                    total_items = (SELECT COUNT(*) FROM purchase_items WHERE purchase_id = ?)
                WHERE purchase_id = ?
            ");
            $update_stmt->bind_param("iii", $purchase_id, $purchase_id, $purchase_id);
            $update_stmt->execute();
            $update_stmt->close();
            
            $conn->commit();
            
            if (count($errors) > 0) {
                $message = "{$updated_count}개 상품이 수정되었습니다. 오류: " . implode(", ", $errors);
                $message_type = 'warning';
            } else {
                $message = "{$updated_count}개 상품이 성공적으로 수정되었습니다.";
                $message_type = 'success';
            }
        } catch (Exception $e) {
            $conn->rollback();
            $message = '상품 일괄 수정에 실패했습니다: ' . $e->getMessage();
            $message_type = 'error';
        }
    }
}

// 매입 상품 삭제 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete_item' && isset($_POST['item_id'])) {
        $item_id = (int)$_POST['item_id'];
        
        try {
            $conn->begin_transaction();
            
            // 삭제할 아이템 정보 조회 (재고 업데이트를 위해)
            $item_stmt = $conn->prepare("
                SELECT pi.*, pr.pieces_per_box, u.store_id 
                FROM purchase_items pi 
                JOIN products pr ON pi.product_id = pr.id
                JOIN purchases p ON pi.purchase_id = p.purchase_id
                JOIN users u ON u.id = ?
                WHERE pi.item_id = ? AND pi.purchase_id = ?
            ");
            $item_stmt->bind_param("iii", $_SESSION['user_id'], $item_id, $purchase_id);
            $item_stmt->execute();
            $item_info = $item_stmt->get_result()->fetch_assoc();
            $item_stmt->close();
            
            if ($item_info) {
                // 실제 입고된 수량 계산
                $actual_quantity = (int)$item_info['quantity'];
                if ($item_info['purchase_type'] === 'box') {
                    $actual_quantity = (int)$item_info['quantity'] * ($item_info['pieces_per_box'] ?? 1);
                }
                
                // 재고에서 해당 수량 차감
                if ($item_info['store_id']) {
                    $inv_stmt = $conn->prepare("UPDATE inventory SET quantity = quantity - ? WHERE product_id = ? AND store_id = ?");
                    $inv_stmt->bind_param("iii", $actual_quantity, $item_info['product_id'], $item_info['store_id']);
                    $inv_stmt->execute();
                    $inv_stmt->close();
                    
                    // 재고 트랜잭션 로그 기록
                    try {
                        // inventory_id를 먼저 조회
                        $inv_id_stmt = $conn->prepare("SELECT id FROM inventory WHERE product_id = ? AND store_id = ?");
                        $inv_id_stmt->bind_param("ii", $item_info['product_id'], $item_info['store_id']);
                        $inv_id_stmt->execute();
                        $inv_id_result = $inv_id_stmt->get_result();
                        
                        if ($inv_row = $inv_id_result->fetch_assoc()) {
                            $trans_stmt = $conn->prepare("INSERT INTO inventory_transactions (inventory_id, user_id, transaction_type, quantity_change, remarks) VALUES (?, ?, 'OUT', ?, ?)");
                            $remarks = "매입 상품 삭제 (Purchase Item ID: {$item_id})";
                            $quantity_change = -$actual_quantity;
                            $trans_stmt->bind_param("iiss", $inv_row['id'], $_SESSION['user_id'], $quantity_change, $remarks);
                            $trans_stmt->execute();
                            $trans_stmt->close();
                        }
                        $inv_id_stmt->close();
                    } catch (Exception $log_error) {
                        // 로그 기록 실패는 무시하고 계속 진행
                    }
                }
                
                // 삭제 전 매입 상품 개수 확인
                $count_stmt = $conn->prepare("SELECT COUNT(*) as item_count FROM purchase_items WHERE purchase_id = ?");
                $count_stmt->bind_param("i", $purchase_id);
                $count_stmt->execute();
                $count_result = $count_stmt->get_result();
                $current_count = $count_result->fetch_assoc()['item_count'];
                $count_stmt->close();
                
                // 매입 상품 삭제
                $delete_stmt = $conn->prepare("DELETE FROM purchase_items WHERE item_id = ? AND purchase_id = ?");
                $delete_stmt->bind_param("ii", $item_id, $purchase_id);
                $delete_stmt->execute();
                $delete_stmt->close();
                
                // 삭제 후 남은 상품이 0개인지 확인
                if ($current_count <= 1) {
                    // 남은 상품이 0개가 되면 전체 매입 내역을 삭제 (Soft Delete)
                    
                    // purchases 테이블에 deleted_at 컬럼이 있는지 확인하고 없으면 추가
                    $check_deleted_at = $conn->query("SHOW COLUMNS FROM purchases LIKE 'deleted_at'");
                    $has_deleted_at_col = $check_deleted_at->num_rows > 0;
                    
                    if (!$has_deleted_at_col) {
                        $conn->query("ALTER TABLE purchases ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL");
                    }
                    
                    // deleted_by_user_id 컬럼이 없으면 추가
                    $check_deleted_by = $conn->query("SHOW COLUMNS FROM purchases LIKE 'deleted_by_user_id'");
                    $has_deleted_by_col = $check_deleted_by->num_rows > 0;
                    
                    if (!$has_deleted_by_col) {
                        $conn->query("ALTER TABLE purchases ADD COLUMN deleted_by_user_id INT DEFAULT NULL");
                    }
                    
                    // Soft Delete: deleted_at 컬럼에 현재 시간 설정
                    $check_deleted_at_final = $conn->query("SHOW COLUMNS FROM purchases LIKE 'deleted_at'");
                    $check_deleted_by_final = $conn->query("SHOW COLUMNS FROM purchases LIKE 'deleted_by_user_id'");
                    
                    if ($check_deleted_at_final->num_rows > 0 && $check_deleted_by_final->num_rows > 0) {
                        $soft_delete_stmt = $conn->prepare("UPDATE purchases SET deleted_at = NOW(), deleted_by_user_id = ? WHERE purchase_id = ?");
                        $soft_delete_stmt->bind_param("ii", $_SESSION['user_id'], $purchase_id);
                    } else if ($check_deleted_at_final->num_rows > 0) {
                        $soft_delete_stmt = $conn->prepare("UPDATE purchases SET deleted_at = NOW() WHERE purchase_id = ?");
                        $soft_delete_stmt->bind_param("i", $purchase_id);
                    } else {
                        throw new Exception('deleted_at 컬럼을 생성할 수 없습니다.');
                    }
                    $soft_delete_stmt->execute();
                    $soft_delete_stmt->close();
                    
                    $conn->commit();
                    $message = t('purchase.js_delete_success');
                    $message_type = 'success';
                    
                    // 전체 매입 내역이 삭제되었으므로 목록으로 리다이렉트
                    header('Location: purchase_management.php?message=' . urlencode($message));
                    exit();
                } else {
                    // 매입 전체 합계 재계산 및 업데이트
                    $update_stmt = $conn->prepare("
                        UPDATE purchases SET 
                            total_amount = (SELECT COALESCE(SUM(CASE WHEN discounted_total IS NOT NULL THEN discounted_total ELSE quantity * unit_price END), 0) FROM purchase_items WHERE purchase_id = ?),
                            total_items = (SELECT COUNT(*) FROM purchase_items WHERE purchase_id = ?)
                        WHERE purchase_id = ?
                    ");
                    $update_stmt->bind_param("iii", $purchase_id, $purchase_id, $purchase_id);
                    $update_stmt->execute();
                    $update_stmt->close();
                    
                    $conn->commit();
                    $message = t('purchase.js_delete_success');
                    $message_type = 'success';
                }
            } else {
                throw new Exception(t('purchase.item_not_found'));
            }
        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = $e->getMessage();
            $message = t('purchase.js_delete_error') . ': ' . $error_msg;
            $message_type = 'error';
        }
    }
    
    // 매입 상품 수정 처리
    if ($_POST['action'] === 'update_item' && isset($_POST['item_id'])) {
        $item_id = (int)$_POST['item_id'];
        $new_quantity = (int)$_POST['quantity'];
        $new_unit_price = (float)$_POST['unit_price'];
        $new_purchase_type = $_POST['purchase_type'] ?? 'piece';
        $new_pieces_per_box = isset($_POST['pieces_per_box']) ? (int)$_POST['pieces_per_box'] : null;
        
        try {
            $conn->begin_transaction();
            
            // 기존 아이템 정보 조회
            $item_stmt = $conn->prepare("
                SELECT pi.*, pr.pieces_per_box, u.store_id 
                FROM purchase_items pi 
                JOIN products pr ON pi.product_id = pr.id
                JOIN purchases p ON pi.purchase_id = p.purchase_id
                JOIN users u ON u.id = ?
                WHERE pi.item_id = ? AND pi.purchase_id = ?
            ");
            $item_stmt->bind_param("iii", $_SESSION['user_id'], $item_id, $purchase_id);
            $item_stmt->execute();
            $old_info = $item_stmt->get_result()->fetch_assoc();
            $item_stmt->close();
            
            if ($old_info) {
                // 기존 실제 입고 수량 계산
                $old_actual_quantity = (int)$old_info['quantity'];
                if ($old_info['purchase_type'] === 'box') {
                    $old_actual_quantity = (int)$old_info['quantity'] * ($old_info['pieces_per_box'] ?? 1);
                }
                
                // 새로운 실제 입고 수량 계산
                $new_actual_quantity = $new_quantity;
                if ($new_purchase_type === 'box') {
                    $new_actual_quantity = $new_quantity * ($old_info['pieces_per_box'] ?? 1);
                }
                
                // 재고 수량 조정
                if ($old_info['store_id']) {
                    $quantity_diff = $new_actual_quantity - $old_actual_quantity;
                    
                    if ($quantity_diff != 0) {
                        $inv_stmt = $conn->prepare("UPDATE inventory SET quantity = quantity + ? WHERE product_id = ? AND store_id = ?");
                        $inv_stmt->bind_param("iii", $quantity_diff, $old_info['product_id'], $old_info['store_id']);
                        $inv_stmt->execute();
                        $inv_stmt->close();
                        
                        // 재고 트랜잭션 로그 기록
                        try {
                            // inventory_id를 먼저 조회
                            $inv_id_stmt = $conn->prepare("SELECT id FROM inventory WHERE product_id = ? AND store_id = ?");
                            $inv_id_stmt->bind_param("ii", $old_info['product_id'], $old_info['store_id']);
                            $inv_id_stmt->execute();
                            $inv_id_result = $inv_id_stmt->get_result();
                            
                            if ($inv_row = $inv_id_result->fetch_assoc()) {
                                $trans_type = $quantity_diff > 0 ? 'IN' : 'OUT';
                                $trans_stmt = $conn->prepare("INSERT INTO inventory_transactions (inventory_id, user_id, transaction_type, quantity_change, remarks) VALUES (?, ?, ?, ?, ?)");
                                $remarks = "매입 상품 수정 (Purchase Item ID: {$item_id})";
                                $trans_stmt->bind_param("iisis", $inv_row['id'], $_SESSION['user_id'], $trans_type, $quantity_diff, $remarks);
                                $trans_stmt->execute();
                                $trans_stmt->close();
                            }
                            $inv_id_stmt->close();
                        } catch (Exception $log_error) {
                            // 로그 기록 실패는 무시하고 계속 진행
                            }
                    }
                }
                
                // 상품의 박스 수량도 업데이트 (제공된 경우)
                if ($new_pieces_per_box !== null && $new_pieces_per_box > 0) {
                    $update_product_stmt = $conn->prepare("UPDATE products SET pieces_per_box = ? WHERE id = ?");
                    $update_product_stmt->bind_param("ii", $new_pieces_per_box, $old_info['product_id']);
                    $update_product_stmt->execute();
                    $update_product_stmt->close();
                }
                
                // 매입 상품 정보 업데이트 (할인 정보 포함)
                // 할인 정보 추출
                $discount_rate = isset($_POST['discount_rate']) ? (float)$_POST['discount_rate'] : 0.00;
                $original_total = $new_quantity * $new_unit_price;
                $discounted_total = $original_total * (1 - $discount_rate / 100);
                
                $update_item_stmt = $conn->prepare("UPDATE purchase_items SET quantity = ?, unit_price = ?, purchase_type = ?, discount_rate = ?, discounted_total = ? WHERE item_id = ?");
                $update_item_stmt->bind_param("idsidi", $new_quantity, $new_unit_price, $new_purchase_type, $discount_rate, $discounted_total, $item_id);
                $update_item_stmt->execute();
                $update_item_stmt->close();
                
                // 매입 전체 합계 재계산 및 업데이트 (할인후 금액 기준)
                $update_stmt = $conn->prepare("
                    UPDATE purchases SET 
                        total_amount = (SELECT COALESCE(SUM(CASE WHEN discounted_total IS NOT NULL THEN discounted_total ELSE quantity * unit_price END), 0) FROM purchase_items WHERE purchase_id = ?),
                        total_items = (SELECT COUNT(*) FROM purchase_items WHERE purchase_id = ?)
                    WHERE purchase_id = ?
                ");
                $update_stmt->bind_param("iii", $purchase_id, $purchase_id, $purchase_id);
                $update_stmt->execute();
                $update_stmt->close();
                
                $conn->commit();
                $message = '매입 상품이 성공적으로 수정되었습니다.';
                $message_type = 'success';
            } else {
                throw new Exception('수정할 상품을 찾을 수 없습니다.');
            }
        } catch (Exception $e) {
            $conn->rollback();
            $message = '상품 수정에 실패했습니다: ' . $e->getMessage();
            $message_type = 'error';
        }
    }
}


// 일괄 VAT 적용 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'apply_bulk_vat') {
    $selected_items = isset($_POST['selected_items']) ? $_POST['selected_items'] : [];
    
    if (!empty($selected_items)) {
        try {
            $conn->begin_transaction();
            
            $updated_count = 0;
            $skipped_count = 0;
            $errors = [];
            
            foreach ($selected_items as $item_id) {
                $item_id = (int)$item_id;
                
                // 기존 아이템 정보 및 상품 정보 조회
                $item_stmt = $conn->prepare("
                    SELECT pi.*, pr.is_vat_applicable 
                    FROM purchase_items pi 
                    JOIN products pr ON pi.product_id = pr.id 
                    WHERE pi.item_id = ? AND pi.purchase_id = ?
                ");
                $item_stmt->bind_param("ii", $item_id, $purchase_id);
                $item_stmt->execute();
                $item_info = $item_stmt->get_result()->fetch_assoc();
                $item_stmt->close();
                
                if ($item_info) {
                    // VAT 적용 상품인지 확인
                    if ($item_info['is_vat_applicable'] == 1) {
                        // VAT를 포함시킨 새로운 가격 계산
                        $current_price = (float)$item_info['unit_price'];
                        $new_price = $current_price * 1.12; // 12% VAT 추가
                        $vat_amount = $current_price * 0.12;
                        
                        // 매입 항목 업데이트
                        $update_stmt = $conn->prepare("
                            UPDATE purchase_items 
                            SET unit_price = ?, 
                                original_unit_price = ?, 
                                vat_amount = ?, 
                                vat_included = 1 
                            WHERE item_id = ?
                        ");
                        $update_stmt->bind_param("dddi", $new_price, $current_price, $vat_amount, $item_id);
                        $update_stmt->execute();
                        $update_stmt->close();
                        
                        // 재고 cost_price도 업데이트
                        $inv_update_stmt = $conn->prepare("
                            UPDATE inventory 
                            SET cost_price = ? 
                            WHERE product_id = ? AND store_id = (
                                SELECT store_id FROM users WHERE id = ?
                            )
                        ");
                        $inv_update_stmt->bind_param("dii", $new_price, $item_info['product_id'], $_SESSION['user_id']);
                        $inv_update_stmt->execute();
                        $inv_update_stmt->close();
                        
                        $updated_count++;
                    } else {
                        $skipped_count++; // VAT 비적용 상품은 건너뜀
                    }
                } else {
                    $errors[] = "상품 ID {$item_id}를 찾을 수 없습니다.";
                }
            }
            
            // 매입 전체 합계 재계산
            $update_purchase_stmt = $conn->prepare("
                UPDATE purchases SET 
                    total_amount = (SELECT COALESCE(SUM(CASE WHEN discounted_total IS NOT NULL THEN discounted_total ELSE quantity * unit_price END), 0) FROM purchase_items WHERE purchase_id = ?)
                WHERE purchase_id = ?
            ");
            $update_purchase_stmt->bind_param("ii", $purchase_id, $purchase_id);
            $update_purchase_stmt->execute();
            $update_purchase_stmt->close();
            
            $conn->commit();
            
            if ($updated_count > 0) {
                $message = "{$updated_count}개 상품에 VAT가 적용되었습니다.";
                if ($skipped_count > 0) {
                    $message .= " ({$skipped_count}개는 VAT 비적용 상품으로 제외되었습니다.)";
                }
                $message_type = 'success';
            } else {
                $message = 'VAT를 적용할 수 있는 상품이 없습니다.';
                $message_type = 'warning';
            }
            
            if (!empty($errors)) {
                $message .= " 오류: " . implode(", ", $errors);
            }
            
        } catch (Exception $e) {
            $conn->rollback();
            $message = 'VAT 적용에 실패했습니다: ' . $e->getMessage();
            $message_type = 'error';
        }
    } else {
        $message = '선택된 상품이 없습니다.';
        $message_type = 'warning';
    }
}

// 일괄 할인 적용 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'apply_bulk_discount') {
    $discount_rate = isset($_POST['bulk_discount_rate']) ? (float)$_POST['bulk_discount_rate'] : 0;
    $selected_items = isset($_POST['selected_items']) ? $_POST['selected_items'] : [];
    
    if ($discount_rate >= 0 && $discount_rate <= 100 && !empty($selected_items)) {
        try {
            $conn->begin_transaction();
            
            $updated_count = 0;
            foreach ($selected_items as $item_id) {
                $item_id = (int)$item_id;
                
                // 기존 아이템 정보 조회
                $item_stmt = $conn->prepare("SELECT quantity, unit_price FROM purchase_items WHERE item_id = ? AND purchase_id = ?");
                $item_stmt->bind_param("ii", $item_id, $purchase_id);
                $item_stmt->execute();
                $item_info = $item_stmt->get_result()->fetch_assoc();
                $item_stmt->close();
                
                if ($item_info) {
                    $original_total = $item_info['quantity'] * $item_info['unit_price'];
                    $discounted_total = $original_total * (1 - $discount_rate / 100);
                    
                    // 할인 정보 업데이트
                    $update_stmt = $conn->prepare("UPDATE purchase_items SET discount_rate = ?, discounted_total = ? WHERE item_id = ?");
                    $update_stmt->bind_param("ddi", $discount_rate, $discounted_total, $item_id);
                    $update_stmt->execute();
                    $update_stmt->close();
                    
                    $updated_count++;
                }
            }
            
            // 매입 전체 합계 재계산 (할인 후 총액 사용)
            $update_purchase_stmt = $conn->prepare("
                UPDATE purchases SET 
                    total_amount = (SELECT COALESCE(SUM(CASE WHEN discounted_total IS NOT NULL THEN discounted_total ELSE quantity * unit_price END), 0) FROM purchase_items WHERE purchase_id = ?)
                WHERE purchase_id = ?
            ");
            $update_purchase_stmt->bind_param("ii", $purchase_id, $purchase_id);
            $update_purchase_stmt->execute();
            $update_purchase_stmt->close();
            
            $conn->commit();
            $message = str_replace(['{count}', '{rate}'], [$updated_count, $discount_rate], t('purchase.js_discount_applied'));
            $message_type = 'success';
            
        } catch (Exception $e) {
            $conn->rollback();
            $message = t('purchase.js_save_error') . ': ' . $e->getMessage();
            $message_type = 'error';
        }
    } else {
        $message = t('purchase.js_discount_rate_invalid');
        $message_type = 'error';
    }
}

// 새로운 상품 추가 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_item') {
    $product_id = (int)$_POST['product_id'];
    $quantity = (int)$_POST['quantity'];
    $unit_price = (float)$_POST['unit_price'];
    $purchase_type = trim($_POST['purchase_type'] ?? 'box');
    
    // 데이터 검증
    if (!in_array($purchase_type, ['box', 'piece'])) {
        $purchase_type = 'box';
    }
    
    if ($product_id && $quantity > 0 && $unit_price >= 0) {
        try {
            $conn->begin_transaction();
            
            // 현재 사용자의 점포 정보 조회
            $user_stmt = $conn->prepare("SELECT store_id FROM users WHERE id = ?");
            $user_stmt->bind_param("i", $_SESSION['user_id']);
            $user_stmt->execute();
            $user_info = $user_stmt->get_result()->fetch_assoc();
            $user_stmt->close();
            
            // 상품의 VAT 적용 여부 확인
            $product_vat_stmt = $conn->prepare("SELECT is_vat_applicable FROM products WHERE id = ?");
            $product_vat_stmt->bind_param("i", $product_id);
            $product_vat_stmt->execute();
            $product_vat_result = $product_vat_stmt->get_result();
            $is_vat_applicable = 1; // 기본값: VAT 적용 상품
            if ($product_vat_row = $product_vat_result->fetch_assoc()) {
                $is_vat_applicable = (int)$product_vat_row['is_vat_applicable'];
            }
            
            // VAT 처리 로직
            $vat_included = isset($_POST['vat_included']) ? (int)$_POST['vat_included'] : 1; // 기본값: VAT 포함
            $original_price = $unit_price; // 사용자 입력 원본 가격
            $vat_rate = 0.12; // VAT 12%
            
            if ($vat_included == 1) {
                // VAT 포함으로 선택한 경우
                $final_unit_price = $original_price; // 입력값 그대로
                if ($is_vat_applicable == 1) {
                    // VAT 적용 상품: VAT 금액 계산
                    $vat_amount = $original_price - ($original_price / 1.12);
                } else {
                    // VAT 비적용 상품: VAT 금액 0
                    $vat_amount = 0;
                }
            } else {
                // VAT 미포함으로 선택한 경우
                if ($is_vat_applicable == 0) {
                    // VAT 비적용 상품: 원가 그대로 저장, VAT 구분은 "포함"으로 변경
                    $final_unit_price = $original_price;
                    $vat_amount = 0;
                    $vat_included = 1; // VAT 구분을 "포함"으로 변경
                } else {
                    // VAT 적용 상품: 원가에 12% 추가하여 저장, VAT 구분은 "포함"으로 변경
                    $final_unit_price = $original_price * 1.12; // VAT 추가된 가격
                    $vat_amount = $original_price * $vat_rate;
                    $vat_included = 1; // VAT 구분을 "포함"으로 변경
                }
            }
            
            // 1. 매입 상세 데이터 저장 (VAT 관련 필드 포함)
            $stmt_item = $conn->prepare("INSERT INTO purchase_items (purchase_id, product_id, purchase_type, quantity, unit_price, vat_included, original_unit_price, vat_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt_item->bind_param("iisididd", $purchase_id, $product_id, $purchase_type, $quantity, $final_unit_price, $vat_included, $original_price, $vat_amount);
            if (!$stmt_item->execute()) {
                throw new Exception("매입 상세 저장 실패: " . $stmt_item->error);
            }
            $stmt_item->close();
            
            // 2. 실제 입고 수량 계산 (박스/낱개 구분)
            $actual_quantity = $quantity;
            if ($purchase_type === 'box') {
                $pieces_stmt = $conn->prepare("SELECT pieces_per_box FROM products WHERE id = ?");
                $pieces_stmt->bind_param("i", $product_id);
                $pieces_stmt->execute();
                $pieces_result = $pieces_stmt->get_result();
                if ($pieces_row = $pieces_result->fetch_assoc()) {
                    $pieces_per_box = $pieces_row['pieces_per_box'] ?? 1;
                    $actual_quantity = $quantity * $pieces_per_box;
                }
                $pieces_stmt->close();
            }
            
            // 3. inventory 테이블 업데이트
            if ($user_info && $user_info['store_id']) {
                $store_id = $user_info['store_id'];
                
                // 기존 재고 레코드 확인
                $inv_check_stmt = $conn->prepare("SELECT id, quantity FROM inventory WHERE product_id = ? AND store_id = ?");
                $inv_check_stmt->bind_param("ii", $product_id, $store_id);
                $inv_check_stmt->execute();
                $inv_result = $inv_check_stmt->get_result();
                
                if ($inv_row = $inv_result->fetch_assoc()) {
                    // 기존 재고 업데이트 (VAT 포함 가격으로 cost_price도 함께 업데이트)
                    $new_quantity = $inv_row['quantity'] + $actual_quantity;
                    $inv_update_stmt = $conn->prepare("UPDATE inventory SET quantity = ?, cost_price = ? WHERE id = ?");
                    $inv_update_stmt->bind_param("idi", $new_quantity, $final_unit_price, $inv_row['id']);
                    $inv_update_stmt->execute();
                    $inv_update_stmt->close();
                    $inventory_id = $inv_row['id'];
                } else {
                    // 새로운 재고 레코드 생성 (VAT 포함 가격으로 cost_price 설정)
                    $inv_insert_stmt = $conn->prepare("INSERT INTO inventory (product_id, store_id, quantity, cost_price) VALUES (?, ?, ?, ?)");
                    $inv_insert_stmt->bind_param("iiid", $product_id, $store_id, $actual_quantity, $final_unit_price);
                    $inv_insert_stmt->execute();
                    $inventory_id = $inv_insert_stmt->insert_id;
                    $inv_insert_stmt->close();
                }
                $inv_check_stmt->close();
                
                // 4. inventory_transactions 로그 기록
                $transaction_stmt = $conn->prepare("INSERT INTO inventory_transactions (inventory_id, user_id, transaction_type, quantity_change, remarks) VALUES (?, ?, '입고', ?, ?)");
                $remarks = "매입 상품 추가 (Purchase ID: {$purchase_id})";
                $transaction_stmt->bind_param("iiis", $inventory_id, $_SESSION['user_id'], $actual_quantity, $remarks);
                if (!$transaction_stmt->execute()) {
                    throw new Exception("재고 거래 로그 저장 실패: " . $transaction_stmt->error);
                }
                $transaction_stmt->close();
            }
            
            // 5. 매입 전체 합계 재계산 및 업데이트 (할인후 금액 기준)
            $update_stmt = $conn->prepare("
                UPDATE purchases SET 
                    total_amount = (SELECT COALESCE(SUM(CASE WHEN discounted_total IS NOT NULL THEN discounted_total ELSE quantity * unit_price END), 0) FROM purchase_items WHERE purchase_id = ?),
                    total_items = (SELECT COUNT(*) FROM purchase_items WHERE purchase_id = ?)
                WHERE purchase_id = ?
            ");
            $update_stmt->bind_param("iii", $purchase_id, $purchase_id, $purchase_id);
            $update_stmt->execute();
            $update_stmt->close();
            
            $conn->commit();
            $message = '새로운 상품이 매입 내역에 성공적으로 추가되었습니다.';
            $message_type = 'success';
            
        } catch (Exception $e) {
            $conn->rollback();
            $message = '상품 추가에 실패했습니다: ' . $e->getMessage();
            $message_type = 'error';
        }
    } else {
        $message = t('purchase.required_fields');
        $message_type = 'error';
    }
}

// deleted_at 컬럼이 존재하는지 확인
$check_deleted_at_column = $conn->query("SHOW COLUMNS FROM purchases LIKE 'deleted_at'");
$has_deleted_at = $check_deleted_at_column->num_rows > 0;

// Fetch purchase details (삭제되지 않은 매입만 + 점포 정보)
if ($has_deleted_at) {
    $stmt = $conn->prepare("SELECT p.*, s.name as supplier_name FROM purchases p JOIN suppliers s ON p.supplier_id = s.id WHERE p.purchase_id = ? AND p.deleted_at IS NULL");
} else {
    $stmt = $conn->prepare("SELECT p.*, s.name as supplier_name FROM purchases p JOIN suppliers s ON p.supplier_id = s.id WHERE p.purchase_id = ?");
}
$stmt->bind_param("i", $purchase_id);
$stmt->execute();
$purchase_result = $stmt->get_result();
$purchase = $purchase_result->fetch_assoc();
$stmt->close();

if (!$purchase) {
    echo "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4'>매입 내역을 찾을 수 없습니다.</div>";
    echo "<a href='purchase_management.php' class='bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded'>매입 리스트로 돌아가기</a>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

// Fetch purchase items with product details (할인 정보 및 VAT 정보 포함)
$stmt = $conn->prepare("
    SELECT 
        pi.*,
        COALESCE(pi.vat_included, 1) as vat_included,
        pi.original_unit_price,
        COALESCE(pi.vat_amount, 0) as vat_amount,
        pr.name_ko as product_name,
        pr.name_en as product_name_en,
        pr.sku,
        pr.pieces_per_box,
        CASE 
            WHEN pi.purchase_type = 'box' THEN pi.quantity * COALESCE(pr.pieces_per_box, 1)
            ELSE pi.quantity
        END as total_pieces,
        COALESCE(pi.discount_rate, 0) as discount_rate,
        COALESCE(pi.discounted_total, pi.quantity * pi.unit_price) as discounted_total
    FROM purchase_items pi 
    JOIN products pr ON pi.product_id = pr.id 
    WHERE pi.purchase_id = ? 
    ORDER BY pi.item_id
");
$stmt->bind_param("i", $purchase_id);
$stmt->execute();
$items_result = $stmt->get_result();
?>

<style>
/* 인라인 편집 스타일 */
.quantity-input,
.price-input,
.pieces-input {
    transition: all 0.2s ease;
    background-color: transparent;
}

.quantity-input:hover,
.price-input:hover,
.pieces-input:hover {
    background-color: #f9fafb;
}

.quantity-input:focus,
.price-input:focus,
.pieces-input:focus {
    background-color: white;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

.save-indicator {
    animation: pulse 1.5s infinite;
}

@keyframes pulse {
    0% { opacity: 1; }
    50% { opacity: 0.5; }
    100% { opacity: 1; }
}

.notification-toast {
    animation: slideIn 0.3s ease;
}

@keyframes slideIn {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

/* 체크박스 열 hover 스타일 */
tr[id^="row-"] td:first-child {
    cursor: pointer;
    user-select: none;
}

tr[id^="row-"] td:first-child:hover {
    background-color: rgba(99, 102, 241, 0.05);
}
</style>

<!-- Page header -->
<div class="mb-8 sm:flex sm:items-center sm:justify-between">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">매입 내역 상세보기</h1>
        <p class="mt-2 text-sm text-gray-700">거래번호: <span class="font-semibold"><?php echo htmlspecialchars($purchase_id); ?></span></p>
    </div>
    <div class="mt-4 sm:mt-0 sm:ml-16 sm:flex-none">
        <div class="flex space-x-3">
            <?php if ($has_deleted_at): ?>
            <button type="button" id="delete-purchase-btn" class="inline-flex items-center justify-center rounded-md border border-red-300 bg-red-50 px-4 py-2 text-sm font-medium text-red-700 shadow-sm hover:bg-red-100 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
                <i class="fas fa-trash-alt mr-2"></i>
                <?php echo t('common.delete'); ?>
            </button>
            <?php else: ?>
            <a href="setup_soft_delete_purchases.php" class="inline-flex items-center justify-center rounded-md border border-yellow-300 bg-yellow-50 px-4 py-2 text-sm font-medium text-yellow-700 shadow-sm hover:bg-yellow-100 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:ring-offset-2">
                <i class="fas fa-database mr-2"></i>
                Soft Delete 설정 필요
            </a>
            <?php endif; ?>
            <a href="purchase_management.php" class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                <i class="fas fa-arrow-left mr-2"></i>
                매입 관리로 돌아가기
            </a>
        </div>
    </div>
</div>

<?php if ($message): ?>
    <div class="mb-6 rounded-md <?php echo $message_type === 'success' ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-200'; ?> p-4 border">
        <div class="flex">
            <div class="flex-shrink-0">
                <i class="fas <?php echo $message_type === 'success' ? 'fa-check-circle text-green-400' : 'fa-exclamation-circle text-red-400'; ?>"></i>
            </div>
            <div class="ml-3">
                <p class="text-sm <?php echo $message_type === 'success' ? 'text-green-800' : 'text-red-800'; ?>"><?php echo $message; ?></p>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="bg-white shadow rounded-lg">
    <div class="px-6 py-4 border-b border-gray-200">
        <h2 class="text-lg font-medium text-gray-900">매입 기본 정보</h2>
    </div>
    <div class="p-6">
        <!-- 매입 기본 정보 -->
        <div class="bg-gray-50 rounded-lg p-4 mb-8">
            <div class="flex flex-wrap items-center justify-between gap-6">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-building text-blue-500 mr-2"></i>
                    </div>
                    <div>
                        <span class="text-sm font-medium text-gray-600"><?php echo t('purchase.supplier'); ?>:</span>
                        <span class="text-base font-semibold text-gray-900 ml-2"><?php echo htmlspecialchars($purchase['supplier_name']); ?></span>
                    </div>
                </div>
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-calendar-alt text-green-500 mr-2"></i>
                    </div>
                    <div>
                        <span class="text-sm font-medium text-gray-600"><?php echo t('purchase.purchase_date'); ?>:</span>
                        <span class="text-base font-semibold text-gray-900 ml-2"><?php echo htmlspecialchars($purchase['purchase_date']); ?></span>
                    </div>
                </div>
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <span class="text-purple-500 text-lg font-bold mr-2">₱</span>
                    </div>
                    <div>
                        <span class="text-sm font-medium text-gray-600"><?php echo t('purchase.total_amount'); ?>:</span>
                        <span class="text-base font-semibold text-gray-900 ml-2 total-amount"><?php echo number_format($purchase['total_amount'], 2); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 매입 상품 목록 -->
        <div class="mb-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-medium text-gray-900"><?php echo t('purchase.purchase_items'); ?></h3>
                <button type="button" id="save-all-changes" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                    <i class="fas fa-save mr-2"></i>
                    <?php echo t('purchase.save_changes'); ?>
                </button>
            </div>
            <div class="shadow ring-1 ring-black ring-opacity-5 md:rounded-lg">
                <table class="w-full table-fixed divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="w-8 px-1 py-2 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <input type="checkbox" id="select-all" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            </th>
                            <th class="w-20 px-1 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">SKU</th>
                            <th class="w-48 px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo t('purchase.product_name'); ?></th>
                            <th class="w-20 px-1 py-2 text-center text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo t('purchase.unit'); ?></th>
                            <th class="w-12 px-1 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo t('purchase.quantity'); ?></th>
                            <th class="w-12 px-1 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo t('purchase.pieces_per_box'); ?></th>
                            <th class="w-16 px-1 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo t('purchase.unit_price'); ?></th>
                            <th class="w-14 px-1 py-2 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">VAT 구분</th>
                            <th class="w-14 px-1 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo t('purchase.piece_price'); ?></th>
                            <th class="w-10 px-1 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo t('purchase.total_pieces'); ?></th>
                            <th class="w-16 px-1 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo t('purchase.total'); ?></th>
                            <th class="w-10 px-1 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo t('purchase.discount_rate'); ?></th>
                            <th class="w-16 px-1 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo t('purchase.discounted_total'); ?></th>
                            <th class="w-10 px-1 py-2 text-center text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo t('purchase.delete'); ?></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php 
                        $total_amount = 0;
                        $total_pieces = 0;
                        $total_products = 0; // 총 품목 수 (상품 종류 수)
                        $total_items = 0;    // 총 아이템 수 (수량 합계)
                        
                        while($item = $items_result->fetch_assoc()): 
                            $item_total = $item['quantity'] * $item['unit_price'];
                            $total_amount += $item_total;
                            $total_pieces += $item['total_pieces'];
                            $total_products++; // 상품 종류 개수
                            $total_items += $item['quantity']; // 수량 합계
                            
                            $piece_price = 0;
                            if ($item['purchase_type'] === 'box' && $item['pieces_per_box'] > 0) {
                                $piece_price = $item['unit_price'] / $item['pieces_per_box'];
                            } else {
                                $piece_price = $item['unit_price'];
                            }
                        ?>
                                <tr class="hover:bg-gray-50 transition-colors duration-150" id="row-<?php echo $item['item_id']; ?>">
                                    <td class="w-8 px-1 py-3 text-center">
                                        <input type="checkbox" class="item-checkbox rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" value="<?php echo $item['item_id']; ?>">
                                    </td>
                                    <td class="w-20 px-1 py-3 text-xs font-medium text-gray-900" title="<?php echo htmlspecialchars($item['sku']); ?>">
                                        <?php echo htmlspecialchars($item['sku']); ?>
                                    </td>
                                    <td class="w-48 px-2 py-3">
                                        <div class="product-name-editable text-sm font-medium text-gray-900 hover:bg-gray-100 hover:cursor-pointer rounded px-2 py-1 transition-colors" 
                                             data-product-id="<?php echo $item['product_id']; ?>"
                                             data-item-id="<?php echo $item['item_id']; ?>"
                                             data-original-name="<?php echo htmlspecialchars($item['product_name']); ?>"
                                             title="클릭하여 상품명 수정"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                        <?php if (!empty($item['product_name_en'])): ?>
                                        <div class="product-name-en-editable text-xs text-gray-600 italic hover:bg-gray-100 hover:cursor-pointer rounded px-2 py-1 transition-colors"
                                             data-product-id="<?php echo $item['product_id']; ?>"
                                             data-item-id="<?php echo $item['item_id']; ?>"
                                             data-original-name="<?php echo htmlspecialchars($item['product_name_en']); ?>"
                                             title="클릭하여 영문 상품명 수정"><?php echo htmlspecialchars($item['product_name_en']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="w-20 px-1 py-3 text-center">
                                        <div class="flex items-center justify-center space-x-1">
                                            <label class="inline-flex items-center text-xs">
                                                <input type="radio" name="purchase_type_<?php echo $item['item_id']; ?>" 
                                                       class="type-input text-indigo-600 border-gray-300 focus:ring-indigo-500 mr-1" 
                                                       value="box" 
                                                       data-item-id="<?php echo $item['item_id']; ?>"
                                                       <?php echo $item['purchase_type'] === 'box' ? 'checked' : ''; ?>>
                                                <span class="text-gray-700"><?php echo t('purchase.box'); ?></span>
                                            </label>
                                            <label class="inline-flex items-center text-xs">
                                                <input type="radio" name="purchase_type_<?php echo $item['item_id']; ?>" 
                                                       class="type-input text-indigo-600 border-gray-300 focus:ring-indigo-500 mr-1" 
                                                       value="piece" 
                                                       data-item-id="<?php echo $item['item_id']; ?>"
                                                       <?php echo $item['purchase_type'] === 'piece' ? 'checked' : ''; ?>>
                                                <span class="text-gray-700"><?php echo t('purchase.piece'); ?></span>
                                            </label>
                                        </div>
                                    </td>
                                    <td class="w-12 px-1 py-3 text-sm text-gray-900 text-right">
                                        <input type="number" 
                                               class="quantity-input w-full px-1 py-1 border border-gray-300 rounded-md text-right text-xs hover:border-indigo-400 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500" 
                                               value="<?php echo $item['quantity']; ?>" 
                                               data-item-id="<?php echo $item['item_id']; ?>"
                                               data-original-value="<?php echo $item['quantity']; ?>"
                                               min="1">
                                    </td>
                                    <td class="w-12 px-1 py-3 text-sm text-gray-500 text-right">
                                        <input type="number" 
                                               class="pieces-input w-full px-1 py-1 border border-gray-300 rounded-md text-right text-xs hover:border-indigo-400 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500" 
                                               value="<?php echo $item['pieces_per_box'] ?? 1; ?>" 
                                               data-item-id="<?php echo $item['item_id']; ?>"
                                               data-original-value="<?php echo $item['pieces_per_box'] ?? 1; ?>"
                                               min="1">
                                    </td>
                                    <td class="w-16 px-1 py-3 text-sm text-gray-900 text-right">
                                        <input type="number" 
                                               class="price-input w-full px-1 py-1 border border-gray-300 rounded-md text-right text-xs hover:border-indigo-400 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500" 
                                               value="<?php echo $item['unit_price']; ?>" 
                                               data-item-id="<?php echo $item['item_id']; ?>"
                                               data-original-value="<?php echo $item['unit_price']; ?>"
                                               min="0" 
                                               step="0.01">
                                        <?php 
                                        // VAT 미포함 금액 계산
                                        $vat_excluded_price = $item['unit_price'] / 1.12;
                                        ?>
                                        <div class="text-xs text-gray-500 mt-1">
                                            미포함: <?php echo number_format($vat_excluded_price, 2); ?>
                                        </div>
                                    </td>
                                    <!-- VAT 구분 컬럼 -->
                                    <td class="w-14 px-1 py-3 text-center">
                                        <?php if ($item['vat_included'] == 1): ?>
                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800" 
                                                  title="VAT 포함 상품&#10;저장가격: <?php echo number_format($item['unit_price'], 2); ?>원">
                                                🟢 포함
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800"
                                                  title="VAT 미포함 상품&#10;입력가격: <?php echo number_format($item['original_unit_price'] ?? $item['unit_price'], 2); ?>원&#10;저장가격: <?php echo number_format($item['unit_price'], 2); ?>원 (VAT 12% 포함)&#10;VAT 금액: <?php echo number_format($item['vat_amount'], 2); ?>원">
                                                🟡 미포함
                                            </span>
                                        <?php endif; ?>
                                        <?php 
                                        // VAT 금액 계산 및 표시
                                        $calculated_vat = $item['unit_price'] - ($item['unit_price'] / 1.12);
                                        ?>
                                        <div class="text-xs text-gray-500 mt-1">
                                            VAT: <?php echo number_format($calculated_vat, 2); ?>
                                        </div>
                                    </td>
                                    <td class="w-14 px-1 py-3 text-xs text-gray-500 text-right"><?php echo number_format($piece_price, 2); ?></td>
                                    <td class="w-10 px-1 py-3 text-xs text-gray-900 text-right font-semibold">
                                        <span class="text-blue-600"><?php echo number_format($item['total_pieces']); ?></span>
                                    </td>
                                    <td class="w-16 px-1 py-3 text-xs text-gray-900 text-right font-bold"><?php echo number_format($item_total, 2); ?></td>
                                    <td class="w-10 px-1 py-3 text-center">
                                        <input type="number" class="discount-rate w-9 px-0 py-1 border border-gray-300 rounded-md text-center text-xs focus:border-indigo-500 focus:ring-indigo-500" value="<?php echo number_format($item['discount_rate'], 1); ?>" min="0" max="100" step="0.1" placeholder="0">
                                    </td>
                                    <td class="w-16 px-1 py-3 text-xs text-gray-900 text-right font-bold discounted-total"><?php echo number_format($item['discounted_total'], 2); ?></td>
                                    <td class="w-10 px-1 py-3 text-center">
                                        <button type="button" class="delete-btn inline-flex items-center justify-center px-2 py-1 border border-transparent text-xs font-medium rounded-md text-red-600 hover:text-red-900 bg-red-50 hover:bg-red-100 transition-colors duration-200" data-item-id="<?php echo $item['item_id']; ?>">
                                            <i class="fas fa-trash-alt text-xs"></i>
                                        </button>
                                    </td>
                                </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Bulk Discount Control -->
        <div class="mt-6 bg-yellow-50 border border-yellow-200 rounded-lg p-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <label class="text-sm font-medium text-gray-700"><?php echo t('purchase.bulk_discount'); ?>:</label>
                    <input type="number" id="bulk-discount-rate" class="w-20 px-3 py-2 border border-gray-300 rounded-md text-center focus:border-indigo-500 focus:ring-indigo-500" min="0" max="100" step="0.1" placeholder="0">
                    <span class="text-sm text-gray-600">%</span>
                </div>
                <button type="button" id="apply-bulk-discount" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-yellow-600 hover:bg-yellow-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500 disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                    <i class="fas fa-percent mr-2"></i>
                    <?php echo t('purchase.apply_bulk_discount'); ?>
                </button>
            </div>
            <p class="mt-2 text-xs text-gray-500"><?php echo t('purchase.bulk_discount_desc'); ?></p>
        </div>

        <!-- Bulk VAT Apply Control -->
        <div class="mt-4 bg-orange-50 border border-orange-200 rounded-lg p-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <label class="text-sm font-medium text-gray-700">VAT 일괄 적용:</label>
                    <span class="text-sm text-gray-600">선택된 VAT 적용 상품의 단가에 12%를 추가합니다</span>
                </div>
                <button type="button" id="apply-bulk-vat" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-orange-600 hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500 disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                    <i class="fas fa-plus-circle mr-2"></i>
                    선택 항목 VAT 포함하기
                </button>
            </div>
            <p class="mt-2 text-xs text-gray-500">
                <strong>주의:</strong> VAT 미포함 단가로 입력된 상품을 VAT 포함 단가로 변환할 때 사용하세요. (예: 1000원 → 1120원)
            </p>
        </div>
        
        <!-- 상품 추가 버튼 -->
        <div class="mt-6 flex justify-center">
            <a href="add_purchase.php?edit_purchase_id=<?php echo $purchase_id; ?>" 
               class="inline-flex items-center px-24 py-5 border border-blue-300 rounded-lg shadow-sm text-xl font-semibold text-blue-800 bg-blue-100 hover:bg-blue-200 hover:text-blue-900 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-all duration-200">
                <i class="fas fa-plus-circle mr-5 text-2xl"></i>
                상품 추가하기
            </a>
        </div>
        
        <!-- Summary Information -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 bg-gradient-to-r from-blue-600 to-indigo-600 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-black flex items-center">
                    <i class="fas fa-chart-bar mr-2"></i>
                    <?php echo t('purchase.summary'); ?>
                </h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-4 text-center text-sm font-semibold text-gray-700 border-r border-gray-200">
                                <div class="flex items-center justify-center mb-2">
                                    <i class="fas fa-boxes text-purple-600 text-lg mr-2"></i>
                                    총 품목 수
                                </div>
                            </th>
                            <th class="px-6 py-4 text-center text-sm font-semibold text-gray-700 border-r border-gray-200">
                                <div class="flex items-center justify-center mb-2">
                                    <i class="fas fa-cube text-orange-600 text-lg mr-2"></i>
                                    총 아이템 수
                                </div>
                            </th>
                            <th class="px-6 py-4 text-center text-sm font-semibold text-gray-700 border-r border-gray-200">
                                <div class="flex items-center justify-center mb-2">
                                    <i class="fas fa-warehouse text-blue-600 text-lg mr-2"></i>
                                    <?php echo t('purchase.total_pieces_count'); ?>
                                </div>
                            </th>
                            <th class="px-6 py-4 text-center text-sm font-semibold text-gray-700">
                                <div class="flex items-center justify-center mb-2">
                                    <span class="text-green-600 text-lg mr-2 font-bold">₱</span>
                                    총 매입금액
                                </div>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white">
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-6 whitespace-nowrap text-center border-r border-gray-200">
                                <div class="text-3xl font-bold text-purple-600 mb-1"><?php echo number_format($total_products); ?></div>
                                <div class="text-sm text-gray-500">종</div>
                            </td>
                            <td class="px-6 py-6 whitespace-nowrap text-center border-r border-gray-200">
                                <div class="text-3xl font-bold text-orange-600 mb-1"><?php echo number_format($total_items); ?></div>
                                <div class="text-sm text-gray-500">개</div>
                            </td>
                            <td class="px-6 py-6 whitespace-nowrap text-center border-r border-gray-200">
                                <div class="text-3xl font-bold text-blue-600 mb-1"><?php echo number_format($total_pieces); ?></div>
                                <div class="text-sm text-gray-500">개</div>
                            </td>
                            <td class="px-6 py-6 whitespace-nowrap text-center">
                                <div class="text-3xl font-bold text-green-600 mb-1"><?php echo number_format($purchase['total_amount'], 2); ?></div>
                                <div class="text-sm text-gray-500">PHP</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
// PHP에서 JavaScript로 언어 데이터 전달
const lang = {
    js_confirm_delete: <?php echo json_encode(t('purchase.js_confirm_delete')); ?>,
    js_update_error: <?php echo json_encode(t('purchase.js_update_error')); ?>,
    js_quantity_required: <?php echo json_encode(t('purchase.js_quantity_required')); ?>,
    js_pieces_required: <?php echo json_encode(t('purchase.js_pieces_required')); ?>,
    js_price_required: <?php echo json_encode(t('purchase.js_price_required')); ?>,
    js_saving: <?php echo json_encode(t('purchase.js_saving')); ?>,
    js_save_success: <?php echo json_encode(t('purchase.js_save_success')); ?>,
    js_save_error: <?php echo json_encode(t('purchase.js_save_error')); ?>,
    js_no_changes: <?php echo json_encode(t('purchase.js_no_changes')); ?>,
    js_updating: <?php echo json_encode(t('purchase.js_updating')); ?>,
    js_deleting: <?php echo json_encode(t('purchase.js_deleting')); ?>,
    js_delete_success: <?php echo json_encode(t('purchase.js_delete_success')); ?>,
    js_delete_error: <?php echo json_encode(t('purchase.js_delete_error')); ?>,
    js_network_error: <?php echo json_encode(t('purchase.js_network_error')); ?>,
    js_apply_discount: <?php echo json_encode(t('purchase.js_apply_discount')); ?>,
    js_discount_applied: <?php echo json_encode(t('purchase.js_discount_applied')); ?>,
    js_confirm_delete_last_item: <?php echo json_encode(t('purchase.js_confirm_delete_last_item')); ?>,
    js_confirm_delete_item: <?php echo json_encode(t('purchase.js_confirm_delete_item')); ?>,
    js_confirm_delete_purchase: <?php echo json_encode(t('purchase.js_confirm_delete_purchase')); ?>,
    js_discount_rate_invalid: <?php echo json_encode(t('purchase.js_discount_rate_invalid')); ?>,
    js_confirm_apply_discount: <?php echo json_encode(t('purchase.js_confirm_apply_discount')); ?>
};

document.addEventListener('DOMContentLoaded', function() {
    
    // 변경사항 추적 객체
    const changedItems = new Map();
    
    // 변경사항 추적 함수
    function trackChange(itemId) {
        const row = document.getElementById('row-' + itemId);
        if (!row) return;
        
        // 라디오 버튼에서 선택된 값 가져오기
        const purchaseTypeRadio = row.querySelector('.type-input:checked');
        const purchaseType = purchaseTypeRadio ? purchaseTypeRadio.value : 'box';
        
        const quantity = row.querySelector('.quantity-input').value;
        const unitPrice = row.querySelector('.price-input').value;
        const piecesPerBox = row.querySelector('.pieces-input').value;
        const discountRate = row.querySelector('.discount-rate').value || 0;
        
        // 변경사항 저장
        changedItems.set(itemId, {
            quantity: quantity,
            unitPrice: unitPrice,
            purchaseType: purchaseType,
            piecesPerBox: piecesPerBox,
            discountRate: discountRate
        });
        
        // 행에 변경 표시 추가
        row.classList.add('bg-yellow-50');
        
        // 저장 버튼 활성화
        const saveButton = document.getElementById('save-all-changes');
        if (saveButton) {
            saveButton.classList.remove('opacity-50', 'cursor-not-allowed');
            saveButton.disabled = false;
        }
        
    }
    
    // 모든 변경사항 저장 함수
    function saveAllChanges() {
        if (changedItems.size === 0) {
            showNotification('변경된 항목이 없습니다.', 'info');
            return;
        }
        
        // 유효성 검사
        let hasError = false;
        changedItems.forEach((data, itemId) => {
            if (!data.quantity || data.quantity <= 0) {
                showNotification(lang.js_quantity_required.replace('{itemId}', itemId), 'error');
                hasError = true;
            }
            if (data.unitPrice === '' || parseFloat(data.unitPrice) < 0) {
                showNotification(lang.js_price_required.replace('{itemId}', itemId), 'error');
                hasError = true;
            }
            if (!data.piecesPerBox || data.piecesPerBox <= 0) {
                showNotification(lang.js_pieces_required.replace('{itemId}', itemId), 'error');
                hasError = true;
            }
        });
        
        if (hasError) return;
        
        // 폼 데이터 생성
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = window.location.pathname + window.location.search;
        
        // action 필드
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'update_multiple_items';
        form.appendChild(actionInput);
        
        // 변경된 각 항목 데이터 추가
        let index = 0;
        changedItems.forEach((data, itemId) => {
            form.innerHTML += `
                <input type="hidden" name="items[${index}][item_id]" value="${itemId}">
                <input type="hidden" name="items[${index}][quantity]" value="${data.quantity}">
                <input type="hidden" name="items[${index}][unit_price]" value="${data.unitPrice}">
                <input type="hidden" name="items[${index}][purchase_type]" value="${data.purchaseType}">
                <input type="hidden" name="items[${index}][pieces_per_box]" value="${data.piecesPerBox}">
                <input type="hidden" name="items[${index}][discount_rate]" value="${data.discountRate}">
            `;
            index++;
        });
        
        document.body.appendChild(form);
        
        // 저장 중 표시
        const saveButton = document.getElementById('save-all-changes');
        if (saveButton) {
            saveButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>' + lang.js_saving;
            saveButton.disabled = true;
        }
        
        form.submit();
    }
    
    // 알림 표시 함수
    function showNotification(message, type = 'info') {
        // 기존 알림 제거
        const existingNotification = document.querySelector('.notification-toast');
        if (existingNotification) {
            existingNotification.remove();
        }
        
        const notification = document.createElement('div');
        notification.className = `notification-toast fixed top-4 right-4 px-4 py-2 rounded-md text-white z-50 ${
            type === 'error' ? 'bg-red-500' : type === 'success' ? 'bg-green-500' : 'bg-blue-500'
        }`;
        notification.textContent = message;
        document.body.appendChild(notification);
        
        setTimeout(() => {
            notification.remove();
        }, 3000);
    }
    
    // 수량 입력 필드 변경 이벤트
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('quantity-input')) {
            const itemId = e.target.dataset.itemId;
            trackChange(itemId);
        }
    });
    
    // 단가 입력 필드 변경 이벤트
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('price-input')) {
            const itemId = e.target.dataset.itemId;
            trackChange(itemId);
        }
    });
    
    // 박스/개 입력 필드 변경 이벤트
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('pieces-input')) {
            const itemId = e.target.dataset.itemId;
            trackChange(itemId);
        }
    });
    
    // 입력 필드에서 엔터키 처리
    document.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            const target = e.target;
            const row = target.closest('tr[id^="row-"]');
            if (!row) return;
            
            // 수량 입력 필드에서 엔터 → 박스/개 입력 필드로
            if (target.classList.contains('quantity-input')) {
                e.preventDefault();
                const piecesInput = row.querySelector('.pieces-input');
                if (piecesInput) {
                    piecesInput.focus();
                    piecesInput.select();
                }
            }
            // 박스/개 입력 필드에서 엔터 → 단가 입력 필드로
            else if (target.classList.contains('pieces-input')) {
                e.preventDefault();
                const priceInput = row.querySelector('.price-input');
                if (priceInput) {
                    priceInput.focus();
                    priceInput.select();
                }
            }
            // 단가 입력 필드에서 엔터 → 할인율 입력 필드로
            else if (target.classList.contains('price-input')) {
                e.preventDefault();
                const discountInput = row.querySelector('.discount-rate');
                if (discountInput) {
                    discountInput.focus();
                    discountInput.select();
                }
            }
            // 할인율 입력 필드에서 엔터 → 다음 행의 수량 입력 필드로
            else if (target.classList.contains('discount-rate')) {
                e.preventDefault();
                const allRows = document.querySelectorAll('tr[id^="row-"]');
                const currentIndex = Array.from(allRows).indexOf(row);
                if (currentIndex < allRows.length - 1) {
                    const nextRow = allRows[currentIndex + 1];
                    const nextQuantityInput = nextRow.querySelector('.quantity-input');
                    if (nextQuantityInput) {
                        nextQuantityInput.focus();
                        nextQuantityInput.select();
                    }
                }
            }
        }
    });
    
    // 단위(박스/낱개) 라디오 버튼 변경 이벤트
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('type-input')) {
            const itemId = e.target.dataset.itemId;
            trackChange(itemId);
        }
    });
    
    // 저장 버튼 클릭 이벤트
    const saveAllButton = document.getElementById('save-all-changes');
    if (saveAllButton) {
        saveAllButton.addEventListener('click', function() {
            saveAllChanges();
        });
    }
    
    // 삭제 버튼 클릭 시 - 이벤트 위임 사용
    document.addEventListener('click', function(e) {
        // 삭제 버튼인지 확인
        if (e.target.closest('.delete-btn')) {
            e.preventDefault();
            const deleteBtn = e.target.closest('.delete-btn');
            const itemId = deleteBtn.dataset.itemId;
            
            // 현재 매입 상품 개수 확인
            const itemRows = document.querySelectorAll('tr[id^="row-"]');
            const currentItemCount = itemRows.length;
            
            let confirmMessage = '';
            if (currentItemCount <= 1) {
                // 마지막 상품을 삭제하는 경우
                confirmMessage = lang.js_confirm_delete_last_item;
            } else {
                // 일반적인 상품 삭제
                confirmMessage = lang.js_confirm_delete_item;
            }
            
            if (confirm(confirmMessage)) {
                
                // 기존 폼이 있다면 제거
                const existingForm = document.getElementById('delete-form');
                if (existingForm) {
                    existingForm.remove();
                }
                
                const form = document.createElement('form');
                form.id = 'delete-form';
                form.method = 'POST';
                form.action = window.location.pathname + window.location.search;
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_item">
                    <input type="hidden" name="item_id" value="${itemId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
    });
    
    // 전체 매입 내역 삭제 버튼 클릭 시 (버튼이 존재할 때만)
    const deletePurchaseBtn = document.getElementById('delete-purchase-btn');
    if (deletePurchaseBtn) {
        deletePurchaseBtn.addEventListener('click', function(e) {
            e.preventDefault();
        
        if (confirm(lang.js_confirm_delete_purchase)) {
            
            // 기존 폼이 있다면 제거
            const existingForm = document.getElementById('delete-purchase-form');
            if (existingForm) {
                existingForm.remove();
            }
            
            const form = document.createElement('form');
            form.id = 'delete-purchase-form';
            form.method = 'POST';
            form.action = window.location.pathname + window.location.search;
            form.innerHTML = `
                <input type="hidden" name="action" value="delete_purchase">
            `;
            document.body.appendChild(form);
            form.submit();
        }
        });
    }
    
    
    
    // 체크박스 기능
    const selectAllCheckbox = document.getElementById('select-all');
    const itemCheckboxes = document.querySelectorAll('.item-checkbox');
    const bulkDiscountButton = document.getElementById('apply-bulk-discount');
    const bulkDiscountInput = document.getElementById('bulk-discount-rate');
    
    // 전체 선택 체크박스 기능
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            itemCheckboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateBulkDiscountButton();
        });
    }
    
    // 개별 체크박스 기능
    itemCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const allChecked = Array.from(itemCheckboxes).every(cb => cb.checked);
            const noneChecked = Array.from(itemCheckboxes).every(cb => !cb.checked);
            
            if (selectAllCheckbox) {
                selectAllCheckbox.checked = allChecked;
                selectAllCheckbox.indeterminate = !allChecked && !noneChecked;
            }
            updateBulkDiscountButton();
            updateRowSelection(this.closest('tr'));
        });
    });
    
    // 체크박스 열 클릭으로 체크박스 선택/해제
    document.addEventListener('click', function(e) {
        // 체크박스 셀을 클릭했는지 확인 (첫 번째 td)
        const td = e.target.closest('td');
        if (!td) return;
        
        const row = td.closest('tr[id^="row-"]');
        if (!row) return;
        
        // 첫 번째 열(체크박스 열)인지 확인
        const isCheckboxColumn = td === row.cells[0];
        if (!isCheckboxColumn) return;
        
        // 체크박스 자체를 클릭한 경우는 기본 동작 유지
        if (e.target.type === 'checkbox') {
            return;
        }
        
        const checkbox = row.querySelector('.item-checkbox');
        if (checkbox) {
            checkbox.checked = !checkbox.checked;
            
            // 전체 체크박스 상태 업데이트
            const allChecked = Array.from(itemCheckboxes).every(cb => cb.checked);
            const noneChecked = Array.from(itemCheckboxes).every(cb => !cb.checked);
            
            if (selectAllCheckbox) {
                selectAllCheckbox.checked = allChecked;
                selectAllCheckbox.indeterminate = !allChecked && !noneChecked;
            }
            updateBulkDiscountButton();
            updateRowSelection(row);
        }
    });
    
    // 일괄 할인 버튼 상태 업데이트
    function updateBulkDiscountButton() {
        const checkedItems = document.querySelectorAll('.item-checkbox:checked');
        if (bulkDiscountButton) {
            bulkDiscountButton.disabled = checkedItems.length === 0;
        }
        
        // VAT 버튼도 함께 업데이트
        const bulkVatButton = document.getElementById('apply-bulk-vat');
        if (bulkVatButton) {
            bulkVatButton.disabled = checkedItems.length === 0;
        }
    }
    
    // 행 선택 상태에 따른 시각적 피드백
    function updateRowSelection(row) {
        const checkbox = row.querySelector('.item-checkbox');
        if (checkbox && checkbox.checked) {
            row.classList.add('bg-blue-50', 'border-l-4', 'border-l-blue-500');
            row.classList.remove('hover:bg-gray-50');
        } else {
            row.classList.remove('bg-blue-50', 'border-l-4', 'border-l-blue-500');
            row.classList.add('hover:bg-gray-50');
        }
    }
    
    // 일괄 할인 적용
    if (bulkDiscountButton) {
        bulkDiscountButton.addEventListener('click', function() {
            const discountRate = parseFloat(bulkDiscountInput.value) || 0;
            const checkedItems = document.querySelectorAll('.item-checkbox:checked');
            
            if (discountRate < 0 || discountRate > 100) {
                alert(lang.js_discount_rate_invalid);
                return;
            }
            
            // 선택된 아이템 ID 배열 생성
            const selectedItems = Array.from(checkedItems).map(cb => cb.value);
            
            if (confirm(lang.js_confirm_apply_discount.replace('{count}', checkedItems.length).replace('{rate}', discountRate))) {
                // 서버로 데이터 전송
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = window.location.pathname + window.location.search;
                
                // 액션 필드 추가
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'apply_bulk_discount';
                form.appendChild(actionInput);
                
                // 할인율 필드 추가
                const discountInput = document.createElement('input');
                discountInput.type = 'hidden';
                discountInput.name = 'bulk_discount_rate';
                discountInput.value = discountRate;
                form.appendChild(discountInput);
                
                // 선택된 아이템들 추가
                selectedItems.forEach(itemId => {
                    const itemInput = document.createElement('input');
                    itemInput.type = 'hidden';
                    itemInput.name = 'selected_items[]';
                    itemInput.value = itemId;
                    form.appendChild(itemInput);
                });
                
                document.body.appendChild(form);
                form.submit();
            }
        });
    }
    
    // 일괄 VAT 적용
    const bulkVatButton = document.getElementById('apply-bulk-vat');
    if (bulkVatButton) {
        bulkVatButton.addEventListener('click', function() {
            const checkedItems = document.querySelectorAll('.item-checkbox:checked');
            
            if (checkedItems.length === 0) {
                alert('선택된 상품이 없습니다.');
                return;
            }
            
            // 선택된 아이템 ID 배열 생성
            const selectedItems = Array.from(checkedItems).map(cb => cb.value);
            
            const confirmMessage = `선택된 ${checkedItems.length}개 상품에 VAT(12%)를 적용하시겠습니까?\n\n예시: 1,000원 → 1,120원\n\n주의: VAT 비적용 상품(쌀 등)은 자동으로 제외됩니다.`;
            
            if (confirm(confirmMessage)) {
                // 서버로 데이터 전송
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = window.location.pathname + window.location.search;
                
                // 액션 필드 추가
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'apply_bulk_vat';
                form.appendChild(actionInput);
                
                // 선택된 아이템들 추가
                selectedItems.forEach(itemId => {
                    const itemInput = document.createElement('input');
                    itemInput.type = 'hidden';
                    itemInput.name = 'selected_items[]';
                    itemInput.value = itemId;
                    form.appendChild(itemInput);
                });
                
                document.body.appendChild(form);
                form.submit();
            }
        });
    }
    
    // 실시간 계산을 위한 이벤트 리스너
    document.addEventListener('input', function(e) {
        const row = e.target.closest('tr');
        if (!row) return;
        
        const itemId = row.id.replace('row-', '');
        
        // 수량, 단가, 박스당개수 변경 시 실시간 계산
        if (e.target.classList.contains('quantity-input') || 
            e.target.classList.contains('price-input') || 
            e.target.classList.contains('pieces-input')) {
            calculateRowTotal(row);
            trackChange(itemId);
            updatePageTotal(); // 전체 합계 업데이트
        }
        
        // 할인율 변경 시
        if (e.target.classList.contains('discount-rate')) {
            updateDiscountedTotal(row);
            trackChange(itemId);
            updatePageTotal(); // 전체 합계 업데이트
        }
    });
    
    // 구매 유형 변경 시 실시간 계산
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('type-input')) {
            const row = e.target.closest('tr');
            const itemId = row.id.replace('row-', '');
            calculateRowTotal(row);
            trackChange(itemId);
            updatePageTotal(); // 전체 합계 업데이트
        }
    });
    
    // 행별 합계 계산
    function calculateRowTotal(row) {
        const quantityInput = row.querySelector('.quantity-input');
        const priceInput = row.querySelector('.price-input');
        const piecesInput = row.querySelector('.pieces-input');
        const typeInput = row.querySelector('.type-input:checked');
        const discountRateInput = row.querySelector('.discount-rate');
        
        const quantity = parseFloat(quantityInput.value) || 0;
        const unitPrice = parseFloat(priceInput.value) || 0;
        const piecesPerBox = parseFloat(piecesInput.value) || 1;
        const purchaseType = typeInput ? typeInput.value : 'piece';
        const discountRate = parseFloat(discountRateInput.value) || 0;
        
        // 개당 가격 계산
        let piecePrice = unitPrice;
        if (purchaseType === 'box' && piecesPerBox > 0) {
            piecePrice = unitPrice / piecesPerBox;
        }
        
        // 총 개수 계산
        let totalPieces = quantity;
        if (purchaseType === 'box') {
            totalPieces = quantity * piecesPerBox;
        }
        
        // 합계 계산
        const total = quantity * unitPrice;
        
        // 할인 후 합계 계산
        const discountedTotal = total * (1 - discountRate / 100);
        
        // UI 업데이트
        // 개당 가격 (8번째 셀)
        row.cells[7].textContent = new Intl.NumberFormat('ko-KR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(piecePrice);
        
        // 총 개수 (9번째 셀)
        row.cells[8].innerHTML = `<span class="text-blue-600">${new Intl.NumberFormat('ko-KR').format(totalPieces)}</span>`;
        
        // 합계 (10번째 셀)
        row.cells[9].textContent = new Intl.NumberFormat('ko-KR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(total);
        
        // 할인 후 합계 (12번째 셀)
        row.querySelector('.discounted-total').textContent = new Intl.NumberFormat('ko-KR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(discountedTotal);
    }
    
    // 할인후 합계 계산 및 업데이트
    function updateDiscountedTotal(row) {
        const originalTotalCell = row.cells[9]; // 합계 셀
        const discountRateInput = row.querySelector('.discount-rate');
        const discountedTotalCell = row.querySelector('.discounted-total');
        
        if (originalTotalCell && discountRateInput && discountedTotalCell) {
            const originalTotal = parseFloat(originalTotalCell.textContent.replace(/,/g, '')) || 0;
            const discountRate = parseFloat(discountRateInput.value) || 0;
            const discountedTotal = originalTotal * (1 - discountRate / 100);
            
            discountedTotalCell.textContent = new Intl.NumberFormat('ko-KR', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }).format(discountedTotal);
        }
    }
    
    // 페이지 전체 합계 업데이트
    function updatePageTotal() {
        let totalAmount = 0;
        let totalPieces = 0;
        let totalDiscountedAmount = 0;
        
        document.querySelectorAll('tr[id^="row-"]').forEach(row => {
            // 합계 (10번째 셀)
            const total = parseFloat(row.cells[9].textContent.replace(/,/g, '')) || 0;
            totalAmount += total;
            
            // 총 개수 (9번째 셀)
            const pieces = parseFloat(row.cells[8].textContent.replace(/,/g, '')) || 0;
            totalPieces += pieces;
            
            // 할인 후 합계
            const discountedTotal = parseFloat(row.querySelector('.discounted-total').textContent.replace(/,/g, '')) || 0;
            totalDiscountedAmount += discountedTotal;
        });
        
        // 상단 총 금액 업데이트
        const totalAmountElement = document.querySelector('.total-amount');
        if (totalAmountElement) {
            totalAmountElement.textContent = new Intl.NumberFormat('ko-KR', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }).format(totalAmount);
        }
        
        // 요약 테이블 업데이트 (있을 경우)
        const summaryTotalElement = document.querySelector('.summary-total-amount');
        if (summaryTotalElement) {
            summaryTotalElement.textContent = new Intl.NumberFormat('ko-KR', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }).format(totalAmount);
        }
        
        const summaryTotalPiecesElement = document.querySelector('.summary-total-pieces');
        if (summaryTotalPiecesElement) {
            summaryTotalPiecesElement.textContent = new Intl.NumberFormat('ko-KR').format(totalPieces);
        }
        
        const summaryDiscountedTotalElement = document.querySelector('.summary-discounted-total');
        if (summaryDiscountedTotalElement) {
            summaryDiscountedTotalElement.textContent = new Intl.NumberFormat('ko-KR', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }).format(totalDiscountedAmount);
        }
    }
    
    
    // 페이지 로드 시 초기화
    updateBulkDiscountButton();
    
    // 모든 행의 초기 선택 상태 설정
    document.querySelectorAll('tr[id^="row-"]').forEach(row => {
        updateRowSelection(row);
    });
    
    
    // 상품명 인라인 편집 기능 추가 (한글, 영문)
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('product-name-editable') || e.target.classList.contains('product-name-en-editable')) {
            const nameDiv = e.target;
            const currentName = nameDiv.textContent.trim();
            const productId = nameDiv.dataset.productId;
            const itemId = nameDiv.dataset.itemId;
            const isEnglish = nameDiv.classList.contains('product-name-en-editable');
            
            // 이미 편집 모드인 경우 무시
            if (nameDiv.querySelector('input')) {
                return;
            }
            
            // 입력 필드 생성
            const input = document.createElement('input');
            input.type = 'text';
            input.value = currentName;
            input.className = 'w-full px-2 py-1 border border-indigo-500 rounded text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500';
            
            // 원래 내용 숨기기
            nameDiv.innerHTML = '';
            nameDiv.appendChild(input);
            nameDiv.classList.add('editing');
            
            // 입력 필드에 포커스
            input.focus();
            input.select();
            
            // 저장 함수
            function saveName() {
                const newName = input.value.trim();
                if (newName === '' || newName === currentName) {
                    // 변경사항 없음 또는 빈 값
                    cancelEdit();
                    return;
                }
                
                // AJAX로 서버에 전송
                const formData = new FormData();
                formData.append('action', 'update_product_name');
                formData.append('product_id', productId);
                formData.append('item_id', itemId);
                formData.append('new_name', newName);
                formData.append('field_type', isEnglish ? 'en' : 'ko');
                
                // URL에서 purchase_id 추출
                const urlParams = new URLSearchParams(window.location.search);
                const purchaseId = urlParams.get('id');
                if (purchaseId) {
                    formData.append('purchase_id', purchaseId);
                }
                
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // 성공 시 화면 업데이트
                        nameDiv.textContent = newName;
                        nameDiv.dataset.originalName = newName;
                        nameDiv.classList.remove('editing');
                        
                        // 성공 피드백
                        const successMessage = isEnglish ? '영문 상품명이 성공적으로 수정되었습니다.' : '상품명이 성공적으로 수정되었습니다.';
                        showFeedback(successMessage, 'success');
                    } else {
                        // 실패 시 원래 값으로 복원
                        cancelEdit();
                        showFeedback(data.error || '상품명 수정에 실패했습니다.', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    cancelEdit();
                    showFeedback('서버 통신 오류가 발생했습니다.', 'error');
                });
            }
            
            // 취소 함수
            function cancelEdit() {
                nameDiv.textContent = currentName;
                nameDiv.classList.remove('editing');
            }
            
            // 키보드 이벤트
            input.addEventListener('keydown', function(e) {
                e.stopPropagation();
                if (e.key === 'Enter') {
                    e.preventDefault();
                    saveName();
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    cancelEdit();
                }
            });
            
            // 포커스 잃을 때 저장
            input.addEventListener('blur', function() {
                setTimeout(saveName, 100); // 약간의 지연을 주어 다른 클릭 이벤트 처리
            });
        }
    });
    
    // 피드백 메시지 표시 함수
    function showFeedback(message, type = 'success') {
        const feedbackDiv = document.createElement('div');
        feedbackDiv.className = `fixed top-4 right-4 px-4 py-2 rounded shadow-lg z-50 ${type === 'success' ? 'bg-green-500 text-white' : 'bg-red-500 text-white'}`;
        feedbackDiv.textContent = message;
        
        document.body.appendChild(feedbackDiv);
        
        // 3초 후 자동 제거
        setTimeout(() => {
            if (feedbackDiv.parentNode) {
                feedbackDiv.parentNode.removeChild(feedbackDiv);
            }
        }, 3000);
    }
    
    // 페이지 로드 완료 후 디버깅 정보 출력
});
</script>

<?php 
$conn->close();
require_once __DIR__ . '/partials/footer.php';
?>