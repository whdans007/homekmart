<?php
/**
 * 가격 내보내기 엔드포인트 — ★ 지점(킴스몰) 서버에 배포하는 파일 ★
 * ─────────────────────────────────────────────────────────────
 * 이 서버(킴스몰)의 원가/판매가를 JSON 으로 반환한다. 메인 서버가 HTTPS 로 호출한다.
 * 인증: ?key=... 또는 X-Sync-Key 헤더 == EXPORT_API_KEY (메인의 remote_stores.php api_key 와 동일)
 *
 * 반환 형식:
 *   { "success": true, "store": "KIMS MALL (킴스몰)",
 *     "items": [ { "sku": "8801234567890", "cost_price": 1000.00, "selling_price": 1500 }, ... ] }
 */

// ★ 메인 서버 remote_stores.php 의 api_key 와 반드시 동일하게 설정 ★
define('EXPORT_API_KEY', 'ks_sync_7Gq2Xr9TfWm4Zc8Ld1Bv6Np3Yh0Ke5A');

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db_config.php'; // 이 서버(킴스몰)의 로컬 DB

function export_respond(int $code, array $arr): void {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

// 인증
if (EXPORT_API_KEY === '' || strpos(EXPORT_API_KEY, 'REPLACE') !== false) {
    export_respond(500, ['success' => false, 'message' => 'EXPORT_API_KEY 미설정']);
}
$provided = $_GET['key'] ?? ($_SERVER['HTTP_X_SYNC_KEY'] ?? '');
if (!is_string($provided) || !hash_equals(EXPORT_API_KEY, $provided)) {
    export_respond(401, ['success' => false, 'message' => '인증 실패']);
}

$conn = get_db_connection();

// 이 서버의 대상 점포 탐지: 이름 LIKE 'KIMS%', 없으면 점포가 하나뿐일 때 그 점포
$store_id   = null;
$store_name = '';
$res = $conn->query("SELECT id, name FROM stores WHERE name LIKE 'KIMS%' ORDER BY id LIMIT 1");
if ($res && ($row = $res->fetch_assoc())) {
    $store_id = (int)$row['id'];
    $store_name = $row['name'];
} else {
    $res = $conn->query("SELECT id, name FROM stores LIMIT 2");
    if ($res && $res->num_rows === 1) {
        $row = $res->fetch_assoc();
        $store_id = (int)$row['id'];
        $store_name = $row['name'];
    }
}
if (!$store_id) {
    export_respond(404, ['success' => false, 'message' => '이 서버에서 대상 점포를 찾을 수 없습니다.']);
}

// 가격 + 상품명 조회 (바코드 보유 항목만)
$stmt = $conn->prepare(
    "SELECT p.sku, p.name_ko, p.name_en, p.pieces_per_box, i.cost_price, i.selling_price
     FROM inventory i JOIN products p ON p.id = i.product_id
     WHERE i.store_id = ? AND p.sku IS NOT NULL AND p.sku <> ''"
);
$stmt->bind_param('i', $store_id);
$stmt->execute();

$items = [];
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $items[] = [
        'sku'            => $row['sku'],
        'name_ko'        => $row['name_ko'],
        'name_en'        => $row['name_en'],
        'pieces_per_box' => $row['pieces_per_box'] !== null ? (int)$row['pieces_per_box'] : 1,
        'cost_price'     => $row['cost_price']    !== null ? (float)$row['cost_price']    : null,
        'selling_price'  => $row['selling_price'] !== null ? (float)$row['selling_price'] : null,
    ];
}

export_respond(200, [
    'success'     => true,
    'store'       => $store_name,
    'count'       => count($items),
    'items'       => $items,
    'exported_at' => date('Y-m-d H:i:s'),
]);
