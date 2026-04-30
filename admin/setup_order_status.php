<?php
require_once __DIR__ . '/../lib/lang_helper.php';
require_once __DIR__ . '/../config/db_config.php';

// 관리자만 접근 가능
if (!has_permission('admin_access')) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => t('messages.permission_denied')
    ];
    header('Location: shop.php');
    exit;
}

$page_title = '주문상태 마이그레이션 - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
?>

<div class="w-full px-4 py-8">
    <div class="max-w-2xl mx-auto">
        <div class="bg-white shadow-lg rounded-lg p-8">
            <h1 class="text-2xl font-bold mb-6 text-gray-900">
                <i class="fas fa-database text-blue-600 mr-3"></i>
                주문상태 필드 마이그레이션
            </h1>

            <div class="space-y-6">
                <?php
                try {
                    $conn = get_db_connection();
                    if (!$conn) {
                        throw new Exception("데이터베이스 연결 실패");
                    }

                    // 현재 스키마 확인
                    $check_remarks = $conn->query("SHOW COLUMNS FROM store_order_list_items LIKE 'remarks'");
                    $remarks_exists = $check_remarks->num_rows > 0;

                    $check_order_status = $conn->query("SHOW COLUMNS FROM store_order_list_items LIKE 'order_status'");
                    $order_status_exists = $check_order_status->num_rows > 0;

                    echo '<div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">';
                    echo '<h3 class="font-semibold text-blue-900 mb-3">현재 상태:</h3>';
                    echo '<ul class="space-y-2 text-sm text-blue-800">';
                    echo '<li>' . ($remarks_exists ? '✗ remarks 컬럼 존재' : '✓ remarks 컬럼 없음') . '</li>';
                    echo '<li>' . ($order_status_exists ? '✓ order_status 컬럼 존재' : '✗ order_status 컬럼 없음') . '</li>';
                    echo '</ul>';
                    echo '</div>';

                    // 마이그레이션 필요 여부 확인
                    $migration_needed = $remarks_exists || !$order_status_exists;

                    if ($migration_needed) {
                        echo '<form method="POST" class="mb-6">';
                        echo '<button type="submit" name="execute_migration" value="1" class="w-full px-6 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 font-semibold transition-colors">';
                        echo '<i class="fas fa-play mr-2"></i>마이그레이션 실행';
                        echo '</button>';
                        echo '</form>';

                        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute_migration'])) {
                            echo '<div class="bg-gray-50 rounded-lg p-4 mb-6">';
                            echo '<h3 class="font-semibold text-gray-900 mb-3">실행 로그:</h3>';
                            echo '<pre class="text-sm text-gray-700 overflow-auto">';

                            // 1. order_status 컬럼 추가
                            if (!$order_status_exists) {
                                echo "[1/3] order_status 컬럼 추가 중...\n";
                                $result = $conn->query("ALTER TABLE store_order_list_items ADD COLUMN order_status ENUM('주문', '비주문') DEFAULT '주문' AFTER quantity");
                                if ($result) {
                                    echo "✓ order_status 컬럼이 추가되었습니다\n\n";
                                } else {
                                    throw new Exception("order_status 컬럼 추가 실패: " . $conn->error);
                                }
                            } else {
                                echo "[1/3] order_status 컬럼이 이미 존재합니다\n\n";
                            }

                            // 2. remarks 컬럼 삭제
                            if ($remarks_exists) {
                                echo "[2/3] remarks 컬럼 삭제 중...\n";
                                $result = $conn->query("ALTER TABLE store_order_list_items DROP COLUMN remarks");
                                if ($result) {
                                    echo "✓ remarks 컬럼이 삭제되었습니다\n\n";
                                } else {
                                    throw new Exception("remarks 컬럼 삭제 실패: " . $conn->error);
                                }
                            } else {
                                echo "[2/3] remarks 컬럼이 이미 없습니다\n\n";
                            }

                            // 3. 인덱스 추가
                            echo "[3/3] 인덱스 추가 중...\n";
                            $index_check = $conn->query("SHOW INDEX FROM store_order_list_items WHERE Column_name='order_status'");
                            if ($index_check->num_rows === 0) {
                                $result = $conn->query("ALTER TABLE store_order_list_items ADD INDEX idx_order_status (order_status)");
                                if ($result) {
                                    echo "✓ order_status 인덱스가 추가되었습니다\n\n";
                                } else {
                                    echo "⚠ 인덱스 추가 실패 (무시 가능): " . $conn->error . "\n\n";
                                }
                            } else {
                                echo "✓ order_status 인덱스가 이미 존재합니다\n\n";
                            }

                            echo "마이그레이션 완료!\n";
                            echo '</pre>';
                            echo '</div>';

                            // 최종 상태 확인
                            echo '<div class="bg-green-50 border border-green-200 rounded-lg p-4">';
                            echo '<h3 class="font-semibold text-green-900 mb-3">최종 상태:</h3>';

                            $final_remarks = $conn->query("SHOW COLUMNS FROM store_order_list_items LIKE 'remarks'");
                            $final_order_status = $conn->query("SHOW COLUMNS FROM store_order_list_items LIKE 'order_status'");

                            echo '<ul class="space-y-2 text-sm text-green-800">';
                            echo '<li>' . ($final_remarks->num_rows === 0 ? '✓ remarks 컬럼 제거됨' : '✗ remarks 컬럼 여전히 존재') . '</li>';
                            echo '<li>' . ($final_order_status->num_rows > 0 ? '✓ order_status 컬럼 생성됨' : '✗ order_status 컬럼 없음') . '</li>';
                            echo '</ul>';

                            echo '<p class="mt-4 text-sm text-green-700">';
                            echo '<a href="store_order_list_edit.php" class="text-green-600 hover:text-green-800 font-semibold">';
                            echo '→ 주문 리스트 편집 페이지로 이동';
                            echo '</a>';
                            echo '</p>';
                            echo '</div>';
                        }
                    } else {
                        echo '<div class="bg-green-50 border border-green-200 rounded-lg p-4">';
                        echo '<h3 class="font-semibold text-green-900 mb-3">✓ 마이그레이션 완료</h3>';
                        echo '<p class="text-sm text-green-700 mb-4">모든 필드가 올바르게 설정되어 있습니다.</p>';
                        echo '<p class="text-sm text-green-700">';
                        echo '<a href="store_order_list_edit.php" class="text-green-600 hover:text-green-800 font-semibold">';
                        echo '→ 주문 리스트 편집 페이지로 이동';
                        echo '</a>';
                        echo '</p>';
                        echo '</div>';
                    }

                    $conn->close();

                } catch (Exception $e) {
                    echo '<div class="bg-red-50 border border-red-200 rounded-lg p-4">';
                    echo '<h3 class="font-semibold text-red-900 mb-2">오류 발생</h3>';
                    echo '<p class="text-sm text-red-700">' . htmlspecialchars($e->getMessage()) . '</p>';
                    echo '</div>';
                    error_log("Migration error: " . $e->getMessage());
                }
                ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
