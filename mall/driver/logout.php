<?php
/**
 * 배송기사 로그아웃
 */
require_once __DIR__ . '/../lib/driver.php';

mall_driver_logout();
header('Location: /mall/driver/login.php');
exit;
