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
$year     = (int)($_POST['year']  ?? date('Y'));
$month    = (int)($_POST['month'] ?? date('n'));

if (!$supplier) {
    echo json_encode(['success'=>false,'error'=>'업체명이 필요합니다.']); exit;
}

$conn = get_db_connection();

// ── preview: 결제 건별 배치 목록 반환 ────────────────────────
// 이연결제 특성상 항목의 entry_date와 실제 paid_date(결제 실행일)의 월이 다른 경우가
// 흔해서(예: 8월분을 9월에 결제), History는 현재 보고 있는 달력 페이지와 무관하게
// 해당 업체의 결제 완료 배치를 전체 기간에서 최신순으로 보여준다.
if ($action === 'preview') {
    $stmt = $conn->prepare(
        "SELECT id, entry_date, amount, notes, paid_date, receipt_id, file_path, file_mime
         FROM deferred_entries
         WHERE store_id=? AND supplier=? AND status='paid'
         ORDER BY paid_date DESC, receipt_id DESC, entry_date ASC, id ASC"
    );
    $stmt->bind_param('is', $store_id, $supplier);
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

// ── 결제일 수기 변경 ──────────────────────────────────────────
if ($action === 'update_paid_date') {
    $raw_ids  = json_decode($_POST['ids'] ?? '[]', true);
    $ids      = array_filter(array_map('intval', (array)$raw_ids));
    $new_date = $_POST['new_date'] ?? '';

    if (empty($ids) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_date)) {
        $conn->close();
        echo json_encode(['success'=>false,'error'=>'필수 값이 누락되었거나 날짜 형식이 올바르지 않습니다.']); exit;
    }

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
        echo json_encode(['success'=>false,'error'=>'변경할 수 있는 항목이 없습니다.']); exit;
    }

    $confirmed_ids = array_column($target, 'id');
    $receipt_ids   = array_filter(array_unique(array_column($target, 'receipt_id')));
    $ph2 = implode(',', array_fill(0, count($confirmed_ids), '?'));

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            "UPDATE deferred_entries SET paid_date=? WHERE id IN ({$ph2}) AND store_id=?"
        );
        $p = array_merge([$new_date], $confirmed_ids, [$store_id]);
        $stmt->bind_param('s' . str_repeat('i', count($confirmed_ids)) . 'i', ...$p);
        $stmt->execute();
        $stmt->close();

        // 같은 영수증(receipt_id)에 연결된 다른 항목도 있을 수 있으므로, 영수증 날짜도 맞춰준다
        foreach ($receipt_ids as $rid) {
            $stmt = $conn->prepare(
                "UPDATE office_receipts SET receipt_date=? WHERE id=? AND store_id=?"
            );
            $stmt->bind_param('sii', $new_date, $rid, $store_id);
            $stmt->execute();
            $stmt->close();
        }

        $conn->commit();
        $conn->close();
        echo json_encode(['success'=>true, 'count'=>count($confirmed_ids)]);
    } catch (Throwable $e) {
        $conn->rollback();
        $conn->close();
        echo json_encode(['success'=>false,'error'=>'결제일 변경 중 오류: '.$e->getMessage()]);
    }
    exit;
}

$conn->close();
echo json_encode(['success'=>false,'error'=>'Invalid action']);
