<?php
require_once __DIR__ . '/config/db.php'; // LC_BASE 먼저 정의
require_once __DIR__ . '/lib/auth.php';
lc_session_start();
session_destroy();
header('Location: ' . LC_BASE . '/login.php');
exit;
