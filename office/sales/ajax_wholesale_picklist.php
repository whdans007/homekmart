<?php
// Design Ref: §5.2 / module-3 — 그날치 Whole Sale·Delivery K 후보 조회 (선택용)
// admin이 입력한 내역을 불러와 셀 모달에서 선택 — 직접 입력 아님 (FR-14, FR-18)
require_once __DIR__ . '/../lib/office_helper.php';
require_once __DIR__ . '/lib/pos_recon_helper.php';
require_office_permission();
header('Content-Type: application/json; charset=utf-8');

$store_id  = get_office_store_id();
$date      = $_GET['date'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['success'=>false, 'error'=>'Invalid date', 'items'=>[]]); exit;
}

// 현재 열려있는 셀(shift/pos_no) — 이 셀에서 이미 선택한 항목은 "다른 곳에서 사용됨"으로 취급하지 않는다.
$cur_shift  = (string)($_GET['shift'] ?? '');
$cur_pos    = (int)($_GET['pos_no'] ?? 0);
$has_cur    = pos_valid_shift($cur_shift) && pos_valid_pos($cur_pos);

$items = [];
$conn  = get_db_connection();

// 이 날짜에 이미 다른 셀(shift×pos)에서 선택되어 사용 중인 Whole Sale / 거래명세서 항목 조회
// → 중복 입력 방지를 위해 후보 목록에서 제외 (FR: 사용된 항목은 다른 입력에서 나오면 안 됨)
$used_elsewhere = [];
$sql = "SELECT source_type, source_id FROM sales_pos_wholesale_pick
        WHERE store_id=? AND sale_date=? AND source_type IN ('wholesale','credit_doc','delivery_k')";
if ($has_cur) $sql .= " AND NOT (shift=? AND pos_no=?)";
$stmt = $conn->prepare($sql);
if ($has_cur) {
    $stmt->bind_param('issi', $store_id, $date, $cur_shift, $cur_pos);
} else {
    $stmt->bind_param('is', $store_id, $date);
}
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $used_elsewhere[$row['source_type'] . ':' . $row['source_id']] = true;
}
$stmt->close();

// ① Whole Sale (admin 관리: wholesale_sales)
$stmt = $conn->prepare(
    "SELECT ws.id, ws.final_amount, wc.name AS customer_name
     FROM wholesale_sales ws
     LEFT JOIN wholesale_customers wc ON ws.customer_id = wc.id
     WHERE ws.store_id=? AND ws.sale_date=? AND ws.status != 'cancelled'
     ORDER BY ws.id"
);
$stmt->bind_param('is', $store_id, $date);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    if (isset($used_elsewhere['wholesale:' . $row['id']])) continue; // 다른 셀에서 이미 사용 중 → 중복 입력 방지
    $items[] = [
        'source_type' => 'wholesale',
        'source_id'   => (int)$row['id'],
        'client'      => $row['customer_name'] ?? '',
        'remark'      => 'Whole Sale',
        'amount'      => (float)$row['final_amount'],
    ];
}
$stmt->close();

// ①-2 Delivery K 매출 (sales_daily_items, item_type='delivery_k') → §5 Whole Sale 선택 후보에 합류
$stmt = $conn->prepare(
    "SELECT id, description, amount
     FROM sales_daily_items
     WHERE store_id=? AND sale_date=? AND item_type='delivery_k'
     ORDER BY id"
);
$stmt->bind_param('is', $store_id, $date);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    if (isset($used_elsewhere['delivery_k:' . $row['id']])) continue; // 다른 셀에서 이미 사용 중 → 중복 입력 방지
    $items[] = [
        'source_type' => 'delivery_k',
        'source_id'   => (int)$row['id'],
        'client'      => '',
        'remark'      => $row['description'] !== '' ? $row['description'] : 'Delivery K',
        'amount'      => (float)$row['amount'],
    ];
}
$stmt->close();

// ② Subsidiary Company Credits 후보 = admin 외상 거래명세서(credit_transactions) 그날치 확정분.
//    금액 preset(거래명세서 최종금액) → source_type='credit_doc' 로 저장해 외상거래 현황의
//    POS외상 합계(source_type='credit')와 중복 집계되지 않도록 구분한다. (미등록 수기 외상은 셀의 [Credit] 버튼 사용)
$stmt = $conn->prepare(
    "SELECT ct.id, ct.final_amount, ct.transaction_date, cc.name AS customer_name
     FROM credit_transactions ct
     LEFT JOIN credit_customers cc ON ct.customer_id = cc.id
     WHERE ct.store_id=? AND ct.transaction_date=? AND ct.status='confirmed'
     ORDER BY ct.id"
);
$stmt->bind_param('is', $store_id, $date);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    if (isset($used_elsewhere['credit_doc:' . $row['id']])) continue; // 다른 셀에서 이미 사용 중 → 중복 입력 방지
    $items[] = [
        'source_type' => 'credit_doc',
        'source_id'   => (int)$row['id'],
        'client'      => $row['customer_name'] ?? '',
        'remark'      => $row['customer_name'] ?? '',
        'amount'      => (float)$row['final_amount'],
    ];
}
$stmt->close();

// ③ POS 외상 등록용 거래처 목록 (credit_customers) — [POS] 버튼에서 거래처 선택용.
//    선택 시 source_type='credit', source_id=거래처 id 로 저장 → 외상거래 현황(AR) 거래처 잔액에 반영.
$companies = [];
$stmt = $conn->prepare(
    "SELECT id, name FROM credit_customers
     WHERE store_id=? AND is_active=1 ORDER BY name ASC"
);
$stmt->bind_param('i', $store_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $companies[] = ['id' => (int)$row['id'], 'name' => $row['name'] ?? ''];
}
$stmt->close();
$conn->close();

echo json_encode(['success'=>true, 'date'=>$date, 'items'=>$items, 'companies'=>$companies]);
