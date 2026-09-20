<?php
/**
 * 도매 판매 반품 이력 원장 백필
 *
 * 기존 도매 반품은 반품 테이블과 inventory_transactions에는 남아 있지만
 * 공통 inventory_ledger에 누락된 경우가 있어 이력만 보충한다.
 *
 * 주의: 이 스크립트는 inventory 수량을 변경하지 않는다.
 * 신규 반품 처리 경로는 inventory_apply_delta_pdo()가 이미 재고와 원장을
 * 함께 반영하므로, UNIQUE 키를 이용해 중복 기록을 건너뛴다.
 *
 * CLI: php run_backfill_wholesale_return_ledger.php
 * 웹: POST action=run
 */
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../lib/inventory_ledger.php';

$is_cli = PHP_SAPI === 'cli';
$should_run = $is_cli || (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'run');
$result = null;
$error = null;

if ($should_run) {
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $sql = "
            INSERT IGNORE INTO inventory_ledger
                (store_id, product_id, quantity_change, quantity_after, event_type,
                 source_type, source_id, reference_history_id, occurred_at, user_id, remarks)
            SELECT
                ws.store_id,
                wsi.product_id,
                CASE
                    WHEN wsi.sale_unit = 'box'
                        THEN ROUND(wsri.quantity * GREATEST(COALESCE(p.pieces_per_box, 1), 1), 2)
                    ELSE ROUND(wsri.quantity, 2)
                END AS quantity_change,
                COALESCE(inv.quantity, 0) AS quantity_after,
                'RETURN_IN',
                'wholesale_sale_return_item',
                wsri.id,
                NULL,
                COALESCE(wsri.created_at, wsr.created_at),
                wsr.processed_by,
                CONCAT('도매 반품 이력 백필 (Return ID: ', wsr.id, ', Sale ID: ', wsr.sale_id, ')')
            FROM wholesale_sale_return_items wsri
            INNER JOIN wholesale_sale_returns wsr ON wsr.id = wsri.return_id
            INNER JOIN wholesale_sales ws ON ws.id = wsr.sale_id
            INNER JOIN wholesale_sale_items wsi ON wsi.id = wsri.sale_item_id
            LEFT JOIN products p ON p.id = wsi.product_id
            LEFT JOIN inventory inv ON inv.product_id = wsi.product_id AND inv.store_id = ws.store_id
            WHERE wsri.restocked = 1
              AND wsi.product_id IS NOT NULL
        ";

        $pdo->exec($sql);
        $result = [
            'inserted' => $pdo->query("SELECT ROW_COUNT()")->fetchColumn(),
            'total_return_items' => $pdo->query("SELECT COUNT(*) FROM wholesale_sale_return_items WHERE restocked = 1")->fetchColumn(),
        ];
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($is_cli) {
    if ($error !== null) {
        fwrite(STDERR, "ERROR: {$error}\n");
        exit(1);
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}
?>
<!doctype html>
<html lang="ko">
<head><meta charset="utf-8"><title>도매 반품 원장 백필</title></head>
<body style="font-family: sans-serif; padding: 2rem">
<h1>도매 반품 원장 백필</h1>
<p>기존 반품의 inventory_ledger 이력만 추가하며 재고 수량은 변경하지 않습니다.</p>
<?php if ($error !== null): ?>
    <p style="color:#b91c1c">오류: <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php elseif ($result !== null): ?>
    <p style="color:#047857">완료: <?= htmlspecialchars((string)$result['inserted'], ENT_QUOTES, 'UTF-8') ?>건 추가 / 대상 반품 <?= htmlspecialchars((string)$result['total_return_items'], ENT_QUOTES, 'UTF-8') ?>건</p>
<?php endif; ?>
<form method="post"><input type="hidden" name="action" value="run"><button type="submit">실행</button></form>
</body>
</html>
