<?php
// ============================================================
// 상품명 한글화 일괄 동기화 스크립트
//   kw_products(창고) 중 name_ko가 비어있는 상품을, 같은 DB의 products(관리자)
//   테이블에서 SKU(=barcode_unit/box/logistics)로 찾아 name_ko를 채워줍니다.
//
// 스캔:  http://main.homekmart.net/kimsmall_wherehouse/sql/run_sync_korean_names.php
// 실행은 화면의 체크박스 선택 후 [선택 적용] 버튼(POST)을 눌러야만 수행됩니다.
// ============================================================
require_once __DIR__ . '/../config/db.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// 후보 조회: kw_products.name_ko 비어있음 + products.sku 매칭 + products.name_ko 존재
// 바코드 우선순위: unit > box > logistics (하나의 kw 상품에 여러 매칭이 있으면 우선순위 높은 것만 채택)
function find_candidates(mysqli $conn): array {
    $sql = "SELECT p.id AS kw_id, p.name_en AS kw_name_en, p.name_ko AS kw_name_ko,
                   p.barcode_unit, p.barcode_box, p.barcode_logistics,
                   pr.id AS admin_id, pr.sku AS admin_sku,
                   pr.name_en AS admin_name_en, pr.name_ko AS admin_name_ko,
                   CASE WHEN pr.sku COLLATE utf8mb4_unicode_ci = p.barcode_unit COLLATE utf8mb4_unicode_ci THEN 1
                        WHEN pr.sku COLLATE utf8mb4_unicode_ci = p.barcode_box COLLATE utf8mb4_unicode_ci THEN 2
                        ELSE 3 END AS match_priority
            FROM kw_products p
            JOIN products pr
              ON pr.sku IS NOT NULL AND pr.sku <> ''
             AND (
                    pr.sku COLLATE utf8mb4_unicode_ci = p.barcode_unit COLLATE utf8mb4_unicode_ci
                 OR pr.sku COLLATE utf8mb4_unicode_ci = p.barcode_box COLLATE utf8mb4_unicode_ci
                 OR pr.sku COLLATE utf8mb4_unicode_ci = p.barcode_logistics COLLATE utf8mb4_unicode_ci
             )
            WHERE (p.name_ko IS NULL OR TRIM(p.name_ko) = '')
              AND pr.name_ko IS NOT NULL AND TRIM(pr.name_ko) <> ''
            ORDER BY p.id, match_priority";
    $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

    // kw_id 당 최우선 매칭 1건만 채택
    $byKwId = [];
    foreach ($rows as $r) {
        $kwId = (int)$r['kw_id'];
        if (!isset($byKwId[$kwId])) {
            $byKwId[$kwId] = $r;
        }
    }
    return array_values($byKwId);
}

$results = null; // 적용 실행 결과
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title>상품명 한글화 동기화</title>
<style>
  body { font-family: -apple-system, 'Malgun Gothic', sans-serif; margin: 24px; color: #1f2937; }
  h1 { font-size: 20px; margin: 0 0 4px; }
  .sub { color: #6b7280; font-size: 13px; margin-bottom: 18px; }
  table { border-collapse: collapse; width: 100%; font-size: 13px; margin-bottom: 8px; }
  th, td { border: 1px solid #e5e7eb; padding: 7px 9px; text-align: left; vertical-align: middle; }
  th { background: #f9fafb; font-weight: 600; color: #374151; white-space: nowrap; }
  .bar { position: sticky; top: 0; background: #fff; padding: 10px 0; border-bottom: 1px solid #e5e7eb; margin-bottom: 10px; z-index: 5; }
  button { border: 0; background: #0d9488; color: #fff; padding: 9px 18px; border-radius: 6px; font-size: 14px; cursor: pointer; }
  .box { padding: 12px 14px; border-radius: 8px; font-size: 13px; line-height: 1.6; margin-bottom: 16px; }
  .ok { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
  .note { background: #f0f9ff; border: 1px solid #bae6fd; color: #075985; }
  .err { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
  .res-ok { color: #15803d; } .res-skip { color: #b45309; } .res-err { color: #b91c1c; }
  .ko-new { color: #0d9488; font-weight: 600; }
  code { background: #f3f4f6; padding: 2px 6px; border-radius: 4px; }
</style>
</head>
<body>
<h1>상품명 한글화 동기화 (창고 ← 관리자)</h1>
<div class="sub">kw_products(창고)의 한글명이 비어있는 상품을, 바코드=SKU로 매칭되는 products(관리자) 상품의 한글명으로 채웁니다.</div>

<?php
try {
    $conn = get_lc_db();

    // ── 적용 실행 (POST) ─────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply') {
        $ids = array_values(array_unique(array_map('intval', $_POST['sync'] ?? [])));
        $candidates = find_candidates($conn);
        $byId = [];
        foreach ($candidates as $c) { $byId[(int)$c['kw_id']] = $c; }

        $results = [];
        foreach ($ids as $kwId) {
            if ($kwId <= 0 || !isset($byId[$kwId])) {
                $results[] = [$kwId, 'skip', '후보 목록에 없음(이미 처리되었거나 조건 불일치)'];
                continue;
            }
            $nameKo = trim($byId[$kwId]['admin_name_ko']);
            $st = $conn->prepare("UPDATE kw_products SET name_ko = ? WHERE id = ? AND (name_ko IS NULL OR TRIM(name_ko) = '')");
            $st->bind_param('si', $nameKo, $kwId);
            $st->execute();
            $aff = $st->affected_rows;
            $st->close();
            $results[] = [$kwId, ($aff > 0 ? 'ok' : 'skip'), $aff > 0 ? "적용: {$nameKo}" : '이미 채워져 있음(동시 변경)'];
        }

        echo '<div class="box ok"><strong>적용 결과</strong></div>';
        echo '<table><thead><tr><th>kw_products.id</th><th>결과</th><th>메시지</th></tr></thead><tbody>';
        foreach ($results as $r) {
            $cls = $r[1] === 'ok' ? 'res-ok' : ($r[1] === 'err' ? 'res-err' : 'res-skip');
            $label = $r[1] === 'ok' ? '✅ 적용' : ($r[1] === 'err' ? '⛔ 오류' : '⏭ 건너뜀');
            echo '<tr><td>' . (int)$r[0] . '</td><td class="' . $cls . '">' . $label . '</td><td>' . h($r[2]) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p style="margin:14px 0"><a href="run_sync_korean_names.php">↻ 다시 스캔</a></p>';
        $conn->close();
        echo '</body></html>';
        exit;
    }

    // ── 스캔 (GET) ───────────────────────────────────────────────
    $candidates = find_candidates($conn);
    $conn->close();

    if (!$candidates) {
        echo '<div class="box ok">✅ 한글화가 필요한(매칭되는) 상품이 없습니다.</div>';
        echo '</body></html>';
        exit;
    }

    echo '<form method="post">';
    echo '<input type="hidden" name="action" value="apply">';
    echo '<div class="bar">';
    echo '<label><input type="checkbox" id="chkAll" checked onclick="document.querySelectorAll(\'.rowchk\').forEach(c=>c.checked=this.checked)"> 전체 선택/해제</label>';
    echo '&nbsp;&nbsp;<button type="submit" onclick="return confirm(\'선택한 ' . count($candidates) . '건의 한글 상품명을 적용할까요?\')">선택 적용</button>';
    echo '&nbsp;&nbsp;<span style="color:#6b7280">총 ' . count($candidates) . '건 매칭됨</span>';
    echo '</div>';

    echo '<table><thead><tr>';
    echo '<th></th><th>kw_products.id</th><th>영문명(창고)</th><th>매칭 바코드/SKU</th><th>관리자 상품(id)</th><th>적용될 한글명</th>';
    echo '</tr></thead><tbody>';
    foreach ($candidates as $c) {
        $matchedSku = $c['admin_sku'];
        echo '<tr>';
        echo '<td><input class="rowchk" type="checkbox" name="sync[]" value="' . (int)$c['kw_id'] . '" checked></td>';
        echo '<td>' . (int)$c['kw_id'] . '</td>';
        echo '<td>' . h($c['kw_name_en']) . '</td>';
        echo '<td><code>' . h($matchedSku) . '</code></td>';
        echo '<td>#' . (int)$c['admin_id'] . ' ' . h($c['admin_name_en']) . '</td>';
        echo '<td class="ko-new">' . h($c['admin_name_ko']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</form>';
} catch (Throwable $t) {
    echo '<div class="box err">오류: ' . h($t->getMessage()) . '</div>';
}
?>
</body>
</html>
