-- ============================================================
-- 소속 지점 변경 요청/승인 워크플로
-- CEO 미만 사용자는 직접 변경 불가 → 요청 후 도착 지점 점장 이상이 승인
-- 작성일: 2026-06-18
-- ============================================================

CREATE TABLE IF NOT EXISTS store_change_requests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,                 -- 이동 대상 사용자
    from_store_id INT NULL,                        -- 현재(출발) 지점
    to_store_id INT NOT NULL,                      -- 도착(발령) 지점
    reason VARCHAR(500) NULL,                       -- 요청 사유
    status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    requested_by INT UNSIGNED NOT NULL,            -- 요청자
    approved_by INT UNSIGNED NULL,                 -- 승인/반려 처리자
    decision_note VARCHAR(500) NULL,               -- 처리 메모
    decided_at TIMESTAMP NULL DEFAULT NULL,        -- 처리 시각
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_status (status),
    KEY idx_user (user_id),
    KEY idx_to_store (to_store_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
