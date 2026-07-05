<?php
require_once __DIR__ . '/config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!empty($_SESSION['user_id']) && !empty($_SESSION['store_id'])) {
    header('Location: ' . STORE_BASE . '/order.php');
} else {
    header('Location: ' . STORE_BASE . '/login.php');
}
exit;
