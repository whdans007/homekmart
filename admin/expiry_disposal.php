<?php
// Design Ref: docs/02-design/features/expiry-management.design.md §5.4 폐기등록
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../lib/expiry_helper.php';

if (!has_permission('product_management')) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => t('messages.permission_denied')
    ];
    header('Location: shop.php');
    exit;
}

$conn = get_db_connection();
$user_id = (int)($_SESSION['user_id'] ?? 0);

$flash_message = null;
$flash_type = null;

// POST 처리 — 폐기 등록 (자체 제출 패턴)
// Plan SC: 폐기 등록 시 해당 로트와 전체 재고(inventory) 수량이 자동으로 줄어든다
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'register') {
    $reason = $_POST['reason'] ?? 'expired';
    $reason_note = $reason === 'other' ? trim($_POST['reason_note'] ?? '') : null;

    if ($reason === 'other' && $reason_note === '') {
        $flash_type = 'error';
        $flash_message = "사유를 '기타'로 선택한 경우 상세 내용을 입력해주세요.";
    } else {
        $result = register_disposal($conn, [
            'store_id' => $current_store_id,
            'product_id' => (int)($_POST['product_id'] ?? 0),
            'inventory_expiration_id' => (int)($_POST['inventory_expiration_id'] ?? 0),
            'quantity' => (int)($_POST['quantity'] ?? 0),
            'reason' => $reason,
            'reason_note' => $reason_note,
            'user_id' => $user_id,
        ]);
        $flash_type = $result['success'] ? 'success' : 'error';
        $flash_message = $result['success'] ? '폐기 등록이 완료되었습니다.' : $result['error'];
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $reason = $_POST['reason'] ?? 'expired';
    $reason_note = $reason === 'other' ? trim($_POST['reason_note'] ?? '') : null;

    if ($reason === 'other' && $reason_note === '') {
        $flash_type = 'error';
        $flash_message = "사유를 '기타'로 선택한 경우 상세 내용을 입력해주세요.";
    } else {
        $result = update_disposal($conn, [
            'store_id' => $current_store_id,
            'disposal_id' => (int)($_POST['disposal_id'] ?? 0),
            'quantity' => (int)($_POST['quantity'] ?? 0),
            'reason' => $reason,
            'reason_note' => $reason_note,
        ]);
        $flash_type = $result['success'] ? 'success' : 'error';
        $flash_message = $result['success'] ? '폐기 이력이 수정되었습니다.' : $result['error'];
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    // 삭제(등록 취소)는 점장 이상만 가능
    if (current_user_level() < LEVEL_BRANCH_MANAGER) {
        $flash_type = 'error';
        $flash_message = '삭제할 권한이 없습니다. (점장 이상 필요)';
    } else {
        $result = delete_disposal($conn, [
            'store_id' => $current_store_id,
            'disposal_id' => (int)($_POST['disposal_id'] ?? 0),
        ]);
        $flash_type = $result['success'] ? 'success' : 'error';
        $flash_message = $result['success'] ? '폐기 이력이 삭제되고 재고가 복구되었습니다.' : $result['error'];
    }
}

// 필터
$period = $_GET['period'] ?? 'all';
$reason_filter = $_GET['reason'] ?? '';

$sql = "
    SELECT pd.id, pd.expiration_date, pd.quantity, pd.unit_cost, pd.reason, pd.reason_note, pd.disposed_at,
           p.sku, p.name_ko, p.name_en,
           u.username AS disposed_by_name
    FROM product_disposals pd
    JOIN products p ON pd.product_id = p.id
    LEFT JOIN users u ON pd.disposed_by = u.id
    WHERE pd.store_id = ?
";
$params = [$current_store_id];
$types = "i";

if ($period === 'this_month') {
    $sql .= " AND YEAR(pd.disposed_at) = YEAR(CURDATE()) AND MONTH(pd.disposed_at) = MONTH(CURDATE()) ";
} elseif ($period === 'last_month') {
    $sql .= " AND YEAR(pd.disposed_at) = YEAR(CURDATE() - INTERVAL 1 MONTH) AND MONTH(pd.disposed_at) = MONTH(CURDATE() - INTERVAL 1 MONTH) ";
}
if (in_array($reason_filter, ['expired', 'damaged', 'other'], true)) {
    $sql .= " AND pd.reason = ? ";
    $params[] = $reason_filter;
    $types .= "s";
}
$sql .= " ORDER BY pd.disposed_at DESC LIMIT 200";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$reason_labels = [
    'expired' => '유통기한경과',
    'damaged' => '파손',
    'other' => '기타',
];

// 삭제(등록 취소)는 점장 이상만 가능
$can_delete_disposal = current_user_level() >= LEVEL_BRANCH_MANAGER;
?>

<div class="w-full px-2 sm:px-3 md:px-4 py-8">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">폐기등록</h1>
            <p class="mt-1 text-sm text-gray-500"><?php echo htmlspecialchars($current_store_name); ?></p>
        </div>
        <?php require __DIR__ . '/partials/expiry_nav.php'; ?>
    </div>

    <?php if ($flash_message): ?>
    <div class="mb-4 rounded-md p-4 <?php echo $flash_type === 'success' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800'; ?>">
        <?php echo htmlspecialchars($flash_message); ?>
    </div>
    <?php endif; ?>

    <!-- 폐기 등록 폼 -->
    <div class="bg-white shadow-lg rounded-lg ring-1 ring-gray-200 p-6 mb-6">
        <form method="post" id="disposalForm" onsubmit="return confirm('선택한 수량만큼 폐기 처리하고 재고에서 차감합니다. 계속할까요?');">
            <input type="hidden" name="action" value="register">
            <input type="hidden" name="product_id" id="form-product-id" value="">

            <div class="flex flex-wrap items-start gap-3">
                <div class="relative">
                    <label class="block text-sm font-medium text-gray-700 mb-1">상품</label>
                    <input type="text" id="product-search" placeholder="상품명 또는 SKU로 검색" class="w-64 px-3 py-2 border border-gray-300 rounded-md text-sm" autocomplete="off">
                    <div id="product-search-results" class="absolute left-0 top-full border border-gray-200 rounded-md mt-1 w-64 max-h-40 overflow-y-auto hidden bg-white z-10"></div>
                    <div id="selected-product" class="mt-1 text-sm font-medium text-primary-700"></div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">폐기 수량</label>
                    <input type="number" name="quantity" id="form-quantity" min="1" required class="w-28 px-3 py-2 border border-gray-300 rounded-md text-sm">
                    <p id="qty-hint" class="text-xs text-gray-500 mt-1"></p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">사유</label>
                    <select name="reason" id="reason-select" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                        <option value="expired">유통기한경과</option>
                        <option value="damaged">파손</option>
                        <option value="other">기타</option>
                    </select>
                </div>

                <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-md text-sm font-medium hover:bg-red-700 self-end">
                    <i class="fas fa-trash mr-2"></i>폐기 등록
                </button>
            </div>

            <div id="lot-select-wrapper" class="mt-3 max-w-md hidden">
                <label class="block text-sm font-medium text-gray-700 mb-1">유통기한 로트</label>
                <select name="inventory_expiration_id" id="lot-select" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" required>
                    <option value="">선택하세요</option>
                </select>
            </div>

            <div id="no-lot-notice" class="mt-3 max-w-md hidden text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-3 py-2">
                등록된 로트가 없습니다. <a href="expiry_inspection.php" class="underline font-medium">점검기록에서 먼저 등록하세요</a>.
            </div>

            <div class="mt-3 max-w-md hidden" id="reason-note-wrapper">
                <label class="block text-sm font-medium text-gray-700 mb-1">사유 상세</label>
                <input type="text" name="reason_note" id="reason-note" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
            </div>
        </form>
    </div>

    <!-- 폐기 이력 -->
    <div class="mb-4">
        <form method="get" class="flex flex-wrap items-center gap-2 bg-white p-4 rounded-lg shadow-sm ring-1 ring-gray-200">
            <select name="period" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="all" <?php echo $period === 'all' ? 'selected' : ''; ?>>전체 기간</option>
                <option value="this_month" <?php echo $period === 'this_month' ? 'selected' : ''; ?>>이번달</option>
                <option value="last_month" <?php echo $period === 'last_month' ? 'selected' : ''; ?>>지난달</option>
            </select>
            <select name="reason" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">사유: 전체</option>
                <?php foreach ($reason_labels as $key => $label): ?>
                    <option value="<?php echo $key; ?>" <?php echo $reason_filter === $key ? 'selected' : ''; ?>><?php echo $label; ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="px-4 py-2 bg-gray-700 text-white rounded-md text-sm hover:bg-gray-800">조회</button>
        </form>
    </div>

    <div class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-200">
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">폐기일시</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">상품명 / SKU</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">유통기한</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-700 uppercase">수량</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">사유</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">등록자</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-700 uppercase">관리</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-100">
                <?php if (empty($history)): ?>
                    <tr><td colspan="7" class="text-center py-8 text-gray-500">폐기 이력이 없습니다.</td></tr>
                <?php else: ?>
                    <?php foreach ($history as $h): ?>
                    <tr>
                        <td class="px-4 py-3 text-sm text-gray-500"><?php echo htmlspecialchars($h['disposed_at']); ?></td>
                        <td class="px-4 py-3">
                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($h['name_ko']); ?></div>
                            <div class="text-xs text-gray-500 font-mono"><?php echo htmlspecialchars($h['sku']); ?></div>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700"><?php echo htmlspecialchars($h['expiration_date']); ?></td>
                        <td class="px-4 py-3 text-right text-sm text-gray-700"><?php echo number_format($h['quantity']); ?></td>
                        <td class="px-4 py-3 text-sm text-gray-700">
                            <?php echo htmlspecialchars($reason_labels[$h['reason']] ?? $h['reason']); ?>
                            <?php if (!empty($h['reason_note'])): ?>
                                <span class="text-gray-400">(<?php echo htmlspecialchars($h['reason_note']); ?>)</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700"><?php echo htmlspecialchars($h['disposed_by_name'] ?? '-'); ?></td>
                        <td class="px-4 py-3 text-center text-sm">
                            <button type="button" class="text-primary-600 hover:text-primary-900 mr-3"
                                onclick="openEditDisposalModal(<?php echo (int)$h['id']; ?>, '<?php echo htmlspecialchars($h['name_ko'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($h['sku'], ENT_QUOTES); ?>', <?php echo (int)$h['quantity']; ?>, '<?php echo $h['reason']; ?>', '<?php echo htmlspecialchars($h['reason_note'] ?? '', ENT_QUOTES); ?>')">수정</button>
                            <?php if ($can_delete_disposal): ?>
                            <form method="post" class="inline" onsubmit="return confirm('삭제하면 폐기했던 수량이 재고로 복구됩니다. 계속할까요?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="disposal_id" value="<?php echo (int)$h['id']; ?>">
                                <button type="submit" class="text-red-600 hover:text-red-900">삭제</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 폐기 이력 수정 모달 -->
<div id="editDisposalModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-lg shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">폐기 이력 수정</h3>
            <button type="button" onclick="closeEditDisposalModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times text-xl"></i></button>
        </div>
        <form method="post" onsubmit="return confirm('폐기 수량을 변경하면 그 차이만큼 재고에도 반영됩니다. 계속할까요?');">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="disposal_id" id="edit-disposal-id" value="">

            <div class="mb-3">
                <label class="block text-sm font-medium text-gray-700 mb-1">상품</label>
                <div id="edit-disposal-product-info" class="text-sm font-medium text-gray-900"></div>
            </div>

            <div class="mb-3 max-w-xs">
                <label class="block text-sm font-medium text-gray-700 mb-1">폐기 수량</label>
                <input type="number" name="quantity" id="edit-disposal-quantity" min="1" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
            </div>

            <div class="mb-3 max-w-xs">
                <label class="block text-sm font-medium text-gray-700 mb-1">사유</label>
                <select name="reason" id="edit-disposal-reason" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                    <option value="expired">유통기한경과</option>
                    <option value="damaged">파손</option>
                    <option value="other">기타</option>
                </select>
            </div>

            <div class="mb-4 hidden" id="edit-disposal-reason-note-wrapper">
                <label class="block text-sm font-medium text-gray-700 mb-1">사유 상세</label>
                <input type="text" name="reason_note" id="edit-disposal-reason-note" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" onclick="closeEditDisposalModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">취소</button>
                <button type="submit" class="px-4 py-2 bg-primary-600 text-white rounded-md text-sm hover:bg-primary-700">저장</button>
            </div>
        </form>
    </div>
</div>

<script>
function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

const productSearchInput = document.getElementById('product-search');
const productSearchResults = document.getElementById('product-search-results');
const lotSelectWrapper = document.getElementById('lot-select-wrapper');
const lotSelect = document.getElementById('lot-select');
const noLotNotice = document.getElementById('no-lot-notice');
const qtyHint = document.getElementById('qty-hint');
let searchTimer = null;
let currentStoreId = <?php echo (int)$current_store_id; ?>;

productSearchInput.addEventListener('input', function() {
    const term = this.value.trim();
    clearTimeout(searchTimer);
    if (term.length < 1) {
        productSearchResults.classList.add('hidden');
        return;
    }
    searchTimer = setTimeout(() => {
        fetch(`ajax_search_products.php?term=${encodeURIComponent(term)}`)
            .then(res => res.json())
            .then(data => {
                if (!Array.isArray(data) || data.length === 0) {
                    productSearchResults.innerHTML = '<div class="px-3 py-2 text-sm text-gray-500">검색 결과가 없습니다.</div>';
                    productSearchResults.classList.remove('hidden');
                    return;
                }
                productSearchResults.innerHTML = data.map(p => `
                    <div class="px-3 py-2 text-sm hover:bg-gray-100 cursor-pointer" data-id="${p.id}" data-name="${escapeHtml(p.name_ko || '').replace(/"/g, '&quot;')}">
                        ${escapeHtml(p.name_ko || '')} <span class="text-gray-400 font-mono">${escapeHtml(p.sku || '')}</span>
                    </div>
                `).join('');
                productSearchResults.classList.remove('hidden');
            });
    }, 250);
});

productSearchResults.addEventListener('click', function(e) {
    const item = e.target.closest('[data-id]');
    if (!item) return;
    const productId = item.dataset.id;
    document.getElementById('form-product-id').value = productId;
    document.getElementById('selected-product').textContent = '선택됨: ' + item.dataset.name;
    productSearchInput.value = '';
    productSearchResults.classList.add('hidden');
    loadLots(productId);
});

function loadLots(productId) {
    fetch(`ajax_get_lot_inventory.php?product_id=${productId}&store_id=${currentStoreId}`)
        .then(res => res.json())
        .then(result => {
            if (!result.success || !result.data || result.data.length === 0) {
                lotSelectWrapper.classList.add('hidden');
                noLotNotice.classList.remove('hidden');
                lotSelect.innerHTML = '<option value="">선택하세요</option>';
                return;
            }
            noLotNotice.classList.add('hidden');
            lotSelectWrapper.classList.remove('hidden');
            lotSelect.innerHTML = '<option value="">선택하세요</option>' + result.data.map(lot =>
                `<option value="${lot.id}" data-qty="${lot.quantity}">${lot.expiration_date} (잔여 ${lot.quantity}개)</option>`
            ).join('');
        });
}

lotSelect.addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    const qty = opt ? opt.dataset.qty : null;
    if (qty) {
        document.getElementById('form-quantity').max = qty;
        qtyHint.textContent = `선택한 로트의 잔여 수량: ${qty}개`;
    } else {
        qtyHint.textContent = '';
    }
});

document.getElementById('reason-select').addEventListener('change', function() {
    const wrapper = document.getElementById('reason-note-wrapper');
    const input = document.getElementById('reason-note');
    if (this.value === 'other') {
        wrapper.classList.remove('hidden');
        input.required = true;
    } else {
        wrapper.classList.add('hidden');
        input.required = false;
    }
});

function closeEditDisposalModal() {
    document.getElementById('editDisposalModal').classList.add('hidden');
}

function openEditDisposalModal(id, name, sku, qty, reason, reasonNote) {
    document.getElementById('edit-disposal-id').value = id;
    document.getElementById('edit-disposal-product-info').textContent = name + ' (' + sku + ') - 상품/유통기한 변경 불가';
    document.getElementById('edit-disposal-quantity').value = qty;
    document.getElementById('edit-disposal-reason').value = reason;

    const wrapper = document.getElementById('edit-disposal-reason-note-wrapper');
    const noteInput = document.getElementById('edit-disposal-reason-note');
    noteInput.value = reasonNote || '';
    if (reason === 'other') {
        wrapper.classList.remove('hidden');
        noteInput.required = true;
    } else {
        wrapper.classList.add('hidden');
        noteInput.required = false;
    }

    document.getElementById('editDisposalModal').classList.remove('hidden');
}

document.getElementById('edit-disposal-reason').addEventListener('change', function() {
    const wrapper = document.getElementById('edit-disposal-reason-note-wrapper');
    const input = document.getElementById('edit-disposal-reason-note');
    if (this.value === 'other') {
        wrapper.classList.remove('hidden');
        input.required = true;
    } else {
        wrapper.classList.add('hidden');
        input.required = false;
    }
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
