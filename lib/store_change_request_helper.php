<?php
/**
 * 소속 지점 변경 요청 / 승인 워크플로 헬퍼.
 * - CEO 미만 사용자는 직접 변경 불가 → 변경 '요청'만 가능
 * - 도착(발령) 지점의 점장 이상(또는 CEO 이상)이 승인하면 users.store_id 반영
 * Ref: store_change_requests 테이블 (sql/store_change_request.sql)
 */
require_once __DIR__ . '/../config/db_config.php';

/**
 * 내부 PDO 커넥션 헬퍼.
 * @return PDO
 */
function scr_pdo() {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

/**
 * 해당 사용자의 처리 대기(pending) 요청 1건을 반환합니다.
 * @param int $user_id
 * @return array|null
 */
function get_user_pending_store_change($user_id) {
    try {
        $pdo = scr_pdo();
        $stmt = $pdo->prepare(
            "SELECT * FROM store_change_requests WHERE user_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Exception $e) {
        error_log("get_user_pending_store_change error: " . $e->getMessage());
        return null;
    }
}

/**
 * 지점 변경 요청을 생성합니다.
 * @param int $user_id 이동 대상 사용자
 * @param int $to_store_id 도착(발령) 지점
 * @param string $reason 사유
 * @param int $requested_by 요청자
 * @return bool|string 성공 시 true, 실패 시 오류 메시지
 */
function create_store_change_request($user_id, $to_store_id, $reason, $requested_by) {
    try {
        if (get_user_pending_store_change($user_id)) {
            return '이미 처리 대기중인 지점 변경 요청이 있습니다.';
        }
        $pdo = scr_pdo();
        $stmt = $pdo->prepare("SELECT store_id FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $from_store_id = $stmt->fetchColumn();
        if ($from_store_id === false) {
            return '사용자를 찾을 수 없습니다.';
        }
        if ((int)$from_store_id === (int)$to_store_id) {
            return '현재 소속과 동일한 지점입니다.';
        }

        $ins = $pdo->prepare(
            "INSERT INTO store_change_requests (user_id, from_store_id, to_store_id, reason, status, requested_by)
             VALUES (?, ?, ?, ?, 'pending', ?)"
        );
        $ins->execute([$user_id, $from_store_id ?: null, $to_store_id, ($reason !== '' ? $reason : null), $requested_by]);
        return true;
    } catch (Exception $e) {
        error_log("create_store_change_request error: " . $e->getMessage());
        return '요청 생성 중 오류가 발생했습니다.';
    }
}

/**
 * 대리 발령 요청 대상 직원 후보 목록을 반환합니다.
 * @param int|null $only_store_id 특정 지점 소속으로 제한(점장용). null이면 전체(CEO 이상).
 * @return array
 */
function get_store_change_candidates($only_store_id = null) {
    try {
        $pdo = scr_pdo();
        $sql = "SELECT u.id, u.username, u.full_name, u.store_id, s.name AS store_name
                FROM users u
                LEFT JOIN stores s ON u.store_id = s.id";
        $params = [];
        if ($only_store_id !== null) {
            $sql .= " WHERE u.store_id = ?";
            $params[] = $only_store_id;
        }
        $sql .= " ORDER BY s.name ASC, u.full_name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("get_store_change_candidates error: " . $e->getMessage());
        return [];
    }
}

/**
 * 특정 사용자의 현재 소속 지점 ID를 반환합니다.
 * @param int $user_id
 * @return int|null
 */
function scr_get_user_store_id($user_id) {
    try {
        $pdo = scr_pdo();
        $stmt = $pdo->prepare("SELECT store_id FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $v = $stmt->fetchColumn();
        return ($v === false || $v === null) ? null : (int)$v;
    } catch (Exception $e) {
        error_log("scr_get_user_store_id error: " . $e->getMessage());
        return null;
    }
}

/**
 * 전체 지점 목록을 반환합니다.
 * @return array
 */
function scr_get_all_stores() {
    try {
        $pdo = scr_pdo();
        return $pdo->query("SELECT id, name FROM stores ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("scr_get_all_stores error: " . $e->getMessage());
        return [];
    }
}

/**
 * 단건 요청을 ID로 조회합니다.
 * @param int $id
 * @return array|null
 */
function get_store_change_request_by_id($id) {
    try {
        $pdo = scr_pdo();
        $stmt = $pdo->prepare("SELECT * FROM store_change_requests WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Exception $e) {
        error_log("get_store_change_request_by_id error: " . $e->getMessage());
        return null;
    }
}

/**
 * 처리 대기중인 전체 요청 목록(사용자/지점명 조인)을 반환합니다.
 * @return array
 */
function get_pending_store_change_requests() {
    try {
        $pdo = scr_pdo();
        $sql = "SELECT r.*, u.username, u.full_name,
                       fs.name AS from_store_name, ts.name AS to_store_name,
                       rb.full_name AS requested_by_name
                FROM store_change_requests r
                JOIN users u ON r.user_id = u.id
                LEFT JOIN stores fs ON r.from_store_id = fs.id
                LEFT JOIN stores ts ON r.to_store_id = ts.id
                LEFT JOIN users rb ON r.requested_by = rb.id
                WHERE r.status = 'pending'
                ORDER BY r.created_at ASC";
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("get_pending_store_change_requests error: " . $e->getMessage());
        return [];
    }
}

/**
 * 요청을 승인/반려 처리합니다. 승인 시 users.store_id를 반영합니다.
 * @param int $id 요청 ID
 * @param bool $approve true=승인, false=반려
 * @param int $approver_id 처리자
 * @param string $note 처리 메모
 * @return bool|string 성공 시 true, 실패 시 오류 메시지
 */
function decide_store_change_request($id, $approve, $approver_id, $note = '') {
    $pdo = null;
    try {
        $pdo = scr_pdo();
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM store_change_requests WHERE id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$req || $req['status'] !== 'pending') {
            $pdo->rollBack();
            return '이미 처리되었거나 존재하지 않는 요청입니다.';
        }

        if ($approve) {
            $upd = $pdo->prepare("UPDATE users SET store_id = ? WHERE id = ?");
            $upd->execute([$req['to_store_id'], $req['user_id']]);
        }

        $status = $approve ? 'approved' : 'rejected';
        $u = $pdo->prepare(
            "UPDATE store_change_requests SET status = ?, approved_by = ?, decision_note = ?, decided_at = NOW() WHERE id = ?"
        );
        $u->execute([$status, $approver_id, ($note !== '' ? $note : null), $id]);

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        if ($pdo && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("decide_store_change_request error: " . $e->getMessage());
        return '처리 중 오류가 발생했습니다.';
    }
}
