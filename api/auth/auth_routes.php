<?php
/**
 * 인증 API 라우트
 * 구글 OAuth + 기존 로그인 통합
 */

$auth_path = $path_parts[1] ?? '';

switch ($method) {
    case 'POST':
        switch ($auth_path) {
            case 'login':
                require_once __DIR__ . '/login.php';
                break;
                
            case 'google':
                require_once __DIR__ . '/google_auth.php';
                break;
                
            case 'logout':
                require_once __DIR__ . '/logout.php';
                break;
                
            case 'refresh':
                require_once __DIR__ . '/refresh_token.php';
                break;
                
            default:
                api_not_found('Authentication endpoint not found');
        }
        break;
        
    case 'GET':
        switch ($auth_path) {
            case 'profile':
                require_once __DIR__ . '/profile.php';
                break;
                
            case 'check':
                require_once __DIR__ . '/check_auth.php';
                break;
                
            default:
                api_not_found('Authentication endpoint not found');
        }
        break;
        
    case 'PUT':
        switch ($auth_path) {
            case 'profile':
                require_once __DIR__ . '/update_profile.php';
                break;
                
            default:
                api_not_found('Authentication endpoint not found');
        }
        break;
        
    default:
        api_error('Method not allowed', 405);
}
?>