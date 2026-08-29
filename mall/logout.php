<?php
require_once __DIR__ . '/lib/auth.php';

mall_logout();
header('Location: /mall/login.php');
exit;
