<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/unit_helper.php';      // Design Ref: box-pcs-unit §4.3
require_once __DIR__ . '/../lib/inventory_helper.php'; // lc_expiry_class()
header('Content-Type: application/json; charset=utf-8');

// Design Ref: inbound-inline-edit - inbound_detail.php 셀별 인라인 수정 통합 엔드포인트
lc_require_staff();

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['lc_csrf'] ?? '', $token)) {
    echo json_encode(['success' => false, 'message' => 'Security error']);
    exit;
}

$inbound_id = (int)($_POST['inbound_id'] ?? 0);
$fields     = json_decode($_POST['fields'] ?? '', true);

$allowed_fields = ['lot_number', 'expiry_date', 'quantity', 'inbound_unit', 'pieces_per_box', 'regular_price', 'discount_rate'];
if (!$inbound_id || !is_array($fields) || empty($fields)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}
foreach (array_keys($fields) as $f) {
    if (!in_array($f, $allowed_fields, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid field: ' . $f]);
        exit;
    }
}

try {
    $conn = get_lc_db();

    $st = $conn->prepare(
        "SELECT i.*, b.is_confirmed, p.requires_expiry
         FROM lc_inbound i
         JOIN lc_inbound_batches b ON i.batch_id = b.id
         JOIN lc_products p ON i.product_id = p.id
         WHERE i.id = ?"
    );
    $st->bind_param('i', $inbound_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        exit;
    }
    if ($row['is_confirmed']) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'This inbound record is locked and cannot be edited.']);
        exit;
    }

    // 현재 값 기준으로 변경분 반영
    $lot_number     = $row['lot_number'];
    $expiry_date    = $row['expiry_date'];
    $quantity       = (int)$row['quantity'];
    $inbound_unit   = lc_valid_unit($row['inbound_unit'], LC_UNIT_PCS);
    $pieces_per_box = max(1, (int)$row['pieces_per_box']);
    $regular_price  = (float)($row['regular_price'] ?? $row['cost_price']);
    $discount_rate  = (float)($row['discount_rate'] ?? 0);

    foreach ($fields as $field => $value) {
        switch ($field) {
            case 'lot_number':
                $lot_number = trim((string)$value) ?: null;
                break;
            case 'expiry_date':
                $v = trim((string)$value);
                if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid date format. Use YYYY-MM-DD.']);
                    exit;
                }
                if ($v === '' && !empty($row['requires_expiry'])) {
                    echo json_encode(['success' => false, 'message' => 'Expiry date is required for this product.']);
                    exit;
                }
                $expiry_date = $v ?: null;
                break;
            case 'quantity':
                $quantity = (int)$value;
                if ($quantity <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Quantity must be at least 1.']);
                    exit;
                }
                break;
            case 'inbound_unit':
                $inbound_unit = lc_valid_unit($value, $inbound_unit);
                break;
            case 'pieces_per_box':
                $pieces_per_box = max(1, (int)$value);
                break;
            case 'regular_price':
                $regular_price = max(0, (float)$value);
                break;
            case 'discount_rate':
                $discount_rate = min(100, max(0, (float)$value));
                break;
        }
    }

    // Design Ref: box-pcs-unit §5.4 — 원가/PCS원가 서버 재계산
    $cost_price = $discount_rate > 0
        ? round($regular_price * (1 - $discount_rate / 100), 2)
        : round($regular_price, 2);
    // Design Ref: pack-unit §4 — 묶음(BOX/PACK) 입고는 낱개원가 환산
    $cost_price_pcs = lc_is_bundle_unit($inbound_unit)
        ? lc_pcs_cost($cost_price, $pieces_per_box)
        : round($cost_price, 4);

    $conn->autocommit(false);

    $st = $conn->prepare(
        "UPDATE lc_inbound SET lot_number=?, expiry_date=?, quantity=?, inbound_unit=?, pieces_per_box=?,
         regular_price=?, discount_rate=?, cost_price=?, cost_price_pcs=? WHERE id=?"
    );
    $st->bind_param('ssisiddddi',
        $lot_number, $expiry_date, $quantity, $inbound_unit, $pieces_per_box,
        $regular_price, $discount_rate, $cost_price, $cost_price_pcs, $inbound_id
    );
    $st->execute(); $st->close();

    // FR-13: lot 단위(lc_inventory)도 일관 갱신
    $st = $conn->prepare(
        "UPDATE lc_inventory SET lot_number=?, expiry_date=?, quantity_in=?, unit=? WHERE inbound_id=?"
    );
    $st->bind_param('ssisi', $lot_number, $expiry_date, $quantity, $inbound_unit, $inbound_id);
    $st->execute(); $st->close();

    $conn->commit(); $conn->close();

    // 화면 표시용 값 구성
    $expiry_display = $expiry_date ? date('d M Y', strtotime($expiry_date)) : '-';
    $expiry_class   = lc_expiry_class($expiry_date);

    $discount_html = $discount_rate > 0
        ? '<span class="inline-block px-1.5 py-0.5 bg-orange-100 text-orange-600 text-xs font-semibold rounded">-' . rtrim(rtrim(number_format($discount_rate, 2), '0'), '.') . '%</span>'
        : '<span class="text-gray-300 text-xs">-</span>';

    $final_cost_html = number_format($cost_price, 2);
    if (lc_is_bundle_unit($inbound_unit) && $cost_price_pcs > 0) {
        $final_cost_html .= '<span class="block text-xs text-gray-400 font-normal">PCS ' . number_format($cost_price_pcs, 2) . '</span>';
    }

    echo json_encode([
        'success'         => true,
        'lot_number'      => $lot_number ?? '',
        'lot_display'     => $lot_number !== null && $lot_number !== '' ? htmlspecialchars($lot_number) : '-',
        'expiry_date'     => $expiry_date ?? '',
        'expiry_display'  => $expiry_display,
        'expiry_class'    => $expiry_class,
        'quantity'        => $quantity,
        'quantity_display'=> number_format($quantity),
        'inbound_unit'    => $inbound_unit,
        'pieces_per_box'  => $pieces_per_box,
        'regular_price'   => $regular_price,
        'regular_display' => number_format($regular_price, 2),
        'discount_rate'   => $discount_rate,
        'discount_html'   => $discount_html,
        'cost_price'      => $cost_price,
        'cost_price_pcs'  => $cost_price_pcs,
        'final_cost_html' => $final_cost_html,
        'subtotal'        => round($quantity * $cost_price, 2),
        'subtotal_display'=> number_format($quantity * $cost_price, 2),
    ]);
} catch (Exception $e) {
    if (isset($conn)) { $conn->rollback(); $conn->close(); }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
