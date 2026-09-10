<?php
/**
 * Foodpang 관리 화면 전역에서 쓰는 "현재 작업 대상 점포" 단일 판정 로직.
 *
 * foodpang/admin/products.php(페이지 렌더링)와 foodpang/admin/ajax_products.php(저장 처리)가
 * 각자 store_id를 따로 계산하면, 세션 상태에 따라 두 값이 어긋나 카테고리 추가/수정/삭제가
 * "성공했다고 나오는데 안 보이는" 현상으로 이어질 수 있다. 이 파일 하나로 판정 로직을 통일해
 * 페이지와 AJAX가 항상 같은 점포를 기준으로 동작하게 한다.
 *
 * admin/partials/header.php의 판정 규칙과 동일하게 맞춘다:
 *  - super_admin: 세션에 저장된 "현재 선택한 점포"(점포 스위처)를 사용. 없거나 존재하지 않는
 *    점포면 1번 점포로 보정하고, 보정된 값을 세션에 다시 저장해 이후 요청과 일관되게 만든다.
 *  - 그 외 역할: 세션 값을 신뢰하지 않고 users.store_id(실제 소속 점포)를 매 요청마다 새로
 *    조회한다. 점포 이동 후 재로그인 전이라도 항상 관리자의 실제 소속 점포를 반영하기 위함이다.
 */
function foodpang_resolve_store_id(mysqli $conn) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $role = $_SESSION['role'] ?? '';

    if ($role === 'super_admin') {
        $store_id = (int)($_SESSION['store_id'] ?? 0);
        if ($store_id <= 0) {
            $store_id = 1;
        }

        $check = $conn->prepare('SELECT id FROM stores WHERE id = ?');
        $check->bind_param('i', $store_id);
        $check->execute();
        $exists = (bool)$check->get_result()->fetch_assoc();
        $check->close();
        if (!$exists) {
            $store_id = 1;
        }

        $_SESSION['store_id'] = $store_id;
        return $store_id;
    }

    $user_id = (int)($_SESSION['user_id'] ?? 0);
    if ($user_id > 0) {
        $stmt = $conn->prepare('SELECT store_id FROM users WHERE id = ?');
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row && (int)$row['store_id'] > 0) {
            return (int)$row['store_id'];
        }
    }

    // users.store_id 조회가 실패/공란인 예외적인 경우에만 세션 값으로 폴백한다.
    $fallback = (int)($_SESSION['store_id'] ?? 0);
    return $fallback > 0 ? $fallback : 1;
}
