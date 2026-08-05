<?php
// Design Ref: purchase-from-store-transfer — 타점 이동 품목 확인 + 매입등록 화면.
// store_transfer_items.product_id는 이미 admin products 테이블을 직접 참조하므로
// (물류센터 lc_orders 케이스와 달리) SKU 매칭 단계가 필요 없다.
require_once __DIR__ . '/../lib/lang_helper.php';

$page_title = '타점 이동 매입등록 — 품목 확인';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

if (!has_permission('purchase_management')) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => t('messages.permission_denied')];
    header('Location: shop.php');
    exit;
}

$transfer_id = (int)($_GET['transfer_id'] ?? 0);
if (!$transfer_id) {
    header('Location: purchase_from_store_transfer.php');
    exit;
}

$conn = get_db_connection();

$stmt = $conn->prepare(
    "SELECT st.id, st.from_store_id, st.to_store_id, st.transfer_date, st.status, st.converted_purchase_id,
            fs.name AS from_store_name, ts.name AS to_store_name
     FROM store_transfers st
     LEFT JOIN stores fs ON st.from_store_id = fs.id
     LEFT JOIN stores ts ON st.to_store_id = ts.id
     WHERE st.id = ?"
);
$stmt->bind_param('i', $transfer_id);
$stmt->execute();
$transfer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$transfer || $transfer['status'] !== 'confirmed') {
    $_SESSION['flash'] = ['type' => 'error', 'message' => '유효하지 않은 이동 건입니다.'];
    $conn->close();
    header('Location: purchase_from_store_transfer.php');
    exit;
}
if (!empty($transfer['converted_purchase_id'])) {
    $_SESSION['flash'] = ['type' => 'warning', 'message' => '이미 매입등록된 이동 건입니다.'];
    $conn->close();
    header('Location: purchase_from_store_transfer.php');
    exit;
}

// 점포 스코프 검증 (super_admin이 아니면 자기 점포가 받은 이동만 접근 가능)
if ($_SESSION['role'] !== 'super_admin' && (int)$transfer['to_store_id'] !== (int)($current_store_id ?? 0)) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => t('messages.permission_denied')];
    $conn->close();
    header('Location: purchase_from_store_transfer.php');
    exit;
}

$stmt = $conn->prepare(
    "SELECT sti.id, sti.product_id, sti.quantity, sti.unit_cost_price, sti.remarks,
            p.sku, p.name_ko, p.name_en, p.pieces_per_box
     FROM store_transfer_items sti
     LEFT JOIN products p ON sti.product_id = p.id
     WHERE sti.transfer_id = ?
     ORDER BY p.name_en, p.name_ko"
);
$stmt->bind_param('i', $transfer_id);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$conn->close();
?>

<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-bold text-gray-900">
        <i class="fas fa-list-check mr-2 text-teal-600"></i>품목 확인 —
        <span class="text-red-600"><?php echo htmlspecialchars($transfer['from_store_name'] ?? '미지정'); ?></span>
        <i class="fas fa-arrow-right mx-1 text-gray-400 text-sm"></i>
        <span class="text-blue-600"><?php echo htmlspecialchars($transfer['to_store_name'] ?? '미지정'); ?></span>
        <span class="text-sm text-gray-500 font-normal ml-2"><?php echo htmlspecialchars($transfer['transfer_date']); ?></span>
    </h1>
    <a href="purchase_from_store_transfer.php" class="btn"><i class="fas fa-arrow-left mr-2"></i>목록으로</a>
</div>

<form id="match-form" class="bg-white shadow rounded-lg p-6">
    <input type="hidden" name="transfer_id" value="<?php echo $transfer_id; ?>">
    <div class="mb-4">
        <label class="block text-sm font-medium text-gray-700 mb-1">매입일자</label>
        <input type="date" name="purchase_date" value="<?php echo htmlspecialchars($transfer['transfer_date']); ?>"
               class="border border-gray-300 rounded-md px-3 py-2 text-sm" required>
    </div>

    <table class="min-w-full divide-y divide-gray-200 mb-4">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-3 py-2 text-left text-xs font-semibold text-gray-600 uppercase">상품 (SKU)</th>
                <th class="px-3 py-2 text-center text-xs font-semibold text-gray-600 uppercase">유형</th>
                <th class="px-3 py-2 text-right text-xs font-semibold text-gray-600 uppercase">수량</th>
                <th class="px-3 py-2 text-right text-xs font-semibold text-gray-600 uppercase">단가</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
        <?php foreach ($items as $idx => $it): ?>
            <tr>
                <td class="px-3 py-2 text-sm">
                    <input type="hidden" name="items[<?php echo $idx; ?>][product_id]" value="<?php echo (int)$it['product_id']; ?>">
                    <span class="text-gray-800"><?php echo htmlspecialchars($it['name_ko'] ?: $it['name_en']); ?></span>
                    <span class="text-xs text-gray-400 font-mono ml-1"><?php echo htmlspecialchars($it['sku'] ?? ''); ?></span>
                    <?php if ($it['remarks']): ?><div class="text-xs text-gray-400"><?php echo htmlspecialchars($it['remarks']); ?></div><?php endif; ?>
                </td>
                <td class="px-3 py-2 text-center">
                    <select name="items[<?php echo $idx; ?>][purchase_type]" class="border border-gray-300 rounded-md text-sm px-2 py-1">
                        <option value="piece" selected>낱개</option>
                        <option value="box">박스</option>
                    </select>
                </td>
                <td class="px-3 py-2 text-right">
                    <input type="number" name="items[<?php echo $idx; ?>][quantity]" value="<?php echo (int)$it['quantity']; ?>" min="1"
                           class="w-20 border border-gray-300 rounded-md px-2 py-1 text-sm text-right">
                    <input type="hidden" name="items[<?php echo $idx; ?>][pieces_per_box]" value="<?php echo (int)($it['pieces_per_box'] ?: 1); ?>">
                </td>
                <td class="px-3 py-2 text-right">
                    <input type="number" step="0.01" name="items[<?php echo $idx; ?>][unit_price]" value="<?php echo number_format((float)$it['unit_cost_price'], 2, '.', ''); ?>"
                           class="w-24 border border-gray-300 rounded-md px-2 py-1 text-sm text-right">
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div id="save-error" class="hidden mb-3 text-sm text-red-600"></div>

    <div class="flex justify-end gap-3">
        <a href="purchase_from_store_transfer.php" class="btn">취소</a>
        <button type="button" id="save-btn" class="btn-primary">
            <i class="fas fa-save mr-2"></i>매입 등록
        </button>
    </div>
</form>

<script>
document.getElementById('save-btn').addEventListener('click', function() {
    const form = document.getElementById('match-form');
    const errEl = document.getElementById('save-error');
    errEl.classList.add('hidden');

    const fd = new FormData(form);
    this.disabled = true;
    this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>등록 중...';

    fetch('ajax_save_store_transfer_purchase.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                window.location.href = 'edit_purchase.php?id=' + data.purchase_id;
            } else {
                errEl.textContent = data.error || '저장에 실패했습니다.';
                errEl.classList.remove('hidden');
                this.disabled = false;
                this.innerHTML = '<i class="fas fa-save mr-2"></i>매입 등록';
            }
        })
        .catch(e => {
            errEl.textContent = '네트워크 오류: ' + e.message;
            errEl.classList.remove('hidden');
            this.disabled = false;
            this.innerHTML = '<i class="fas fa-save mr-2"></i>매입 등록';
        });
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
