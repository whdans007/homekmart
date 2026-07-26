<?php
/**
 * 일회성 데이터 보정 스크립트
 *
 * 배경: 예전 update_batch_supplier.php 는 배치(kw_inbound_batches)의 supplier_id 만 변경하고
 *       라인아이템(kw_inbound)의 supplier_id 는 갱신하지 않았다.
 *       그 결과 Inbound Items List(라인아이템 기준)에는 이전 거래처가 그대로 남았다.
 *
 * 이 스크립트는 각 라인아이템의 supplier_id 를 소속 배치의 supplier_id 로 맞춘다.
 *
 * 사용법:
 *   - 미리보기(변경 없음):  fix_inbound_supplier_sync.php
 *   - 실제 적용:            fix_inbound_supplier_sync.php?apply=1
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/auth.php';

kw_require_staff();

header('Content-Type: text/plain; charset=utf-8');

$apply = isset($_GET['apply']) && $_GET['apply'] == '1';
$conn  = get_lc_db();
if (!$conn) { echo "DB 연결 실패\n"; exit; }

// 배치 supplier_id 와 라인아이템 supplier_id 가 다른 행 조회
$sql = "
    SELECT i.id AS inbound_id, i.batch_id,
           i.supplier_id AS line_sid, b.supplier_id AS batch_sid,
           sl.name AS line_name, sb.name AS batch_name
    FROM kw_inbound i
    JOIN kw_inbound_batches b ON i.batch_id = b.id
    LEFT JOIN kw_suppliers sl ON i.supplier_id = sl.id
    LEFT JOIN kw_suppliers sb ON b.supplier_id = sb.id
    WHERE NOT (i.supplier_id <=> b.supplier_id)
    ORDER BY i.batch_id, i.id
";
$res = $conn->query($sql);
$rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

echo $apply ? "=== 적용 모드 ===\n" : "=== 미리보기 모드 (변경 없음, 적용하려면 ?apply=1) ===\n";
echo "불일치 라인아이템: " . count($rows) . " 건\n\n";

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
        "UPDATE kw_inbound i
         JOIN kw_inbound_batches b ON i.batch_id = b.id
         SET i.supplier_id = b.supplier_id
         WHERE NOT (i.supplier_id <=> b.supplier_id)"
    );
    echo "\n적용 완료. 변경된 행: " . $conn->affected_rows . " 건\n";
} elseif (!$apply && $rows) {
    echo "\n적용하려면 URL 뒤에 ?apply=1 을 붙여 다시 실행하세요.\n";
} else {
    echo "\n보정할 데이터가 없습니다.\n";
}

$conn->close();
