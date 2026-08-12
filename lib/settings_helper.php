<?php
/*
 * settings 테이블(key-value) 안전 접근 헬퍼
 *
 * 배경: 운영 DB의 settings 테이블에 setting_key UNIQUE 인덱스가 없는 경우
 * "INSERT ... ON DUPLICATE KEY UPDATE" 가 갱신이 아닌 삽입으로 동작해 중복 행이 쌓이고,
 * 조회 시 가장 오래된 행이 읽혀 "저장했는데 새로고침하면 예전 값" 증상이 발생한다.
 * 여기서는 UNIQUE 인덱스 유무와 무관하게 항상 최신 값이 저장/조회되도록 처리한다.
 */

/**
 * settings 테이블이 없으면 생성한다. (admin/ajax_save_margin_presets.php 와 동일한 스키마)
 */
function settings_ensure_table(PDO $pdo): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $st = $pdo->prepare("SHOW TABLES LIKE 'settings'");
    $st->execute();
    if ($st->fetch()) return;

    $pdo->exec(
        "CREATE TABLE settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(255) NOT NULL UNIQUE,
            setting_value TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/**
 * 설정 값 조회. 중복 행이 있어도 항상 가장 최근 행(id 최대)을 반환한다.
 *
 * @return string|null 저장된 값이 없으면 null
 */
function settings_get(PDO $pdo, string $key): ?string {
    settings_ensure_table($pdo);
    $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return ($v === false || $v === '') ? null : (string)$v;
}

/**
 * 설정 값 저장 (upsert).
 * UNIQUE 인덱스에 의존하지 않는다 — 존재하면 같은 키의 모든 행을 갱신하고, 없으면 삽입한다.
 * (중복 행이 이미 있는 환경에서도 어떤 행을 읽든 동일한 최신 값이 되도록 전부 갱신)
 */
function settings_set(PDO $pdo, string $key, string $value): void {
    settings_ensure_table($pdo);

    $st = $pdo->prepare("SELECT id FROM settings WHERE setting_key = ? LIMIT 1");
    $st->execute([$key]);
    $exists = ($st->fetchColumn() !== false);

    if ($exists) {
        $st = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
        $st->execute([$value, $key]);
    } else {
        $st = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
        $st->execute([$key, $value]);
    }
}

/**
 * 진단용: 테이블/인덱스/중복행 상태를 반환한다.
 */
function settings_diagnose(PDO $pdo, string $key): array {
    $out = ['table_exists' => false, 'unique_index' => false, 'row_count' => 0, 'value' => null];

    $st = $pdo->prepare("SHOW TABLES LIKE 'settings'");
    $st->execute();
    $out['table_exists'] = (bool)$st->fetch();
    if (!$out['table_exists']) return $out;

    foreach ($pdo->query("SHOW INDEX FROM settings")->fetchAll(PDO::FETCH_ASSOC) as $idx) {
        if (($idx['Column_name'] ?? '') === 'setting_key' && (int)($idx['Non_unique'] ?? 1) === 0) {
            $out['unique_index'] = true;
        }
    }

    $st = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
    $st->execute([$key]);
    $out['row_count'] = (int)$st->fetchColumn();
    $out['value'] = settings_get($pdo, $key);

    return $out;
}
