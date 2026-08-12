<?php
// 점포별 마스터파일 업로드 컬럼 매핑 + 프리셋 정의 저장/조회 API
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/settings_helper.php';
header('Content-Type: application/json; charset=utf-8');

function colmap_setting_key($storeId) { return 'pricing_colmap_' . $storeId; }
define('COLMAP_PRESETS_SETTING_KEY', 'pricing_colmap_presets');

$action  = $_POST['action'] ?? $_GET['action'] ?? '';
$storeId = (int)($_POST['store_id'] ?? $_GET['store_id'] ?? 0);

// store_id는 점포별 매핑 저장/조회에만 필요. 프리셋 정의(save_preset/load_presets)는 전역 값이라 불필요.
if ($storeId <= 0 && in_array($action, ['save', 'load'], true)) {
    echo json_encode(['success' => false, 'message' => '점포가 선택되지 않았습니다.']);
    exit;
}

/** 열 문자 4개 + 헤더행을 POST에서 읽어 검증한다. 실패 시 null 반환. */
function colmap_read_posted_cols(?string &$error): ?array {
    $cols = [
        'col_sku'   => strtoupper(trim($_POST['col_sku']   ?? 'A')) ?: 'A',
        'col_name'  => strtoupper(trim($_POST['col_name']  ?? 'B')) ?: 'B',
        'col_cost'  => strtoupper(trim($_POST['col_cost']  ?? 'C')) ?: 'C',
        'col_price' => strtoupper(trim($_POST['col_price'] ?? 'D')) ?: 'D',
    ];
    foreach ($cols as $v) {
        if (!preg_match('/^[A-Z]{1,3}$/', $v)) {
            $error = '열 문자는 A~Z 조합만 입력할 수 있습니다.';
            return null;
        }
    }
    $cols['header_row'] = max(0, (int)($_POST['header_row'] ?? 1));
    return $cols;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // ── 진단: 저장이 왜 안 되는지 확인용 (브라우저에서 직접 열어볼 수 있음) ──
    if ($action === 'diag') {
        $key = $storeId > 0 ? colmap_setting_key($storeId) : COLMAP_PRESETS_SETTING_KEY;
        echo json_encode([
            'success'  => true,
            'db_name'  => DB_NAME,
            'checked_key' => $key,
            'store'    => settings_diagnose($pdo, colmap_setting_key($storeId)),
            'presets'  => settings_diagnose($pdo, COLMAP_PRESETS_SETTING_KEY),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // ── 프리셋 정의 자체를 수정/저장 (모든 점포 공통) ──
    if ($action === 'save_preset') {
        $presetName = strtolower(trim($_POST['preset_name'] ?? ''));
        if (!preg_match('/^[a-z0-9_]{1,30}$/', $presetName)) {
            echo json_encode(['success' => false, 'message' => '프리셋 이름이 올바르지 않습니다.']);
            exit;
        }

        $err = null;
        $cols = colmap_read_posted_cols($err);
        if ($cols === null) {
            echo json_encode(['success' => false, 'message' => $err]);
            exit;
        }

        $label = trim($_POST['label'] ?? $presetName);
        if ($label === '') $label = $presetName;
        $cols['label'] = mb_substr($label, 0, 100);

        $stored  = settings_get($pdo, COLMAP_PRESETS_SETTING_KEY);
        $presets = $stored ? (json_decode($stored, true) ?: []) : [];
        $presets[$presetName] = $cols;

        settings_set($pdo, COLMAP_PRESETS_SETTING_KEY, json_encode($presets, JSON_UNESCAPED_UNICODE));

        // 저장 직후 다시 읽어 실제 반영 여부를 확인해서 돌려준다 (조용한 실패 방지)
        $verify = json_decode((string)settings_get($pdo, COLMAP_PRESETS_SETTING_KEY), true);
        $ok = isset($verify[$presetName]) && $verify[$presetName]['col_sku'] === $cols['col_sku'];

        echo json_encode([
            'success' => $ok,
            'message' => $ok ? '프리셋이 저장되었습니다.' : '저장 후 검증에 실패했습니다. DB 상태를 확인해주세요.',
            'presets' => $verify,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── 프리셋 정의 조회 ──
    if ($action === 'load_presets') {
        $stored = settings_get($pdo, COLMAP_PRESETS_SETTING_KEY);
        echo json_encode([
            'success' => true,
            'presets' => $stored ? (json_decode($stored, true) ?: null) : null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── 점포별 컬럼 매핑 저장 ──
    if ($action === 'save') {
        $err = null;
        $cols = colmap_read_posted_cols($err);
        if ($cols === null) {
            echo json_encode(['success' => false, 'message' => $err]);
            exit;
        }

        settings_set($pdo, colmap_setting_key($storeId), json_encode($cols, JSON_UNESCAPED_UNICODE));

        // 저장 직후 재조회로 실제 반영 확인
        $verify = json_decode((string)settings_get($pdo, colmap_setting_key($storeId)), true);
        $ok = is_array($verify) && ($verify['col_sku'] ?? null) === $cols['col_sku'];

        echo json_encode([
            'success' => $ok,
            'message' => $ok ? '저장되었습니다.' : '저장 후 검증에 실패했습니다. DB 상태를 확인해주세요.',
            'colmap'  => $verify,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── 점포별 컬럼 매핑 조회 ──
    if ($action === 'load') {
        $stored = settings_get($pdo, colmap_setting_key($storeId));
        echo json_encode([
            'success' => true,
            'colmap'  => $stored ? (json_decode($stored, true) ?: null) : null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['success' => false, 'message' => '알 수 없는 요청입니다.']);
} catch (Throwable $e) {
    error_log('ajax_colmap.php error: ' . $e->getMessage());
    // 원인 파악이 가능하도록 실제 DB 오류 메시지를 함께 반환 (관리자 전용 화면)
    echo json_encode(['success' => false, 'message' => 'DB 오류: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
