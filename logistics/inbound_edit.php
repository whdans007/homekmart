<?php
$page_title = 'Edit Inbound - Logistics Center';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/unit_helper.php'; // Design Ref: box-pcs-unit §4.3

lc_require_staff();

$id      = (int)($_GET['id']   ?? 0);
$back_id = (int)($_GET['back'] ?? 0);
$back_url = $back_id
    ? LC_BASE . '/inbound_detail.php?batch_id=' . $back_id
    : LC_BASE . '/inbound.php';
if (!$id) { header('Location: ' . $back_url); exit; }

$errors = [];

try {
    $conn = get_lc_db();
    $st = $conn->prepare(
        "SELECT i.*, p.unit, p.requires_expiry, p.name_en, p.name_ko,
                p.barcode_unit, p.barcode_box, p.pieces_per_box AS product_ppb,
                inv.storage_location, b.is_confirmed
         FROM lc_inbound i
         JOIN lc_products p ON i.product_id = p.id
         JOIN lc_inbound_batches b ON i.batch_id = b.id
         LEFT JOIN lc_inventory inv ON inv.inbound_id = i.id
         WHERE i.id = ?"
    );
    $st->bind_param('i', $id); $st->execute();
    $form = $st->get_result()->fetch_assoc(); $st->close();
    if (!$form) {
        $conn->close();
        lc_set_flash('error', 'Inbound record not found.');
        header('Location: ' . $back_url); exit;
    }
    if ($form['is_confirmed']) {
        $conn->close();
        lc_set_flash('error', 'This inbound record is locked and cannot be edited.');
        header('Location: ' . $back_url); exit;
    }

    // 출고 여부 확인
    $st = $conn->prepare("SELECT quantity_out FROM lc_inventory WHERE inbound_id = ?");
    $st->bind_param('i', $id); $st->execute();
    $inv = $st->get_result()->fetch_assoc(); $st->close();
    $has_outbound = $inv && $inv['quantity_out'] > 0;

    $products  = $conn->query("SELECT id, name_en, name_ko, unit, pieces_per_box, requires_expiry FROM lc_products WHERE is_active = 1 ORDER BY name_en ASC")->fetch_all(MYSQLI_ASSOC);
    $suppliers = $conn->query("SELECT id, name FROM lc_suppliers ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
    $conn->close();
} catch (Exception $e) {
    lc_set_flash('error', 'DB Error:' . $e->getMessage());
    header('Location: ' . $back_url); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lc_verify_csrf();

    // Inbound date, supplier, and product are not editable here — keep the original values from the loaded record.
    $form['product_id']     = (int)$form['product_id'];
    $form['lot_number']       = trim($_POST['lot_number']       ?? '') ?: null;
    $form['expiry_date']      = trim($_POST['expiry_date']      ?? '') ?: null;
    $form['storage_location'] = trim($_POST['storage_location'] ?? '') ?: null;
    $form['quantity']       = (int)($_POST['quantity'] ?? 0);
    $form['regular_price']  = (float)($_POST['regular_price'] ?? 0);
    $form['discount_rate']  = min(100, max(0, (float)($_POST['discount_rate'] ?? 0)));
    $rate = $form['discount_rate'];
    $form['cost_price']     = $rate > 0
        ? round($form['regular_price'] * (1 - $rate / 100), 2)
        : $form['regular_price'];
    $form['notes']          = trim($_POST['notes'] ?? '');

    // Design Ref: box-pcs-unit §5.4 — 단위 수정 + ppb 스냅샷 + PCS원가 서버 재계산 (FR-13)
    $form['inbound_unit']   = lc_valid_unit($_POST['inbound_unit'] ?? '', LC_UNIT_PCS);
    $ppb_map = array_column($products, 'pieces_per_box', 'id');
    $form['pieces_per_box'] = max(1, (int)($ppb_map[$form['product_id']] ?? 1));
    // Design Ref: pack-unit §4 — 묶음(BOX/PACK) 입고는 낱개원가 환산
    $form['cost_price_pcs'] = lc_is_bundle_unit($form['inbound_unit'])
        ? lc_pcs_cost((float)$form['cost_price'], $form['pieces_per_box'])
        : round((float)$form['cost_price'], 4);

    if (!$form['inbound_date']) $errors[] = 'Please enter the inbound date.';
    if (!$form['product_id'])   $errors[] = 'Please select a product.';
    if ($form['quantity'] <= 0) $errors[] = 'Quantity must be at least 1.';

    // 유통기한 필수 검사
    $expiry_required_map = array_column($products, 'requires_expiry', 'id');
    if (!empty($expiry_required_map[$form['product_id']]) && empty($form['expiry_date'])) {
        $errors[] = 'Expiry date is required for this product.';
    }

    if (empty($errors)) {
        try {
            $conn = get_lc_db();
            $conn->autocommit(false);

            // Design Ref: box-pcs-unit §3.1 — 단위·ppb·PCS원가 함께 갱신
            $st = $conn->prepare(
                "UPDATE lc_inbound SET inbound_date=?, product_id=?, lot_number=?, expiry_date=?,
                 quantity=?, inbound_unit=?, pieces_per_box=?, cost_price=?, cost_price_pcs=?,
                 regular_price=?, discount_rate=?, supplier_id=?, notes=? WHERE id=?"
            );
            $st->bind_param('sissisiddddisi',
                $form['inbound_date'], $form['product_id'], $form['lot_number'],
                $form['expiry_date'], $form['quantity'], $form['inbound_unit'], $form['pieces_per_box'],
                $form['cost_price'], $form['cost_price_pcs'],
                $form['regular_price'], $form['discount_rate'],
                $form['supplier_id'], $form['notes'], $id
            );
            $st->execute(); $st->close();

            // FR-13: lot 단위도 일관 갱신
            $st = $conn->prepare(
                "UPDATE lc_inventory SET product_id=?, unit=?, lot_number=?, expiry_date=?, storage_location=?, quantity_in=?
                 WHERE inbound_id=?"
            );
            $st->bind_param('issssii',
                $form['product_id'], $form['inbound_unit'], $form['lot_number'], $form['expiry_date'],
                $form['storage_location'], $form['quantity'], $id
            );
            $st->execute(); $st->close();

            $conn->commit(); $conn->close();
            lc_set_flash('success', 'Updated successfully.');
            header('Location: ' . $back_url); exit;
        } catch (Exception $e) {
            $conn->rollback(); $conn->close();
            $errors[] = 'DB Error:' . $e->getMessage();
        }
    }
}
?>
<div class="flex items-center gap-3 mb-6">
    <a href="<?php echo $back_url; ?>" class="text-gray-400 hover:text-gray-600"><i class="fas fa-arrow-left"></i></a>
    <h2 class="text-xl font-bold text-gray-900">Edit Inbound</h2>
</div>

<?php if ($has_outbound): ?>
<div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3 mb-4 text-sm text-yellow-800">
    <i class="fas fa-exclamation-triangle mr-1"></i> This record has outbound history. Changing quantity may affect inventory.
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
    <?php foreach ($errors as $e): ?><p class="text-sm text-red-700"><?php echo htmlspecialchars($e); ?></p><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="bg-white rounded-lg border border-gray-200 p-6 max-w-2xl">
    <form method="post" class="space-y-5">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(lc_csrf_token()); ?>">

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Inbound Date</label>
                <div class="w-full border border-gray-200 bg-gray-50 rounded-md px-3 py-2 text-sm text-gray-700">
                    <?php echo htmlspecialchars($form['inbound_date']); ?>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Supplier</label>
                <div class="w-full border border-gray-200 bg-gray-50 rounded-md px-3 py-2 text-sm text-gray-700">
                    <?php
                        $supplier_name = '-';
                        foreach ($suppliers as $s) {
                            if ($s['id'] == $form['supplier_id']) { $supplier_name = $s['name']; break; }
                        }
                        echo htmlspecialchars($supplier_name);
                    ?>
                </div>
            </div>
        </div>
        <p class="text-xs text-gray-400 -mt-2">Inbound date and supplier cannot be changed here. Edit them from the inbound batch detail page.</p>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Product</label>
            <div class="w-full border border-gray-200 bg-gray-50 rounded-md px-3 py-2 text-sm text-gray-700">
                <span class="font-medium"><?php echo htmlspecialchars($form['name_en'] . ($form['name_ko'] ? ' ('.$form['name_ko'].')' : '') . ' ['.$form['unit'].']'); ?></span>
                <span class="ml-2 text-xs text-gray-500"><i class="fas fa-box mr-1"></i>1 BOX = <?php echo (int)$form['product_ppb']; ?> PCS</span>
                <?php if (!empty($form['barcode_unit']) || !empty($form['barcode_box'])): ?>
                <span class="block text-xs text-gray-400 font-mono mt-0.5">
                    <?php if (!empty($form['barcode_unit'])): ?><i class="fas fa-barcode mr-1"></i>PCS <?php echo htmlspecialchars($form['barcode_unit']); ?><?php endif; ?>
                    <?php if (!empty($form['barcode_box'])): ?><span class="ml-2"><i class="fas fa-box mr-1"></i>BOX <?php echo htmlspecialchars($form['barcode_box']); ?></span><?php endif; ?>
                </span>
                <?php endif; ?>
            </div>
            <p class="text-xs text-gray-400 mt-0.5">Product cannot be changed here. Delete and re-register the item to use a different product.</p>
        </div>

        <div class="grid grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Lot Number</label>
                <input type="text" name="lot_number" value="<?php echo htmlspecialchars($form['lot_number'] ?? ''); ?>"
                       class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-teal-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Expiry Date</label>
                <input type="text" name="expiry_date" id="expiryDateInput"
                       value="<?php echo htmlspecialchars($form['expiry_date'] ?? ''); ?>"
                       placeholder="YYYYMMDD" maxlength="10" autocomplete="off"
                       class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-teal-500">
                <span id="expiryReqBadge" class="<?php echo !empty($form['requires_expiry']) ? '' : 'hidden'; ?> text-xs font-semibold text-orange-600 mt-0.5 block">&#9888; Expiry Required</span>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Location</label>
                <input type="text" name="storage_location" value="<?php echo htmlspecialchars($form['storage_location'] ?? ''); ?>"
                       placeholder="e.g. A-01-03"
                       class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-teal-500">
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Quantity <span class="text-red-500">*</span></label>
                <input type="number" name="quantity" value="<?php echo $form['quantity']; ?>" min="1" required
                       class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
            </div>
            <div>
                <!-- Design Ref: box-pcs-unit §5.4 — 입고 단위 (FR-13) -->
                <label class="block text-sm font-medium text-teal-600 mb-1">Inbound Unit</label>
                <?php $cur_unit = $form['inbound_unit'] ?? 'PCS'; ?>
                <select name="inbound_unit" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-teal-500 bg-white">
                    <option value="BOX" <?php echo $cur_unit === 'BOX' ? 'selected' : ''; ?>>BOX</option>
                    <option value="PACK" <?php echo $cur_unit === 'PACK' ? 'selected' : ''; ?>>PACK</option>
                    <option value="PCS" <?php echo $cur_unit === 'PCS' ? 'selected' : ''; ?>>PCS</option>
                </select>
                <p class="text-xs text-gray-400 mt-0.5">If BOX is selected, PCS cost = Final Cost ÷ units per box (auto-calculated)</p>
            </div>
        </div>

        <!-- 원가 3개 필드 -->
        <div class="grid grid-cols-3 gap-3 p-4 bg-gray-50 rounded-lg border border-gray-200">
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Input Cost</label>
                <input type="number" name="regular_price" id="regularPriceInput"
                       value="<?php echo $form['regular_price'] ?? $form['cost_price']; ?>" step="0.01" min="0"
                       oninput="recalcFinalCost()"
                       class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-orange-500 mb-1">Discount Rate (%)</label>
                <input type="number" name="discount_rate" id="discountRateInput"
                       value="<?php echo $form['discount_rate'] ?? 0; ?>" step="0.1" min="0" max="100"
                       oninput="recalcFinalCost()"
                       class="w-full border border-orange-200 rounded-md px-3 py-2 text-sm font-semibold text-center focus:outline-none focus:ring-2 focus:ring-orange-300">
            </div>
            <div>
                <label class="block text-xs font-medium text-teal-600 mb-1">Final Cost <span class="text-gray-400 font-normal">(auto-calculated)</span></label>
                <div id="finalCostDisplay"
                     class="w-full border border-teal-200 bg-teal-50 rounded-md px-3 py-2 text-sm font-semibold text-teal-700">
                    <?php echo number_format($form['cost_price'], 2); ?>
                </div>
                <input type="hidden" name="cost_price" id="costPriceHidden" value="<?php echo $form['cost_price']; ?>">
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
            <input type="text" name="notes" value="<?php echo htmlspecialchars($form['notes'] ?? ''); ?>"
                   class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
        </div>

        <div class="flex gap-3 pt-2">
            <button type="submit" class="px-6 py-2 bg-teal-600 text-white text-sm font-medium rounded-lg hover:bg-teal-700">
                <i class="fas fa-save mr-2"></i>Save
            </button>
            <a href="<?php echo $back_url; ?>" class="px-6 py-2 bg-gray-100 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-200">Cancel</a>
        </div>
    </form>
</div>

<script>
window.recalcFinalCost = function() {
    var regular  = parseFloat(document.getElementById('regularPriceInput').value) || 0;
    var rate     = parseFloat(document.getElementById('discountRateInput').value)  || 0;
    var final    = rate > 0 ? regular * (1 - rate / 100) : regular;
    var display  = document.getElementById('finalCostDisplay');
    var hidden   = document.getElementById('costPriceHidden');
    display.textContent = final > 0 ? final.toFixed(2) : '-';
    if (hidden) hidden.value = final.toFixed(2);
};
recalcFinalCost();

(function() {
    function updateExpiryRequired() {
        var reqExpiry = <?php echo !empty($form['requires_expiry']) ? 1 : 0; ?>;
        var expiryInput = document.getElementById('expiryDateInput');
        var badge = document.getElementById('expiryReqBadge');
        if (reqExpiry) {
            expiryInput.classList.add('border-orange-400', 'bg-orange-50');
            expiryInput.classList.remove('border-gray-300');
            badge.classList.remove('hidden');
        } else {
            expiryInput.classList.remove('border-orange-400', 'bg-orange-50');
            expiryInput.classList.add('border-gray-300');
            badge.classList.add('hidden');
        }
    }
    window.updateExpiryRequired = updateExpiryRequired;
    updateExpiryRequired();

    // 유통기한 자동 포맷: 숫자 연속 입력 → YYYY-MM-DD
    var expiryInput = document.getElementById('expiryDateInput');
    expiryInput.addEventListener('input', function(e) {
        var digits = this.value.replace(/\D/g, '').substring(0, 8);
        var v = digits;
        if (digits.length > 6)      v = digits.slice(0,4) + '-' + digits.slice(4,6) + '-' + digits.slice(6);
        else if (digits.length > 4) v = digits.slice(0,4) + '-' + digits.slice(4);
        this.value = v;
    });
    expiryInput.addEventListener('blur', function() {
        var digits = this.value.replace(/\D/g, '');
        if (digits.length === 8) {
            this.value = digits.slice(0,4) + '-' + digits.slice(4,6) + '-' + digits.slice(6);
        } else if (digits.length > 0 && digits.length < 8) {
            this.classList.add('border-red-400');
        } else {
            this.classList.remove('border-red-400');
        }
    });
    expiryInput.addEventListener('focus', function() {
        this.classList.remove('border-red-400');
    });
})();

document.querySelector('form').addEventListener('keydown', function(e) {
    if (e.key !== 'Enter' || e.target.tagName === 'TEXTAREA') return;
    e.preventDefault();
    const fields = Array.from(this.querySelectorAll('input:not([type=hidden]), select'));
    const idx = fields.indexOf(e.target);
    if (idx >= 0 && idx < fields.length - 1) fields[idx + 1].focus();
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
