<?php
/**
 * 일회성 데이터 보정 스크립트
 *
 * 배경: 예전 update_batch_supplier.php 는 배치(lc_inbound_batches)의 supplier_id 만 변경하고
 *       라인아이템(lc_inbound)의 supplier_id 는 갱신하지 않았다.
 *       그 결과 Inbound Items List(라인아이템 기준)에는 이전 거래처가 그대로 남았다.
 *
 * 이 스크립트는 각 라인아이템의 supplier_id 를 소속 배치의 supplier_id 로 맞춘다.
 *
 * 사용법:
 *   - 미리보기(변경 없음):  fix_inbound_supplier_sync.php
 *   - 실제 적용:            fix_inbound_supplier_sync.php?apply=1
 */
require_once __DIR__ . '/../lib/lang_helper.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/auth.php';

lc_require_staff();

header('Content-Type: text/plain; charset=utf-8');

$apply = isset($_GET['apply']) && $_GET['apply'] == '1';
$conn  = get_lc_db();
if (!$conn) { echo t('logistics.fix_inbound_supplier_sync.db_failed') . "\n"; exit; }

// 배치 supplier_id 와 라인아이템 supplier_id 가 다른 행 조회
$sql = "
    SELECT i.id AS inbound_id, i.batch_id,
           i.supplier_id AS line_sid, b.supplier_id AS batch_sid,
           sl.name AS line_name, sb.name AS batch_name
    FROM lc_inbound i
    JOIN lc_inbound_batches b ON i.batch_id = b.id
    LEFT JOIN lc_suppliers sl ON i.supplier_id = sl.id
    LEFT JOIN lc_suppliers sb ON b.supplier_id = sb.id
    WHERE NOT (i.supplier_id <=> b.supplier_id)
    ORDER BY i.batch_id, i.id
";
$res = $conn->query($sql);
$rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

echo $apply ? t('logistics.fix_inbound_supplier_sync.apply_mode') . "\n" : t('logistics.fix_inbound_supplier_sync.preview_mode') . "\n";
echo t('logistics.fix_inbound_supplier_sync.mismatch_count', ['count' => count($rows)]) . "\n\n";

foreach ($rows as $r) {
    printf(
        "inbound_id=%d  batch_id=%d  라인:[%s] -> 배치:[%s]\n",
        $r['inbound_id'], $r['batch_id'],
        ($r['line_name'] ?? 'NULL') . " #" . ($r['line_sid'] ?? 'NULL'),
        ($r['batch_name'] ?? 'NULL') . " #" . ($r['batch_sid'] ?? 'NULL')
    );
}

if ($apply && $rows) {
    // 라인아이템을 배치 기준으로 일괄 정렬
    $updated = $conn->query(
        "UPDATE lc_inbound i
         JOIN lc_inbound_batches b ON i.batch_id = b.id
         SET i.supplier_id = b.supplier_id
         WHERE NOT (i.supplier_id <=> b.supplier_id)"
    );
echo "\n" . t('logistics.fix_inbound_supplier_sync.applied', ['count' => $conn->affected_rows]) . "\n";
} elseif (!$apply && $rows) {
echo "\n" . t('logistics.fix_inbound_supplier_sync.apply_hint') . "\n";
} else {
echo "\n" . t('logistics.fix_inbound_supplier_sync.no_data') . "\n";
}

$conn->close();
