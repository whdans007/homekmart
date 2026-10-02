<?php
/**
 * KIM'S MALL 재고조사 스키마 마이그레이션 v24 (브라우저 실행용)
 *
 * 기존 sql/run_stock_count.php 는 CLI 전용이라 운영 서버에서 실행할 수 없었다.
 * 이 스크립트는 같은 스키마(sql/kw_stock_count.sql + 후속 ALTER)를 브라우저에서 적용한다.
 * 모두 CREATE TABLE IF NOT EXISTS / 컬럼 존재 확인 후 ALTER 이므로 재실행 안전하며 기존 데이터는 변경하지 않는다.
 *
 * 실행: 로그인한 창고 관리자 브라우저에서
 *   기본(DRY-RUN, 아무것도 변경 안 함): .../kimsmall_wherehouse/sql/run_migration_v24.php
 *   적용:                               .../run_migration_v24.php?apply=1&confirm=KW-V24
 *
 * 수동 롤백 SQL (자동 실행 안 함, 재고조사 데이터가 없을 때만 사용):
 *   DROP TABLE IF EXISTS kw_stock_count_adjustments, kw_stock_count_entries, kw_stock_count_baseline,
 *                        kw_stock_count_sessions, kw_stock_count_control;
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../config/db.php';

kw_session_start();
if (empty($_SESSION['user_id'])) {
    header('Location: ' . LC_BASE . '/login.php');
    exit;
}
if (!kw_is_admin()) {
    http_response_code(403);
    exit('관리자 권한이 필요합니다.');
}

header('Content-Type: text/html; charset=utf-8');
echo "<!doctype html><html lang=\"ko\"><meta charset=\"utf-8\"><title>마이그레이션 v24</title><body><pre>\n";
$apply = (($_GET['apply'] ?? '') === '1' && ($_GET['confirm'] ?? '') === 'KW-V24');
echo "=== 재고조사 스키마 마이그레이션 v24 ===\n";
echo '모드: ' . ($apply ? '적용' : 'DRY-RUN') . "\n";
if (!$apply) {
    echo "(변경 없이 점검만 합니다. 적용하려면 ?apply=1&confirm=KW-V24 를 붙이세요)\n";
}

// 테이블 정의 (kw_stock_count.sql 과 동일)
$tables = [
    'kw_stock_count_control' => "CREATE TABLE IF NOT EXISTS kw_stock_count_control (
 id TINYINT PRIMARY KEY,
 open_session_id BIGINT NULL
) ENGINE=InnoDB",
    'kw_stock_count_sessions' => "CREATE TABLE IF NOT EXISTS kw_stock_count_sessions (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 status ENUM('open','finalized','cancelled') NOT NULL DEFAULT 'open',
 opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 opened_by INT NOT NULL,
 inbound_closed_ack_at DATETIME NULL,
 inbound_closed_ack_by INT NULL,
 finalized_at DATETIME NULL,
 finalized_by INT NULL,
 cancelled_at DATETIME NULL,
 cancelled_by INT NULL
) ENGINE=InnoDB",
    'kw_stock_count_baseline' => "CREATE TABLE IF NOT EXISTS kw_stock_count_baseline (
 session_id BIGINT NOT NULL,
 product_id INT NOT NULL,
 unit ENUM('BOX','PACK','PCS') NOT NULL,
 quantity BIGINT NOT NULL,
 pieces_per_box INT NOT NULL,
 PRIMARY KEY (session_id, product_id, unit),
 FOREIGN KEY (session_id) REFERENCES kw_stock_count_sessions(id)
) ENGINE=InnoDB",
    'kw_stock_count_entries' => "CREATE TABLE IF NOT EXISTS kw_stock_count_entries (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 session_id BIGINT NOT NULL,
 product_id INT NOT NULL,
 barcode VARCHAR(100) NOT NULL,
 unit ENUM('BOX','PACK','PCS') NOT NULL,
 quantity INT NOT NULL,
 pieces_per_box INT NOT NULL,
 scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 entered_by INT NOT NULL,
 voided_at DATETIME NULL,
 voided_by INT NULL,
 void_reason ENUM('cancel','correction') NULL,
 corrected_from_entry_id BIGINT NULL,
 INDEX (session_id, product_id, unit),
 INDEX (session_id, scanned_at),
 INDEX (corrected_from_entry_id),
 FOREIGN KEY (session_id) REFERENCES kw_stock_count_sessions(id)
) ENGINE=InnoDB",
    'kw_stock_count_adjustments' => "CREATE TABLE IF NOT EXISTS kw_stock_count_adjustments (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 session_id BIGINT NOT NULL,
 product_id INT NOT NULL,
 unit ENUM('BOX','PACK','PCS') NOT NULL,
 inventory_id INT NOT NULL,
 before_quantity INT NOT NULL,
 after_quantity INT NOT NULL,
 delta INT NOT NULL,
 applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX (session_id, product_id),
 FOREIGN KEY (session_id) REFERENCES kw_stock_count_sessions(id)
) ENGINE=InnoDB",
];

// 이미 있는 테이블에 후속으로 추가된 컬럼 (테이블 → [컬럼 => ALTER 구문])
$extra_columns = [
    'kw_stock_count_sessions' => [
        'inbound_closed_ack_at' => 'ALTER TABLE kw_stock_count_sessions ADD COLUMN inbound_closed_ack_at DATETIME NULL AFTER opened_by',
        'inbound_closed_ack_by' => 'ALTER TABLE kw_stock_count_sessions ADD COLUMN inbound_closed_ack_by INT NULL AFTER inbound_closed_ack_at',
    ],
    'kw_stock_count_entries' => [
        'voided_at'               => 'ALTER TABLE kw_stock_count_entries ADD COLUMN voided_at DATETIME NULL AFTER entered_by',
        'voided_by'               => 'ALTER TABLE kw_stock_count_entries ADD COLUMN voided_by INT NULL AFTER voided_at',
        'void_reason'             => "ALTER TABLE kw_stock_count_entries ADD COLUMN void_reason ENUM('cancel','correction') NULL AFTER voided_by",
        'corrected_from_entry_id' => 'ALTER TABLE kw_stock_count_entries ADD COLUMN corrected_from_entry_id BIGINT NULL AFTER void_reason, ADD INDEX idx_kw_sc_corrected_from (corrected_from_entry_id)',
    ],
];

try {
    $conn   = get_lc_db();
    $server = $conn->query('SELECT DATABASE() AS db_name, @@hostname AS db_host')->fetch_assoc();
    echo '접속 DB: ' . htmlspecialchars((string)$server['db_name'], ENT_QUOTES, 'UTF-8')
        . ' / 호스트: ' . htmlspecialchars((string)$server['db_host'], ENT_QUOTES, 'UTF-8') . "\n\n";

    $tableExists = static function (mysqli $db, string $table): bool {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        return $exists;
    };
    $columnExists = static function (mysqli $db, string $table, string $column): bool {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        return $exists;
    };

    // ── 사전점검 ──
    echo "[사전점검]\n";
    foreach (array_keys($tables) as $t) {
        echo "{$t}: " . ($tableExists($conn, $t) ? '있음' : '없음') . "\n";
    }
    foreach ($extra_columns as $t => $cols) {
        if (!$tableExists($conn, $t)) continue;
        foreach (array_keys($cols) as $c) {
            echo "{$t}.{$c}: " . ($columnExists($conn, $t, $c) ? '있음' : '없음') . "\n";
        }
    }
    echo "\n";

    // ── 적용 ──
    if ($apply) {
        foreach ($tables as $t => $ddl) {
            if ($tableExists($conn, $t)) {
                echo "SKIP: {$t} 이미 존재\n";
                continue;
            }
            $conn->query($ddl);
            echo "OK: {$t} 생성\n";
        }
        $conn->query('INSERT IGNORE INTO kw_stock_count_control (id, open_session_id) VALUES (1, NULL)');
        echo "OK: kw_stock_count_control 기본 행(id=1) 확인\n";
        foreach ($extra_columns as $t => $cols) {
            foreach ($cols as $c => $alter) {
                if ($columnExists($conn, $t, $c)) continue;
                $conn->query($alter);
                echo "OK: {$t}.{$c} 컬럼 추가\n";
            }
        }

        // ── 적용 후 검증 ──
        echo "\n[적용 후 검증]\n";
        $missing = [];
        foreach (array_keys($tables) as $t) {
            if (!$tableExists($conn, $t)) $missing[] = $t;
        }
        foreach ($extra_columns as $t => $cols) {
            foreach (array_keys($cols) as $c) {
                if (!$columnExists($conn, $t, $c)) $missing[] = "{$t}.{$c}";
            }
        }
        $ctl = (int)$conn->query('SELECT COUNT(*) FROM kw_stock_count_control WHERE id = 1')->fetch_row()[0];
        echo '누락 항목: ' . ($missing ? implode(', ', $missing) : '없음') . "\n";
        echo "kw_stock_count_control 기본 행: {$ctl} (1이어야 정상)\n";
        echo ($missing || $ctl !== 1) ? "\n=== 확인 필요 ===\n" : "\n=== 완료 ===\n";
    } else {
        echo "DRY-RUN: 데이터와 스키마를 변경하지 않았습니다.\n";
    }

    $conn->close();
} catch (Throwable $e) {
    echo "\n오류: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "\n";
}
echo "</pre></body></html>";
