<?php
/**
 * 몰(mall_products)에 큐레이션된 적이 있는 상품들의 category_id를 전부 NULL로 초기화한다.
 * 접속: http://서버주소/sunset/sql/run_clear_category_for_mall_products.php
 *
 * 범위: mall_products에 존재하는 product_id만 대상으로 한다(점포/노출여부 무관 — 전부).
 *       구매/재고 전용이고 몰에 한 번도 큐레이션된 적 없는 상품은 건드리지 않는다.
 * categories 테이블 자체(카테고리 행)는 삭제하지 않는다 — 상품과의 연결만 끊는다.
 * 이후 mall/admin/products.php에서 기존 카테고리들을 순서대로 삭제할 수 있다.
 *
 * 주의: 실행 후 이 파일을 삭제하세요.
 */
require_once __DIR__ . '/../config/db_config.php';

mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die('DB 연결 실패: ' . $conn->connect_error);
}
$conn->set_charset(DB_CHARSET);

$steps = [];

// 대상 미리보기(확인용) — 실제로 바뀔 상품 목록
$preview_result = $conn->query(
    "SELECT p.id, p.name_ko, p.sku, p.category_id
     FROM products p
     INNER JOIN (SELECT DISTINCT product_id FROM mall_products) mp ON mp.product_id = p.id
     WHERE p.category_id IS NOT NULL
     ORDER BY p.name_ko"
);
$preview = $preview_result ? $preview_result->fetch_all(MYSQLI_ASSOC) : [];
$total = count($preview);

if ($total === 0) {
    $steps[] = ['SKIP', '몰에 큐레이션된 상품 중 category_id가 지정된 상품이 없습니다(이미 전부 미지정 상태).'];
} else {
    if ($conn->query(
        "UPDATE products p
         INNER JOIN (SELECT DISTINCT product_id FROM mall_products) mp ON mp.product_id = p.id
         SET p.category_id = NULL
         WHERE p.category_id IS NOT NULL"
    )) {
        $steps[] = ['OK', "{$conn->affected_rows}개 상품의 category_id를 NULL로 초기화했습니다."];
    } else {
        $steps[] = ['ERROR', '업데이트 실패: ' . $conn->error];
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>몰 큐레이션 상품 카테고리 초기화</title>
    <style>
        body{font-family:-apple-system,sans-serif;background:#f9fafb;margin:0;padding:2rem}
        .card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.5rem;max-width:720px;margin:0 auto 1rem}
        h1{font-size:1.2rem;font-weight:700;margin:0 0 .25rem;color:#111}
        .sub{color:#6b7280;font-size:.875rem}
        table{width:100%;border-collapse:collapse;font-size:.875rem}
        td,th{padding:.5rem .75rem;border-bottom:1px solid #f3f4f6;text-align:left}
        .ok{color:#059669}.skip{color:#9ca3af}.error{color:#dc2626}
        .success-box{background:#ecfdf5;border:1px solid #6ee7b7;border-radius:6px;padding:1rem;font-size:.875rem;color:#065f46;margin-bottom:1rem}
        .err-box{background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:1rem;font-size:.875rem;color:#991b1b;margin-bottom:1rem}
        details{margin-top:.75rem;font-size:.8rem;color:#4b5563}
    </style>
</head>
<body>
<div class="card">
    <h1>🧹 몰 큐레이션 상품 카테고리 초기화</h1>
    <p class="sub">mall_products에 등록된 적 있는 상품 <?php echo $total; ?>개의 category_id를 NULL로 초기화 · DB: <strong><?php echo DB_NAME; ?></strong></p>
</div>

<div class="card">
    <?php $has_error = false; foreach ($steps as [$s]) { if ($s === 'ERROR') $has_error = true; } ?>
    <?php if ($has_error): ?>
    <div class="err-box">⚠️ 일부 단계 실패. 아래 오류를 확인하세요.</div>
    <?php else: ?>
    <div class="success-box">✅ 완료! 이제 mall/admin/products.php에서 기존 카테고리들을 삭제해보세요.</div>
    <p class="sub">⚠️ 보안을 위해 이 파일을 삭제하세요.</p>
    <?php endif; ?>
    <table>
        <thead><tr><th>단계</th><th>결과</th></tr></thead>
        <tbody>
        <?php foreach ($steps as [$status, $msg]): ?>
        <tr><td colspan="2" class="<?php echo strtolower($status); ?>">[<?php echo $status; ?>] <?php echo htmlspecialchars($msg); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($total > 0): ?>
    <details>
        <summary>초기화 대상 상품 목록 (<?php echo $total; ?>개)</summary>
        <ul>
            <?php foreach ($preview as $p): ?>
            <li><?php echo htmlspecialchars($p['name_ko']); ?> (<?php echo htmlspecialchars($p['sku']); ?>) — 기존 category_id: <?php echo (int)$p['category_id']; ?></li>
            <?php endforeach; ?>
        </ul>
    </details>
    <?php endif; ?>
</div>
</body>
</html>
