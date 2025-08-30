<?php
/**
 * 간단한 디버그 파일
 */

header('Content-Type: application/json; charset=utf-8');

$response = [];

// 1. 현재 디렉토리
$response['current_dir'] = __DIR__;

// 2. 상위 디렉토리 확인
$response['parent_dirs'] = [
    '../' => is_dir('../'),
    '../../' => is_dir('../../'),
    '../../config/' => is_dir('../../config/'),
];

// 3. config 파일 경로들 확인
$possible_paths = [
    '../../config/db_config.php',
    '../../../config/db_config.php',
    '/homekmart/config/db_config.php',
    $_SERVER['DOCUMENT_ROOT'] . '/homekmart/config/db_config.php'
];

foreach ($possible_paths as $path) {
    $response['config_paths'][$path] = file_exists($path) ? 'exists' : 'not found';
}

// 4. realpath로 실제 경로 확인
$response['realpath'] = realpath('../../');

echo json_encode($response, JSON_PRETTY_PRINT);
?>