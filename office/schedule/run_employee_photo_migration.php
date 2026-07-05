<?php
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();

$conn = get_db_connection();

$result = $conn->query("SHOW COLUMNS FROM office_employees LIKE 'photo'");
if ($result && $result->num_rows === 0) {
    $conn->query("ALTER TABLE office_employees ADD COLUMN photo VARCHAR(255) NULL DEFAULT NULL AFTER job_role");
    echo "OK: photo 컬럼 추가 완료<br>";
} else {
    echo "SKIP: photo 컬럼이 이미 존재합니다<br>";
}

$r2 = $conn->query("SHOW COLUMNS FROM office_employees LIKE 'inactive_reason'");
if ($r2 && $r2->num_rows === 0) {
    $conn->query("ALTER TABLE office_employees ADD COLUMN inactive_reason VARCHAR(255) NULL DEFAULT NULL AFTER status");
    echo "OK: inactive_reason 컬럼 추가 완료<br>";
} else {
    echo "SKIP: inactive_reason 컬럼이 이미 존재합니다<br>";
}

$conn->close();
echo "Done.";
