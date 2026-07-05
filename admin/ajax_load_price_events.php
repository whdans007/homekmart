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

    $user_id = $_SESSION['user_id'] ?? null;
    $store_id = $_SESSION['store_id'] ?? null;
    if (!$store_id && $user_id) {
        $s = $pdo->prepare("SELECT store_id FROM users WHERE id = ?");
        $s->execute([$user_id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        $store_id = $row['store_id'] ?? null;
        if ($store_id) $_SESSION['store_id'] = $store_id;
    }

    if (!$store_id) {
        echo json_encode(['success' => false, 'message' => '점포 정보가 없습니다.']);
        exit();
    }

    // 만료된 행사 자동 종료 + 가격 복원
    $expired = $pdo->prepare("
        SELECT id, product_id, original_cost_price, original_selling_price,
               event_cost_price, event_selling_price
        FROM price_events
        WHERE store_id = ? AND status = 'active'
          AND end_date IS NOT NULL AND end_date < CURDATE()
    ");
    $expired->execute([$store_id]);
    $expired_rows = $expired->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($expired_rows)) {
        $pdo->beginTransaction();
        foreach ($expired_rows as $ev) {
            $pdo->prepare("
                UPDATE inventory SET cost_price = ?, selling_price = ?
                WHERE product_id = ? AND store_id = ?
            ")->execute([$ev['original_cost_price'], $ev['original_selling_price'],
                         $ev['product_id'], $store_id]);

            $pdo->prepare("
                INSERT INTO price_change_history
                  (product_id, store_id, old_cost_price, new_cost_price,
                   old_selling_price, new_selling_price, changed_by_user_id, changed_at)
                VALUES (?, ?, ?, ?, ?, ?, NULL, NOW())
            ")->execute([
                $ev['product_id'], $store_id,
                $ev['event_cost_price'], $ev['original_cost_price'],
                $ev['event_selling_price'], $ev['original_selling_price'],
            ]);

            $pdo->prepare("
                UPDATE price_events SET status = 'ended', ended_at = NOW(), ended_by = NULL
                WHERE id = ?
            ")->execute([$ev['id']]);
        }
        $pdo->commit();
    }

    // 행사 목록 조회 (active + ended, deleted 제외)
    $stmt = $pdo->prepare("
        SELECT
            pe.id,
            pe.product_id,
            p.sku,
            COALESCE(p.name_ko, p.name_en, '') AS product_name,
            p.name_ko,
            p.name_en,
            pe.original_cost_price,
            pe.original_selling_price,
            pe.event_cost_price,
            pe.event_selling_price,
            pe.event_name,
            pe.end_date,
            pe.status,
            pe.created_at,
            pe.ended_at,
            COALESCE(u.full_name, u.username, '') AS created_by_name
        FROM price_events pe
        JOIN products p ON pe.product_id = p.id
        LEFT JOIN users u ON pe.created_by = u.id
        WHERE pe.store_id = ? AND pe.status IN ('active', 'ended')
        ORDER BY pe.status ASC, pe.created_at DESC
        LIMIT 200
    ");
    $stmt->execute([$store_id]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'events' => $events,
                      'auto_ended' => count($expired_rows)]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("ajax_load_price_events error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '데이터베이스 오류가 발생했습니다.']);
}
