<?php
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

// 로그인 체크 및 권한 확인
if (!check_login_redirect(false)) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (!has_permission('admin_access')) {
    http_response_code(403);
    echo json_encode(['error' => 'No permission']);
    exit;
}

$conn = get_db_connection();
$action = $_GET['action'] ?? '';
$store_id = $_SESSION['store_id'] ?? null;

if ($action == 'list') {
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;
    
    $status_filter = $_GET['status'] ?? '';
    $date_filter = $_GET['date'] ?? '';
    $search_filter = $_GET['search'] ?? '';
    
    // 조건 생성
    $where_conditions = ["o.store_id = ?"];
    $params = [$store_id];
    $param_types = "i";
    
    if ($status_filter) {
        $where_conditions[] = "o.order_status = ?";
        $params[] = $status_filter;
        $param_types .= "s";
    }
    
    if ($date_filter) {
        $where_conditions[] = "DATE(o.order_date) = ?";
        $params[] = $date_filter;
        $param_types .= "s";
    }
    
    if ($search_filter) {
        $where_conditions[] = "(o.order_number LIKE ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ?)";
        $search_param = "%{$search_filter}%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $param_types .= "sss";
    }
    
    $where_clause = implode(" AND ", $where_conditions);
    
    // 전체 개수 조회
    $count_query = "SELECT COUNT(*) as total FROM orders o WHERE {$where_clause}";
    $count_stmt = $conn->prepare($count_query);
    $count_stmt->bind_param($param_types, ...$params);
    $count_stmt->execute();
    $total_count = $count_stmt->get_result()->fetch_assoc()['total'];
    $count_stmt->close();
    
    // 주문 목록 조회
    $query = "
        SELECT o.*, s.name as store_name
        FROM orders o
        JOIN stores s ON o.store_id = s.id
        WHERE {$where_clause}
        ORDER BY o.order_date DESC
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $limit;
    $params[] = $offset;
    $param_types .= "ii";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $orders = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // 상태 라벨
    $status_labels = [
        'pending' => '주문접수',
        'confirmed' => '주문확인',
        'preparing' => '상품준비중',
        'shipping' => '배송중',
        'delivered' => '배송완료',
        'cancelled' => '주문취소'
    ];
    
    $payment_labels = [
        'card' => '카드결제',
        'cash' => '현금결제',
        'transfer' => '계좌이체',
        'mobile' => '휴대폰결제'
    ];
    
    // HTML 생성
    ob_start();
    
    if (empty($orders)) {
        echo '<tr><td colspan="7" class="text-center text-muted py-4">주문이 없습니다.</td></tr>';
    } else {
        foreach ($orders as $order) {
            $status_badge_class = match($order['order_status']) {
                'pending' => 'bg-warning',
                'confirmed' => 'bg-info',
                'preparing' => 'bg-primary',
                'shipping' => 'bg-primary',
                'delivered' => 'bg-success',
                'cancelled' => 'bg-danger',
                default => 'bg-secondary'
            };
            ?>
            <tr>
                <td>
                    <strong><?php echo htmlspecialchars($order['order_number']); ?></strong>
                </td>
                <td>
                    <div>
                        <strong><?php echo htmlspecialchars($order['customer_name']); ?></strong><br>
                        <small class="text-muted"><?php echo htmlspecialchars($order['customer_phone']); ?></small>
                        <?php if ($order['customer_email']): ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars($order['customer_email']); ?></small>
                        <?php endif; ?>
                    </div>
                </td>
                <td>
                    <div>
                        <?php echo date('Y-m-d', strtotime($order['order_date'])); ?><br>
                        <small class="text-muted"><?php echo date('H:i', strtotime($order['order_date'])); ?></small>
                    </div>
                </td>
                <td>
                    <span class="badge <?php echo $status_badge_class; ?>">
                        <?php echo $status_labels[$order['order_status']] ?? $order['order_status']; ?>
                    </span>
                </td>
                <td><?php echo $payment_labels[$order['payment_method']] ?? $order['payment_method']; ?></td>
                <td class="text-end">
                    <strong><?php echo number_format($order['final_amount'], 0); ?>원</strong>
                </td>
                <td>
                    <div class="btn-group btn-group-sm">
                        <a href="orders.php?action=detail&id=<?php echo $order['id']; ?>" class="btn btn-outline-primary">
                            <i class="fas fa-eye"></i>
                        </a>
                        <button class="btn btn-outline-secondary" onclick="quickStatusUpdate(<?php echo $order['id']; ?>, '<?php echo $order['order_status']; ?>')">
                            <i class="fas fa-edit"></i>
                        </button>
                    </div>
                </td>
            </tr>
            <?php
        }
    }
    
    $table_html = ob_get_clean();
    
    // 페이지네이션 생성
    ob_start();
    
    if ($total_count > $limit) {
        $total_pages = ceil($total_count / $limit);
        $start_page = max(1, $page - 2);
        $end_page = min($total_pages, $page + 2);
        
        echo '<ul class="pagination justify-content-center mb-0">';
        
        // 이전 페이지
        if ($page > 1) {
            echo '<li class="page-item"><a class="page-link" href="#" onclick="loadOrders(' . ($page - 1) . ')">이전</a></li>';
        }
        
        // 페이지 번호
        for ($i = $start_page; $i <= $end_page; $i++) {
            $active_class = $i == $page ? 'active' : '';
            echo '<li class="page-item ' . $active_class . '"><a class="page-link" href="#" onclick="loadOrders(' . $i . ')">' . $i . '</a></li>';
        }
        
        // 다음 페이지
        if ($page < $total_pages) {
            echo '<li class="page-item"><a class="page-link" href="#" onclick="loadOrders(' . ($page + 1) . ')">다음</a></li>';
        }
        
        echo '</ul>';
        
        // 페이지 정보
        echo '<div class="text-center mt-2"><small class="text-muted">';
        echo '전체 ' . number_format($total_count) . '개 주문 중 ' . (($page - 1) * $limit + 1) . '-' . min($page * $limit, $total_count) . '번째';
        echo '</small></div>';
    }
    
    $pagination_html = ob_get_clean();
    
    // 응답 반환
    echo json_encode([
        'html' => $table_html,
        'pagination' => $pagination_html,
        'total' => $total_count
    ]);
    
} elseif ($action == 'quick_status_update') {
    $order_id = $_POST['order_id'] ?? null;
    $new_status = $_POST['status'] ?? null;
    
    if (!$order_id || !$new_status) {
        echo json_encode(['success' => false, 'message' => '필수 정보가 누락되었습니다.']);
        exit;
    }
    
    // 주문 상태 업데이트
    $update_stmt = $conn->prepare("UPDATE orders SET order_status = ?, updated_at = NOW() WHERE id = ? AND store_id = ?");
    $update_stmt->bind_param("sii", $new_status, $order_id, $store_id);
    
    if ($update_stmt->execute()) {
        echo json_encode(['success' => true, 'message' => '주문 상태가 업데이트되었습니다.']);
    } else {
        echo json_encode(['success' => false, 'message' => '상태 업데이트에 실패했습니다.']);
    }
    
    $update_stmt->close();
    
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
}

$conn->close();
?>