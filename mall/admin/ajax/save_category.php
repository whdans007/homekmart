<?php
/**
 * POST mall/admin/ajax/save_category.php
 * 상품 큐레이션 화면(products.php) 좌측 카테고리 메뉴에서 대분류/소분류 카테고리를 추가/수정/삭제한다.
 * add 액션에 parent_id가 있으면 소분류로, 없으면 대분류로 추가한다.
 * categories 테이블은 몰 전용이 아니라 구매/재고/도매 등 전체 시스템이 공유하므로
 * mall_management와 별개로 category_management 권한을 추가로 확인한다.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../lib/session_helper.php';
require_once __DIR__ . '/../../../lib/permission_helper.php';
require_once __DIR__ . '/../../../config/db_config.php';
require_once __DIR__ . '/../../config/mall_config.php';
require_once __DIR__ . '/../../lib/csrf.php';

function json_error($code, $message, $http = 400, $details = null) {
    http_response_code($http);
    echo json_encode(['success' => false, 'error' => ['code' => $code, 'message' => $message, 'details' => $details]]);
    exit;
}

if (!is_logged_in()) {
    json_error('UNAUTHORIZED', '로그인이 필요합니다', 401);
}
if (!has_mall_permission('mall_management')) {
    json_error('UNAUTHORIZED', '권한이 없습니다', 403);
}
if (!has_mall_permission('category_management')) {
    json_error('UNAUTHORIZED', '카테고리 관리 권한이 없습니다', 403);
}
if (!mall_csrf_verify($_POST['csrf_token'] ?? '')) {
    json_error('CSRF_INVALID', '요청이 만료되었습니다. 새로고침 후 다시 시도해주세요', 403);
}

$action = $_POST['action'] ?? '';

try {
    $conn = get_db_connection();

    if ($action === 'upload_image') {
        $category_id = (int)($_POST['category_id'] ?? 0);
        if ($category_id <= 0 || empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            json_error('VALIDATION_ERROR', '이미지 파일을 확인해주세요');
        }

        $category_check = $conn->prepare('SELECT id FROM categories WHERE id = ? AND parent_id IS NULL');
        $category_check->bind_param('i', $category_id);
        $category_check->execute();
        if (!$category_check->get_result()->fetch_assoc()) {
            $category_check->close();
            $conn->close();
            json_error('VALIDATION_ERROR', '유효한 대분류 카테고리가 아닙니다');
        }
        $category_check->close();

        $allowed_mimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/avif' => 'avif'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $_FILES['image']['tmp_name']);
        finfo_close($finfo);
        if (!isset($allowed_mimes[$mime])) {
            json_error('VALIDATION_ERROR', 'JPG/PNG/WEBP/AVIF 이미지만 업로드할 수 있습니다');
        }
        if ($_FILES['image']['size'] > 5 * 1024 * 1024) {
            json_error('VALIDATION_ERROR', '이미지 크기는 5MB 이하여야 합니다');
        }

        $upload_dir = __DIR__ . '/../../uploads/categories/';
        if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
            json_error('SERVER_ERROR', '파일 저장 폴더를 만들 수 없습니다', 500);
        }
        $filename = bin2hex(random_bytes(16)) . '.' . $allowed_mimes[$mime];
        if (!move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $filename)) {
            json_error('SERVER_ERROR', '파일 저장에 실패했습니다', 500);
        }

        $image_url = 'uploads/categories/' . $filename;
        $stmt = $conn->prepare('UPDATE categories SET image_url = ? WHERE id = ? AND parent_id IS NULL');
        $stmt->bind_param('si', $image_url, $category_id);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        echo json_encode(['success' => true, 'data' => ['image_url' => $image_url]]);
        exit;
    }

    if ($action === 'remove_image') {
        $category_id = (int)($_POST['category_id'] ?? 0);
        if ($category_id <= 0) {
            json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
        }
        $stmt = $conn->prepare('UPDATE categories SET image_url = NULL WHERE id = ? AND parent_id IS NULL');
        $stmt->bind_param('i', $category_id);
        $stmt->execute();
        if ($stmt->affected_rows === 0) {
            $check = $conn->prepare('SELECT id FROM categories WHERE id = ? AND parent_id IS NULL');
            $check->bind_param('i', $category_id);
            $check->execute();
            $valid_category = (bool)$check->get_result()->fetch_assoc();
            $check->close();
            if (!$valid_category) {
                $stmt->close();
                $conn->close();
                json_error('VALIDATION_ERROR', '유효한 대분류 카테고리가 아닙니다');
            }
        }
        $stmt->close();
        $conn->close();
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $name_en = trim($_POST['name_en'] ?? '');
        $parent_id = (int)($_POST['parent_id'] ?? 0);
        if ($name === '') {
            json_error('VALIDATION_ERROR', '카테고리명을 입력해주세요');
        }

        // parent_id가 넘어오면 소분류 추가(products.php 좌측 "소분류" 폼). 없으면 기존처럼 대분류 추가.
        // 2단계 구조만 허용하므로 부모로 지정한 카테고리 자신이 이미 소분류(parent_id가 있음)면 거부한다.
        $parent_id_value = null;
        if ($parent_id > 0) {
            $parent_check = $conn->prepare('SELECT id FROM categories WHERE id = ? AND parent_id IS NULL');
            $parent_check->bind_param('i', $parent_id);
            $parent_check->execute();
            if (!$parent_check->get_result()->fetch_assoc()) {
                $parent_check->close();
                $conn->close();
                json_error('VALIDATION_ERROR', '유효하지 않은 상위 카테고리입니다');
            }
            $parent_check->close();
            $parent_id_value = $parent_id;
        }

        $check = $conn->prepare('SELECT id FROM categories WHERE name = ?');
        $check->bind_param('s', $name);
        $check->execute();
        if ($check->get_result()->fetch_assoc()) {
            $check->close();
            $conn->close();
            json_error('VALIDATION_ERROR', '이미 존재하는 카테고리명입니다');
        }
        $check->close();

        $stmt = $conn->prepare('INSERT INTO categories (name, name_en, parent_id) VALUES (?, ?, ?)');
        $stmt->bind_param('ssi', $name, $name_en, $parent_id_value);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'bulk_edit') {
        // 카테고리 관리 모달의 "변경/추가 내용 한번에 적용" — 기존 카테고리 이름 수정(category_id[]/name[]/name_en[])과
        // "추가"로 화면에 쌓아둔 신규 카테고리(new_name[]/new_name_en[]/new_parent_id[])를 한 트랜잭션으로 함께 반영한다.
        $ids = $_POST['category_id'] ?? [];
        $names = $_POST['name'] ?? [];
        $names_en = $_POST['name_en'] ?? [];
        $new_names = $_POST['new_name'] ?? [];
        $new_names_en = $_POST['new_name_en'] ?? [];
        $new_parent_ids = $_POST['new_parent_id'] ?? [];

        if (!is_array($ids) || !is_array($names) || !is_array($names_en) || count($ids) !== count($names) || count($ids) !== count($names_en)) {
            json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
        }
        if (!is_array($new_names) || !is_array($new_names_en) || !is_array($new_parent_ids)
            || count($new_names) !== count($new_names_en) || count($new_names) !== count($new_parent_ids)) {
            json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
        }

        $updates = [];
        foreach ($ids as $i => $raw_id) {
            $cid = (int)$raw_id;
            $name = trim($names[$i] ?? '');
            $name_en = trim($names_en[$i] ?? '');
            if ($cid <= 0 || $name === '') {
                json_error('VALIDATION_ERROR', '카테고리명을 입력해주세요');
            }
            $updates[$cid] = ['name' => $name, 'name_en' => $name_en];
        }

        $inserts = [];
        foreach ($new_names as $i => $raw_name) {
            $name = trim($raw_name);
            $name_en = trim($new_names_en[$i] ?? '');
            $parent_raw = trim((string)($new_parent_ids[$i] ?? ''));
            if ($name === '') {
                json_error('VALIDATION_ERROR', '추가하려는 카테고리명이 비어있습니다');
            }
            $inserts[] = ['name' => $name, 'name_en' => $name_en, 'parent_id' => $parent_raw !== '' ? (int)$parent_raw : null];
        }

        if (empty($updates) && empty($inserts)) {
            json_error('VALIDATION_ERROR', '적용할 변경사항이 없습니다');
        }

        // 신규로 추가하려는 상위 카테고리가 실제로 존재하는 대분류인지 검증 (2단계 구조만 허용)
        foreach ($inserts as $ins) {
            if ($ins['parent_id'] !== null) {
                $parent_check = $conn->prepare('SELECT id FROM categories WHERE id = ? AND parent_id IS NULL');
                $parent_check->bind_param('i', $ins['parent_id']);
                $parent_check->execute();
                if (!$parent_check->get_result()->fetch_assoc()) {
                    $parent_check->close();
                    $conn->close();
                    json_error('VALIDATION_ERROR', '유효하지 않은 상위 카테고리입니다');
                }
                $parent_check->close();
            }
        }

        // 배치 내부 이름 중복 검사(수정 대상 + 신규 추가 전부 합쳐서)
        $all_names = array_merge(array_column($updates, 'name'), array_column($inserts, 'name'));
        $name_counts = array_count_values($all_names);
        foreach ($name_counts as $dup_name => $cnt) {
            if ($cnt > 1) {
                $conn->close();
                json_error('VALIDATION_ERROR', "카테고리명이 중복됩니다: {$dup_name}");
            }
        }
        // 배치 밖의 기존 카테고리와의 중복 검사
        foreach ($updates as $cid => $u) {
            $check = $conn->prepare('SELECT id FROM categories WHERE name = ? AND id != ?');
            $check->bind_param('si', $u['name'], $cid);
            $check->execute();
            if ($check->get_result()->fetch_assoc()) {
                $check->close();
                $conn->close();
                json_error('VALIDATION_ERROR', "이미 존재하는 카테고리명입니다: {$u['name']}");
            }
            $check->close();
        }
        foreach ($inserts as $ins) {
            $check = $conn->prepare('SELECT id FROM categories WHERE name = ?');
            $check->bind_param('s', $ins['name']);
            $check->execute();
            if ($check->get_result()->fetch_assoc()) {
                $check->close();
                $conn->close();
                json_error('VALIDATION_ERROR', "이미 존재하는 카테고리명입니다: {$ins['name']}");
            }
            $check->close();
        }

        $conn->begin_transaction();
        if (!empty($updates)) {
            $stmt = $conn->prepare('UPDATE categories SET name = ?, name_en = ? WHERE id = ?');
            foreach ($updates as $cid => $u) {
                $stmt->bind_param('ssi', $u['name'], $u['name_en'], $cid);
                $stmt->execute();
            }
            $stmt->close();
        }
        if (!empty($inserts)) {
            $ins_stmt = $conn->prepare('INSERT INTO categories (name, name_en, parent_id) VALUES (?, ?, ?)');
            foreach ($inserts as $ins) {
                $ins_stmt->bind_param('ssi', $ins['name'], $ins['name_en'], $ins['parent_id']);
                $ins_stmt->execute();
            }
            $ins_stmt->close();
        }
        $conn->commit();
        $conn->close();
        echo json_encode(['success' => true, 'data' => ['updated' => count($updates), 'inserted' => count($inserts)]]);
        exit;
    }

    if ($action === 'edit') {
        $category_id = (int)($_POST['category_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $name_en = trim($_POST['name_en'] ?? '');
        if ($category_id <= 0 || $name === '') {
            json_error('VALIDATION_ERROR', '카테고리명을 입력해주세요');
        }

        $check = $conn->prepare('SELECT id FROM categories WHERE name = ? AND id != ?');
        $check->bind_param('si', $name, $category_id);
        $check->execute();
        if ($check->get_result()->fetch_assoc()) {
            $check->close();
            $conn->close();
            json_error('VALIDATION_ERROR', '이미 존재하는 카테고리명입니다');
        }
        $check->close();

        $stmt = $conn->prepare('UPDATE categories SET name = ?, name_en = ? WHERE id = ?');
        $stmt->bind_param('ssi', $name, $name_en, $category_id);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'delete') {
        $category_id = (int)($_POST['category_id'] ?? 0);
        if ($category_id <= 0) {
            json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
        }

        // 이 대분류 + 하위 소분류에 등록된(활성) 상품이 하나라도 있으면 삭제를 막는다.
        $sub_stmt = $conn->prepare('SELECT id FROM categories WHERE id = ? OR parent_id = ?');
        $sub_stmt->bind_param('ii', $category_id, $category_id);
        $sub_stmt->execute();
        $sub_ids = array_column($sub_stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id');
        $sub_stmt->close();
        if (empty($sub_ids)) {
            $sub_ids = [$category_id];
        }

        // 실제로 어떤 상품이 걸려있는지 관리자가 바로 알 수 있도록 목록도 함께 가져온다
        // (mall_products에서 "삭제"해도 products.category_id는 안 지워지므로, 몰 큐레이션 삭제와
        // 헷갈려서 "분명히 다 지웠는데 왜 안 지워지지"라는 문의가 잦았다).
        $placeholders = implode(',', array_fill(0, count($sub_ids), '?'));
        $blocking_stmt = $conn->prepare("SELECT id, name_ko, sku FROM products WHERE category_id IN ({$placeholders}) AND is_active = 1 ORDER BY name_ko LIMIT 21");
        $blocking_stmt->bind_param(str_repeat('i', count($sub_ids)), ...$sub_ids);
        $blocking_stmt->execute();
        $blocking_products = $blocking_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $blocking_stmt->close();

        $count_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM products WHERE category_id IN ({$placeholders}) AND is_active = 1");
        $count_stmt->bind_param(str_repeat('i', count($sub_ids)), ...$sub_ids);
        $count_stmt->execute();
        $product_count = (int)($count_stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $count_stmt->close();

        if ($product_count > 0) {
            $conn->close();
            $shown = array_slice($blocking_products, 0, 20);
            $details = [
                'product_count' => $product_count,
                'products' => array_map(fn($p) => ['id' => (int)$p['id'], 'name' => $p['name_ko'], 'sku' => $p['sku']], $shown),
                'truncated' => $product_count > 20,
            ];
            json_error('VALIDATION_ERROR', "이 카테고리에 상품이 {$product_count}개 등록되어 있어 삭제할 수 없습니다. 먼저 상품의 카테고리를 변경해주세요.", 400, $details);
        }

        // mall_fresh_products.category_id는 categories에 대한 FK(ON DELETE 미지정=RESTRICT)라, 배정된
        // 신선상품이 있으면 아래 DELETE가 FK 위반으로 실패한다. 일반상품과 동일하게 미리 확인해 친절한
        // 메시지로 안내한다(products.php "신선상품" 탭에서 배정한 항목).
        $fresh_count_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM mall_fresh_products WHERE category_id IN ({$placeholders})");
        $fresh_count_stmt->bind_param(str_repeat('i', count($sub_ids)), ...$sub_ids);
        $fresh_count_stmt->execute();
        $fresh_product_count = (int)($fresh_count_stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $fresh_count_stmt->close();

        if ($fresh_product_count > 0) {
            $conn->close();
            json_error('VALIDATION_ERROR', "이 카테고리에 신선상품이 {$fresh_product_count}개 배정되어 있어 삭제할 수 없습니다. 먼저 신선상품의 카테고리 배정을 해제해주세요.", 400, ['fresh_product_count' => $fresh_product_count]);
        }

        // categories.parent_id는 ON DELETE SET NULL이므로 하위 카테고리는 삭제되지 않고 대분류만 해제된다.
        $stmt = $conn->prepare('DELETE FROM categories WHERE id = ?');
        $stmt->bind_param('i', $category_id);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'clear_and_delete') {
        // "delete"가 걸려있는 상품 때문에 실패했을 때, 카테고리 삭제 화면에서 바로 이어서 쓰는 액션.
        // 걸려있는 상품들의 category_id를 비우고(=미지정) 카테고리 자체를 삭제한다. 되돌릴 수 없다.
        $category_id = (int)($_POST['category_id'] ?? 0);
        if ($category_id <= 0) {
            json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
        }

        $sub_stmt = $conn->prepare('SELECT id FROM categories WHERE id = ? OR parent_id = ?');
        $sub_stmt->bind_param('ii', $category_id, $category_id);
        $sub_stmt->execute();
        $sub_ids = array_column($sub_stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id');
        $sub_stmt->close();
        if (empty($sub_ids)) {
            $sub_ids = [$category_id];
        }

        $placeholders = implode(',', array_fill(0, count($sub_ids), '?'));
        $types = str_repeat('i', count($sub_ids));

        $conn->begin_transaction();

        $clear_stmt = $conn->prepare("UPDATE products SET category_id = NULL WHERE category_id IN ({$placeholders})");
        $clear_stmt->bind_param($types, ...$sub_ids);
        $clear_stmt->execute();
        $cleared_count = $clear_stmt->affected_rows;
        $clear_stmt->close();

        // mall_fresh_products.category_id도 함께 비워야 아래 카테고리 삭제가 FK 위반 없이 진행된다.
        $clear_fresh_stmt = $conn->prepare("UPDATE mall_fresh_products SET category_id = NULL WHERE category_id IN ({$placeholders})");
        $clear_fresh_stmt->bind_param($types, ...$sub_ids);
        $clear_fresh_stmt->execute();
        $cleared_fresh_count = $clear_fresh_stmt->affected_rows;
        $clear_fresh_stmt->close();

        $del_stmt = $conn->prepare('DELETE FROM categories WHERE id = ?');
        $del_stmt->bind_param('i', $category_id);
        $del_stmt->execute();
        $del_stmt->close();

        $conn->commit();
        $conn->close();
        echo json_encode(['success' => true, 'data' => ['cleared_products' => $cleared_count, 'cleared_fresh_products' => $cleared_fresh_count]]);
        exit;
    }

    $conn->close();
    json_error('VALIDATION_ERROR', '알 수 없는 작업입니다');
} catch (Exception $e) {
    error_log('save_category.php error: ' . $e->getMessage());
    json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
