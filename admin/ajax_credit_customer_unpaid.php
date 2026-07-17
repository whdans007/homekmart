<?php
/**
 * 거래처의 미결제(잔액 남은) 외상거래 목록 조회 (거래명세서 + POS 외상 통합, FIFO 잔액 계산)
 * 외상거래 목록 화면의 "수금 입력" 모달에서, 결제할 건을 체크해서 선택할 수 있도록 제공.
 * credit_transactions.php 의 월별 집계/FIFO 로직과 동일한 기준(선택한 달, 매월 리셋)을 사용.
 */
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit;
}

if (!has_permission('wholesale_management')) {
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit;
}

$customer_id = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
$sel_month   = $_GET['month'] ?? $_POST['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $sel_month)) { $sel_month = date('Y-m'); }

if ($customer_id <= 0) {
    echo json_encode(['success' => false, 'message' => '거래처를 선택해주세요.']);
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 거래처 확인 및 점포 권한 체크
    $cust_stmt = $pdo->prepare("SELECT id, store_id FROM credit_customers WHERE id = ? AND is_active = 1");
    $cust_stmt->execute([$customer_id]);
    $customer = $cust_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$customer) {
        echo json_encode(['success' => false, 'message' => '거래처를 찾을 수 없습니다.']);
        exit;
    }
    if (($_SESSION['role'] ?? '') !== 'super_admin') {
        $store_id = $_SESSION['store_id'] ?? null;
        if ($store_id != $customer['store_id']) {
            echo json_encode(['success' => false, 'message' => '해당 거래처에 대한 권한이 없습니다.']);
            exit;
        }
    }

    $month_start = $sel_month . '-01';
    $month_end   = date('Y-m-t', strtotime($month_start));

    $has_pos = false;
    try { $has_pos = (bool)$pdo->query("SHOW TABLES LIKE 'sales_pos_wholesale_pick'")->fetchColumn(); } catch (PDOException $e) { $has_pos = false; }

    // 1) 거래명세서 (해당 거래처, 해당 달)
    $rows = [];
    $st = $pdo->prepare("
        SELECT ct.id, ct.transaction_date AS tdate, ct.final_amount AS amount, ct.created_at,
               (SELECT COUNT(*) FROM credit_transaction_items cti WHERE cti.transaction_id = ct.id) AS item_count
        FROM credit_transactions ct
        WHERE ct.status != 'cancelled' AND ct.customer_id = ? AND ct.transaction_date BETWEEN ? AND ?
    ");
    $st->execute([$customer_id, $month_start, $month_end]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = [
            'source' => 'doc', 'id' => (int)$r['id'], 'tdate' => $r['tdate'], 'created_at' => $r['created_at'],
            'amount' => (float)$r['amount'], 'item_count' => (int)$r['item_count'],
        ];
    }

    // 2) POS 외상 (해당 거래처, 해당 달)
    if ($has_pos) {
        $sp = $pdo->prepare("
            SELECT wp.id, wp.sale_date AS tdate, wp.amount, wp.shift, wp.pos_no, wp.created_at
            FROM sales_pos_wholesale_pick wp
            WHERE wp.source_type='credit' AND wp.source_id = ? AND wp.sale_date BETWEEN ? AND ?
        ");
        $sp->execute([$customer_id, $month_start, $month_end]);
        foreach ($sp->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [
                'source' => 'pos', 'id' => (int)$r['id'], 'tdate' => $r['tdate'], 'created_at' => $r['created_at'],
                'amount' => (float)$r['amount'], 'item_count' => null,
                'label' => 'POS ' . strtoupper((string)$r['shift']) . '·' . (int)$r['pos_no'],
            ];
        }
    }

    // 이 거래처의 해당 달 수금액 (FIFO 충당 기준)
    $paid_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM credit_payments WHERE customer_id = ? AND payment_date BETWEEN ? AND ?");
    $paid_stmt->execute([$customer_id, $month_start, $month_end]);
    $paid_total = (float)$paid_stmt->fetchColumn();

    // 오래된 순 정렬 후 FIFO 충당 계산
    usort($rows, function($a, $b) {
        if ($a['tdate'] !== $b['tdate']) return strcmp($a['tdate'], $b['tdate']);
        return strcmp((string)$a['created_at'], (string)$b['created_at']);
    });
    $accum = 0.0;
    $items = [];
    foreach ($rows as $r) {
        $applied   = max(0.0, min($r['amount'], $paid_total - $accum));
        $remaining = round($r['amount'] - $applied, 2);
        $accum    += $r['amount'];
        if ($remaining > 0.005) {
            $items[] = [
                'source'      => $r['source'],
                'id'          => $r['id'],
                'tdate'       => $r['tdate'],
                'amount'      => $r['amount'],
                'remaining'   => $remaining,
                'item_count'  => $r['item_count'],
                'label'       => $r['label'] ?? null,
            ];
        }
    }
    // 최신순으로 반환 (화면 표시용)
    $items = array_reverse($items);

    echo json_encode([
        'success'         => true,
        'items'           => $items,
        'total_remaining' => round(array_sum(array_column($items, 'remaining')), 2),
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => '데이터베이스 오류: ' . $e->getMessage()]);
}
