<?php
/**
 * mall-delivery-dispatch 기능 DB 마이그레이션
 * Design Ref: docs/02-design/features/mall-delivery-dispatch.design.md §3
 * 접속: http://서버주소/sunset/sql/run_add_mall_delivery_dispatch_migration.php
 *
 * - mall_drivers: 배송기사 계정(mall_members/admin users와 완전 분리)
 * - mall_order_driver_assignments: 주문-기사 배정 이력(재배정마다 새 행)
 * - mall_driver_locations: 기사 위치 이력(경로 재생용)
 * - mall_orders: status enum에 assigned/delivering/arrived/delivery_failed 추가 + current_driver_id 컬럼
 * SHOW TABLES/COLUMNS로 이미 적용됐는지 확인해 재실행해도 안전하다.
 *
 * 주의: 실행 후 이 파일을 삭제하세요.
 */
require_once __DIR__ . '/../config/db_config.php';

mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die('DB 연결 실패: ' . $conn->connect_error);
}
$conn->set_charset(DB_CHARSET);

$steps = [];

// 1. mall_drivers
$check = $conn->query("SHOW TABLES LIKE 'mall_drivers'");
if ($check && $check->num_rows > 0) {
    $steps[] = ['SKIP', 'mall_drivers 테이블이 이미 존재합니다.'];
} else {
    $sql = "CREATE TABLE `mall_drivers` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `name` varchar(100) NOT NULL,
        `phone` varchar(50) NOT NULL,
        `password_hash` varchar(255) NOT NULL,
        `driver_type` enum('inhouse','external') NOT NULL DEFAULT 'inhouse',
        `vehicle_info` varchar(255) DEFAULT NULL,
        `is_active` tinyint(1) NOT NULL DEFAULT 1,
        `last_lat` decimal(10,7) DEFAULT NULL,
        `last_lng` decimal(10,7) DEFAULT NULL,
        `last_seen_at` datetime DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `phone` (`phone`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='배송기사 계정'";
    if ($conn->query($sql)) {
        $steps[] = ['OK', 'mall_drivers 테이블 생성 완료'];
    } else {
        $steps[] = ['ERROR', 'mall_drivers 생성 실패: ' . $conn->error];
    }
}

// 2. mall_order_driver_assignments
$check = $conn->query("SHOW TABLES LIKE 'mall_order_driver_assignments'");
if ($check && $check->num_rows > 0) {
    $steps[] = ['SKIP', 'mall_order_driver_assignments 테이블이 이미 존재합니다.'];
} else {
    $sql = "CREATE TABLE `mall_order_driver_assignments` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `order_id` int(11) NOT NULL,
        `driver_id` int(11) NOT NULL,
        `status` enum('assigned','delivering','arrived','completed','failed','reassigned') NOT NULL DEFAULT 'assigned',
        `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `delivering_at` datetime DEFAULT NULL,
        `arrived_at` datetime DEFAULT NULL,
        `completed_at` datetime DEFAULT NULL,
        `failed_reason` varchar(255) DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `order_id` (`order_id`),
        KEY `driver_id` (`driver_id`),
        CONSTRAINT `mall_oda_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `mall_orders` (`id`) ON DELETE CASCADE,
        CONSTRAINT `mall_oda_ibfk_2` FOREIGN KEY (`driver_id`) REFERENCES `mall_drivers` (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='주문-기사 배정 이력'";
    if ($conn->query($sql)) {
        $steps[] = ['OK', 'mall_order_driver_assignments 테이블 생성 완료'];
    } else {
        $steps[] = ['ERROR', 'mall_order_driver_assignments 생성 실패: ' . $conn->error];
    }
}

// 3. mall_driver_locations
$check = $conn->query("SHOW TABLES LIKE 'mall_driver_locations'");
if ($check && $check->num_rows > 0) {
    $steps[] = ['SKIP', 'mall_driver_locations 테이블이 이미 존재합니다.'];
} else {
    $sql = "CREATE TABLE `mall_driver_locations` (
        `id` bigint(20) NOT NULL AUTO_INCREMENT,
        `driver_id` int(11) NOT NULL,
        `order_id` int(11) NOT NULL,
        `lat` decimal(10,7) NOT NULL,
        `lng` decimal(10,7) NOT NULL,
        `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `driver_order_time` (`driver_id`,`order_id`,`recorded_at`),
        CONSTRAINT `mall_dl_ibfk_1` FOREIGN KEY (`driver_id`) REFERENCES `mall_drivers` (`id`) ON DELETE CASCADE,
        CONSTRAINT `mall_dl_ibfk_2` FOREIGN KEY (`order_id`) REFERENCES `mall_orders` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='기사 위치 이력'";
    if ($conn->query($sql)) {
        $steps[] = ['OK', 'mall_driver_locations 테이블 생성 완료'];
    } else {
        $steps[] = ['ERROR', 'mall_driver_locations 생성 실패: ' . $conn->error];
    }
}

// 4. mall_orders.status enum 확장
$col = $conn->query("SHOW COLUMNS FROM mall_orders LIKE 'status'");
$col_row = $col ? $col->fetch_assoc() : null;
if ($col_row && strpos($col_row['Type'], 'delivery_failed') !== false) {
    $steps[] = ['SKIP', 'mall_orders.status enum이 이미 확장되어 있습니다.'];
} else {
    $sql = "ALTER TABLE `mall_orders`
        MODIFY COLUMN `status` enum('pending','confirmed','preparing','ready','assigned','delivering','arrived','completed','cancelled','delivery_failed') NOT NULL DEFAULT 'pending'";
    if ($conn->query($sql)) {
        $steps[] = ['OK', 'mall_orders.status enum 확장 완료'];
    } else {
        $steps[] = ['ERROR', 'status enum 확장 실패: ' . $conn->error];
    }
}

// 5. mall_orders.current_driver_id 컬럼
$col_check = $conn->query("SHOW COLUMNS FROM mall_orders LIKE 'current_driver_id'");
if ($col_check && $col_check->num_rows > 0) {
    $steps[] = ['SKIP', 'current_driver_id 컬럼이 이미 존재합니다.'];
} else {
    $after = $conn->query("SHOW COLUMNS FROM mall_orders LIKE 'estimated_ready_at'");
    $after_clause = ($after && $after->num_rows > 0) ? 'AFTER `estimated_ready_at`' : '';
    $sql = "ALTER TABLE `mall_orders` ADD COLUMN `current_driver_id` int(11) DEFAULT NULL {$after_clause}";
    if ($conn->query($sql)) {
        $steps[] = ['OK', 'current_driver_id 컬럼 추가 완료'];
    } else {
        $steps[] = ['ERROR', 'current_driver_id 컬럼 추가 실패: ' . $conn->error];
    }
}

// 6. current_driver_id FK (mall_drivers 생성 이후에만 가능하므로 마지막에 시도)
$fk_check = $conn->query("
    SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mall_orders' AND CONSTRAINT_NAME = 'mall_orders_driver_fk'
");
if ($fk_check && $fk_check->num_rows > 0) {
    $steps[] = ['SKIP', 'mall_orders_driver_fk 외래키가 이미 존재합니다.'];
} else {
    $sql = "ALTER TABLE `mall_orders` ADD CONSTRAINT `mall_orders_driver_fk` FOREIGN KEY (`current_driver_id`) REFERENCES `mall_drivers` (`id`)";
    if ($conn->query($sql)) {
        $steps[] = ['OK', 'mall_orders_driver_fk 외래키 추가 완료'];
    } else {
        $steps[] = ['ERROR', '외래키 추가 실패: ' . $conn->error];
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>배송 배차 기능 DB 마이그레이션</title>
    <style>
        body{font-family:-apple-system,sans-serif;background:#f9fafb;margin:0;padding:2rem}
        .card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.5rem;max-width:720px;margin:0 auto 1rem}
        h1{font-size:1.2rem;font-weight:700;margin:0 0 .25rem;color:#111}
        .sub{color:#6b7280;font-size:.875rem}
        table{width:100%;border-collapse:collapse;font-size:.875rem}
        td,th{padding:.5rem .75rem;border-bottom:1px solid #f3f4f6;text-align:left}
        .ok{color:#059669}.skip{color:#9ca3af}.error{color:#dc2626}
        .success-box{background:#ecfdf5;border:1px solid #6ee7b7;border-radius:6px;padding:1rem;font-size:.875rem;color:#065f46;margin-bottom:1rem}
        .err-box{background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:1rem;font-size:.875rem;color:#991b1b;margin-bottom:1rem}
    </style>
</head>
<body>
<div class="card">
    <h1>🚀 배송 배차 기능 DB 마이그레이션</h1>
    <p class="sub">mall_drivers / mall_order_driver_assignments / mall_driver_locations 생성 + mall_orders 확장 · DB: <strong><?php echo DB_NAME; ?></strong></p>
</div>

<div class="card">
    <?php $has_error = false; foreach ($steps as [$s]) { if ($s === 'ERROR') $has_error = true; } ?>
    <?php if ($has_error): ?>
    <div class="err-box">⚠️ 일부 단계 실패. 아래 오류를 확인하세요.</div>
    <?php else: ?>
    <div class="success-box">✅ 완료!</div>
    <p class="sub">⚠️ 보안을 위해 이 파일을 삭제하세요.</p>
    <?php endif; ?>
    <table>
        <thead><tr><th>단계</th><th>결과</th></tr></thead>
        <tbody>
        <?php foreach ($steps as [$status, $msg]): ?>
        <tr><td colspan="2" class="<?php echo strtolower($status); ?>">[<?php echo $status; ?>] <?php echo htmlspecialchars($msg); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
</body>
</html>
