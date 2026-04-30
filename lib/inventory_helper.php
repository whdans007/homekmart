<?php
/**
 * Inventory Helper - 재고 관리 유틸리티 (유통기한별 Lot 관리 포함)
 */

if (!function_exists('get_db_connection')) {
    require_once __DIR__ . '/../config/db_config.php';
}

/**
 * 선입선출(FIFO) 기반 유통기한별 재고 차감 (안전 장치 포함)
 *
 * @param mixed $conn 데이터베이스 커넥션 객체 (mysqli 또는 PDO)
 * @param int $store_id 점포 ID
 * @param int $product_id 상품 ID
 * @param int $quantity 차감할 수량 (양수)
 * @return array 차감된 이력 (각 유통기한별로 얼마나 차감되었는지 기록) 반환
 * @throws Exception
 */
function deduct_inventory_by_expiration($conn, $store_id, $product_id, $quantity) {
    if ($quantity <= 0) {
        return [];
    }
    
    $remaining_qty = $quantity;
    $deduction_log = [];
    $is_pdo = ($conn instanceof PDO);

    // 1. 유통기한이 가장 임박한(오래된) 재고부터 순서대로 조회 (가장 임박한 것 먼저 소진)
    // 수량이 0 초과인 로트만 조회
    $sql = "SELECT id, expiration_date, quantity 
            FROM inventory_expirations 
            WHERE store_id = ? AND product_id = ? AND quantity > 0
            ORDER BY expiration_date ASC";
    
    $available_lots = [];
    if ($is_pdo) {
        $stmt = $conn->prepare($sql);
        $stmt->execute([$store_id, $product_id]);
        $available_lots = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $store_id, $product_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $available_lots[] = $row;
        }
        $stmt->close();
    }

    // 2. 가용 롯트들을 돌면서 차감
    $update_sql = "UPDATE inventory_expirations SET quantity = quantity - ? WHERE id = ?";
    foreach ($available_lots as $lot) {
        if ($remaining_qty <= 0) break;

        $deduct_amount = min($lot['quantity'], $remaining_qty);
        
        if ($is_pdo) {
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->execute([$deduct_amount, $lot['id']]);
        } else {
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("ii", $deduct_amount, $lot['id']);
            $update_stmt->execute();
            $update_stmt->close();
        }
        
        $deduction_log[] = [
            'id' => $lot['id'],
            'expiration_date' => $lot['expiration_date'],
            'deducted' => $deduct_amount
        ];
        
        $remaining_qty -= $deduct_amount;
    }

    // 3. (안전장치) 여전히 차감해야 할 수량이 남은 경우 - 재고 관리 오류 상황
    if ($remaining_qty > 0) {
        // 기존 롯트 중 가장 최근(오래된) 것 하나 찾기
        $fallback_sql = "SELECT id, expiration_date 
                         FROM inventory_expirations 
                         WHERE store_id = ? AND product_id = ? 
                         ORDER BY expiration_date ASC LIMIT 1";
                         
        $fallback_lot_id = null;
        $fallback_lot_expiration_date = null;
        $has_fallback = false;

        if ($is_pdo) {
            $fallback_stmt = $conn->prepare($fallback_sql);
            $fallback_stmt->execute([$store_id, $product_id]);
            if ($row = $fallback_stmt->fetch(PDO::FETCH_ASSOC)) {
                $has_fallback = true;
                $fallback_lot_id = $row['id'];
                $fallback_lot_expiration_date = $row['expiration_date'];
            }
        } else {
            $fallback_stmt = $conn->prepare($fallback_sql);
            $fallback_stmt->bind_param("ii", $store_id, $product_id);
            $fallback_stmt->execute();
            $fallback_stmt->bind_result($fallback_lot_id, $fallback_lot_expiration_date);
            $has_fallback = $fallback_stmt->fetch();
            $fallback_stmt->close();
        }
        
        if ($has_fallback) {
            // 해당 롯트 재고를 음수로 만듦
            if ($is_pdo) {
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->execute([$remaining_qty, $fallback_lot_id]);
            } else {
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("ii", $remaining_qty, $fallback_lot_id);
                $update_stmt->execute();
                $update_stmt->close();
            }
            
            $deduction_log[] = [
                'id' => $fallback_lot_id,
                'expiration_date' => $fallback_lot_expiration_date,
                'deducted' => $remaining_qty,
                'is_negative_fallback' => true // 안전 장치 발동 기록
            ];
            
            $remaining_qty = 0;
        } else {
            // 아예 등록된 로트가 없었다면 시스템 중단을 막기 위해 오늘 기준 30일 뒤 날짜로 생성
            $default_exp_date = date('Y-m-d', strtotime('+30 days'));
            $neg_qty = -$remaining_qty;
            
            $insert_sql = "INSERT INTO inventory_expirations (store_id, product_id, expiration_date, quantity)
                           VALUES (?, ?, ?, ?)";
                           
            if ($is_pdo) {
                $insert_stmt = $conn->prepare($insert_sql);
                $insert_stmt->execute([$store_id, $product_id, $default_exp_date, $neg_qty]);
                $new_lot_id = $conn->lastInsertId();
            } else {
                $insert_stmt = $conn->prepare($insert_sql);
                $insert_stmt->bind_param("iisi", $store_id, $product_id, $default_exp_date, $neg_qty);
                $insert_stmt->execute();
                $new_lot_id = $insert_stmt->insert_id;
                $insert_stmt->close();
            }
            
            $deduction_log[] = [
                'id' => $new_lot_id,
                'expiration_date' => $default_exp_date,
                'deducted' => $remaining_qty,
                'is_negative_fallback' => true,
                'is_created_fallback' => true
            ];
            
            $remaining_qty = 0;
        }
    }

    return $deduction_log;
}

/**
 * 유통기한별 재고 증가 (매입/취소/환불 시)
 * 
 * @param mixed $conn (mysqli 또는 PDO)
 * @param int $store_id
 * @param int $product_id
 * @param string $expiration_date (Y-m-d)
 * @param int $quantity (양수)
 */
function add_inventory_by_expiration($conn, $store_id, $product_id, $expiration_date, $quantity) {
    if ($quantity <= 0 || empty($expiration_date)) return false;

    $is_pdo = ($conn instanceof PDO);

    $sql = "INSERT INTO inventory_expirations (store_id, product_id, expiration_date, quantity)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)";
            
    if ($is_pdo) {
        $stmt = $conn->prepare($sql);
        return $stmt->execute([$store_id, $product_id, $expiration_date, $quantity]);
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iisi", $store_id, $product_id, $expiration_date, $quantity);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }
}
