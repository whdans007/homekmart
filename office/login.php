<?php
// office 전용 로그인 폐지 — admin/login.php 로 통합 (admin 세션 공유).
// 옛 북마크/링크 호환을 위해 리다이렉트만 유지.
require_once __DIR__ . '/../lib/session_helper.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

header('Location: ../admin/login.php');
exit;
