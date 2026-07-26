<?php
// ============================================================
// 진단(읽기 전용) 실행 스크립트: 중복 바코드 상품 + 참조 데이터 현황
// 브라우저 실행 예: http://main.homekmart.net/kimsmall_wherehouse/sql/run_diag_duplicate_barcode.php?barcode=18801046433383
// ⚠ SELECT 만 수행하며 데이터를 변경하지 않습니다.
// ============================================================
require_once __DIR__ . '/../config/db.php';

$barcode = trim($_GET['barcode'] ?? '18801046433383');

// 참조 테이블(FK 규칙): 물리삭제 차단 여부 판단용
//   RESTRICT → 건수 > 0 이면 DELETE 불가
//   CASCADE  → 상품 삭제 시 자동 삭제(참고용 표시만)
$blocking = ['inbound_cnt', 'inventory_lots', 'order_items_cnt', 'box_break_cnt'];

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title>중복 바코드 진단</title>
<style>
  body { font-family: -apple-system, 'Malgun Gothic', sans-serif; margin: 24px; color: #1f2937; }
  h1 { font-size: 20px; margin: 0 0 4px; }
  .sub { color: #6b7280; font-size: 13px; margin-bottom: 20px; }
  form { margin-bottom: 20px; }
  input[type=text] { border: 1px solid #d1d5db; border-radius: 6px; padding: 6px 10px; font-size: 14px; width: 240px; }
  button { border: 0; background: #0d9488; color: #fff; padding: 7px 14px; border-radius: 6px; font-size: 14px; cursor: pointer; }
  table { border-collapse: collapse; width: 100%; font-size: 13px; margin-bottom: 24px; }
  th, td { border: 1px solid #e5e7eb; padding: 8px 10px; text-align: left; }
  th { background: #f9fafb; font-weight: 600; color: #374151; }
  td.num { text-align: right; font-variant-numeric: tabular-nums; }
  .active { color: #15803d; font-weight: 600; }
  .inactive { color: #6b7280; }
  .hl { background: #fef2f2; }
  .verdict-ok { color: #15803d; font-weight: 700; }
  .verdict-block { color: #b91c1c; font-weight: 700; }
  .err { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; padding: 12px; border-radius: 8px; }
  .note { background: #f0f9ff; border: 1px solid #bae6fd; color: #075985; padding: 12px; border-radius: 8px; font-size: 13px; line-height: 1.6; }
</style>
</head>
<body>
<h1>중복 바코드 진단 <span style="color:#6b7280">(읽기 전용)</span></h1>
<div class="sub">동일 바코드로 등록된 상품과, 각 상품의 재고·입고·주문·개봉 이력 연결 건수를 확인합니다.</div>

<form method="get">
  <input type="text" name="barcode" value="<?php echo h($barcode); ?>" placeholder="바코드 입력">
  <button type="submit">조회</button>
</form>

<?php
try {
    $conn = get_lc_db();

    // 1) 동일 바코드 상품 목록
    $sql = "SELECT id, name_en, name_ko, is_active, barcode_unit, barcode_box, barcode_logistics, created_at
            FROM kw_products
            WHERE barcode_unit = ? OR barcode_box = ? OR barcode_logistics = ?
            ORDER BY is_active DESC, id";
    $st = $conn->prepare($sql);
    $st->bind_param('sss', $barcode, $barcode, $barcode);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    if (!$rows) {
        echo '<div class="note">해당 바코드로 등록된 상품이 없습니다: <strong>' . h($barcode) . '</strong></div>';
        $conn->close();
        echo '</body></html>';
        exit;
    }

    // 참조 건수 조회 (상품별)
    $cntSql = "SELECT
        (SELECT COUNT(*) FROM kw_inbound      WHERE product_id = ?) AS inbound_cnt,
        (SELECT COUNT(*) FROM kw_inventory    WHERE product_id = ?) AS inventory_lots,
        (SELECT COALESCE(SUM(quantity_remain),0) FROM kw_inventory WHERE product_id = ?) AS stock_remain,
        (SELECT COUNT(*) FROM kw_order_items  WHERE product_id = ?) AS order_items_cnt,
        (SELECT COUNT(*) FROM kw_box_breaks   WHERE product_id = ?) AS box_break_cnt,
        (SELECT COUNT(*) FROM kw_product_history WHERE product_id = ?) AS history_cnt";
    $cntStmt = $conn->prepare($cntSql);
    ?>
    <table>
      <thead><tr>
        <th>ID</th><th>상품명</th><th>상태</th>
        <th>Unit BC</th><th>Box BC</th><th>Logistics BC</th>
        <th class="num">입고</th><th class="num">재고 로트</th><th class="num">현재고</th>
        <th class="num">주문</th><th class="num">개봉이력</th><th class="num">변경이력</th>
        <th>판정</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $p):
          $pid = (int)$p['id'];
          $cntStmt->bind_param('iiiiii', $pid, $pid, $pid, $pid, $pid, $pid);
          $cntStmt->execute();
          $c = $cntStmt->get_result()->fetch_assoc();

          $blockSum = 0;
          foreach ($blocking as $k) { $blockSum += (int)$c[$k]; }
          $canDelete = ($blockSum === 0);
          $rowCls = !$p['is_active'] ? 'hl' : '';
      ?>
      <tr class="<?php echo $rowCls; ?>">
        <td><?php echo $pid; ?></td>
        <td><?php echo h($p['name_en']); ?><?php echo $p['name_ko'] ? ' <span style="color:#9ca3af">(' . h($p['name_ko']) . ')</span>' : ''; ?></td>
        <td class="<?php echo $p['is_active'] ? 'active' : 'inactive'; ?>"><?php echo $p['is_active'] ? 'Active' : 'Inactive'; ?></td>
        <td><?php echo h($p['barcode_unit'] ?: '-'); ?></td>
        <td><?php echo h($p['barcode_box'] ?: '-'); ?></td>
        <td><?php echo h($p['barcode_logistics'] ?: '-'); ?></td>
        <td class="num"><?php echo (int)$c['inbound_cnt']; ?></td>
        <td class="num"><?php echo (int)$c['inventory_lots']; ?></td>
        <td class="num"><?php echo number_format((float)$c['stock_remain']); ?></td>
        <td class="num"><?php echo (int)$c['order_items_cnt']; ?></td>
        <td class="num"><?php echo (int)$c['box_break_cnt']; ?></td>
        <td class="num"><?php echo (int)$c['history_cnt']; ?></td>
        <td class="<?php echo $canDelete ? 'verdict-ok' : 'verdict-block'; ?>">
          <?php echo $canDelete ? '✅ 삭제 가능' : '⛔ 삭제 불가'; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php
    $cntStmt->close();
    $conn->close();
    ?>
    <div class="note">
      <strong>판정 기준</strong><br>
      • <strong>입고·재고 로트·주문·개봉이력</strong> 합계가 <strong>0</strong> → 안전하게 물리삭제 가능(✅)<br>
      • 하나라도 <strong>&gt; 0</strong> → 재고/이력과 연결되어 물리삭제 불가(⛔). 재고 이관 또는 비활성 유지 필요<br>
      • 변경이력(history)은 상품 삭제 시 자동 삭제(CASCADE)되므로 삭제를 막지 않습니다.
    </div>
<?php
} catch (Exception $e) {
    echo '<div class="err">[ERROR] ' . h($e->getMessage()) . '</div>';
}
?>
</body>
</html>
