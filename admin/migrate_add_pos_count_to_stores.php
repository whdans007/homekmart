<?php
/**
 * Design Ref: homekmart-store-config §3.1 — stores 테이블에 pos_count(점포별 POS 대수) 컬럼 추가
 */

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';

// 관리자만 실행 가능
ensure_logged_in();
if ($_SESSION['role'] !== 'super_admin') {
    die("이 스크립트는 super_admin만 실행할 수 있습니다.");
}

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Stores 마이그레이션</title></head><body>";
echo "<h1>stores 테이블 pos_count 추가 마이그레이션</h1>";
echo "<pre>";

$conn = get_db_connection();

if (!$conn) {
    die("데이터베이스 연결 실패: " . mysqli_connect_error());
}

try {
    $conn->autocommit(false);

    echo "[1/2] pos_count 컬럼 존재 여부 확인 중...\n";
    $check_column = $conn->query("SHOW COLUMNS FROM stores LIKE 'pos_count'");

    if ($check_column->num_rows > 0) {
        echo "✓ pos_count 컬럼이 이미 존재합니다. 마이그레이션을 건너뜁니다.\n";
        $conn->rollback();
        echo "</pre></body></html>";
        exit;
    }

    echo "✓ pos_count 컬럼이 존재하지 않습니다. 마이그레이션을 진행합니다.\n\n";

    echo "[2/2] pos_count 컬럼 추가 중...\n";
    $sql = "ALTER TABLE `stores`
            ADD COLUMN `pos_count` TINYINT UNSIGNED NOT NULL DEFAULT 2 COMMENT '점포별 POS 단말 대수 (기본 2)' AFTER `representative_user_id`";

    if ($conn->query($sql)) {
        echo "✓ pos_count 컬럼이 추가되었습니다.\n\n";
    } else {
        throw new Exception("컬럼 추가 실패: " . $conn->error);
    }

    $conn->commit();

    echo "========================================\n";
    echo "✅ 마이그레이션이 성공적으로 완료되었습니다!\n";
    echo "========================================\n\n";

    echo "변경된 테이블 구조:\n";
    $result = $conn->query("SHOW COLUMNS FROM stores");
    while ($row = $result->fetch_assoc()) {
        echo "  - {$row['Field']}: {$row['Type']} " .
             ($row['Null'] === 'NO' ? 'NOT NULL' : 'NULL') .
             ($row['Key'] ? " [{$row['Key']}]" : '') . "\n";
    }

    $conn->autocommit(true);
    $conn->close();

    echo "</pre>";
    echo "<p><strong>중요:</strong> 마이그레이션 완료 후 이 파일을 삭제하거나 이름을 변경하세요.</p>";
    echo "</body></html>";

} catch (Exception $e) {
    $conn->rollback();
    echo "\n❌ 오류 발생: " . $e->getMessage() . "\n";
    echo "모든 변경사항이 롤백되었습니다.\n";
    echo "</pre>";
    $conn->autocommit(true);
    $conn->close();
}
?>
