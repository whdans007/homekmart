<?php
/**
 * KIM'S MALL 공유 상품 연결 마이그레이션 v23
 *
 * - kw_products.product_id (admin products.id 연결 키) 컬럼 + 일반 인덱스 추가
 * - barcode_unit = products.sku 정확일치(TRIM, 대소문자 구분) + 양쪽 모두 유일한 건만 자동 연결
 * - 읽기 전용 VIEW kw_products_v 생성 (이름만 admin 우선, 나머지는 창고 값 유지)
 *
 * 실행: 로그인한 창고 관리자 브라우저에서
 *   기본(DRY-RUN, 아무것도 변경 안 함): .../kimsmall_wherehouse/sql/run_migration_v23.php
 *   적용:                               .../run_migration_v23.php?apply=1&confirm=KW-V23
 * 재실행 안전: 이미 연결된 행은 덮어쓰지 않음.
 *
 * 수동 롤백 SQL (이 스크립트는 자동 롤백을 실행하지 않음):
 *   DROP VIEW IF EXISTS kw_products_v;
 *   ALTER TABLE kw_products DROP INDEX idx_kw_products_product_id;
 *   ALTER TABLE kw_products DROP COLUMN product_id;
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../config/db.php';

kw_session_start();
if (empty($_SESSION['user_id'])) {
    header('Location: ' . LC_BASE . '/login.php');
    exit;
}
if (!kw_is_admin()) {
    http_response_code(403);
    exit('관리자 권한이 필요합니다.');
}

header('Content-Type: text/html; charset=utf-8');
echo "<!doctype html><html lang=\"ko\"><meta charset=\"utf-8\"><title>마이그레이션 v23</title><body><pre>\n";
$apply = (($_GET['apply'] ?? '') === '1' && ($_GET['confirm'] ?? '') === 'KW-V23');
$mode  = $apply ? '적용' : 'DRY-RUN';
echo "=== 공유 상품 연결 마이그레이션 v23 ===\n";
echo "모드: {$mode}\n";
if (!$apply) {
    echo "(변경 없이 점검만 합니다. 적용하려면 ?apply=1&confirm=KW-V23 을 붙이세요)\n";
}

try {
    $conn   = get_lc_db();
    $server = $conn->query('SELECT DATABASE() AS db_name, @@hostname AS db_host')->fetch_assoc();
    echo '접속 DB: ' . htmlspecialchars((string)$server['db_name'], ENT_QUOTES, 'UTF-8')
        . ' / 호스트: ' . htmlspecialchars((string)$server['db_host'], ENT_QUOTES, 'UTF-8') . "\n\n";

    $run = static function (mysqli $db, string $sql, string $types = '', array $params = []): mysqli_stmt {
        $stmt = $db->prepare($sql);
        if ($types !== '') $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt;
    };
    $tableExists = static function (mysqli $db, string $table) use ($run): bool {
        $stmt = $run($db, 'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', 's', [$table]);
        $exists = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        return $exists;
    };
    $columnExists = static function (mysqli $db, string $table, string $column) use ($run): bool {
        $stmt = $run($db, 'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', 'ss', [$table, $column]);
        $exists = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        return $exists;
    };
    $indexExists = static function (mysqli $db, string $table, string $column) use ($run): bool {
        $stmt = $run($db, 'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1', 'ss', [$table, $column]);
        $exists = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        return $exists;
    };
    $countQuery = static function (mysqli $db, string $sql): int {
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $count = (int)$stmt->get_result()->fetch_row()[0];
        $stmt->close();
        return $count;
    };

    // ── 사전점검 ──
    $hasKw       = $tableExists($conn, 'kw_products');
    $hasProducts = $tableExists($conn, 'products');
    echo "[사전점검]\n";
    echo 'kw_products 테이블: ' . ($hasKw ? '있음' : '없음') . "\n";
    echo 'products 테이블: ' . ($hasProducts ? '있음' : '없음') . "\n";
    $hasProductId      = $hasKw && $columnExists($conn, 'kw_products', 'product_id');
    $hasProductIdIndex = $hasProductId && $indexExists($conn, 'kw_products', 'product_id');
    echo 'kw_products.product_id 컬럼: ' . ($hasProductId ? '있음' : '없음') . "\n";
    echo 'kw_products.product_id 인덱스: ' . ($hasProductIdIndex ? '있음' : '없음') . "\n";

    $required = [
        'kw_products'  => ['id', 'name_en', 'name_ko', 'barcode_unit', 'is_active'],
        'products'     => ['id', 'sku', 'name_en', 'name_ko', 'image_url'],
        'kw_inventory' => ['product_id', 'quantity_remain'],
    ];
    $missing = [];
    foreach ($required as $table => $columns) {
        if (!$tableExists($conn, $table)) { $missing[] = "테이블 {$table}"; continue; }
        foreach ($columns as $column) {
            if (!$columnExists($conn, $table, $column)) $missing[] = "컬럼 {$table}.{$column}";
        }
    }
    if ($tableExists($conn, 'kw_products_v')) {
        $stmt = $run($conn, 'SELECT table_type FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', 's', ['kw_products_v']);
        $viewType = $stmt->get_result()->fetch_row()[0] ?? '';
        $stmt->close();
        if ($viewType === 'BASE TABLE') $missing[] = 'kw_products_v 이름의 TABLE이 이미 존재합니다';
    }
    if ($missing) {
        echo '사전검증 실패: ' . implode(', ', $missing) . "\n쓰기 작업은 시작하지 않았습니다.\n";
        $conn->close();
        echo "</pre></body></html>";
        exit;
    }

    $kwRows       = $countQuery($conn, 'SELECT COUNT(*) FROM kw_products');
    $emptyBarcode = $countQuery($conn, "SELECT COUNT(*) FROM kw_products WHERE barcode_unit IS NULL OR TRIM(barcode_unit) = ''");
    $kwDupValues  = $countQuery($conn, "SELECT COUNT(*) FROM (SELECT BINARY TRIM(barcode_unit) AS v FROM kw_products WHERE barcode_unit IS NOT NULL AND TRIM(barcode_unit) <> '' GROUP BY BINARY TRIM(barcode_unit) HAVING COUNT(*) > 1) d");
    $prodDupValues = $countQuery($conn, "SELECT COUNT(*) FROM (SELECT BINARY TRIM(sku) AS v FROM products WHERE sku IS NOT NULL AND TRIM(sku) <> '' GROUP BY BINARY TRIM(sku) HAVING COUNT(*) > 1) d");
    $exactMatches = $countQuery($conn, "SELECT COUNT(*) FROM kw_products kw WHERE kw.barcode_unit IS NOT NULL AND TRIM(kw.barcode_unit) <> '' AND EXISTS (SELECT 1 FROM products p WHERE BINARY TRIM(p.sku) = BINARY TRIM(kw.barcode_unit))");
    echo "kw_products 행 수: {$kwRows}\n";
    echo "barcode_unit NULL/공백 수(TRIM): {$emptyBarcode}\n";
    echo "중복 kw barcode_unit 값 수: {$kwDupValues}\n";
    echo "중복 products.sku 값 수: {$prodDupValues}\n";
    echo "정확 일치 수(TRIM, 대소문자 구분): {$exactMatches}\n";
    echo '미일치 수: ' . ($kwRows - $exactMatches) . "\n\n";

    // ── 적용 ──
    if ($apply) {
        if (!$hasProductId) {
            $conn->query('ALTER TABLE kw_products ADD COLUMN product_id INT UNSIGNED NULL');
            echo "product_id 컬럼을 추가했습니다.\n";
            $hasProductId = true;
        }
        if (!$hasProductIdIndex) {
            $conn->query('ALTER TABLE kw_products ADD INDEX idx_kw_products_product_id (product_id)');
            echo "product_id 인덱스를 추가했습니다.\n";
        }
        $conn->begin_transaction();
        try {
            // 이미 연결된 행(product_id NOT NULL)은 건드리지 않고, 양쪽 모두 유일한 sku만 연결
            $updateSql = "UPDATE kw_products kw
                JOIN (
                    SELECT BINARY TRIM(sku) AS match_code, MIN(id) AS product_id
                    FROM products
                    WHERE sku IS NOT NULL AND TRIM(sku) <> ''
                    GROUP BY BINARY TRIM(sku)
                    HAVING COUNT(*) = 1
                ) pu ON pu.match_code = BINARY TRIM(kw.barcode_unit)
                JOIN (
                    SELECT BINARY TRIM(barcode_unit) AS match_code
                    FROM kw_products
                    WHERE barcode_unit IS NOT NULL AND TRIM(barcode_unit) <> ''
                    GROUP BY BINARY TRIM(barcode_unit)
                    HAVING COUNT(*) = 1
                ) ku ON ku.match_code = BINARY TRIM(kw.barcode_unit)
                SET kw.product_id = pu.product_id
                WHERE kw.product_id IS NULL";
            $stmt = $conn->prepare($updateSql);
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();
            $conn->commit();
            echo "연결 업데이트: {$changed}건\n";
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    } else {
        echo "DRY-RUN: 데이터와 스키마를 변경하지 않았습니다.\n";
    }

    // ── 미연결 리포트 (읽기 전용, DRY-RUN/적용 공통) ──
    echo "\n[연결되지 않는 창고 상품 - 수동 확인 필요]\n";
    if ($hasProductId) {
        $unlinkedWhere = 'kw.product_id IS NULL';
    } else {
        // 컬럼이 아직 없으면 "적용 시 연결되지 않을 행"을 계산해서 보여줌
        $unlinkedWhere = "NOT (
            kw.barcode_unit IS NOT NULL AND TRIM(kw.barcode_unit) <> ''
            AND (SELECT COUNT(*) FROM products p WHERE BINARY TRIM(p.sku) = BINARY TRIM(kw.barcode_unit)) = 1
            AND (SELECT COUNT(*) FROM kw_products k2 WHERE BINARY TRIM(k2.barcode_unit) = BINARY TRIM(kw.barcode_unit)) = 1
        )";
    }
    $reportSql = "SELECT kw.id, kw.name_en, kw.name_ko, kw.barcode_unit, kw.is_active,
                         COALESCE(inv.quantity_remain, 0) AS quantity_remain
                  FROM kw_products kw
                  LEFT JOIN (SELECT product_id, SUM(quantity_remain) AS quantity_remain FROM kw_inventory GROUP BY product_id) inv
                    ON inv.product_id = kw.id
                  WHERE {$unlinkedWhere} ORDER BY kw.id";
    echo "id\tname_en\tname_ko\tbarcode_unit\tis_active\t재고합계\n";
    $stmt = $conn->prepare($reportSql);
    $stmt->execute();
    $rows = $stmt->get_result();
    while ($row = $rows->fetch_assoc()) {
        echo implode("\t", array_map(static fn($v) => htmlspecialchars(str_replace(["\t", "\r", "\n"], ' ', (string)($v ?? 'NULL')), ENT_QUOTES, 'UTF-8'), $row)) . "\n";
    }
    $stmt->close();

    echo "\n[중복 SKU 예외]\n구분\t정규화 코드\t행 수\n";
    $duplicateSql = "SELECT 'kw barcode_unit' AS kind, BINARY TRIM(barcode_unit) AS code_value, COUNT(*) AS row_count
                     FROM kw_products WHERE barcode_unit IS NOT NULL AND TRIM(barcode_unit) <> ''
                     GROUP BY BINARY TRIM(barcode_unit) HAVING COUNT(*) > 1
                     UNION ALL
                     SELECT 'products.sku', BINARY TRIM(sku), COUNT(*)
                     FROM products WHERE sku IS NOT NULL AND TRIM(sku) <> ''
                     GROUP BY BINARY TRIM(sku) HAVING COUNT(*) > 1";
    $stmt = $conn->prepare($duplicateSql);
    $stmt->execute();
    $rows = $stmt->get_result();
    while ($row = $rows->fetch_assoc()) {
        echo htmlspecialchars(implode("\t", array_map('strval', $row)), ENT_QUOTES, 'UTF-8') . "\n";
    }
    $stmt->close();

    // ── VIEW 생성 ──
    if ($apply) {
        // kw_products 실제 컬럼을 그대로 노출하고, 이름 2개 컬럼만 공유 상품 값을 우선 사용
        $stmt = $run($conn, 'SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position', 's', ['kw_products']);
        $columns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $projection = [];
        foreach ($columns as $columnRow) {
            $column = $columnRow['column_name'] ?? $columnRow['COLUMN_NAME'];
            if ($column === 'name_en')      $projection[] = "COALESCE(NULLIF(TRIM(p.name_en), ''), kw.name_en) AS `name_en`";
            elseif ($column === 'name_ko')  $projection[] = "COALESCE(NULLIF(TRIM(p.name_ko), ''), kw.name_ko) AS `name_ko`";
            else                            $projection[] = "kw.`{$column}` AS `{$column}`";
        }
        $projection[] = 'p.sku AS sku';
        $projection[] = 'p.image_url AS shared_image_url';
        $conn->query('CREATE OR REPLACE VIEW kw_products_v AS SELECT ' . implode(', ', $projection) . ' FROM kw_products kw LEFT JOIN products p ON p.id = kw.product_id');
        echo "\nVIEW kw_products_v 를 생성/갱신했습니다.\n";

        // ── 적용 후 검증 ──
        echo "\n[적용 후 검증]\n";
        $linked        = $countQuery($conn, 'SELECT COUNT(*) FROM kw_products WHERE product_id IS NOT NULL');
        $unlinked      = $countQuery($conn, 'SELECT COUNT(*) FROM kw_products WHERE product_id IS NULL');
        $mismatch      = $countQuery($conn, "SELECT COUNT(*) FROM kw_products kw JOIN products p ON p.id = kw.product_id WHERE BINARY TRIM(p.sku) <> BINARY TRIM(kw.barcode_unit) OR p.sku IS NULL OR kw.barcode_unit IS NULL");
        $shared        = $countQuery($conn, 'SELECT COUNT(*) FROM (SELECT product_id FROM kw_products WHERE product_id IS NOT NULL GROUP BY product_id HAVING COUNT(*) > 1) d');
        $viewCount     = $countQuery($conn, 'SELECT COUNT(*) FROM kw_products_v');
        $viewEmptyName = $countQuery($conn, "SELECT COUNT(*) FROM kw_products_v WHERE name_en IS NULL OR TRIM(name_en) = ''");
        echo "연결 수: {$linked}\n";
        echo "미연결 수: {$unlinked}\n";
        echo "sku와 barcode_unit(TRIM) 불일치 연결: {$mismatch} (0이어야 정상)\n";
        echo "같은 product_id를 공유하는 kw 그룹 수: {$shared} (0이어야 정상)\n";
        echo 'VIEW 조회: ' . ($viewCount === $kwRows ? '성공' : '행 수 불일치') . " (VIEW {$viewCount} / kw_products {$kwRows})\n";
        echo "VIEW name_en NULL/공백 수: {$viewEmptyName}\n";
    }

    $conn->close();
} catch (Throwable $e) {
    echo "\n오류: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "\n";
}
echo "</pre></body></html>";
