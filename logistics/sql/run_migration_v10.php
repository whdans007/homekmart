<?php
/**
 * Migration v10 — 상품 등록/변경 이력 테이블
 * 접속: http://서버주소/sunset/logistics/sql/run_migration_v10.php
 * 실행 후 이 파일을 삭제하세요.
 */
require_once __DIR__ . '/../../config/db_config.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset('utf8mb4');

$sql = "CREATE TABLE IF NOT EXISTS lc_product_history (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    product_id  INT NOT NULL,
    user_id     INT,
    user_name   VARCHAR(100),
    action      ENUM('create','update') NOT NULL,
    field_name  VARCHAR(60),
    field_label VARCHAR(60),
    old_value   TEXT,
    new_value   TEXT,
    changed_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_product_time (product_id, changed_at),
    FOREIGN KEY (product_id) REFERENCES lc_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$ok = $conn->query($sql);
$conn->close();
?>
<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8"><title>Migration v10</title>
<style>body{font-family:-apple-system,sans-serif;padding:2rem;background:#f9fafb;}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.5rem;max-width:500px;margin:0 auto}
.ok{color:#059669}.err{color:#dc2626} a{color:#0d9488}</style>
</head><body>
<div class="card">
<h2 style="margin:0 0 1rem">Migration v10 — 상품 이력 테이블</h2>
<?php if ($ok): ?>
<p class="ok">✅ lc_product_history 테이블 생성 완료</p>
<p><a href="/sunset/logistics/products.php">상품 목록으로 →</a></p>
<p style="color:#9ca3af;font-size:.8rem;margin-top:1rem">⚠️ 보안상 이 파일을 삭제하세요.</p>
<?php else: ?>
<p class="err">❌ 실패: <?php echo htmlspecialchars($conn->error ?? 'Unknown error'); ?></p>
<?php endif; ?>
</div></body></html>
