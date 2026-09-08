<?php
// Design Ref: homekmart-store-config §4.1 — 점포별 설정(POS 대수·근무시간) 단일 진입점
// 이 파일은 config/db_config.php 외 다른 프로젝트 헬퍼에 의존하지 않는다 (순환 의존 방지).

require_once __DIR__ . '/../config/db_config.php';

const STORE_DEFAULT_POS_COUNT = 2;
const STORE_POS_COUNT_MAX     = 9;
const STORE_SHIFT_KEYS        = ['gy', 'morning', 'mid'];

// 점포별 설정 행이 없을 때 쓰는 전역 기본값 (매출 계열 채택, Plan §10 Q5)
const STORE_SHIFT_DEFAULTS = [
    'gy'      => ['label' => 'GY',      'start_time' => '00:00:00', 'end_time' => '08:00:00', 'sort_order' => 1],
    'morning' => ['label' => 'Morning', 'start_time' => '08:00:00', 'end_time' => '17:00:00', 'sort_order' => 2],
    'mid'     => ['label' => 'Mid',     'start_time' => '17:00:00', 'end_time' => '00:00:00', 'sort_order' => 3],
];

/**
 * 점포 POS 대수. 컬럼 없음/조회 실패 시 기본값(2) 반환. 요청당 정적 캐시.
 */
function get_store_pos_count(int $store_id): int {
    static $cache = [];
    if (isset($cache[$store_id])) {
        return $cache[$store_id];
    }

    try {
        $conn = get_db_connection();
        $stmt = $conn->prepare('SELECT pos_count FROM stores WHERE id = ?');
        if (!$stmt) {
            throw new Exception($conn->error);
        }
        $stmt->bind_param('i', $store_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $conn->close();

        $count = ($row && $row['pos_count'] !== null) ? (int)$row['pos_count'] : STORE_DEFAULT_POS_COUNT;
        return $cache[$store_id] = $count;
    } catch (Throwable $e) {
        error_log('get_store_pos_count error: ' . $e->getMessage());
        return $cache[$store_id] = STORE_DEFAULT_POS_COUNT;
    }
}

/**
 * 점포 교대 설정 조회.
 * @return array<string, array{label:string, start_time:string, end_time:string, display:string, sort_order:int}>
 *         키는 shift_key. 행이 없으면 해당 shift만 전역 기본값으로 채운다.
 */
function get_store_shifts(int $store_id): array {
    static $cache = [];
    if (isset($cache[$store_id])) {
        return $cache[$store_id];
    }

    $shifts = [];
    foreach (STORE_SHIFT_DEFAULTS as $key => $d) {
        $shifts[$key] = [
            'label'      => $d['label'],
            'start_time' => $d['start_time'],
            'end_time'   => $d['end_time'],
            'sort_order' => $d['sort_order'],
        ];
    }

    try {
        $conn = get_db_connection();
        $stmt = $conn->prepare(
            'SELECT shift_key, label, start_time, end_time, sort_order FROM store_shift_settings WHERE store_id = ?'
        );
        if (!$stmt) {
            throw new Exception($conn->error);
        }
        $stmt->bind_param('i', $store_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $conn->close();

        foreach ($rows as $row) {
            $key = $row['shift_key'];
            if (!in_array($key, STORE_SHIFT_KEYS, true)) {
                continue;
            }
            $shifts[$key] = [
                'label'      => $row['label'],
                'start_time' => $row['start_time'],
                'end_time'   => $row['end_time'],
                'sort_order' => (int)$row['sort_order'],
            ];
        }
    } catch (Throwable $e) {
        error_log('get_store_shifts error: ' . $e->getMessage());
        // 조회 실패 시 위에서 채운 전역 기본값 그대로 반환
    }

    foreach ($shifts as $key => &$s) {
        $s['display'] = format_shift_time($s['start_time'], $s['end_time'], 'tilde');
    }
    unset($s);

    return $cache[$store_id] = $shifts;
}

/** POS 번호 유효성. pos_recon_helper.php의 pos_valid_pos() 대체(점포별 상한 기준). */
function store_valid_pos_no(int $store_id, int $pos_no): bool {
    return $pos_no >= 1 && $pos_no <= get_store_pos_count($store_id);
}

/**
 * TIME('HH:MM:SS') 2개 → 표시 문자열.
 * style='tilde' → '8AM~5PM' (스케줄 관례, 분이 0이면 생략)
 * style='dash'  → '8:00AM–5:00PM' (매출 관례, 분 항상 표시, en dash)
 */
function format_shift_time(string $start, string $end, string $style = 'tilde'): string {
    $fmt = function (string $t) use ($style): string {
        $dt = DateTime::createFromFormat('H:i:s', $t) ?: DateTime::createFromFormat('H:i', $t);
        if (!$dt) {
            return $t;
        }
        if ($style === 'dash') {
            return $dt->format('g:iA');
        }
        return $dt->format('i') === '00' ? $dt->format('gA') : $dt->format('g:iA');
    };

    $sep = $style === 'dash' ? "\u{2013}" : '~';
    return $fmt($start) . $sep . $fmt($end);
}

/**
 * POS 대수 저장. 축소 시 기존 데이터 존재 여부 검증.
 * @return array{ok:bool, error?:string}
 */
function save_store_pos_count(int $store_id, int $pos_count, ?int $updated_by = null): array {
    if ($pos_count < 1 || $pos_count > STORE_POS_COUNT_MAX) {
        return ['ok' => false, 'error' => "POS 대수는 1 ~ " . STORE_POS_COUNT_MAX . " 사이여야 합니다."];
    }

    try {
        $conn = get_db_connection();

        // 축소 시 이미 그 번호로 데이터가 있으면 차단
        $stmt = $conn->prepare(
            'SELECT MAX(pos_no) AS max_pos FROM sales_pos_reconciliation WHERE store_id = ? AND pos_no > ?'
        );
        $stmt->bind_param('ii', $store_id, $pos_count);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row && $row['max_pos'] !== null) {
            $conn->close();
            return ['ok' => false, 'error' => "POS {$row['max_pos']}번에 이미 매출 데이터가 있어 줄일 수 없습니다."];
        }

        $stmt = $conn->prepare('UPDATE stores SET pos_count = ? WHERE id = ?');
        $stmt->bind_param('ii', $pos_count, $store_id);
        $ok = $stmt->execute();
        $stmt->close();
        $conn->close();

        if (!$ok) {
            return ['ok' => false, 'error' => 'POS 대수 저장 중 오류가 발생했습니다.'];
        }

        return ['ok' => true];
    } catch (Throwable $e) {
        error_log('save_store_pos_count error: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'POS 대수 저장 중 오류가 발생했습니다.'];
    }
}

/**
 * 근무시간 저장 (3교대 일괄, 트랜잭션).
 * @param array<string,array{label:string,start:string,end:string}> $shifts 키는 shift_key
 * @return array{ok:bool, error?:string}
 */
function save_store_shifts(int $store_id, array $shifts, ?int $updated_by = null): array {
    foreach (STORE_SHIFT_KEYS as $key) {
        if (!isset($shifts[$key])) {
            return ['ok' => false, 'error' => "근무시간 설정이 누락되었습니다: {$key}"];
        }
        $s = $shifts[$key];
        $label = trim((string)($s['label'] ?? ''));
        if ($label === '' || mb_strlen($label) > 20) {
            return ['ok' => false, 'error' => "교대 표시명은 1~20자여야 합니다: {$key}"];
        }
        if (!preg_match('/^\d{2}:\d{2}$/', $s['start'] ?? '') || !preg_match('/^\d{2}:\d{2}$/', $s['end'] ?? '')) {
            return ['ok' => false, 'error' => "시간은 HH:MM 형식이어야 합니다: {$key}"];
        }
    }

    try {
        $conn = get_db_connection();
        $conn->autocommit(false);

        $stmt = $conn->prepare(
            'INSERT INTO store_shift_settings (store_id, shift_key, label, start_time, end_time, sort_order, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE label = VALUES(label), start_time = VALUES(start_time),
               end_time = VALUES(end_time), updated_by = VALUES(updated_by)'
        );

        foreach (STORE_SHIFT_KEYS as $key) {
            $s = $shifts[$key];
            $label = trim($s['label']);
            $start = $s['start'] . ':00';
            $end   = $s['end'] . ':00';
            $sort  = STORE_SHIFT_DEFAULTS[$key]['sort_order'];
            $stmt->bind_param('ississi', $store_id, $key, $label, $start, $end, $sort, $updated_by);
            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }
        }
        $stmt->close();

        $conn->commit();
        $conn->autocommit(true);
        $conn->close();

        return ['ok' => true];
    } catch (Throwable $e) {
        if (isset($conn)) {
            $conn->rollback();
            $conn->autocommit(true);
            $conn->close();
        }
        error_log('save_store_shifts error: ' . $e->getMessage());
        return ['ok' => false, 'error' => '근무시간 저장 중 오류가 발생했습니다.'];
    }
}
