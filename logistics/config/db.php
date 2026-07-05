<?php
require_once __DIR__ . '/../../config/db_config.php';

// 서버 환경에 따라 자동 감지 (예: /sunset/logistics)
if (!defined('LC_BASE')) {
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $pos = strpos($script, '/logistics/');
    $base_prefix = $pos !== false ? substr($script, 0, $pos) : '';
    define('LC_BASE',      $base_prefix . '/logistics');
    define('LC_WEB_ROOT',  $base_prefix);              // 예: /sunset
}

function get_lc_db(): mysqli {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        error_log('lc_db connect error: ' . $conn->connect_error);
        throw new Exception('Database connection failed.');
    }
    $conn->set_charset(DB_CHARSET);
    return $conn;
}
