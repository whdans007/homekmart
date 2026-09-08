<?php
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false]); exit; }

$store_id = get_office_store_id();
$action   = $_POST['action'] ?? '';
$supplier = trim(office_b64_decode($_POST['supplier'] ?? ''));
$pay_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['pay_date'] ?? '') ? $_POST['pay_date'] : null;

if (!$supplier || !$pay_date) {
    echo json_encode(['success'=>false,'error'=>'필수 값이 누락되었습니다.']); exit;
}

// 실제 결제 등록일은 항상 오늘 날짜 (pay_date는 미결 항목을 선택하는 기준일로만 사용)
$paid_today = date('Y-m-d');

$conn = get_db_connection();

// paid_date, receipt_id 컬럼 자동 추가
foreach (['paid_date DATE DEFAULT NULL', 'receipt_id INT UNSIGNED DEFAULT NULL'] as $col_def) {
    $col = explode(' ', $col_def)[0];
    $chk = $conn->query("SHOW COLUMNS FROM deferred_entries LIKE '{$col}'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("ALTER TABLE deferred_entries ADD COLUMN {$col_def}");
    }
}

// 선택 날짜 이전 모든 미결 항목 (이전 달 포함)
$stmt = $conn->prepare(
    "SELECT id, entry_date, amount, notes, entry_type
     FROM deferred_entries
     WHERE store_id=? AND supplier=? AND status='pending' AND entry_date<=?
     ORDER BY entry_date ASC, id ASC"
);
$stmt->bind_param('iss', $store_id, $supplier, $pay_date);
$stmt->execute();
$pending = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── 미리보기 ─────────────────────────────────────────────────
if ($action === 'preview') {
    $items = [];
    $total = 0.0;
    foreach ($pending as $e) {
        $ts      = strtotime($e['entry_date']);
        $label   = date('M j', $ts) . (($e['entry_type'] ?? 'purchase') === 'return' ? ' (Return)' : '');
        $items[] = ['label'=>$label, 'amount'=>(float)$e['amount'], 'notes'=>$e['notes']??''];
        $total  += (float)$e['amount'];
    }
    $conn->close();
    echo json_encode(['success'=>true, 'items'=>$items, 'total'=>$total]);
    exit;
}

// ── 결제 실행 ─────────────────────────────────────────────────
if ($action === 'pay') {
    if (empty($pending)) {
        $conn->close();
        echo json_encode(['success'=>false,'error'=>'미결 항목이 없습니다.']); exit;
    }

    $total = array_sum(array_column($pending, 'amount'));

    // Description: "5월1일 ₱2,000, 5월2일 ₱3,000"
    $parts = [];
    foreach ($pending as $e) {
        $ts      = strtotime($e['entry_date']);
        $label   = date('M', $ts) . '-' . date('d', $ts);
        $parts[] = $label . '(₱' . number_format((float)$e['amount'], 0) . ')';
    }
    $description = implode(', ', $parts);
    $by = (int)($_SESSION['user_id'] ?? 0) ?: null;

    $conn->begin_transaction();
    try {
        // ① office_receipts 등록
        $stmt = $conn->prepare(
            "INSERT INTO office_receipts
             (store_id, supplier_name, description, amount, receipt_date, created_by)
             VALUES (?,?,?,?,?,?)"
        );
        $stmt->bind_param('issdsi', $store_id, $supplier, $description, $total, $paid_today, $by);
        $stmt->execute();
        $receipt_id = $conn->insert_id;
        $stmt->close();

        // ② deferred_entries 완료 처리
        $ids          = array_column($pending, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt2 = $conn->prepare(
            "UPDATE deferred_entries
             SET status='paid', paid_date=?, receipt_id=?
             WHERE id IN ({$placeholders})"
        );
        $params = array_merge([$paid_today, $receipt_id], $ids);
        $stmt2->bind_param('si' . str_repeat('i', count($ids)), ...$params);
        $stmt2->execute();
        $stmt2->close();

        $conn->commit();
        $conn->close();
        echo json_encode(['success'=>true, 'receipt_id'=>$receipt_id, 'total'=>$total, 'count'=>count($pending)]);
    } catch (Throwable $e) {
        $conn->rollback();
        $conn->close();
        echo json_encode(['success'=>false,'error'=>'결제 처리 중 오류: '.$e->getMessage()]);
    }
    exit;
}

$conn->close();
echo json_encode(['success'=>false,'error'=>'Invalid action']);
