<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = '점포세팅 주문관리 - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// 상품 관리 권한 확인
if (!has_permission('product_management') && !has_permission('shop_access')) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => t('messages.permission_denied')
    ];
    header('Location: shop.php');
    exit;
}

// POST 요청 처리 (리스트 삭제)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_list'])) {
    $list_id = (int)($_POST['list_id'] ?? 0);

    if ($list_id > 0) {
        try {
            $conn = get_db_connection();
            $conn->autocommit(false);

            $current_store_id = $_SESSION['store_id'] ?? 0;

            if (empty($current_store_id) && !empty($_SESSION['user_id'])) {
                $user_sql = "SELECT store_id FROM users WHERE id = ?";
                $user_stmt = $conn->prepare($user_sql);
                $user_stmt->bind_param("i", $_SESSION['user_id']);
                $user_stmt->execute();
                $user_result = $user_stmt->get_result();
                if ($user_row = $user_result->fetch_assoc()) {
                    $current_store_id = $user_row['store_id'];
                }
                $user_stmt->close();
            }

            // 리스트 소유권 확인
            $check_sql = "SELECT id FROM store_order_lists WHERE id = ? AND store_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("ii", $list_id, $current_store_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();

            if ($check_result->num_rows === 0) {
                throw new Exception('리스트를 찾을 수 없거나 접근 권한이 없습니다.');
            }

            // 리스트 아이템들 삭제
            $delete_items_sql = "DELETE FROM store_order_list_items WHERE order_list_id = ?";
            $delete_items_stmt = $conn->prepare($delete_items_sql);
            $delete_items_stmt->bind_param("i", $list_id);
            $delete_items_stmt->execute();

            // 리스트 삭제
            $delete_list_sql = "DELETE FROM store_order_lists WHERE id = ? AND store_id = ?";
            $delete_list_stmt = $conn->prepare($delete_list_sql);
            $delete_list_stmt->bind_param("ii", $list_id, $current_store_id);
            $delete_list_stmt->execute();

            $conn->commit();
            $conn->close();

            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => '리스트가 삭제되었습니다.'
            ];

            header('Location: store_order_lists.php');
            exit;

        } catch (Exception $e) {
            if (isset($conn)) {
                $conn->rollback();
                $conn->close();
            }

            $_SESSION['flash'] = [
                'type' => 'error',
                'message' => '삭제 중 오류가 발생했습니다: ' . $e->getMessage()
            ];

            header('Location: store_order_lists.php');
            exit;
        }
    }
}

// 리스트 데이터 로드
$lists = [];
try {
    $conn = get_db_connection();
    $current_store_id = $_SESSION['store_id'] ?? 0;

    if (empty($current_store_id) && !empty($_SESSION['user_id'])) {
        $user_sql = "SELECT store_id FROM users WHERE id = ?";
        $user_stmt = $conn->prepare($user_sql);
        $user_stmt->bind_param("i", $_SESSION['user_id']);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        if ($user_row = $user_result->fetch_assoc()) {
            $current_store_id = $user_row['store_id'];
        }
        $user_stmt->close();
    }

    // 리스트 조회 (상품 개수 포함)
    $sql = "
        SELECT
            sol.id,
            sol.title,
            sol.created_at,
            sol.updated_at,
            COUNT(DISTINCT soli.product_id) as product_count
        FROM store_order_lists sol
        LEFT JOIN store_order_list_items soli ON sol.id = soli.order_list_id
        WHERE sol.store_id = ?
        GROUP BY sol.id, sol.title, sol.created_at, sol.updated_at
        ORDER BY sol.updated_at DESC, sol.created_at DESC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $current_store_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $lists[] = $row;
    }

    $conn->close();

} catch (Exception $e) {
    error_log("Error loading store order lists: " . $e->getMessage());
}

// 플래시 메시지 표시
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}
?>

<div class="px-2 sm:px-3 md:px-4 py-4 h-full flex flex-col">
    <!-- 토스트 알림 -->
    <div id="barcode_toast" style="display:none; position:fixed; top:20px; right:20px; z-index:9999; min-width:280px; max-width:400px; padding:14px 18px; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.15); font-size:14px; font-weight:500; transition:opacity 0.4s;"></div>

    <div class="mb-6">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">점포세팅 주문관리</h1>
                <p class="mt-2 text-sm text-gray-600">주문할 상품 목록을 등록하고 관리합니다.</p>
            </div>
            <a href="store_order_list_edit.php"
               class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                <i class="fas fa-plus mr-2"></i>
                새로 등록
            </a>
        </div>
        <!-- 바코드 스캔 입력 -->
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
            <div class="flex items-center gap-3">
                <i class="fas fa-barcode text-yellow-600 text-xl"></i>
                <div class="flex-1">
                    <label class="block text-sm font-semibold text-yellow-800 mb-1">바코드 스캔 — 주문 → 비주문 변경</label>
                    <input type="text" id="barcode_input" placeholder="바코드(SKU)를 스캔하거나 입력 후 Enter"
                           class="w-full px-3 py-2 border border-yellow-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-yellow-400 bg-white"
                           autocomplete="off">
                </div>
            </div>
        </div>
    </div>

    <?php if (isset($flash)): ?>
        <div class="mb-6 p-4 rounded-md <?php echo $flash['type'] === 'error' ? 'bg-red-50 border border-red-200' : 'bg-green-50 border border-green-200'; ?>">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas <?php echo $flash['type'] === 'error' ? 'fa-exclamation-circle text-red-400' : 'fa-check-circle text-green-400'; ?>"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm <?php echo $flash['type'] === 'error' ? 'text-red-800' : 'text-green-800'; ?>">
                        <?php echo htmlspecialchars($flash['message']); ?>
                    </p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="flex-grow flex flex-col min-h-0">
        <?php if (empty($lists)): ?>
            <div class="bg-white rounded-lg shadow-md p-12 text-center flex-grow flex items-center justify-center">
                <div>
                    <i class="fas fa-inbox text-4xl text-gray-300 mb-4"></i>
                    <p class="text-gray-500 text-lg">등록된 리스트가 없습니다.</p>
                    <p class="text-gray-400 text-sm mt-2">우측의 [새로 등록] 버튼을 클릭하여 첫 번째 리스트를 만들어보세요.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="bg-white shadow-md rounded-lg overflow-hidden flex flex-col min-h-0">
                <div class="overflow-x-auto flex-grow">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50 sticky top-0">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                    제목
                                </th>
                                <th class="px-6 py-3 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                    상품 개수
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                    등록일
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                    수정일
                                </th>
                                <th class="px-6 py-3 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                    작업
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($lists as $list): ?>
                                <tr class="hover:bg-blue-50 transition-colors cursor-pointer" onclick="navigateToEdit(<?php echo $list['id']; ?>)" style="cursor: pointer;">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="text-blue-600 font-medium hover:text-blue-900">
                                            <?php echo htmlspecialchars($list['title']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center">
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold bg-blue-100 text-blue-800">
                                            <?php echo $list['product_count']; ?> 개
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                        <?php echo date('Y-m-d H:i', strtotime($list['created_at'])); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                        <?php echo date('Y-m-d H:i', strtotime($list['updated_at'])); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center space-x-2" onclick="event.stopPropagation();">
                                        <a href="store_order_list_edit.php?id=<?php echo $list['id']; ?>"
                                           class="inline-flex items-center px-3 py-1 rounded-md text-sm font-medium text-blue-600 hover:bg-blue-100">
                                            <i class="fas fa-edit mr-1"></i>
                                            수정
                                        </a>
                                        <form method="POST" class="inline" onsubmit="event.stopPropagation(); return confirm('정말 삭제하시겠습니까?');">
                                            <input type="hidden" name="list_id" value="<?php echo $list['id']; ?>">
                                            <button type="submit" name="delete_list"
                                                    class="inline-flex items-center px-3 py-1 rounded-md text-sm font-medium text-red-600 hover:bg-red-100">
                                                <i class="fas fa-trash mr-1"></i>
                                                삭제
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function navigateToEdit(listId) {
    window.location.href = 'store_order_list_edit.php?id=' + listId;
}

// 바코드 스캔 처리
(function() {
    const input = document.getElementById('barcode_input');
    const toast = document.getElementById('barcode_toast');
    let toastTimer = null;

    function showToast(message, type) {
        // type: 'blue'(주문), 'red'(비주문/오류)
        var styles = {
            blue: { bg: '#dbeafe', color: '#1e40af', border: '1px solid #93c5fd' },
            red:  { bg: '#fee2e2', color: '#991b1b', border: '1px solid #fca5a5' }
        };
        var s = styles[type] || styles.red;
        clearTimeout(toastTimer);
        toast.textContent = message;
        toast.style.background = s.bg;
        toast.style.color = s.color;
        toast.style.border = s.border;
        toast.style.opacity = '1';
        toast.style.display = 'block';

        toastTimer = setTimeout(function() {
            toast.style.opacity = '0';
            setTimeout(function() { toast.style.display = 'none'; }, 400);
        }, 3000);
    }

    input.addEventListener('keydown', function(e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();

        const barcode = input.value.trim().replace(/^,+/, '');
        if (!barcode) return;

        input.value = '';
        input.disabled = true;

        const fd = new FormData();
        fd.append('barcode', barcode);

        fetch('ajax_barcode_toggle_order_status.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var type = 'red';
                if (data.success) {
                    type = (data.new_status === '주문') ? 'blue' : 'red';
                }
                showToast(data.message, type);
            })
            .catch(function() {
                showToast('네트워크 오류가 발생했습니다.', 'red');
            })
            .finally(function() {
                input.disabled = false;
                input.focus();
            });
    });

    // 페이지 로드 시 자동 포커스
    window.addEventListener('load', function() { input.focus(); });
})();
</script>
