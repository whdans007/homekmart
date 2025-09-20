<?php
header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../lib/session_helper.php';

    // 세션 시작
    ensure_logged_in();

    // 디버깅 세션 데이터 제거
    unset($_SESSION['debug_post_data']);
    unset($_SESSION['debug_saved_data']);

    echo json_encode(['success' => true, 'message' => '디버깅 정보가 지워졌습니다.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '오류가 발생했습니다: ' . $e->getMessage()]);
}
?>