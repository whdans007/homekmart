<?php
/**
 * 물류센터 입고분 매입등록(purchase_from_logistics) 자동매칭 버그로 인해
 * products.sku 가 낱개코드(barcode_unit) 대신 박스코드(barcode_box, 보통 앞자리 '1')로
 * 잘못 등록된 상품을 찾아 교정한다.
 *
 * 배경: admin/match_logistics_purchase.php 의 기존 매칭 쿼리가
 *       "WHERE sku IN (낱개코드, 박스코드, 물류코드) LIMIT 1" 형태라 우선순위를 지키지 않았음.
 *       코드 자체는 이미 수정했으나(우선순위 강제 조회), 이미 잘못된 SKU로 등록되어버린
 *       기존 products 행은 그대로 남아있어 별도 데이터 교정이 필요함 (예: 매입번호 2416,
 *       "오뚜기 오뚜기밥 흰밥" — products.sku=18801045892082(박스) 로 등록됨,
 *       실제 낱개코드는 lc_products.barcode_unit=8801045892085).
 *
 * 동작:
 *  - 기본(파라미터 없음): 진단만 수행, 변경 없음 (안전).
 *  - ?apply=1 : "단순 교정 대상"(아래 조건 모두 만족)만 실제로 products.sku 를 낱개코드로 변경.
 *      1) products.sku 가 어떤 lc_products.barcode_box 와 정확히(바코드 단위) 일치 — 우연 충돌 확률은
 *         13~14자리 바코드 특성상 사실상 0이므로 이 매칭 자체가 충분한 근거. 상품명은 admin 쪽이
 *         "OOO(박스)" 식으로 별도 표기하는 관례가 있어 lc_products 명과 문자열이 다른 게 정상이라 비교하지 않음.
 *      2) 해당 lc_products.barcode_unit 이 존재하고, sku 와 다름
 *      3) 그 barcode_unit 값을 sku 로 쓰는 다른 products 행이 없음 (충돌 없음 = 단순 교정)
 *  - 조건 3(충돌 있음, 즉 낱개코드 상품이 이미 별도로 존재)인 건은 자동 처리하지 않고
 *    "병합 필요" 로만 보고한다 (기존 logistics/sql/fix_merge_duplicate_product_*.php 참고).
 *
 * 접속: https://<도메인>/admin/diag_fix_box_sku_mismatch.php        (진단만)
 *       https://<도메인>/admin/diag_fix_box_sku_mismatch.php?apply=1 (단순 교정 실행)
 * 주의: 실행 후 이 파일은 삭제하세요 (1회성 데이터 교정 스크립트).
 */

require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../config/db_config.php';

ensure_logged_in();
if ($_SESSION['role'] !== 'super_admin') {
    die('이 스크립트는 super_admin만 실행할 수 있습니다.');
}

$apply = isset($_GET['apply']) && $_GET['apply'] === '1';
$only_id = isset($_GET['only']) ? (int)$_GET['only'] : 0; // products.id 하나만 대상으로 테스트하고 싶을 때 사용

echo "<h2>박스코드로 잘못 등록된 SKU 진단" . ($apply ? ' + 교정 실행' : ' (진단 전용)') . ($only_id ? " — product #{$only_id} 만" : '') . "</h2>";
echo "<pre>";

try {
    $conn = get_db_connection();

    // products / lc_products 컬럼 존재 여부부터 확인 (조용히 죽지 않도록)
    foreach (['products' => ['id', 'sku', 'name_ko', 'name_en'], 'lc_products' => ['id', 'name_ko', 'name_en', 'barcode_unit', 'barcode_box', 'barcode_logistics']] as $tbl => $cols) {
        foreach ($cols as $col) {
            $chk = $conn->query("SHOW COLUMNS FROM {$tbl} LIKE '{$col}'");
            if (!$chk || $chk->num_rows === 0) {
                echo "[중단] 테이블 {$tbl} 에 컬럼 {$col} 이(가) 없습니다. 스키마를 확인해주세요.\n";
                echo "</pre>";
                $conn->close();
                exit;
            }
        }
    }

    // products.sku 가 lc_products.barcode_box 와 일치하는 후보를 모두 조회
    $sql = "
        SELECT p.id AS product_id, p.sku AS product_sku, p.name_ko AS product_name_ko, p.name_en AS product_name_en,
               lp.id AS lc_product_id, lp.name_ko AS lc_name_ko, lp.name_en AS lc_name_en,
               lp.barcode_unit, lp.barcode_box, lp.barcode_logistics
        FROM products p
        JOIN lc_products lp ON lp.barcode_box = p.sku COLLATE utf8mb4_unicode_ci
        WHERE lp.barcode_unit IS NOT NULL AND lp.barcode_unit <> ''
          AND lp.barcode_unit <> p.sku COLLATE utf8mb4_unicode_ci
          " . ($only_id ? "AND p.id = {$only_id}" : "") . "
        ORDER BY p.id
    ";
    $result = $conn->query($sql);
    if ($result === false) {
        echo "[SQL 오류] " . $conn->error . "\n";
        echo "</pre>";
        $conn->close();
        exit;
    }
    $candidates = $result->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $e) {
    echo "[예외 발생] " . $e->getMessage() . "\n";
    echo "</pre>";
    if (isset($conn)) $conn->close();
    exit;
}

if (empty($candidates)) {
    echo "박스코드로 잘못 등록된 상품 후보가 없습니다.\n";
    echo "</pre>";
    $conn->close();
    exit;
}

echo count($candidates) . "건 발견\n\n";

$fixed = 0;
$conflict = 0;

foreach ($candidates as $c) {
    $label = "#{$c['product_id']} sku={$c['product_sku']} ({$c['product_name_ko']} / {$c['product_name_en']})"
        . " -> 낱개코드 {$c['barcode_unit']} (lc_products #{$c['lc_product_id']}: {$c['lc_name_ko']} / {$c['lc_name_en']})";

    // 낱개코드를 이미 sku로 쓰는 다른 상품이 있는지 확인 (충돌 = 별도 병합 필요)
    $chk = $conn->prepare("SELECT id, name_ko, name_en FROM products WHERE sku = ? AND id <> ? LIMIT 1");
    $chk->bind_param('si', $c['barcode_unit'], $c['product_id']);
    $chk->execute();
    $existing = $chk->get_result()->fetch_assoc();
    $chk->close();

    if ($existing) {
        echo "[병합필요] {$label}\n";
        echo "    낱개코드 {$c['barcode_unit']} 는 이미 다른 상품 #{$existing['id']} ({$existing['name_ko']})의 SKU 입니다.\n";
        echo "    -> 자동 처리하지 않음. logistics/sql/fix_merge_duplicate_product_*.php 방식으로 수동 병합 검토 필요.\n";
        $conflict++;
        continue;
    }

    if (!$apply) {
        echo "[교정대상] {$label} (진단 모드 — 미실행. ?apply=1 로 실행)\n";
        $fixed++;
        continue;
    }

    $upd = $conn->prepare("UPDATE products SET sku = ? WHERE id = ? AND sku = ?");
    $upd->bind_param('sis', $c['barcode_unit'], $c['product_id'], $c['product_sku']);
    if ($upd->execute() && $upd->affected_rows === 1) {
        echo "[교정완료] {$label}\n";
        $fixed++;
    } else {
        echo "[교정실패] {$label} — " . $conn->error . "\n";
    }
    $upd->close();
}

echo "\n요약: 교정" . ($apply ? '완료' : '대상') . " {$fixed}건, 병합필요(스킵) {$conflict}건\n";
if (!$apply && $fixed > 0) {
    echo "\n실제로 교정하려면 ?apply=1 을 붙여 다시 실행하세요.\n";
}
echo "</pre>";

$conn->close();
?>
<br><a href="purchase_management.php">매입 관리로 이동</a>
