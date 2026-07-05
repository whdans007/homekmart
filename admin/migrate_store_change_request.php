<?php
/**
 * 마이그레이션 실행 스크립트: store_change_requests 테이블 생성.
 * super_admin 로그인 상태에서 브라우저로 1회 실행하세요.
 *   예) https://.../admin/migrate_store_change_request.php
 */
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../config/db_config.php';

ensure_logged_in();
if (($_SESSION['role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    exit('super_admin 전용 스크립트입니다.');
}

header('Content-Type: text/plain; charset=utf-8');

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $sql = file_get_contents(__DIR__ . '/../sql/store_change_request.sql');
    if ($sql === false) {
        throw new Exception('sql/store_change_request.sql 파일을 읽을 수 없습니다.');
    }

    $pdo->exec($sql);

    echo "OK: store_change_requests 테이블이 준비되었습니다.\n\n";
    echo "컬럼 구조:\n";
    foreach ($pdo->query("SHOW COLUMNS FROM store_change_requests") as $c) {
        echo sprintf(" - %-16s %s\n", $c['Field'], $c['Type']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
