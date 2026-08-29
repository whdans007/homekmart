<?php
// Design Ref: purchase-from-logistics — 물류센터 배송완료 건 품목별 SKU 매칭 확인 + 매입등록 화면.
// products.sku를 lc_products.barcode_unit/barcode_box/barcode_logistics 와 대조해 자동 매칭하고,
// 매칭 안 된 품목은 수기 검색으로 선택할 수 있게 한다 (Ref: admin/ajax_all_store_prices.php 의
// 기존 products↔lc_products SKU 대조 패턴 재사용).
require_once __DIR__ . '/../lib/lang_helper.php';

$page_title = '물류센터 입고분 매입등록 — 품목 확인';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

if (!has_permission('purchase_management')) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => t('messages.permission_denied')];
    header('Location: shop.php');
    exit;
}

$lc_order_id = (int)($_GET['lc_order_id'] ?? 0);
if (!$lc_order_id) {
    header('Location: purchase_from_logistics.php');
    exit;
}

$conn = get_db_connection();

$stmt = $conn->prepare(
    "SELECT o.id, o.store_id, o.delivered_at, o.status, o.converted_purchase_id, s.name AS store_name
     FROM lc_orders o LEFT JOIN stores s ON o.store_id = s.id
     WHERE o.id = ?"
);
$stmt->bind_param('i', $lc_order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order || $order['status'] !== 'delivered') {
    $_SESSION['flash'] = ['type' => 'error', 'message' => '유효하지 않은 주문입니다.'];
    $conn->close();
    header('Location: purchase_from_logistics.php');
    exit;
}
if ($order['converted_purchase_id'] !== null) {
    $_SESSION['flash'] = ['type' => 'warning', 'message' => '이미 처리된 주문입니다.'];
    $conn->close();
    header('Location: purchase_from_logistics.php');
    exit;
}

// 점포 스코프 검증 (super_admin이 아니면 자기 점포 건만 접근 가능)
if ($_SESSION['role'] !== 'super_admin' && (int)$order['store_id'] !== (int)($current_store_id ?? 0)) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => t('messages.permission_denied')];
    $conn->close();
    header('Location: purchase_from_logistics.php');
    exit;
}

$stmt = $conn->prepare(
    "SELECT oi.id, oi.product_id AS lc_product_id, oi.quantity, oi.order_unit, oi.pieces_per_box, oi.unit_price,
            lp.name_en, lp.name_ko, lp.barcode_unit, lp.barcode_box, lp.barcode_logistics
     FROM lc_order_items oi
     JOIN lc_products lp ON lp.id = oi.product_id
     WHERE oi.order_id = ?
     ORDER BY oi.id"
);
$stmt->bind_param('i', $lc_order_id);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// SKU 자동 매칭: products.sku = lc_products의 barcode_unit/box/logistics 중 하나
// 매칭 안 되는 품목은 물류센터 상품정보(lc_products)를 그대로 admin products에 자동 등록해 매칭시킨다.
foreach ($items as &$it) {
    $it['matched'] = null;
    $it['auto_registered'] = false;
    $codes = array_values(array_filter([$it['barcode_unit'], $it['barcode_box'], $it['barcode_logistics']], fn($v) => $v !== null && $v !== ''));
    if ($codes) {
        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $mstmt = $conn->prepare("SELECT id, sku, name_ko, name_en, pieces_per_box, is_vat_applicable FROM products WHERE sku IN ($placeholders) LIMIT 1");
        $mstmt->bind_param(str_repeat('s', count($codes)), ...$codes);
        $mstmt->execute();
        $it['matched'] = $mstmt->get_result()->fetch_assoc();
        $mstmt->close();
    }

    // Design Ref: purchase-from-logistics — 미매칭 품목 자동 등록 (barcode_unit 우선, 없으면 box/logistics 순)
    if (!$it['matched'] && $codes) {
        $new_sku = $codes[0];
        $name_ko = $it['name_ko'] ?: '';
        $name_en = $it['name_en'] ?: ($it['name_ko'] ?: $new_sku);
        $ppb     = max(1, (int)($it['pieces_per_box'] ?: 1));
        $uid     = (int)($_SESSION['user_id'] ?? 0);

        try {
            $ins = $conn->prepare(
                "INSERT INTO products (sku, name_ko, name_en, is_active, pieces_per_box, is_vat_applicable, last_modified_by_user_id)
                 VALUES (?, ?, ?, 1, ?, 1, ?)"
            );
            $ins->bind_param('sssii', $new_sku, $name_ko, $name_en, $ppb, $uid);
            $ins->execute();
            $new_id = $conn->insert_id;
            $ins->close();

            $it['matched'] = [
                'id' => $new_id, 'sku' => $new_sku, 'name_ko' => $name_ko, 'name_en' => $name_en,
                'pieces_per_box' => $ppb, 'is_vat_applicable' => 1,
            ];
            $it['auto_registered'] = true;
        } catch (Throwable $e) {
            // SKU 중복 등 동시성 문제 시 해당 SKU로 재조회 (경쟁 상황 방어)
            $mstmt = $conn->prepare("SELECT id, sku, name_ko, name_en, pieces_per_box, is_vat_applicable FROM products WHERE sku = ? LIMIT 1");
            $mstmt->bind_param('s', $new_sku);
            $mstmt->execute();
            $it['matched'] = $mstmt->get_result()->fetch_assoc();
            $mstmt->close();
        }
    }

    // 물류센터 주문/출고 단위(BOX/PACK/PCS) → 매입 유형(box/piece)
    $it['default_purchase_type'] = ($it['order_unit'] === 'BOX') ? 'box' : 'piece';
}
unset($it);
$conn->close();

$unmatched_count = count(array_filter($items, fn($i) => !$i['matched']));
$auto_registered_count = count(array_filter($items, fn($i) => !empty($i['auto_registered'])));
?>

<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-bold text-gray-900">
        <i class="fas fa-list-check mr-2 text-indigo-600"></i>품목 확인 — <?php echo htmlspecialchars($order['store_name'] ?? '미지정'); ?>
        <span class="text-sm text-gray-500 font-normal ml-2"><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($order['delivered_at']))); ?></span>
    </h1>
    <a href="purchase_from_logistics.php" class="btn"><i class="fas fa-arrow-left mr-2"></i>목록으로</a>
</div>

<?php if ($auto_registered_count > 0): ?>
<div class="mb-4 rounded-md bg-indigo-50 border border-indigo-200 p-4 text-sm text-indigo-800">
    <i class="fas fa-robot mr-1"></i>
    SKU가 매칭되지 않은 품목 <?php echo $auto_registered_count; ?>건을 물류센터 상품정보로 새로 등록했습니다. 아래에서 확인 후, 다르게 매칭하려면 [변경]을 눌러주세요.
</div>
<?php endif; ?>

<?php if ($unmatched_count > 0): ?>
<div class="mb-4 rounded-md bg-yellow-50 border border-yellow-200 p-4 text-sm text-yellow-800">
    <i class="fas fa-triangle-exclamation mr-1"></i>
    바코드 정보가 없어 자동 등록할 수 없는 품목이 <?php echo $unmatched_count; ?>건 있습니다. 아래에서 직접 상품을 검색해 선택해주세요.
</div>
<?php endif; ?>

<form id="match-form" class="bg-white shadow rounded-lg p-6">
    <input type="hidden" name="lc_order_id" value="<?php echo $lc_order_id; ?>">
    <div class="mb-4">
        <label class="block text-sm font-medium text-gray-700 mb-1">매입일자</label>
        <input type="date" name="purchase_date" value="<?php echo date('Y-m-d', strtotime($order['delivered_at'])); ?>"
               class="border border-gray-300 rounded-md px-3 py-2 text-sm" required>
    </div>

    <table class="min-w-full divide-y divide-gray-200 mb-4" id="match-table">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-3 py-2 text-left text-xs font-semibold text-gray-600 uppercase">물류센터 상품</th>
                <th class="px-3 py-2 text-left text-xs font-semibold text-gray-600 uppercase" style="min-width:260px">매칭 상품 (SKU)</th>
                <th class="px-3 py-2 text-center text-xs font-semibold text-gray-600 uppercase">유형</th>
                <th class="px-3 py-2 text-right text-xs font-semibold text-gray-600 uppercase">수량</th>
                <th class="px-3 py-2 text-right text-xs font-semibold text-gray-600 uppercase">단가</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
        <?php foreach ($items as $idx => $it): $m = $it['matched']; ?>
            <tr data-idx="<?php echo $idx; ?>">
                <td class="px-3 py-2 text-sm text-gray-700">
                    <?php echo htmlspecialchars($it['name_en']); ?>
                    <?php if ($it['name_ko']): ?><span class="text-gray-400">(<?php echo htmlspecialchars($it['name_ko']); ?>)</span><?php endif; ?>
                </td>
                <td class="px-3 py-2 text-sm">
                    <input type="hidden" class="product-id-input" name="items[<?php echo $idx; ?>][product_id]" value="<?php echo $m['id'] ?? ''; ?>">
                    <?php if ($m): ?>
                        <div class="matched-display flex items-center gap-2">
                            <?php if (!empty($it['auto_registered'])): ?>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-800">
                                <i class="fas fa-robot mr-1"></i>신규 등록
                            </span>
                            <?php else: ?>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                <i class="fas fa-check mr-1"></i>매칭됨
                            </span>
                            <?php endif; ?>
                            <span class="text-gray-800"><?php echo htmlspecialchars($m['name_ko'] ?: $m['name_en']); ?></span>
                            <span class="text-xs text-gray-400 font-mono"><?php echo htmlspecialchars($m['sku']); ?></span>
                            <button type="button" class="text-xs text-indigo-500 hover:underline change-match-btn">변경</button>
                        </div>
                    <?php endif; ?>
                    <div class="product-search-wrap relative <?php echo $m ? 'hidden' : ''; ?>">
                        <input type="text" class="product-search-input w-full border border-gray-300 rounded-md px-2 py-1.5 text-sm"
                               placeholder="상품명 또는 SKU 검색">
                        <div class="product-search-results absolute z-20 mt-1 w-full bg-white shadow-lg max-h-48 rounded-md py-1 text-sm ring-1 ring-black ring-opacity-5 overflow-auto hidden"></div>
                        <?php if (!$m): ?>
                        <div class="text-xs text-red-500 mt-1 no-match-warning">미매칭 — 상품을 선택해주세요</div>
                        <?php endif; ?>
                    </div>
                </td>
                <td class="px-3 py-2 text-center">
                    <select name="items[<?php echo $idx; ?>][purchase_type]" class="border border-gray-300 rounded-md text-sm px-2 py-1">
                        <option value="box" <?php echo $it['default_purchase_type']==='box' ? 'selected' : ''; ?>>박스</option>
                        <option value="piece" <?php echo $it['default_purchase_type']==='piece' ? 'selected' : ''; ?>>낱개</option>
                    </select>
                </td>
                <td class="px-3 py-2 text-right">
                    <input type="number" name="items[<?php echo $idx; ?>][quantity]" value="<?php echo (int)$it['quantity']; ?>" min="1"
                           class="w-20 border border-gray-300 rounded-md px-2 py-1 text-sm text-right">
                    <input type="hidden" name="items[<?php echo $idx; ?>][pieces_per_box]" value="<?php echo (int)($it['pieces_per_box'] ?: 1); ?>">
                </td>
                <td class="px-3 py-2 text-right">
                    <input type="number" step="0.01" name="items[<?php echo $idx; ?>][unit_price]" value="<?php echo number_format((float)$it['unit_price'], 2, '.', ''); ?>"
                           class="w-24 border border-gray-300 rounded-md px-2 py-1 text-sm text-right">
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div id="save-error" class="hidden mb-3 text-sm text-red-600"></div>

    <div class="flex justify-end gap-3">
        <a href="purchase_from_logistics.php" class="btn">취소</a>
        <button type="button" id="mark-complete-btn"
                class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-600 bg-gray-100 rounded-md hover:bg-gray-200">
            <i class="fas fa-flag-checkered mr-2"></i>매입등록완료
        </button>
        <button type="button" id="save-btn" class="btn-primary">
            <i class="fas fa-save mr-2"></i>매입 등록
        </button>
    </div>
</form>

<script>
document.querySelectorAll('#match-table tr[data-idx]').forEach(function(row) {
    const searchWrap    = row.querySelector('.product-search-wrap');
    const searchInput   = row.querySelector('.product-search-input');
    const searchResults = row.querySelector('.product-search-results');
    const productIdInput = row.querySelector('.product-id-input');
    const matchedDisplay = row.querySelector('.matched-display');
    const changeBtn      = row.querySelector('.change-match-btn');
    let searchTimeout;

    if (changeBtn) {
        changeBtn.addEventListener('click', function() {
            matchedDisplay.classList.add('hidden');
            searchWrap.classList.remove('hidden');
            searchInput.focus();
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const term = this.value.trim();
            if (term.length < 2) { searchResults.classList.add('hidden'); return; }
            searchTimeout = setTimeout(function() {
                fetch('ajax_search_products.php?term=' + encodeURIComponent(term))
                    .then(r => r.json())
                    .then(data => {
                        searchResults.innerHTML = '';
                        if (!Array.isArray(data) || data.length === 0) {
                            searchResults.innerHTML = '<div class="p-2 text-gray-400">검색 결과 없음</div>';
                            searchResults.classList.remove('hidden');
                            return;
                        }
                        data.forEach(function(p) {
                            const div = document.createElement('div');
                            div.className = 'p-2 hover:bg-indigo-50 cursor-pointer border-b border-gray-100 last:border-b-0';
                            div.innerHTML = '<div class="font-medium text-gray-800">' + (p.name_ko || p.name_en || '') + '</div>'
                                + '<div class="text-xs text-gray-400 font-mono">' + (p.sku || '') + '</div>';
                            div.addEventListener('click', function() {
                                productIdInput.value = p.id;
                                searchInput.value = (p.name_ko || p.name_en) + ' (' + p.sku + ')';
                                searchResults.classList.add('hidden');
                                const warn = row.querySelector('.no-match-warning');
                                if (warn) warn.remove();
                            });
                            searchResults.appendChild(div);
                        });
                        searchResults.classList.remove('hidden');
                    });
            }, 300);
        });
        document.addEventListener('click', function(e) {
            if (!searchWrap.contains(e.target)) searchResults.classList.add('hidden');
        });
    }
});

document.getElementById('save-btn').addEventListener('click', function() {
    const form = document.getElementById('match-form');
    const errEl = document.getElementById('save-error');
    errEl.classList.add('hidden');

    // 미선택 상품 체크
    const missing = Array.from(document.querySelectorAll('.product-id-input')).some(inp => !inp.value);
    if (missing) {
        errEl.textContent = '매칭되지 않은 상품이 있습니다. 모든 품목의 상품을 선택해주세요.';
        errEl.classList.remove('hidden');
        return;
    }

    const fd = new FormData(form);
    this.disabled = true;
    this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>등록 중...';

    fetch('ajax_save_logistics_purchase.php', { method: 'POST', body: fd })
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

// Design Ref: purchase-from-logistics — 실제 매입등록 없이 완료 처리만 하는 버튼
document.getElementById('mark-complete-btn').addEventListener('click', function() {
    if (!confirm('실제 매입 등록을 하지 않고, 이 건을 매입등록완료 상태로 처리합니다.\n계속하시겠습니까?')) return;

    const errEl = document.getElementById('save-error');
    errEl.classList.add('hidden');

    const fd = new FormData();
    fd.append('lc_order_id', <?php echo (int)$lc_order_id; ?>);

    const btn = this;
    btn.disabled = true;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>처리 중...';

    fetch('ajax_mark_logistics_purchase_complete.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                window.location.href = 'purchase_from_logistics.php';
            } else {
                errEl.textContent = data.error || '처리에 실패했습니다.';
                errEl.classList.remove('hidden');
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        })
        .catch(e => {
            errEl.textContent = '네트워크 오류: ' + e.message;
            errEl.classList.remove('hidden');
            btn.disabled = false;
            btn.innerHTML = originalText;
        });
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
