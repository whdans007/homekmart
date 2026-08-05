<?php
// Design Ref: purchase-from-store-transfer — match_store_transfer_purchase.php에서 확정한 품목들을
// purchases/purchase_items 로 저장. VAT 계산 로직은 admin/add_purchase.php와 동일하게 맞춘다.
// 거래처(supplier)는 발신 점포명 기준으로 자동 생성/재사용한다 (예: "SUNSET (선셋점) 점간이동").
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !has_permission('purchase_management')) {
    echo json_encode(['success' => false, 'error' => '권한이 없습니다.']);
    exit;
}

$transfer_id   = (int)($_POST['transfer_id'] ?? 0);
$purchase_date = $_POST['purchase_date'] ?? '';
$items         = $_POST['items'] ?? [];

if (!$transfer_id || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $purchase_date) || empty($items)) {
    echo json_encode(['success' => false, 'error' => '필수 값이 누락되었습니다.']);
    exit;
}

$conn = get_db_connection();

$stmt = $conn->prepare(
    "SELECT st.id, st.from_store_id, st.to_store_id, st.status, st.converted_purchase_id, fs.name AS from_store_name
     FROM store_transfers st
     LEFT JOIN stores fs ON st.from_store_id = fs.id
     WHERE st.id = ?"
);
$stmt->bind_param('i', $transfer_id);
$stmt->execute();
$transfer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$transfer || $transfer['status'] !== 'confirmed') {
    $conn->close();
    echo json_encode(['success' => false, 'error' => '유효하지 않은 이동 건입니다.']);
    exit;
}
if (!empty($transfer['converted_purchase_id'])) {
    $conn->close();
    echo json_encode(['success' => false, 'error' => '이미 매입등록된 이동 건입니다.']);
    exit;
}

$store_id = (int)$transfer['to_store_id'];
if ($_SESSION['role'] !== 'super_admin') {
    $u_stmt = $conn->prepare("SELECT store_id FROM users WHERE id = ?");
    $u_stmt->bind_param('i', $_SESSION['user_id']);
    $u_stmt->execute();
    $u_row = $u_stmt->get_result()->fetch_assoc();
    $u_stmt->close();
    if ((int)($u_row['store_id'] ?? 0) !== $store_id) {
        $conn->close();
        echo json_encode(['success' => false, 'error' => '권한이 없습니다.']);
        exit;
    }
}

// 발신 점포명 기준 거래처 조회, 없으면 신규 생성 (예: "SUNSET (선셋점) 점간이동")
$from_store_name = trim($transfer['from_store_name'] ?? '') ?: ('점포#' . (int)$transfer['from_store_id']);
$supplier_name   = $from_store_name . ' 점간이동';
$s_stmt = $conn->prepare("SELECT id FROM suppliers WHERE name = ?");
$s_stmt->bind_param('s', $supplier_name);
$s_stmt->execute();
$s_row = $s_stmt->get_result()->fetch_assoc();
$s_stmt->close();

if ($s_row) {
    $supplier_id = (int)$s_row['id'];
} else {
    $ins_s = $conn->prepare("INSERT INTO suppliers (name) VALUES (?)");
    $ins_s->bind_param('s', $supplier_name);
    $ins_s->execute();
    $supplier_id = (int)$conn->insert_id;
    $ins_s->close();
}

$conn->begin_transaction();
try {
    $total_items  = 0;
    $total_amount = 0.0;
    $valid_items  = [];
    foreach ($items as $item) {
        $product_id = (int)($item['product_id'] ?? 0);
        $quantity   = (int)($item['quantity'] ?? 0);
        $unit_price = isset($item['unit_price']) && $item['unit_price'] !== '' ? (float)$item['unit_price'] : null;
        if (!$product_id || !$quantity || $unit_price === null) {
            continue;
        }
        $purchase_type = in_array($item['purchase_type'] ?? '', ['box', 'piece'], true) ? $item['purchase_type'] : 'piece';
        $valid_items[] = [
            'product_id'    => $product_id,
            'purchase_type' => $purchase_type,
            'quantity'      => $quantity,
            'unit_price'    => $unit_price,
        ];
        $total_items  += 1;
        $total_amount += $quantity * $unit_price;
    }

    if (empty($valid_items)) {
        throw new Exception('등록할 품목이 없습니다. 모든 품목의 수량/단가를 확인해주세요.');
    }

    $stmt = $conn->prepare(
        "INSERT INTO purchases (store_id, supplier_id, purchase_date, total_amount, total_items) VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('iisdi', $store_id, $supplier_id, $purchase_date, $total_amount, $total_items);
    $stmt->execute();
    $purchase_id = (int)$conn->insert_id;
    $stmt->close();

    $item_stmt = $conn->prepare(
        "INSERT INTO purchase_items
            (purchase_id, product_id, purchase_type, quantity, unit_price, vat_included, original_unit_price, vat_amount, discount_rate, discounted_unit_price, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $sort_order = 1;
    foreach ($valid_items as $item) {
        // 상품의 VAT 적용 여부 확인 (Ref: admin/add_purchase.php 동일 로직)
        $vat_stmt = $conn->prepare("SELECT is_vat_applicable FROM products WHERE id = ?");
        $vat_stmt->bind_param('i', $item['product_id']);
        $vat_stmt->execute();
        $vat_row = $vat_stmt->get_result()->fetch_assoc();
        $vat_stmt->close();
        $is_vat_applicable = $vat_row ? (int)$vat_row['is_vat_applicable'] : 1;

        // 타점 이동 단가는 VAT 포함 원가로 간주 (매입 등록 화면 기본값과 동일)
        $vat_included     = 1;
        $original_price   = $item['unit_price'];
        $final_unit_price = $original_price;
        $vat_amount       = $is_vat_applicable ? ($original_price - ($original_price / 1.12)) : 0;

        $discount_rate = 0;
        $original_unit_price_calc   = $final_unit_price;
        $discounted_unit_price_calc = $final_unit_price;

        // 필드 순서: purchase_id(i) product_id(i) purchase_type(s) quantity(i) unit_price(d)
        //           vat_included(i) original_unit_price(d) vat_amount(d) discount_rate(d) discounted_unit_price(d) sort_order(i)
        $item_stmt->bind_param(
            'iisididdddi',
            $purchase_id,
            $item['product_id'],
            $item['purchase_type'],
            $item['quantity'],
            $final_unit_price,
            $vat_included,
            $original_unit_price_calc,
            $vat_amount,
            $discount_rate,
            $discounted_unit_price_calc,
            $sort_order
        );
        if (!$item_stmt->execute()) {
            throw new Exception('품목 저장 실패: ' . $item_stmt->error);
        }
        $sort_order++;
    }
    $item_stmt->close();

    $mark_stmt = $conn->prepare("UPDATE store_transfers SET converted_purchase_id = ? WHERE id = ?");
    $mark_stmt->bind_param('ii', $purchase_id, $transfer_id);
    $mark_stmt->execute();
    $mark_stmt->close();

    $conn->commit();
    $conn->close();
    echo json_encode(['success' => true, 'purchase_id' => $purchase_id]);
} catch (Exception $e) {
    $conn->rollback();
    $conn->close();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
