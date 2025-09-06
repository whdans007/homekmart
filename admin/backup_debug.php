<?php
// 단계별 디버깅
header('Content-Type: application/json; charset=utf-8');

try {
    // 1단계: 기본 응답 테스트
    $step = $_GET['step'] ?? '1';
    
    if ($step == '1') {
        echo json_encode(['success' => true, 'message' => '1단계: 기본 응답 성공']);
        exit;
    }
    
    // 2단계: 세션 시작 테스트
    if ($step == '2') {
        session_start();
        echo json_encode(['success' => true, 'message' => '2단계: 세션 시작 성공']);
        exit;
    }
    
    // 3단계: config 파일 include 테스트
    if ($step == '3') {
        session_start();
        require_once '../config/db_config.php';
        echo json_encode(['success' => true, 'message' => '3단계: config include 성공']);
        exit;
    }
    
    // 4단계: helper 파일들 include 테스트
    if ($step == '4') {
        session_start();
        require_once '../config/db_config.php';
        require_once '../lib/session_helper.php';
        require_once '../lib/permission_helper.php';
        echo json_encode(['success' => true, 'message' => '4단계: 모든 include 성공']);
        exit;
    }
    
    // 5단계: 권한 체크 테스트
    if ($step == '5') {
        session_start();
        require_once '../config/db_config.php';
        require_once '../lib/session_helper.php';
        require_once '../lib/permission_helper.php';
        
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => '5단계: 로그인 필요']);
            exit;
        }
        
        echo json_encode(['success' => true, 'message' => '5단계: 로그인 확인 성공', 'user_id' => $_SESSION['user_id']]);
        exit;
    }
    
    echo json_encode(['success' => false, 'message' => '알 수 없는 단계']);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
} catch (Error $e) {
    echo json_encode(['success' => false, 'message' => 'Fatal Error: ' . $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
}
?>