<?php
// Design Ref: §5.3 — 주문서 엑셀 생성 + 이력 저장
// Plan SC: 업체별 주문서 다운로드 시 원본 서식 100% 유지
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/order_helper.php';
ord_require_manager();
ord_verify_csrf();

$vendorId    = (int)($_POST['vendor_id'] ?? 0);
$saveHistory = (bool)($_POST['save_history'] ?? true);
$storeId     = ord_current_store_id();

if (!$vendorId || !$storeId) {
    http_response_code(400);
    echo '잘못된 요청입니다.';
    exit;
}

try {
    $conn = get_ord_db();

    // 업체명 조회
    $stmt = $conn->prepare("SELECT name FROM order_vendors WHERE id = ?");
    $stmt->bind_param('i', $vendorId);
    $stmt->execute();
    $stmt->bind_result($vendorName);
    $stmt->fetch();
    $stmt->close();

    if (!$vendorName) { throw new Exception('업체를 찾을 수 없습니다.'); }

    // 컬럼 매핑
    $stmt = $conn->prepare("SELECT * FROM order_vendor_column_maps WHERE vendor_id = ?");
    $stmt->bind_param('i', $vendorId);
    $stmt->execute();
    $colMap = $stmt->get_result()->fetch_object();
    $stmt->close();
    if (!$colMap) { throw new Exception('컬럼 설정이 없습니다.'); }

    // 최신 재고 파일
    $stmt = $conn->prepare("SELECT id, stored_filepath, original_filename FROM order_vendor_inventories WHERE vendor_id = ? AND is_current = 1 ORDER BY created_at DESC LIMIT 1");
    $stmt->bind_param('i', $vendorId);
    $stmt->execute();
    $inv = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$inv) { throw new Exception('최신 재고 파일이 없습니다. 재고를 먼저 업로드해주세요.'); }

    $originalPath = dirname(__DIR__, 2) . '/' . $inv['stored_filepath'];
    if (!file_exists($originalPath)) { throw new Exception('원본 파일을 찾을 수 없습니다. 재업로드가 필요합니다.'); }

    // 장바구니 아이템 조회
    $stmt = $conn->prepare("
        SELECT c.quantity, i.`row_number`, i.product_name, i.product_code, i.unit_price, i.unit
        FROM order_cart_items c
        JOIN order_vendor_inventory_items i ON c.inventory_item_id = i.id
        WHERE c.store_id = ? AND c.vendor_id = ?
        ORDER BY i.`row_number`
    ");
    $stmt->bind_param('ii', $storeId, $vendorId);
    $stmt->execute();
    $cartItems = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($cartItems)) { throw new Exception('장바구니에 상품이 없습니다.'); }

    // 주문서 엑셀 생성 (대용량 재고 파일은 시간이 걸릴 수 있어 DB 커넥션이 유휴 상태로 끊길 수 있음)
    $tmpFile = ord_generate_order_file($originalPath, $colMap, $cartItems);

    // 이력 저장 — 실패해도 다운로드 자체는 막지 않는다 (부가 기능)
    if ($saveHistory) {
        try {
            // 파일 생성 중 시간이 오래 걸리면 기존 커넥션이 서버 측에서 끊겨
            // "MySQL server has gone away" 오류로 이어질 수 있으므로 재연결한다.
            $conn->close();
            $conn = get_ord_db();

            // 엑셀 파일의 인코딩 문제로 유효하지 않은 UTF-8 바이트가 섞여 들어오면
            // json_encode()가 false를 반환해 items_json(JSON 컬럼)에 빈 문자열이 저장되며
            // "Invalid JSON text" SQL 에러로 이어지므로 사전에 정리한다.
            array_walk_recursive($cartItems, function (&$v) {
                if (is_string($v) && !mb_check_encoding($v, 'UTF-8')) {
                    $v = iconv('UTF-8', 'UTF-8//IGNORE', $v);
                }
            });
            $itemsJson = json_encode($cartItems, JSON_UNESCAPED_UNICODE);
            if ($itemsJson === false) {
                throw new Exception('주문 내역을 JSON으로 변환하지 못했습니다 (원인: ' . json_last_error_msg() . ')');
            }
            $itemCount = count($cartItems);
            $invId     = (int)$inv['id'];
            $userId    = ord_current_user_id();
            $stmt = $conn->prepare("INSERT INTO order_history (store_id, vendor_id, vendor_name, ordered_by, items_json, item_count, inventory_id) VALUES (?,?,?,?,?,?,?)");
            $stmt->bind_param('iisisii', $storeId, $vendorId, $vendorName, $userId, $itemsJson, $itemCount, $invId);
            $stmt->execute();
            $stmt->close();
        } catch (\Throwable $historyError) {
            error_log('order_history 저장 실패 (vendor_id=' . $vendorId . '): ' . $historyError->getMessage());
        }
    }

    try { $conn->close(); } catch (\Throwable $ignore) {}

    // 다운로드 응답
    $downloadName = date('Ymd') . '_' . preg_replace('/[^a-zA-Z0-9가-힣_-]/', '_', $vendorName) . '_발주서.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName) . '"');
    header('Content-Length: ' . filesize($tmpFile));
    header('Cache-Control: no-cache');
    readfile($tmpFile);
    unlink($tmpFile);
    exit;

} catch (Exception $e) {
    http_response_code(400);
    // 오류 시 JS alert으로 표시될 수 있도록 Content-Type 변경
    header('Content-Type: text/html; charset=utf-8');
    echo '<script>alert(' . json_encode($e->getMessage()) . '); history.back();</script>';
}
