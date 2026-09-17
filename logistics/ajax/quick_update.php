<?php
// Design Ref: §3 — 브랜드/카테고리 이름 인라인 수정 AJAX (quick_create.php 미러링)
require_once __DIR__ . '/../lib/auth.php';
header('Content-Type: application/json; charset=utf-8');

lc_require_staff();

$type    = $_POST['type']    ?? '';
$id      = (int)($_POST['id'] ?? 0);
$name_en = trim($_POST['name_en'] ?? '');
$name_ko = trim($_POST['name_ko'] ?? '') ?: null;
$token   = $_POST['csrf_token'] ?? '';

if (!hash_equals($_SESSION['lc_csrf'] ?? '', $token)) {
    echo json_encode(['success' => false, 'message' => t('logistics.ajax_quick_update.security_error')]);
    exit;
}

if (!in_array($type, ['brand', 'category'], true)) {
    echo json_encode(['success' => false, 'message' => t('logistics.ajax_quick_update.invalid_request')]);
    exit;
}

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => t('logistics.ajax_quick_update.invalid_target')]);
    exit;
}

// Plan SC-3: 영문 이름 필수
if ($name_en === '') {
    echo json_encode(['success' => false, 'message' => t('logistics.ajax_quick_update.name_required')]);
    exit;
}

try {
    $conn = get_lc_db();

    // 테이블은 화이트리스트 분기로만 결정 → SQL 인젝션 불가
    $table = $type === 'brand' ? 'lc_brands' : 'lc_categories';

    // Plan SC-1: name_en / name_ko 갱신
    $st = $conn->prepare("UPDATE {$table} SET name_en = ?, name_ko = ? WHERE id = ?");
    $st->bind_param('ssi', $name_en, $name_ko, $id);
    $st->execute();
    $affected = $st->affected_rows;
    $st->close();
    $conn->close();

    if ($affected < 0) {
        echo json_encode(['success' => false, 'message' => t('logistics.ajax_quick_update.update_failed')]);
        exit;
    }

    echo json_encode(['success' => true, 'id' => $id, 'name_en' => $name_en, 'name_ko' => $name_ko]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => t('logistics.ajax_quick_update.db_error', ['error' => $e->getMessage()])]);
}
