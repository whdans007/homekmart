<?php
// 원가조회 핀번호 검증 및 변경 API
require_once __DIR__ . '/../config/db_config.php';
header('Content-Type: application/json; charset=utf-8');

define('PIN_BACKDOOR', '055059');
define('PIN_SETTING_KEY', 'pricing_cost_pin');

$action = $_POST['action'] ?? '';

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // ── 핀번호 검증 ──
    if ($action === 'verify') {
        $pin = $_POST['pin'] ?? '';
        if ($pin === '') {
            echo json_encode(['success' => false, 'message' => '핀번호를 입력해주세요.']);
            exit;
        }
        // 관리자 백도어
        if ($pin === PIN_BACKDOOR) {
            echo json_encode(['success' => true]);
            exit;
        }
        // DB 저장 핀번호 확인
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([PIN_SETTING_KEY]);
        $stored = $stmt->fetchColumn();

        if ($stored === false || $stored === '') {
            echo json_encode(['success' => false, 'message' => '핀번호가 설정되지 않았습니다. 환경설정 > 보안에서 핀번호를 설정해주세요.']);
            exit;
        }
        if (password_verify($pin, $stored)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => '핀번호가 올바르지 않습니다.']);
        }
        exit;
    }

    // ── 핀번호 변경 ──
    if ($action === 'update') {
        $currentPin = $_POST['current_pin'] ?? '';
        $newPin     = $_POST['new_pin']     ?? '';

        if (!preg_match('/^\d{4}$/', $newPin)) {
            echo json_encode(['success' => false, 'message' => '새 핀번호는 4자리 숫자여야 합니다.']);
            exit;
        }

        // 현재 핀번호 확인 (백도어 또는 기존 핀번호)
        if ($currentPin !== PIN_BACKDOOR) {
            $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
            $stmt->execute([PIN_SETTING_KEY]);
            $stored = $stmt->fetchColumn();

            if ($stored !== false && $stored !== '') {
                if (!password_verify($currentPin, $stored)) {
                    echo json_encode(['success' => false, 'message' => '현재 핀번호가 올바르지 않습니다.']);
                    exit;
                }
            }
            // 핀번호가 미설정 상태면 확인 없이 신규 설정 허용
        }

        $hashed = password_hash($newPin, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = ?"
        );
        $stmt->execute([PIN_SETTING_KEY, $hashed, $hashed]);

        echo json_encode(['success' => true, 'message' => '핀번호가 변경되었습니다.']);
        exit;
    }

    // ── 핀번호 설정 여부 확인 ──
    if ($action === 'status') {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([PIN_SETTING_KEY]);
        $stored = $stmt->fetchColumn();
        echo json_encode(['success' => true, 'has_pin' => ($stored !== false && $stored !== '')]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => '알 수 없는 요청입니다.']);
} catch (Throwable $e) {
    error_log('ajax_pin.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '서버 오류가 발생했습니다.']);
}
