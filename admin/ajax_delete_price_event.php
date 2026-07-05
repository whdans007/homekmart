<?php
session_start();
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit();
}

if (!has_permission('purchase_management')) {
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit();
}

$input    = json_decode(file_get_contents('php://input'), true);
$event_id = (int)($input['event_id'] ?? 0);

if (!$event_id) {
    echo json_encode(['success' => false, 'message' => '잘못된 요청입니다.']);
    exit();
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `price_events` (
          `id`                     INT           AUTO_INCREMENT PRIMARY KEY,
          `product_id`             INT           NOT NULL,
          `store_id`               INT           NOT NULL,
          `original_cost_price`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
          `original_selling_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
          `event_cost_price`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
          `event_selling_price`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
          `event_name`             VARCHAR(255)  DEFAULT NULL,
          `end_date`               DATE          DEFAULT NULL,
          `status`                 ENUM('active','ended','deleted') NOT NULL DEFAULT 'active',
          `created_by`             INT           NOT NULL,
          `created_at`             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `ended_at`               DATETIME      DEFAULT NULL,
          `ended_by`               INT           DEFAULT NULL,
          INDEX `idx_store_status`  (`store_id`, `status`),
          INDEX `idx_product_store` (`product_id`, `store_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $user_id  = $_SESSION['user_id'] ?? null;
    $store_id = $_SESSION['store_id'] ?? null;
    if (!$store_id && $user_id) {
        $s = $pdo->prepare("SELECT store_id FROM users WHERE id = ?");
        $s->execute([$user_id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        $store_id = $row['store_id'] ?? null;
        if ($store_id) $_SESSION['store_id'] = $store_id;
    }

    $stmt = $pdo->prepare("
        SELECT * FROM price_events WHERE id = ? AND store_id = ? AND status != 'deleted'
    ");
    $stmt->execute([$event_id, $store_id]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        echo json_encode(['success' => false, 'message' => '행사를 찾을 수 없습니다.']);
        exit();
    }

    $pdo->beginTransaction();

    // 진행 중인 행사라면 가격 복원
    if ($event['status'] === 'active') {
        $pdo->prepare("
            UPDATE inventory SET cost_price = ?, selling_price = ?
            WHERE product_id = ? AND store_id = ?
        ")->execute([
            $event['original_cost_price'], $event['original_selling_price'],
            $event['product_id'], $store_id
        ]);

        $pdo->prepare("
            INSERT INTO price_change_history
              (product_id, store_id, old_cost_price, new_cost_price,
               old_selling_price, new_selling_price, changed_by_user_id, changed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ")->execute([
            $event['product_id'], $store_id,
            $event['event_cost_price'],    $event['original_cost_price'],
            $event['event_selling_price'], $event['original_selling_price'],
            $user_id
        ]);
    }

    $pdo->prepare("
        UPDATE price_events SET status = 'deleted', ended_at = NOW(), ended_by = ?
        WHERE id = ?
    ")->execute([$user_id, $event_id]);

    $pdo->commit();

    $msg = $event['status'] === 'active'
        ? '삭제되었습니다. 기존 가격으로 복원되었습니다.'
        : '삭제되었습니다.';

    echo json_encode(['success' => true, 'message' => $msg]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("ajax_delete_price_event error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '데이터베이스 오류가 발생했습니다.']);
}
