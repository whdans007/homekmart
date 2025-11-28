<?php
session_start();
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'is_logged_in' => is_logged_in(),
    'session_data' => [
        'user_id' => $_SESSION['user_id'] ?? null,
        'username' => $_SESSION['username'] ?? null,
        'store_id' => $_SESSION['store_id'] ?? null,
        'role' => $_SESSION['role'] ?? null
    ],
    'all_session_keys' => array_keys($_SESSION)
]);
