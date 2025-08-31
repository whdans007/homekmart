<?php
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

// 로그인 체크 및 권한 확인
if (!check_login_redirect()) {
    exit;
}

require_permission('admin_access', '../login.php');

$conn = get_db_connection();
$page_title = "주문 관리 (간단버전)";

include 'partials/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fas fa-shopping-cart me-2"></i>주문 관리</h2>
    </div>
    
    <!-- 테이블 존재 여부 확인 -->
    <div class="card mb-4">
        <div class="card-body">
            <h5>시스템 상태 확인</h5>
            
            <?php
            try {
                // orders 테이블 확인
                $result = $conn->query("SHOW TABLES LIKE 'orders'");
                if ($result->num_rows > 0) {
                    echo '<div class="alert alert-success">✅ orders 테이블 존재</div>';
                    
                    // 주문 개수 확인
                    $count_result = $conn->query("SELECT COUNT(*) as count FROM orders");
                    $count = $count_result->fetch_assoc()['count'];
                    echo '<p>총 주문 개수: ' . $count . '개</p>';
                    
                    // 최근 주문 5개 표시
                    if ($count > 0) {
                        $orders_result = $conn->query("SELECT * FROM orders ORDER BY order_date DESC LIMIT 5");
                        echo '<h6>최근 주문 5개:</h6>';
                        echo '<div class="table-responsive">';
                        echo '<table class="table table-sm">';
                        echo '<thead><tr><th>주문번호</th><th>고객명</th><th>주문일시</th><th>상태</th><th>금액</th></tr></thead>';
                        echo '<tbody>';
                        
                        while ($order = $orders_result->fetch_assoc()) {
                            echo '<tr>';
                            echo '<td>' . htmlspecialchars($order['order_number']) . '</td>';
                            echo '<td>' . htmlspecialchars($order['customer_name']) . '</td>';
                            echo '<td>' . date('Y-m-d H:i', strtotime($order['order_date'])) . '</td>';
                            echo '<td>' . htmlspecialchars($order['order_status']) . '</td>';
                            echo '<td>' . number_format($order['final_amount']) . '원</td>';
                            echo '</tr>';
                        }
                        
                        echo '</tbody></table></div>';
                    }
                } else {
                    echo '<div class="alert alert-danger">❌ orders 테이블이 존재하지 않습니다.</div>';
                    echo '<p>다음 SQL을 실행하여 테이블을 생성하세요:</p>';
                    echo '<pre>CREATE TABLE `orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_number` varchar(50) NOT NULL,
  `customer_name` varchar(100) NOT NULL,
  `customer_phone` varchar(20) NOT NULL,
  `customer_email` varchar(255) DEFAULT NULL,
  `store_id` int(11) NOT NULL,
  `delivery_address` text DEFAULT NULL,
  `delivery_notes` text DEFAULT NULL,
  `order_date` datetime NOT NULL DEFAULT current_timestamp(),
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `delivery_fee` decimal(10,2) DEFAULT 0.00,
  `discount_amount` decimal(10,2) DEFAULT 0.00,
  `final_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_method` enum("card","cash","transfer","mobile") DEFAULT "card",
  `order_status` enum("pending","confirmed","preparing","shipping","delivered","cancelled") DEFAULT "pending",
  `notes` text DEFAULT NULL,
  `processed_by` int(11) DEFAULT NULL,
  `processed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_number` (`order_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;</pre>';
                }
                
                // order_items 테이블 확인
                $result = $conn->query("SHOW TABLES LIKE 'order_items'");
                if ($result->num_rows > 0) {
                    echo '<div class="alert alert-success">✅ order_items 테이블 존재</div>';
                } else {
                    echo '<div class="alert alert-danger">❌ order_items 테이블이 존재하지 않습니다.</div>';
                }
                
            } catch (Exception $e) {
                echo '<div class="alert alert-danger">오류: ' . $e->getMessage() . '</div>';
            }
            ?>
            
            <div class="mt-3">
                <a href="orders.php" class="btn btn-primary">전체 주문 관리로 이동</a>
                <a href="../shop/index.php" class="btn btn-success">고객 쇼핑몰로 이동</a>
            </div>
        </div>
    </div>
</div>

<?php include 'partials/footer.php'; ?>