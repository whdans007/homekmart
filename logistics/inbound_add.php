<?php
$page_title = 'Register Inbound - Logistics Center';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/unit_helper.php'; // Design Ref: box-pcs-unit §4.3

lc_require_staff();

$errors = [];
$existing_batch_id = (int)($_GET['batch_id'] ?? $_POST['existing_batch_id'] ?? 0);
$form          = ['inbound_date' => date('Y-m-d'), 'supplier_id' => '', 'discount_rate' => 0];
$existing_items = [];   // try 밖에 선언 → 예외 발생해도 항상 정의됨

try {
    $conn      = get_lc_db();
    $products  = $conn->query(
        "SELECT id, name_en, name_ko, unit, capacity, pieces_per_box, requires_expiry, barcode_unit, barcode FROM lc_products WHERE is_active = 1 ORDER BY name_en ASC"
    )->fetch_all(MYSQLI_ASSOC);
    $suppliers  = $conn->query(
        "SELECT id, name FROM lc_suppliers ORDER BY name ASC"
    )->fetch_all(MYSQLI_ASSOC);
    $brands     = $conn->query("SELECT id, name_en, name_ko FROM lc_brands ORDER BY name_en ASC")->fetch_all(MYSQLI_ASSOC);
    $categories = $conn->query("SELECT id, name_en, name_ko FROM lc_categories ORDER BY name_en ASC")->fetch_all(MYSQLI_ASSOC);

    // 기존 배치가 있으면 날짜·공급업체·기존 아이템 미리 채우기
    if ($existing_batch_id) {
        $st = $conn->prepare("SELECT inbound_date, supplier_id, is_confirmed FROM lc_inbound_batches WHERE id = ?");
        $st->bind_param('i', $existing_batch_id);
        $st->execute();
        $existing_batch = $st->get_result()->fetch_assoc();
        $st->close();
        if ($existing_batch && $existing_batch['is_confirmed']) {
            $conn->close();
            lc_set_flash('error', 'This inbound record is locked and items cannot be added.');
            header('Location: ' . LC_BASE . '/inbound_detail.php?batch_id=' . $existing_batch_id); exit;
        }
        if ($existing_batch) {
            $form['inbound_date'] = $existing_batch['inbound_date'];
            $form['supplier_id']  = $existing_batch['supplier_id'] ?? '';

            // regular_price·discount_rate 컬럼은 migration v11 이후 존재
            // 없을 경우 cost_price·0 으로 폴백
            try {
                $st2 = $conn->prepare(
                    "SELECT i.id, p.name_en, p.name_ko, p.unit, IFNULL(p.capacity, '') AS capacity, p.barcode, i.expiry_date,
                            i.quantity,
                            IFNULL(i.regular_price, i.cost_price) AS regular_price,
                            IFNULL(i.discount_rate, 0)            AS discount_rate,
                            i.cost_price,
                            IFNULL(i.inbound_unit, 'PCS')         AS inbound_unit,
                            IFNULL(i.pieces_per_box, 1)           AS pieces_per_box,
                            IFNULL(i.cost_price_pcs, 0)           AS cost_price_pcs
                     FROM lc_inbound i
                     JOIN lc_products p ON i.product_id = p.id
                     WHERE i.batch_id = ?
                     ORDER BY i.id ASC"
                );
                $st2->bind_param('i', $existing_batch_id);
                $st2->execute();
                $existing_items = $st2->get_result()->fetch_all(MYSQLI_ASSOC);
                $st2->close();
            } catch (Exception $e2) {
                // IFNULL도 실패하는 경우(컬럼 부재) → 기본 쿼리
                $st2 = $conn->prepare(
                    "SELECT i.id, p.name_en, p.name_ko, p.unit, '' AS capacity, p.barcode, i.expiry_date,
                            i.quantity, i.cost_price,
                            i.cost_price AS regular_price, 0 AS discount_rate,
                            'PCS' AS inbound_unit, 1 AS pieces_per_box, 0 AS cost_price_pcs
                     FROM lc_inbound i
                     JOIN lc_products p ON i.product_id = p.id
                     WHERE i.batch_id = ?
                     ORDER BY i.id ASC"
                );
                $st2->bind_param('i', $existing_batch_id);
                $st2->execute();
                $existing_items = $st2->get_result()->fetch_all(MYSQLI_ASSOC);
                $st2->close();
            }
        } else {
            $existing_batch_id = 0;
        }
    }

    $conn->close();
} catch (Exception $e) { $products = []; $suppliers = []; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lc_verify_csrf();

    $form['inbound_date']   = trim($_POST['inbound_date'] ?? date('Y-m-d'));
    $form['supplier_id']    = (int)($_POST['supplier_id'] ?? 0) ?: null;
    $form['discount_rate']  = min(100, max(0, (float)($_POST['discount_rate'] ?? 0)));

    $product_ids    = $_POST['product_id']     ?? [];
    $lot_numbers        = $_POST['lot_number']        ?? [];
    $expiry_dates       = $_POST['expiry_date']       ?? [];
    $storage_locations  = $_POST['storage_location']  ?? [];
    $quantities         = $_POST['quantity']          ?? [];
    $units              = $_POST['unit']              ?? []; // Design Ref: box-pcs-unit §5.4 — 행별 입고 단위
    $cost_prices        = $_POST['cost_price']        ?? [];
    $regular_prices     = $_POST['regular_price']     ?? [];
    $item_notes         = $_POST['item_notes']        ?? [];
    $pieces_per_box_inputs = $_POST['pieces_per_box'] ?? []; // Design Ref: inbound-ppb-override §3 — 행별 ppb 오버라이드

    if (!$form['inbound_date']) $errors[] = 'Please enter the inbound date.';

    // Supplier 필수 + DB 일치 검증
    if (empty($form['supplier_id'])) {
        $errors[] = 'Please select a supplier.';
    } else {
        $valid_supplier_ids = array_map('intval', array_column($suppliers, 'id'));
        if (!in_array($form['supplier_id'], $valid_supplier_ids, true)) {
            $errors[] = 'Selected supplier is invalid. Please select a supplier from the list.';
            $form['supplier_id'] = null;
        }
    }

    // 상품별 ppb 맵 (서버측 PCS원가 재계산용 — 클라이언트 값 신뢰하지 않음)
    $ppb_map = array_column($products, 'pieces_per_box', 'id');

    // 누락/오류 필드 위치 — 복원된 행에서 해당 입력란으로 포커스 이동용 (key: product_id[] 인덱스)
    $error_fields = [];

    $valid_items = [];
    foreach ($product_ids as $i => $pid) {
        $pid = (int)$pid;
        $qty = (int)($quantities[$i] ?? 0);
        if (!$pid) continue;
        if ($qty <= 0) {
            $errors[] = "Check the quantity for item #" . ($i + 1) . ".";
            $error_fields[$i] = 'quantity';
            continue;
        }
        $final_price   = (float)($cost_prices[$i]    ?? 0);
        $regular_price = (float)($regular_prices[$i] ?? $final_price);

        // Plan SC-1/SC-2: 단위 검증 + ppb 스냅샷 + PCS원가 서버 계산
        $unit = lc_valid_unit($units[$i] ?? '', LC_UNIT_PCS);
        // Design Ref: inbound-ppb-override §3.1 — 행별 ppb 오버라이드, 마스터값은 폴백
        $ppb  = lc_resolve_row_ppb($pieces_per_box_inputs[$i] ?? null, $ppb_map[$pid] ?? 1);
        // Design Ref: pack-unit §4 — 묶음(BOX/PACK) 입고는 낱개원가 환산, PCS는 그대로
        $cost_price_pcs = lc_is_bundle_unit($unit) ? lc_pcs_cost($final_price, $ppb) : round($final_price, 4);

        $valid_items[] = [
            '_idx'          => $i, // product_id[] 원본 인덱스 (오류 필드 매핑용)
            'product_id'    => $pid,
            'lot_number'       => trim($lot_numbers[$i]       ?? '') ?: null,
            'expiry_date'      => trim($expiry_dates[$i]      ?? '') ?: null,
            'storage_location' => trim($storage_locations[$i] ?? '') ?: null,
            'quantity'      => $qty,
            'unit'          => $unit,
            'pieces_per_box'=> $ppb,
            'cost_price'    => $final_price,
            'cost_price_pcs'=> $cost_price_pcs,
            'regular_price' => $regular_price,
            'discount_rate' => $form['discount_rate'],
            'notes'         => trim($item_notes[$i]   ?? ''),
        ];
    }

    if (empty($valid_items) && empty($errors)) $errors[] = 'Please register at least 1 product.';

    // 유통기한 필수 상품 검사
    $expiry_required_map = array_column($products, 'requires_expiry', 'id');
    foreach ($valid_items as $i => $item) {
        if (!empty($expiry_required_map[$item['product_id']]) && empty($item['expiry_date'])) {
            $errors[] = "Item #" . ($i + 1) . ": Expiry date is required for this product.";
            $error_fields[$item['_idx']] = 'expiry_date';
        }
    }

    if (empty($errors)) {
        try {
            $conn = get_lc_db();
            $conn->autocommit(false);
            $uid = lc_current_user_id();

            if ($existing_batch_id) {
                // 기존 배치에 추가 — 배치 생성 없이 ID 재사용
                $lock_check = $conn->prepare("SELECT is_confirmed FROM lc_inbound_batches WHERE id = ?");
                $lock_check->bind_param('i', $existing_batch_id);
                $lock_check->execute();
                $lock_row = $lock_check->get_result()->fetch_assoc();
                $lock_check->close();
                if ($lock_row && $lock_row['is_confirmed']) {
                    $conn->rollback(); $conn->close();
                    lc_set_flash('error', 'This inbound record is locked and items cannot be added.');
                    header('Location: ' . LC_BASE . '/inbound_detail.php?batch_id=' . $existing_batch_id); exit;
                }
                $batch_id = $existing_batch_id;
            } else {
                // 새 배치 생성
                $st = $conn->prepare(
                    "INSERT INTO lc_inbound_batches (inbound_date, supplier_id, created_by) VALUES (?,?,?)"
                );
                $st->bind_param('sii', $form['inbound_date'], $form['supplier_id'], $uid);
                $st->execute();
                $batch_id = $conn->insert_id;
                $st->close();
            }

            foreach ($valid_items as $item) {
                // Design Ref: box-pcs-unit §3.1 — 단위·ppb 스냅샷·PCS원가 기록
                $st = $conn->prepare(
                    "INSERT INTO lc_inbound
                     (batch_id, inbound_date, product_id, lot_number, expiry_date, quantity, inbound_unit, pieces_per_box, cost_price, cost_price_pcs, regular_price, discount_rate, supplier_id, notes, created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                );
                $st->bind_param('isissisiddddisi',
                    $batch_id, $form['inbound_date'], $item['product_id'], $item['lot_number'],
                    $item['expiry_date'], $item['quantity'], $item['unit'], $item['pieces_per_box'],
                    $item['cost_price'], $item['cost_price_pcs'],
                    $item['regular_price'], $item['discount_rate'],
                    $form['supplier_id'], $item['notes'], $uid
                );
                $st->execute();
                $inbound_id = $conn->insert_id;
                $st->close();

                // Plan SC-1/SC-2: lot에 단위 기록 — BOX lot / PCS lot 분리 저장
                $st2 = $conn->prepare(
                    "INSERT INTO lc_inventory
                     (inbound_id, product_id, unit, lot_number, expiry_date, storage_location, quantity_in, quantity_out)
                     VALUES (?,?,?,?,?,?,?,0)"
                );
                $st2->bind_param('iissssi',
                    $inbound_id, $item['product_id'], $item['unit'], $item['lot_number'],
                    $item['expiry_date'], $item['storage_location'], $item['quantity']
                );
                $st2->execute();
                $st2->close();
            }

            $conn->commit();
            $conn->close();

            lc_set_flash('success', count($valid_items) . ' inbound record(s) registered.');
            header('Location: ' . LC_BASE . '/inbound_detail.php?batch_id=' . $batch_id);
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $conn->close();
            $errors[] = 'DB Error:' . $e->getMessage();
        }
    }
}

// 상품 목록 JSON (바코드 조회 결과에서 select 옵션 설정용)
$products_map = [];
foreach ($products as $p) {
    $products_map[$p['id']] = $p;
}
?>

<?php
$back_url   = $existing_batch_id
    ? LC_BASE . '/inbound_detail.php?batch_id=' . $existing_batch_id
    : LC_BASE . '/inbound.php';
$page_label = $existing_batch_id ? 'Add Items to Inbound #' . $existing_batch_id : 'Register Inbound';
?>
<style>
#itemsBody input[type=number]::-webkit-outer-spin-button,
#itemsBody input[type=number]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
#itemsBody input[type=number] { -moz-appearance: textfield; appearance: textfield; }
#itemsTable th, #itemsTable td { padding-left: 2px; padding-right: 2px; }
#itemsTable input, #itemsTable select { padding-left: 4px; padding-right: 4px; }
</style>
<!-- 헤더 -->
<div class="flex items-center justify-between mb-5">
    <div class="flex items-center gap-3">
        <a href="<?php echo $back_url; ?>" class="text-gray-400 hover:text-gray-600"><i class="fas fa-arrow-left"></i></a>
        <h2 class="text-lg font-bold text-gray-900"><?php echo htmlspecialchars($page_label); ?></h2>
    </div>
    <div class="flex gap-2">
        <button type="submit" form="inboundForm" class="px-5 py-2 bg-teal-600 text-white text-sm font-medium rounded-lg hover:bg-teal-700 transition-colors">
            <i class="fas fa-save mr-1.5"></i>Register
        </button>
        <a href="<?php echo $back_url; ?>" class="px-5 py-2 bg-gray-100 text-gray-600 text-sm font-medium rounded-lg hover:bg-gray-200">Cancel</a>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="bg-red-50 border border-red-200 rounded-lg px-4 py-3 mb-4">
    <?php foreach ($errors as $err): ?>
    <p class="text-sm text-red-700"><?php echo htmlspecialchars($err); ?></p>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<form method="post" id="inboundForm">
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(lc_csrf_token()); ?>">
<?php if ($existing_batch_id): ?>
<input type="hidden" name="existing_batch_id" value="<?php echo $existing_batch_id; ?>">
<?php endif; ?>

<!-- discount_rate 히든 필드 (JS가 채움) -->
<input type="hidden" name="discount_rate" id="discountRateHidden" value="<?php echo $form['discount_rate']; ?>">

<!-- 공급업체 + 날인율 + 날짜 -->
<div class="flex gap-4 mb-4">
    <div class="flex-1 relative" id="supplierWrapper">
        <label class="block text-xs font-medium text-gray-500 mb-1">Supplier</label>
        <input type="hidden" name="supplier_id" id="supplierIdInput" value="<?php echo (int)$form['supplier_id']; ?>">
        <input type="text" id="supplierSearch" autocomplete="off" placeholder="Search supplier..."
               value="<?php
                   if ($form['supplier_id']) {
                       foreach ($suppliers as $s) {
                           if ($s['id'] == $form['supplier_id']) { echo htmlspecialchars($s['name']); break; }
                       }
                   }
               ?>"
               class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500 bg-white">
        <div id="supplierPanel" class="hidden absolute z-30 top-full left-0 right-0 mt-1"></div>
    </div>
    <div class="w-36">
        <label class="block text-xs font-medium text-gray-500 mb-1">Discount Rate (%)</label>
        <div class="flex items-center gap-1">
            <input type="number" id="discountRate" min="0" max="100" step="0.1"
                   value="<?php echo $form['discount_rate']; ?>" placeholder="0"
                   class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-400 text-center font-semibold">
            <span class="text-gray-400 text-sm font-medium">%</span>
        </div>
        <p id="discountHint" class="text-xs text-orange-500 mt-0.5 <?php echo $form['discount_rate'] > 0 ? '' : 'hidden'; ?>">
            <?php echo $form['discount_rate']; ?>% discount applied
        </p>
    </div>
    <div class="w-40">
        <label class="block text-xs font-medium text-gray-500 mb-1">Inbound Date <span class="text-red-500">*</span></label>
        <input type="date" name="inbound_date" value="<?php echo $form['inbound_date']; ?>" required
               class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
    </div>
</div>

<!-- 상품 검색 -->
<div class="mb-4">
    <div class="flex gap-2">
        <input type="text" id="barcodeInput"
               placeholder="Scan barcode or enter product name (Korean/English)..."
               autocomplete="off"
               class="flex-1 border-2 border-gray-300 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-teal-500 focus:ring-2 focus:ring-teal-100 transition-colors">
        <button type="button" onclick="searchBarcode()"
                class="px-5 py-2.5 bg-teal-600 text-white text-sm font-semibold rounded-lg hover:bg-teal-700 active:bg-teal-800 transition-colors whitespace-nowrap shadow-sm">
            <i class="fas fa-search mr-1.5"></i>Search
        </button>
        <button type="button" onclick="openCameraScanner()"
                class="px-4 py-2.5 bg-indigo-600 text-white text-sm font-semibold rounded-lg hover:bg-indigo-700 active:bg-indigo-800 transition-colors whitespace-nowrap shadow-sm"
                title="Scan barcode with camera">
            <i class="fas fa-camera"></i>
        </button>
    </div>
    <div id="barcodeStatus" class="mt-2 hidden"></div>
    <div id="barcodeMulti" class="mt-2 hidden"></div>
</div>

<!-- 입고 상품 목록 -->
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-4">
    <div class="px-4 py-2.5 border-b border-gray-100">
        <span class="text-sm font-medium text-gray-700">
            Inbound Product List
            <?php if (!empty($existing_items)): ?>
            <span class="ml-2 text-xs text-gray-400 font-normal"><?php echo count($existing_items); ?> existing included</span>
            <?php endif; ?>
        </span>
    </div>
    <div class="overflow-x-auto">
        <table id="itemsTable" class="w-full text-sm" style="min-width:960px">
            <thead class="bg-gray-50 border-b border-gray-100">
                <tr>
                    <th class="px-3 py-2 text-left text-xs text-gray-500 font-medium" style="width:2rem;min-width:2rem">#</th>
                    <th class="px-3 py-2 text-left text-xs text-gray-500 font-medium" style="width:15rem">Product</th>
                    <th class="px-3 py-2 text-left text-xs text-gray-500 font-medium w-20">Capacity</th>
                    <th class="px-3 py-2 text-center text-xs text-gray-500 font-medium w-16">Unit</th>
                    <th class="px-3 py-2 text-left text-xs text-gray-500 font-medium w-16">PKG</th>
                    <th class="px-3 py-2 text-left text-xs text-gray-500 font-medium" style="width:8rem;min-width:8rem">Expiry Date</th>
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
            <tbody id="itemsBody">
                <?php foreach ($existing_items as $ei => $item): ?>
                <?php
                    $ei_regular = (float)($item['regular_price'] ?? $item['cost_price']);
                    $ei_drate   = (float)($item['discount_rate'] ?? 0);
                    $ei_unit    = $item['inbound_unit'] ?? 'PCS';
                    $ei_ppb     = max(1, (int)($item['pieces_per_box'] ?? 1));
                    $ei_qty     = (int)$item['quantity'];
                    // 수량은 매입 단위 쪽만 표시 (반대 단위 환산 표시 안 함 — 혼동 방지)
                    // Design Ref: pack-unit §4 — 묶음(BOX/PACK)은 bundle 컬럼, PCS는 낱개 컬럼
                    $ei_is_bundle = lc_is_bundle_unit($ei_unit);
                    $ei_qty_box = $ei_is_bundle ? $ei_qty : 0;
                    $ei_qty_pcs = $ei_unit === 'PCS' ? $ei_qty : 0;
                    $ei_price_pcs = $ei_unit === 'PCS' ? $ei_regular : ($ei_ppb > 0 ? round($ei_regular / $ei_ppb, 2) : $ei_regular);
                    $ei_price_box = $ei_is_bundle ? $ei_regular : round($ei_regular * $ei_ppb, 2);
                    // 단위별 원가: cost_price는 묶음 lot이면 묶음단가, PCS lot이면 낱개단가 (unit_helper.php §3.2)
                    // 묶음 lot의 cost_price를 낱개단가로 오인하면 COST/Total이 ppb배 부풀려지므로 단위 분기 필수
                    $ei_cost_pcs = $ei_is_bundle
                        ? ((float)($item['cost_price_pcs'] ?? 0) > 0 ? (float)$item['cost_price_pcs'] : ($ei_ppb > 0 ? $item['cost_price'] / $ei_ppb : (float)$item['cost_price']))
                        : (float)$item['cost_price'];
                    $ei_cost_box = $ei_is_bundle ? (float)$item['cost_price'] : round($ei_cost_pcs * $ei_ppb, 2);
                    // 행 합계: 매입 단위 기준 (묶음=묶음수량×묶음원가, PCS=낱개수량×낱개원가)
                    $ei_row_total = $ei_is_bundle ? ($ei_cost_box * $ei_qty) : ($ei_cost_pcs * $ei_qty);
                ?>
                <tr class="existing-row border-b border-gray-100 bg-gray-50">
                    <td class="px-3 py-2 text-xs text-gray-400"><?php echo $ei + 1; ?></td>
                    <td class="px-3 py-2 text-gray-700" style="width:15rem;max-width:15rem">
                        <div class="truncate text-xs font-medium text-gray-700"><?php echo htmlspecialchars($item['name_en']); ?> <span class="text-gray-400">[<?php echo htmlspecialchars($item['unit']); ?>]</span></div>
                        <div class="truncate text-xs text-gray-400"><?php echo $item['name_ko'] ? htmlspecialchars($item['name_ko']) : '-'; ?></div>
                        <div class="truncate text-xs text-gray-400 font-mono"><?php echo !empty($item['barcode']) ? htmlspecialchars($item['barcode']) : '-'; ?></div>
                    </td>
                    <td class="px-3 py-2 text-xs text-gray-500"><?php echo htmlspecialchars($item['capacity'] ?? '-'); ?></td>
                    <td class="px-3 py-2 text-center text-xs font-semibold <?php echo $ei_unit === 'BOX' ? 'text-amber-600' : ($ei_unit === 'PACK' ? 'text-emerald-600' : 'text-blue-600'); ?>"><?php echo htmlspecialchars($ei_unit); ?></td>
                    <td class="px-3 py-2 text-center text-xs text-gray-500"><?php echo $ei_ppb; ?></td>
                    <td class="px-3 py-2 text-xs text-gray-500 whitespace-nowrap" style="min-width:8rem"><?php echo $item['expiry_date'] ? date('Y-m-d', strtotime($item['expiry_date'])) : '-'; ?></td>
                    <td class="px-3 py-2 text-left font-semibold text-gray-700"><?php echo $ei_qty_pcs > 0 ? number_format($ei_qty_pcs) : '-'; ?></td>
                    <td class="px-3 py-2 text-left font-semibold text-gray-700"><?php echo $ei_qty_box > 0 ? number_format($ei_qty_box) : '-'; ?></td>
                    <td class="px-3 py-2 text-left text-gray-500 text-xs"><?php echo $ei_price_pcs > 0 ? number_format($ei_price_pcs, 2) : '-'; ?></td>
                    <td class="px-3 py-2 text-left text-gray-500 text-xs"><?php echo $ei_price_box > 0 ? number_format($ei_price_box, 2) : '-'; ?></td>
                    <td class="px-3 py-2 text-xs text-gray-500"><?php echo htmlspecialchars($item['storage_location'] ?? '-'); ?></td>
                    <td class="px-3 py-2 text-center">
                        <?php if ($ei_drate > 0): ?>
                        <span class="inline-block px-1.5 py-0.5 bg-orange-100 text-orange-600 text-xs font-semibold rounded">-<?php echo rtrim(rtrim(number_format($ei_drate, 2),'0'),'.'); ?>%</span>
                        <?php else: ?><span class="text-gray-300 text-xs">-</span><?php endif; ?>
                    </td>
                    <td class="px-3 py-2 text-left font-semibold text-teal-700">
                        <?php echo number_format($ei_cost_pcs, 2); ?>
                    </td>
                    <td class="px-3 py-2 text-left font-semibold text-teal-700">
                        <?php echo number_format($ei_cost_box, 2); ?>
                    </td>
                    <td class="px-3 py-2 text-left font-bold text-teal-800">
                        <?php echo $ei_row_total > 0 ? number_format($ei_row_total, 2) : '-'; ?>
                    </td>
                    <td class="px-3 py-2"></td>
                </tr>
                <?php endforeach; ?>
                <?php
                // 검증 오류 시 입력값 복원 (Register 실패해도 작성 중이던 행이 사라지지 않도록)
                $has_restored = false;
                if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($errors)):
                    foreach (($product_ids ?? []) as $ri => $rpid):
                        $rpid = (int)$rpid;
                        if (!$rpid) continue;
                        $rp = $products_map[$rpid] ?? null;
                        if (!$rp) continue;
                        $has_restored = true;
                        $r_unit  = lc_valid_unit($units[$ri] ?? '', LC_UNIT_PCS);
                        $r_qty   = (int)($quantities[$ri] ?? 0);
                        $r_exp   = $expiry_dates[$ri] ?? '';
                        $r_loc   = $storage_locations[$ri] ?? '';
                        $r_cost  = (float)($regular_prices[$ri] ?? ($cost_prices[$ri] ?? 0));
                        $r_notes = $item_notes[$ri] ?? '';
                        $r_ppb   = lc_resolve_row_ppb($pieces_per_box_inputs[$ri] ?? null, $rp['pieces_per_box'] ?? 1);
                        $r_req_expiry = !empty($rp['requires_expiry']) ? 1 : 0;
                        $r_is_bundle = lc_is_bundle_unit($r_unit); // Design Ref: pack-unit §4 — BOX/PACK 공통 bundle 처리
                        $r_qty_box   = $r_is_bundle ? $r_qty : 0;
                        $r_qty_pcs   = $r_is_bundle ? ($r_qty * $r_ppb) : $r_qty;
                        $r_price_pcs = $r_unit === 'PCS' ? $r_cost : ($r_ppb > 0 ? round($r_cost / $r_ppb, 2) : $r_cost);
                        $r_price_box = $r_is_bundle ? $r_cost : round($r_cost * $r_ppb, 2);
                ?>
                <tr class="item-row restored-row border-b border-gray-50" data-error-field="<?php echo htmlspecialchars($error_fields[$ri] ?? ''); ?>">
                    <td class="px-3 py-2 text-xs text-gray-400 row-num"></td>
                    <td class="px-3 py-2" style="width:15rem;max-width:15rem">
                        <input type="hidden" name="product_id[]" class="product-id-hidden" value="<?php echo $rpid; ?>" data-req-expiry="<?php echo $r_req_expiry; ?>">
                        <div class="truncate text-xs font-medium text-gray-700"><span class="product-name-en"><?php echo htmlspecialchars($rp['name_en']); ?></span> <span class="product-name-unit text-gray-400">[<?php echo htmlspecialchars($rp['unit']); ?>]</span></div>
                        <div class="truncate text-xs text-gray-400 product-name-ko"><?php echo $rp['name_ko'] ? htmlspecialchars($rp['name_ko']) : '-'; ?></div>
                        <div class="truncate text-xs text-gray-400 font-mono product-name-barcode"><?php echo !empty($rp['barcode']) ? htmlspecialchars($rp['barcode']) : '-'; ?></div>
                    </td>
                    <td class="px-3 py-2">
                        <span class="row-capacity text-xs text-gray-500"><?php echo htmlspecialchars($rp['capacity'] ?? '-'); ?></span>
                    </td>
                    <td class="px-3 py-2">
                        <select class="row-unit-select w-full border border-gray-200 rounded px-1 py-1.5 text-xs text-center focus:outline-none focus:ring-1 focus:ring-teal-500" onchange="onRowUnitSelectChange(this)">
                            <option value="BOX" <?php echo $r_unit === 'BOX' ? 'selected' : ''; ?>>BOX</option>
                            <option value="PACK" <?php echo $r_unit === 'PACK' ? 'selected' : ''; ?>>PACK</option>
                            <option value="PCS" <?php echo $r_unit === 'PCS' ? 'selected' : ''; ?>>PCS</option>
                        </select>
                    </td>
                    <td class="px-3 py-2">
                        <input type="number" name="pieces_per_box[]" class="row-ppb-input w-full border border-gray-200 rounded px-2 py-1.5 text-sm text-center focus:outline-none focus:ring-1 focus:ring-teal-500"
                               min="1" step="1" value="<?php echo $r_ppb; ?>" oninput="onRowPpbInput(this)">
                    </td>
                    <td class="px-3 py-2" style="min-width:8rem">
                        <input type="text" name="expiry_date[]" placeholder="YYYYMMDD" maxlength="10" autocomplete="off" value="<?php echo htmlspecialchars($r_exp); ?>" class="w-full border border-gray-200 rounded px-2 py-1.5 text-sm font-mono focus:outline-none focus:ring-1 focus:ring-teal-500">
                        <span class="expiry-req-badge hidden text-xs font-semibold text-orange-600 mt-0.5 block">&#9888; Expiry Required</span>
                    </td>
                    <td class="px-3 py-2">
                        <input type="number" placeholder="0" value="<?php echo $r_qty_pcs > 0 ? $r_qty_pcs : ''; ?>"
                               class="row-qty-pcs w-full border border-gray-200 rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-teal-500"
                               oninput="onRowQtyPcs(this)">
                        <span class="row-qty-pcs-dash hidden text-sm text-gray-300">-</span>
                    </td>
                    <td class="px-3 py-2">
                        <span class="row-qty-box-label text-[10px] font-semibold text-gray-400 block mb-0.5"><?php echo $r_unit === 'PACK' ? 'PACK' : 'BOX'; ?></span>
                        <input type="number" placeholder="0" value="<?php echo $r_qty_box > 0 ? $r_qty_box : ''; ?>"
                               class="row-qty-box w-full border border-gray-200 rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-teal-500"
                               oninput="onRowQtyBox(this)">
                        <span class="row-qty-box-dash hidden text-sm text-gray-300">-</span>
                    </td>
                    <td class="px-3 py-2">
                        <input type="number" step="0.01" min="0" placeholder="0.00" value="<?php echo $r_price_pcs > 0 ? htmlspecialchars((string)round($r_price_pcs, 2)) : ''; ?>"
                               class="row-price-pcs w-full border border-gray-200 rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-teal-500"
                               oninput="onRowPricePcs(this)">
                    </td>
                    <td class="px-3 py-2">
                        <input type="number" step="0.01" min="0" placeholder="0.00" value="<?php echo $r_price_box > 0 ? htmlspecialchars((string)round($r_price_box, 2)) : ''; ?>"
                               class="row-price-box w-full border border-gray-200 rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-teal-500"
                               oninput="onRowPriceBox(this)">
                    </td>
                    <td class="px-3 py-2"><input type="text" name="storage_location[]" placeholder="e.g. A-01-03" value="<?php echo htmlspecialchars($r_loc); ?>" class="w-full border border-gray-200 rounded px-2 py-1.5 text-sm font-mono focus:outline-none focus:ring-1 focus:ring-teal-500"></td>
                    <!-- hidden fields for form submission -->
                    <input type="hidden" name="quantity[]">
                    <input type="hidden" name="unit[]" value="<?php echo htmlspecialchars($r_unit); ?>">
                    <input type="hidden" name="lot_number[]" value="">
                    <input type="hidden" name="cost_price[]">
                    <input type="hidden" name="regular_price[]" class="regular-price-hidden">
                    <td class="px-3 py-2 text-center"><span class="row-discount-rate text-xs font-semibold text-orange-500">-</span></td>
                    <td class="px-3 py-2">
                        <span class="row-final-cost text-sm font-semibold text-teal-700">-</span>
                    </td>
                    <td class="px-3 py-2">
                        <span class="row-final-cost-box text-sm font-semibold text-teal-700">-</span>
                    </td>
                    <td class="px-3 py-2">
                        <span class="row-total text-sm font-bold text-teal-800">-</span>
                    </td>
                    <td class="px-3 py-2 text-center"><button type="button" onclick="removeRow(this)" class="text-gray-300 hover:text-red-400 transition-colors"><i class="fas fa-times text-xs"></i></button></td>
                </tr>
                <?php
                    endforeach;
                endif;
                ?>
                <?php if (empty($existing_items) && !$has_restored): ?>
                <tr id="emptyRow">
                    <td colspan="16" class="px-4 py-6 text-center text-sm text-gray-400">Scan barcode or click Add Row to register a product.</td>
                </tr>
                <?php else: ?>
                <tr id="emptyRow" style="display:none">
                    <td colspan="16" class="px-4 py-6 text-center text-sm text-gray-400">Scan barcode or click Add Row to register a product.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</form>

<!-- 바코드 미등록 확인 모달 -->
<div id="inboundConfirmModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-sm mx-4 p-6">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-10 h-10 bg-amber-100 rounded-full flex items-center justify-center shrink-0">
                <i class="fas fa-barcode text-amber-500 text-lg"></i>
            </div>
            <h3 class="text-sm font-semibold text-gray-900">No registered product found</h3>
        </div>
        <p class="text-xs text-gray-500 mb-1">Scanned barcode:</p>
        <p class="font-mono font-semibold text-gray-800 text-sm mb-4" id="inboundConfirmBarcode"></p>
        <p class="text-sm text-gray-700 mb-5">Would you like to register it as a new product?</p>
        <div class="flex gap-2">
            <button type="button" onclick="openInboundRegisterModal()"
                    class="flex-1 px-4 py-2 bg-teal-600 text-white text-sm font-medium rounded-lg hover:bg-teal-700 transition-colors">
                <i class="fas fa-plus mr-1.5"></i>Yes
            </button>
            <button type="button" onclick="closeInboundConfirmModal()"
                    class="px-4 py-2 bg-gray-100 text-gray-600 text-sm font-medium rounded-lg hover:bg-gray-200 transition-colors">
                No
            </button>
        </div>
    </div>
</div>

<!-- 신규 상품 등록 모달 -->
<div id="inboundRegisterModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50">
    <div class="relative bg-white rounded-xl shadow-2xl w-full mx-4 flex flex-col" style="max-height:90vh; max-width:63rem;">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 shrink-0">
            <h3 class="text-base font-semibold text-gray-900"><i class="fas fa-plus-circle text-teal-600 mr-2"></i>Register Product</h3>
            <button type="button" onclick="closeInboundRegisterModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
        </div>
        <div class="overflow-y-auto px-6 py-5 flex-1">
            <div id="inboundRegModalError" class="hidden bg-red-50 border border-red-200 rounded-lg p-3 mb-4 text-sm text-red-700"></div>
            <form id="inbRegModalForm" class="space-y-5">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(lc_csrf_token()); ?>">

                <div class="border border-teal-200 bg-teal-50 rounded-lg p-4">
                    <h4 class="text-sm font-semibold text-teal-800 mb-1"><i class="fas fa-magic mr-1"></i>Import from Existing Product</h4>
                    <p class="text-xs text-teal-700 mb-2">Search by barcode or product name to auto-fill the Korean/English name and units per box.</p>
                    <div class="relative">
                        <input type="text" id="inbRegShopProductSearch" autocomplete="off" placeholder="Search barcode or product name..."
                               class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                        <div id="inbRegShopProductDropdown" class="hidden absolute z-10 top-full left-0 right-0 mt-0.5 bg-white border border-gray-200 rounded-md shadow-lg max-h-56 overflow-y-auto">
                            <ul id="inbRegShopProductList" class="py-1"></ul>
                        </div>
                    </div>
                </div>

                <div class="border-b border-gray-100 pb-5">
                    <h4 class="text-sm font-semibold text-gray-700 mb-3">Product Name</h4>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">English Name <span class="text-red-500">*</span></label>
                            <input type="text" name="name_en" id="inbReg_name_en" autocomplete="off" placeholder="E.g.: Shin Ramyun, Choco Pie"
                                   class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Korean Name <span class="text-xs text-gray-400 font-normal">(Korean products only)</span></label>
                            <div class="flex gap-2">
                                <input type="text" name="name_ko" id="inbReg_name_ko" autocomplete="off" placeholder="E.g.: Shin Ramyun, Choco Pie"
                                       class="flex-1 border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                                <button type="button" onclick="inbRegRomanize()" id="inbRegRomanizeBtn" title="한글을 영문 발음(로마자)으로 변환"
                                        class="px-3 py-2 text-xs font-semibold rounded-md whitespace-nowrap transition-colors"
                                        style="background:#f3e8ff;color:#7e22ce;">발음 ▶ English</button>
                                <button type="button" onclick="inbRegTranslateToEn()" id="inbRegTransToEnBtn"
                                        class="px-3 py-2 bg-blue-500 text-white text-xs font-semibold rounded-md hover:bg-blue-600 whitespace-nowrap transition-colors">Translate ▶ English</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="border-b border-gray-100 pb-5">
                    <div class="gap-4" style="display:grid; grid-template-columns:1fr 2fr 2fr; gap:1rem;">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Capacity</label>
                            <input type="text" name="capacity" id="inbReg_capacity" autocomplete="off" placeholder="E.g.: 500ml, 1kg, 20ea"
                                   class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Brand</label>
                            <div class="flex gap-2">
                                <div class="relative flex-1">
                                    <input type="hidden" name="brand_id" id="inbReg_brand_id">
                                    <input type="text" id="inbRegBrandSearch" autocomplete="off" placeholder="Search brand..."
                                           class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                                    <div id="inbRegBrandDropdown" class="hidden absolute top-full left-0 right-0 mt-0.5 bg-white border border-gray-200 rounded-md shadow-lg max-h-48 overflow-y-auto" style="z-index:70">
                                        <ul id="inbRegBrandList" class="py-1"></ul>
                                    </div>
                                </div>
                                <button type="button" onclick="openInbRegQuick('brand')" title="Add New Brand"
                                        class="shrink-0 px-3 py-2 bg-teal-50 border border-teal-300 text-teal-700 rounded-md hover:bg-teal-100 transition-colors text-sm"><i class="fas fa-plus"></i></button>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                            <div class="flex gap-2">
                                <div class="relative flex-1">
                                    <input type="hidden" name="category_id" id="inbReg_category_id">
                                    <input type="text" id="inbRegCatSearch" autocomplete="off" placeholder="Search category..."
                                           class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                                    <div id="inbRegCatDropdown" class="hidden absolute top-full left-0 right-0 mt-0.5 bg-white border border-gray-200 rounded-md shadow-lg max-h-48 overflow-y-auto" style="z-index:70">
                                        <ul id="inbRegCatList" class="py-1"></ul>
                                    </div>
                                </div>
                                <button type="button" onclick="openInbRegQuick('category')" title="Add New Category"
                                        class="shrink-0 px-3 py-2 bg-teal-50 border border-teal-300 text-teal-700 rounded-md hover:bg-teal-100 transition-colors text-sm"><i class="fas fa-plus"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="border-b border-gray-100 pb-5">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Unit</label>
                            <select name="unit" id="inbReg_unit"
                                    class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                                <option value="BOX">BOX</option>
                                <option value="PCS">PCS</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Units per Box(PKG)</label>
                            <input type="number" name="pieces_per_box" id="inbReg_pieces_per_box" value="1" min="1"
                                   class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                        </div>
                    </div>
                </div>

                <div class="border-b border-gray-100 pb-5">
                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-barcode text-gray-400 mr-1"></i>Barcode</label>
                            <input type="text" name="barcode_unit" id="inbReg_barcode_unit" autocomplete="off" placeholder="Product Barcode"
                                   class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-teal-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-box text-gray-400 mr-1"></i>Box Code</label>
                            <input type="text" name="barcode_box" id="inbReg_barcode_box" autocomplete="off" placeholder="Box Unit Barcode"
                                   class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-teal-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-warehouse text-gray-400 mr-1"></i>Logistics Code</label>
                            <input type="text" name="barcode_logistics" id="inbReg_barcode_logistics" autocomplete="off" placeholder="Logistics Center Code"
                                   class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-teal-500">
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-4 gap-4 items-start pb-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Minimum Stock</label>
                        <input type="number" name="min_stock" id="inbReg_min_stock" value="0" min="0"
                               class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                    </div>
                    <div class="col-span-3 flex items-start gap-3 p-3 bg-orange-50 border border-orange-200 rounded-lg">
                        <input type="checkbox" name="requires_expiry" id="inbReg_requires_expiry" value="1"
                               class="mt-0.5 w-4 h-4 text-orange-500 border-gray-300 rounded focus:ring-orange-400">
                        <label for="inbReg_requires_expiry" class="cursor-pointer">
                            <span class="text-sm font-medium text-gray-800">Expiry Date Required</span>
                            <p class="text-xs text-gray-500 mt-0.5">When checked, expiry date must be entered when receiving this product.</p>
                        </label>
                    </div>
                </div>
            </form>
        </div>
        <div class="px-6 py-4 border-t border-gray-100 flex gap-3 shrink-0">
            <button type="button" onclick="submitInboundRegisterModal()"
                    class="flex-1 px-4 py-2 bg-teal-600 text-white text-sm font-medium rounded-lg hover:bg-teal-700">
                <i class="fas fa-save mr-2"></i>Register
            </button>
            <button type="button" onclick="closeInboundRegisterModal()"
                    class="px-4 py-2 bg-gray-100 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-200">Cancel</button>
        </div>
    </div>
</div>

<!-- 브랜드/카테고리 빠른 등록 (인바운드용) -->
<div id="inbRegQuickModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40" style="z-index:60">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-sm mx-4 p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 id="inbRegQuickTitle" class="text-base font-semibold text-gray-800">New Brand</h3>
            <button type="button" onclick="closeInbRegQuick()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
        </div>
        <div id="inbRegQuickError" class="hidden mb-3 text-xs text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2"></div>
        <div class="space-y-3">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">English Name <span class="text-red-500">*</span></label>
                <input type="text" id="inbRegQuickNameEn" autocomplete="off" placeholder="E.g.: Nongshim, Beverage"
                       class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"
                       onkeydown="if(event.key==='Enter'){event.preventDefault();saveInbRegQuick();}">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Korean Name</label>
                <div class="flex gap-2">
                    <input type="text" id="inbRegQuickNameKo" autocomplete="off" placeholder="E.g.: Nongshim, Beverage"
                           class="flex-1 border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"
                           onkeydown="if(event.key==='Enter'){event.preventDefault();inbRegQuickTranslate();}">
                    <button type="button" id="inbRegQuickTransBtn" onclick="inbRegQuickTranslate()"
                            class="px-3 py-2 text-xs font-semibold rounded-md whitespace-nowrap transition-colors"
                            style="background:#f3e8ff;color:#7e22ce;">한글→영문표기</button>
                </div>
            </div>
        </div>
        <div class="flex gap-2 mt-5">
            <button type="button" onclick="saveInbRegQuick()"
                    class="flex-1 px-4 py-2 bg-teal-600 text-white text-sm font-medium rounded-lg hover:bg-teal-700 transition-colors">
                <i class="fas fa-plus mr-1.5"></i>Register
            </button>
            <button type="button" onclick="closeInbRegQuick()"
                    class="px-4 py-2 bg-gray-100 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-200 transition-colors">
                Cancel
            </button>
        </div>
    </div>
</div>

<!-- Supplier 빠른 등록 모달 -->
<div id="supplierModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-sm mx-4 p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-gray-800">New Supplier</h3>
            <button type="button" onclick="closeSupplierModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
        </div>
        <div id="supplierModalError" class="hidden mb-3 text-xs text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2"></div>
        <div class="space-y-3">
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Name <span class="text-red-500">*</span></label>
                <input type="text" id="smName" placeholder="Supplier name" autocomplete="off"
                       class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Contact Person</label>
                <input type="text" id="smContact" placeholder="-" autocomplete="off"
                       class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Phone</label>
                <input type="text" id="smPhone" placeholder="-" autocomplete="off"
                       class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Email</label>
                <input type="email" id="smEmail" placeholder="-" autocomplete="off"
                       class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
            </div>
        </div>
        <div class="flex gap-2 mt-5">
            <button type="button" onclick="saveSupplier()"
                    class="flex-1 px-4 py-2 bg-teal-600 text-white text-sm font-medium rounded-lg hover:bg-teal-700 transition-colors">
                <i class="fas fa-save mr-1.5"></i>Save
            </button>
            <button type="button" onclick="closeSupplierModal()"
                    class="px-4 py-2 bg-gray-100 text-gray-600 text-sm font-medium rounded-lg hover:bg-gray-200 transition-colors">
                Cancel
            </button>
        </div>
    </div>
</div>

<!-- 카메라 스캔 모달 -->
<div id="cameraScanModal" class="hidden fixed inset-0 z-50 flex flex-col bg-black">
    <div class="flex items-center justify-between px-4 py-3 bg-gray-900">
        <div class="flex items-center gap-2">
            <i class="fas fa-camera text-indigo-400"></i>
            <span class="text-white text-sm font-semibold">Barcode Scan</span>
        </div>
        <button type="button" onclick="closeCameraScanner()" class="text-gray-400 hover:text-white p-1">
            <i class="fas fa-times text-lg"></i>
        </button>
    </div>
    <div class="flex-1 relative flex flex-col items-center justify-center">
        <div id="cameraViewfinder" class="w-full max-w-sm"></div>
        <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
            <div class="w-64 h-32 border-2 border-indigo-400 rounded-lg opacity-70"></div>
        </div>
        <p id="cameraScanStatus" class="mt-4 text-sm text-gray-300 text-center px-4">Point the camera at the barcode</p>
    </div>
    <div class="px-4 py-3 bg-gray-900 text-center">
        <button type="button" onclick="closeCameraScanner()"
                class="px-6 py-2 bg-gray-700 text-white text-sm font-medium rounded-lg hover:bg-gray-600">
            Cancel
        </button>
    </div>
</div>

<!-- 행 템플릿 -->
<template id="rowTpl">
    <tr class="item-row border-b border-gray-50">
        <td class="px-3 py-2 text-xs text-gray-400 row-num"></td>
        <td class="px-3 py-2" style="width:15rem;max-width:15rem">
            <input type="hidden" name="product_id[]" class="product-id-hidden" data-req-expiry="0">
            <div class="truncate text-xs font-medium text-gray-700"><span class="product-name-en">-</span> <span class="product-name-unit text-gray-400"></span></div>
            <div class="truncate text-xs text-gray-400 product-name-ko">-</div>
            <div class="truncate text-xs text-gray-400 font-mono product-name-barcode">-</div>
        </td>
        <!-- Capacity (read-only) -->
        <td class="px-3 py-2">
            <span class="row-capacity text-xs text-gray-500">-</span>
        </td>
        <!-- Unit (BOX/PCS 행별 선택) -->
        <td class="px-3 py-2">
            <select class="row-unit-select w-full border border-gray-200 rounded px-1 py-1.5 text-xs text-center focus:outline-none focus:ring-1 focus:ring-teal-500" onchange="onRowUnitSelectChange(this)">
                <option value="BOX">BOX</option>
                <option value="PACK">PACK</option>
                <option value="PCS">PCS</option>
            </select>
        </td>
        <!-- PKG (1박스당 PCS 수량) -->
        <td class="px-3 py-2">
            <input type="number" name="pieces_per_box[]" class="row-ppb-input w-full border border-gray-200 rounded px-2 py-1.5 text-sm text-center focus:outline-none focus:ring-1 focus:ring-teal-500"
                   min="1" step="1" value="1" oninput="onRowPpbInput(this)">
        </td>
        <!-- Expiry Date -->
        <td class="px-3 py-2" style="min-width:8rem">
            <input type="text" name="expiry_date[]" placeholder="YYYYMMDD" maxlength="10" autocomplete="off" class="w-full border border-gray-200 rounded px-2 py-1.5 text-sm font-mono focus:outline-none focus:ring-1 focus:ring-teal-500">
            <span class="expiry-req-badge hidden text-xs font-semibold text-orange-600 mt-0.5 block">&#9888; Expiry Required</span>
        </td>
        <!-- QTY(PCS) -->
        <td class="px-3 py-2">
            <input type="number" placeholder="0"
                   class="row-qty-pcs w-full border border-gray-200 rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-teal-500"
                   oninput="onRowQtyPcs(this)">
            <span class="row-qty-pcs-dash hidden text-sm text-gray-300">-</span>
        </td>
        <!-- QTY(BOX/PACK) -->
        <td class="px-3 py-2">
            <span class="row-qty-box-label text-[10px] font-semibold text-gray-400 block mb-0.5">BOX</span>
            <input type="number" placeholder="0"
                   class="row-qty-box w-full border border-gray-200 rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-teal-500"
                   oninput="onRowQtyBox(this)">
            <span class="row-qty-box-dash hidden text-sm text-gray-300">-</span>
        </td>
        <!-- PRICE(PCS) -->
        <td class="px-3 py-2">
            <input type="number" step="0.01" min="0" placeholder="0.00"
                   class="row-price-pcs w-full border border-gray-200 rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-teal-500"
                   oninput="onRowPricePcs(this)">
        </td>
        <!-- PRICE(BOX) -->
        <td class="px-3 py-2">
            <input type="number" step="0.01" min="0" placeholder="0.00"
                   class="row-price-box w-full border border-gray-200 rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-teal-500"
                   oninput="onRowPriceBox(this)">
        </td>
        <!-- Location -->
        <td class="px-3 py-2"><input type="text" name="storage_location[]" placeholder="e.g. A-01-03" class="w-full border border-gray-200 rounded px-2 py-1.5 text-sm font-mono focus:outline-none focus:ring-1 focus:ring-teal-500"></td>
        <!-- hidden fields for form submission -->
        <input type="hidden" name="quantity[]">
        <input type="hidden" name="unit[]" value="BOX">
        <input type="hidden" name="lot_number[]" value="">
        <input type="hidden" name="cost_price[]">
        <input type="hidden" name="regular_price[]" class="regular-price-hidden">
        <!-- Discount -->
        <td class="px-3 py-2 text-center">
            <span class="row-discount-rate text-xs font-semibold text-orange-500">-</span>
        </td>
        <!-- COST(PCS) -->
        <td class="px-3 py-2">
            <span class="row-final-cost text-sm font-semibold text-teal-700">-</span>
        </td>
        <!-- COST(BOX) -->
        <td class="px-3 py-2">
            <span class="row-final-cost-box text-sm font-semibold text-teal-700">-</span>
        </td>
        <!-- Total -->
        <td class="px-3 py-2">
            <span class="row-total text-sm font-bold text-teal-800">-</span>
        </td>
        <td class="px-3 py-2 text-center"><button type="button" onclick="removeRow(this)" class="text-gray-300 hover:text-red-400 transition-colors"><i class="fas fa-times text-xs"></i></button></td>
    </tr>
</template>

<script>
// ── Supplier 검색 자동완성 ────────────────────────────────────────────────
(function() {
    var LC_BASE_S    = '<?php echo LC_BASE; ?>';
    var searchInput  = document.getElementById('supplierSearch');
    var idInput      = document.getElementById('supplierIdInput');
    var panel        = document.getElementById('supplierPanel');

    var currentSuppliers  = [];
    var supplierRows      = [];
    var activeIdx         = -1;
    var mouseSelectEnabled = false;
    var scrollWrap        = null;
    var debounceTimer     = null;
    var isNoResultsMode   = false;

    function scrollRowIntoView(tr) {
        if (!scrollWrap) return;
        var top    = tr.offsetTop;
        var bottom = top + tr.offsetHeight;
        if (top < scrollWrap.scrollTop)
            scrollWrap.scrollTop = top;
        else if (bottom > scrollWrap.scrollTop + scrollWrap.clientHeight)
            scrollWrap.scrollTop = bottom - scrollWrap.clientHeight;
    }

    function setActiveRow(idx) {
        supplierRows.forEach(function(tr, i) {
            var numBadge = tr.querySelector('.s-num');
            var selBadge = tr.querySelector('.s-sel');
            if (i === idx) {
                tr.style.backgroundColor = '#0f766e';
                tr.querySelectorAll('p, span:not(.s-sel):not(.s-num)').forEach(function(el) { el.style.color = 'rgba(255,255,255,0.9)'; });
                if (numBadge) { numBadge.style.backgroundColor = 'rgba(255,255,255,0.25)'; numBadge.style.color = '#fff'; }
                if (selBadge) { selBadge.style.backgroundColor = '#fff'; selBadge.style.color = '#0f766e'; }
                scrollRowIntoView(tr);
            } else {
                tr.style.backgroundColor = '';
                tr.querySelectorAll('p, span:not(.s-sel):not(.s-num)').forEach(function(el) { el.style.color = ''; });
                if (numBadge) { numBadge.style.backgroundColor = ''; numBadge.style.color = ''; }
                if (selBadge) { selBadge.style.backgroundColor = ''; selBadge.style.color = ''; }
            }
        });
        activeIdx = idx;
    }

    function renderPanel(suppliers, query) {
        currentSuppliers = suppliers;
        supplierRows = [];
        activeIdx = -1;
        scrollWrap = null;
        isNoResultsMode = false;
        panel.innerHTML = '';

        var wrap = document.createElement('div');

        if (suppliers.length === 0) {
            isNoResultsMode = true;
            wrap.className = 'bg-white border-2 border-amber-300 rounded-xl shadow-lg overflow-hidden';

            var hdr = document.createElement('div');
            hdr.className = 'flex items-center justify-between px-4 py-2.5 bg-amber-500 text-white';
            hdr.innerHTML =
                '<span class="font-semibold text-sm"><i class="fas fa-search mr-2"></i>No results</span>' +
                '<span class="text-xs text-amber-100 flex items-center gap-1.5">' +
                '<kbd class="px-1.5 py-0.5 bg-amber-600 rounded text-xs">Enter</kbd> Register New ' +
                '<kbd class="px-1.5 py-0.5 bg-amber-600 rounded text-xs ml-1">Esc</kbd> Close</span>';
            wrap.appendChild(hdr);

            var table = document.createElement('table');
            table.className = 'w-full';
            var tr = document.createElement('tr');
            tr.className = 'border-b border-gray-100 cursor-pointer transition-all duration-100';
            tr.innerHTML =
                '<td class="px-1.5 py-3 w-8 text-center">' +
                    '<span class="s-num inline-flex items-center justify-center w-6 h-6 rounded-full bg-gray-100 text-gray-500 text-xs font-bold"><i class="fas fa-plus"></i></span>' +
                '</td>' +
                '<td class="px-3 py-3">' +
                    '<p class="font-semibold text-gray-900 text-sm leading-tight">Register as new supplier</p>' +
                    '<p class="text-xs text-gray-500 mt-0.5">No suppliers matching "' + escHtml(query) + '".</p>' +
                '</td>' +
                '<td class="px-3 py-3 w-20 text-right">' +
                    '<span class="s-sel inline-flex items-center gap-1 px-3 py-1 bg-amber-500 text-white text-xs font-semibold rounded-lg">' +
                    '<i class="fas fa-plus text-xs"></i>Register</span>' +
                '</td>';
            tr.addEventListener('mousedown', function(e) { e.preventDefault(); hidePanel(); openSupplierModal(query || ''); });
            tr.addEventListener('mouseenter', function() { if (mouseSelectEnabled) setActiveRow(0); });
            table.appendChild(tr);
            supplierRows.push(tr);
            wrap.appendChild(table);

            mouseSelectEnabled = false;
            setTimeout(function() { setActiveRow(0); }, 0);
        } else {
            wrap.className = 'bg-white border-2 border-teal-300 rounded-xl shadow-lg overflow-hidden';

            // 헤더
            var hdr = document.createElement('div');
            hdr.className = 'flex items-center justify-between px-4 py-2.5 bg-teal-600 text-white';
            hdr.innerHTML =
                '<span class="font-semibold text-sm"><i class="fas fa-truck mr-2"></i>' + suppliers.length + ' supplier(s) found</span>' +
                '<span class="text-xs text-teal-200 flex items-center gap-1.5">' +
                '<kbd class="px-1.5 py-0.5 bg-teal-700 rounded text-xs">↑↓</kbd> Move ' +
                '<kbd class="px-1.5 py-0.5 bg-teal-700 rounded text-xs ml-1">Enter</kbd> Select ' +
                '<kbd class="px-1.5 py-0.5 bg-teal-700 rounded text-xs ml-1">Esc</kbd> Close</span>';
            wrap.appendChild(hdr);

            // 테이블
            var tableWrap = document.createElement('div');
            tableWrap.className = 'max-h-56 overflow-y-auto';
            var table = document.createElement('table');
            table.className = 'w-full';

            suppliers.forEach(function(s, idx) {
                var tr = document.createElement('tr');
                tr.className = 'border-b border-gray-100 cursor-pointer transition-all duration-100';
                tr.innerHTML =
                    '<td class="px-1.5 py-3 w-8 text-center">' +
                        '<span class="s-num inline-flex items-center justify-center w-6 h-6 rounded-full bg-gray-100 text-gray-500 text-xs font-bold">' + (idx + 1) + '</span>' +
                    '</td>' +
                    '<td class="px-3 py-3">' +
                        '<p class="font-semibold text-gray-900 text-sm leading-tight">' + escHtml(s.name) + '</p>' +
                        (s.contact_person ? '<p class="text-xs text-gray-500 mt-0.5"><i class="fas fa-user mr-1"></i>' + escHtml(s.contact_person) + '</p>' : '') +
                        (s.phone ? '<p class="text-xs text-gray-400 mt-0.5"><i class="fas fa-phone mr-1"></i>' + escHtml(s.phone) + '</p>' : '') +
                    '</td>' +
                    '<td class="px-3 py-3 w-20 text-right">' +
                        '<span class="s-sel inline-flex items-center gap-1 px-3 py-1 bg-teal-600 text-white text-xs font-semibold rounded-lg">' +
                        '<i class="fas fa-check text-xs"></i>Select</span>' +
                    '</td>';
                tr.addEventListener('click', function() { selectSupplier(s); });
                tr.addEventListener('mouseenter', function() { if (mouseSelectEnabled) setActiveRow(idx); });
                table.appendChild(tr);
                supplierRows.push(tr);
            });

            tableWrap.addEventListener('mousemove', function() { mouseSelectEnabled = true; });
            tableWrap.appendChild(table);
            wrap.appendChild(tableWrap);
            scrollWrap = tableWrap;

            // 푸터 - 신규 등록
            var footer = document.createElement('div');
            footer.className = 'px-4 py-2 border-t border-gray-100 bg-gray-50 flex items-center justify-between';
            footer.innerHTML = '<span class="text-xs text-gray-400">Can\'t find the supplier?</span>';
            var footerBtn = document.createElement('button');
            footerBtn.type = 'button';
            footerBtn.className = 'text-xs text-teal-600 hover:text-teal-800 font-semibold';
            footerBtn.innerHTML = '<i class="fas fa-plus mr-1"></i>Add New Supplier';
            footerBtn.addEventListener('mousedown', function(e) { e.preventDefault(); hidePanel(); openSupplierModal(query || ''); });
            footer.appendChild(footerBtn);
            wrap.appendChild(footer);

            // 첫 번째 행 자동 하이라이트
            mouseSelectEnabled = false;
            setTimeout(function() { setActiveRow(0); }, 0);
        }

        panel.appendChild(wrap);
        panel.classList.remove('hidden');
    }

    function hidePanel() {
        panel.classList.add('hidden');
        panel.innerHTML = '';
        currentSuppliers = [];
        supplierRows = [];
        activeIdx = -1;
        scrollWrap = null;
        mouseSelectEnabled = false;
        isNoResultsMode = false;
    }

    function selectSupplier(s) {
        idInput.value     = s.id;
        searchInput.value = s.name;
        hidePanel();
    }

    function search(q) {
        fetch(LC_BASE_S + '/ajax/search_supplier.php?q=' + encodeURIComponent(q))
            .then(function(r) { return r.json(); })
            .then(function(data) { if (data.success) renderPanel(data.suppliers, q); })
            .catch(function() {});
    }

    searchInput.addEventListener('focus', function() { search(this.value.trim()); });

    searchInput.addEventListener('input', function() {
        idInput.value = '';
        clearTimeout(debounceTimer);
        var q = this.value.trim();
        debounceTimer = setTimeout(function() { search(q); }, 300);
    });

    searchInput.addEventListener('blur', function() {
        setTimeout(hidePanel, 200);
    });

    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') { hidePanel(); return; }

        var isPanelOpen = !panel.classList.contains('hidden') && supplierRows.length > 0;
        if (!isPanelOpen) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            mouseSelectEnabled = false;
            setActiveRow(activeIdx < supplierRows.length - 1 ? activeIdx + 1 : 0);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            mouseSelectEnabled = false;
            setActiveRow(activeIdx > 0 ? activeIdx - 1 : supplierRows.length - 1);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (isNoResultsMode) {
                var q = searchInput.value.trim();
                hidePanel();
                openSupplierModal(q);
            } else if (activeIdx >= 0 && activeIdx < currentSuppliers.length) {
                selectSupplier(currentSuppliers[activeIdx]);
            }
        }
    });

    window._supplierSelectById = function(id, name) {
        idInput.value = id; searchInput.value = name; hidePanel();
    };

    function escHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
})();

// ── Supplier 빠른 등록 모달 ───────────────────────────────────────────────
window.openSupplierModal = function(prefill) {
    document.getElementById('smName').value    = prefill || '';
    document.getElementById('smContact').value = '';
    document.getElementById('smPhone').value   = '';
    document.getElementById('smEmail').value   = '';
    document.getElementById('supplierModalError').classList.add('hidden');
    document.getElementById('supplierModal').classList.remove('hidden');
    document.getElementById('smName').focus();
};

window.closeSupplierModal = function() {
    document.getElementById('supplierModal').classList.add('hidden');
};

window.saveSupplier = function() {
    var name    = document.getElementById('smName').value.trim();
    var contact = document.getElementById('smContact').value.trim();
    var phone   = document.getElementById('smPhone').value.trim();
    var email   = document.getElementById('smEmail').value.trim();
    var errEl   = document.getElementById('supplierModalError');

    if (!name) {
        errEl.textContent = 'Supplier name is required.';
        errEl.classList.remove('hidden');
        document.getElementById('smName').focus();
        return;
    }

    var LC_BASE_S = '<?php echo LC_BASE; ?>';
    var fd = new FormData();
    fd.append('csrf_token',     '<?php echo htmlspecialchars(lc_csrf_token()); ?>');
    fd.append('name',           name);
    fd.append('contact_person', contact);
    fd.append('phone',          phone);
    fd.append('email',          email);

    fetch(LC_BASE_S + '/ajax/quick_create_supplier.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) {
                errEl.textContent = data.message || 'Failed to save.';
                errEl.classList.remove('hidden');
                return;
            }
            window._supplierSelectById(data.supplier.id, data.supplier.name);
            closeSupplierModal();
        })
        .catch(function() {
            errEl.textContent = 'Network error. Please try again.';
            errEl.classList.remove('hidden');
        });
};

// ESC로 모달 닫기
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeSupplierModal();
});
</script>

<script>
(function() {
    var multiProducts = [];
    var isNoResultsMode = false; // 검색 결과 없음 → "신규 등록" 항목 표시 모드
    var activeMultiIdx = -1;
    var mouseSelectEnabled = false;  // 실제 마우스 이동 전엔 hover 선택 비활성
    var multiScrollWrap = null;      // 결과 패널 스크롤 컨테이너
    var barcodeTimer = null;
    var searchInFlight = false;  // AJAX 중복 호출 방지
    var lastQuery = '';          // 마지막 검색어 (IME compositionend 중복 검색 방지)
    var LC_BASE = '<?php echo LC_BASE; ?>';

    // ── 바코드 입력 이벤트 ──────────────────────────────────────────
    var barcodeInput = document.getElementById('barcodeInput');

    barcodeInput.addEventListener('keydown', function(e) {
        var div = document.getElementById('barcodeMulti');
        var isResultsVisible = !div.classList.contains('hidden') && multiProducts.length > 0;

        if (isResultsVisible && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
            e.preventDefault();
            mouseSelectEnabled = false;  // 키보드 탐색 중엔 hover 무시 (실제 이동 전까지)
            var next = activeMultiIdx + (e.key === 'ArrowDown' ? 1 : -1);
            next = Math.max(0, Math.min(multiProducts.length - 1, next));
            setActiveMultiRow(next);
            return;
        }

        if (e.key === 'Enter') {
            e.preventDefault();
            clearTimeout(barcodeTimer);
            if (isResultsVisible) {
                // 패널이 열려있으면: 하이라이트된 항목 또는 첫 번째 항목 선택
                selectMulti(activeMultiIdx >= 0 ? activeMultiIdx : 0);
            } else {
                searchBarcode();
            }
        }

        if (e.key === 'Escape' && isResultsVisible) {
            e.preventDefault();
            hideMutli();
        }
    });

    barcodeInput.addEventListener('input', function() {
        clearTimeout(barcodeTimer);
        var val = this.value.trim();
        if (/^\d{8,}$/.test(val)) {
            // 8자리 이상 숫자 → 바코드 스캔, 빠르게 검색
            barcodeTimer = setTimeout(searchBarcode, 200);
        } else if (val.length >= 2) {
            // 2글자 이상 텍스트 → 상품명(한글/영문) 검색
            barcodeTimer = setTimeout(searchBarcode, 400);
        }
    });

    // ── 바코드 검색 ────────────────────────────────────────────────
    window.searchBarcode = function() {
        var barcode = barcodeInput.value.trim();
        if (!barcode) { barcodeInput.focus(); return; }

        // 같은 검색어의 결과 패널이 이미 떠 있으면 재검색/재렌더 생략
        // (한글 IME compositionend 가 동일 검색어로 input 을 한 번 더 발생시켜
        //  패널이 재렌더되며 선택(activeMultiIdx)이 초기화되는 문제 방지)
        var divEl = document.getElementById('barcodeMulti');
        if (barcode === lastQuery && !divEl.classList.contains('hidden')) return;

        if (searchInFlight) return;   // 이미 검색 중이면 무시

        searchInFlight = true;
        lastQuery = barcode;
        setStatus('loading', 'Searching...');
        hideMutli();

        fetch(LC_BASE + '/ajax/search_product_by_barcode.php?barcode=' + encodeURIComponent(barcode))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                searchInFlight = false;
                if (!data.success) {
                    setStatus('warn', "No products matching '" + barcode + "'. Press Enter or click to register a new product.");
                    showNoResults(barcode);
                    return;
                }
                setStatus('warn', data.products.length + ' product(s) found. Please select to add.');
                multiProducts = data.products;
                showMulti(data.products);
            })
            .catch(function() {
                searchInFlight = false;
                setStatus('error', '⚠ An error occurred during search.');
            });
    };

    var multiRows = [];

    function showMulti(prods) {
        isNoResultsMode = false;
        activeMultiIdx = -1;
        multiRows = [];
        var div = document.getElementById('barcodeMulti');
        div.innerHTML = '';

        // 패널 컨테이너
        var panel = document.createElement('div');
        panel.className = 'bg-white border-2 border-teal-300 rounded-xl shadow-lg overflow-hidden';

        // 헤더
        var header = document.createElement('div');
        header.className = 'flex items-center justify-between px-4 py-2.5 bg-teal-600 text-white';
        header.innerHTML =
            '<span class="font-semibold text-sm"><i class="fas fa-boxes mr-2"></i>' + prods.length + ' products found</span>' +
            '<span class="text-xs text-teal-200 flex items-center gap-1.5">' +
            '<kbd class="px-1.5 py-0.5 bg-teal-700 rounded text-xs">↑↓</kbd> Move' +
            '<kbd class="px-1.5 py-0.5 bg-teal-700 rounded text-xs ml-1">Enter</kbd> Add' +
            '<kbd class="px-1.5 py-0.5 bg-teal-700 rounded text-xs ml-1">Esc</kbd> Close' +
            '</span>';
        panel.appendChild(header);

        // 테이블
        var tableWrap = document.createElement('div');
        tableWrap.className = 'max-h-64 overflow-y-auto';
        var table = document.createElement('table');
        table.className = 'w-full';

        prods.forEach(function(p, idx) {
            var tr = document.createElement('tr');
            tr.className = 'multi-row border-b border-gray-100 cursor-pointer transition-all duration-100';
            tr.innerHTML =
                '<td class="px-1.5 py-3 w-8 text-center">' +
                    '<span class="row-num inline-flex items-center justify-center w-6 h-6 rounded-full bg-gray-100 text-gray-500 text-xs font-bold">' + (idx + 1) + '</span>' +
                '</td>' +
                '<td class="px-3 py-3">' +
                    '<p class="font-semibold text-gray-900 text-sm leading-tight">' + escHtml(p.name_en) +
                        (p.capacity ? ' <span class="text-xs font-normal text-gray-400">' + escHtml(p.capacity) + '</span>' : '') + '</p>' +
                    (p.name_ko ? '<p class="text-xs text-gray-500 mt-0.5">' + escHtml(p.name_ko) + '</p>' : '') +
                    (p.barcode_unit ? '<p class="text-xs text-gray-400 font-mono mt-0.5"><i class="fas fa-barcode mr-1"></i>' + escHtml(p.barcode_unit) + '</p>' : '') +
                '</td>' +
                '<td class="px-3 py-3 w-16">' +
                    '<span class="inline-block px-2 py-0.5 bg-gray-100 text-gray-600 text-xs rounded-full font-medium">' + escHtml(p.unit) + '</span>' +
                '</td>' +
                '<td class="px-3 py-3 w-20 text-right">' +
                    '<span class="add-badge inline-flex items-center gap-1 px-3 py-1 bg-teal-600 text-white text-xs font-semibold rounded-lg">' +
                    '<i class="fas fa-plus text-xs"></i>Add</span>' +
                '</td>';
            tr.addEventListener('click', function() { selectMulti(idx); });
            tr.addEventListener('mouseenter', function() {
                // 실제 마우스 이동이 감지된 경우에만 hover 선택 허용
                if (mouseSelectEnabled) setActiveMultiRow(idx);
            });
            table.appendChild(tr);
            multiRows.push(tr);
        });

        // 실제 포인터 이동이 있을 때만 hover 선택 활성화
        // (키보드 탐색/스크롤로 인한 가짜 mouseenter는 mousemove를 동반하지 않음)
        tableWrap.addEventListener('mousemove', function() {
            mouseSelectEnabled = true;
        });

        tableWrap.appendChild(table);
        panel.appendChild(tableWrap);
        div.appendChild(panel);
        div.classList.remove('hidden');

        multiScrollWrap = tableWrap;

        // 결과가 하나 이상이면 첫 번째 행을 자동 하이라이트
        if (prods.length > 0) {
            mouseSelectEnabled = false;
            setActiveMultiRow(0);
        }
    }

    // ── 검색 결과 없음 → "신규 등록" 항목 표시 ────────────────────
    function showNoResults(query) {
        isNoResultsMode = true;
        activeMultiIdx = -1;
        multiRows = [];
        var div = document.getElementById('barcodeMulti');
        div.innerHTML = '';

        var panel = document.createElement('div');
        panel.className = 'bg-white border-2 border-amber-300 rounded-xl shadow-lg overflow-hidden';

        var header = document.createElement('div');
        header.className = 'flex items-center justify-between px-4 py-2.5 bg-amber-500 text-white';
        header.innerHTML =
            '<span class="font-semibold text-sm"><i class="fas fa-search mr-2"></i>No results</span>' +
            '<span class="text-xs text-amber-100 flex items-center gap-1.5">' +
            '<kbd class="px-1.5 py-0.5 bg-amber-600 rounded text-xs">Enter</kbd> Register New' +
            '<kbd class="px-1.5 py-0.5 bg-amber-600 rounded text-xs ml-1">Esc</kbd> Close' +
            '</span>';
        panel.appendChild(header);

        var table = document.createElement('table');
        table.className = 'w-full';

        var tr = document.createElement('tr');
        tr.className = 'multi-row border-b border-gray-100 cursor-pointer transition-all duration-100';
        tr.innerHTML =
            '<td class="px-1.5 py-3 w-8 text-center">' +
                '<span class="row-num inline-flex items-center justify-center w-6 h-6 rounded-full bg-gray-100 text-gray-500 text-xs font-bold"><i class="fas fa-plus"></i></span>' +
            '</td>' +
            '<td class="px-3 py-3">' +
                '<p class="font-semibold text-gray-900 text-sm leading-tight">Register as new product</p>' +
                '<p class="text-xs text-gray-500 mt-0.5">No products matching "' + escHtml(query) + '".</p>' +
            '</td>' +
            '<td class="px-3 py-3 w-20 text-right">' +
                '<span class="add-badge inline-flex items-center gap-1 px-3 py-1 bg-amber-500 text-white text-xs font-semibold rounded-lg">' +
                '<i class="fas fa-plus text-xs"></i>Register</span>' +
            '</td>';
        tr.addEventListener('click', function() { selectMulti(0); });
        tr.addEventListener('mouseenter', function() {
            if (mouseSelectEnabled) setActiveMultiRow(0);
        });
        table.appendChild(tr);
        multiRows.push(tr);

        panel.appendChild(table);
        div.appendChild(panel);
        div.classList.remove('hidden');

        multiScrollWrap = null;
        multiProducts = [{ __newProduct: true, query: query }];
        mouseSelectEnabled = false;
        setActiveMultiRow(0);
    }

    // 패널 내부에서만 스크롤 (윈도우는 절대 건드리지 않음)
    function scrollRowIntoView(tr) {
        if (!multiScrollWrap) return;
        var wr = multiScrollWrap.getBoundingClientRect();
        var rr = tr.getBoundingClientRect();
        if (rr.top < wr.top) {
            multiScrollWrap.scrollTop -= (wr.top - rr.top);
        } else if (rr.bottom > wr.bottom) {
            multiScrollWrap.scrollTop += (rr.bottom - wr.bottom);
        }
    }

    function setActiveMultiRow(idx) {
        multiRows.forEach(function(tr, i) {
            var numBadge = tr.querySelector('.row-num');
            var addBadge = tr.querySelector('.add-badge');
            if (i === idx) {
                tr.style.backgroundColor = '#0f766e'; // teal-700
                tr.querySelectorAll('p, span:not(.add-badge):not(.row-num)').forEach(function(el) {
                    el.style.color = 'rgba(255,255,255,0.9)';
                });
                if (numBadge) { numBadge.style.backgroundColor = 'rgba(255,255,255,0.25)'; numBadge.style.color = '#fff'; }
                if (addBadge) { addBadge.style.backgroundColor = '#fff'; addBadge.style.color = '#0f766e'; }
                scrollRowIntoView(tr);
            } else {
                tr.style.backgroundColor = '';
                tr.querySelectorAll('p, span:not(.add-badge):not(.row-num)').forEach(function(el) {
                    el.style.color = '';
                });
                if (numBadge) { numBadge.style.backgroundColor = ''; numBadge.style.color = ''; }
                if (addBadge) { addBadge.style.backgroundColor = ''; addBadge.style.color = ''; }
            }
        });
        activeMultiIdx = idx;
    }

    function hideMutli() {
        isNoResultsMode = false;
        activeMultiIdx = -1;
        multiRows = [];
        multiScrollWrap = null;
        mouseSelectEnabled = false;
        var div = document.getElementById('barcodeMulti');
        div.classList.add('hidden');
        div.innerHTML = '';
    }

    window.selectMulti = function(idx) {
        if (isNoResultsMode) {
            var query = multiProducts[0].query;
            hideMutli();
            openInboundConfirmRegister(query);
            return;
        }
        addProductRow(multiProducts[idx]);
        setStatus('success', '✓ ' + multiProducts[idx].name_en + ' Added');
        hideMutli();
        barcodeInput.value = '';
        // 포커스는 addProductRow 내부에서 Expiry Date로 이동
    };

    // ── 유통기한 필수 여부 업데이트 ────────────────────────────────
    function updateExpiryRequired(row) {
        var hiddenInp = row.querySelector('input[name="product_id[]"]');
        var reqExpiry = hiddenInp ? parseInt(hiddenInp.getAttribute('data-req-expiry') || '0') : 0;
        var expiryInput = row.querySelector('input[name="expiry_date[]"]');
        var badge = row.querySelector('.expiry-req-badge');

        if (reqExpiry) {
            // 필수: 입력 가능 + 강조
            expiryInput.readOnly = false;
            expiryInput.placeholder = 'YYYYMMDD';
            expiryInput.classList.remove('border-gray-200', 'bg-gray-100', 'text-gray-400', 'cursor-not-allowed');
            expiryInput.classList.add('border-orange-400', 'bg-orange-50');
            if (badge) badge.classList.remove('hidden');
        } else {
            // 비필수: 입력 차단(readonly로 제출 인덱스는 유지) + 값 비움
            // disabled는 폼 미제출로 expiry_date[] 인덱스가 어긋나므로 readonly 사용
            expiryInput.value = '';
            expiryInput.readOnly = true;
            expiryInput.placeholder = '-';
            expiryInput.classList.remove('border-orange-400', 'bg-orange-50');
            expiryInput.classList.add('border-gray-200', 'bg-gray-100', 'text-gray-400', 'cursor-not-allowed');
            if (badge) badge.classList.add('hidden');
        }
    }

    // 행 단위 select 색상: 하위 호환 유지 (no-op — unit select 없음)
    window.styleUnitSelect = function(sel) { /* no-op: unit select removed */ };

    // 기존 updateRowPcsCost — no-op (unit select 없음, updateRowFinalCost로 대체)
    window.updateRowPcsCost = function(row) { /* no-op */ };

    // 기존 onRowUnitChange — no-op
    window.onRowUnitChange = function(sel) { /* no-op */ };

    // ── row내 PPB 가져오기 ─────────────────────────────────────────
    function getRowPpb(row) {
        var inp = row.querySelector('.row-ppb-input');
        return Math.max(1, parseInt(inp ? inp.value : '1', 10));
    }

    // ── QTY 동기화 ─────────────────────────────────────────────────
    window.onRowQtyBox = function(inp) {
        var row = inp.closest('tr.item-row');
        var ppb = getRowPpb(row);
        var box = parseFloat(inp.value) || 0;
        var pcsInp = row.querySelector('.row-qty-pcs');
        if (pcsInp) pcsInp.value = box > 0 ? Math.round(box * ppb) : '';
        syncRowHiddenUnit(row);
        updateRowFinalCost(row);
    };
    window.onRowQtyPcs = function(inp) {
        var row = inp.closest('tr.item-row');
        var ppb = getRowPpb(row);
        var pcs = parseFloat(inp.value) || 0;
        var boxInp = row.querySelector('.row-qty-box');
        if (boxInp) boxInp.value = pcs > 0 && ppb > 1 ? (pcs / ppb).toFixed(2) : '';
        syncRowHiddenUnit(row);
        updateRowFinalCost(row);
    };

    // ── PRICE 동기화 ───────────────────────────────────────────────
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

    // ── PKG 변경 시 재계산 ─────────────────────────────────────────
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

    // ── hidden unit[]/quantity[] 동기화 ───────────────────────────
    // 행별 Unit select가 있으면 그 값을 우선, 없으면 BOX 수량 유무로 추론(하위 호환)
    function syncRowHiddenUnit(row) {
        var unitHid = row.querySelector('input[name="unit[]"]');
        if (!unitHid) return;
        var sel = row.querySelector('.row-unit-select');
        if (sel) {
            unitHid.value = sel.value;
        } else {
            var boxQty = parseFloat(row.querySelector('.row-qty-box') ? row.querySelector('.row-qty-box').value : '') || 0;
            unitHid.value = boxQty > 0 ? 'BOX' : 'PCS';
        }
    }

    // ── 행별 Unit(BOX/PCS) 선택 변경 ───────────────────────────────
    function highlightRowUnit(row) {
        var sel = row.querySelector('.row-unit-select');
        if (!sel) return;
        // Design Ref: pack-unit §4 — 묶음(BOX/PACK)은 bundle 컬럼, PCS는 낱개 컬럼
        var isBundle = sel.value !== 'PCS';
        var pcsQ = row.querySelector('.row-qty-pcs');
        var boxQ = row.querySelector('.row-qty-box');
        var boxLabel = row.querySelector('.row-qty-box-label');
        // 선택된 단위의 수량 칸을 옅게 강조 (BOX=주황, PACK=초록, PCS=파랑)
        if (boxQ) boxQ.style.backgroundColor = isBundle ? (sel.value === 'PACK' ? '#ecfdf5' : '#fff7ed') : '';
        if (pcsQ) pcsQ.style.backgroundColor = isBundle ? '' : '#eff6ff';
        // QTY(BOX) 칸 라벨을 실제 선택 단위(BOX/PACK)에 맞게 표시 — 혼동 방지
        if (boxLabel) {
            boxLabel.textContent = sel.value === 'PACK' ? 'PACK' : 'BOX';
            boxLabel.style.color = sel.value === 'PACK' ? '#059669' : '#d97706';
        }
    }

    window.onRowUnitSelectChange = function(sel) {
        var row = sel.closest('tr.item-row');
        if (!row) return;
        var unitHid = row.querySelector('input[name="unit[]"]');
        if (unitHid) unitHid.value = sel.value;
        highlightRowUnit(row);
        applyUnitQtyVisibility(row);
        updateRowFinalCost(row);
    };

    // ── 매입 단위에 따라 수량 칸 표시 토글 (선택 단위만 입력, 반대는 '-') ──────
    // 단가/원가 칸은 그대로 두고 수량 칸만 제어한다. 환산값은 내부적으로 hidden 입력에
    // 유지되어 합계/제출 계산에 쓰이되, 화면에는 선택 단위 수량만 보인다.
    function applyUnitQtyVisibility(row) {
        var sel = row.querySelector('.row-unit-select');
        // Design Ref: pack-unit §4 — 묶음(BOX/PACK)이면 bundle 수량 칸 표시
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

    // ── COST(PCS)/COST(BOX) 갱신 (PRICE(PCS) 기준 할인 후 표시) ────────────
    function updateRowFinalCost(row) {
        var rate = parseFloat(document.getElementById('discountRate').value) || 0;
        var pcsPriceEl = row.querySelector('.row-price-pcs');
        var pcsPrice = pcsPriceEl ? (parseFloat(pcsPriceEl.value) || 0) : 0;
        var ppb = getRowPpb(row);
        var qtyPcsEl = row.querySelector('.row-qty-pcs');
        var qtyPcs = qtyPcsEl ? (parseFloat(qtyPcsEl.value) || 0) : 0;
        var finalSpan = row.querySelector('.row-final-cost');
        var boxSpan   = row.querySelector('.row-final-cost-box');
        var totalSpan = row.querySelector('.row-total');
        var discSpan  = row.querySelector('.row-discount-rate');
        if (!finalSpan) return;
        var costPcs = rate > 0 && pcsPrice > 0 ? pcsPrice * (1 - rate / 100) : pcsPrice;
        if (rate > 0 && pcsPrice > 0) {
            if (discSpan) discSpan.textContent = '-' + rate + '%';
            finalSpan.textContent = costPcs.toFixed(2);
            finalSpan.style.color = '#0f766e';
            if (boxSpan) { boxSpan.textContent = (costPcs * ppb).toFixed(2); boxSpan.style.color = '#0f766e'; }
        } else {
            if (discSpan) discSpan.textContent = '-';
            finalSpan.textContent = pcsPrice > 0 ? costPcs.toFixed(2) : '-';
            finalSpan.style.color = pcsPrice > 0 ? '#374151' : '';
            if (boxSpan) {
                boxSpan.textContent = pcsPrice > 0 ? (costPcs * ppb).toFixed(2) : '-';
                boxSpan.style.color = pcsPrice > 0 ? '#374151' : '';
            }
        }
        if (totalSpan) {
            totalSpan.textContent = (costPcs > 0 && qtyPcs > 0) ? (costPcs * qtyPcs).toFixed(2) : '-';
        }
    }

    // ── 행 추가 (바코드 결과) ───────────────────────────────────────
    window.addProductRow = function(product) {
        var tpl = document.getElementById('rowTpl');
        var clone = tpl.content.cloneNode(true);
        document.getElementById('itemsBody').appendChild(clone);

        var rows = document.querySelectorAll('#itemsBody .item-row');
        var lastRow = rows[rows.length - 1];

        // 상품 선택
        if (product && product.id) {
            var hiddenInp = lastRow.querySelector('input[name="product_id[]"]');
            var enSpan    = lastRow.querySelector('.product-name-en');
            var unitSpan  = lastRow.querySelector('.product-name-unit');
            var koSpan    = lastRow.querySelector('.product-name-ko');
            var bcSpan    = lastRow.querySelector('.product-name-barcode');
            var capSpan   = lastRow.querySelector('.row-capacity');
            var ppbVal = parseInt(product.pieces_per_box, 10) || 1;
            if (hiddenInp) {
                hiddenInp.value = product.id;
                hiddenInp.setAttribute('data-req-expiry', product.requires_expiry ? '1' : '0');
            }
            var ppbInput = lastRow.querySelector('.row-ppb-input');
            if (ppbInput) ppbInput.value = ppbVal; // 기본값 = 상품 마스터 ppb (수정 가능)
            if (capSpan) capSpan.textContent = product.capacity || '-';
            if (enSpan)   enSpan.textContent   = product.name_en || '-';
            if (unitSpan) unitSpan.textContent = product.unit ? '[' + product.unit + ']' : '';
            if (koSpan)   koSpan.textContent   = product.name_ko || '-';
            if (bcSpan)   bcSpan.textContent   = product.barcode_unit || product.barcode || '-';
            updateExpiryRequired(lastRow);
        }

        // 행별 Unit select 기본값 = 상품 마스터에 등록된 단위 자동 선택 (이후 행마다 개별 변경 가능)
        var unitSelNew = lastRow.querySelector('.row-unit-select');
        if (unitSelNew) {
            var pu = (product && product.unit ? String(product.unit).toUpperCase().trim() : '');
            unitSelNew.value = (pu === 'BOX' || pu === 'PACK' || pu === 'PCS') ? pu : 'BOX';
            syncRowHiddenUnit(lastRow);
            highlightRowUnit(lastRow);
            applyUnitQtyVisibility(lastRow);
        }

        // 행 번호 갱신
        updateRowNums();

        // 잠시 하이라이트
        lastRow.classList.add('bg-teal-50');
        setTimeout(function() { lastRow.classList.remove('bg-teal-50'); }, 800);

        // Expiry Date 포커스 (상품 추가 후 첫 번째 입력 필드)
        var expFirst = lastRow.querySelector('input[name="expiry_date[]"]');
        if (expFirst) { expFirst.focus(); expFirst.select(); }
    };

    // ── 행 삭제 ────────────────────────────────────────────────────
    window.removeRow = function(btn) {
        btn.closest('tr').remove();
        updateRowNums();
    };

    // ── 행 번호 / 빈 상태 갱신 ────────────────────────────────────
    var existingRowCount = document.querySelectorAll('#itemsBody .existing-row').length;

    function updateRowNums() {
        var rows = document.querySelectorAll('#itemsBody .item-row');
        rows.forEach(function(row, i) {
            var numCell = row.querySelector('.row-num');
            if (numCell) numCell.textContent = existingRowCount + i + 1;
        });
        var emptyRow = document.getElementById('emptyRow');
        if (emptyRow) emptyRow.style.display = rows.length > 0 ? 'none' : '';
    }

    // ── 상태 메시지 ────────────────────────────────────────────────
    function setStatus(type, msg) {
        var el = document.getElementById('barcodeStatus');
        var styles = {
            loading: 'flex items-center gap-2 px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm text-gray-600',
            success: 'flex items-center gap-2 px-3 py-2 bg-green-50 border border-green-200 rounded-lg text-sm text-green-700 font-medium',
            error:   'flex items-center gap-2 px-3 py-2 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700',
            warn:    'flex items-center gap-2 px-3 py-2 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-800',
        };
        var icons = {
            loading: '<i class="fas fa-circle-notch fa-spin text-gray-400"></i>',
            success: '<i class="fas fa-check-circle text-green-500"></i>',
            error:   '<i class="fas fa-exclamation-circle text-red-500"></i>',
            warn:    '<i class="fas fa-list-ul text-amber-500"></i>',
        };
        el.className = styles[type] || styles.warn;
        el.innerHTML = (icons[type] || '') + '<span>' + escHtml(msg) + '</span>';
        el.classList.remove('hidden');
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ── 할인율 일괄 적용 ──────────────────────────────────────────
    var discountRateInput  = document.getElementById('discountRate');
    var discountRateHidden = document.getElementById('discountRateHidden');
    var discountHint       = document.getElementById('discountHint');

    // updateRowDiscount → updateRowFinalCost 위임 (하위 호환)
    window.updateRowDiscount = function(inp) {
        var row = inp ? inp.closest('tr.item-row') : null;
        if (row) updateRowFinalCost(row);
    };

    function applyDiscountToAllRows() {
        var rate = parseFloat(discountRateInput.value) || 0;
        discountRateHidden.value = rate;
        if (rate > 0) {
            discountHint.textContent = rate + '% discount applied';
            discountHint.classList.remove('hidden');
        } else {
            discountHint.classList.add('hidden');
        }
        document.querySelectorAll('#itemsBody .item-row').forEach(function(row) {
            updateRowFinalCost(row);
        });
    }

    discountRateInput.addEventListener('input', applyDiscountToAllRows);

    // 폼 제출 전: hidden 필드 채우기
    document.getElementById('inboundForm').addEventListener('submit', function() {
        var rate = parseFloat(discountRateInput.value) || 0;
        discountRateHidden.value = rate;

        document.querySelectorAll('#itemsBody .item-row').forEach(function(row) {
            var boxQty   = parseFloat(row.querySelector('.row-qty-box')  ? row.querySelector('.row-qty-box').value  : '') || 0;
            var pcsQty   = parseFloat(row.querySelector('.row-qty-pcs')  ? row.querySelector('.row-qty-pcs').value  : '') || 0;
            var pcsPrice = parseFloat(row.querySelector('.row-price-pcs') ? row.querySelector('.row-price-pcs').value : '') || 0;
            var boxPrice = parseFloat(row.querySelector('.row-price-box') ? row.querySelector('.row-price-box').value : '') || 0;

            var unitHid = row.querySelector('input[name="unit[]"]');
            var qtyHid  = row.querySelector('input[name="quantity[]"]');
            var costHid = row.querySelector('input[name="cost_price[]"]');
            var regHid  = row.querySelector('input[name="regular_price[]"]');

            // 행별 Unit select가 단위의 기준 (없으면 BOX 수량 유무로 추론)
            // Design Ref: pack-unit §4 — PACK 선택을 보존하고 묶음(BOX/PACK)은 bundle 칸 사용
            var unitSel = row.querySelector('.row-unit-select');
            var selUnit = unitSel ? unitSel.value : (boxQty > 0 ? 'BOX' : 'PCS');
            var useBundle = (selUnit !== 'PCS');
            if (unitHid) unitHid.value = selUnit;
            if (qtyHid)  qtyHid.value  = useBundle ? boxQty : pcsQty;

            var regularPrice = useBundle ? boxPrice : pcsPrice;
            var finalPrice   = rate > 0 ? regularPrice * (1 - rate / 100) : regularPrice;
            if (regHid)  regHid.value  = regularPrice.toFixed(2);
            if (costHid) costHid.value = finalPrice.toFixed(2);
        });
    });

    // 페이지 로드 시 바코드 입력란 포커스
    barcodeInput.focus();

    // Enter → 다음 필드 이동 (헤더 영역: 공급업체·입고일)
    document.getElementById('inboundForm').addEventListener('keydown', function(e) {
        if (e.key !== 'Enter' || e.target === barcodeInput) return;
        e.preventDefault();

        // 현재 행 안에서 Enter 이동 순서: Expiry Date → QTY(PCS) → QTY(BOX) → PRICE(PCS) → PRICE(BOX) → barcode
        var row = e.target.closest('tr.item-row');
        if (row) {
            var enterSeq = [
                row.querySelector('input[name="expiry_date[]"]'),
                row.querySelector('.row-qty-pcs'),
                row.querySelector('.row-qty-box'),
                row.querySelector('.row-price-pcs'),
                row.querySelector('.row-price-box'),
            ];
            var ci = enterSeq.indexOf(e.target);
            if (ci >= 0 && ci < enterSeq.length - 1) {
                var nxt = enterSeq[ci + 1];
                if (nxt) { nxt.focus(); if (nxt.select) nxt.select(); return; }
            }
            barcodeInput.focus(); barcodeInput.select();
            return;
        }

        // 헤더 필드 (공급업체·입고일)
        var headerFields = Array.from(this.querySelectorAll(
            'select[name="supplier_id"], input[name="inbound_date"]'
        ));
        var hi = headerFields.indexOf(e.target);
        if (hi >= 0 && hi < headerFields.length - 1) {
            headerFields[hi + 1].focus();
        } else {
            barcodeInput.focus();
            barcodeInput.select();
        }
    });

    // ── 유통기한 자동 포맷 (이벤트 위임) ──────────────────────────
    document.getElementById('itemsBody').addEventListener('input', function(e) {
        if (e.target.name !== 'expiry_date[]') return;
        var inp = e.target;
        var digits = inp.value.replace(/\D/g, '').substring(0, 8);
        var v = digits;
        if (digits.length > 6)      v = digits.slice(0,4) + '-' + digits.slice(4,6) + '-' + digits.slice(6);
        else if (digits.length > 4) v = digits.slice(0,4) + '-' + digits.slice(4);
        inp.value = v;
    });
    document.getElementById('itemsBody').addEventListener('blur', function(e) {
        if (e.target.name !== 'expiry_date[]') return;
        var inp = e.target;
        var digits = inp.value.replace(/\D/g, '');
        if (digits.length === 8) {
            inp.value = digits.slice(0,4) + '-' + digits.slice(4,6) + '-' + digits.slice(6);
            inp.classList.remove('border-red-400');
        } else if (digits.length > 0) {
            inp.classList.add('border-red-400');
        }
    }, true);
    document.getElementById('itemsBody').addEventListener('focus', function(e) {
        if (e.target.name !== 'expiry_date[]') return;
        e.target.classList.remove('border-red-400');
    }, true);

    // ── 검증 오류 시 서버에서 복원한 행(.restored-row) 초기화 ──────────
    var firstErrorField = null;
    document.querySelectorAll('#itemsBody .restored-row').forEach(function(row) {
        updateExpiryRequired(row);
        updateRowFinalCost(row);
        highlightRowUnit(row);
        applyUnitQtyVisibility(row);

        // 누락/오류 필드 강조 + 첫 번째 오류 필드로 포커스 이동
        var errField = row.getAttribute('data-error-field');
        if (errField) {
            var fieldInp = row.querySelector('[name="' + errField + '[]"]');
            if (fieldInp) {
                fieldInp.classList.add('border-red-400', 'ring-1', 'ring-red-300');
                if (!firstErrorField) firstErrorField = fieldInp;
            }
        }
    });
    updateRowNums();
    if (firstErrorField) {
        firstErrorField.scrollIntoView({ behavior: 'smooth', block: 'center' });
        firstErrorField.focus();
    }
})();
</script>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
(function() {
    var scanner = null;

    window.openCameraScanner = function() {
        document.getElementById('cameraScanModal').classList.remove('hidden');
        document.getElementById('cameraScanStatus').textContent = 'Point the camera at the barcode';

        scanner = new Html5Qrcode('cameraViewfinder');

        var config = {
            fps: 15,
            qrbox: { width: 250, height: 120 },
            formatsToSupport: [
                Html5QrcodeSupportedFormats.EAN_13,
                Html5QrcodeSupportedFormats.EAN_8,
                Html5QrcodeSupportedFormats.CODE_128,
                Html5QrcodeSupportedFormats.CODE_39,
                Html5QrcodeSupportedFormats.UPC_A,
                Html5QrcodeSupportedFormats.UPC_E,
                Html5QrcodeSupportedFormats.ITF,
                Html5QrcodeSupportedFormats.QR_CODE,
            ]
        };

        scanner.start(
            { facingMode: 'environment' },
            config,
            function(decodedText) {
                document.getElementById('cameraScanStatus').textContent = 'Recognized: ' + decodedText;
                closeCameraScanner();
                var inp = document.getElementById('barcodeInput');
                inp.value = decodedText;
                inp.focus();
                searchBarcode();
            },
            function() {}
        ).catch(function(err) {
            document.getElementById('cameraScanStatus').textContent = 'Camera access error: ' + err;
        });
    };

    window.closeCameraScanner = function() {
        if (scanner) {
            scanner.stop().catch(function() {}).finally(function() {
                scanner = null;
                var el = document.getElementById('cameraViewfinder');
                el.innerHTML = '';
                document.getElementById('cameraScanModal').classList.add('hidden');
            });
        } else {
            document.getElementById('cameraScanModal').classList.add('hidden');
        }
    };

    // ESC 키로 카메라 닫기
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && !document.getElementById('cameraScanModal').classList.contains('hidden')) {
            closeCameraScanner();
        }
    });
})();
</script>

<script>
// ── 인바운드 바코드 미등록 상품 모달 ─────────────────────────────────────────
(function() {
    var pendingBarcode   = '';
    var inbRegQuickType  = '';
    var LC_BASE          = '<?php echo LC_BASE; ?>';
    var csrfToken        = '<?php echo htmlspecialchars(lc_csrf_token()); ?>';
    var _inbBrands = <?php echo json_encode(array_values($brands), JSON_UNESCAPED_UNICODE); ?>;
    var _inbCats   = <?php echo json_encode(array_values($categories), JSON_UNESCAPED_UNICODE); ?>;

    window.openInboundConfirmRegister = function(barcode) {
        // 확인 모달을 건너뛰고 바로 신규 상품 등록 모달을 연다
        pendingBarcode = barcode || '';
        openInboundRegisterModal();
    };

    window.closeInboundConfirmModal = function() {
        document.getElementById('inboundConfirmModal').classList.add('hidden');
    };

    window.openInboundRegisterModal = function() {
        closeInboundConfirmModal();
        document.getElementById('inbReg_name_en').value               = '';
        document.getElementById('inbReg_name_ko').value               = '';
        document.getElementById('inbReg_capacity').value              = '';
        if (window._inbBrandWidget) window._inbBrandWidget.reset();
        else document.getElementById('inbReg_brand_id').value = '';
        if (window._inbCatWidget) window._inbCatWidget.reset();
        else document.getElementById('inbReg_category_id').value = '';
        document.getElementById('inbReg_unit').value                  = 'BOX';
        document.getElementById('inbReg_pieces_per_box').value        = '1';
        document.getElementById('inbReg_barcode_unit').value          = pendingBarcode;
        document.getElementById('inbReg_barcode_box').value           = '';
        document.getElementById('inbReg_barcode_logistics').value     = '';
        document.getElementById('inbReg_min_stock').value             = '0';
        document.getElementById('inbReg_requires_expiry').checked     = false;
        document.getElementById('inbRegShopProductSearch').value      = '';
        document.getElementById('inbRegShopProductDropdown').classList.add('hidden');
        document.getElementById('inboundRegModalError').classList.add('hidden');
        document.getElementById('inboundRegisterModal').classList.remove('hidden');
        document.getElementById('inbReg_name_en').focus();
    };

    window.closeInboundRegisterModal = function() {
        document.getElementById('inboundRegisterModal').classList.add('hidden');
    };

    window.inbRegTranslateToKo = function() {
        var en  = document.getElementById('inbReg_name_en').value.trim();
        var btn = document.getElementById('inbRegTransToKoBtn');
        if (!en) { document.getElementById('inbReg_name_en').focus(); return; }
        btn.textContent = 'Translating…'; btn.disabled = true;
        fetch('https://api.mymemory.translated.net/get?q=' + encodeURIComponent(en) + '&langpair=en|ko')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.responseStatus === 200) {
                    document.getElementById('inbReg_name_ko').value = data.responseData.translatedText;
                }
            })
            .catch(function() {})
            .finally(function() { btn.textContent = 'Translate ▶ Korean'; btn.disabled = false; });
    };

    // 한글 → 영문 발음(로마자) 변환: 음절을 초성/중성/종성으로 분해하여 변환
    var INB_RR_CHO  = ['g','kk','n','d','tt','r','m','b','pp','s','ss','','j','jj','ch','k','t','p','h'];
    var INB_RR_JUNG = ['a','ae','ya','yae','eo','e','yeo','ye','o','wa','wae','oe','yo','u','wo','we','wi','yu','eu','ui','i'];
    var INB_RR_JONG = ['','k','k','k','n','n','n','t','l','k','m','l','l','l','p','l','m','p','p','t','t','ng','t','t','k','t','p','h'];

    function inbRomanizeKorean(text) {
        var out = '';
        for (var i = 0; i < text.length; i++) {
            var code = text.charCodeAt(i);
            if (code >= 0xAC00 && code <= 0xD7A3) {
                var s = code - 0xAC00;
                out += INB_RR_CHO[Math.floor(s / 588)] + INB_RR_JUNG[Math.floor((s % 588) / 28)] + INB_RR_JONG[s % 28];
            } else {
                out += text.charAt(i);
            }
        }
        return out;
    }

    window.inbRegRomanize = function() {
        var koEl = document.getElementById('inbReg_name_ko');
        var enEl = document.getElementById('inbReg_name_en');
        var ko = koEl.value.trim();
        if (!ko) { koEl.focus(); return; }
        enEl.value = inbRomanizeKorean(ko).toUpperCase();
        enEl.focus();
    };

    window.inbRegTranslateToEn = function() {
        var ko  = document.getElementById('inbReg_name_ko').value.trim();
        var btn = document.getElementById('inbRegTransToEnBtn');
        if (!ko) { document.getElementById('inbReg_name_ko').focus(); return; }
        btn.textContent = 'Translating…'; btn.disabled = true;
        fetch('https://api.mymemory.translated.net/get?q=' + encodeURIComponent(ko) + '&langpair=ko|en')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.responseStatus === 200) {
                    document.getElementById('inbReg_name_en').value = data.responseData.translatedText.toUpperCase();
                }
            })
            .catch(function() {})
            .finally(function() { btn.textContent = 'Translate ▶ English'; btn.disabled = false; });
    };

    window.submitInboundRegisterModal = function() {
        var form   = document.getElementById('inbRegModalForm');
        var nameEn = document.getElementById('inbReg_name_en').value.trim();
        var errEl  = document.getElementById('inboundRegModalError');
        if (!nameEn) {
            errEl.textContent = 'English product name is required.';
            errEl.classList.remove('hidden');
            document.getElementById('inbReg_name_en').focus();
            return;
        }

        var fd = new FormData(form);

        fetch(LC_BASE + '/ajax/add_product.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) {
                    errEl.textContent = data.message || 'Failed to save.';
                    errEl.classList.remove('hidden');
                    return;
                }
                closeInboundRegisterModal();

                document.getElementById('barcodeInput').value = '';
                addProductRow(data);
            })
            .catch(function() {
                errEl.textContent = 'Network error. Please try again.';
                errEl.classList.remove('hidden');
            });
    };

    // ── 브랜드/카테고리 빠른 등록 ────────────────────────────────────────────
    // 한글 발음을 영문 표기(로마자)로 변환 (의미 번역 X)
    window.inbRegQuickTranslate = function() {
        var ko   = document.getElementById('inbRegQuickNameKo').value.trim();
        var enEl = document.getElementById('inbRegQuickNameEn');
        if (!ko) { document.getElementById('inbRegQuickNameKo').focus(); return; }
        enEl.value = inbRomanizeKorean(ko).toUpperCase();
        enEl.focus();
    };

    window.openInbRegQuick = function(type) {
        inbRegQuickType = type;
        document.getElementById('inbRegQuickTitle').textContent = type === 'brand' ? 'New Brand' : 'New Category';
        document.getElementById('inbRegQuickNameEn').value = '';
        document.getElementById('inbRegQuickNameKo').value = '';
        document.getElementById('inbRegQuickError').classList.add('hidden');
        document.getElementById('inbRegQuickModal').classList.remove('hidden');
        setTimeout(function() { document.getElementById('inbRegQuickNameEn').focus(); }, 50);
    };

    window.closeInbRegQuick = function() {
        document.getElementById('inbRegQuickModal').classList.add('hidden');
    };

    // ─── 브랜드/카테고리 검색 위젯 ──────────────────────────────────────
    (function() {
        function makeW(cfg) {
            var data = cfg.data;
            var sEl = document.getElementById(cfg.sId), hEl = document.getElementById(cfg.hId);
            var dEl = document.getElementById(cfg.dId), lEl = document.getElementById(cfg.lId);
            var _sid = '', _fi = -1, _filt = [];
            function lb(i) { return i.name_en + (i.name_ko ? ' (' + i.name_ko + ')' : ''); }
            function hl(idx) {
                var lis = lEl.querySelectorAll('li[data-s]');
                Array.prototype.forEach.call(lis, function(li, i) {
                    li.style.backgroundColor = (i === idx) ? '#ccfbf1' : '';
                    li.style.color           = (i === idx) ? '#0f766e' : '';
                    li.style.fontWeight      = (i === idx) ? '600'     : '';
                });
                _fi = idx;
                if (lis[idx]) lis[idx].scrollIntoView({ block: 'nearest' });
            }
            function render(f) {
                lEl.innerHTML = ''; _fi = -1;
                var q = (f||'').toLowerCase();
                _filt = data.filter(function(i){ return !q||lb(i).toLowerCase().indexOf(q)!==-1; });
                if (!_filt.length) { lEl.innerHTML='<li class="px-3 py-2 text-sm text-gray-400">No results</li>'; return; }
                _filt.forEach(function(i) {
                    var li = document.createElement('li');
                    li.className = 'px-3 py-2 text-sm cursor-pointer hover:bg-teal-50 hover:text-teal-700';
                    li.dataset.s = '1';
                    li.addEventListener('mousedown', function(e){ e.preventDefault(); sel(i.id, lb(i)); });
                    // Design Ref: §5.1 — 항목별 인라인 수정 (연필) 부착
                    LcInlineEdit.attach({
                        li: li, item: i, type: cfg.type, csrf: csrfToken,
                        onSaved: function(u) {
                            if (_sid === String(u.id)) { sEl.value = lb(u); hEl.value = u.id; } // Plan SC-2: 선택중이면 라벨 동기화
                            render(sEl.value);
                        }
                    });
                    lEl.appendChild(li);
                });
            }
            function sel(id, label) { _sid=String(id); hEl.value=id; sEl.value=label; dEl.classList.add('hidden'); }
            sEl.addEventListener('focus', function(){ render(sEl.value); dEl.classList.remove('hidden'); });
            sEl.addEventListener('input', function(){ _sid=''; hEl.value=''; render(sEl.value); dEl.classList.remove('hidden'); });
            sEl.addEventListener('blur',  function(){ setTimeout(function(){ if (window.LcInlineEdit && window.LcInlineEdit.editing) return; dEl.classList.add('hidden'); if(!_sid){sEl.value='';hEl.value='';} },150); });
            sEl.addEventListener('keydown', function(e) {
                var open = !dEl.classList.contains('hidden');
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (!open) { render(sEl.value); dEl.classList.remove('hidden'); }
                    if (_filt.length) hl(Math.min(_fi + 1, _filt.length - 1));
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (_fi > 0) hl(_fi - 1);
                } else if (e.key === 'Enter' && open) {
                    e.preventDefault();
                    if (_fi >= 0 && _filt[_fi]) sel(_filt[_fi].id, lb(_filt[_fi]));
                    else dEl.classList.add('hidden');
                } else if (e.key === 'Escape') {
                    dEl.classList.add('hidden');
                }
            });
            return { reset:function(){_sid='';_fi=-1;_filt=[];hEl.value='';sEl.value='';dEl.classList.add('hidden');}, select:sel, data:data };
        }
        window._inbBrandWidget = makeW({ data:_inbBrands, type:'brand',    sId:'inbRegBrandSearch', hId:'inbReg_brand_id', dId:'inbRegBrandDropdown', lId:'inbRegBrandList' });
        window._inbCatWidget   = makeW({ data:_inbCats,   type:'category', sId:'inbRegCatSearch',   hId:'inbReg_category_id', dId:'inbRegCatDropdown', lId:'inbRegCatList' });
    })();

    window.saveInbRegQuick = function() {
        var nameEn = document.getElementById('inbRegQuickNameEn').value.trim();
        var nameKo = document.getElementById('inbRegQuickNameKo').value.trim();
        var errEl  = document.getElementById('inbRegQuickError');
        if (!nameEn) {
            errEl.textContent = 'English name is required.';
            errEl.classList.remove('hidden');
            document.getElementById('inbRegQuickNameEn').focus();
            return;
        }

        var fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('type',       inbRegQuickType);
        fd.append('name_en',    nameEn);
        fd.append('name_ko',    nameKo);

        fetch(LC_BASE + '/ajax/quick_create.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) {
                    errEl.textContent = data.message || 'Failed to save.';
                    errEl.classList.remove('hidden');
                    return;
                }
                var label = data.name_en + (data.name_ko ? ' (' + data.name_ko + ')' : '');
                if (inbRegQuickType === 'brand') {
                    _inbBrands.push({id: data.id, name_en: data.name_en, name_ko: data.name_ko});
                    if (window._inbBrandWidget) window._inbBrandWidget.select(data.id, label);
                } else {
                    _inbCats.push({id: data.id, name_en: data.name_en, name_ko: data.name_ko});
                    if (window._inbCatWidget) window._inbCatWidget.select(data.id, label);
                }
                closeInbRegQuick();
            })
            .catch(function() {
                errEl.textContent = 'Network error. Please try again.';
                errEl.classList.remove('hidden');
            });
    };
})();
</script>

<!-- 기존 상품 가져오기 (신규 상품 등록 모달) -->
<script>
(function() {
    var LC_BASE = '<?php echo LC_BASE; ?>';
    var searchEl = document.getElementById('inbRegShopProductSearch');
    var dropEl   = document.getElementById('inbRegShopProductDropdown');
    var listEl   = document.getElementById('inbRegShopProductList');
    var timer = null;

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    function lbl(item) {
        return item.name_en + (item.name_ko ? ' (' + item.name_ko + ')' : '');
    }

    function render(products) {
        listEl.innerHTML = '';
        if (!products.length) {
            listEl.innerHTML = '<li class="px-3 py-2 text-sm text-gray-400">No results</li>';
            return;
        }
        products.forEach(function(item) {
            var li = document.createElement('li');
            li.className = 'px-3 py-2 text-sm cursor-pointer hover:bg-teal-50 hover:text-teal-700';
            li.innerHTML = '<div class="font-medium">' + escapeHtml(item.name_en) +
                            (item.name_ko ? ' <span class="text-gray-500">(' + escapeHtml(item.name_ko) + ')</span>' : '') + '</div>' +
                            '<div class="text-xs text-gray-400 font-mono">' + escapeHtml(item.sku) +
                            (item.pieces_per_box ? ' &middot; ' + item.pieces_per_box + ' pcs/box' : '') + '</div>';
            li.addEventListener('mousedown', function(e) { e.preventDefault(); applyProduct(item); });
            listEl.appendChild(li);
        });
    }

    function applyProduct(item) {
        var enInput      = document.getElementById('inbReg_name_en');
        var koInput      = document.getElementById('inbReg_name_ko');
        var ppbInput     = document.getElementById('inbReg_pieces_per_box');
        var barcodeInput = document.getElementById('inbReg_barcode_unit');

        if (item.name_en) enInput.value = item.name_en;
        if (item.name_ko) koInput.value = item.name_ko;
        if (item.pieces_per_box) ppbInput.value = item.pieces_per_box;
        if (item.sku && !barcodeInput.value.trim()) {
            barcodeInput.value = item.sku;
        }

        searchEl.value = lbl(item);
        dropEl.classList.add('hidden');
    }

    function search(q) {
        fetch(LC_BASE + '/ajax/search_shop_product.php?q=' + encodeURIComponent(q))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    render(data.products);
                } else {
                    listEl.innerHTML = '<li class="px-3 py-2 text-sm text-gray-400">' + escapeHtml(data.message) + '</li>';
                }
                dropEl.classList.remove('hidden');
            })
            .catch(function() {
                listEl.innerHTML = '<li class="px-3 py-2 text-sm text-red-400">Search failed.</li>';
                dropEl.classList.remove('hidden');
            });
    }

    searchEl.addEventListener('input', function() {
        var q = searchEl.value.trim();
        if (timer) clearTimeout(timer);
        if (!q) { dropEl.classList.add('hidden'); return; }
        timer = setTimeout(function() { search(q); }, 350);
    });

    searchEl.addEventListener('blur', function() {
        setTimeout(function() { dropEl.classList.add('hidden'); }, 150);
    });
})();
</script>

<?php require __DIR__ . '/partials/inline_edit_widget.php'; // Design Ref: §5.4 ?>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
