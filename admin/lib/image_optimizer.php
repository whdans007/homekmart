<?php
/**
 * 상품 이미지 자동 최적화
 * 업로드된 이미지를 3가지 크기로 자동 변환 및 저장
 * - thumb: 썸네일용 (200x200)
 * - detail: 상세정보용 (800x800)
 * - original: 원본
 */

if (!defined('ADMIN_PRODUCT_IMG_DIR')) {
    define('ADMIN_PRODUCT_IMG_DIR', __DIR__ . '/../mall/uploads/products');
    define('ADMIN_PRODUCT_IMG_THUMB_SIZE', 200);
    define('ADMIN_PRODUCT_IMG_DETAIL_SIZE', 800);
}

/**
 * 이미지 파일을 최적화하여 3가지 크기로 저장
 * @param array $file $_FILES['image']
 * @param string $error 에러 메시지 (참조 반환)
 * @return array|null {thumb: '경로', detail: '경로', original: '경로'} 또는 null
 */
function optimize_product_image(array $file, string &$error = ''): ?array {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        $error = '파일 업로드 실패';
        return null;
    }

    if ($file['size'] <= 0 || $file['size'] > 5 * 1024 * 1024) {
        $error = '이미지 크기는 5MB 이하여야 합니다';
        return null;
    }

    $allowed_mimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!isset($allowed_mimes[$mime])) {
        $error = 'JPG/PNG/WEBP 이미지만 업로드할 수 있습니다';
        return null;
    }

    if (!extension_loaded('gd')) {
        $error = 'GD 라이브러리가 필요합니다';
        return null;
    }

    $ext = $allowed_mimes[$mime];

    if (!is_dir(ADMIN_PRODUCT_IMG_DIR)) {
        @mkdir(ADMIN_PRODUCT_IMG_DIR, 0755, true);
    }

    $rand = bin2hex(random_bytes(8));
    $filename = $rand . '.' . $ext;

    // 원본 이미지 로드
    $source = match($mime) {
        'image/jpeg' => imagecreatefromjpeg($file['tmp_name']),
        'image/png' => imagecreatefrompng($file['tmp_name']),
        'image/webp' => imagecreatefromwebp($file['tmp_name']),
        default => null
    };

    if (!$source) {
        $error = '이미지 처리 실패';
        return null;
    }

    $result = [];

    try {
        // 1. 원본 저장
        $original_path = ADMIN_PRODUCT_IMG_DIR . '/original_' . $filename;
        $save_success = match($ext) {
            'jpg' => imagejpeg($source, $original_path, 85),
            'png' => imagepng($source, $original_path, 6),
            'webp' => imagewebp($source, $original_path, 80),
            default => false
        };

        if (!$save_success) {
            $error = '원본 이미지 저장 실패';
            imagedestroy($source);
            return null;
        }
        $result['original'] = 'uploads/products/original_' . $filename;

        // 2. 썸네일 생성 (200x200)
        $thumb = create_resized_image($source, ADMIN_PRODUCT_IMG_THUMB_SIZE);
        if ($thumb) {
            $thumb_path = ADMIN_PRODUCT_IMG_DIR . '/thumb_' . $filename;
            $save_success = match($ext) {
                'jpg' => imagejpeg($thumb, $thumb_path, 80),
                'png' => imagepng($thumb, $thumb_path, 6),
                'webp' => imagewebp($thumb, $thumb_path, 75),
                default => false
            };

            if ($save_success) {
                $result['thumb'] = 'uploads/products/thumb_' . $filename;
            }
            imagedestroy($thumb);
        }

        // 3. 상세정보용 생성 (800x800)
        $detail = create_resized_image($source, ADMIN_PRODUCT_IMG_DETAIL_SIZE);
        if ($detail) {
            $detail_path = ADMIN_PRODUCT_IMG_DIR . '/detail_' . $filename;
            $save_success = match($ext) {
                'jpg' => imagejpeg($detail, $detail_path, 82),
                'png' => imagepng($detail, $detail_path, 6),
                'webp' => imagewebp($detail, $detail_path, 78),
                default => false
            };

            if ($save_success) {
                $result['detail'] = 'uploads/products/detail_' . $filename;
            }
            imagedestroy($detail);
        }

        imagedestroy($source);

        // 최소한 original은 있어야 함
        return isset($result['original']) ? $result : null;

    } catch (Exception $e) {
        $error = '이미지 처리 중 오류: ' . $e->getMessage();
        imagedestroy($source);
        return null;
    }
}

/**
 * 이미지를 리사이즈 (정사각형, 비율 유지, 중앙 정렬)
 * @param resource $source 원본 이미지 리소스
 * @param int $size 목표 크기 (너비=높이)
 * @return resource|null 리사이즈된 이미지 리소스
 */
function create_resized_image($source, int $size) {
    $src_width = imagesx($source);
    $src_height = imagesy($source);

    // 정사각형 캔버스 생성
    $canvas = imagecreatetruecolor($size, $size);

    // 배경색 설정 (흰색)
    $bg = imagecolorallocate($canvas, 255, 255, 255);
    imagefill($canvas, 0, 0, $bg);

    // 원본 비율 계산
    $ratio = min($size / $src_width, $size / $src_height);
    $new_width = (int)($src_width * $ratio);
    $new_height = (int)($src_height * $ratio);

    // 중앙에 배치할 좌표
    $x = (int)(($size - $new_width) / 2);
    $y = (int)(($size - $new_height) / 2);

    // 리사이즈 및 배치
    imagecopyresampled($canvas, $source, $x, $y, 0, 0, $new_width, $new_height, $src_width, $src_height);

    return $canvas;
}

/**
 * 최적화된 이미지 세트 중 적절한 이미지 선택
 * @param array $image_set {thumb, detail, original} 중 하나
 * @param string $type 'thumb' | 'detail' | 'original'
 * @return string 이미지 경로
 */
function get_optimized_image_url(array $image_set, string $type = 'detail'): string {
    return $image_set[$type] ?? $image_set['original'] ?? '';
}
