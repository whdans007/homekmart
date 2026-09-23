<?php
require_once __DIR__ . '/lib/stock_count_service.php';
$page_title = "Inbound Details - KIM'S MALL WAREHOUSE";
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/inventory_helper.php';
require_once __DIR__ . '/lib/unit_helper.php';

kw_require_staff();

$batch_id = (int)($_GET['batch_id'] ?? 0);
if (!$batch_id) { header('Location: ' . LC_BASE . '/inbound.php'); exit; }

// 출처 복귀 URL (검색 목록 등). 들어온 화면(inbound_items.php?search=...)으로 되돌아가기 위함.
// 오픈 리다이렉트/헤더 인젝션 방지를 위해 앱 내부 경로만 허용한다.
function kw_safe_return_url(string $val): string {
    if ($val === '') return '';
    if (preg_match('#^(?:https?:)?//#i', $val)) return '';            // 외부/프로토콜 상대 URL 차단
    if (strpos($val, '://') !== false) return '';
    if (strpos($val, "\r") !== false || strpos($val, "\n") !== false) return ''; // 헤더 인젝션 차단
    if (strpos($val, LC_BASE . '/') !== 0) return '';                 // logistics 내부 경로만 허용
    if (strpos($val, '..') !== false) return '';
    return $val;
}
$return_url = kw_safe_return_url((string)($_POST['return'] ?? $_GET['return'] ?? ''));
$back_url   = $return_url !== '' ? $return_url : (LC_BASE . '/inbound.php');
$return_q   = $return_url !== '' ? ('&return=' . urlencode($return_url)) : '';

// 수정 잠금 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm') {
    kw_verify_csrf();
    try {
        $conn = get_lc_db();
        $uid = kw_current_user_id();
        $now = date('Y-m-d H:i:s');
        $st = $conn->prepare(
            "UPDATE kw_inbound_batches SET is_confirmed=1, confirmed_at=?, confirmed_by=? WHERE id=? AND is_confirmed=0"
        );
        $st->bind_param('sii', $now, $uid, $batch_id);
        $st->execute();
        $conn->close();
        kw_set_flash('success', 'The record has been locked for editing.');
    } catch (Exception $e) {
        kw_set_flash('error', 'DB Error: ' . $e->getMessage());
    }
    header('Location: ' . LC_BASE . '/inbound_detail.php?batch_id=' . $batch_id . $return_q);
    exit;
}

// 관리자(super_admin/admin) 배치 삭제 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_batch') {
    kw_verify_csrf();
    if (!kw_is_admin()) {
        kw_set_flash('error', 'Access denied.');
        header('Location: ' . LC_BASE . '/inbound_detail.php?batch_id=' . $batch_id . $return_q); exit;
    }
    try {
        $conn = get_lc_db();

        // 출고된 재고가 있으면 삭제 불가
        $used = $conn->query(
            "SELECT COUNT(*) FROM kw_inventory inv
             JOIN kw_inbound i ON inv.inbound_id = i.id
             WHERE i.batch_id = $batch_id AND inv.quantity_out > 0"
        )->fetch_row()[0];

        if ($used > 0) {
            $conn->close();
            kw_set_flash('error', 'Cannot delete: this batch has already been used for outbound shipments.');
            header('Location: ' . LC_BASE . '/inbound_detail.php?batch_id=' . $batch_id . $return_q); exit;
        }

        $conn->autocommit(false);
        kw_assert_no_open_stock_count($conn);
        // kw_inventory 삭제 (inbound_id 기준)
        $conn->query(
            "DELETE inv FROM kw_inventory inv
             JOIN kw_inbound i ON inv.inbound_id = i.id
             WHERE i.batch_id = $batch_id"
        );
        // kw_inbound 아이템 삭제
        $conn->query("DELETE FROM kw_inbound WHERE batch_id = $batch_id");
        // 배치 헤더 삭제
        $st = $conn->prepare("DELETE FROM kw_inbound_batches WHERE id = ?");
        $st->bind_param('i', $batch_id); $st->execute(); $st->close();
        $conn->commit(); $conn->close();
        kw_set_flash('success', 'Inbound batch #' . $batch_id . ' deleted.');
        // 삭제 성공: 들어온 검색 목록(있으면)으로 복귀, 없으면 Inbound Management로.
        header('Location: ' . $back_url); exit;
    } catch (Exception $e) {
        if (isset($conn)) { $conn->rollback(); $conn->close(); }
        kw_set_flash('error', 'DB Error: ' . $e->getMessage());
        header('Location: ' . LC_BASE . '/inbound_detail.php?batch_id=' . $batch_id . $return_q); exit;
    }
}

// 수정 잠금 해제 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unconfirm') {
    kw_verify_csrf();
    try {
        $conn = get_lc_db();
        $st = $conn->prepare(
            "UPDATE kw_inbound_batches SET is_confirmed=0, confirmed_at=NULL, confirmed_by=NULL WHERE id=? AND is_confirmed=1"
        );
        $st->bind_param('i', $batch_id);
        $st->execute();
        $conn->close();
        kw_set_flash('success', 'The edit lock has been released.');
    } catch (Exception $e) {
        kw_set_flash('error', 'DB Error: ' . $e->getMessage());
    }
    header('Location: ' . LC_BASE . '/inbound_detail.php?batch_id=' . $batch_id . $return_q);
    exit;
}

try {
    $conn = get_lc_db();

    $st = $conn->prepare(
        "SELECT b.*, s.name AS supplier_name, u.full_name AS created_by_name,
                uc.full_name AS confirmed_by_name
         FROM kw_inbound_batches b
         LEFT JOIN kw_suppliers s ON b.supplier_id = s.id
         LEFT JOIN users u ON b.created_by = u.id
         LEFT JOIN users uc ON b.confirmed_by = uc.id
         WHERE b.id = ?"
    );
    $st->bind_param('i', $batch_id); $st->execute();
    $batch = $st->get_result()->fetch_assoc(); $st->close();

    if (!$batch) {
        $conn->close();
        kw_set_flash('error', 'Inbound record not found.');
        header('Location: ' . $back_url); exit;
    }

    $suppliers = $conn->query("SELECT id, name FROM kw_suppliers ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

    $st = $conn->prepare(
        "SELECT i.*, CONCAT(p.name_en, IFNULL(CONCAT(' (', p.name_ko, ')'), '')) AS product_name,
                p.name_en, p.name_ko, p.capacity, p.unit, p.requires_expiry,
                COALESCE(NULLIF(p.barcode_unit,''), NULLIF(p.barcode_box,''), NULLIF(p.barcode_logistics,'')) AS barcode,
                inv.storage_location
         FROM kw_inbound i
         JOIN kw_products p ON i.product_id = p.id
         LEFT JOIN kw_inventory inv ON inv.inbound_id = i.id
         WHERE i.batch_id = ?
         ORDER BY i.id ASC"
    );
    $st->bind_param('i', $batch_id); $st->execute();
    $items = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

    $total_qty    = array_sum(array_column($items, 'quantity'));
    $total_amount = array_sum(array_map(fn($r) => $r['quantity'] * $r['cost_price'], $items));

    $conn->close();
} catch (Exception $e) {
    kw_set_flash('error', 'DB Error:' . $e->getMessage());
    header('Location: ' . LC_BASE . '/inbound.php'); exit;
}
$locked = (bool)$batch['is_confirmed'];
?>
<div class="flex items-center gap-3 mb-6 flex-wrap">
    <a id="backLink" href="<?php echo htmlspecialchars($back_url); ?>" class="text-gray-400 hover:text-gray-600"><i class="fas fa-arrow-left"></i></a>
    <h2 class="text-xl font-bold text-gray-900">Inbound Details</h2>
    <?php if (kw_is_admin()): ?>
    <form method="post" class="inline ml-auto">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(kw_csrf_token()); ?>">
        <input type="hidden" name="action" value="delete_batch">
        <input type="hidden" name="return" value="<?php echo htmlspecialchars($return_url); ?>">
        <button type="submit"
                onclick="return confirm('Permanently delete inbound batch #<?php echo $batch_id; ?>?\nIt cannot be deleted if any of its stock has already been shipped out.')"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-red-50 hover:bg-red-100 text-red-600 border border-red-200 text-xs font-semibold rounded-lg transition-colors">
            <i class="fas fa-trash"></i> Delete Batch
        </button>
    </form>
    <?php endif; ?>
    <?php if ($batch['is_confirmed']): ?>
    <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-gray-100 text-gray-600 text-xs font-semibold rounded-full">
        <i class="fas fa-lock"></i> Locked
        <?php if (!empty($batch['confirmed_at'])): ?>
        <span class="font-normal opacity-70">· <?php echo $batch['confirmed_at']; ?></span>
        <?php endif; ?>
        <?php if (!empty($batch['confirmed_by_name'])): ?>
        <span class="font-normal opacity-70">· <?php echo htmlspecialchars($batch['confirmed_by_name']); ?></span>
        <?php endif; ?>
    </span>
    <form method="post" class="inline">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(kw_csrf_token()); ?>">
        <input type="hidden" name="action" value="unconfirm">
        <button type="submit" onclick="return confirm('Are you sure you want to unlock this record for editing?')"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-gray-100 hover:bg-teal-50 text-gray-500 hover:text-teal-600 border border-gray-200 hover:border-teal-200 text-xs font-semibold rounded-lg transition-colors">
            <i class="fas fa-unlock"></i> Unlock
        </button>
    </form>
    <?php else: ?>
    <form method="post" class="inline">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(kw_csrf_token()); ?>">
        <input type="hidden" name="action" value="confirm">
        <button type="submit" onclick="return confirm('Lock this inbound record for editing?\nOnce locked, items, locations, suppliers, and quantities cannot be modified.')"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-teal-600 hover:bg-teal-700 text-white text-xs font-semibold rounded-lg transition-colors">
            <i class="fas fa-lock"></i> Lock
        </button>
    </form>
    <?php endif; ?>
</div>

<script>
// 뒤로 가기: 들어온 출처(검색/목록 페이지)가 같은 사이트면 그 화면으로 복귀, 아니면 Inbound Management로.
(function() {
    var back = document.getElementById('backLink');
    if (!back) return;
    var ref = document.referrer;
    if (!ref) return;
    try {
        var u = new URL(ref);
        // 같은 출처 + 상세 페이지 자체(POST 후 자기 리다이렉트)가 아닐 때만 referrer로 복귀
        if (u.origin === location.origin && u.pathname.indexOf('/inbound_detail.php') === -1) {
            back.href = ref;
        }
    } catch (e) { /* 잘못된 referrer 무시 → 기본 inbound.php 유지 */ }
})();
</script>

<!-- 배치 헤더 -->
<div class="bg-white rounded-lg border border-gray-200 p-5 mb-5">
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
        <div>
            <p class="text-xs text-gray-500 mb-1">Inbound Date</p>
            <p class="font-semibold text-gray-900"><?php echo date('d M Y', strtotime($batch['inbound_date'])); ?></p>
        </div>
        <div>
            <p class="text-xs text-gray-500 mb-1">Supplier</p>
            <div class="flex items-center gap-1.5">
                <span id="supplierName" class="font-semibold text-gray-900"><?php echo htmlspecialchars($batch['supplier_name'] ?? '-'); ?></span>
                <?php if (!$batch['is_confirmed']): ?>
                <button type="button" onclick="openSupplierModal()" title="Change Supplier"
                        class="text-gray-300 hover:text-teal-500 transition-colors">
                    <i class="fas fa-pen text-xs"></i>
                </button>
                <?php endif; ?>
            </div>
        </div>
        <div>
            <p class="text-xs text-gray-500 mb-1">Total Amount</p>
            <p class="font-semibold text-gray-900" id="totalAmountDisplay"><?php echo number_format($total_amount, 2); ?></p>
        </div>
        <div>
            <p class="text-xs text-gray-500 mb-1">Registered By</p>
            <p class="font-semibold text-gray-900"><?php echo htmlspecialchars($batch['created_by_name'] ?? '-'); ?></p>
        </div>
    </div>
    <?php if ($batch['notes']): ?>
    <div class="mt-3 pt-3 border-t border-gray-100 text-sm text-gray-600"><?php echo htmlspecialchars($batch['notes']); ?></div>
    <?php endif; ?>
</div>

<style>
#itemsTable input[type=number]::-webkit-outer-spin-button,
#itemsTable input[type=number]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
#itemsTable input[type=number] { -moz-appearance: textfield; appearance: textfield; }
#itemsTable th, #itemsTable td { padding-left: 4px; padding-right: 4px; }
</style>

<!-- 상품 목록 (add 스타일 편집 그리드 + 행별 자동저장) -->
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
    <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
        <span class="text-sm text-gray-500">Total <?php echo count($items); ?> items · <?php echo number_format($total_qty); ?> units
            <?php if (!$locked): ?><span class="ml-2 text-xs text-gray-400"><i class="fas fa-bolt text-amber-400 mr-1"></i>Edits save automatically</span><?php endif; ?>
        </span>
        <?php if (!$locked): ?>
        <a href="<?php echo LC_BASE; ?>/inbound_add.php?batch_id=<?php echo $batch_id; ?>"
           class="text-xs text-teal-600 hover:text-teal-800 font-medium">
            <i class="fas fa-plus mr-1"></i>Add More Items
        </a>
        <?php endif; ?>
    </div>
    <div class="overflow-x-auto">
        <table id="itemsTable" class="w-full text-sm" style="min-width:1100px">
            <thead class="bg-gray-50 border-b border-gray-100">
                <tr>
                    <th class="px-3 py-2 text-left text-xs text-gray-500 font-medium" style="width:2rem">#</th>
                    <th class="px-3 py-2 text-left text-xs text-gray-500 font-medium" style="width:14rem">Product</th>
                    <th class="px-3 py-2 text-left text-xs text-gray-500 font-medium w-20">Capacity</th>
                    <th class="px-3 py-2 text-center text-xs text-gray-500 font-medium w-16">Unit</th>
                    <th class="px-3 py-2 text-left text-xs text-gray-500 font-medium w-14">PKG</th>
                    <th class="px-3 py-2 text-left text-xs text-gray-500 font-medium" style="width:8rem">Expiry Date</th>
                    <th class="px-3 py-2 text-left text-xs text-gray-500 font-medium w-20">QTY(PCS)</th>
                    <th class="px-3 py-2 text-left text-xs text-gray-500 font-medium w-20">QTY(BOX)</th>
                    <th class="px-3 py-2 text-left text-xs text-gray-500 font-medium w-24">PRICE(PCS)</th>
                    <th class="px-3 py-2 text-left text-xs text-gray-500 font-medium w-24">PRICE(BOX)</th>
                    <th class="px-3 py-2 text-left text-xs text-gray-500 font-medium w-24">Location</th>
                    <th class="px-3 py-2 text-center text-xs text-orange-400 font-medium w-16">Discount</th>
                    <th class="px-3 py-2 text-left text-xs text-teal-600 font-medium w-24">COST(PCS)</th>
                    <th class="px-3 py-2 text-left text-xs text-teal-600 font-medium w-24">COST(BOX)</th>
                    <th class="px-3 py-2 text-left text-xs text-teal-700 font-semibold w-28">Total</th>
                    <th class="px-3 py-2 w-7"></th>
                </tr>
            </thead>
            <tbody id="itemsBody" class="divide-y divide-gray-100">
            <?php if (empty($items)): ?>
            <tr><td colspan="16" class="px-4 py-8 text-center text-gray-400">No products registered.</td></tr>
            <?php endif; ?>
            <?php foreach ($items as $idx => $row): ?>
            <?php
                $regular  = (float)($row['regular_price'] ?? $row['cost_price']);
                $drate    = (float)($row['discount_rate'] ?? 0);
                $unit     = kw_valid_unit($row['inbound_unit'] ?? 'PCS');
                $is_bundle = kw_is_bundle_unit($unit); // BOX/PACK 공통 처리 (pack-unit §4)
                $ppb      = max(1, (int)($row['pieces_per_box'] ?? 1));
                $qty      = (int)$row['quantity'];
                $cost     = (float)$row['cost_price'];
                $cost_pcs = (float)($row['cost_price_pcs'] ?? 0);
                if ($cost_pcs <= 0) $cost_pcs = ($is_bundle && $ppb > 0) ? round($cost / $ppb, 4) : $cost;
                $qty_pcs   = $is_bundle ? $qty * $ppb : $qty;
                $qty_box   = $is_bundle ? $qty : ($ppb > 0 ? round($qty / $ppb, 2) : $qty);
                $price_pcs = $unit === 'PCS' ? $regular : ($ppb > 0 ? round($regular / $ppb, 2) : $regular);
                $price_box = $is_bundle ? $regular : round($regular * $ppb, 2);
                $cost_box  = round($cost_pcs * $ppb, 2);
                $expClass  = kw_expiry_class($row['expiry_date']);
                $exp_val   = $row['expiry_date'] ? date('Y-m-d', strtotime($row['expiry_date'])) : '';
                $req_expiry = !empty($row['requires_expiry']) ? 1 : 0;
            ?>
            <?php if ($locked): ?>
            <!-- 잠금: 읽기전용 -->
            <tr class="hover:bg-gray-50">
                <td class="px-3 py-2 text-gray-400 text-xs"><?php echo $idx + 1; ?></td>
                <td class="px-3 py-2" style="width:14rem;max-width:14rem">
                    <div class="truncate text-xs font-medium text-gray-700"><?php echo htmlspecialchars($row['name_en']); ?> <span class="text-gray-400">[<?php echo htmlspecialchars($row['unit']); ?>]</span></div>
                    <div class="truncate text-xs text-gray-400"><?php echo $row['name_ko'] ? htmlspecialchars($row['name_ko']) : '-'; ?></div>
                    <div class="truncate text-xs text-gray-400 font-mono"><?php echo htmlspecialchars($row['barcode'] ?? '-'); ?></div>
                </td>
                <td class="px-3 py-2 text-xs text-gray-500"><?php echo htmlspecialchars($row['capacity'] ?? '-'); ?></td>
                <td class="px-3 py-2 text-center text-xs font-semibold <?php echo $unit === 'BOX' ? 'text-amber-600' : ($unit === 'PACK' ? 'text-emerald-600' : 'text-blue-600'); ?>"><?php echo htmlspecialchars($unit); ?></td>
                <td class="px-3 py-2 text-center text-xs text-gray-500"><?php echo $ppb; ?></td>
                <td class="px-3 py-2 text-xs <?php echo $exp_val ? ($expClass ?: 'text-gray-700') : 'text-gray-400'; ?> whitespace-nowrap"><?php echo $exp_val ? date('d M Y', strtotime($exp_val)) : '-'; ?></td>
                <td class="px-3 py-2 font-semibold text-gray-700"><?php echo $is_bundle ? '-' : number_format($qty_pcs); ?></td>
                <td class="px-3 py-2 font-semibold text-gray-700"><?php echo $is_bundle ? number_format($qty_box) : '-'; ?></td>
                <td class="px-3 py-2 text-gray-500 text-xs"><?php echo number_format($price_pcs, 2); ?></td>
                <td class="px-3 py-2 text-gray-500 text-xs"><?php echo number_format($price_box, 2); ?></td>
                <td class="px-3 py-2 text-xs text-gray-500"><?php echo htmlspecialchars($row['storage_location'] ?? '-'); ?></td>
                <td class="px-3 py-2 text-center">
                    <?php if ($drate > 0): ?>
                    <span class="inline-block px-1.5 py-0.5 bg-orange-100 text-orange-600 text-xs font-semibold rounded">-<?php echo rtrim(rtrim(number_format($drate, 2), '0'), '.'); ?>%</span>
                    <?php else: ?><span class="text-gray-300 text-xs">-</span><?php endif; ?>
                </td>
                <td class="px-3 py-2 font-semibold text-teal-700"><?php echo number_format($cost_pcs, 2); ?></td>
                <td class="px-3 py-2 font-semibold text-teal-700"><?php echo number_format($cost_box, 2); ?></td>
                <td class="px-3 py-2 font-bold text-teal-800"><?php echo number_format($qty * $cost, 2); ?></td>
                <td class="px-3 py-2 text-center"><span class="text-gray-300 text-xs"><i class="fas fa-lock"></i></span></td>
            </tr>
            <?php else: ?>
            <!-- 편집 가능: 행별 자동저장 -->
            <tr class="item-row hover:bg-gray-50" data-inbound-id="<?php echo $row['id']; ?>" data-req-expiry="<?php echo $req_expiry; ?>">
                <td class="px-3 py-2 text-gray-400 text-xs"><?php echo $idx + 1; ?></td>
                <td class="px-3 py-2" style="width:14rem;max-width:14rem">
                    <div class="truncate text-xs font-medium text-gray-700"><?php echo htmlspecialchars($row['name_en']); ?> <span class="text-gray-400">[<?php echo htmlspecialchars($row['unit']); ?>]</span></div>
                    <div class="truncate text-xs text-gray-400"><?php echo $row['name_ko'] ? htmlspecialchars($row['name_ko']) : '-'; ?></div>
                    <div class="truncate text-xs text-gray-400 font-mono"><?php echo htmlspecialchars($row['barcode'] ?? '-'); ?></div>
                </td>
                <td class="px-3 py-2"><span class="text-xs text-gray-500"><?php echo htmlspecialchars($row['capacity'] ?? '-'); ?></span></td>
                <td class="px-3 py-2">
                    <select class="row-unit-select w-full border border-gray-200 rounded px-1 py-1.5 text-xs text-center focus:outline-none focus:ring-1 focus:ring-teal-500" onchange="onRowUnitSelectChange(this)">
                        <option value="BOX" <?php echo $unit === 'BOX' ? 'selected' : ''; ?>>BOX</option>
                        <option value="PACK" <?php echo $unit === 'PACK' ? 'selected' : ''; ?>>PACK</option>
                        <option value="PCS" <?php echo $unit === 'PCS' ? 'selected' : ''; ?>>PCS</option>
                    </select>
                </td>
                <td class="px-3 py-2">
                    <input type="number" min="1" step="1" value="<?php echo $ppb; ?>"
                           class="row-ppb-input w-full border border-gray-200 rounded px-2 py-1.5 text-sm text-center focus:outline-none focus:ring-1 focus:ring-teal-500"
                           oninput="onRowPpbInput(this)">
                </td>
                <td class="px-3 py-2">
                    <input type="text" class="row-expiry-input w-full border border-gray-200 rounded px-2 py-1.5 text-sm font-mono focus:outline-none focus:ring-1 focus:ring-teal-500"
                           placeholder="YYYYMMDD" maxlength="10" autocomplete="off" value="<?php echo htmlspecialchars($exp_val); ?>">
                </td>
                <td class="px-3 py-2">
                    <input type="number" placeholder="0" value="<?php echo $qty_pcs > 0 ? $qty_pcs : ''; ?>"
                           class="row-qty-pcs w-full border border-gray-200 rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-teal-500"
                           oninput="onRowQtyPcs(this)">
                    <span class="row-qty-pcs-dash hidden text-sm text-gray-300">-</span>
                </td>
                <td class="px-3 py-2">
                    <input type="number" placeholder="0" value="<?php echo $qty_box > 0 ? $qty_box : ''; ?>"
                           class="row-qty-box w-full border border-gray-200 rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-teal-500"
                           oninput="onRowQtyBox(this)">
                    <span class="row-qty-box-dash hidden text-sm text-gray-300">-</span>
                </td>
                <td class="px-3 py-2">
                    <input type="number" step="0.01" min="0" placeholder="0.00" value="<?php echo $price_pcs > 0 ? htmlspecialchars((string)round($price_pcs, 2)) : ''; ?>"
                           class="row-price-pcs w-full border border-gray-200 rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-teal-500"
                           oninput="onRowPricePcs(this)">
                </td>
                <td class="px-3 py-2">
                    <input type="number" step="0.01" min="0" placeholder="0.00" value="<?php echo $price_box > 0 ? htmlspecialchars((string)round($price_box, 2)) : ''; ?>"
                           class="row-price-box w-full border border-gray-200 rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-teal-500"
                           oninput="onRowPriceBox(this)">
                </td>
                <td class="px-3 py-2">
                    <input type="text" placeholder="e.g. A-01-03" value="<?php echo htmlspecialchars($row['storage_location'] ?? ''); ?>"
                           class="row-loc-input w-full border border-gray-200 rounded px-2 py-1.5 text-sm font-mono focus:outline-none focus:ring-1 focus:ring-teal-500">
                </td>
                <td class="px-3 py-2">
                    <div class="flex items-center justify-center gap-0.5">
                        <input type="number" min="0" max="100" step="0.1" placeholder="0" value="<?php echo $drate > 0 ? htmlspecialchars((string)$drate) : ''; ?>"
                               class="row-discount-input w-12 border border-orange-200 rounded px-1 py-1.5 text-xs text-center focus:outline-none focus:ring-1 focus:ring-orange-400"
                               oninput="onRowDiscountInput(this)">
                        <span class="text-gray-400 text-xs">%</span>
                    </div>
                </td>
                <td class="px-3 py-2"><span class="row-final-cost text-sm font-semibold text-teal-700"><?php echo number_format($cost_pcs, 2); ?></span></td>
                <td class="px-3 py-2"><span class="row-final-cost-box text-sm font-semibold text-teal-700"><?php echo number_format($cost_box, 2); ?></span></td>
                <td class="px-3 py-2"><span class="row-total text-sm font-bold text-teal-800" id="subtotal-<?php echo $row['id']; ?>"><?php echo number_format($qty * $cost, 2); ?></span></td>
                <td class="px-3 py-2 text-center">
                    <button type="button" onclick="deleteInboundItem(<?php echo $row['id']; ?>, this)"
                            class="text-gray-300 hover:text-red-400 transition-colors" title="Delete"><i class="fas fa-times text-xs"></i></button>
                </td>
            </tr>
            <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
            <?php if (!empty($items)): ?>
            <tfoot class="bg-gray-50 border-t border-gray-200">
                <tr>
                    <td colspan="14" class="px-3 py-2 text-xs text-gray-500 text-right font-medium">Total</td>
                    <td class="px-3 py-2 font-bold text-teal-800" id="footerTotalDisplay"><?php echo number_format($total_amount, 2); ?></td>
                    <td></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<script>
(function() {
    var LC_BASE = '<?php echo LC_BASE; ?>';
    var CSRF    = '<?php echo htmlspecialchars(kw_csrf_token()); ?>';
    var BATCH   = <?php echo $batch_id; ?>;

    // ── Supplier 변경/추가 모달 ─────────────────────────────────────────
    function supplierModalError(msg) {
        var err = document.getElementById('supplierModalError');
        if (!err) return;
        err.textContent = msg; err.classList.remove('hidden');
    }

    window.openSupplierModal = function() {
        var modal = document.getElementById('supplierModal');
        if (!modal) return;
        document.getElementById('supplierAddRow').classList.add('hidden');
        document.getElementById('newSupplierName').value = '';
        document.getElementById('supplierModalError').classList.add('hidden');
        modal.classList.remove('hidden');
        document.getElementById('supplierSelect').focus();
    };
    window.closeSupplierModal = function() {
        var modal = document.getElementById('supplierModal');
        if (modal) modal.classList.add('hidden');
    };
    window.toggleSupplierAdd = function() {
        var row = document.getElementById('supplierAddRow');
        row.classList.toggle('hidden');
        if (!row.classList.contains('hidden')) document.getElementById('newSupplierName').focus();
    };

    window.createSupplier = function() {
        var nameEl = document.getElementById('newSupplierName');
        var name = nameEl.value.trim();
        if (!name) { nameEl.focus(); return; }
        var btn = document.getElementById('newSupplierBtn');
        btn.disabled = true; btn.textContent = '…';

        var fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('name', name);
        fetch(LC_BASE + '/ajax/quick_create_supplier.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) { supplierModalError(data.message || 'Failed to create supplier.'); return; }
                // 새 공급처를 드롭다운에 추가하고 선택 (저장은 Save 버튼으로 확정)
                var sel = document.getElementById('supplierSelect');
                var opt = new Option(data.supplier.name, data.supplier.id, true, true);
                sel.appendChild(opt);
                sel.value = data.supplier.id;
                nameEl.value = '';
                document.getElementById('supplierAddRow').classList.add('hidden');
                document.getElementById('supplierModalError').classList.add('hidden');
            })
            .catch(function() { supplierModalError('Request failed.'); })
            .finally(function() { btn.disabled = false; btn.textContent = 'Create'; });
    };

    window.saveSupplier = function() {
        var sel = document.getElementById('supplierSelect');
        var sid = sel.value;
        var btn = document.getElementById('supplierSaveBtn');
        btn.disabled = true; btn.textContent = 'Saving…';

        var fd = new FormData();
        fd.append('action', 'update_supplier');
        fd.append('csrf_token', CSRF);
        fd.append('supplier_id', sid);
        fd.append('batch_id', BATCH);
        fetch(LC_BASE + '/ajax/update_batch_supplier.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) { supplierModalError(data.message || 'An error occurred.'); return; }
                document.getElementById('supplierName').textContent = data.supplier_name || '-';
                closeSupplierModal();
            })
            .catch(function() { supplierModalError('Request failed.'); })
            .finally(function() { btn.disabled = false; btn.textContent = 'Save'; });
    };

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeSupplierModal();
    });

    // ── 항목 삭제 ──────────────────────────────────────────────────────
    window.deleteInboundItem = function(inboundId, btn) {
        if (!confirm('Delete this item?\nThis cannot be undone, and is only possible if it has not been shipped out yet.')) return;
        btn.disabled = true;
        var fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('inbound_id', inboundId);
        fetch(LC_BASE + '/ajax/delete_inbound_item.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) { alert(data.message || 'Delete failed'); btn.disabled = false; return; }
                if (data.remaining > 0) { location.reload(); }
                else { location.href = LC_BASE + '/inbound.php'; }
            })
            .catch(function() { alert('Request failed'); btn.disabled = false; });
    };

    // ── 행 계산 헬퍼 (add 그리드 로직 이식) ─────────────────────────────
    function getRowPpb(row) {
        var inp = row.querySelector('.row-ppb-input');
        return Math.max(1, parseInt(inp ? inp.value : '1', 10));
    }

    window.onRowQtyBox = function(inp) {
        var row = inp.closest('tr.item-row');
        var ppb = getRowPpb(row);
        var box = parseFloat(inp.value) || 0;
        var pcsInp = row.querySelector('.row-qty-pcs');
        if (pcsInp) pcsInp.value = box > 0 ? Math.round(box * ppb) : '';
        updateRowFinalCost(row);
    };
    window.onRowQtyPcs = function(inp) {
        var row = inp.closest('tr.item-row');
        var ppb = getRowPpb(row);
        var pcs = parseFloat(inp.value) || 0;
        var boxInp = row.querySelector('.row-qty-box');
        if (boxInp) boxInp.value = pcs > 0 && ppb > 1 ? (pcs / ppb).toFixed(2) : (pcs > 0 ? pcs : '');
        updateRowFinalCost(row);
    };
    window.onRowPricePcs = function(inp) {
        var row = inp.closest('tr.item-row');
        var ppb = getRowPpb(row);
        var pcs = parseFloat(inp.value) || 0;
        var boxInp = row.querySelector('.row-price-box');
        if (boxInp) boxInp.value = pcs > 0 ? (pcs * ppb).toFixed(2) : '';
        updateRowFinalCost(row);
    };
    window.onRowPriceBox = function(inp) {
        var row = inp.closest('tr.item-row');
        var ppb = getRowPpb(row);
        var box = parseFloat(inp.value) || 0;
        var pcsInp = row.querySelector('.row-price-pcs');
        if (pcsInp) pcsInp.value = box > 0 ? (box / ppb).toFixed(2) : '';
        updateRowFinalCost(row);
    };
    window.onRowPpbInput = function(inp) {
        var v = parseInt(inp.value, 10);
        if (!v || v < 1) { inp.value = '1'; }
        var row = inp.closest('tr.item-row');
        var ppb = Math.max(1, parseInt(inp.value, 10));
        var boxQty = parseFloat(row.querySelector('.row-qty-box') ? row.querySelector('.row-qty-box').value : '') || 0;
        var pcsInp = row.querySelector('.row-qty-pcs');
        if (pcsInp && boxQty > 0) pcsInp.value = Math.round(boxQty * ppb);
        var pcsPriceVal = parseFloat(row.querySelector('.row-price-pcs') ? row.querySelector('.row-price-pcs').value : '') || 0;
        var boxPriceInp = row.querySelector('.row-price-box');
        if (boxPriceInp && pcsPriceVal > 0) boxPriceInp.value = (pcsPriceVal * ppb).toFixed(2);
        updateRowFinalCost(row);
    };
    window.onRowDiscountInput = function(inp) {
        updateRowFinalCost(inp.closest('tr.item-row'));
    };

    function highlightRowUnit(row) {
        var sel = row.querySelector('.row-unit-select');
        if (!sel) return;
        var isBundle = sel.value !== 'PCS';
        var pcsQ = row.querySelector('.row-qty-pcs');
        var boxQ = row.querySelector('.row-qty-box');
        if (boxQ) boxQ.style.backgroundColor = isBundle ? (sel.value === 'PACK' ? '#ecfdf5' : '#fff7ed') : '';
        if (pcsQ) pcsQ.style.backgroundColor = isBundle ? '' : '#eff6ff';
    }

    // 유통기한 필수가 아닌 상품은 입력란 차단(readonly로 자동저장 페이로드 유지) + 값 비움
    function applyExpiryEditable(row) {
        var exp = row.querySelector('.row-expiry-input');
        if (!exp) return;
        var req = parseInt(row.getAttribute('data-req-expiry') || '0', 10);
        if (req) {
            exp.readOnly = false;
            exp.placeholder = 'YYYYMMDD';
            exp.classList.remove('bg-gray-100', 'text-gray-400', 'cursor-not-allowed');
        } else {
            exp.value = '';
            exp.readOnly = true;
            exp.placeholder = '-';
            exp.classList.add('bg-gray-100', 'text-gray-400', 'cursor-not-allowed');
        }
    }

    // 매입 단위에 따라 수량 칸만 표시 토글 (선택 단위만 입력, 반대는 '-'). 단가 칸은 그대로.
    function applyUnitQtyVisibility(row) {
        var sel = row.querySelector('.row-unit-select');
        var isBundle = sel ? sel.value !== 'PCS' : true;
        var pcsInp = row.querySelector('.row-qty-pcs');
        var boxInp = row.querySelector('.row-qty-box');
        var pcsDash = row.querySelector('.row-qty-pcs-dash');
        var boxDash = row.querySelector('.row-qty-box-dash');
        if (pcsInp)  pcsInp.style.display = isBundle ? 'none' : '';
        if (boxInp)  boxInp.style.display = isBundle ? '' : 'none';
        if (pcsDash) pcsDash.classList.toggle('hidden', !isBundle);
        if (boxDash) boxDash.classList.toggle('hidden', isBundle);
    }

    window.onRowUnitSelectChange = function(sel) {
        var row = sel.closest('tr.item-row');
        if (!row) return;
        highlightRowUnit(row);
        applyUnitQtyVisibility(row);
        updateRowFinalCost(row);
        saveRow(row); // 단위는 즉시 저장
    };

    // COST(PCS)/COST(BOX)/Total 로컬 재계산 (PRICE(PCS) 기준 할인 후)
    function updateRowFinalCost(row) {
        var rate = parseFloat(row.querySelector('.row-discount-input') ? row.querySelector('.row-discount-input').value : '') || 0;
        var pcsPrice = parseFloat(row.querySelector('.row-price-pcs') ? row.querySelector('.row-price-pcs').value : '') || 0;
        var ppb = getRowPpb(row);
        var qtyPcs = parseFloat(row.querySelector('.row-qty-pcs') ? row.querySelector('.row-qty-pcs').value : '') || 0;
        var finalSpan = row.querySelector('.row-final-cost');
        var boxSpan   = row.querySelector('.row-final-cost-box');
        var totalSpan = row.querySelector('.row-total');
        if (!finalSpan) return;
        var costPcs = rate > 0 && pcsPrice > 0 ? pcsPrice * (1 - rate / 100) : pcsPrice;
        finalSpan.textContent = pcsPrice > 0 ? costPcs.toFixed(2) : '-';
        if (boxSpan) boxSpan.textContent = pcsPrice > 0 ? (costPcs * ppb).toFixed(2) : '-';
        if (totalSpan) totalSpan.textContent = (costPcs > 0 && qtyPcs > 0) ? (costPcs * qtyPcs).toFixed(2) : '-';
    }

    function recalcTotals() {
        var total = 0;
        document.querySelectorAll('#itemsBody .row-total').forEach(function(el) {
            total += parseFloat(el.textContent.replace(/,/g, '')) || 0;
        });
        var formatted = total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        var header = document.getElementById('totalAmountDisplay');
        if (header) header.textContent = formatted;
        var footer = document.getElementById('footerTotalDisplay');
        if (footer) footer.textContent = formatted;
    }

    // ── 행별 자동저장 (기존 안전 엔드포인트 재사용) ──────────────────────
    function flashRow(row, ok) {
        row.style.transition = 'background-color .2s';
        row.style.backgroundColor = ok ? '#ecfdf5' : '#fef2f2';
        setTimeout(function() { row.style.backgroundColor = ''; }, 600);
    }

    function saveRow(row) {
        var id = row.dataset.inboundId;
        if (!id) return;
        var unit = (row.querySelector('.row-unit-select') || {}).value || 'PCS';
        var ppb  = getRowPpb(row);
        var pcsQty = parseFloat(row.querySelector('.row-qty-pcs').value) || 0;
        var boxQty = parseFloat(row.querySelector('.row-qty-box').value) || 0;
        var pcsPrice = parseFloat(row.querySelector('.row-price-pcs').value) || 0;
        var boxPrice = parseFloat(row.querySelector('.row-price-box').value) || 0;
        var rate = parseFloat(row.querySelector('.row-discount-input').value) || 0;
        var expiry = (row.querySelector('.row-expiry-input').value || '').trim();

        var isBundle = unit !== 'PCS';
        var qty     = isBundle ? Math.round(boxQty) : Math.round(pcsQty);
        var regular = isBundle ? boxPrice : pcsPrice;
        if (qty <= 0) return; // 유효하지 않은 수량은 저장 보류

        var fields = {
            quantity:       qty,
            inbound_unit:   unit,
            pieces_per_box: ppb,
            regular_price:  regular,
            discount_rate:  rate,
            expiry_date:    expiry
        };

        var fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('inbound_id', id);
        fd.append('fields', JSON.stringify(fields));

        fetch(LC_BASE + '/ajax/update_inbound_item.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) { alert(data.message || 'Save failed'); flashRow(row, false); return; }
                // 서버 권위값으로 표시 동기화
                var fc = row.querySelector('.row-final-cost');
                var fb = row.querySelector('.row-final-cost-box');
                var tt = row.querySelector('.row-total');
                if (fc) fc.textContent = Number(data.cost_price_pcs).toFixed(2);
                if (fb) fb.textContent = (Number(data.cost_price_pcs) * data.pieces_per_box).toFixed(2);
                if (tt) tt.textContent = data.subtotal_display;
                var exp = row.querySelector('.row-expiry-input');
                if (exp && data.expiry_date) exp.value = data.expiry_date;
                recalcTotals();
                flashRow(row, true);
            })
            .catch(function() { alert('Request failed'); flashRow(row, false); });
    }

    function saveLocation(row) {
        var id = row.dataset.inboundId;
        var val = (row.querySelector('.row-loc-input').value || '').trim();
        var fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('inbound_id', id);
        fd.append('storage_location', val);
        fetch(LC_BASE + '/ajax/update_inventory_location.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) { flashRow(row, !!data.success); if (!data.success) alert(data.message || 'Save failed'); })
            .catch(function() { alert('Request failed'); flashRow(row, false); });
    }

    // ── 자동저장 트리거 바인딩 ──────────────────────────────────────────
    document.querySelectorAll('#itemsBody tr.item-row').forEach(function(row) {
        highlightRowUnit(row);
        applyUnitQtyVisibility(row);
        applyExpiryEditable(row);

        // 수량/가격/PKG/할인/유통기한 → change(커밋) 시 행 저장
        ['.row-qty-pcs', '.row-qty-box', '.row-price-pcs', '.row-price-box', '.row-ppb-input', '.row-discount-input', '.row-expiry-input']
            .forEach(function(selc) {
                var el = row.querySelector(selc);
                if (el) el.addEventListener('change', function() { saveRow(row); });
            });

        // 위치 → change 시 위치 저장
        var loc = row.querySelector('.row-loc-input');
        if (loc) loc.addEventListener('change', function() { saveLocation(row); });
    });

    // ── 유통기한 자동 포맷 (YYYYMMDD → YYYY-MM-DD) ──────────────────────
    var bodyEl = document.getElementById('itemsBody');
    if (bodyEl) {
        bodyEl.addEventListener('input', function(e) {
            if (!e.target.classList.contains('row-expiry-input')) return;
            var inp = e.target;
            var digits = inp.value.replace(/\D/g, '').substring(0, 8);
            var v = digits;
            if (digits.length > 6)      v = digits.slice(0,4) + '-' + digits.slice(4,6) + '-' + digits.slice(6);
            else if (digits.length > 4) v = digits.slice(0,4) + '-' + digits.slice(4);
            inp.value = v;
        });
    }
})();
</script>

<?php if (!$batch['is_confirmed']): ?>
<!-- 공급처 변경/추가 모달 -->
<div id="supplierModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
            <h3 class="text-base font-semibold text-gray-900"><i class="fas fa-truck text-teal-600 mr-2"></i>Change Supplier</h3>
            <button type="button" onclick="closeSupplierModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
        </div>
        <div class="px-6 py-5 space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Supplier</label>
                <select id="supplierSelect"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                    <option value="">None</option>
                    <?php foreach ($suppliers as $s): ?>
                    <option value="<?php echo $s['id']; ?>" <?php echo $batch['supplier_id'] == $s['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($s['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="pt-3 border-t border-gray-100">
                <button type="button" id="supplierAddToggle" onclick="toggleSupplierAdd()"
                        class="text-sm text-teal-600 hover:text-teal-800 font-medium">
                    <i class="fas fa-plus mr-1"></i>Add New Supplier
                </button>
                <div id="supplierAddRow" class="hidden mt-2 flex gap-2">
                    <input type="text" id="newSupplierName" placeholder="New supplier name" autocomplete="off"
                           class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"
                           onkeydown="if(event.key==='Enter'){event.preventDefault();createSupplier();}">
                    <button type="button" onclick="createSupplier()" id="newSupplierBtn"
                            class="shrink-0 px-4 py-2 bg-teal-600 text-white text-sm font-medium rounded-lg hover:bg-teal-700 transition-colors">
                        Create
                    </button>
                </div>
            </div>

            <div id="supplierModalError" class="hidden text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg px-3 py-2"></div>
        </div>
        <div class="flex gap-3 px-6 py-4 border-t border-gray-100">
            <button type="button" onclick="closeSupplierModal()"
                    class="flex-1 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 transition-colors">
                Cancel
            </button>
            <button type="button" onclick="saveSupplier()" id="supplierSaveBtn"
                    class="flex-1 py-2 bg-teal-600 text-white rounded-lg text-sm font-semibold hover:bg-teal-700 transition-colors">
                Save
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
