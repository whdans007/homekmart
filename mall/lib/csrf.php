<?php
/**
 * CSRF 토큰 헬퍼 (관리자 폼 + 주문 관련 POST에 적용)
 * Design Ref: shopping-mall.design.md §7 — "CSRF 토큰: 관리자 폼 및 주문/결제 관련 POST에 세션 기반 토큰 검증 추가"
 *
 * 세션 이름에 무관하게 현재 활성 $_SESSION에 저장하므로 mall_members 세션(MALLSESSID)과
 * 관리자 세션(PHPSESSID) 양쪽에서 그대로 재사용할 수 있다. 세션 시작은 호출측 책임이다.
 */

/**
 * 현재 세션의 CSRF 토큰을 반환합니다(없으면 새로 생성).
 * @return string
 */
function mall_csrf_token() {
    if (empty($_SESSION['mall_csrf_token'])) {
        $_SESSION['mall_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['mall_csrf_token'];
}

/**
 * 전달된 토큰이 세션 토큰과 일치하는지 확인합니다(타이밍 공격 방지 hash_equals).
 * @param string|null $token
 * @return bool
 */
function mall_csrf_verify($token) {
    return !empty($_SESSION['mall_csrf_token']) && is_string($token) && hash_equals($_SESSION['mall_csrf_token'], $token);
}

/**
 * 폼에 삽입할 hidden input HTML을 반환합니다.
 * @return string
 */
function mall_csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(mall_csrf_token()) . '">';
}
