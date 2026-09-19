<?php
require_once __DIR__ . '/config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!empty($_SESSION['user_id']) && !empty($_SESSION['store_id'])) {
    require_once __DIR__ . '/../logistics/lib/auth.php';

    // 물류센터(CENTER) 소속 계정은 order.php에서 자기 자신에게 주문할 수 없어 다시 이곳으로
    // 돌아오므로, order.php로 보내면 index.php <-> order.php 무한 리다이렉트에 빠진다.
    // 이런 계정은 대신 킴스몰 재고 조회 페이지로 보낸다.
    $conn_center_check = get_lc_db();
    $is_center_account = lc_is_center_store($conn_center_check, (int)$_SESSION['store_id']);
    $conn_center_check->close();

    if ($is_center_account) {
        header('Location: ' . STORE_BASE . '/kimsmall_stock.php');
    } else {
        header('Location: ' . STORE_BASE . '/order.php');
    }
} else {
    header('Location: ' . STORE_BASE . '/login.php');
}
exit;
