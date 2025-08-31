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
$page_title = "주문 관리";
$current_page = "orders";

// 액션 처리
$action = $_GET['action'] ?? 'list';
$order_id = $_GET['id'] ?? null;
$store_id = $_SESSION['store_id'] ?? null;

// 주문 상태 업데이트 처리
if ($_POST && $action == 'update_status' && $order_id) {
    $new_status = $_POST['order_status'];
    $notes = $_POST['notes'] ?? '';
    
    $update_stmt = $conn->prepare("UPDATE orders SET order_status = ?, notes = ?, updated_at = NOW() WHERE id = ? AND store_id = ?");
    $update_stmt->bind_param("ssii", $new_status, $notes, $order_id, $store_id);
    
    if ($update_stmt->execute()) {
        $_SESSION['flash_message'] = "주문 상태가 업데이트되었습니다.";
    } else {
        $_SESSION['flash_error'] = "주문 상태 업데이트에 실패했습니다.";
    }
    
    header("Location: orders.php?action=detail&id=" . $order_id);
    exit;
}

include 'partials/header.php';
?>

<div class="container-fluid">
    <?php if ($action == 'list'): ?>
        <!-- 주문 목록 -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-shopping-cart me-2"></i>주문 관리</h2>
            <div class="btn-group">
                <button class="btn btn-outline-secondary" onclick="refreshOrders()">
                    <i class="fas fa-refresh me-1"></i>새로고침
                </button>
            </div>
        </div>

        <!-- 필터 및 검색 -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">주문상태</label>
                        <select class="form-select" id="status-filter">
                            <option value="">전체</option>
                            <option value="pending">주문접수</option>
                            <option value="confirmed">주문확인</option>
                            <option value="preparing">상품준비중</option>
                            <option value="shipping">배송중</option>
                            <option value="delivered">배송완료</option>
                            <option value="cancelled">주문취소</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">주문일자</label>
                        <input type="date" class="form-control" id="date-filter">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">검색</label>
                        <div class="input-group">
                            <input type="text" class="form-control" placeholder="주문번호, 고객명, 전화번호" id="search-input">
                            <button class="btn btn-primary" onclick="searchOrders()">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <button class="btn btn-outline-secondary w-100" onclick="resetFilters()">초기화</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- 주문 목록 테이블 -->
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="orders-table">
                        <thead class="table-light">
                            <tr>
                                <th>주문번호</th>
                                <th>고객정보</th>
                                <th>주문일시</th>
                                <th>주문상태</th>
                                <th>결제방법</th>
                                <th>주문금액</th>
                                <th>관리</th>
                            </tr>
                        </thead>
                        <tbody id="orders-tbody">
                            <!-- AJAX로 로드 -->
                        </tbody>
                    </table>
                </div>
                
                <!-- 페이지네이션 -->
                <nav aria-label="주문 목록 페이지네이션" id="pagination-container">
                    <!-- AJAX로 로드 -->
                </nav>
            </div>
        </div>

    <?php elseif ($action == 'detail' && $order_id): ?>
        <?php
        // 주문 상세 정보 조회
        $order_stmt = $conn->prepare("
            SELECT o.*, s.name as store_name, s.address as store_address, s.phone as store_phone
            FROM orders o
            JOIN stores s ON o.store_id = s.id
            WHERE o.id = ? AND o.store_id = ?
        ");
        $order_stmt->bind_param("ii", $order_id, $store_id);
        $order_stmt->execute();
        $order_result = $order_stmt->get_result();
        $order = $order_result->fetch_assoc();
        $order_stmt->close();

        if (!$order) {
            echo '<div class="alert alert-danger">주문을 찾을 수 없습니다.</div>';
            include 'partials/footer.php';
            exit;
        }

        // 주문 상품 조회
        $items_stmt = $conn->prepare("
            SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC
        ");
        $items_stmt->bind_param("i", $order_id);
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

        <!-- 주문 상세 -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <a href="orders.php" class="btn btn-outline-secondary me-3">
                    <i class="fas fa-arrow-left me-1"></i>목록으로
                </a>
                <h2><i class="fas fa-receipt me-2"></i>주문 상세 정보</h2>
            </div>
            <div class="btn-group">
                <button class="btn btn-primary" onclick="printOrder()">
                    <i class="fas fa-print me-1"></i>인쇄
                </button>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-8">
                <!-- 주문 정보 카드 -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="mb-1">주문번호: <?php echo htmlspecialchars($order['order_number']); ?></h5>
                            <small class="text-muted">주문일시: <?php echo date('Y-m-d H:i', strtotime($order['order_date'])); ?></small>
                        </div>
                        <span class="badge bg-<?php 
                            echo match($order['order_status']) {
                                'pending' => 'warning',
                                'confirmed' => 'info',
                                'preparing' => 'primary',
                                'shipping' => 'primary',
                                'delivered' => 'success',
                                'cancelled' => 'danger',
                                default => 'secondary'
                            };
                        ?> fs-6">
                            <?php echo $status_labels[$order['order_status']] ?? $order['order_status']; ?>
                        </span>
                    </div>
                    
                    <div class="card-body">
                        <!-- 주문 상품 -->
                        <h6 class="mb-3">주문 상품</h6>
                        <?php foreach ($order_items as $item): ?>
                            <div class="border-bottom pb-3 mb-3">
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
            </div>

            <div class="col-lg-4">
                <!-- 상태 관리 카드 -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h6 class="mb-0">주문 상태 관리</h6>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="orders.php?action=update_status&id=<?php echo $order_id; ?>">
                            <div class="mb-3">
                                <label class="form-label">주문 상태</label>
                                <select name="order_status" class="form-select" required>
                                    <?php foreach ($status_labels as $status => $label): ?>
                                        <option value="<?php echo $status; ?>" <?php echo $order['order_status'] == $status ? 'selected' : ''; ?>>
                                            <?php echo $label; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">관리자 메모</label>
                                <textarea name="notes" class="form-control" rows="3"><?php echo htmlspecialchars($order['notes'] ?? ''); ?></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-save me-1"></i>상태 업데이트
                            </button>
                        </form>
                    </div>
                </div>

                <!-- 고객 정보 카드 -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h6 class="mb-0">고객 정보</h6>
                    </div>
                    <div class="card-body">
                        <p class="mb-2"><strong>이름:</strong> <?php echo htmlspecialchars($order['customer_name']); ?></p>
                        <p class="mb-2"><strong>전화번호:</strong> <?php echo htmlspecialchars($order['customer_phone']); ?></p>
                        <?php if ($order['customer_email']): ?>
                            <p class="mb-0"><strong>이메일:</strong> <?php echo htmlspecialchars($order['customer_email']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 배송 정보 카드 -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h6 class="mb-0">배송 정보</h6>
                    </div>
                    <div class="card-body">
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

                <!-- 결제 정보 카드 -->
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0">결제 정보</h6>
                    </div>
                    <div class="card-body">
                        <p class="mb-2"><strong>결제 방법:</strong> <?php echo $payment_labels[$order['payment_method']] ?? $order['payment_method']; ?></p>
                        <p class="mb-0"><strong>결제 금액:</strong> <?php echo number_format($order['final_amount'], 0); ?>원</p>
                    </div>
                </div>
            </div>
        </div>

    <?php endif; ?>
</div>

<script>
$(document).ready(function() {
    <?php if ($action == 'list'): ?>
        loadOrders();
        
        // 필터 변경 이벤트
        $('#status-filter, #date-filter').change(function() {
            loadOrders();
        });
    <?php endif; ?>
});

function loadOrders(page = 1) {
    const status = $('#status-filter').val();
    const date = $('#date-filter').val();
    const search = $('#search-input').val();
    
    $.ajax({
        url: 'ajax_orders.php',
        method: 'GET',
        data: {
            action: 'list',
            page: page,
            status: status,
            date: date,
            search: search
        },
        success: function(response) {
            const data = JSON.parse(response);
            $('#orders-tbody').html(data.html);
            $('#pagination-container').html(data.pagination);
        },
        error: function() {
            alert('주문 목록을 불러오는데 실패했습니다.');
        }
    });
}

function searchOrders() {
    loadOrders(1);
}

function resetFilters() {
    $('#status-filter').val('');
    $('#date-filter').val('');
    $('#search-input').val('');
    loadOrders(1);
}

function refreshOrders() {
    loadOrders();
}

function printOrder() {
    window.print();
}

function quickStatusUpdate(orderId, currentStatus) {
    // 상태 변경을 위한 모달이나 확인 다이얼로그
    const statuses = [
        {value: 'pending', label: '주문접수'},
        {value: 'confirmed', label: '주문확인'},
        {value: 'preparing', label: '상품준비중'},
        {value: 'shipping', label: '배송중'},
        {value: 'delivered', label: '배송완료'},
        {value: 'cancelled', label: '주문취소'}
    ];
    
    let statusOptions = '';
    statuses.forEach(status => {
        const selected = status.value === currentStatus ? 'selected' : '';
        statusOptions += `<option value="${status.value}" ${selected}>${status.label}</option>`;
    });
    
    const modal = `
        <div class="modal fade" id="quickStatusModal" tabindex="-1">
            <div class="modal-dialog modal-sm">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">주문 상태 변경</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <form id="quickStatusForm">
                            <div class="mb-3">
                                <label class="form-label">새 상태</label>
                                <select name="status" class="form-select" required>
                                    ${statusOptions}
                                </select>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">취소</button>
                        <button type="button" class="btn btn-primary" onclick="submitQuickStatus(${orderId})">변경</button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // 모달이 이미 존재하면 제거
    const existingModal = document.getElementById('quickStatusModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    // 새 모달 추가
    document.body.insertAdjacentHTML('beforeend', modal);
    
    // Bootstrap 모달 표시
    const modalElement = new bootstrap.Modal(document.getElementById('quickStatusModal'));
    modalElement.show();
}

function submitQuickStatus(orderId) {
    const form = document.getElementById('quickStatusForm');
    const formData = new FormData(form);
    formData.append('order_id', orderId);
    
    $.ajax({
        url: 'ajax_orders.php?action=quick_status_update',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(response) {
            const result = JSON.parse(response);
            if (result.success) {
                // 모달 닫기
                bootstrap.Modal.getInstance(document.getElementById('quickStatusModal')).hide();
                // 목록 새로고침
                loadOrders();
                alert(result.message);
            } else {
                alert(result.message);
            }
        },
        error: function() {
            alert('상태 업데이트에 실패했습니다.');
        }
    });
}
</script>

<style>
@media print {
    .no-print { display: none !important; }
    .card { box-shadow: none; border: 1px solid #ddd; }
}
</style>

<?php include 'partials/footer.php'; ?>