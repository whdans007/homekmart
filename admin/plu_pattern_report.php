<?php
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/partials/header.php';

// 권한 확인
if (!has_permission('product_management')) {
    echo "권한 없음";
    exit;
}

// 000000066 처럼 앞쪽이 0으로 채워지고 뒤쪽 유효 숫자가 1~3자리인 SKU 패턴
$pattern = '^0+[1-9][0-9]{0,2}$';

$delete_results = null;

// 삭제 처리 (POST + 확인 문구 입력 시에만 실행)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirm_text'] ?? '') === '삭제') {
    $conn = get_db_connection();

    // 클라이언트가 보낸 id는 신뢰하지 않고, 현재 DB에서 패턴에 매칭되는 id로만 다시 검증
    $valid_ids = [];
    $stmt = $conn->prepare("SELECT id FROM products WHERE sku REGEXP ?");
    $stmt->bind_param('s', $pattern);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $valid_ids[$row['id']] = true;
    }
    $stmt->close();

    $requested_ids = array_map('intval', $_POST['ids'] ?? []);
    $delete_results = ['success' => [], 'failed' => []];

    foreach ($requested_ids as $id) {
        if (!isset($valid_ids[$id])) {
            continue; // 패턴에 더 이상 매칭되지 않는 id는 무시
        }

        $info_stmt = $conn->prepare("SELECT sku, name_ko FROM products WHERE id = ?");
        $info_stmt->bind_param('i', $id);
        $info_stmt->execute();
        $info = $info_stmt->get_result()->fetch_assoc();
        $info_stmt->close();

        $del_stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
        $del_stmt->bind_param('i', $id);

        if ($del_stmt->execute()) {
            $delete_results['success'][] = ['id' => $id, 'sku' => $info['sku'] ?? '', 'name_ko' => $info['name_ko'] ?? ''];
        } else {
            $reason = $conn->errno === 1451
                ? '다른 자료(구매내역 등)에서 참조 중이라 삭제 불가'
                : $conn->error;
            $delete_results['failed'][] = ['id' => $id, 'sku' => $info['sku'] ?? '', 'name_ko' => $info['name_ko'] ?? '', 'reason' => $reason];
        }
        $del_stmt->close();
    }

    $conn->close();
}

try {
    $conn = get_db_connection();

    $stmt = $conn->prepare("
        SELECT p.id, p.sku, p.name_ko, p.name_en, p.is_active,
               c.name AS category_name, b.name_ko AS brand_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id
        WHERE p.sku REGEXP ?
        ORDER BY LENGTH(p.sku), p.sku
    ");
    $stmt->bind_param('s', $pattern);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);

    $stmt->close();
    $conn->close();

    echo "<h2>PLU(SKU) 패턴 점검 - 앞쪽 0 + 뒤쪽 유효숫자 1~3자리</h2>";
    echo "<p>예: 000000066</p>";

    if ($delete_results !== null) {
        $s = count($delete_results['success']);
        $f = count($delete_results['failed']);
        echo "<div style='padding: 12px; margin-bottom: 16px; border: 1px solid #ccc; background: #f8f9fa;'>";
        echo "<p><strong>삭제 완료:</strong> " . $s . "건</p>";
        if ($f > 0) {
            echo "<p style='color:#b91c1c;'><strong>삭제 실패:</strong> " . $f . "건</p>";
            echo "<ul>";
            foreach ($delete_results['failed'] as $item) {
                echo "<li>[" . htmlspecialchars($item['id']) . "] " . htmlspecialchars($item['sku']) . " - " . htmlspecialchars($item['name_ko']) . " : " . htmlspecialchars($item['reason']) . "</li>";
            }
            echo "</ul>";
        }
        echo "</div>";
    }

    echo "<p><strong>현재 남은 대상: " . count($rows) . "건</strong></p>";

    if (count($rows) > 0) {
        echo "<form method='post' onsubmit=\"return confirm('선택한 상품을 영구 삭제합니다. 계속할까요?');\">";
        echo "<div style='margin-bottom: 10px;'>";
        echo "<button type='button' onclick=\"document.querySelectorAll('.plu-chk').forEach(c=>c.checked=true)\">전체 선택</button> ";
        echo "<button type='button' onclick=\"document.querySelectorAll('.plu-chk').forEach(c=>c.checked=false)\">전체 해제</button>";
        echo "</div>";

        echo "<table border='1' style='border-collapse: collapse; width: 100%; margin-bottom: 20px;'>";
        echo "<tr>
                <th style='padding: 8px;'>선택</th>
                <th style='padding: 8px;'>ID</th>
                <th style='padding: 8px;'>SKU</th>
                <th style='padding: 8px;'>상품명(한글)</th>
                <th style='padding: 8px;'>상품명(영문)</th>
                <th style='padding: 8px;'>브랜드</th>
                <th style='padding: 8px;'>카테고리</th>
                <th style='padding: 8px;'>사용 여부</th>
              </tr>";
        foreach ($rows as $row) {
            echo "<tr>";
            echo "<td style='padding: 8px; border: 1px solid #ddd; text-align:center;'><input type='checkbox' class='plu-chk' name='ids[]' value='" . htmlspecialchars($row['id']) . "' checked></td>";
            echo "<td style='padding: 8px; border: 1px solid #ddd;'>" . htmlspecialchars($row['id']) . "</td>";
            echo "<td style='padding: 8px; border: 1px solid #ddd; font-family: monospace;'>" . htmlspecialchars($row['sku'] ?? '') . "</td>";
            echo "<td style='padding: 8px; border: 1px solid #ddd;'>" . htmlspecialchars($row['name_ko'] ?? '') . "</td>";
            echo "<td style='padding: 8px; border: 1px solid #ddd;'>" . htmlspecialchars($row['name_en'] ?? '') . "</td>";
            echo "<td style='padding: 8px; border: 1px solid #ddd;'>" . htmlspecialchars($row['brand_name'] ?? '') . "</td>";
            echo "<td style='padding: 8px; border: 1px solid #ddd;'>" . htmlspecialchars($row['category_name'] ?? '') . "</td>";
            echo "<td style='padding: 8px; border: 1px solid #ddd;'>" . ($row['is_active'] ? '사용' : '미사용') . "</td>";
            echo "</tr>";
        }
        echo "</table>";

        echo "<div style='padding: 12px; border: 1px solid #f5c2c7; background: #f8d7da; margin-bottom: 12px;'>";
        echo "<label>선택한 상품을 <strong>영구 삭제</strong>하려면 아래 칸에 '삭제'라고 입력하세요: ";
        echo "<input type='text' name='confirm_text' required></label>";
        echo "</div>";

        echo "<button type='submit' style='padding: 10px 20px; background:#dc2626; color:#fff; border:none; border-radius:4px; cursor:pointer;'>선택 항목 영구 삭제</button>";
        echo "</form>";
    } else {
        echo "<p>해당 패턴의 SKU가 없습니다.</p>";
    }

} catch (Exception $e) {
    echo "<p style='color: red;'>오류: " . htmlspecialchars($e->getMessage()) . "</p>";
}

require_once __DIR__ . '/partials/footer.php';
