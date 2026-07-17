<?php
/**
 * 점포간 재고 이동(신선상품) 소숫점 수량 지원을 위한 마이그레이션
 * store_transfer_items.quantity / inventory.quantity / inventory_expirations.quantity
 * int(11) -> decimal(10,2)
 * 실행일: 2026-07-16
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

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>수량 DECIMAL 마이그레이션</title></head><body>";
echo "<h1>재고 수량 DECIMAL(10,2) 마이그레이션</h1>";
echo "<pre>";

$conn = get_db_connection();

if (!$conn) {
    die("데이터베이스 연결 실패: " . mysqli_connect_error());
}

$targets = [
    'store_transfer_items' => "MODIFY `quantity` decimal(10,2) NOT NULL COMMENT '이동 수량(소숫점 둘째자리)'",
    'inventory'            => "MODIFY `quantity` decimal(10,2) NOT NULL DEFAULT 0 COMMENT '재고 수량(소숫점 둘째자리)'",
    'inventory_expirations'=> "MODIFY `quantity` decimal(10,2) NOT NULL DEFAULT 0 COMMENT '음수 허용(안전 장치), 소숫점 둘째자리'",
];

try {
    $conn->autocommit(false);

    $step = 1;
    $total = count($targets);

    foreach ($targets as $table => $modify_clause) {
        echo "[{$step}/{$total}] {$table}.quantity 컬럼 타입 확인 중...\n";

        $check_column = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE 'quantity'");
        $column_info = $check_column ? $check_column->fetch_assoc() : null;

        if (!$column_info) {
            throw new Exception("{$table} 테이블에서 quantity 컬럼을 찾을 수 없습니다.");
        }

        if (stripos($column_info['Type'], 'decimal') === 0) {
            echo "✓ {$table}.quantity 는 이미 decimal 타입입니다 ({$column_info['Type']}). 건너뜁니다.\n\n";
            $step++;
            continue;
        }

        // decimal(10,2)의 허용 범위(±99,999,999.99)를 벗어나는 값이 있으면
        // ALTER가 실패하므로 사전에 찾아서 보고하고, 버그로 인한 값(예: INT32 최소값 근처로
        // 망가진 재고)은 0으로 리셋한 뒤 진행한다.
        echo "  decimal(10,2) 범위(±99,999,999.99) 초과 값 사전 점검 중...\n";
        $range_sql = "quantity > 99999999 OR quantity < -99999999";
        $range_check = $conn->query("SELECT * FROM `{$table}` WHERE {$range_sql} LIMIT 20");
        if ($range_check && $range_check->num_rows > 0) {
            echo "⚠ {$table}에서 범위를 벗어나는 값을 발견하여 0으로 리셋합니다:\n";
            while ($bad_row = $range_check->fetch_assoc()) {
                echo "    " . json_encode($bad_row, JSON_UNESCAPED_UNICODE) . "\n";
            }
            $reset_stmt = $conn->prepare("UPDATE `{$table}` SET quantity = 0 WHERE {$range_sql}");
            if (!$reset_stmt->execute()) {
                throw new Exception("{$table}.quantity 리셋 실패: " . $conn->error);
            }
            echo "  ✓ {$reset_stmt->affected_rows}건 리셋 완료 (실제 재고는 별도 실사 후 재입력 필요).\n";
        } else {
            echo "  ✓ 범위를 벗어나는 값 없음.\n";
        }

        echo "  현재 타입: {$column_info['Type']} -> decimal(10,2) 로 변경합니다...\n";

        $sql = "ALTER TABLE `{$table}` {$modify_clause}";
        if ($conn->query($sql)) {
            echo "✓ {$table}.quantity 변경 완료.\n\n";
        } else {
            throw new Exception("{$table}.quantity 변경 실패: " . $conn->error);
        }

        $step++;
    }

    $conn->commit();

    echo "========================================\n";
    echo "✅ 마이그레이션이 성공적으로 완료되었습니다!\n";
    echo "========================================\n\n";

    echo "변경된 테이블 구조:\n";
    foreach (array_keys($targets) as $table) {
        echo "- {$table}\n";
        $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE 'quantity'");
        if ($row = $result->fetch_assoc()) {
            echo "    - {$row['Field']}: {$row['Type']} " .
                 ($row['Null'] === 'NO' ? 'NOT NULL' : 'NULL') . "\n";
        }
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
