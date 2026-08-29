<?php
/**
 * 월 1회 등급 재산정 배치
 * Design Ref: shopping-mall.design.md §2.2 [월 1회 배치], §11.2
 *
 * - 소매: mall_orders(완료) 누적 합계로 등급(일반/할인/우수) 재산정 → mall_members.retail_tier 갱신
 * - 도매: mall_orders(완료, 도매채널) 누적 합계로 다음 달 적용 누적할인 등급 재산정 → mall_member_stats 갱신
 * - 누적 집계 기간: 가입 이후 전체 누적 (design v0.2 §3.1 결정사항)
 *
 * 실행 방법:
 *   CLI:  php mall/batch/recalc_tiers.php
 *   HTTP: POST /mall/batch/recalc_tiers.php  (X-Api-Key: MALL_BATCH_API_KEY 헤더 필요)
 */
require_once __DIR__ . '/../config/mall_config.php';
require_once __DIR__ . '/../../config/db_config.php';

$is_cli = (php_sapi_name() === 'cli');

if (!$is_cli) {
    header('Content-Type: application/json; charset=utf-8');
    $api_key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if ($api_key !== MALL_BATCH_API_KEY) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => ['code' => 'UNAUTHORIZED', 'message' => 'invalid api key']]);
        exit;
    }
}

function recalc_log($is_cli, $line) {
    if ($is_cli) {
        echo $line . PHP_EOL;
    }
    error_log('[mall recalc_tiers] ' . $line);
}

$conn = get_db_connection();
$retail_updated = 0;
$wholesale_updated = 0;

// --- 1. 소매 회원 등급 재산정 ---
$retail_rules = $conn->query(
    'SELECT tier, min_cumulative_amount FROM mall_retail_discount_rules ORDER BY min_cumulative_amount DESC'
)->fetch_all(MYSQLI_ASSOC);

$retail_members = $conn->query(
    "SELECT m.id, COALESCE(SUM(o.total_amount), 0) AS cumulative
     FROM mall_members m
     LEFT JOIN mall_orders o ON o.member_id = m.id AND o.status = 'completed' AND o.channel = 'retail'
     WHERE m.member_type = 'retail'
     GROUP BY m.id"
)->fetch_all(MYSQLI_ASSOC);

$update_tier_stmt = $conn->prepare('UPDATE mall_members SET retail_tier = ? WHERE id = ? AND retail_tier != ?');

foreach ($retail_members as $row) {
    $new_tier = 'general';
    foreach ($retail_rules as $rule) {
        if ((float)$row['cumulative'] >= (float)$rule['min_cumulative_amount']) {
            $new_tier = $rule['tier'];
            break;
        }
    }
    $update_tier_stmt->bind_param('sis', $new_tier, $row['id'], $new_tier);
    $update_tier_stmt->execute();
    if ($update_tier_stmt->affected_rows > 0) {
        $retail_updated++;
    }
}
$update_tier_stmt->close();
recalc_log($is_cli, "소매 회원 등급 재산정 완료: {$retail_updated}명 변경 (대상 " . count($retail_members) . "명)");

// --- 2. 도매 회원 누적등급 재산정 ---
$wholesale_tiers = $conn->query(
    'SELECT id, min_cumulative_amount FROM mall_wholesale_cumulative_tiers ORDER BY min_cumulative_amount DESC'
)->fetch_all(MYSQLI_ASSOC);

$wholesale_members = $conn->query(
    "SELECT m.id, COALESCE(SUM(o.total_amount), 0) AS cumulative
     FROM mall_members m
     LEFT JOIN mall_orders o ON o.member_id = m.id AND o.status = 'completed' AND o.channel = 'wholesale'
     WHERE m.member_type = 'wholesale' AND m.wholesale_status = 'approved'
     GROUP BY m.id"
)->fetch_all(MYSQLI_ASSOC);

$upsert_stats_stmt = $conn->prepare(
    'INSERT INTO mall_member_stats (member_id, cumulative_amount, current_wholesale_tier_id, last_recalculated_at)
     VALUES (?, ?, ?, NOW())
     ON DUPLICATE KEY UPDATE cumulative_amount = VALUES(cumulative_amount),
                             current_wholesale_tier_id = VALUES(current_wholesale_tier_id),
                             last_recalculated_at = VALUES(last_recalculated_at)'
);

foreach ($wholesale_members as $row) {
    $tier_id = null;
    foreach ($wholesale_tiers as $tier) {
        if ((float)$row['cumulative'] >= (float)$tier['min_cumulative_amount']) {
            $tier_id = $tier['id'];
            break;
        }
    }
    $cumulative = (float)$row['cumulative'];
    $upsert_stats_stmt->bind_param('idi', $row['id'], $cumulative, $tier_id);
    $upsert_stats_stmt->execute();
    $wholesale_updated++;
}
$upsert_stats_stmt->close();
recalc_log($is_cli, "도매 회원 누적등급 재산정 완료: {$wholesale_updated}명 처리");

$conn->close();

if (!$is_cli) {
    echo json_encode([
        'success' => true,
        'data' => ['retail_updated' => $retail_updated, 'wholesale_processed' => $wholesale_updated],
    ]);
}
