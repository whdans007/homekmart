<?php
// Design Ref: §3.1 — logistics 패턴, ORD_BASE 상수 정의
require_once __DIR__ . '/../../config/db_config.php';

if (!defined('ORD_BASE')) {
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $pos = strpos($script, '/order/');
    $base_prefix = $pos !== false ? substr($script, 0, $pos) : '';
    define('ORD_BASE',     $base_prefix . '/order');
    define('ORD_WEB_ROOT', $base_prefix);
}

function get_ord_db(): mysqli {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        error_log('ord_db connect error: ' . $conn->connect_error);
        throw new Exception('Database connection failed.');
    }
    $conn->set_charset(DB_CHARSET);
    $conn->query("SET time_zone = '+08:00'");
    return $conn;
}
