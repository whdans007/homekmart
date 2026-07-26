<?php
// Design Ref: 상품 대표 이미지 — 업로드 검증/저장/삭제 공통 헬퍼
// 파일은 logistics/uploads/products/ 에 저장하고, DB에는 logistics 루트 기준 상대경로만 보관한다.

if (!defined('LC_PRODUCT_IMG_REL')) {
    define('LC_PRODUCT_IMG_REL', 'uploads/products');                 // logistics 루트 기준 상대경로
    define('LC_PRODUCT_IMG_DIR', dirname(__DIR__) . '/' . LC_PRODUCT_IMG_REL); // 절대 디렉터리
    define('LC_PRODUCT_IMG_MAX', 5 * 1024 * 1024);                    // 5MB
}

/**
 * 허용 확장자 ↔ MIME 매핑
 */
function kw_product_img_allowed(): array {
    return [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
}

/**
 * 업로드된 상품 이미지를 검증 후 저장한다.
 * @param array  $file  $_FILES['image'] 항목
 * @param string $error 실패 사유(참조 반환)
 * @return string|null  성공 시 상대경로(uploads/products/xxx.jpg), 실패/미업로드 시 null
 */
function kw_handle_product_image_upload(array $file, string &$error = ''): ?string {
    // 업로드 자체가 없으면 조용히 null (선택 항목)
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'Image upload failed (code ' . (int)$file['error'] . ').';
        return null;
    }
    if ($file['size'] <= 0 || $file['size'] > LC_PRODUCT_IMG_MAX) {
        $error = 'Image must be 5MB or smaller.';
        return null;
    }

    // 실제 MIME 검사 (확장자 위조 방지)
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = $finfo ? finfo_file($finfo, $file['tmp_name']) : null;
    if ($finfo) finfo_close($finfo);

    $allowed = kw_product_img_allowed();
    if (!isset($allowed[$mime])) {
        $error = 'Only JPG, PNG, WEBP, GIF images are allowed.';
        return null;
    }
    $ext = $allowed[$mime];

    // 저장 디렉터리 보장
    if (!is_dir(LC_PRODUCT_IMG_DIR)) {
        if (!@mkdir(LC_PRODUCT_IMG_DIR, 0755, true) && !is_dir(LC_PRODUCT_IMG_DIR)) {
            $error = 'Cannot create upload directory.';
            return null;
        }
    }

    try {
        $rand = bin2hex(random_bytes(4));
    } catch (Exception $e) {
        $rand = substr(md5(uniqid('', true)), 0, 8);
    }
    $fname = 'p_' . time() . '_' . $rand . '.' . $ext;
    $dest  = LC_PRODUCT_IMG_DIR . '/' . $fname;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        $error = 'Failed to save the image file.';
        return null;
    }
    @chmod($dest, 0644);

    return LC_PRODUCT_IMG_REL . '/' . $fname;
}

/**
 * 저장된 상품 이미지 파일 삭제 (DB 갱신은 호출측 책임)
 * @param string|null $rel_path uploads/products/xxx.jpg 형태의 상대경로
 */
function kw_delete_product_image(?string $rel_path): void {
    if (!$rel_path) return;
    // 경로 탈출 방지: 반드시 uploads/products/ 하위만 허용
    $rel_path = str_replace('\\', '/', $rel_path);
    if (strpos($rel_path, LC_PRODUCT_IMG_REL . '/') !== 0 || strpos($rel_path, '..') !== false) {
        return;
    }
    $abs = dirname(__DIR__) . '/' . $rel_path;
    if (is_file($abs)) {
        @unlink($abs);
    }
}
