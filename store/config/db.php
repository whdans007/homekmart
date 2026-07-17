<?php
require_once __DIR__ . '/../../config/db_config.php';

if (!defined('STORE_BASE')) {
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $pos = strpos($script, '/store/');
    $base_prefix = $pos !== false ? substr($script, 0, $pos) : '';
    define('STORE_BASE',     $base_prefix . '/store');
    define('STORE_WEB_ROOT', $base_prefix);
}

function get_store_db(): mysqli {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        throw new Exception('Database connection failed.');
    }
    $conn->set_charset(DB_CHARSET);
    $conn->query("SET time_zone = '+08:00'");
    return $conn;
}
