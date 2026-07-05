<?php
// Design Ref: pack-unit.design.md §3.2 — v22 러너 (재실행 가드 + 적용 전/후 검증)
// Plan SC-02: 3개 ENUM에 PACK 포함, 기존 BOX/PCS 행 무변경

// 진단용: 이 스크립트 한정 에러 표시 (원인 파악을 위해 명시적으로 켠다)
ini_set('display_errors', '1');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // 쿼리 실패를 예외로

require_once __DIR__ . '/../../config/db_config.php';

header('Content-Type: text/html; charset=utf-8');
echo "<pre>\n";
echo "=== Migration v22: PACK 단위 추가 ===\n\n";

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conn->set_charset(DB_CHARSET);

    // 대상 컬럼 정의
    $targets = [
        ['table' => 'lc_inbound',     'column' => 'inbound_unit'],
        ['table' => 'lc_inventory',   'column' => 'unit'],
        ['table' => 'lc_order_items', 'column' => 'order_unit'],
    ];

    // 컬럼 타입 조회 헬퍼
    $column_type = function (mysqli $conn, string $table, string $column): ?string {
        $t = $conn->real_escape_string($table);
        $c = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
        $row = $res ? $res->fetch_assoc() : null;
        return $row['Type'] ?? null;
    };

    // 행수 조회 헬퍼 (무변경 검증용)
    $row_count = function (mysqli $conn, string $table): int {
        $t = $conn->real_escape_string($table);
        return (int)$conn->query("SELECT COUNT(*) FROM `$t`")->fetch_row()[0];
    };

    // ── 1) 재실행 가드: 이미 PACK 포함 시 skip ──────────────────
    $already = true;
    foreach ($targets as $t) {
        $type = $column_type($conn, $t['table'], $t['column']);
        echo "[적용 전] {$t['table']}.{$t['column']} = " . ($type ?? '(컬럼 없음)') . "\n";
        if ($type === null || stripos($type, "'PACK'") === false) {
            $already = false;
        }
    }
    echo "\n";

    if ($already) {
        echo "✅ 이미 PACK 단위가 모든 대상 ENUM에 존재합니다 — 마이그레이션 skip.\n";
        $conn->close();
        echo "</pre>";
        exit;
    }

    // ── 2) 적용 전 행수 스냅샷 (무변경 검증) ────────────────────
    $before = [];
    foreach ($targets as $t) {
        $before[$t['table']] = $row_count($conn, $t['table']);
    }

    // ── 3) 마이그레이션 실행 (ALTER 직접 정의 — 주석 파싱 이슈 회피) ──
    $alters = [
        "ALTER TABLE lc_inbound     MODIFY inbound_unit ENUM('BOX','PACK','PCS') NOT NULL DEFAULT 'PCS' COMMENT '입고 단위'",
        "ALTER TABLE lc_inventory   MODIFY unit         ENUM('BOX','PACK','PCS') NOT NULL DEFAULT 'PCS' COMMENT 'lot 단위'",
        "ALTER TABLE lc_order_items MODIFY order_unit   ENUM('BOX','PACK','PCS') NOT NULL DEFAULT 'PCS' COMMENT '주문/출고 단위'",
    ];
    foreach ($alters as $stmt) {
        $conn->query($stmt); // 실패 시 예외 → 아래 catch
        echo "OK: " . substr(preg_replace('/\s+/', ' ', $stmt), 0, 90) . "...\n";
    }
    echo "\n";

    // ── 4) 적용 후 검증: ENUM 정의 + 행수 무변경 ────────────────
    $ok = true;
    foreach ($targets as $t) {
        $type = $column_type($conn, $t['table'], $t['column']);
        $has_pack  = ($type !== null && stripos($type, "'PACK'") !== false);
        $after     = $row_count($conn, $t['table']);
        $unchanged = ($after === $before[$t['table']]);
        echo sprintf(
            "[적용 후] %-22s PACK포함=%s  행수 %d→%d %s\n",
            "{$t['table']}.{$t['column']}",
            $has_pack ? 'Y' : 'N',
            $before[$t['table']], $after,
            $unchanged ? '(무변경 ✅)' : '(⚠️ 변경됨)'
        );
        if (!$has_pack || !$unchanged) $ok = false;
    }

    echo "\n" . ($ok ? "✅ 마이그레이션 v22 완료 (SC-02 충족)." : "❌ 마이그레이션 v22 검증 실패 — 위 로그 확인.") . "\n";
    $conn->close();

} catch (Throwable $e) {
    echo "\n❌ 예외 발생: " . $e->getMessage() . "\n";
    echo "  파일: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "</pre>";
