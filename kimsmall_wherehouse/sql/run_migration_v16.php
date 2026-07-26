<?php
/**
 * Migration v16 실행 스크립트
 * 용법: php run_migration_v16.php
 */
require_once __DIR__ . '/../config/db.php';

echo "[Migration v16 시작]\n";

try {
    $conn = get_lc_db();

    // SQL 파일 읽기
    $sql = file_get_contents(__DIR__ . '/kw_migration_v16.sql');

    // 주석과 빈 줄 제거, 쿼리 분리
    $queries = preg_split('/;\s*(?=SELECT|ALTER|CREATE|DROP|UPDATE|INSERT)/i', $sql);
    $queries = array_filter(array_map('trim', $queries));

    foreach ($queries as $query) {
        if (empty($query)) continue;

        echo "Executing: " . substr($query, 0, 50) . "...\n";

        if (strpos($query, 'SELECT') !== false) {
            // SELECT 쿼리는 결과 출력
            $result = $conn->query($query);
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    echo "  → " . implode(', ', $row) . "\n";
                }
            }
        } else {
            // ALTER/CREATE/DROP 등
            if (!$conn->query($query)) {
                throw new Exception("Query failed: " . $conn->error);
            }
        }
    }

    $conn->close();
    echo "\n✓ Migration v16 완료\n";

} catch (Exception $e) {
    echo "\n✗ 에러: " . $e->getMessage() . "\n";
    exit(1);
}
