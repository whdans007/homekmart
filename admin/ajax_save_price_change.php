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

// 매입관리 권한 확인
if (!has_permission('purchase_management')) {
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit();
}

// POST 데이터 받기
$input = json_decode(file_get_contents('php://input'), true);

$product_id        = $input['product_id']        ?? null;
$new_selling_price = $input['new_selling_price']  ?? null;
$new_cost_price    = $input['new_cost_price']      ?? null;
$event_name        = trim($input['event_name']     ?? '') ?: null;
$end_date          = $input['end_date']            ?? null;
if ($end_date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
    $end_date = null;
}
$skip_event        = !empty($input['skip_event']);

if (!$product_id || !$new_selling_price) {
    echo json_encode(['success' => false, 'message' => '필수 정보가 누락되었습니다.']);
    exit();
}

// 숫자 검증
if (!is_numeric($new_selling_price) || $new_selling_price < 0) {
    echo json_encode(['success' => false, 'message' => '올바른 판매가를 입력해주세요.']);
    exit();
}

if ($new_cost_price !== null && (!is_numeric($new_cost_price) || $new_cost_price < 0)) {
    echo json_encode(['success' => false, 'message' => '올바른 원가를 입력해주세요.']);
    exit();
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // price_events 테이블 자동 생성
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

    // 사용자의 점포 ID 및 사용자 ID 확인
    $user_id = $_SESSION['user_id'] ?? null;
    $store_id = $_SESSION['store_id'] ?? null;

    // 세션에 store_id가 없으면 데이터베이스에서 조회
    if (!$store_id && $user_id) {
        $user_stmt = $pdo->prepare("SELECT store_id FROM users WHERE id = ?");
        $user_stmt->execute([$user_id]);
        $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
        $store_id = $user['store_id'] ?? null;

        // 세션에 store_id 저장
        if ($store_id) {
            $_SESSION['store_id'] = $store_id;
        }
    }

    if (!$store_id || !$user_id) {
        echo json_encode(['success' => false, 'message' => '세션 정보가 없습니다. 다시 로그인해주세요.']);
        exit();
    }

    // 트랜잭션 시작
    $pdo->beginTransaction();

    // 현재 가격 정보 조회
    $stmt = $pdo->prepare("
        SELECT
            cost_price,
            selling_price
        FROM inventory
        WHERE product_id = ? AND store_id = ?
        LIMIT 1
    ");
    $stmt->execute([$product_id, $store_id]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);

    // inventory 레코드가 없는 경우 새로 생성
    if (!$current) {
        // 원가가 제공되지 않은 경우 0으로 설정
        if ($new_cost_price === null) {
            $new_cost_price = 0;
        }

        // 새 inventory 레코드 생성
        $insert_sql = "
            INSERT INTO inventory (product_id, store_id, cost_price, selling_price, quantity)
            VALUES (?, ?, ?, ?, 0)
        ";
        $stmt = $pdo->prepare($insert_sql);
        $stmt->execute([$product_id, $store_id, $new_cost_price, $new_selling_price]);

        $old_cost_price = 0;
        $old_selling_price = 0;

        // price_change_history 테이블에 기록 추가
        $history_sql = "
            INSERT INTO price_change_history
            (product_id, store_id, old_cost_price, new_cost_price, old_selling_price, new_selling_price, changed_by_user_id, changed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ";
        $stmt = $pdo->prepare($history_sql);
        $stmt->execute([
            $product_id,
            $store_id,
            $old_cost_price,
            $new_cost_price,
            $old_selling_price,
            $new_selling_price,
            $user_id
        ]);

        // price_events 기록 (신규 등록)
        if (!$skip_event) {
            $pdo->prepare("
                INSERT INTO price_events
                  (product_id, store_id, original_cost_price, original_selling_price,
                   event_cost_price, event_selling_price, event_name, end_date, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $product_id, $store_id,
                $old_cost_price, $old_selling_price,
                $new_cost_price, $new_selling_price,
                $event_name, $end_date, $user_id
            ]);
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => '가격이 성공적으로 등록되었습니다.',
            'changes' => [
                'old_cost_price' => number_format($old_cost_price, 2),
                'new_cost_price' => number_format($new_cost_price, 2),
                'old_selling_price' => number_format($old_selling_price),
                'new_selling_price' => number_format($new_selling_price)
            ]
        ]);
    } else {
        // 기존 inventory 레코드 업데이트
        $old_cost_price = $current['cost_price'];
        $old_selling_price = $current['selling_price'];

        // 원가가 제공되지 않은 경우 기존 원가 유지
        if ($new_cost_price === null) {
            $new_cost_price = $old_cost_price;
        }

        // inventory 테이블 업데이트
        $update_fields = [];
        $update_params = [];

        if ($new_cost_price != $old_cost_price) {
            $update_fields[] = "cost_price = ?";
            $update_params[] = $new_cost_price;
        }

        if ($new_selling_price != $old_selling_price) {
            $update_fields[] = "selling_price = ?";
            $update_params[] = $new_selling_price;
        }

        // 변경사항이 있는 경우에만 업데이트
        if (!empty($update_fields)) {
            $update_sql = "UPDATE inventory SET " . implode(', ', $update_fields) . " WHERE product_id = ? AND store_id = ?";
            $update_params[] = $product_id;
            $update_params[] = $store_id;

            $stmt = $pdo->prepare($update_sql);
            $stmt->execute($update_params);

            // price_change_history 테이블에 기록 추가
            $history_sql = "
                INSERT INTO price_change_history
                (product_id, store_id, old_cost_price, new_cost_price, old_selling_price, new_selling_price, changed_by_user_id, changed_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ";
            $stmt = $pdo->prepare($history_sql);
            $stmt->execute([
                $product_id,
                $store_id,
                $old_cost_price,
                $new_cost_price,
                $old_selling_price,
                $new_selling_price,
                $user_id
            ]);

            // price_events 기록 — 기존 active 이벤트가 있으면 업데이트, 없으면 신규 삽입
            if (!$skip_event) {
                $evt_check = $pdo->prepare("
                    SELECT id FROM price_events
                    WHERE product_id = ? AND store_id = ? AND status = 'active'
                    LIMIT 1
                ");
                $evt_check->execute([$product_id, $store_id]);
                $existing_event = $evt_check->fetch(PDO::FETCH_ASSOC);

                if ($existing_event) {
                    $pdo->prepare("
                        UPDATE price_events
                        SET event_cost_price = ?, event_selling_price = ?,
                            event_name = COALESCE(?, event_name),
                            end_date   = COALESCE(?, end_date)
                        WHERE id = ?
                    ")->execute([
                        $new_cost_price, $new_selling_price,
                        $event_name, $end_date,
                        $existing_event['id']
                    ]);
                } else {
                    $pdo->prepare("
                        INSERT INTO price_events
                          (product_id, store_id, original_cost_price, original_selling_price,
                           event_cost_price, event_selling_price, event_name, end_date, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([
                        $product_id, $store_id,
                        $old_cost_price, $old_selling_price,
                        $new_cost_price, $new_selling_price,
                        $event_name, $end_date, $user_id
                    ]);
                }
            }

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => '가격이 성공적으로 변경되었습니다.',
                'changes' => [
                    'old_cost_price' => number_format($old_cost_price, 2),
                    'new_cost_price' => number_format($new_cost_price, 2),
                    'old_selling_price' => number_format($old_selling_price),
                    'new_selling_price' => number_format($new_selling_price)
                ]
            ]);
        } else {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => '변경된 가격이 없습니다.']);
        }
    }

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Database error in ajax_save_price_change.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '데이터베이스 오류가 발생했습니다.']);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error in ajax_save_price_change.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '오류가 발생했습니다.']);
}
