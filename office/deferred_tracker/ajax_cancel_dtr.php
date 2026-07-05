<?php
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false]); exit; }

$store_id = get_office_store_id();
$action   = $_POST['action'] ?? '';
$supplier = trim($_POST['supplier'] ?? '');
$year     = (int)($_POST['year']  ?? date('Y'));
$month    = (int)($_POST['month'] ?? date('n'));

if (!$supplier) {
    echo json_encode(['success'=>false,'error'=>'업체명이 필요합니다.']); exit;
}

$conn = get_db_connection();

// ── preview: 결제 건별 배치 목록 반환 ────────────────────────
if ($action === 'preview') {
    $stmt = $conn->prepare(
        "SELECT id, entry_date, amount, notes, paid_date, receipt_id, file_path, file_mime
         FROM deferred_entries
         WHERE store_id=? AND supplier=? AND status='paid'
           AND YEAR(paid_date)=? AND MONTH(paid_date)=?
         ORDER BY paid_date ASC, receipt_id ASC, entry_date ASC, id ASC"
    );
    $stmt->bind_param('isii', $store_id, $supplier, $year, $month);
    $stmt->execute();
    $paid_entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();

    // receipt_id 있으면 receipt_id 기준, 없으면 paid_date 기준으로 그룹핑
    $batches = [];
    foreach ($paid_entries as $e) {
        $key = $e['receipt_id'] ? 'r_'.$e['receipt_id'] : 'd_'.($e['paid_date'] ?? 'unknown');
        if (!isset($batches[$key])) {
            $batches[$key] = [
                'key'        => $key,
                'receipt_id' => $e['receipt_id'] ? (int)$e['receipt_id'] : null,
                'paid_date'  => $e['paid_date'],
                'items'      => [],
                'total'      => 0.0,
            ];
        }
        $ts = strtotime($e['entry_date']);
        $batches[$key]['items'][] = [
            'id'        => (int)$e['id'],
            'label'     => date('M j', $ts),
            'amount'    => (float)$e['amount'],
            'notes'     => $e['notes'] ?? '',
            'file_path' => $e['file_path'] ?? null,
            'file_mime' => $e['file_mime'] ?? null,
        ];
        $batches[$key]['total'] += (float)$e['amount'];
    }

    echo json_encode(['success'=>true, 'batches'=>array_values($batches)]);
    exit;
}

// ── cancel: 특정 배치(IDs)만 취소 ────────────────────────────
if ($action === 'cancel') {
    $raw_ids = json_decode($_POST['ids'] ?? '[]', true);
    $ids     = array_map('intval', (array)$raw_ids);
    $ids     = array_filter($ids);

    if (empty($ids)) {
        $conn->close();
        echo json_encode(['success'=>false,'error'=>'취소할 항목 ID가 없습니다.']); exit;
    }

    // 해당 IDs의 receipt_id 조회
    $ph   = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare(
        "SELECT id, receipt_id FROM deferred_entries
         WHERE id IN ({$ph}) AND store_id=? AND status='paid'"
    );
    $params = array_merge($ids, [$store_id]);
    $stmt->bind_param(str_repeat('i', count($ids)).'i', ...$params);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($target)) {
        $conn->close();
        echo json_encode(['success'=>false,'error'=>'취소 가능한 항목이 없습니다.']); exit;
    }

    $confirmed_ids = array_column($target, 'id');
    $receipt_ids   = array_filter(array_unique(array_column($target, 'receipt_id')));
    $ph2           = implode(',', array_fill(0, count($confirmed_ids), '?'));

    $conn->begin_transaction();
    try {
        // 항목을 pending으로 초기화
        $stmt = $conn->prepare(
            "UPDATE deferred_entries
             SET status='pending', paid_date=NULL, receipt_id=NULL
             WHERE id IN ({$ph2}) AND store_id=?"
        );
        $p = array_merge($confirmed_ids, [$store_id]);
        $stmt->bind_param(str_repeat('i', count($confirmed_ids)).'i', ...$p);
        $stmt->execute();
        $stmt->close();

        // 해당 영수증의 남은 연결 항목이 없으면 영수증 삭제
        $deleted_receipts = 0;
        foreach ($receipt_ids as $rid) {
            $chk = $conn->prepare(
                "SELECT COUNT(*) AS cnt FROM deferred_entries
                 WHERE receipt_id=? AND store_id=?"
            );
            $chk->bind_param('ii', $rid, $store_id);
            $chk->execute();
            $cnt = (int)$chk->get_result()->fetch_assoc()['cnt'];
            $chk->close();
            if ($cnt === 0) {
                $conn->query("DELETE FROM office_receipts WHERE id={$rid} AND store_id={$store_id}");
                $deleted_receipts++;
            }
        }

        $conn->commit();
        $conn->close();
        echo json_encode([
            'success'          => true,
            'count'            => count($confirmed_ids),
            'deleted_receipts' => $deleted_receipts,
        ]);
    } catch (Throwable $e) {
        $conn->rollback();
        $conn->close();
        echo json_encode(['success'=>false,'error'=>'결제 취소 중 오류: '.$e->getMessage()]);
    }
    exit;
}

$conn->close();
echo json_encode(['success'=>false,'error'=>'Invalid action']);
