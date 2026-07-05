<?php
// ============================================================
// 중복 바코드 일괄 점검 & 정리 스크립트
// 스캔:  http://main.homekmart.net/logistics/sql/run_dedupe_barcodes.php
// 동작:
//   1) barcode_unit/box/logistics 3개 컬럼을 합쳐 같은 바코드를 쓰는 상품 그룹을 모두 탐지
//   2) 그룹별로 참조(입고/재고/주문/개봉)가 있는 상품은 '유지', 참조 0건 중복만 삭제 후보로 표시
//   3) [선택 삭제] 버튼(POST)으로 체크된 상품만 삭제
//
// 안전장치(서버 재검증):
//   A) 참조가 하나라도 있으면 삭제 거부 (DB FK RESTRICT 가 2차 방어)
//   B) 삭제 시 해당 바코드를 쓰는 상품이 하나도 안 남으면 삭제 거부(바코드 소실 방지)
//   C) 트랜잭션 처리 · 변경이력(history)은 함께 삭제
// ============================================================
require_once __DIR__ . '/../config/db.php';

$blocking = ['inbound_cnt', 'inventory_lots', 'order_items_cnt', 'box_break_cnt'];

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// 상품 1건의 참조 건수 조회
function product_counts(mysqli $conn, int $id): array {
    $cs = $conn->prepare("SELECT
        (SELECT COUNT(*) FROM lc_inbound         WHERE product_id = ?) AS inbound_cnt,
        (SELECT COUNT(*) FROM lc_inventory       WHERE product_id = ?) AS inventory_lots,
        (SELECT COALESCE(SUM(quantity_remain),0) FROM lc_inventory WHERE product_id = ?) AS stock_remain,
        (SELECT COUNT(*) FROM lc_order_items     WHERE product_id = ?) AS order_items_cnt,
        (SELECT COUNT(*) FROM lc_box_breaks      WHERE product_id = ?) AS box_break_cnt,
        (SELECT COUNT(*) FROM lc_product_history WHERE product_id = ?) AS history_cnt");
    $cs->bind_param('iiiiii', $id, $id, $id, $id, $id, $id);
    $cs->execute();
    $c = $cs->get_result()->fetch_assoc();
    $cs->close();
    return $c;
}

// 상품의 비어있지 않은 바코드 목록
function product_barcodes(mysqli $conn, int $id): array {
    $st = $conn->prepare("SELECT barcode_unit, barcode_box, barcode_logistics FROM lc_products WHERE id = ?");
    $st->bind_param('i', $id); $st->execute();
    $r = $st->get_result()->fetch_assoc(); $st->close();
    if (!$r) return [];
    $out = [];
    foreach (['barcode_unit', 'barcode_box', 'barcode_logistics'] as $col) {
        $v = trim((string)($r[$col] ?? ''));
        if ($v !== '') $out[] = $v;
    }
    return array_values(array_unique($out));
}

// 특정 바코드를 보유한 상품 수 (공유=중복 판정용, 캐시)
function bc_holders(mysqli $conn, string $bc): int {
    static $cache = [];
    if (isset($cache[$bc])) return $cache[$bc];
    $st = $conn->prepare("SELECT COUNT(*) FROM lc_products WHERE barcode_unit = ? OR barcode_box = ? OR barcode_logistics = ?");
    $st->bind_param('sss', $bc, $bc, $bc);
    $st->execute();
    $n = (int)$st->get_result()->fetch_row()[0];
    $st->close();
    return $cache[$bc] = $n;
}

// 바코드 셀 렌더: 공유(중복)면 빨강 배지, 고유면 회색 배지
function bc_cell(mysqli $conn, ?string $v): string {
    $v = trim((string)$v);
    if ($v === '') return '<span style="color:#cbd5e1">-</span>';
    $n = bc_holders($conn, $v);
    if ($n > 1) return h($v) . ' <span style="color:#b91c1c;font-size:11px">(중복 ' . $n . ')</span>';
    return h($v) . ' <span style="color:#9ca3af;font-size:11px">(고유)</span>';
}

// 특정 바코드가 delete_set 를 제외하고도 다른 상품에 남아있는지
function barcode_still_used(mysqli $conn, string $bc, int $selfId, array $deleteSet): bool {
    $excl = array_values(array_unique(array_map('intval', $deleteSet)));
    $notIn = $excl ? (' AND id NOT IN (' . implode(',', $excl) . ')') : '';
    $sql = "SELECT COUNT(*) FROM lc_products
            WHERE id <> ? AND (barcode_unit = ? OR barcode_box = ? OR barcode_logistics = ?)" . $notIn;
    $st = $conn->prepare($sql);
    $st->bind_param('isss', $selfId, $bc, $bc, $bc);
    $st->execute();
    $n = (int)$st->get_result()->fetch_row()[0];
    $st->close();
    return $n > 0;
}

$results = null; // 삭제 실행 결과
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title>중복 바코드 일괄 정리</title>
<style>
  body { font-family: -apple-system, 'Malgun Gothic', sans-serif; margin: 24px; color: #1f2937; }
  h1 { font-size: 20px; margin: 0 0 4px; }
  .sub { color: #6b7280; font-size: 13px; margin-bottom: 18px; }
  table { border-collapse: collapse; width: 100%; font-size: 13px; margin-bottom: 8px; }
  th, td { border: 1px solid #e5e7eb; padding: 7px 9px; text-align: left; vertical-align: middle; }
  th { background: #f9fafb; font-weight: 600; color: #374151; white-space: nowrap; }
  td.num { text-align: right; font-variant-numeric: tabular-nums; }
  .grp { margin: 22px 0 6px; font-weight: 700; font-size: 14px; }
  .grp code { background: #f3f4f6; padding: 2px 6px; border-radius: 4px; }
  .keep { color: #b45309; font-weight: 700; }
  .del  { color: #b91c1c; font-weight: 700; }
  .inactive { color: #9ca3af; }
  .bar { position: sticky; top: 0; background: #fff; padding: 10px 0; border-bottom: 1px solid #e5e7eb; margin-bottom: 10px; z-index: 5; }
  button { border: 0; background: #dc2626; color: #fff; padding: 9px 18px; border-radius: 6px; font-size: 14px; cursor: pointer; }
  .box { padding: 12px 14px; border-radius: 8px; font-size: 13px; line-height: 1.6; margin-bottom: 16px; }
  .ok { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
  .note { background: #f0f9ff; border: 1px solid #bae6fd; color: #075985; }
  .err { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
  .res-ok { color: #15803d; } .res-skip { color: #b45309; } .res-err { color: #b91c1c; }
</style>
</head>
<body>
<h1>중복 바코드 일괄 정리</h1>
<div class="sub">3개 바코드 컬럼을 합쳐 같은 바코드를 쓰는 상품을 모두 찾아, 참조가 없는 중복만 안전하게 삭제합니다.</div>

<?php
try {
    $conn = get_lc_db();

    // ── 삭제 실행 (POST) ─────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
        $ids = array_values(array_unique(array_map('intval', $_POST['del'] ?? [])));
        $results = [];
        foreach ($ids as $pid) {
            if ($pid <= 0) continue;
            $c = product_counts($conn, $pid);
            $blockSum = 0; foreach ($blocking as $k) { $blockSum += (int)$c[$k]; }

            if ($blockSum > 0) {
                $results[] = [$pid, 'skip', '참조 데이터 있음 (삭제 거부)'];
                continue;
            }
            // 바코드 소실 방지: 이 상품의 각 바코드가 삭제집합 제외 후에도 남는지
            $wiped = [];
            foreach (product_barcodes($conn, $pid) as $bc) {
                if (!barcode_still_used($conn, $bc, $pid, $ids)) $wiped[] = $bc;
            }
            if ($wiped) {
                $results[] = [$pid, 'skip', '삭제 시 바코드 소실: ' . implode(', ', $wiped)];
                continue;
            }
            // 삭제 수행
            $conn->begin_transaction();
            try {
                $d1 = $conn->prepare("DELETE FROM lc_product_history WHERE product_id = ?");
                $d1->bind_param('i', $pid); $d1->execute(); $d1->close();
                $d2 = $conn->prepare("DELETE FROM lc_products WHERE id = ?");
                $d2->bind_param('i', $pid); $d2->execute();
                $aff = $d2->affected_rows; $d2->close();
                $conn->commit();
                $results[] = [$pid, ($aff > 0 ? 'ok' : 'skip'), $aff > 0 ? '삭제 완료' : '이미 없음'];
            } catch (Throwable $t) {
                $conn->rollback();
                $results[] = [$pid, 'err', $t->getMessage()];
            }
        }

        // 결과 표
        echo '<div class="box ok"><strong>삭제 처리 결과</strong></div>';
        echo '<table><thead><tr><th>상품 ID</th><th>결과</th><th>메시지</th></tr></thead><tbody>';
        foreach ($results as $r) {
            $cls = $r[1] === 'ok' ? 'res-ok' : ($r[1] === 'err' ? 'res-err' : 'res-skip');
            $label = $r[1] === 'ok' ? '✅ 삭제' : ($r[1] === 'err' ? '⛔ 오류' : '⏭ 건너뜀');
            echo '<tr><td>' . (int)$r[0] . '</td><td class="' . $cls . '">' . $label . '</td><td>' . h($r[2]) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p style="margin:14px 0"><a href="run_dedupe_barcodes.php">↻ 다시 스캔</a></p>';
        $conn->close();
        echo '</body></html>';
        exit;
    }

    // ── 스캔 (GET) ───────────────────────────────────────────────
    // 3개 바코드 컬럼을 합쳐 같은 바코드를 2개 이상 상품이 쓰는 그룹 탐지
    $dupSql = "SELECT bc, COUNT(DISTINCT id) AS pc
               FROM (
                   SELECT id, barcode_unit AS bc FROM lc_products WHERE barcode_unit IS NOT NULL AND barcode_unit <> ''
                   UNION ALL
                   SELECT id, barcode_box       FROM lc_products WHERE barcode_box IS NOT NULL AND barcode_box <> ''
                   UNION ALL
                   SELECT id, barcode_logistics FROM lc_products WHERE barcode_logistics IS NOT NULL AND barcode_logistics <> ''
               ) t
               GROUP BY bc
               HAVING COUNT(DISTINCT id) > 1
               ORDER BY pc DESC, bc";
    $groups = $conn->query($dupSql)->fetch_all(MYSQLI_ASSOC);

    if (!$groups) {
        echo '<div class="box ok">✅ 중복된 바코드가 없습니다.</div>';
        $conn->close(); echo '</body></html>'; exit;
    }

    // 그룹별 상품 조회 준비
    $memberStmt = $conn->prepare(
        "SELECT id, name_en, name_ko, is_active, barcode_unit, barcode_box, barcode_logistics
         FROM lc_products
         WHERE barcode_unit = ? OR barcode_box = ? OR barcode_logistics = ?
         ORDER BY id");

    $totalDelCandidates = 0;
    ob_start(); // 그룹 표를 버퍼링 (상단 요약 먼저 출력하기 위함)

    foreach ($groups as $g) {
        $bc = $g['bc'];
        $memberStmt->bind_param('sss', $bc, $bc, $bc);
        $memberStmt->execute();
        $members = $memberStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // 참조 건수 + 고유 바코드 보유 여부 부착
        foreach ($members as &$m) {
            $c = product_counts($conn, (int)$m['id']);
            $m['_c'] = $c;
            $m['_block'] = 0; foreach ($blocking as $k) { $m['_block'] += (int)$c[$k]; }
            // 고유 바코드(그 상품만 가진 바코드) 보유 시 삭제하면 소실 → 삭제 불가
            $m['_hasUnique'] = false;
            foreach (['barcode_unit', 'barcode_box', 'barcode_logistics'] as $col) {
                $v = trim((string)($m[$col] ?? ''));
                if ($v !== '' && bc_holders($conn, $v) <= 1) { $m['_hasUnique'] = true; break; }
            }
        }
        unset($m);

        // 유지(keeper) 선정: 참조 많은 순 → 이력 많은 순 → id 낮은 순
        $sorted = $members;
        usort($sorted, function($a, $b) {
            if ($a['_block'] !== $b['_block']) return $b['_block'] <=> $a['_block'];
            if ((int)$a['_c']['history_cnt'] !== (int)$b['_c']['history_cnt']) return (int)$b['_c']['history_cnt'] <=> (int)$a['_c']['history_cnt'];
            return (int)$a['id'] <=> (int)$b['id'];
        });
        $keeperId = (int)$sorted[0]['id'];

        echo '<div class="grp">바코드 <code>' . h($bc) . '</code> · ' . count($members) . '개 상품</div>';
        echo '<table><thead><tr>'
           . '<th>삭제</th><th>ID</th><th>상품명</th><th>상태</th>'
           . '<th>Unit BC</th><th>Box BC</th><th>Logi BC</th>'
           . '<th class="num">입고</th><th class="num">재고</th><th class="num">현재고</th>'
           . '<th class="num">주문</th><th class="num">개봉</th><th class="num">이력</th><th>구분</th><th>병합</th>'
           . '</tr></thead><tbody>';

        foreach ($members as $m) {
            $pid = (int)$m['id'];
            $c = $m['_c'];
            $isKeeper = ($pid === $keeperId);
            $referenced = ($m['_block'] > 0);
            $hasUnique = !empty($m['_hasUnique']);
            // 순수 중복(모든 바코드가 공유)이고 참조 없고 keeper 아닐 때만 안전 삭제 가능
            $deletable = (!$isKeeper && !$referenced && !$hasUnique);
            if ($deletable) $totalDelCandidates++;

            if ($isKeeper)         $tag = '<span class="keep">유지</span>';
            elseif ($referenced)   $tag = '<span class="keep">유지(참조)</span>';
            elseif ($hasUnique)    $tag = '<span style="color:#7c3aed;font-weight:700">부분중복(정정 필요)</span>';
            else                   $tag = '<span class="del">삭제후보</span>';

            echo '<tr>';
            // 체크박스: 안전 삭제 가능한 순수 중복만 활성 + 기본 체크
            if ($deletable) {
                echo '<td><input type="checkbox" name="del[]" form="delForm" value="' . $pid . '" checked></td>';
            } else {
                echo '<td style="text-align:center;color:#cbd5e1">—</td>';
            }
            echo '<td>' . $pid . '</td>';
            echo '<td>' . h($m['name_en']) . ($m['name_ko'] ? ' <span style="color:#9ca3af">(' . h($m['name_ko']) . ')</span>' : '') . '</td>';
            echo '<td class="' . ($m['is_active'] ? '' : 'inactive') . '">' . ($m['is_active'] ? 'Active' : 'Inactive') . '</td>';
            echo '<td>' . bc_cell($conn, $m['barcode_unit']) . '</td><td>' . bc_cell($conn, $m['barcode_box']) . '</td><td>' . bc_cell($conn, $m['barcode_logistics']) . '</td>';
            echo '<td class="num">' . (int)$c['inbound_cnt'] . '</td>';
            echo '<td class="num">' . (int)$c['inventory_lots'] . '</td>';
            echo '<td class="num">' . number_format((float)$c['stock_remain']) . '</td>';
            echo '<td class="num">' . (int)$c['order_items_cnt'] . '</td>';
            echo '<td class="num">' . (int)$c['box_break_cnt'] . '</td>';
            echo '<td class="num">' . (int)$c['history_cnt'] . '</td>';
            echo '<td>' . $tag . '</td>';
            // 병합 링크: 비-대표 상품 → 대표(keeper)로 합치기
            if ($isKeeper) {
                echo '<td style="text-align:center;color:#cbd5e1">대표</td>';
            } else {
                echo '<td><a href="run_merge_products.php?keep=' . $keeperId . '&remove=' . $pid . '"'
                   . ' style="color:#7c3aed;font-weight:600" title="#' . $pid . ' 을 대표 #' . $keeperId . ' 로 합치기">→ #' . $keeperId . ' 로 합치기</a></td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    $memberStmt->close();
    $tables = ob_get_clean();

    // 상단 요약 + 삭제 폼
    echo '<div class="bar">';
    echo '<form id="delForm" method="post" onsubmit="return confirm(\'체크된 상품을 영구 삭제합니다. 계속할까요?\');">';
    echo '<input type="hidden" name="action" value="delete">';
    echo '<button type="submit">🗑 선택 삭제 실행</button> ';
    echo '<span style="margin-left:12px;color:#6b7280;font-size:13px">중복 그룹 <strong>' . count($groups) . '</strong>개 · 삭제 후보(기본 체크) <strong>' . $totalDelCandidates . '</strong>건</span>';
    echo '</form></div>';

    echo '<div class="box note"><strong>표시 규칙</strong><br>'
       . '• 바코드 옆 <span style="color:#b91c1c">(중복 N)</span> = N개 상품이 공유 · <span style="color:#9ca3af">(고유)</span> = 그 상품만 보유<br>'
       . '• <span class="keep">유지</span> = 그룹 대표(참조 최다) · <span class="keep">유지(참조)</span> = 재고/입고/주문 연결됨<br>'
       . '• <span class="del">삭제후보</span> = 모든 바코드가 공유되는 순수 중복(기본 체크, 안전 삭제 가능)<br>'
       . '• <span style="color:#7c3aed;font-weight:700">부분중복(정정 필요)</span> = <strong>고유 바코드를 가진 상품</strong>. 삭제하면 그 바코드가 소실되므로 삭제 불가 → '
       . '상품을 지우지 말고, 잘못 공유된 바코드 칸만 수정(상품 편집)해서 정리하세요.</div>';

    echo $tables;

    $conn->close();
} catch (Exception $e) {
    echo '<div class="box err">[ERROR] ' . h($e->getMessage()) . '</div>';
}
?>
</body>
</html>
