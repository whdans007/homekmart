<?php
// Design Ref: §1 — receipt-status-tracking DB migration + backfill
require_once __DIR__ . '/lib/office_helper.php';
require_office_permission();

$conn = get_db_connection();
header('Content-Type: text/html; charset=utf-8');
echo '<style>body{font-family:sans-serif;padding:20px;max-width:800px}
.ok{color:#166534;background:#f0fdf4;border:1px solid #86efac;padding:6px 12px;border-radius:6px;margin:4px 0}
.err{color:#991b1b;background:#fef2f2;border:1px solid #fca5a5;padding:6px 12px;border-radius:6px;margin:4px 0}
.info{color:#1e40af;background:#eff6ff;border:1px solid #bfdbfe;padding:6px 12px;border-radius:6px;margin:4px 0}
.skip{color:#92400e;background:#fffbeb;border:1px solid #fde68a;padding:6px 12px;border-radius:6px;margin:4px 0}
h3{margin-top:20px}</style>';
echo '<h2>Receipt Status Migration</h2>';

// 컬럼 존재 확인 헬퍼
function col_exists($conn, $table, $col) {
    $r = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
    return $r && $r->num_rows > 0;
}

// 인덱스 존재 확인 헬퍼
function idx_exists($conn, $table, $idx) {
    $r = $conn->query("SHOW INDEX FROM `{$table}` WHERE Key_name='{$idx}'");
    return $r && $r->num_rows > 0;
}

// 안전한 SQL 실행
function safe_sql($conn, $sql, $label) {
    $ok = $conn->query($sql);
    if ($ok) {
        echo "<div class='ok'>✅ {$label}</div>";
    } else {
        echo "<div class='err'>❌ {$label}: " . htmlspecialchars($conn->error) . "</div>";
    }
    return $ok;
}

// ── Step 1: 4개 컬럼 추가 ─────────────────────────────────────────────────
echo '<h3>Step 1: Add columns</h3>';

$cols = [
    'payment_type'  => "ALTER TABLE office_receipts ADD COLUMN payment_type ENUM('cash','check') NOT NULL DEFAULT 'cash' COMMENT '결제수단'",
    'er_section'    => "ALTER TABLE office_receipts ADD COLUMN er_section VARCHAR(50) NULL COMMENT 'ER 배치 섹션명'",
    'is_cer_placed' => "ALTER TABLE office_receipts ADD COLUMN is_cer_placed TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'CER 처리 완료'",
    'is_cd_paid'    => "ALTER TABLE office_receipts ADD COLUMN is_cd_paid TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'CD 처리 완료'",
];

foreach ($cols as $col => $sql) {
    if (col_exists($conn, 'office_receipts', $col)) {
        echo "<div class='skip'>⏭ {$col} — 이미 존재</div>";
    } else {
        safe_sql($conn, $sql, "{$col} column 추가");
    }
}

// 인덱스 추가
$indexes = [
    'idx_er_section'  => "ALTER TABLE office_receipts ADD INDEX idx_er_section (store_id, er_section, receipt_date)",
    'idx_cer_placed'  => "ALTER TABLE office_receipts ADD INDEX idx_cer_placed (store_id, is_cer_placed)",
    'idx_cd_paid_rc'  => "ALTER TABLE office_receipts ADD INDEX idx_cd_paid_rc (store_id, is_cd_paid)",
];
foreach ($indexes as $idx_name => $sql) {
    if (idx_exists($conn, 'office_receipts', $idx_name)) {
        echo "<div class='skip'>⏭ {$idx_name} — 이미 존재</div>";
    } else {
        safe_sql($conn, $sql, "{$idx_name} index 추가");
    }
}

// ── Step 2: er_saved_state → er_section backfill ──────────────────────────
echo '<h3>Step 2: Backfill er_section from er_saved_state</h3>';

// er_section 컬럼 재확인
if (!col_exists($conn, 'office_receipts', 'er_section')) {
    echo "<div class='err'>❌ er_section 컬럼 없음 — Step 1 실패, 중단</div>";
    $conn->close(); exit;
}

$er_tbl = $conn->query("SHOW TABLES LIKE 'er_saved_state'");
if (!$er_tbl || $er_tbl->num_rows === 0) {
    echo "<div class='info'>er_saved_state 테이블 없음 — skip</div>";
} else {
    $er_rows = $conn->query("SELECT state_json, store_id FROM er_saved_state ORDER BY save_date ASC");
    $er_updated = 0;
    if ($er_rows) {
        while ($er_row = $er_rows->fetch_assoc()) {
            $state = json_decode($er_row['state_json'], true);
            $sid   = (int)$er_row['store_id'];
            if (!$state || !isset($state['sections'])) continue;

            foreach ($state['sections'] as $section_name => $rows) {
                foreach ($rows as $row) {
                    $bid = (string)($row['item_id'] ?? '');
                    if (strncmp($bid, 'r_', 2) !== 0) continue;
                    $n = (int)substr($bid, 2);
                    if ($n <= 0) continue;

                    // er_section만 업데이트 (is_er_placed 컬럼 미참조)
                    $u = $conn->prepare(
                        "UPDATE office_receipts SET er_section=?
                         WHERE id=? AND store_id=? AND er_section IS NULL"
                    );
                    if (!$u) continue;
                    $u->bind_param('sii', $section_name, $n, $sid);
                    $u->execute();
                    $er_updated += $u->affected_rows;
                    $u->close();
                }
            }
        }
    }
    echo "<div class='ok'>✅ er_section backfill: {$er_updated}개 업데이트</div>";

    // payment_type: check 섹션 영수증 → 'check'로
    $pu = $conn->query(
        "UPDATE office_receipts SET payment_type='check'
         WHERE er_section IN ('check_sup','other_exp_check') AND payment_type='cash'"
    );
    $pt_updated = $conn->affected_rows;
    echo "<div class='ok'>✅ payment_type='check' backfill: {$pt_updated}개 업데이트</div>";
}

// ── Step 3: cer_saved_state → is_cer_placed backfill ──────────────────────
echo '<h3>Step 3: Backfill is_cer_placed from cer_saved_state</h3>';

$cer_tbl = $conn->query("SHOW TABLES LIKE 'cer_saved_state'");
if (!$cer_tbl || $cer_tbl->num_rows === 0) {
    echo "<div class='info'>cer_saved_state 테이블 없음 — skip</div>";
} else {
    $cer_rows = $conn->query("SELECT state_json, store_id FROM cer_saved_state ORDER BY save_date ASC");
    $cer_updated = 0;
    if ($cer_rows) {
        while ($cer_row = $cer_rows->fetch_assoc()) {
            $state = json_decode($cer_row['state_json'], true);
            $sid   = (int)$cer_row['store_id'];
            if (!$state || !isset($state['sections'])) continue;

            foreach ($state['sections'] as $rows) {
                foreach ($rows as $row) {
                    $bid = (string)($row['item_id'] ?? '');
                    if (strncmp($bid, 'r_', 2) !== 0) continue;
                    $n = (int)substr($bid, 2);
                    if ($n <= 0) continue;

                    $u = $conn->prepare(
                        "UPDATE office_receipts SET is_cer_placed=1 WHERE id=? AND store_id=? AND is_cer_placed=0"
                    );
                    if (!$u) continue;
                    $u->bind_param('ii', $n, $sid);
                    $u->execute();
                    $cer_updated += $u->affected_rows;
                    $u->close();
                }
            }
        }
    }
    echo "<div class='ok'>✅ is_cer_placed backfill: {$cer_updated}개 업데이트</div>";
}

// ── Step 4: cd_saved_state → is_cd_paid backfill ──────────────────────────
echo '<h3>Step 4: Backfill is_cd_paid from cd_saved_state</h3>';

$cd_tbl = $conn->query("SHOW TABLES LIKE 'cd_saved_state'");
if (!$cd_tbl || $cd_tbl->num_rows === 0) {
    echo "<div class='info'>cd_saved_state 테이블 없음 — skip</div>";
} else {
    $cd_rows = $conn->query("SELECT state_json, store_id FROM cd_saved_state ORDER BY save_date ASC");
    $cd_updated = 0;
    if ($cd_rows) {
        while ($cd_row = $cd_rows->fetch_assoc()) {
            $state = json_decode($cd_row['state_json'], true);
            $sid   = (int)$cd_row['store_id'];
            if (!$state || !isset($state['sections'])) continue;

            foreach ($state['sections'] as $rows) {
                foreach ($rows as $row) {
                    $bid = (string)($row['item_id'] ?? '');
                    if (strncmp($bid, 'r_', 2) === 0) {
                        $n = (int)substr($bid, 2);
                        if ($n > 0) {
                            $u = $conn->prepare(
                                "UPDATE office_receipts SET is_cd_paid=1 WHERE id=? AND store_id=? AND is_cd_paid=0"
                            );
                            if ($u) {
                                $u->bind_param('ii', $n, $sid);
                                $u->execute();
                                $cd_updated += $u->affected_rows;
                                $u->close();
                            }
                        }
                    }
                    foreach ($row['batch_ids'] ?? [] as $bbid) {
                        if (strncmp((string)$bbid, 'r_', 2) === 0) {
                            $n = (int)substr($bbid, 2);
                            if ($n > 0) {
                                $u = $conn->prepare(
                                    "UPDATE office_receipts SET is_cd_paid=1 WHERE id=? AND store_id=? AND is_cd_paid=0"
                                );
                                if ($u) {
                                    $u->bind_param('ii', $n, $sid);
                                    $u->execute();
                                    $cd_updated += $u->affected_rows;
                                    $u->close();
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    echo "<div class='ok'>✅ is_cd_paid backfill: {$cd_updated}개 업데이트</div>";
}

// ── Step 5: 결과 검증 ─────────────────────────────────────────────────────
echo '<h3>Step 5: 결과 확인</h3>';

$v1 = $conn->query("SELECT COUNT(*) AS cnt FROM office_receipts WHERE er_section IS NOT NULL");
$v2 = $conn->query("SELECT COUNT(*) AS cnt FROM office_receipts WHERE is_cer_placed=1");
$v3 = $conn->query("SELECT COUNT(*) AS cnt FROM office_receipts WHERE is_cd_paid=1");
$v4 = $conn->query("SELECT COUNT(*) AS cnt FROM office_receipts WHERE payment_type='check'");

echo "<div class='info'>er_section 있는 영수증: " . ($v1 ? $v1->fetch_assoc()['cnt'] : '?') . "</div>";
echo "<div class='info'>is_cer_placed=1: " . ($v2 ? $v2->fetch_assoc()['cnt'] : '?') . "</div>";
echo "<div class='info'>is_cd_paid=1: " . ($v3 ? $v3->fetch_assoc()['cnt'] : '?') . "</div>";
echo "<div class='info'>payment_type='check': " . ($v4 ? $v4->fetch_assoc()['cnt'] : '?') . "</div>";

$conn->close();
echo '<h3 style="color:#166534">완료</h3>';
echo '<p style="color:#6b7280;font-size:13px">완료 후 이 파일을 삭제하세요: <code>run_receipt_status_migration.php</code></p>';
