<?php
/**
 * POST mall/admin/ajax/save_discount_rules.php
 * Design Ref: shopping-mall.design.md §4.1 save_discount_rules.php
 * 저장 즉시 mall/lib/pricing.php 계산에 반영됨(별도 캐시 없음).
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../lib/session_helper.php';
require_once __DIR__ . '/../../../lib/permission_helper.php';
require_once __DIR__ . '/../../../config/db_config.php';
require_once __DIR__ . '/../../lib/csrf.php';

function json_error($code, $message, $http = 400) {
    http_response_code($http);
    echo json_encode(['success' => false, 'error' => ['code' => $code, 'message' => $message]]);
    exit;
}

if (!is_logged_in()) {
    json_error('UNAUTHORIZED', '로그인이 필요합니다', 401);
}
if (!has_permission('mall_management')) {
    json_error('UNAUTHORIZED', '권한이 없습니다', 403);
}
if (!mall_csrf_verify($_POST['csrf_token'] ?? '')) {
    json_error('CSRF_INVALID', '요청이 만료되었습니다. 새로고침 후 다시 시도해주세요', 403);
}

$action = $_POST['action'] ?? '';
$valid_tiers = ['general', 'good', 'vip', 'platinum'];

try {
    $conn = get_db_connection();

    switch ($action) {
        case 'retail_save':
            $conn->begin_transaction();
            $upsert = $conn->prepare(
                'INSERT INTO mall_retail_discount_rules (tier, discount_rate, min_cumulative_amount)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE discount_rate = VALUES(discount_rate), min_cumulative_amount = VALUES(min_cumulative_amount)'
            );
            foreach ($valid_tiers as $tier) {
                $rate = (float)($_POST['rate_' . $tier] ?? 0);
                $min = (float)($_POST['min_' . $tier] ?? 0);
                if ($rate < 0 || $rate > 100 || $min < 0) {
                    $conn->rollback();
                    $upsert->close();
                    $conn->close();
                    json_error('VALIDATION_ERROR', '입력값을 확인해주세요(0~100% 범위)');
                }
                $upsert->bind_param('sdd', $tier, $rate, $min);
                $upsert->execute();
            }
            $upsert->close();
            $conn->commit();
            echo json_encode(['success' => true]);
            break;

        case 'wholesale_reference_save':
            $rate = (float)($_POST['rate'] ?? -1);
            if ($rate < 0 || $rate > 100) {
                json_error('VALIDATION_ERROR', '입력값을 확인해주세요(0~100% 범위)');
            }
            $stmt = $conn->prepare(
                "INSERT INTO system_settings (setting_key, setting_value, setting_type, description)
                 VALUES ('mall_wholesale_reference_markup_rate', ?, 'number', '상품 큐레이션 화면 기준도매가 = 원가 x (1 + 마진율)')
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            );
            $rate_str = (string)$rate;
            $stmt->bind_param('s', $rate_str);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => true]);
            break;

        case 'point_accrual_save':
            $rate = (float)($_POST['rate'] ?? -1);
            if ($rate < 0 || $rate > 100) {
                json_error('VALIDATION_ERROR', '입력값을 확인해주세요(0~100% 범위)');
            }
            $stmt = $conn->prepare(
                "INSERT INTO system_settings (setting_key, setting_value, setting_type, description)
                 VALUES ('mall_point_accrual_rate', ?, 'number', '구매 금액 대비 포인트 적립율(회사 규정 기본 2%)')
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            );
            $rate_str = (string)$rate;
            $stmt->bind_param('s', $rate_str);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => true]);
            break;

        case 'shipping_save':
            $base_shipping_fee = (float)($_POST['base_shipping_fee'] ?? -1);
            $free_shipping_threshold = (float)($_POST['free_shipping_threshold'] ?? -1);
            if ($base_shipping_fee < 0 || $free_shipping_threshold < 0) {
                json_error('VALIDATION_ERROR', '입력값을 확인해주세요(0 이상)');
            }
            $stmt = $conn->prepare(
                "INSERT INTO system_settings (setting_key, setting_value, setting_type, description)
                 VALUES ('mall_base_shipping_fee', ?, 'number', '체크아웃 일반배송 기본 배송비')
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            );
            $fee_str = (string)$base_shipping_fee;
            $stmt->bind_param('s', $fee_str);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare(
                "INSERT INTO system_settings (setting_key, setting_value, setting_type, description)
                 VALUES ('mall_free_shipping_threshold', ?, 'number', '체크아웃/장바구니 무료배송 기준금액')
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            );
            $threshold_str = (string)$free_shipping_threshold;
            $stmt->bind_param('s', $threshold_str);
            $stmt->execute();
            $stmt->close();

            echo json_encode(['success' => true]);
            break;

        case 'prep_minutes_save':
            $minutes = (int)($_POST['minutes'] ?? -1);
            if ($minutes < 0) {
                json_error('VALIDATION_ERROR', '입력값을 확인해주세요(0 이상)');
            }
            $stmt = $conn->prepare(
                "INSERT INTO system_settings (setting_key, setting_value, setting_type, description)
                 VALUES ('mall_default_prep_minutes', ?, 'number', '주문 관리 접수확인 시 기본으로 채워지는 상품 준비 소요시간(분)')
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            );
            $minutes_str = (string)$minutes;
            $stmt->bind_param('s', $minutes_str);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => true]);
            break;

        case 'instant_add':
            $min_order_amount = (float)($_POST['min_order_amount'] ?? -1);
            $discount_rate = (float)($_POST['discount_rate'] ?? -1);
            $sort_order = (int)($_POST['sort_order'] ?? 0);
            if ($min_order_amount < 0 || $discount_rate < 0 || $discount_rate > 100) {
                json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
            }
            $stmt = $conn->prepare(
                'INSERT INTO mall_wholesale_instant_discount_tiers (min_order_amount, discount_rate, sort_order, is_active)
                 VALUES (?, ?, ?, 1)'
            );
            $stmt->bind_param('ddi', $min_order_amount, $discount_rate, $sort_order);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => true]);
            break;

        case 'instant_delete':
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $conn->prepare('DELETE FROM mall_wholesale_instant_discount_tiers WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => true]);
            break;

        case 'cumulative_add':
            $tier_name = trim($_POST['tier_name'] ?? '');
            $min_cumulative_amount = (float)($_POST['min_cumulative_amount'] ?? -1);
            $additional_discount_rate = (float)($_POST['additional_discount_rate'] ?? -1);
            $sort_order = (int)($_POST['sort_order'] ?? 0);
            if ($tier_name === '' || $min_cumulative_amount < 0 || $additional_discount_rate < 0 || $additional_discount_rate > 100) {
                json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
            }
            $stmt = $conn->prepare(
                'INSERT INTO mall_wholesale_cumulative_tiers (tier_name, min_cumulative_amount, additional_discount_rate, sort_order)
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->bind_param('sddi', $tier_name, $min_cumulative_amount, $additional_discount_rate, $sort_order);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => true]);
            break;

        case 'cumulative_delete':
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $conn->prepare('DELETE FROM mall_wholesale_cumulative_tiers WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => true]);
            break;

        default:
            json_error('VALIDATION_ERROR', '알 수 없는 작업입니다');
    }

    $conn->close();
} catch (Exception $e) {
    error_log('save_discount_rules.php error: ' . $e->getMessage());
    json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
