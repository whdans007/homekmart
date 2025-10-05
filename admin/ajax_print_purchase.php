<?php
try {
    require_once __DIR__ . '/../lib/lang_helper.php';
    require_once __DIR__ . '/../config/db_config.php';
    require_once __DIR__ . '/../lib/permission_helper.php';

    // 세션 시작 및 로그인 확인
    session_start();

    // AJAX 요청 확인 (GET만 허용)
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        exit('Method not allowed');
    }

    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        exit('Unauthorized');
    }

    // 매입관리 권한 확인
    if (!has_permission('purchase_management')) {
        http_response_code(403);
        exit('Forbidden');
    }

    // purchase_id 파라미터 확인
    $purchase_id = $_GET['purchase_id'] ?? null;
    if (!$purchase_id || !is_numeric($purchase_id)) {
        http_response_code(400);
        exit('Invalid purchase_id');
    }

} catch (Exception $e) {
    http_response_code(500);
    exit('Server Error: ' . $e->getMessage());
}

$conn = null;
$purchase = null;
$purchase_items = [];
$error_message = '';

try {
    $conn = get_db_connection();

    // 현재 사용자의 점포 정보 가져오기 (헤더에서)
    $store_name = $_SESSION['store_name'] ?? 'N/A';

    // 매입 기본 정보 조회
    $stmt = $conn->prepare("
        SELECT p.*, s.name as supplier_name
        FROM purchases p
        JOIN suppliers s ON p.supplier_id = s.id
        WHERE p.purchase_id = ? AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
    ");
    $stmt->bind_param("i", $purchase_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $purchase = $result->fetch_assoc();
    $stmt->close();

    if (!$purchase) {
        throw new Exception('매입 내역을 찾을 수 없습니다.');
    }

    // 점포명 추가 (세션에서)
    $purchase['store_name'] = $store_name;

    // 매입 상품 목록 조회
    $items_stmt = $conn->prepare("
        SELECT
            pi.*,
            p.name_ko,
            p.name_en,
            p.sku,
            p.pieces_per_box,
            c.name as category_name,
            b.name_ko as brand_name
        FROM purchase_items pi
        JOIN products p ON pi.product_id = p.id
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id
        WHERE pi.purchase_id = ?
        ORDER BY pi.item_id
    ");
    $items_stmt->bind_param("i", $purchase_id);
    $items_stmt->execute();
    $items_result = $items_stmt->get_result();

    while ($row = $items_result->fetch_assoc()) {
        $purchase_items[] = $row;
    }
    $items_stmt->close();

} catch (Exception $e) {
    $error_message = $e->getMessage();
}

// HTML 출력
?>

<?php if ($error_message): ?>
    <div style="text-align: center; color: red; padding: 40px;">
        <i class="fas fa-exclamation-triangle"></i>
        <p><?php echo htmlspecialchars($error_message); ?></p>
    </div>
<?php elseif (!$purchase || empty($purchase_items)): ?>
    <div style="text-align: center; padding: 40px; color: #666;">
        <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 15px;"></i>
        <h3>매입 내역이 없습니다</h3>
        <p>매입 ID: <?php echo htmlspecialchars($purchase_id); ?></p>
    </div>
<?php else: ?>
    <!-- 인쇄 헤더 -->
    <div style="text-align: center; margin-bottom: 8px; padding-bottom: 8px; border-bottom: 2px solid #333;">
        <h1 style="margin: 0 0 3px 0; font-size: 22px; font-weight: bold;">매입 상세 내역</h1>
        <p style="margin: 0; color: #666; font-size: 13px;">Purchase Details</p>
    </div>

    <!-- 매입 기본 정보 -->
    <div style="margin-bottom: 8px; padding: 5px; background: #f8f9fa; border-radius: 4px; text-align: center;">
        <strong>거래처:</strong> <?php echo htmlspecialchars($purchase['supplier_name']); ?> &nbsp;&nbsp;|&nbsp;&nbsp;
        <strong>매입일자:</strong> <?php echo htmlspecialchars($purchase['purchase_date']); ?> &nbsp;&nbsp;|&nbsp;&nbsp;
        <strong>총 매입금액:</strong> <span style="font-size: 16px; font-weight: bold; color: #28a745;"><?php echo number_format($purchase['total_amount'], 2); ?></span>
    </div>

    <!-- 매입 상품 목록 -->
    <div style="overflow-x: auto; margin-bottom: 8px;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background-color: #333; color: white;">
                    <th style="border: 1px solid #000; padding: 6px; text-align: center; width: 5%;">No</th>
                    <th style="border: 1px solid #000; padding: 6px; text-align: center; width: 13%;">SKU</th>
                    <th style="border: 1px solid #000; padding: 6px; text-align: left; width: 40%;">상품명</th>
                    <th style="border: 1px solid #000; padding: 6px; text-align: center; width: 10%;">매입타입</th>
                    <th style="border: 1px solid #000; padding: 6px; text-align: center; width: 10%;">수량</th>
                    <th style="border: 1px solid #000; padding: 6px; text-align: right; width: 10%;">단가</th>
                    <th style="border: 1px solid #000; padding: 6px; text-align: right; width: 8%;">할인율</th>
                    <th style="border: 1px solid #000; padding: 6px; text-align: right; width: 12%;">합계</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $row_num = 1;
                $total_pieces = 0;
                foreach ($purchase_items as $item):
                    // 실제 개수 계산
                    $actual_pieces = $item['quantity'];
                    if ($item['purchase_type'] === 'box') {
                        $actual_pieces = $item['quantity'] * ($item['pieces_per_box'] ?? 1);
                    }
                    $total_pieces += $actual_pieces;

                    $item_total = $item['quantity'] * $item['unit_price'];
                    $discounted_total = $item['discounted_total'] ?? $item_total;
                ?>
                    <tr style="<?php echo $row_num % 2 == 0 ? 'background-color: #f9f9f9;' : 'background-color: #fff;'; ?>">
                        <td style="border: 1px solid #ddd; padding: 5px; text-align: center;">
                            <?php echo $row_num++; ?>
                        </td>
                        <td style="border: 1px solid #ddd; padding: 5px; text-align: center; font-family: monospace;">
                            <?php echo htmlspecialchars($item['sku'] ?? 'N/A'); ?>
                        </td>
                        <td style="border: 1px solid #ddd; padding: 5px;">
                            <?php if (!empty($item['name_en'])): ?>
                                <div style="font-weight: 500; margin-bottom: 2px;">
                                    <?php echo htmlspecialchars($item['name_en']); ?>
                                </div>
                                <div style="font-size: 11px; color: #666;">
                                    <?php echo htmlspecialchars($item['name_ko'] ?? ''); ?>
                                </div>
                            <?php else: ?>
                                <div style="font-weight: 500;">
                                    <?php echo htmlspecialchars($item['name_ko'] ?? 'N/A'); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="border: 1px solid #ddd; padding: 5px; text-align: center;">
                            <?php if ($item['purchase_type'] === 'box'): ?>
                                <span style="background: #e3f2fd; color: #1976d2; padding: 2px 6px; border-radius: 3px; font-size: 11px;">박스</span>
                            <?php else: ?>
                                <span style="background: #f3e5f5; color: #7b1fa2; padding: 2px 6px; border-radius: 3px; font-size: 11px;">낱개</span>
                            <?php endif; ?>
                        </td>
                        <td style="border: 1px solid #ddd; padding: 5px; text-align: center;">
                            <?php echo number_format($item['quantity']); ?>
                        </td>
                        <td style="border: 1px solid #ddd; padding: 5px; text-align: right; font-weight: 500;">
                            <?php echo number_format($item['unit_price'], 2); ?>
                        </td>
                        <td style="border: 1px solid #ddd; padding: 5px; text-align: right;">
                            <?php echo number_format($item['discount_rate'] ?? 0, 1); ?>%
                        </td>
                        <td style="border: 1px solid #ddd; padding: 5px; text-align: right; font-weight: bold;">
                            <?php echo number_format($discounted_total, 2); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php
if ($conn) {
    $conn->close();
}
?>
