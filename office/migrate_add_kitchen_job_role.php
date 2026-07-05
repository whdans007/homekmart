<?php
/**
 * 직무(job_role) ENUM 에 'kitchen' 추가 마이그레이션
 * 대상: office_employees, office_schedule_items
 * 실행일: 2026-06-26 · 실행 후 이 파일은 삭제/이름변경 권장.
 */

require_once __DIR__ . '/../config/db_config.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>KITCHEN 직무 추가 마이그레이션</title></head><body>";
echo "<h1>job_role ENUM 에 'kitchen' 추가</h1><pre>";

$conn = get_db_connection();
if (!$conn) {
    die("데이터베이스 연결 실패: " . mysqli_connect_error());
}

$enum = "ENUM('cashier','patcher','butcher','kitchen','driver','merchandiser','supervisor','admin') NOT NULL";
$targets = ['office_employees', 'office_schedule_items'];

try {
    foreach ($targets as $i => $tbl) {
        $n = $i + 1; $cnt = count($targets);
        echo "[{$n}/{$cnt}] {$tbl}.job_role 확인 중...\n";
        $res = $conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'job_role'");
        $col = $res ? $res->fetch_assoc() : null;
        if ($col && strpos($col['Type'], "'kitchen'") !== false) {
            echo "  ✓ 이미 'kitchen' 포함 — 건너뜁니다.\n\n";
            continue;
        }
        echo "  → 'kitchen' 추가 중...\n";
        if ($conn->query("ALTER TABLE {$tbl} MODIFY job_role {$enum}")) {
            echo "  ✓ {$tbl} 갱신 완료.\n\n";
        } else {
            throw new Exception("{$tbl} 변경 실패: " . $conn->error);
        }
    }

    echo "========================================\n";
    echo "✅ 마이그레이션이 성공적으로 완료되었습니다!\n";
    echo "========================================\n\n";

    foreach ($targets as $tbl) {
        $res = $conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'job_role'");
        $col = $res ? $res->fetch_assoc() : null;
        echo "{$tbl}.job_role: " . ($col['Type'] ?? '?') . "\n";
    }
} catch (Exception $e) {
    echo "\n❌ 오류 발생: " . $e->getMessage() . "\n";
}

$conn->close();
echo "</pre>";
echo "<p><strong>중요:</strong> 마이그레이션 완료 후 이 파일을 삭제하거나 이름을 변경하세요.</p>";
echo "</body></html>";
