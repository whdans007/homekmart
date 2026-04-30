<?php
require_once __DIR__ . '/../config/db_config.php';

$conn = get_db_connection();

$order_number = $_GET['order_number'] ?? '';

if (empty($order_number)) {
    header("Location: index.php");
    exit;
}

// 주문 정보 조회
$order_stmt = $conn->prepare("
    SELECT o.*, s.name as store_name, s.address as store_address, s.phone as store_phone
    FROM orders o
    JOIN stores s ON o.store_id = s.id
    WHERE o.order_number = ?
");
$order_stmt->bind_param("s", $order_number);
$order_stmt->execute();
$order_result = $order_stmt->get_result();
$order = $order_result->fetch_assoc();
$order_stmt->close();

if (!$order) {
    header("Location: index.php");
    exit;
}

// 주문 상품 조회
$items_stmt = $conn->prepare("
    SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC
");
$items_stmt->bind_param("i", $order['id']);
$items_stmt->execute();
$items_result = $items_stmt->get_result();
$order_items = $items_result->fetch_all(MYSQLI_ASSOC);
$items_stmt->close();

// 주문 상태 한글화
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
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>주문완료 - <?php echo htmlspecialchars($order['store_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Google AdSense -->
    <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-2783446549667436"
     crossorigin="anonymous"></script>
    <style>
        .success-header {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 3rem 0;
            text-align: center;
        }
        
        .order-card {
            border: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            border-radius: 15px;
            overflow: hidden;
        }
        
        .order-header {
            background: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
        }
        
        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: bold;
            font-size: 0.9rem;
        }
        
        .order-item {
            border-bottom: 1px solid #e9ecef;
            padding: 1rem 0;
        }
        
        .order-item:last-child {
            border-bottom: none;
        }
        
        .info-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .next-steps {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 2rem;
            text-align: center;
        }
        
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; }
        }
    </style>
</head>
<body>
    <!-- 성공 헤더 -->
    <div class="success-header no-print">
        <div class="container">
            <div class="mb-4">
                <div class="success-icon mb-3">
                    <i class="fas fa-check-circle fa-5x"></i>
                </div>
                <h1 class="mb-3">주문이 완료되었습니다!</h1>
                <p class="lead mb-0">주문번호: <strong><?php echo htmlspecialchars($order_number); ?></strong></p>
            </div>
        </div>
    </div>

    <div class="container my-5">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <!-- 주문 정보 카드 -->
                <div class="card order-card mb-4">
                    <div class="card-header order-header d-flex justify-content-between align-items-center p-3">
                        <div>
                            <h5 class="mb-1">주문 상세 정보</h5>
                            <small class="text-muted">주문일시: <?php echo date('Y-m-d H:i', strtotime($order['order_date'])); ?></small>
                        </div>
                        <span class="status-badge bg-success text-white">
                            <?php echo $status_labels[$order['order_status']] ?? $order['order_status']; ?>
                        </span>
                    </div>
                    
                    <div class="card-body">
                        <!-- 주문 상품 -->
                        <h6 class="mb-3">주문 상품</h6>
                        <?php foreach ($order_items as $item): ?>
                            <div class="order-item">
                                <div class="row align-items-center">
                                    <div class="col-md-6">
                                        <h6 class="mb-1"><?php echo htmlspecialchars($item['product_name']); ?></h6>
                                        <small class="text-muted">개당 <?php echo number_format($item['unit_price'], 0); ?>원</small>
                                    </div>
                                    <div class="col-md-3 text-center">
                                        <span class="badge bg-light text-dark"><?php echo $item['quantity']; ?>개</span>
                                    </div>
                                    <div class="col-md-3 text-end">
                                        <strong><?php echo number_format($item['total_price'], 0); ?>원</strong>
                                    </div>
                                </div>
                                <?php if ($item['notes']): ?>
                                    <div class="mt-2">
                                        <small class="text-primary">메모: <?php echo htmlspecialchars($item['notes']); ?></small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        
                        <!-- 총 금액 -->
                        <div class="row mt-3 pt-3 border-top">
                            <div class="col-md-9 text-end">
                                <strong>총 결제금액:</strong>
                            </div>
                            <div class="col-md-3 text-end">
                                <h5 class="text-primary mb-0"><?php echo number_format($order['final_amount'], 0); ?>원</h5>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 고객 및 배송 정보 -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="info-section">
                            <h6 class="mb-3"><i class="fas fa-user me-2"></i>고객 정보</h6>
                            <p class="mb-2"><strong>이름:</strong> <?php echo htmlspecialchars($order['customer_name']); ?></p>
                            <p class="mb-2"><strong>전화번호:</strong> <?php echo htmlspecialchars($order['customer_phone']); ?></p>
                            <?php if ($order['customer_email']): ?>
                                <p class="mb-0"><strong>이메일:</strong> <?php echo htmlspecialchars($order['customer_email']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="info-section">
                            <h6 class="mb-3"><i class="fas fa-truck me-2"></i>배송 정보</h6>
                            <?php if ($order['delivery_address']): ?>
                                <p class="mb-2"><strong>배송지:</strong><br><?php echo nl2br(htmlspecialchars($order['delivery_address'])); ?></p>
                            <?php else: ?>
                                <p class="mb-2 text-muted">배송지 미입력 (매장 픽업 예상)</p>
                            <?php endif; ?>
                            <?php if ($order['delivery_notes']): ?>
                                <p class="mb-0"><strong>배송 메모:</strong> <?php echo htmlspecialchars($order['delivery_notes']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- 결제 및 점포 정보 -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="info-section">
                            <h6 class="mb-3"><i class="fas fa-credit-card me-2"></i>결제 정보</h6>
                            <p class="mb-2"><strong>결제 방법:</strong> <?php echo $payment_labels[$order['payment_method']] ?? $order['payment_method']; ?></p>
                            <p class="mb-0"><strong>결제 금액:</strong> <?php echo number_format($order['final_amount'], 0); ?>원</p>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="info-section">
                            <h6 class="mb-3"><i class="fas fa-store me-2"></i>주문 점포</h6>
                            <p class="mb-2"><strong><?php echo htmlspecialchars($order['store_name']); ?></strong></p>
                            <?php if ($order['store_address']): ?>
                                <p class="mb-2"><small><?php echo htmlspecialchars($order['store_address']); ?></small></p>
                            <?php endif; ?>
                            <?php if ($order['store_phone']): ?>
                                <p class="mb-0"><small><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($order['store_phone']); ?></small></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- 다음 단계 안내 -->
                <div class="next-steps mb-4">
                    <h5 class="mb-3"><i class="fas fa-info-circle me-2"></i>다음 단계</h5>
                    <div class="row text-start">
                        <div class="col-md-4 mb-3">
                            <div class="d-flex align-items-start">
                                <div class="step-number me-3">
                                    <div class="bg-white text-primary rounded-circle d-flex align-items-center justify-content-center" style="width: 30px; height: 30px;">
                                        <strong>1</strong>
                                    </div>
                                </div>
                                <div>
                                    <h6>주문 확인</h6>
                                    <small>관리자가 주문 내용을 확인합니다</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="d-flex align-items-start">
                                <div class="step-number me-3">
                                    <div class="bg-white text-primary rounded-circle d-flex align-items-center justify-content-center" style="width: 30px; height: 30px;">
                                        <strong>2</strong>
                                    </div>
                                </div>
                                <div>
                                    <h6>상품 준비</h6>
                                    <small>주문하신 상품을 준비합니다</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="d-flex align-items-start">
                                <div class="step-number me-3">
                                    <div class="bg-white text-primary rounded-circle d-flex align-items-center justify-content-center" style="width: 30px; height: 30px;">
                                        <strong>3</strong>
                                    </div>
                                </div>
                                <div>
                                    <h6>배송/픽업</h6>
                                    <small>배송 또는 매장에서 픽업</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <p class="mb-0">
                            <i class="fas fa-phone me-2"></i>문의사항이 있으시면 
                            <?php if ($order['store_phone']): ?>
                                <strong><?php echo htmlspecialchars($order['store_phone']); ?></strong>
                            <?php else: ?>
                                <strong>해당 점포</strong>
                            <?php endif; ?>
                            로 연락해주세요.
                        </p>
                    </div>
                </div>

                <!-- 액션 버튼 -->
                <div class="row no-print">
                    <div class="col-md-4">
                        <a href="index.php?store_id=<?php echo $order['store_id']; ?>" class="btn btn-outline-primary w-100">
                            <i class="fas fa-shopping-cart me-2"></i>쇼핑 계속하기
                        </a>
                    </div>
                    <div class="col-md-4">
                        <button onclick="window.print()" class="btn btn-outline-secondary w-100">
                            <i class="fas fa-print me-2"></i>주문서 인쇄
                        </button>
                    </div>
                    <div class="col-md-4">
                        <button onclick="shareOrder()" class="btn btn-success w-100">
                            <i class="fas fa-share me-2"></i>주문정보 공유
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // 주문 완료 후 장바구니 비우기
        localStorage.removeItem('cart');
        
        function shareOrder() {
            const orderInfo = `주문번호: <?php echo $order_number; ?>\n점포: <?php echo htmlspecialchars($order['store_name']); ?>\n총 금액: <?php echo number_format($order['final_amount'], 0); ?>원\n주문일시: <?php echo date('Y-m-d H:i', strtotime($order['order_date'])); ?>`;
            
            if (navigator.share) {
                navigator.share({
                    title: 'HOME K MART 주문 완료',
                    text: orderInfo
                });
            } else {
                // 클립보드에 복사
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(orderInfo).then(() => {
                        alert('주문 정보가 클립보드에 복사되었습니다.');
                    });
                } else {
                    alert(orderInfo);
                }
            }
        }
        
        // 페이지 로드 시 축하 효과 (간단한 애니메이션)
        document.addEventListener('DOMContentLoaded', function() {
            const successIcon = document.querySelector('.success-icon i');
            if (successIcon) {
                setTimeout(() => {
                    successIcon.style.animation = 'bounce 1s ease-in-out';
                }, 500);
            }
        });
        
        // CSS 애니메이션 추가
        const style = document.createElement('style');
        style.textContent = `
            @keyframes bounce {
                0%, 20%, 60%, 100% { transform: translateY(0); }
                40% { transform: translateY(-20px); }
                80% { transform: translateY(-10px); }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>

<?php
$conn->close();
?>