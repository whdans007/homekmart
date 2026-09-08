<?php
/**
 * Design Ref: homekmart-store-config §3.2 — store_shift_settings 테이블 생성 + 전 점포 3교대 기본값 시드
 * 시드값(매출 계열 채택, Plan §10 Q5): gy 00:00-08:00 / morning 08:00-17:00 / mid 17:00-00:00
 */

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';

ensure_logged_in();
if ($_SESSION['role'] !== 'super_admin') {
    die("이 스크립트는 super_admin만 실행할 수 있습니다.");
}

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>store_shift_settings 마이그레이션</title></head><body>";
echo "<h1>store_shift_settings 테이블 생성 + 시드</h1>";
echo "<pre>";

$conn = get_db_connection();

if (!$conn) {
    die("데이터베이스 연결 실패: " . mysqli_connect_error());
}

try {
    $conn->autocommit(false);

    echo "[1/3] store_shift_settings 테이블 존재 여부 확인 중...\n";
    $check_table = $conn->query("SHOW TABLES LIKE 'store_shift_settings'");
    $table_exists = $check_table->num_rows > 0;

    if ($table_exists) {
        echo "✓ 테이블이 이미 존재합니다. 생성 단계를 건너뜁니다.\n\n";
    } else {
        echo "[2/3] store_shift_settings 테이블 생성 중...\n";
        $sql = "CREATE TABLE `store_shift_settings` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `store_id`   INT UNSIGNED NOT NULL,
            `shift_key`  ENUM('gy','morning','mid') NOT NULL COMMENT 'sales_pos_* 테이블의 shift ENUM과 동일 집합',
            `label`      VARCHAR(20)  NOT NULL COMMENT '화면 표시명 (GY / Morning / Mid)',
            `start_time` TIME         NOT NULL,
            `end_time`   TIME         NOT NULL COMMENT 'start > end 이면 자정 넘김(overnight)으로 해석',
            `sort_order` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `updated_by` INT UNSIGNED NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_store_shift` (`store_id`, `shift_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        if ($conn->query($sql)) {
            echo "✓ store_shift_settings 테이블이 생성되었습니다.\n\n";
        } else {
            throw new Exception("테이블 생성 실패: " . $conn->error);
        }
    }

    echo "[3/3] 전 점포 × 3교대 기본값 시드 중 (매출 계열 기준)...\n";

    $shift_defaults = [
        ['shift_key' => 'gy',      'label' => 'GY',      'start' => '00:00:00', 'end' => '08:00:00', 'sort' => 1],
        ['shift_key' => 'morning', 'label' => 'Morning', 'start' => '08:00:00', 'end' => '17:00:00', 'sort' => 2],
        ['shift_key' => 'mid',     'label' => 'Mid',     'start' => '17:00:00', 'end' => '00:00:00', 'sort' => 3],
    ];

    $stores_result = $conn->query("SELECT id FROM stores");
    $store_ids = [];
    while ($row = $stores_result->fetch_assoc()) {
        $store_ids[] = (int)$row['id'];
    }
    echo "  대상 점포 수: " . count($store_ids) . "\n";

    $insert_stmt = $conn->prepare(
        "INSERT IGNORE INTO store_shift_settings (store_id, shift_key, label, start_time, end_time, sort_order)
         VALUES (?, ?, ?, ?, ?, ?)"
    );

    $seeded = 0;
    foreach ($store_ids as $store_id) {
        foreach ($shift_defaults as $d) {
            $insert_stmt->bind_param(
                'issssi',
                $store_id, $d['shift_key'], $d['label'], $d['start'], $d['end'], $d['sort']
            );
            $insert_stmt->execute();
            if ($insert_stmt->affected_rows > 0) {
                $seeded++;
            }
        }
    }
    $insert_stmt->close();
    echo "  신규 시드 행: {$seeded}건 (기존 행은 INSERT IGNORE로 건너뜀 — 재실행 안전)\n\n";

    $conn->commit();

    echo "========================================\n";
    echo "✅ 마이그레이션이 성공적으로 완료되었습니다!\n";
    echo "========================================\n\n";

    echo "현재 store_shift_settings 행 수: ";
    $count_result = $conn->query("SELECT COUNT(*) AS cnt FROM store_shift_settings");
    echo $count_result->fetch_assoc()['cnt'] . "\n";

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
