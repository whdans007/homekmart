<?php
/**
 * 회원 배송지(mall_addresses) 유스케이스
 * Design Ref: mall/address.php — 구글 맵 핀 입력 기반 배송지 등록
 *
 * region/city/barangay는 리버스 지오코딩 결과를 사용자가 확인/수정한 자유 입력 텍스트다
 * (필리핀 행정구역 전체를 구조화 데이터로 관리하지 않는다 — 범위 밖).
 */
require_once __DIR__ . '/../../config/db_config.php';

/**
 * 회원의 배송지 목록을 기본 배송지가 먼저 오도록 조회합니다.
 * @param int $member_id
 * @return array<int, array>
 */
function mall_address_list($member_id) {
    $conn = get_db_connection();
    $stmt = $conn->prepare(
        'SELECT id, recipient_name, phone, region, city, barangay, detail_address, landmark, lat, lng, is_default
         FROM mall_addresses WHERE member_id = ? ORDER BY is_default DESC, created_at DESC'
    );
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();
    return $rows;
}

/**
 * 소유자 확인 후 배송지 한 건을 조회합니다(IDOR 방지 — 항상 member_id 조건을 함께 건다).
 * @param int $member_id
 * @param int $address_id
 * @return array|null
 */
function mall_address_get($member_id, $address_id) {
    $conn = get_db_connection();
    $stmt = $conn->prepare(
        'SELECT id, recipient_name, phone, region, city, barangay, detail_address, landmark, lat, lng, is_default
         FROM mall_addresses WHERE id = ? AND member_id = ?'
    );
    $stmt->bind_param('ii', $address_id, $member_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    return $row ?: null;
}

/**
 * 배송지를 새로 저장합니다. 회원의 첫 배송지는 무조건 기본 배송지가 되고,
 * is_default를 요청하면 기존 기본 배송지의 플래그를 내린다.
 * @param int $member_id
 * @param array{recipient_name:string, phone:string, region:string, city:string, barangay:string,
 *              detail_address:string, landmark:string, lat:float, lng:float, is_default:bool} $data
 * @return array{success:bool, id?:int, error?:string}
 */
function mall_address_create($member_id, $data) {
    if (trim($data['recipient_name'] ?? '') === '' || trim($data['phone'] ?? '') === '' || trim($data['landmark'] ?? '') === '') {
        return ['success' => false, 'error' => 'VALIDATION_ERROR'];
    }
    if (!is_numeric($data['lat'] ?? null) || !is_numeric($data['lng'] ?? null)) {
        return ['success' => false, 'error' => 'PIN_REQUIRED'];
    }

    $conn = get_db_connection();

    $count_stmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM mall_addresses WHERE member_id = ?');
    $count_stmt->bind_param('i', $member_id);
    $count_stmt->execute();
    $is_first = (int)($count_stmt->get_result()->fetch_assoc()['cnt'] ?? 0) === 0;
    $count_stmt->close();

    $is_default = $is_first || !empty($data['is_default']);
    if ($is_default) {
        mall_address_clear_default($conn, $member_id);
    }

    $recipient_name = trim($data['recipient_name']);
    $phone = trim($data['phone']);
    $region = trim($data['region'] ?? '');
    $city = trim($data['city'] ?? '');
    $barangay = trim($data['barangay'] ?? '');
    $detail_address = trim($data['detail_address'] ?? '');
    $landmark = trim($data['landmark']);
    $lat = (float)$data['lat'];
    $lng = (float)$data['lng'];
    $is_default_int = $is_default ? 1 : 0;

    $stmt = $conn->prepare(
        'INSERT INTO mall_addresses
            (member_id, recipient_name, phone, region, city, barangay, detail_address, landmark, lat, lng, is_default)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param(
        'isssssssddi',
        $member_id, $recipient_name, $phone, $region, $city, $barangay, $detail_address, $landmark, $lat, $lng, $is_default_int
    );
    $success = $stmt->execute();
    $new_id = $conn->insert_id;
    $stmt->close();
    $conn->close();

    return $success ? ['success' => true, 'id' => $new_id] : ['success' => false, 'error' => 'SERVER_ERROR'];
}

/**
 * 기존 배송지를 수정합니다(소유자 확인 포함).
 * @param int $member_id
 * @param int $address_id
 * @param array $data mall_address_create()와 동일한 필드
 * @return array{success:bool, error?:string}
 */
function mall_address_update($member_id, $address_id, $data) {
    if (trim($data['recipient_name'] ?? '') === '' || trim($data['phone'] ?? '') === '' || trim($data['landmark'] ?? '') === '') {
        return ['success' => false, 'error' => 'VALIDATION_ERROR'];
    }
    if (!is_numeric($data['lat'] ?? null) || !is_numeric($data['lng'] ?? null)) {
        return ['success' => false, 'error' => 'PIN_REQUIRED'];
    }

    $conn = get_db_connection();

    if (!empty($data['is_default'])) {
        mall_address_clear_default($conn, $member_id);
    }

    $recipient_name = trim($data['recipient_name']);
    $phone = trim($data['phone']);
    $region = trim($data['region'] ?? '');
    $city = trim($data['city'] ?? '');
    $barangay = trim($data['barangay'] ?? '');
    $detail_address = trim($data['detail_address'] ?? '');
    $landmark = trim($data['landmark']);
    $lat = (float)$data['lat'];
    $lng = (float)$data['lng'];
    $is_default_int = !empty($data['is_default']) ? 1 : 0;

    $stmt = $conn->prepare(
        'UPDATE mall_addresses SET
            recipient_name = ?, phone = ?, region = ?, city = ?, barangay = ?, detail_address = ?, landmark = ?,
            lat = ?, lng = ?, is_default = IF(? = 1, 1, is_default)
         WHERE id = ? AND member_id = ?'
    );
    $stmt->bind_param(
        'sssssssddiii',
        $recipient_name, $phone, $region, $city, $barangay, $detail_address, $landmark, $lat, $lng, $is_default_int, $address_id, $member_id
    );
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    $conn->close();

    return $affected >= 0 ? ['success' => true] : ['success' => false, 'error' => 'SERVER_ERROR'];
}

/**
 * 배송지를 삭제합니다. 기본 배송지를 지운 경우 남은 것 중 가장 최근 배송지를 새 기본으로 승격합니다.
 * @param int $member_id
 * @param int $address_id
 * @return array{success:bool}
 */
function mall_address_delete($member_id, $address_id) {
    $conn = get_db_connection();

    $stmt = $conn->prepare('DELETE FROM mall_addresses WHERE id = ? AND member_id = ?');
    $stmt->bind_param('ii', $address_id, $member_id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected > 0) {
        $remaining = $conn->prepare('SELECT id FROM mall_addresses WHERE member_id = ? AND is_default = 1');
        $remaining->bind_param('i', $member_id);
        $remaining->execute();
        $has_default = $remaining->get_result()->num_rows > 0;
        $remaining->close();

        if (!$has_default) {
            // UPDATE에는 ORDER BY/LIMIT을 직접 걸 수 없어 대상 id를 먼저 조회한다.
            $find = $conn->prepare('SELECT id FROM mall_addresses WHERE member_id = ? ORDER BY created_at DESC LIMIT 1');
            $find->bind_param('i', $member_id);
            $find->execute();
            $next = $find->get_result()->fetch_assoc();
            $find->close();
            if ($next) {
                $set = $conn->prepare('UPDATE mall_addresses SET is_default = 1 WHERE id = ?');
                $set->bind_param('i', $next['id']);
                $set->execute();
                $set->close();
            }
        }
    }

    $conn->close();
    return ['success' => $affected > 0];
}

/**
 * 배송지를 기본 배송지로 지정합니다(기존 기본은 자동으로 해제).
 * @param int $member_id
 * @param int $address_id
 * @return array{success:bool}
 */
function mall_address_set_default($member_id, $address_id) {
    $conn = get_db_connection();
    mall_address_clear_default($conn, $member_id);

    $stmt = $conn->prepare('UPDATE mall_addresses SET is_default = 1 WHERE id = ? AND member_id = ?');
    $stmt->bind_param('ii', $address_id, $member_id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    $conn->close();

    return ['success' => $affected > 0];
}

/**
 * 회원의 기존 기본 배송지 플래그를 전부 내립니다(내부 헬퍼 — 연결을 넘겨받아 재사용).
 * @param mysqli $conn
 * @param int $member_id
 * @return void
 */
function mall_address_clear_default($conn, $member_id) {
    $stmt = $conn->prepare('UPDATE mall_addresses SET is_default = 0 WHERE member_id = ?');
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $stmt->close();
}
