<?php
// ============================================================
// 중복 상품 병합(합치기) 스크립트
//   대표(keeper)로 나머지(loser)의 재고/입고/주문/개봉/이력을 모두 이관하고,
//   빈 바코드 칸을 채운 뒤 loser 를 삭제합니다.
//
// 미리보기: run_merge_products.php?keep=475&remove=829
// 실행은 화면의 [병합 실행] 버튼(POST)을 눌러야만 수행됩니다.
//
// ⚠ 주의: 병합은 "같은 상품이 2번 등록된" 경우에만 사용하세요.
//    서로 다른 상품이 바코드 하나만 잘못 겹친 경우(부분중복)에는 병합하면 안 되고,
//    잘못된 바코드 칸만 정정해야 합니다.
//
// product_id 를 저장하는 테이블(전수): kw_inbound / kw_inventory /
//   kw_order_items / kw_box_breaks / kw_product_history
// ============================================================
require_once __DIR__ . '/../config/db.php';

$keep   = (int)($_GET['keep']   ?? $_POST['keep']   ?? 0);
$remove = (int)($_GET['remove'] ?? $_POST['remove'] ?? 0);
$do_merge = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'merge');

// product_id 를 이관해야 하는 테이블 (전수)
$REF_TABLES = ['kw_inbound', 'kw_inventory', 'kw_order_items', 'kw_box_breaks', 'kw_product_history'];
$BC_COLS = ['barcode_unit', 'barcode_box', 'barcode_logistics'];

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function load_product(mysqli $conn, int $id): ?array {
    $st = $conn->prepare("SELECT id, name_en, name_ko, is_active, barcode_unit, barcode_box, barcode_logistics FROM kw_products WHERE id = ?");
    $st->bind_param('i', $id); $st->execute();
    $r = $st->get_result()->fetch_assoc(); $st->close();
    return $r ?: null;
}

function ref_counts(mysqli $conn, int $id): array {
    $cs = $conn->prepare("SELECT
        (SELECT COUNT(*) FROM kw_inbound         WHERE product_id = ?) AS inbound_cnt,
        (SELECT COUNT(*) FROM kw_inventory       WHERE product_id = ?) AS inventory_lots,
        (SELECT COALESCE(SUM(quantity_remain),0) FROM kw_inventory WHERE product_id = ?) AS stock_remain,
        (SELECT COUNT(*) FROM kw_order_items     WHERE product_id = ?) AS order_items_cnt,
        (SELECT COUNT(*) FROM kw_box_breaks      WHERE product_id = ?) AS box_break_cnt,
        (SELECT COUNT(*) FROM kw_product_history WHERE product_id = ?) AS history_cnt");
    $cs->bind_param('iiiiii', $id, $id, $id, $id, $id, $id);
    $cs->execute();
    $c = $cs->get_result()->fetch_assoc(); $cs->close();
    return $c;
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title>중복 상품 병합</title>
<style>
  body { font-family: -apple-system, 'Malgun Gothic', sans-serif; margin: 24px; color: #1f2937; max-width: 860px; }
  h1 { font-size: 20px; margin: 0 0 16px; }
  .cols { display: flex; gap: 16px; margin-bottom: 18px; }
  .card { flex: 1; border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px; }
  .card.keep { border-color: #86efac; background: #f0fdf4; }
  .card.remove { border-color: #fecaca; background: #fef2f2; }
  .card h3 { margin: 0 0 8px; font-size: 14px; }
  .card .ktag { color: #15803d; } .card .rtag { color: #b91c1c; }
  table.kv { width: 100%; border-collapse: collapse; font-size: 13px; }
  table.kv th, table.kv td { border: 1px solid #e5e7eb; padding: 6px 8px; text-align: left; }
  table.kv th { background: #fff; width: 130px; color: #6b7280; }
  .plan { border-collapse: collapse; width: 100%; font-size: 13px; margin: 8px 0 18px; }
  .plan th, .plan td { border: 1px solid #e5e7eb; padding: 7px 9px; text-align: left; }
  .plan th { background: #f9fafb; }
  .box { padding: 12px 14px; border-radius: 8px; font-size: 13px; line-height: 1.6; margin-bottom: 16px; }
  .warn { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
  .ok { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
  .done { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }
  .err { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
  button { border: 0; background: #7c3aed; color: #fff; padding: 10px 20px; border-radius: 6px; font-size: 14px; cursor: pointer; }
  a { color: #0d9488; }
  .conflict { color: #b91c1c; font-weight: 600; }
</style>
</head>
<body>
<h1>중복 상품 병합 <span style="color:#6b7280">(합치고 하나 삭제)</span></h1>

<?php
try {
    $conn = get_lc_db();

    if ($keep <= 0 || $remove <= 0) {
        // ── 바코드로 후보 불러오기 → 대표/삭제 선택 ──
        $bc = trim($_GET['barcode'] ?? '');
        echo '<div class="box warn">합칠 두 상품을 고르세요. 바코드를 입력하면 같은 바코드를 쓰는 상품이 나옵니다. '
           . '(또는 <a href="run_dedupe_barcodes.php">중복 스캔</a>에서 “합치기” 링크 사용)</div>';
        echo '<form method="get" style="margin-bottom:16px">'
           . '<input type="text" name="barcode" value="' . h($bc) . '" placeholder="바코드 입력" '
           . 'style="border:1px solid #d1d5db;border-radius:6px;padding:7px 10px;font-size:14px;width:240px">'
           . ' <button type="submit" style="background:#0d9488">조회</button></form>';

        if ($bc !== '') {
            $st = $conn->prepare("SELECT id, name_en, name_ko, is_active, barcode_unit, barcode_box, barcode_logistics
                                  FROM kw_products WHERE barcode_unit = ? OR barcode_box = ? OR barcode_logistics = ? ORDER BY id");
            $st->bind_param('sss', $bc, $bc, $bc);
            $st->execute();
            $cand = $st->get_result()->fetch_all(MYSQLI_ASSOC);
            $st->close();

            if (count($cand) < 2) {
                echo '<div class="box err">같은 바코드를 쓰는 상품이 2개 미만입니다. (' . count($cand) . '개) 병합할 대상이 없습니다.</div>';
            } else {
                echo '<table class="plan"><thead><tr><th>대표(유지)</th><th>삭제(합쳐짐)</th><th>ID</th><th>상품명</th><th>상태</th>'
                   . '<th>Unit/Box/Logi BC</th><th class="num">재고</th><th class="num">현재고</th><th class="num">주문</th></tr></thead><tbody>';
                foreach ($cand as $p) {
                    $pid = (int)$p['id'];
                    $rc = ref_counts($conn, $pid);
                    echo '<tr>'
                       . '<td style="text-align:center"><input type="radio" name="kpick" value="' . $pid . '"></td>'
                       . '<td style="text-align:center"><input type="radio" name="rpick" value="' . $pid . '"></td>'
                       . '<td>' . $pid . '</td>'
                       . '<td>' . h($p['name_en']) . ($p['name_ko'] ? ' <span style="color:#9ca3af">(' . h($p['name_ko']) . ')</span>' : '') . '</td>'
                       . '<td>' . ($p['is_active'] ? 'Active' : 'Inactive') . '</td>'
                       . '<td>' . h($p['barcode_unit'] ?: '-') . ' / ' . h($p['barcode_box'] ?: '-') . ' / ' . h($p['barcode_logistics'] ?: '-') . '</td>'
                       . '<td class="num">' . (int)$rc['inventory_lots'] . '</td>'
                       . '<td class="num">' . number_format((float)$rc['stock_remain']) . '</td>'
                       . '<td class="num">' . (int)$rc['order_items_cnt'] . '</td>'
                       . '</tr>';
                }
                echo '</tbody></table>';
                echo '<p style="color:#6b7280;font-size:13px;margin:6px 0 12px">재고/주문이 있는 쪽을 <strong>대표</strong>로 두는 것을 권장합니다.</p>';
                echo '<button type="button" onclick="goMerge()">선택한 상품 병합 미리보기 →</button>';
                echo '<script>function goMerge(){'
                   . 'var k=document.querySelector("input[name=kpick]:checked");'
                   . 'var r=document.querySelector("input[name=rpick]:checked");'
                   . 'if(!k||!r){alert("대표와 삭제 대상을 각각 선택하세요.");return;}'
                   . 'if(k.value===r.value){alert("대표와 삭제 대상이 같습니다.");return;}'
                   . 'location.href="run_merge_products.php?keep="+k.value+"&remove="+r.value;}</script>';
            }
        }
        $conn->close(); echo '</body></html>'; exit;
    }
    if ($keep === $remove) {
        echo '<div class="box err">대표와 삭제 대상이 같습니다.</div>';
        $conn->close(); echo '</body></html>'; exit;
    }

    $K = load_product($conn, $keep);
    $R = load_product($conn, $remove);
    if (!$K || !$R) {
        echo '<div class="box err">상품을 찾을 수 없습니다. (keep=' . $keep . ', remove=' . $remove . ')</div>';
        $conn->close(); echo '</body></html>'; exit;
    }
    $Kc = ref_counts($conn, $keep);
    $Rc = ref_counts($conn, $remove);

    // 바코드 병합 계획 계산
    $bcPlan = [];
    foreach ($BC_COLS as $col) {
        $kv = trim((string)$K[$col]);
        $rv = trim((string)$R[$col]);
        if ($kv === '' && $rv !== '')            { $bcPlan[$col] = ['result' => $rv, 'note' => 'loser 값으로 채움']; }
        elseif ($kv !== '' && $rv !== '' && $kv !== $rv) { $bcPlan[$col] = ['result' => $kv, 'note' => 'keeper 유지 · loser(' . $rv . ') 폐기', 'conflict' => true]; }
        else                                     { $bcPlan[$col] = ['result' => ($kv !== '' ? $kv : '-'), 'note' => 'keeper 유지']; }
    }

    if ($do_merge) {
        // ── 병합 실행 ──
        $conn->begin_transaction();
        try {
            $moved = [];
            foreach ($REF_TABLES as $t) {
                $st = $conn->prepare("UPDATE $t SET product_id = ? WHERE product_id = ?");
                $st->bind_param('ii', $keep, $remove);
                $st->execute();
                $moved[$t] = $st->affected_rows;
                $st->close();
            }
            // 대표의 빈 바코드 칸 채우기
            $sets = []; $vals = []; $types = '';
            foreach ($BC_COLS as $col) {
                $kv = trim((string)$K[$col]); $rv = trim((string)$R[$col]);
                if ($kv === '' && $rv !== '') { $sets[] = "$col = ?"; $vals[] = $rv; $types .= 's'; }
            }
            if ($sets) {
                $sql = "UPDATE kw_products SET " . implode(', ', $sets) . " WHERE id = ?";
                $vals[] = $keep; $types .= 'i';
                $up = $conn->prepare($sql);
                $up->bind_param($types, ...$vals);
                $up->execute(); $up->close();
            }
            // loser 삭제 (이력은 위에서 이미 이관됨)
            $del = $conn->prepare("DELETE FROM kw_products WHERE id = ?");
            $del->bind_param('i', $remove); $del->execute();
            $delRows = $del->affected_rows; $del->close();

            if ($delRows < 1) throw new Exception('삭제 대상 상품이 삭제되지 않았습니다.');

            $conn->commit();

            echo '<div class="box done"><strong>✅ 병합 완료</strong><br>'
               . '상품 <strong>#' . $remove . '</strong> → <strong>#' . $keep . '</strong> 로 이관 후 삭제되었습니다.</div>';
            echo '<table class="plan"><thead><tr><th>테이블</th><th>이관된 행</th></tr></thead><tbody>';
            foreach ($moved as $t => $n) echo '<tr><td>' . h($t) . '</td><td>' . (int)$n . '</td></tr>';
            echo '</tbody></table>';
            if ($sets) echo '<div class="box ok">대표(#' . $keep . ')의 빈 바코드 칸 ' . count($sets) . '개를 채웠습니다.</div>';
            echo '<p><a href="run_dedupe_barcodes.php">↻ 중복 스캔으로</a> · <a href="' . h(LC_BASE) . '/product_edit.php?id=' . $keep . '">대표 상품 편집</a></p>';
            $conn->close(); echo '</body></html>'; exit;

        } catch (Throwable $t) {
            $conn->rollback();
            echo '<div class="box err">[ERROR] 병합 실패(롤백됨): ' . h($t->getMessage()) . '</div>';
            $conn->close(); echo '</body></html>'; exit;
        }
    }

    // ── 미리보기 ──
    // 두 상품이 서로 "다른 상품"으로 의심되면 경고
    $sharedBc = false;
    foreach ($BC_COLS as $c1) {
        $rv = trim((string)$R[$c1]); if ($rv === '') continue;
        foreach ($BC_COLS as $c2) { if ($rv !== '' && $rv === trim((string)$K[$c2])) { $sharedBc = true; break 2; } }
    }
    ?>
    <div class="box warn">
      <strong>⚠ 병합 전 확인</strong> — 병합은 <u>같은 상품이 2번 등록된 경우</u>에만 사용하세요.
      서로 다른 상품이 바코드 하나만 잘못 겹친 경우라면 병합하지 말고, 잘못된 바코드 칸만 정정해야 합니다.
      <?php if (!$sharedBc): ?><br><span class="conflict">※ 이 두 상품은 공유하는 바코드가 없습니다 — 정말 같은 상품인지 다시 확인하세요.</span><?php endif; ?>
    </div>

    <div class="cols">
      <div class="card keep">
        <h3><span class="ktag">유지(대표)</span> · #<?php echo $keep; ?></h3>
        <table class="kv">
          <tr><th>상품명</th><td><?php echo h($K['name_en']) . ($K['name_ko'] ? ' (' . h($K['name_ko']) . ')' : ''); ?></td></tr>
          <tr><th>상태</th><td><?php echo $K['is_active'] ? 'Active' : 'Inactive'; ?></td></tr>
          <tr><th>Unit/Box/Logi BC</th><td><?php echo h($K['barcode_unit'] ?: '-') . ' / ' . h($K['barcode_box'] ?: '-') . ' / ' . h($K['barcode_logistics'] ?: '-'); ?></td></tr>
          <tr><th>입고/재고/현재고</th><td><?php echo (int)$Kc['inbound_cnt'] . ' / ' . (int)$Kc['inventory_lots'] . ' / ' . number_format((float)$Kc['stock_remain']); ?></td></tr>
          <tr><th>주문/개봉/이력</th><td><?php echo (int)$Kc['order_items_cnt'] . ' / ' . (int)$Kc['box_break_cnt'] . ' / ' . (int)$Kc['history_cnt']; ?></td></tr>
        </table>
      </div>
      <div class="card remove">
        <h3><span class="rtag">삭제(합쳐짐)</span> · #<?php echo $remove; ?></h3>
        <table class="kv">
          <tr><th>상품명</th><td><?php echo h($R['name_en']) . ($R['name_ko'] ? ' (' . h($R['name_ko']) . ')' : ''); ?></td></tr>
          <tr><th>상태</th><td><?php echo $R['is_active'] ? 'Active' : 'Inactive'; ?></td></tr>
          <tr><th>Unit/Box/Logi BC</th><td><?php echo h($R['barcode_unit'] ?: '-') . ' / ' . h($R['barcode_box'] ?: '-') . ' / ' . h($R['barcode_logistics'] ?: '-'); ?></td></tr>
          <tr><th>입고/재고/현재고</th><td><?php echo (int)$Rc['inbound_cnt'] . ' / ' . (int)$Rc['inventory_lots'] . ' / ' . number_format((float)$Rc['stock_remain']); ?></td></tr>
          <tr><th>주문/개봉/이력</th><td><?php echo (int)$Rc['order_items_cnt'] . ' / ' . (int)$Rc['box_break_cnt'] . ' / ' . (int)$Rc['history_cnt']; ?></td></tr>
        </table>
      </div>
    </div>

    <h3 style="font-size:14px;margin:0 0 6px">병합 계획</h3>
    <table class="plan">
      <thead><tr><th>이관 항목</th><th>#<?php echo $remove; ?> → #<?php echo $keep; ?></th></tr></thead>
      <tbody>
        <tr><td>입고 (kw_inbound)</td><td><?php echo (int)$Rc['inbound_cnt']; ?> 건 이관</td></tr>
        <tr><td>재고 로트 (kw_inventory)</td><td><?php echo (int)$Rc['inventory_lots']; ?> 건 (현재고 <?php echo number_format((float)$Rc['stock_remain']); ?>) 이관</td></tr>
        <tr><td>주문 상세 (kw_order_items)</td><td><?php echo (int)$Rc['order_items_cnt']; ?> 건 이관</td></tr>
        <tr><td>개봉이력 (kw_box_breaks)</td><td><?php echo (int)$Rc['box_break_cnt']; ?> 건 이관</td></tr>
        <tr><td>변경이력 (kw_product_history)</td><td><?php echo (int)$Rc['history_cnt']; ?> 건 이관</td></tr>
        <?php foreach ($BC_COLS as $col): $p = $bcPlan[$col]; ?>
        <tr><td><?php echo h($col); ?></td>
            <td<?php echo !empty($p['conflict']) ? ' class="conflict"' : ''; ?>><?php echo h($p['result']); ?> <span style="color:#9ca3af">— <?php echo h($p['note']); ?></span></td></tr>
        <?php endforeach; ?>
        <tr><td>상품 #<?php echo $remove; ?></td><td class="conflict">삭제</td></tr>
      </tbody>
    </table>

    <form method="post" onsubmit="return confirm('상품 #<?php echo $remove; ?> 을(를) #<?php echo $keep; ?> 로 병합하고 삭제합니다. 되돌릴 수 없습니다. 계속할까요?');">
      <input type="hidden" name="keep" value="<?php echo $keep; ?>">
      <input type="hidden" name="remove" value="<?php echo $remove; ?>">
      <input type="hidden" name="action" value="merge">
      <button type="submit">🔀 병합 실행 (#<?php echo $remove; ?> → #<?php echo $keep; ?>)</button>
      <a href="run_dedupe_barcodes.php" style="margin-left:12px;">취소</a>
    </form>
    <?php
    $conn->close();
} catch (Exception $e) {
    echo '<div class="box err">[ERROR] ' . h($e->getMessage()) . '</div>';
}
?>
</body>
</html>
