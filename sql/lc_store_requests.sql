-- ============================================================
-- 점포 요청사항 게시판 (store-request-board)
-- store 앱에서 등록 -> logistics 앱에서 확인/답변/상태변경
-- 작성일: 2026-07-07
-- ============================================================

CREATE TABLE IF NOT EXISTS lc_store_requests (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    store_id    INT NOT NULL,
    title       VARCHAR(200) NOT NULL,
    content     TEXT NOT NULL,
    status      ENUM('pending','in_progress','done') NOT NULL DEFAULT 'pending',
    created_by  INT NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_store_status (store_id, status),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='점포 요청사항 게시판';

-- stores(id)와 타입/인덱스가 일치하는 환경에서만 실행하세요 (불일치 시 생략 가능)
ALTER TABLE lc_store_requests
    ADD CONSTRAINT fk_lsr_store FOREIGN KEY (store_id) REFERENCES stores(id);

CREATE TABLE IF NOT EXISTS lc_store_request_comments (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    request_id  INT NOT NULL,
    author_side ENUM('store','logistics') NOT NULL,
    author_id   INT NOT NULL,
    author_name VARCHAR(100) NOT NULL,
    content     TEXT NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES lc_store_requests(id) ON DELETE CASCADE,
    INDEX idx_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='점포 요청사항 댓글(답변) 스레드';
