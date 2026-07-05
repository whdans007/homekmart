<?php
/**
 * 도매판매 원가/판매가 진단 스크립트 (일회성)
 * 특정 바코드 상품의 wholesale_products / inventory 값을 점포별로 출력하여
 * "원가 0 → 판매가 90% 할인" 규칙이 왜 적용/미적용 되는지 확인한다.
 *
 * 사용법: admin/diag_wholesale_price.php?barcode=8801045575865
 */

require_once __DIR__ . '/../config/db_config.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

$barcode = $_GET['barcode'] ?? '8801045575865';

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>도매가 진단</title>";
echo "<style>body{font-family:'Malgun Gothic',sans-serif;margin:20px;} table{border-collapse:collapse;margin:10px 0;} th,td{border:1px solid #ccc;padding:5px 9px;font-size:13px;} th{background:#f3f4f6;} .y{color:#16a34a;font-weight:bold;} .n{color:#dc2626;font-weight:bold;}</style>";
echo "</head><body>";
echo "<h1>도매가 진단 — 바코드: " . htmlspecialchars($barcode) . "</h1>";

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 상품 찾기 (sku 또는 wholesale_skus)
    $like = '%"' . $barcode . '"%';
    $psql = "
        SELECT DISTINCT p.id, p.sku, p.name_ko, p.name_en, p.pieces_per_box
        FROM products p
        LEFT JOIN wholesale_products wp ON wp.product_id = p.id
        WHERE p.sku = ? OR wp.wholesale_skus LIKE ?
        LIMIT 5
    ";
    $pstmt = $pdo->prepare($psql);
    $pstmt->execute([$barcode, $like]);
    $products = $pstmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($products)) {
        echo "<p class='n'>해당 바코드로 상품을 찾지 못했습니다.</p></body></html>";
        exit;
    }

    foreach ($products as $p) {
        echo "<h2>상품 #{$p['id']} — {$p['sku']} / " . htmlspecialchars($p['name_en'] ?: $p['name_ko']) . " (박스입수 {$p['pieces_per_box']})</h2>";

        // 도매상품 등록 + 인벤토리 점포별 값
        $sql = "
            SELECT
                wp.store_id            AS wp_store_id,
                wp.is_active           AS wp_active,
                COALESCE(wp.cost_price, 0)        AS wp_cost_box,
                COALESCE(wp.cost_price_piece, 0)  AS wp_cost_piece,
                wp.wholesale_price,
                COALESCE(wp.wholesale_price_piece, 0) AS wholesale_price_piece,
                i.store_id             AS inv_store_id,
                COALESCE(i.cost_price, 0)    AS inv_cost_price,
                COALESCE(i.selling_price, 0) AS inv_selling_price
            FROM wholesale_products wp
            LEFT JOIN inventory i ON i.product_id = wp.product_id AND i.store_id = wp.store_id
            WHERE wp.product_id = ?
            ORDER BY wp.store_id
        ";
        $st = $pdo->prepare($sql);
        $st->execute([$p['id']]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            echo "<p class='n'>이 상품은 도매상품(wholesale_products)으로 등록되어 있지 않습니다. → 일반 상품 경로(이름 검색 드롭다운)로 추가됩니다.</p>";

            // 일반 상품: 인벤토리 원가/판매가를 점포별로 표시
            $isql = "
                SELECT store_id,
                       COALESCE(cost_price, 0)    AS inv_cost_price,
                       COALESCE(selling_price, 0) AS inv_selling_price
                FROM inventory
                WHERE product_id = ?
                ORDER BY store_id
            ";
            $ist = $pdo->prepare($isql);
            $ist->execute([$p['id']]);
            $invs = $ist->fetchAll(PDO::FETCH_ASSOC);

            if (empty($invs)) {
                echo "<p class='n'>인벤토리(inventory) 레코드가 없습니다 → 판매가가 없어 할인 규칙이 적용되지 않습니다.</p>";
            } else {
                echo "<table><tr><th>store_id</th><th>인벤토리원가</th><th>판매가(소매)</th><th>규칙 적용?</th><th>적용 시 도매가(판매가×0.9)</th></tr>";
                foreach ($invs as $iv) {
                    $c = (float)$iv['inv_cost_price'];
                    $s = (float)$iv['inv_selling_price'];
                    $applies = ($c <= 0 && $s > 0);
                    echo "<tr>"
                       . "<td>{$iv['store_id']}</td>"
                       . "<td>" . number_format($c) . "</td>"
                       . "<td>" . number_format($s) . "</td>"
                       . "<td class='" . ($applies ? 'y' : 'n') . "'>" . ($applies ? 'YES' : 'NO') . "</td>"
                       . "<td>" . ($applies ? number_format(round($s * 0.9)) : '-') . "</td>"
                       . "</tr>";
                }
                echo "</table>";
                echo "<p style='font-size:12px;color:#6b7280;'>규칙: 원가=0 AND 판매가&gt;0 일 때만 도매가 = 판매가 × 0.9. 본인 점포의 행 기준으로 확인하세요.</p>";
            }
            continue;
        }

        echo "<table><tr>"
           . "<th>wp.store_id</th><th>활성</th><th>원가(박스)</th><th>원가(낱개)</th><th>도매가(박스)</th><th>도매가(낱개)</th>"
           . "<th>inv.store_id</th><th>인벤토리원가</th><th>판매가(소매)</th>"
           . "<th>규칙 적용?</th><th>적용 시 도매가</th></tr>";
        foreach ($rows as $r) {
            $costBox = (float)$r['wp_cost_box'];
            $selling = (float)$r['inv_selling_price'];
            $applies = ($costBox <= 0 && $selling > 0);
            $newPrice = $applies ? round($selling * 0.9) : (float)$r['wholesale_price'];
            echo "<tr>"
               . "<td>{$r['wp_store_id']}</td>"
               . "<td>" . ($r['wp_active'] ? '1' : '0') . "</td>"
               . "<td>" . number_format($costBox) . "</td>"
               . "<td>" . number_format((float)$r['wp_cost_piece']) . "</td>"
               . "<td>" . number_format((float)$r['wholesale_price']) . "</td>"
               . "<td>" . number_format((float)$r['wholesale_price_piece']) . "</td>"
               . "<td>" . ($r['inv_store_id'] ?? '<span class=n>없음</span>') . "</td>"
               . "<td>" . number_format((float)$r['inv_cost_price']) . "</td>"
               . "<td>" . number_format($selling) . "</td>"
               . "<td class='" . ($applies ? 'y' : 'n') . "'>" . ($applies ? 'YES' : 'NO') . "</td>"
               . "<td>" . number_format($newPrice) . "</td>"
               . "</tr>";
        }
        echo "</table>";
        echo "<p style='font-size:12px;color:#6b7280;'>규칙: 원가(박스)=0 AND 판매가(소매)&gt;0 일 때만 도매가 = 판매가 × 0.9. "
           . "NO 인 경우 원인: 원가(박스)가 0이 아니거나, 판매가(소매)가 0(인벤토리 없음/미설정).</p>";
    }

} catch (Exception $e) {
    echo "<p class='n'>오류: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<p style='color:#6b7280;font-size:12px;margin-top:30px;'><strong>중요:</strong> 확인 후 이 파일을 삭제하세요.</p>";
echo "</body></html>";
?>
