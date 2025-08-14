<?php
/**
 * 다국어 지원 헬퍼
 * 
 * 사용법:
 * - t('key') : 번역된 텍스트 반환
 * - set_language('en') : 언어 설정
 * - get_language() : 현재 언어 반환
 */

// 기본 언어 설정
if (!isset($_SESSION['language'])) {
    $_SESSION['language'] = 'ko';
}

// 번역 캐시
$_translation_cache = [];

/**
 * 번역 파일 로드
 * @param string $lang 언어 코드 (ko, en)
 * @return array 번역 배열
 */
function load_translations($lang = 'ko') {
    global $_translation_cache;
    
    if (isset($_translation_cache[$lang])) {
        return $_translation_cache[$lang];
    }
    
    $lang_file = __DIR__ . "/../lang/{$lang}.json";
    
    if (!file_exists($lang_file)) {
        error_log("Translation file not found: {$lang_file}");
        return [];
    }
    
    $content = file_get_contents($lang_file);
    $translations = json_decode($content, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Invalid JSON in translation file: {$lang_file}");
        return [];
    }
    
    $_translation_cache[$lang] = $translations;
    return $translations;
}

/**
 * 번역 키를 실제 텍스트로 변환
 * @param string $key 번역 키 (예: "user.add", "common.save")
 * @param array $params 치환할 파라미터 배열
 * @return string 번역된 텍스트
 */
function t($key, $params = []) {
    $lang = get_language();
    $translations = load_translations($lang);
    
    // 점으로 구분된 키를 배열 경로로 변환
    $keys = explode('.', $key);
    $value = $translations;
    
    foreach ($keys as $k) {
        if (isset($value[$k])) {
            $value = $value[$k];
        } else {
            // 번역이 없으면 한국어로 폴백
            if ($lang !== 'ko') {
                $ko_translations = load_translations('ko');
                $ko_value = $ko_translations;
                foreach ($keys as $ko_k) {
                    if (isset($ko_value[$ko_k])) {
                        $ko_value = $ko_value[$ko_k];
                    } else {
                        $ko_value = $key; // 한국어도 없으면 키 자체 반환
                        break;
                    }
                }
                $value = $ko_value;
            } else {
                $value = $key; // 키 자체 반환
            }
            break;
        }
    }
    
    // 파라미터 치환
    if (!empty($params) && is_string($value)) {
        foreach ($params as $param_key => $param_value) {
            $value = str_replace("{{$param_key}}", $param_value, $value);
        }
    }
    
    return is_string($value) ? $value : $key;
}

/**
 * 현재 언어 반환
 * @return string 언어 코드
 */
function get_language() {
    return $_SESSION['language'] ?? 'ko';
}

/**
 * 언어 설정
 * @param string $lang 언어 코드 (ko, en)
 * @return bool 성공 여부
 */
function set_language($lang) {
    $supported_languages = ['ko', 'en'];
    
    if (!in_array($lang, $supported_languages)) {
        return false;
    }
    
    $_SESSION['language'] = $lang;
    
    // 번역 캐시 초기화
    global $_translation_cache;
    $_translation_cache = [];
    
    return true;
}

/**
 * 지원되는 언어 목록 반환
 * @return array 언어 목록
 */
function get_supported_languages() {
    return [
        'ko' => '한국어',
        'en' => 'English'
    ];
}

/**
 * 현재 언어의 RTL 여부 반환
 * @return bool RTL 여부
 */
function is_rtl_language() {
    $rtl_languages = []; // 현재는 RTL 언어 지원 안함
    return in_array(get_language(), $rtl_languages);
}

/**
 * 언어별 날짜 형식 반환
 * @param string $date 날짜 문자열
 * @param string $format 형식 (short, long)
 * @return string 형식화된 날짜
 */
function format_date($date, $format = 'short') {
    $lang = get_language();
    $timestamp = is_numeric($date) ? $date : strtotime($date);
    
    if ($lang === 'en') {
        return $format === 'long' 
            ? date('F j, Y', $timestamp)
            : date('M j, Y', $timestamp);
    } else {
        return $format === 'long'
            ? date('Y년 n월 j일', $timestamp)
            : date('Y-m-d', $timestamp);
    }
}

/**
 * 언어별 숫자 형식 반환
 * @param float $number 숫자
 * @return string 형식화된 숫자
 */
function format_number($number) {
    $lang = get_language();
    
    if ($lang === 'en') {
        return number_format($number, 0, '.', ',');
    } else {
        return number_format($number, 0, '.', ',');
    }
}

/**
 * 언어별 통화 형식 반환
 * @param float $amount 금액
 * @return string 형식화된 금액
 */
function format_currency($amount) {
    $lang = get_language();
    $formatted = number_format($amount, 0, '.', ',');
    
    if ($lang === 'en') {
        return "₩{$formatted}";
    } else {
        return "{$formatted}원";
    }
}

/**
 * JavaScript에서 사용할 번역 객체 생성
 * @param array $keys 포함할 번역 키 배열 (없으면 모든 키 포함)
 * @return string JSON 형식의 번역 객체
 */
function get_js_translations($keys = []) {
    $lang = get_language();
    $translations = load_translations($lang);
    
    if (!empty($keys)) {
        // 특정 키들만 추출
        $filtered_translations = [];
        foreach ($keys as $key) {
            $value = get_translation_value($translations, $key);
            if ($value !== $key) { // 번역이 존재하는 경우만
                set_nested_array_value($filtered_translations, $key, $value);
            }
        }
        return json_encode($filtered_translations, JSON_UNESCAPED_UNICODE);
    }
    
    return json_encode($translations, JSON_UNESCAPED_UNICODE);
}

/**
 * 중첩된 배열에서 점으로 구분된 키로 값 가져오기
 * @param array $array 대상 배열
 * @param string $key 점으로 구분된 키
 * @return mixed 값 또는 키 자체
 */
function get_translation_value($array, $key) {
    $keys = explode('.', $key);
    $value = $array;
    
    foreach ($keys as $k) {
        if (isset($value[$k])) {
            $value = $value[$k];
        } else {
            return $key;
        }
    }
    
    return is_string($value) ? $value : $key;
}

/**
 * 중첩된 배열에 점으로 구분된 키로 값 설정
 * @param array &$array 대상 배열 (참조)
 * @param string $key 점으로 구분된 키
 * @param mixed $value 설정할 값
 */
function set_nested_array_value(&$array, $key, $value) {
    $keys = explode('.', $key);
    $current = &$array;
    
    foreach ($keys as $k) {
        if (!isset($current[$k])) {
            $current[$k] = [];
        }
        $current = &$current[$k];
    }
    
    $current = $value;
}

/**
 * JavaScript용 번역 스크립트 태그 생성
 * @param array $keys 포함할 번역 키 배열
 * @return string JavaScript 스크립트 태그
 */
function get_js_translation_script($keys = []) {
    $js_translations = get_js_translations($keys);
    $current_lang = get_language();
    
    return "<script>
        window.translations = {$js_translations};
        window.currentLanguage = '{$current_lang}';
        
        // JavaScript에서 번역 함수
        function t(key, params = {}) {
            let keys = key.split('.');
            let value = window.translations;
            
            for (let k of keys) {
                if (value && typeof value === 'object' && k in value) {
                    value = value[k];
                } else {
                    return key; // 번역이 없으면 키 반환
                }
            }
            
            if (typeof value === 'string') {
                // 파라미터 치환
                for (let [param_key, param_value] of Object.entries(params)) {
                    value = value.replace(new RegExp('\\{' + param_key + '\\}', 'g'), param_value);
                }
                return value;
            }
            
            return key;
        }
    </script>";
}