<?php
// ============================================================
// 상품 안전 물리삭제 스크립트 (참조 0건일 때만 삭제)
// 미리보기: http://main.homekmart.net/kimsmall_wherehouse/sql/run_delete_product.php?id=829
// 삭제는 화면의 [삭제 실행] 버튼(POST)을 눌러야만 수행됩니다.
//
// 안전장치:
//   1) 입고/재고/주문/개봉이력 중 하나라도 있으면 삭제 거부
//   2) DB FK(RESTRICT)가 2차 방어 (변경이력은 CASCADE로 자동 삭제)
//   3) 트랜잭션으로 처리
// ============================================================
require_once __DIR__ . '/../config/db.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$do_delete = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete');

$blocking = ['inbound_cnt', 'inventory_lots', 'order_items_cnt', 'box_break_cnt'];

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function fetch_product_and_counts(mysqli $conn, int $id): ?array {
    $st = $conn->prepare("SELECT id, name_en, name_ko, is_active, barcode_unit, barcode_box, barcode_logistics
                          FROM kw_products WHERE id = ?");
    $st->bind_param('i', $id);
    $st->execute();
    $p = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$p) return null;

    $cs = $conn->prepare("SELECT
        (SELECT COUNT(*) FROM kw_inbound      WHERE product_id = ?) AS inbound_cnt,
        (SELECT COUNT(*) FROM kw_inventory    WHERE product_id = ?) AS inventory_lots,
        (SELECT COUNT(*) FROM kw_order_items  WHERE product_id = ?) AS order_items_cnt,
        (SELECT COUNT(*) FROM kw_box_breaks   WHERE product_id = ?) AS box_break_cnt,
        (SELECT COUNT(*) FROM kw_product_history WHERE product_id = ?) AS history_cnt");
    $cs->bind_param('iiiii', $id, $id, $id, $id, $id);
    $cs->execute();
    $c = $cs->get_result()->fetch_assoc();
    $cs->close();

    return ['product' => $p, 'counts' => $c];
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title>상품 안전 삭제</title>
<style>
  body { font-family: -apple-system, 'Malgun Gothic', sans-serif; margin: 24px; color: #1f2937; max-width: 720px; }
  h1 { font-size: 20px; margin: 0 0 16px; }
  table { border-collapse: collapse; width: 100%; font-size: 13px; margin-bottom: 20px; }
  th, td { border: 1px solid #e5e7eb; padding: 8px 10px; text-align: left; }
  th { background: #f9fafb; width: 140px; color: #374151; }
  .box { padding: 14px; border-radius: 8px; font-size: 14px; line-height: 1.6; margin-bottom: 16px; }
  .ok { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
  .block { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
  .done { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }
  .err { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
  button { border: 0; background: #dc2626; color: #fff; padding: 9px 18px; border-radius: 6px; font-size: 14px; cursor: pointer; }
  button.gray { background: #e5e7eb; color: #374151; }
  a { color: #0d9488; }
</style>
</head>
<body>
<h1>상품 안전 삭제 <span style="color:#6b7280">(참조 0건일 때만)</span></h1>

<?php
try {
    $conn = get_lc_db();

    if ($id <= 0) {
        echo '<div class="box block">삭제할 상품 ID가 지정되지 않았습니다. 예: <code>?id=829</code></div>';
        $conn->close(); echo '</body></html>'; exit;
    }

    $data = fetch_product_and_counts($conn, $id);
    if (!$data) {
        echo '<div class="box block">해당 ID의 상품을 찾을 수 없습니다: <strong>' . $id . '</strong> (이미 삭제되었을 수 있습니다)</div>';
        $conn->close(); echo '</body></html>'; exit;
    }

    $p = $data['product'];
    $c = $data['counts'];
    $blockSum = 0;
    foreach ($blocking as $k) { $blockSum += (int)$c[$k]; }
    $canDelete = ($blockSum === 0);

    // 상품 정보 표
    ?>
    <table>
      <tr><th>ID</th><td><?php echo (int)$p['id']; ?></td></tr>
      <tr><th>상품명</th><td><?php echo h($p['name_en']); ?><?php echo $p['name_ko'] ? ' (' . h($p['name_ko']) . ')' : ''; ?></td></tr>
      <tr><th>상태</th><td><?php echo $p['is_active'] ? 'Active' : 'Inactive'; ?></td></tr>
      <tr><th>Unit / Box / Logistics BC</th><td><?php echo h($p['barcode_unit'] ?: '-') . ' / ' . h($p['barcode_box'] ?: '-') . ' / ' . h($p['barcode_logistics'] ?: '-'); ?></td></tr>
      <tr><th>입고 / 재고로트 / 주문 / 개봉이력</th>
          <td><?php echo (int)$c['inbound_cnt'] . ' / ' . (int)$c['inventory_lots'] . ' / ' . (int)$c['order_items_cnt'] . ' / ' . (int)$c['box_break_cnt']; ?></td></tr>
      <tr><th>변경이력(자동삭제)</th><td><?php echo (int)$c['history_cnt']; ?> 건</td></tr>
    </table>
    <?php

    if ($do_delete) {
        // ── 실제 삭제 수행 ──
        if (!$canDelete) {
            echo '<div class="box block">⛔ 참조 데이터가 있어 삭제할 수 없습니다. (안전장치 작동)</div>';
        } else {
            $conn->begin_transaction();
            try {
                // history 는 FK CASCADE 로 자동 삭제되지만, 명시적으로도 처리(안전)
                $d1 = $conn->prepare("DELETE FROM kw_product_history WHERE product_id = ?");
                $d1->bind_param('i', $id); $d1->execute(); $d1->close();

                $d2 = $conn->prepare("DELETE FROM kw_products WHERE id = ?");
                $d2->bind_param('i', $id); $d2->execute();
                $affected = $d2->affected_rows; $d2->close();

                $conn->commit();
                echo '<div class="box done">✅ 삭제 완료 — 상품 ID <strong>' . $id . '</strong> (' . $affected . '행) 및 변경이력이 삭제되었습니다.</div>';
                echo '<a href="' . h(LC_BASE) . '/products.php">← 상품 목록으로</a>';
            } catch (Throwable $t) {
                $conn->rollback();
                echo '<div class="box err">[ERROR] 삭제 실패(롤백됨): ' . h($t->getMessage()) . '</div>';
            }
        }
    } else {
        // ── 미리보기 + 확인 폼 ──
        if ($canDelete) {
            echo '<div class="box ok">✅ 참조 데이터가 없어 안전하게 삭제할 수 있습니다. 아래 버튼을 누르면 <strong>영구 삭제</strong>됩니다(되돌릴 수 없음).</div>';
            ?>
            <form method="post" onsubmit="return confirm('정말 상품 ID <?php echo $id; ?> 을(를) 영구 삭제할까요?');">
              <input type="hidden" name="id" value="<?php echo $id; ?>">
              <input type="hidden" name="action" value="delete">
              <button type="submit">🗑 삭제 실행</button>
              <a href="<?php echo h(LC_BASE); ?>/products.php" style="margin-left:12px;">취소</a>
            </form>
            <?php
        } else {
            echo '<div class="box block">⛔ 입고·재고·주문·개봉이력과 연결되어 있어 삭제할 수 없습니다. 재고 이관 후 삭제하거나 비활성 처리하세요.</div>';
        }
    }

    $conn->close();
} catch (Exception $e) {
    echo '<div class="box err">[ERROR] ' . h($e->getMessage()) . '</div>';
}
?>
</body>
</html>
