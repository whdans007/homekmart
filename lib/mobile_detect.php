<?php
/**
 * 모바일 기기 감지 헬퍼 함수
 * User-Agent를 기반으로 모바일 기기 여부를 판단
 */

/**
 * 모바일 기기 여부 감지
 * @return bool 모바일 기기이면 true, 아니면 false
 */
function is_mobile_device() {
    if (!isset($_SERVER['HTTP_USER_AGENT'])) {
        return false;
    }
    
    $user_agent = $_SERVER['HTTP_USER_AGENT'];
    
    // 모바일 기기 패턴 배열
    $mobile_patterns = [
        // iOS 기기
        '/iPhone/',
        '/iPad/',
        '/iPod/',
        
        // Android 기기
        '/Android/',
        
        // Windows Phone
        '/Windows Phone/',
        '/Windows Mobile/',
        '/IEMobile/',
        
        // BlackBerry
        '/BlackBerry/',
        '/BB10/',
        
        // 기타 모바일
        '/Mobile/',
        '/webOS/',
        '/Opera Mini/',
        '/Opera Mobi/',
        '/Kindle/',
        '/Silk/',
        
        // 태블릿 (일부)
        '/Tablet/',
        
        // 한국 통신사 모바일
        '/SamsungBrowser/',
        '/KAKAOTALK/',
        '/NAVER/',
        
        // 일반적인 모바일 키워드
        '/phone/i',
        '/mobile/i',
        '/portable/i',
        '/handheld/i'
    ];
    
    // 데스크톱으로 강제 설정된 경우 확인
    if (isset($_GET['desktop']) && $_GET['desktop'] == '1') {
        return false;
    }
    
    // 모바일 헤더 확인
    if (isset($_SERVER['HTTP_X_WAP_PROFILE']) || 
        isset($_SERVER['HTTP_PROFILE']) ||
        isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'vnd.wap.wml') !== false) {
        return true;
    }
    
    // User-Agent 패턴 매칭
    foreach ($mobile_patterns as $pattern) {
        if (preg_match($pattern, $user_agent)) {
            return true;
        }
    }
    
    // 화면 크기 기반 감지 (JavaScript에서 설정된 쿠키 확인)
    if (isset($_COOKIE['screen_width']) && (int)$_COOKIE['screen_width'] <= 768) {
        return true;
    }
    
    return false;
}

/**
 * 태블릿 기기 여부 감지 (선택적으로 태블릿을 모바일에서 제외하고 싶을 때)
 * @return bool 태블릿 기기이면 true, 아니면 false
 */
function is_tablet_device() {
    if (!isset($_SERVER['HTTP_USER_AGENT'])) {
        return false;
    }
    
    $user_agent = $_SERVER['HTTP_USER_AGENT'];
    
    $tablet_patterns = [
        '/iPad/',
        '/Android(?!.*Mobile)/',  // Android 태블릿 (Mobile이 없는 Android)
        '/Tablet/',
        '/PlayBook/',
        '/Kindle/',
        '/Silk/',
        '/GT-P\d{4}/',  // 삼성 갤럭시 탭
        '/SM-T\d{3}/'   // 삼성 갤럭시 탭 S 시리즈
    ];
    
    foreach ($tablet_patterns as $pattern) {
        if (preg_match($pattern, $user_agent)) {
            return true;
        }
    }
    
    return false;
}

/**
 * 모바일 전용 페이지로 리다이렉트
 * @param string $mobile_url 모바일 페이지 URL
 * @param bool $include_tablet 태블릿도 모바일로 처리할지 여부 (기본: true)
 */
function redirect_if_mobile($mobile_url = 'mobile_main.php', $include_tablet = true) {
    // 이미 모바일 페이지에 있으면 리다이렉트하지 않음
    if (strpos($_SERVER['REQUEST_URI'], 'mobile_') !== false) {
        return;
    }
    
    // 데스크톱으로 강제 설정된 경우 리다이렉트하지 않음
    if (isset($_GET['desktop']) && $_GET['desktop'] == '1') {
        return;
    }
    
    $is_mobile = is_mobile_device();
    $is_tablet = is_tablet_device();
    
    // 태블릿 처리 옵션에 따라 결정
    if ($include_tablet) {
        $should_redirect = $is_mobile;
    } else {
        $should_redirect = $is_mobile && !$is_tablet;
    }
    
    if ($should_redirect) {
        // 현재 GET 파라미터 유지
        $query_params = $_GET;
        $query_string = http_build_query($query_params);
        
        $redirect_url = $mobile_url;
        if (!empty($query_string)) {
            $redirect_url .= (strpos($mobile_url, '?') !== false ? '&' : '?') . $query_string;
        }
        
        header('Location: ' . $redirect_url);
        exit;
    }
}

/**
 * 현재 기기 정보를 반환
 * @return array 기기 정보 배열
 */
function get_device_info() {
    return [
        'is_mobile' => is_mobile_device(),
        'is_tablet' => is_tablet_device(),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'screen_width' => $_COOKIE['screen_width'] ?? null,
        'forced_desktop' => isset($_GET['desktop']) && $_GET['desktop'] == '1'
    ];
}
?>