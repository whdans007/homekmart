<?php
/** 공급처 등록자와 등록 점포 스냅샷 컬럼을 추가하는 멱등 마이그레이션입니다. */
require_once __DIR__ . '/config/db_config.php';

$is_cli = PHP_SAPI === 'cli';
if (!$is_cli) {
    require_once __DIR__ . '/lib/session_helper.php';
    ensure_logged_in();
    if (($_SESSION['role'] ?? '') !== 'super_admin') {
        http_response_code(403);
        exit('super_admin 권한이 필요합니다.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$conn = get_db_connection();
$columns = [
    'created_by' => 'INT UNSIGNED NULL',
    'created_by_name' => 'VARCHAR(100) NULL',
    'created_store_id' => 'INT NULL',
    'created_store_name' => 'VARCHAR(100) NULL',
];

try {
    foreach ($columns as $column => $definition) {
        $check = $conn->prepare(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = ?"
        );
        $check->bind_param('s', $column);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();
        if (!$exists) {
            $conn->query("ALTER TABLE suppliers ADD COLUMN `{$column}` {$definition}");
            echo "추가 완료: {$column}\n";
        } else {
            echo "이미 존재함: {$column}\n";
        }
    }
    echo "공급처 감사 정보 마이그레이션이 완료되었습니다.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo '마이그레이션 실패: ' . $e->getMessage() . "\n";
    exit(1);
} finally {
    $conn->close();
}
