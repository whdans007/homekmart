<?php
// 진행 상황 조회 엔드포인트
header('Content-Type: application/json; charset=utf-8');

session_start();

// 로그인 확인
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit;
}

// 진행 상황 ID 가져오기
$progress_id = $_GET['id'] ?? '';
if (empty($progress_id)) {
    echo json_encode([
        'success' => true,
        'progress' => [
            'status' => 'idle',
            'message' => '대기 중',
            'current' => 0,
            'total' => 0,
            'percentage' => 0
        ]
    ]);
    exit;
}

// 진행 상황 파일 경로
$progress_file = sys_get_temp_dir() . '/backup_progress_' . $progress_id . '.json';

if (file_exists($progress_file)) {
    $progress_data = file_get_contents($progress_file);
    $progress = json_decode($progress_data, true);
    
    echo json_encode([
        'success' => true,
        'progress' => $progress
    ]);
} else {
    echo json_encode([
        'success' => true,
        'progress' => [
            'status' => 'idle',
            'message' => '대기 중',
            'current' => 0,
            'total' => 0,
            'percentage' => 0
        ]
    ]);
}
?>