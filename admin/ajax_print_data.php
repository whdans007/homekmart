<?php
try {
    require_once __DIR__ . '/../lib/lang_helper.php';
    require_once __DIR__ . '/../config/db_config.php';
    require_once __DIR__ . '/../lib/permission_helper.php';

    // 세션 시작 및 로그인 확인
    session_start();

    // AJAX 요청 확인
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

    // 현재 사용자 정보 가져오기
    $current_store_id = $_SESSION['store_id'] ?? null;

    // super_admin이 아닌 경우 store_id 가져오기
    if ($_SESSION['role'] !== 'super_admin' && !$current_store_id) {
        try {
            $conn = get_db_connection();
            $stmt = $conn->prepare("SELECT store_id FROM users WHERE id = ?");
            $stmt->bind_param("i", $_SESSION['user_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $current_store_id = $row['store_id'];
            }
            $stmt->close();
            $conn->close();
        } catch (Exception $e) {
            // 실패해도 계속 진행
        }
    }
} catch (Exception $e) {
    http_response_code(500);
    exit('Server Error: ' . $e->getMessage());
}

// 날짜 파라미터 검증
$selected_date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) {
    http_response_code(400);
    exit('Invalid date format');
}

$pdo = null;
$price_changes = [];
$error_message = '';

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 가격변경 이력 테이블이 존재하는지 확인
    $table_check = $pdo->prepare("SHOW TABLES LIKE 'price_change_history'");
    $table_check->execute();
    
    if (!$table_check->fetch()) {
        $error_message = t('price_change.table_not_exists');
    } else {
        // 검색 조건 구성 (선택된 날짜만)
        $where_conditions = [];
        $params = [];
        
        // 선택된 날짜의 데이터만 조회
        $where_conditions[] = "DATE(pch.changed_at) = ?";
        $params[] = $selected_date;

        // 점포별 필터링 (super_admin이 아닌 경우)
        if ($_SESSION['role'] !== 'super_admin' && !empty($current_store_id)) {
            $where_conditions[] = "(pch.store_id = ? OR pch.store_id IS NULL)";
            $params[] = $current_store_id;
        }

        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

        // 가격변경 이력 조회
        $sql = "
            SELECT 
                pch.*,
                p.name_ko as product_name_ko,
                p.name_en as product_name_en,
                p.sku,
                u.username as changed_by,
                s.name as store_name
            FROM price_change_history pch
            LEFT JOIN products p ON pch.product_id = p.id
            LEFT JOIN users u ON pch.changed_by_user_id = u.id
            LEFT JOIN stores s ON pch.store_id = s.id
            $where_clause
            ORDER BY pch.changed_at DESC
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $price_changes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    $error_message = t('price_change.load_error') . ": " . $e->getMessage();
}

// HTML 출력
?>

<?php if ($error_message): ?>
    <div style="text-align: center; color: red; padding: 40px;">
        <i class="fas fa-exclamation-triangle"></i>
        <p><?php echo htmlspecialchars($error_message); ?></p>
    </div>
<?php elseif (empty($price_changes)): ?>
    <div style="text-align: center; padding: 40px; color: #666;">
        <i class="fas fa-calendar-times" style="font-size: 48px; margin-bottom: 15px;"></i>
        <h3>No price change history for this date</h3>
        <p>No price change history found for <?php echo $selected_date; ?></p>
    </div>
<?php else: ?>
    <!-- 요약 정보 -->
    <div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px;" class="no-print">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <strong>Total Changes:</strong> <?php echo number_format(count($price_changes)); ?> items
            </div>
            <div>
                <strong>Selected Date:</strong> <?php echo $selected_date; ?>
            </div>
        </div>
    </div>
    
    <!-- 데이터 테이블 -->
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; margin-top: 10px;">
            <thead>
                <tr style="background-color: #f8f9fa;">
                    <th style="border: 1px solid #ddd; padding: 12px; text-align: left; font-weight: bold; width: 5%;">
                        No
                    </th>
                    <th style="border: 1px solid #ddd; padding: 12px; text-align: left; font-weight: bold; width: 15%;">
                        SKU
                    </th>
                    <th style="border: 1px solid #ddd; padding: 12px; text-align: left; font-weight: bold; width: 35%;">
                        Product Name
                    </th>
                    <th style="border: 1px solid #ddd; padding: 12px; text-align: right; font-weight: bold; width: 11.25%;">
                        Old Cost
                    </th>
                    <th style="border: 1px solid #ddd; padding: 12px; text-align: right; font-weight: bold; width: 11.25%;">
                        New Cost
                    </th>
                    <th style="border: 1px solid #ddd; padding: 12px; text-align: right; font-weight: bold; width: 11.25%;">
                        Old Selling
                    </th>
                    <th style="border: 1px solid #ddd; padding: 12px; text-align: right; font-weight: bold; width: 11.25%;">
                        New Selling
                    </th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($price_changes as $index => $change): ?>
                    <tr style="<?php echo $index % 2 == 0 ? 'background-color: #fff;' : 'background-color: #f9f9f9;'; ?>">
                        <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">
                            <?php echo $index + 1; ?>
                        </td>
                        <td style="border: 1px solid #ddd; padding: 8px; font-family: monospace;">
                            <?php echo htmlspecialchars($change['sku'] ?? 'N/A'); ?>
                        </td>
                        <td style="border: 1px solid #ddd; padding: 8px;">
                            <?php if (!empty($change['product_name_en'])): ?>
                                <div style="font-weight: 500; margin-bottom: 2px;">
                                    <?php echo htmlspecialchars($change['product_name_en']); ?>
                                </div>
                                <div style="font-size: 12px; color: #666;">
                                    <?php echo htmlspecialchars($change['product_name_ko'] ?? 'N/A'); ?>
                                </div>
                            <?php else: ?>
                                <div style="font-weight: 500;">
                                    <?php echo htmlspecialchars($change['product_name_ko'] ?? 'N/A'); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">
                            <?php if ($change['old_cost_price']): ?>
                                <?php echo number_format($change['old_cost_price']); ?>
                            <?php else: ?>
                                <span style="color: #999;">-</span>
                            <?php endif; ?>
                        </td>
                        <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">
                            <?php if ($change['new_cost_price']): ?>
                                <strong style="color: #28a745;"><?php echo number_format($change['new_cost_price']); ?></strong>
                            <?php else: ?>
                                <span style="color: #999;">-</span>
                            <?php endif; ?>
                        </td>
                        <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">
                            <?php if ($change['old_selling_price']): ?>
                                <?php echo number_format($change['old_selling_price']); ?>
                            <?php else: ?>
                                <span style="color: #999;">-</span>
                            <?php endif; ?>
                        </td>
                        <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">
                            <?php if ($change['new_selling_price']): ?>
                                <strong style="color: #007bff;"><?php echo number_format($change['new_selling_price']); ?></strong>
                            <?php else: ?>
                                <span style="color: #999;">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- 인쇄 시 표시될 요약 -->
    <div style="margin-top: 30px; font-size: 14px; border-top: 1px solid #ddd; padding-top: 15px;">
        <div style="display: flex; justify-content: space-between;">
            <div>
                <strong>Total Changes:</strong> <?php echo number_format(count($price_changes)); ?> items
            </div>
            <div>
                <strong>Print Time:</strong> <?php echo date('Y-m-d H:i:s'); ?>
            </div>
        </div>
    </div>
<?php endif; ?>