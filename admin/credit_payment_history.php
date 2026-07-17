<?php
/**
 * 거래처별 수금(입금) 내역 관리 - 조회 및 삭제
 * credit_payments는 특정 거래(판매건)와 1:1로 연결되지 않고 거래처 잔액에 FIFO로 충당되므로,
 * 거래를 삭제해도 지워지지 않는 수금 기록을 여기서 직접 정리할 수 있게 한다.
 */
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = '수금 내역 관리 - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

if (!has_permission('wholesale_management')) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => t('messages.permission_denied')];
    header('Location: shop.php');
    exit;
}

$is_super_admin = ($_SESSION['role'] === 'super_admin');
$customer_id = (int)($_GET['customer_id'] ?? 0);
$errors = [];
$customer = null;
$payments = [];
$total_paid = 0;

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 수금 기록 삭제 처리
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
        $del_id = (int)($_POST['payment_id'] ?? 0);
        $redirect_customer_id = (int)($_POST['customer_id'] ?? 0);

        if ($del_id > 0) {
            $check_sql = "SELECT id, customer_id FROM credit_payments WHERE id = ?";
            if (!$is_super_admin) {
                $check_sql .= " AND store_id = " . (int)$current_store_id;
            }
            $check = $pdo->prepare($check_sql);
            $check->execute([$del_id]);
            $del_row = $check->fetch(PDO::FETCH_ASSOC);

            if (!$del_row) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => '삭제 권한이 없거나 존재하지 않는 수금 기록입니다.'];
            } else {
                $pdo->prepare("DELETE FROM credit_payments WHERE id = ?")->execute([$del_id]);
                $_SESSION['flash'] = ['type' => 'success', 'message' => '수금 기록이 삭제되었습니다.'];
            }
        }
        header('Location: credit_payment_history.php?customer_id=' . $redirect_customer_id);
        exit;
    }

    if ($customer_id > 0) {
        $cust_sql = "SELECT id, name, phone FROM credit_customers WHERE id = ?";
        if (!$is_super_admin) {
            $cust_sql .= " AND store_id = " . (int)$current_store_id;
        }
        $cust_stmt = $pdo->prepare($cust_sql);
        $cust_stmt->execute([$customer_id]);
        $customer = $cust_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$customer) {
            $errors[] = '거래처를 찾을 수 없거나 조회 권한이 없습니다.';
        } else {
            $pay_sql = "
                SELECT cp.id, cp.payment_date, cp.amount, cp.method, cp.notes, cp.created_at, u.full_name AS user_name
                FROM credit_payments cp
                LEFT JOIN users u ON cp.user_id = u.id
                WHERE cp.customer_id = ?
                ORDER BY cp.payment_date DESC, cp.id DESC
            ";
            $pay_stmt = $pdo->prepare($pay_sql);
            $pay_stmt->execute([$customer_id]);
            $payments = $pay_stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($payments as $p) {
                $total_paid += (float)$p['amount'];
            }
        }
    }
} catch (PDOException $e) {
    $errors[] = '데이터베이스 오류: ' . $e->getMessage();
}

if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}
?>

<div class="w-full px-2 sm:px-3 md:px-4 py-8">
    <div class="max-w-3xl mx-auto">
        <div class="mb-6">
            <a href="credit_transactions.php<?php echo $customer_id > 0 ? '?customer_id=' . $customer_id : ''; ?>" class="inline-flex items-center text-sm font-medium text-gray-500 hover:text-gray-700">
                <i class="fas fa-arrow-left mr-2"></i>외상거래 목록으로
            </a>
            <h1 class="text-2xl font-bold text-gray-900 mt-2">
                <i class="fas fa-hand-holding-usd mr-2 text-blue-600"></i>수금 내역 관리
            </h1>
            <p class="mt-1 text-sm text-gray-500">거래(판매건) 삭제와 무관하게 남아있는 수금 기록을 직접 확인하고 정리할 수 있습니다.</p>
        </div>

        <?php if (isset($flash)): ?>
            <div class="mb-6 p-4 rounded-md <?php echo $flash['type'] === 'error' ? 'bg-red-50 border border-red-200 text-red-700' : 'bg-green-50 border border-green-200 text-green-700'; ?> text-sm">
                <i class="fas <?php echo $flash['type'] === 'error' ? 'fa-exclamation-triangle' : 'fa-check-circle'; ?> mr-1"></i>
                <?php echo htmlspecialchars($flash['message']); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-md text-sm text-red-700">
                <?php foreach ($errors as $error): ?><div><i class="fas fa-exclamation-triangle mr-1"></i><?php echo htmlspecialchars($error); ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($customer_id <= 0): ?>
            <div class="bg-white shadow rounded-lg ring-1 ring-gray-200 p-6">
                <label class="block text-sm font-medium text-gray-700 mb-1">거래처 검색</label>
                <div class="relative">
                    <input type="text" id="cph-customer-search" autocomplete="off" placeholder="거래처명으로 검색"
                           class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                    <div id="cph-customer-results" class="absolute z-10 w-full bg-white border border-gray-300 rounded-md shadow-lg mt-1 max-h-60 overflow-y-auto hidden"></div>
                </div>
            </div>
        <?php elseif ($customer): ?>
            <div class="bg-white shadow rounded-lg ring-1 ring-gray-200 p-6 mb-6">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-lg font-bold text-gray-900"><?php echo htmlspecialchars($customer['name']); ?></div>
                        <?php if (!empty($customer['phone'])): ?>
                            <div class="text-sm text-gray-500"><i class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($customer['phone']); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="text-right">
                        <div class="text-xs text-gray-500">수금 합계</div>
                        <div class="text-2xl font-bold text-blue-700"><?php echo number_format($total_paid, 2); ?></div>
                    </div>
                </div>
            </div>

            <div class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-300">
                <?php if (empty($payments)): ?>
                    <div class="text-center py-12 text-gray-500">
                        <i class="fas fa-receipt text-gray-300 text-5xl mb-4"></i>
                        <p>수금 기록이 없습니다.</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full">
                            <thead class="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th class="px-4 py-2 text-center text-xs font-semibold text-gray-700 uppercase">날짜</th>
                                    <th class="px-4 py-2 text-right text-xs font-semibold text-gray-700 uppercase">금액</th>
                                    <th class="px-4 py-2 text-center text-xs font-semibold text-gray-700 uppercase">수단</th>
                                    <th class="px-4 py-2 text-left text-xs font-semibold text-gray-700 uppercase">메모</th>
                                    <th class="px-4 py-2 text-center text-xs font-semibold text-gray-700 uppercase">등록자</th>
                                    <th class="px-4 py-2 text-center text-xs font-semibold text-gray-700 uppercase">삭제</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white">
                                <?php foreach ($payments as $p): ?>
                                    <tr class="border-b border-gray-100">
                                        <td class="px-4 py-2 whitespace-nowrap text-center text-sm text-gray-900"><?php echo date('Y-m-d', strtotime($p['payment_date'])); ?></td>
                                        <td class="px-4 py-2 whitespace-nowrap text-right text-sm font-semibold text-blue-700"><?php echo number_format($p['amount'], 2); ?></td>
                                        <td class="px-4 py-2 whitespace-nowrap text-center text-sm text-gray-700"><?php echo htmlspecialchars($p['method'] ?: '-'); ?></td>
                                        <td class="px-4 py-2 text-sm text-gray-500"><?php echo htmlspecialchars($p['notes'] ?: '-'); ?></td>
                                        <td class="px-4 py-2 whitespace-nowrap text-center text-sm text-gray-500"><?php echo htmlspecialchars($p['user_name'] ?? '-'); ?></td>
                                        <td class="px-4 py-2 whitespace-nowrap text-center">
                                            <button type="button" class="cph-delete-btn text-red-500 hover:text-red-700"
                                                    data-id="<?php echo (int)$p['id']; ?>"
                                                    data-amount="<?php echo htmlspecialchars(number_format($p['amount'], 2)); ?>"
                                                    data-date="<?php echo htmlspecialchars(date('Y-m-d', strtotime($p['payment_date']))); ?>">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- 삭제 확인 모달 -->
<div id="cph-delete-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
            <div class="p-6">
                <div class="flex items-center mb-4">
                    <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-red-100"><i class="fas fa-exclamation-triangle text-red-600"></i></div>
                </div>
                <div class="text-center">
                    <h3 class="text-lg font-medium text-gray-900 mb-2">수금 기록 삭제</h3>
                    <div class="text-sm text-gray-500 mb-4">
                        <p><span id="cph-delete-date"></span> · <strong id="cph-delete-amount"></strong> 수금 기록을 삭제하시겠습니까?</p>
                        <p class="font-semibold text-red-600 mt-2">삭제 시 거래처 잔액이 그만큼 증가합니다. 복구할 수 없습니다.</p>
                    </div>
                </div>
                <div class="flex space-x-3 justify-center">
                    <button id="cph-cancel-delete" type="button" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400">취소</button>
                    <button id="cph-confirm-delete" type="button" class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700">삭제</button>
                </div>
            </div>
        </div>
    </div>
</div>

<form id="cph-delete-form" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="payment_id" id="cph-delete-payment-id" value="">
    <input type="hidden" name="customer_id" value="<?php echo $customer_id; ?>">
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const deleteModal = document.getElementById('cph-delete-modal');
    const deleteForm = document.getElementById('cph-delete-form');
    const deletePaymentId = document.getElementById('cph-delete-payment-id');
    const deleteDateEl = document.getElementById('cph-delete-date');
    const deleteAmountEl = document.getElementById('cph-delete-amount');

    document.querySelectorAll('.cph-delete-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            deletePaymentId.value = this.dataset.id;
            deleteDateEl.textContent = this.dataset.date;
            deleteAmountEl.textContent = this.dataset.amount;
            deleteModal.classList.remove('hidden');
        });
    });
    const cancelBtn = document.getElementById('cph-cancel-delete');
    const confirmBtn = document.getElementById('cph-confirm-delete');
    if (cancelBtn) cancelBtn.addEventListener('click', function() { deleteModal.classList.add('hidden'); });
    if (confirmBtn) confirmBtn.addEventListener('click', function() { deleteForm.submit(); });
    deleteModal.addEventListener('click', function(e) { if (e.target === deleteModal) deleteModal.classList.add('hidden'); });

    // 거래처 검색 (customer_id 없이 진입한 경우)
    const search = document.getElementById('cph-customer-search');
    const results = document.getElementById('cph-customer-results');
    if (search) {
        let searchTimer;
        search.addEventListener('input', function() {
            const q = this.value.trim();
            clearTimeout(searchTimer);
            if (q.length < 1) { results.classList.add('hidden'); return; }
            searchTimer = setTimeout(function() {
                fetch('ajax_search_credit_customers.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'q=' + encodeURIComponent(q) + '&limit=10'
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    const items = (data.success && data.customers) ? data.customers : [];
                    if (!items.length) {
                        results.innerHTML = '<div class="p-2 text-sm text-gray-500">검색 결과 없음</div>';
                    } else {
                        results.innerHTML = items.map(function(c) {
                            const safeName = String(c.name).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                            return '<div class="p-2 hover:bg-gray-100 cursor-pointer border-b border-gray-100 last:border-b-0" data-id="' + c.id + '">' +
                                '<div class="text-sm font-medium text-gray-900">' + safeName + '</div></div>';
                        }).join('');
                        results.querySelectorAll('[data-id]').forEach(function(el) {
                            el.addEventListener('click', function() {
                                window.location.href = 'credit_payment_history.php?customer_id=' + this.dataset.id;
                            });
                        });
                    }
                    results.classList.remove('hidden');
                })
                .catch(function() { results.classList.add('hidden'); });
            }, 250);
        });
    }
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
