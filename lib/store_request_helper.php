<?php
// Design Ref: docs/02-design/features/store-request-board.design.md §3 — 소유권검증/뱃지카운트만 공용화(Option C)

// 점포 소유권 검증: request_id가 해당 store_id 소유인지 확인. 아니면 null 반환
function get_owned_store_request(mysqli $conn, int $request_id, int $store_id): ?array {
    $st = $conn->prepare("SELECT * FROM lc_store_requests WHERE id = ? AND store_id = ?");
    $st->bind_param('ii', $request_id, $store_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

// 물류센터 사이드바 뱃지: 대기(pending) 요청 건수
// 마이그레이션 미적용 등으로 테이블이 없으면 query()가 false를 반환할 수 있어 방어적으로 처리
function count_pending_store_requests(mysqli $conn): int {
    $res = $conn->query("SELECT COUNT(*) FROM lc_store_requests WHERE status='pending'");
    return $res ? (int)$res->fetch_row()[0] : 0;
}

function store_request_status_label(string $s): string {
    return ['pending' => 'Pending', 'in_progress' => 'In Progress', 'done' => 'Done'][$s] ?? $s;
}

function store_request_status_class(string $s): string {
    return [
        'pending'     => 'bg-yellow-100 text-yellow-800',
        'in_progress' => 'bg-blue-100 text-blue-800',
        'done'        => 'bg-green-100 text-green-800',
    ][$s] ?? 'bg-gray-100 text-gray-500';
}
