<?php
require_once __DIR__ . '/../config/db_config.php';

$conn = get_db_connection();

// 선택된 점포
$selected_store_id = isset($_GET['store_id']) ? (int)$_GET['store_id'] : 1;

// 점포 정보
$store_stmt = $conn->prepare("SELECT * FROM stores WHERE id = ? AND is_active = 1");
$store_stmt->bind_param("i", $selected_store_id);
$store_stmt->execute();
$store_result = $store_stmt->get_result();
$current_store = $store_result->fetch_assoc();
$store_stmt->close();

if (!$current_store) {
    header("Location: index.php");
    exit;
}

// 주문 처리
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $customer_name = trim($_POST['customer_name']);
    $customer_phone = trim($_POST['customer_phone']);
    $customer_email = trim($_POST['customer_email']);
    $delivery_address = trim($_POST['delivery_address']);
    $delivery_notes = trim($_POST['delivery_notes']);
    $payment_method = $_POST['payment_method'];
    $cart_data = $_POST['cart_data'];
    
    // 유효성 검사
    if (empty($customer_name) || empty($customer_phone) || empty($cart_data)) {
        $error = "필수 정보를 입력해주세요.";
    } else {
        $cart = json_decode($cart_data, true);
        
        if (!$cart || count($cart) == 0) {
            $error = "장바구니가 비어있습니다.";
        } else {
            // 주문번호 생성 (HK + 날짜 + 시퀀스)
            $order_date = date('Y-m-d H:i:s');
            $order_number_prefix = 'HK' . date('Ymd');
            
            // 오늘 주문 수 조회
            $count_stmt = $conn->prepare("SELECT COUNT(*) as count FROM orders WHERE DATE(order_date) = CURDATE()");
            $count_stmt->execute();
            $count_result = $count_stmt->get_result();
            $daily_count = $count_result->fetch_assoc()['count'] + 1;
            $count_stmt->close();
            
            $order_number = $order_number_prefix . str_pad($daily_count, 3, '0', STR_PAD_LEFT);
            
            // 총 금액 계산
            $total_amount = 0;
            $valid_items = [];
            
            foreach ($cart as $item) {
                if (isset($item['productId']) && isset($item['quantity']) && $item['quantity'] > 0) {
                    // 현재 가격 조회
                    $price_stmt = $conn->prepare("
                        SELECT p.name, i.selling_price 
                        FROM products p 
                        LEFT JOIN inventory i ON p.id = i.product_id AND i.store_id = ? 
                        WHERE p.id = ?
                    ");
                    $price_stmt->bind_param("ii", $selected_store_id, $item['productId']);
                    $price_stmt->execute();
                    $price_result = $price_stmt->get_result();
                    $product_data = $price_result->fetch_assoc();
                    $price_stmt->close();
                    
                    if ($product_data && $product_data['selling_price'] > 0) {
                        $item_total = $product_data['selling_price'] * $item['quantity'];
                        $total_amount += $item_total;
                        
                        $valid_items[] = [
                            'product_id' => $item['productId'],
                            'product_name' => $product_data['name'],
                            'quantity' => $item['quantity'],
                            'unit_price' => $product_data['selling_price'],
                            'total_price' => $item_total
                        ];
                    }
                }
            }
            
            if (count($valid_items) == 0) {
                $error = "주문 가능한 상품이 없습니다.";
            } else {
                $conn->begin_transaction();
                
                try {
                    // 주문 생성
                    $order_stmt = $conn->prepare("
                        INSERT INTO orders (
                            order_number, customer_name, customer_phone, customer_email, 
                            store_id, delivery_address, delivery_notes, order_date, 
                            total_amount, final_amount, payment_method, order_status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                    ");
                    
                    $order_stmt->bind_param("ssssississs", 
                        $order_number, $customer_name, $customer_phone, $customer_email,
                        $selected_store_id, $delivery_address, $delivery_notes, $order_date,
                        $total_amount, $total_amount, $payment_method
                    );
                    
                    $order_stmt->execute();
                    $order_id = $conn->insert_id;
                    $order_stmt->close();
                    
                    // 주문 상품 생성
                    $item_stmt = $conn->prepare("
                        INSERT INTO order_items (order_id, product_id, product_name, quantity, unit_price, total_price)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    
                    foreach ($valid_items as $item) {
                        $item_stmt->bind_param("iisidd", 
                            $order_id, $item['product_id'], $item['product_name'],
                            $item['quantity'], $item['unit_price'], $item['total_price']
                        );
                        $item_stmt->execute();
                    }
                    $item_stmt->close();
                    
                    $conn->commit();
                    
                    // 주문 완료 페이지로 리다이렉트
                    header("Location: order_complete.php?order_number=" . urlencode($order_number));
                    exit;
                    
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = "주문 처리 중 오류가 발생했습니다. 다시 시도해주세요.";
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>주문하기 - <?php echo htmlspecialchars($current_store['name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .store-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem 0;
        }
        
        .order-step {
            position: relative;
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .step-number {
            position: absolute;
            top: -15px;
            left: 20px;
            background: #667eea;
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }
        
        .cart-item {
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.5rem;
            background: white;
        }
        
        .order-summary {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
            position: sticky;
            top: 20px;
        }
    </style>
</head>
<body>
    <!-- 헤더 -->
    <div class="store-header">
        <div class="container">
            <div class="d-flex align-items-center">
                <a href="index.php?store_id=<?php echo $selected_store_id; ?>" class="btn btn-light me-3">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div>
                    <h1 class="mb-0">주문하기</h1>
                    <p class="mb-0"><?php echo htmlspecialchars($current_store['name']); ?></p>
                </div>
            </div>
        </div>
    </div>

    <div class="container mt-4">
        <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-lg-8">
                <form method="POST" id="orderForm">
                    <!-- 1단계: 장바구니 확인 -->
                    <div class="order-step">
                        <div class="step-number">1</div>
                        <h5 class="mb-3 mt-2">주문 상품 확인</h5>
                        <div id="cartItems">
                            <!-- JavaScript로 장바구니 항목 표시 -->
                        </div>
                    </div>

                    <!-- 2단계: 고객 정보 -->
                    <div class="order-step">
                        <div class="step-number">2</div>
                        <h5 class="mb-3 mt-2">고객 정보</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">이름 *</label>
                                    <input type="text" class="form-control" name="customer_name" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">전화번호 *</label>
                                    <input type="tel" class="form-control" name="customer_phone" required>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">이메일</label>
                            <input type="email" class="form-control" name="customer_email" placeholder="선택사항">
                        </div>
                    </div>

                    <!-- 3단계: 배송 정보 -->
                    <div class="order-step">
                        <div class="step-number">3</div>
                        <h5 class="mb-3 mt-2">배송 정보</h5>
                        <div class="mb-3">
                            <label class="form-label">배송지 주소</label>
                            <textarea class="form-control" name="delivery_address" rows="3" placeholder="배송받을 주소를 입력하세요 (매장 픽업의 경우 '매장픽업'으로 입력)"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">배송 요청사항</label>
                            <textarea class="form-control" name="delivery_notes" rows="2" placeholder="배송 시 요청사항이 있으시면 입력하세요"></textarea>
                        </div>
                    </div>

                    <!-- 4단계: 결제 방법 -->
                    <div class="order-step">
                        <div class="step-number">4</div>
                        <h5 class="mb-3 mt-2">결제 방법</h5>
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="payment_method" value="card" checked>
                                    <label class="form-check-label">
                                        <i class="fas fa-credit-card me-2"></i>카드결제
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="payment_method" value="cash">
                                    <label class="form-check-label">
                                        <i class="fas fa-money-bill me-2"></i>현금결제
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="payment_method" value="transfer">
                                    <label class="form-check-label">
                                        <i class="fas fa-university me-2"></i>계좌이체
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="payment_method" value="mobile">
                                    <label class="form-check-label">
                                        <i class="fas fa-mobile-alt me-2"></i>휴대폰결제
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <input type="hidden" name="cart_data" id="cartData">
                </form>
            </div>

            <div class="col-lg-4">
                <div class="order-summary">
                    <h5 class="mb-3">주문 요약</h5>
                    <div class="d-flex justify-content-between mb-2">
                        <span>상품 금액</span>
                        <span id="subtotal">0원</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>배송비</span>
                        <span class="text-success">무료</span>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between mb-3">
                        <strong>총 결제금액</strong>
                        <strong class="text-primary fs-5" id="totalAmount">0원</strong>
                    </div>
                    
                    <button type="submit" form="orderForm" class="btn btn-primary w-100 btn-lg">
                        <i class="fas fa-credit-card me-2"></i>주문 완료
                    </button>
                    
                    <div class="mt-3">
                        <small class="text-muted">
                            <i class="fas fa-info-circle me-2"></i>
                            주문 후 관리자 확인을 통해 처리됩니다.<br>
                            재고관리를 하지 않으므로 품절 시 별도 연락드립니다.
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // 장바구니 데이터 가져오기
        let cart = JSON.parse(localStorage.getItem('cart') || '[]');
        
        // 현재 점포 상품만 필터링
        const storeId = <?php echo $selected_store_id; ?>;
        cart = cart.filter(item => item.storeId === storeId);
        
        function displayCartItems() {
            const cartItemsContainer = document.getElementById('cartItems');
            const subtotalElement = document.getElementById('subtotal');
            const totalAmountElement = document.getElementById('totalAmount');
            const cartDataInput = document.getElementById('cartData');
            
            if (cart.length === 0) {
                cartItemsContainer.innerHTML = `
                    <div class="text-center py-4">
                        <i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">장바구니가 비어있습니다</h5>
                        <a href="index.php?store_id=${storeId}" class="btn btn-primary">쇼핑 계속하기</a>
                    </div>
                `;
                subtotalElement.textContent = '0원';
                totalAmountElement.textContent = '0원';
                return;
            }
            
            let html = '';
            let total = 0;
            
            cart.forEach((item, index) => {
                const itemTotal = item.price * item.quantity;
                total += itemTotal;
                
                html += `
                    <div class="cart-item">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="flex-grow-1">
                                <h6 class="mb-1">${item.name}</h6>
                                <small class="text-muted">${item.price.toLocaleString()}원 × ${item.quantity}개</small>
                            </div>
                            <div class="text-end">
                                <div class="fw-bold">${itemTotal.toLocaleString()}원</div>
                                <button class="btn btn-sm btn-outline-danger" onclick="removeItem(${index})">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            cartItemsContainer.innerHTML = html;
            subtotalElement.textContent = total.toLocaleString() + '원';
            totalAmountElement.textContent = total.toLocaleString() + '원';
            
            // 장바구니 데이터를 폼에 추가
            cartDataInput.value = JSON.stringify(cart);
        }
        
        function removeItem(index) {
            if (confirm('이 상품을 주문에서 제외하시겠습니까?')) {
                cart.splice(index, 1);
                localStorage.setItem('cart', JSON.stringify(cart));
                displayCartItems();
            }
        }
        
        // 주문 폼 검증
        document.getElementById('orderForm').addEventListener('submit', function(e) {
            if (cart.length === 0) {
                e.preventDefault();
                alert('주문할 상품이 없습니다.');
                return false;
            }
            
            // 로딩 표시
            const submitBtn = e.target.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>주문 처리중...';
            submitBtn.disabled = true;
        });
        
        // 페이지 로드 시 장바구니 표시
        displayCartItems();
        
        // 장바구니가 비어있으면 경고
        if (cart.length === 0) {
            setTimeout(() => {
                if (confirm('장바구니가 비어있습니다. 쇼핑을 계속하시겠습니까?')) {
                    window.location.href = `index.php?store_id=${storeId}`;
                } else {
                    window.history.back();
                }
            }, 1000);
        }
    </script>
</body>
</html>

<?php
$conn->close();
?>