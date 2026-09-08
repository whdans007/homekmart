<?php
/**
 * 몰 전용 신선상품 코드 자동생성
 * mall_fresh_products.code에 이미 저장된 값들 중 가장 큰 일련번호를 직접 찾아 다음 번호를 생성한다.
 * 코드 형식: [7자리 프리픽스][5자리 일련번호][1자리 체크디지트] = EAN-13
 */
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../config/db_config.php';

header('Content-Type: application/json; charset=utf-8');

const FRESH_CODE_PREFIX = '2099001';

function fresh_code_check_digit(string $base12): int
{
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $n = ord($base12[$i]) - 48;
        $sum += ($i % 2 === 0) ? $n : $n * 3;
    }
    return (10 - ($sum % 10)) % 10;
}

try {
    ensure_logged_in();
    if (!has_permission('product_management') && !in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => '권한이 없습니다.']);
        exit;
    }

    $conn = get_db_connection();
    $prefix = FRESH_CODE_PREFIX;

    // 기존 코드 중 이 프리픽스로 시작하는 13자리 코드에서 최대 일련번호(7~11번째 자리, 5자리)를 찾는다
    $stmt = $conn->prepare("SELECT code FROM mall_fresh_products WHERE code LIKE CONCAT(?, '%') AND CHAR_LENGTH(code) = 13");
    $stmt->bind_param('s', $prefix);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $maxSerial = 0;
    foreach ($rows as $row) {
        $code = $row['code'];
        if (!preg_match('/^\d{13}$/', $code)) {
            continue; // 체크디지트 규격에 안 맞는 기존 값은 시퀀스 계산에서 제외
        }
        $serial = (int)substr($code, 7, 5);
        if ($serial > $maxSerial) {
            $maxSerial = $serial;
        }
    }

    $nextSerial = $maxSerial + 1;
    if ($nextSerial > 99999) {
        throw new Exception('사용 가능한 일련번호를 초과했습니다.');
    }

    // 혹시 수동 입력 등으로 중간에 이미 존재하는 코드라면 다음 번호로 계속 진행
    $checkExists = $conn->prepare('SELECT 1 FROM mall_fresh_products WHERE code = ? LIMIT 1');
    $candidate = null;
    $attempts = 0;
    while (true) {
        $attempts++;
        if ($attempts > 1000 || $nextSerial > 99999) {
            throw new Exception('사용 가능한 코드를 찾지 못했습니다.');
        }
        $base12 = $prefix . str_pad((string)$nextSerial, 5, '0', STR_PAD_LEFT);
        $checkDigit = fresh_code_check_digit($base12);
        $candidateCode = $base12 . $checkDigit;

        $checkExists->bind_param('s', $candidateCode);
        $checkExists->execute();
        $exists = (bool)$checkExists->get_result()->fetch_assoc();
        if (!$exists) {
            $candidate = $candidateCode;
            break;
        }
        $nextSerial++;
    }
    $checkExists->close();
    $conn->close();

    echo json_encode(['ok' => true, 'code' => $candidate, 'serial' => $nextSerial], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('ajax_generate_fresh_code.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
