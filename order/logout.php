<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/auth.php';
ord_session_start();
unset($_SESSION['ord_flash'], $_SESSION['ord_csrf']);
session_destroy();
header('Location: ' . ORD_BASE . '/login.php');
exit;
