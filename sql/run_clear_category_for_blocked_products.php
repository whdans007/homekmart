<?php
/**
 * 카테고리 삭제를 가로막는 특정 상품들의 category_id를 비운다(NULL = 미지정).
 * 접속: http://서버주소/sunset/sql/run_clear_category_for_blocked_products.php
 *
 * 대상(SKU 기준, 필요하면 아래 $target_skus 배열만 고쳐서 재사용 가능):
 * - 8801043021128 (농심 김치 사발면 6입)
 * - 8801043015653 (농심 육개장 사발면 86g)
 *
 * 몰 큐레이션 화면(mall/admin/products.php)에서 "삭제"해도 mall_products 행만 지워질 뿐
 * products.category_id는 그대로 남기 때문에, 카테고리 삭제 검증(활성 상품 존재 여부)에 걸린다.
 * 이 스크립트는 해당 상품의 카테고리만 미지정으로 초기화해서 카테고리 삭제를 가능하게 한다.
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

$target_skus = ['8801043021128', '8801043015653'];

$steps = [];

foreach ($target_skus as $sku) {
    $stmt = $conn->prepare('SELECT id, name_ko, category_id FROM products WHERE sku = ?');
    $stmt->bind_param('s', $sku);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $steps[] = ['SKIP', "SKU {$sku}: 상품을 찾을 수 없습니다."];
        continue;
    }
    if ($row['category_id'] === null) {
        $steps[] = ['SKIP', "SKU {$sku} ({$row['name_ko']}): 이미 카테고리가 미지정 상태입니다."];
        continue;
    }

    $update = $conn->prepare('UPDATE products SET category_id = NULL WHERE id = ?');
    $update->bind_param('i', $row['id']);
    if ($update->execute()) {
        $steps[] = ['OK', "SKU {$sku} ({$row['name_ko']}): category_id {$row['category_id']} -> NULL로 초기화 완료"];
    } else {
        $steps[] = ['ERROR', "SKU {$sku} ({$row['name_ko']}): 업데이트 실패 - " . $conn->error];
    }
    $update->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>카테고리 미지정 초기화</title>
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
    </style>
</head>
<body>
<div class="card">
    <h1>🧹 카테고리 미지정 초기화</h1>
    <p class="sub">지정한 SKU 상품들의 category_id를 NULL로 초기화 · DB: <strong><?php echo DB_NAME; ?></strong></p>
</div>

<div class="card">
    <?php $has_error = false; foreach ($steps as [$s]) { if ($s === 'ERROR') $has_error = true; } ?>
    <?php if ($has_error): ?>
    <div class="err-box">⚠️ 일부 단계 실패. 아래 오류를 확인하세요.</div>
    <?php else: ?>
    <div class="success-box">✅ 완료! 이제 mall/admin/products.php에서 해당 카테고리 삭제를 다시 시도해보세요.</div>
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
</div>
</body>
</html>
