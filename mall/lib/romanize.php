<?php
/**
 * 한글 이름 로마자 변환 (Domain Layer, 의존성 없음)
 * Design Ref: mall-member-english-name.design.md §4.2, §9 Domain Layer
 *
 * 국어의 로마자 표기법(문화체육관광부 고시 제2000-8호) 초성/중성/종성 매핑을 음절 단위로
 * 그대로 적용한다. 인접 음절 간 자음 동화(예: 종성+초성 연음 규칙) 등 세부 예외는 다루지
 * 않는다 — Plan에서 "완전한 정확도 보장은 범위 밖"으로 명시했고, 부정확한 결과는 회원이
 * 마이페이지 프로필에서 직접 수정할 수 있다(mall/mypage/profile.php).
 * 외부 API 호출 없음 — 개인정보(이름)가 서버 밖으로 전송되지 않는다.
 */

const MALL_ROMANIZE_INITIALS = [
    'g', 'kk', 'n', 'd', 'tt', 'r', 'm', 'b', 'pp', 's',
    'ss', '', 'j', 'jj', 'ch', 'k', 't', 'p', 'h',
];

const MALL_ROMANIZE_MEDIALS = [
    'a', 'ae', 'ya', 'yae', 'eo', 'e', 'yeo', 'ye', 'o', 'wa',
    'wae', 'oe', 'yo', 'u', 'wo', 'we', 'wi', 'yu', 'eu', 'ui', 'i',
];

const MALL_ROMANIZE_FINALS = [
    '', 'k', 'k', 'k', 'n', 'n', 'n', 't', 'l', 'k',
    'm', 'l', 'l', 'l', 'p', 'l', 'm', 'p', 'p', 't',
    't', 'ng', 't', 't', 'k', 't', 'p', 't',
];

/**
 * 한글 음절 하나(U+AC00~U+D7A3)를 로마자로 변환합니다.
 * @param int $codepoint
 * @return string
 */
function mall_romanize_hangul_syllable($codepoint) {
    $offset = $codepoint - 0xAC00;
    $final_index = $offset % 28;
    $medial_index = intdiv($offset, 28) % 21;
    $initial_index = intdiv($offset, 28 * 21);

    return MALL_ROMANIZE_INITIALS[$initial_index]
        . MALL_ROMANIZE_MEDIALS[$medial_index]
        . MALL_ROMANIZE_FINALS[$final_index];
}

/**
 * UTF-8 문자열을 코드포인트 배열로 분해합니다(mbstring 확장 없이도 동작).
 * @param string $str
 * @return int[]
 */
function mall_romanize_utf8_to_codepoints($str) {
    $codepoints = [];
    $bytes = array_values(unpack('C*', $str));
    $len = count($bytes);
    $i = 0;
    while ($i < $len) {
        $byte = $bytes[$i];
        if ($byte < 0x80) {
            $codepoints[] = $byte;
            $i += 1;
        } elseif (($byte & 0xE0) === 0xC0 && $i + 1 < $len) {
            $codepoints[] = (($byte & 0x1F) << 6) | ($bytes[$i + 1] & 0x3F);
            $i += 2;
        } elseif (($byte & 0xF0) === 0xE0 && $i + 2 < $len) {
            $codepoints[] = (($byte & 0x0F) << 12) | (($bytes[$i + 1] & 0x3F) << 6) | ($bytes[$i + 2] & 0x3F);
            $i += 3;
        } elseif (($byte & 0xF8) === 0xF0 && $i + 3 < $len) {
            $codepoints[] = (($byte & 0x07) << 18) | (($bytes[$i + 1] & 0x3F) << 12)
                | (($bytes[$i + 2] & 0x3F) << 6) | ($bytes[$i + 3] & 0x3F);
            $i += 4;
        } else {
            // 손상된 바이트 시퀀스는 건너뛴다(변환 결과는 부정확할 수 있으나 예외를 던지지 않는다).
            $i += 1;
        }
    }
    return $codepoints;
}

/**
 * 한글 이름을 로마자 표기로 변환합니다. 성/이름 각 어절의 첫 글자만 대문자화합니다.
 * 한글이 아닌 문자(영문/숫자/공백 등)는 그대로 통과시킵니다.
 * 이 함수는 예외를 던지지 않습니다 — 변환할 수 없으면 빈 문자열을 반환합니다.
 * @param string $name
 * @return string
 */
function mall_romanize_korean_name($name) {
    if (!is_string($name) || trim($name) === '') {
        return '';
    }

    $codepoints = mall_romanize_utf8_to_codepoints($name);
    if (empty($codepoints)) {
        return '';
    }

    $words = [''];
    foreach ($codepoints as $cp) {
        if ($cp === 0x20) {
            $words[] = '';
            continue;
        }
        if ($cp >= 0xAC00 && $cp <= 0xD7A3) {
            $words[count($words) - 1] .= mall_romanize_hangul_syllable($cp);
        } else {
            $words[count($words) - 1] .= mb_chr($cp, 'UTF-8');
        }
    }

    $result = [];
    foreach ($words as $word) {
        if ($word === '') {
            continue;
        }
        $result[] = mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($word, 1, null, 'UTF-8');
    }

    return trim(implode(' ', $result));
}
