<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

// 로그인 체크 및 권한 확인
ensure_logged_in();

require_permission('admin_access', '../login.php');

$conn = get_db_connection();
$page_title = "주문 관리";
$current_page = "orders";

// 액션 처리
$action = $_GET['action'] ?? 'list';
$order_id = $_GET['id'] ?? null;

// 안전한 store_id 가져오기
$store_id = null;
if (isset($_SESSION['store_id'])) {
    $store_id = $_SESSION['store_id'];
} else {
    // 세션에 store_id가 없으면 사용자의 기본 점포를 찾아서 설정
    if (isset($_SESSION['user_id'])) {
        $user_stmt = $conn->prepare("SELECT store_id FROM users WHERE id = ?");
        $user_stmt->bind_param("i", $_SESSION['user_id']);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        if ($user_row = $user_result->fetch_assoc()) {
            $store_id = $user_row['store_id'];
            $_SESSION['store_id'] = $store_id;
        }
        $user_stmt->close();
    }
    
    // 여전히 store_id가 없으면 첫 번째 활성 점포 사용
    if (!$store_id) {
        $store_result = $conn->query("SELECT id FROM stores WHERE is_active = 1 ORDER BY id LIMIT 1");
        if ($store_row = $store_result->fetch_assoc()) {
            $store_id = $store_row['id'];
            $_SESSION['store_id'] = $store_id;
        }
    }
}

// 주문 상태 업데이트 처리
if ($_POST && $action == 'update_status' && $order_id && $store_id) {
    $new_status = $_POST['order_status'];
    $notes = $_POST['notes'] ?? '';
    
    $update_stmt = $conn->prepare("UPDATE orders SET order_status = ?, notes = ?, updated_at = NOW() WHERE id = ? AND store_id = ?");
    $update_stmt->bind_param("ssii", $new_status, $notes, $order_id, $store_id);
    
    if ($update_stmt->execute()) {
        $_SESSION['flash_message'] = "주문 상태가 업데이트되었습니다.";
    } else {
        $_SESSION['flash_error'] = "주문 상태 업데이트에 실패했습니다.";
    }
    
    header("Location: orders_fixed.php?action=detail&id=" . $order_id);
    exit;
}

<?php
// 주문 목록 데이터를 header.php 전에 조회
$orders_data = [];
$error_message = '';

try {
    // 주문 목록 조회 (store_id 조건 추가)
    $where_condition = $store_id ? "WHERE o.store_id = ?" : "";
    $query = "
        SELECT o.*, s.name as store_name
        FROM orders o
        LEFT JOIN stores s ON o.store_id = s.id
        $where_condition
        ORDER BY o.order_date DESC
        LIMIT 10
    ";
    
    $stmt = $conn->prepare($query);
    if ($store_id) {
        $stmt->bind_param("i", $store_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $orders_data = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
} catch (Exception $e) {
    $error_message = $e->getMessage();
}

include 'partials/header.php';
?>

<div class="container-fluid">
    <div class="alert alert-info mb-4">
        <strong>디버깅 정보:</strong> 
        Store ID: <?php echo $store_id ? $store_id : 'NULL'; ?>, 
        User ID: <?php echo $_SESSION['user_id'] ?? 'NULL'; ?>,
        Action: <?php echo $action; ?>
    </div>

    <?php if ($action == 'list'): ?>
        <!-- 주문 목록 -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-shopping-cart me-2"></i>주문 관리 (수정 버전)</h2>
        </div>

        <!-- 주문 목록 테이블 -->
        <div class="card">
            <div class="card-body">
                <?php if ($error_message): ?>
                    <div class="alert alert-danger">오류: <?php echo $error_message; ?></div>
                <?php elseif (!empty($orders_data)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr><th>주문번호</th><th>고객명</th><th>주문일시</th><th>상태</th><th>금액</th><th>점포</th><th>관리</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orders_data as $order): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($order['order_number']); ?></td>
                                        <td><?php echo htmlspecialchars($order['customer_name']); ?></td>
                                        <td><?php echo date('Y-m-d H:i', strtotime($order['order_date'])); ?></td>
                                        <td><span class="badge bg-primary"><?php echo htmlspecialchars($order['order_status']); ?></span></td>
                                        <td><?php echo number_format($order['final_amount']); ?>원</td>
                                        <td><?php echo htmlspecialchars($order['store_name'] ?? 'N/A'); ?></td>
                                        <td><a href="?action=detail&id=<?php echo $order['id']; ?>" class="btn btn-sm btn-outline-primary">상세</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">주문이 없습니다.</div>
                <?php endif; ?>
                
                <div class="mt-3">
                    <a href="../shop/index.php" class="btn btn-success">쇼핑몰로 이동</a>
                    <a href="display_sections.php" class="btn btn-primary">진열 관리</a>
                </div>
            </div>
        </div>

    <?php elseif ($action == 'detail' && $order_id): ?>
        <!-- 주문 상세 -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <a href="orders_fixed.php" class="btn btn-outline-secondary me-3">
                    <i class="fas fa-arrow-left me-1"></i>목록으로
                </a>
                <h2><i class="fas fa-receipt me-2"></i>주문 상세 정보</h2>
            </div>
        </div>

        <?php
        try {
            // 주문 상세 정보 조회
            $order_query = "
                SELECT o.*, s.name as store_name, s.address as store_address, s.phone as store_phone
                FROM orders o
                LEFT JOIN stores s ON o.store_id = s.id
                WHERE o.id = ?" . ($store_id ? " AND o.store_id = ?" : "");
                
            $order_stmt = $conn->prepare($order_query);
            if ($store_id) {
                $order_stmt->bind_param("ii", $order_id, $store_id);
            } else {
                $order_stmt->bind_param("i", $order_id);
            }
            $order_stmt->execute();
            $order_result = $order_stmt->get_result();
            $order = $order_result->fetch_assoc();
            $order_stmt->close();

            if ($order) {
                ?>
                <div class="card">
                    <div class="card-body">
                        <h5>주문번호: <?php echo htmlspecialchars($order['order_number']); ?></h5>
                        <p><strong>고객명:</strong> <?php echo htmlspecialchars($order['customer_name']); ?></p>
                        <p><strong>전화번호:</strong> <?php echo htmlspecialchars($order['customer_phone']); ?></p>
                        <p><strong>주문일시:</strong> <?php echo date('Y-m-d H:i', strtotime($order['order_date'])); ?></p>
                        <p><strong>상태:</strong> <?php echo htmlspecialchars($order['order_status']); ?></p>
                        <p><strong>금액:</strong> <?php echo number_format($order['final_amount']); ?>원</p>
                        <p><strong>점포:</strong> <?php echo htmlspecialchars($order['store_name'] ?? 'N/A'); ?></p>
                        
                        <?php if ($order['delivery_address']): ?>
                            <p><strong>배송지:</strong> <?php echo nl2br(htmlspecialchars($order['delivery_address'])); ?></p>
                        <?php endif; ?>
                        
                        <?php if ($order['notes']): ?>
                            <p><strong>메모:</strong> <?php echo htmlspecialchars($order['notes']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php
            } else {
                echo '<div class="alert alert-danger">주문을 찾을 수 없습니다.</div>';
            }
        } catch (Exception $e) {
            echo '<div class="alert alert-danger">오류: ' . $e->getMessage() . '</div>';
        }
        ?>

    <?php endif; ?>
</div>

<?php include 'partials/footer.php'; ?>