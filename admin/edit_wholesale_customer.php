<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('wholesale.edit_customer') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// 도매판매 권한 확인
if (!has_permission('wholesale_management')) {
    $_SESSION['flash'] = [
        'type' => 'error', 
        'message' => t('messages.permission_denied')
    ];
    header('Location: shop.php');
    exit;
}

$customer_id = $_GET['id'] ?? 0;
$errors = [];
$customer = null;

// 거래처 정보 불러오기
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->prepare("SELECT * FROM wholesale_customers WHERE id = ? AND is_active = 1");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => '거래처를 찾을 수 없습니다.'
        ];
        header('Location: wholesale_customer_management.php');
        exit;
    }
} catch (PDOException $e) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => '데이터베이스 오류: ' . $e->getMessage()
    ];
    header('Location: wholesale_customer_management.php');
    exit;
}

// 거래처 삭제 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $delete_customer_id = (int)($_POST['customer_id'] ?? 0);
    
    if ($delete_customer_id > 0 && $delete_customer_id == $customer_id) {
        try {
            // 거래처를 사용하는 판매 내역이 있는지 확인
            $sales_check_stmt = $pdo->prepare("SELECT COUNT(*) FROM wholesale_sales WHERE customer_id = ?");
            $sales_check_stmt->execute([$delete_customer_id]);
            $sales_count = $sales_check_stmt->fetchColumn();
            
            if ($sales_count > 0) {
                $_SESSION['flash'] = [
                    'type' => 'error',
                    'message' => "이 거래처는 {$sales_count}건의 판매 내역이 있어 삭제할 수 없습니다. 먼저 관련된 판매 내역을 처리해주세요."
                ];
            } else {
                // is_active를 0으로 설정하여 비활성화 (완전 삭제 대신)
                $delete_stmt = $pdo->prepare("
                    UPDATE wholesale_customers 
                    SET is_active = 0, updated_at = NOW() 
                    WHERE id = ?
                ");
                
                if ($delete_stmt->execute([$delete_customer_id])) {
                    $_SESSION['flash'] = [
                        'type' => 'success',
                        'message' => '거래처가 성공적으로 삭제되었습니다.'
                    ];
                    
                    // 거래처 관리 페이지로 리다이렉트
                    header('Location: wholesale_customer_management.php');
                    exit;
                } else {
                    $_SESSION['flash'] = [
                        'type' => 'error',
                        'message' => '거래처 삭제 중 오류가 발생했습니다.'
                    ];
                }
            }
            
        } catch (Exception $e) {
            $_SESSION['flash'] = [
                'type' => 'error',
                'message' => '삭제 중 오류가 발생했습니다: ' . $e->getMessage()
            ];
            
            error_log("Wholesale customer delete error: " . $e->getMessage());
        }
        
        // 오류가 있었다면 현재 페이지로 리다이렉트
        header("Location: edit_wholesale_customer.php?id=$delete_customer_id");
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 입력값 검증
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $memo = trim($_POST['memo'] ?? '');
    
    if (empty($name)) {
        $errors[] = '거래처명을 입력해주세요.';
    }
    
    if (empty($errors)) {
        try {
            // 다른 거래처와의 중복명 확인 (자기 제외)
            $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM wholesale_customers WHERE name = ? AND id != ? AND is_active = 1");
            $check_stmt->execute([$name, $customer_id]);
            
            if ($check_stmt->fetchColumn() > 0) {
                $errors[] = '이미 등록된 거래처명입니다.';
            } else {
                // 거래처 수정
                $stmt = $pdo->prepare("
                    UPDATE wholesale_customers 
                    SET name = ?, phone = ?, address = ?, memo = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                
                if ($stmt->execute([$name, $phone, $address, $memo, $customer_id])) {
                    $_SESSION['flash'] = [
                        'type' => 'success',
                        'message' => '거래처 정보가 성공적으로 수정되었습니다.'
                    ];
                    header('Location: wholesale_customer_management.php');
                    exit;
                } else {
                    $errors[] = '거래처 수정 중 오류가 발생했습니다.';
                }
            }
        } catch (PDOException $e) {
            $errors[] = '데이터베이스 오류: ' . $e->getMessage();
        }
    }
    
    // POST 데이터로 폼 값 업데이트
    $customer['name'] = $name;
    $customer['phone'] = $phone;
    $customer['address'] = $address;
    $customer['memo'] = $memo;
}

// 플래시 메시지 표시
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

?>

<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="max-w-2xl mx-auto">
        <div class="mb-6">
            <nav class="flex" aria-label="Breadcrumb">
                <ol class="flex items-center space-x-2">
                    <li>
                        <a href="wholesale_customer_management.php" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-users mr-1"></i>
                            <?php echo t('navigation.wholesale_customer_management'); ?>
                        </a>
                    </li>
                    <li>
                        <div class="flex items-center">
                            <i class="fas fa-chevron-right text-gray-400 mx-2"></i>
                            <span class="text-gray-600"><?php echo t('wholesale.edit_customer'); ?></span>
                        </div>
                    </li>
                </ol>
            </nav>
        </div>

        <div class="bg-white shadow-sm rounded-lg border">
            <div class="px-6 py-4 border-b border-gray-200">
                <h1 class="text-xl font-semibold text-gray-900">
                    <i class="fas fa-edit mr-2 text-primary-500"></i>
                    <?php echo t('wholesale.edit_customer'); ?>
                </h1>
                <p class="mt-1 text-sm text-gray-600">거래처 정보를 수정해주세요.</p>
            </div>

            <div class="px-6 py-4">
                <?php if (isset($flash)): ?>
                    <div class="mb-6 p-4 rounded-md <?php echo $flash['type'] === 'error' ? 'bg-red-50 border border-red-200' : 'bg-green-50 border border-green-200'; ?>">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas <?php echo $flash['type'] === 'error' ? 'fa-exclamation-triangle text-red-400' : 'fa-check-circle text-green-400'; ?>"></i>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm <?php echo $flash['type'] === 'error' ? 'text-red-700' : 'text-green-700'; ?>">
                                    <?php echo htmlspecialchars($flash['message']); ?>
                                </p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-md">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-exclamation-triangle text-red-400"></i>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-red-800">다음 오류를 해결해주세요:</h3>
                                <ul class="mt-2 text-sm text-red-700 list-disc list-inside">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?php echo htmlspecialchars($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST" class="space-y-6">
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700">
                            <?php echo t('wholesale.customer_name'); ?> <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="name" id="name" required
                               value="<?php echo htmlspecialchars($customer['name']); ?>"
                               class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                               placeholder="거래처명을 입력하세요">
                    </div>

                    <div>
                        <label for="phone" class="block text-sm font-medium text-gray-700">
                            <?php echo t('wholesale.customer_phone'); ?>
                        </label>
                        <input type="tel" name="phone" id="phone"
                               value="<?php echo htmlspecialchars($customer['phone'] ?? ''); ?>"
                               class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                               placeholder="전화번호를 입력하세요">
                    </div>

                    <div>
                        <label for="address" class="block text-sm font-medium text-gray-700">
                            <?php echo t('wholesale.customer_address'); ?>
                        </label>
                        <textarea name="address" id="address" rows="3"
                                  class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                  placeholder="주소를 입력하세요"><?php echo htmlspecialchars($customer['address'] ?? ''); ?></textarea>
                    </div>

                    <div>
                        <label for="memo" class="block text-sm font-medium text-gray-700">
                            <?php echo t('wholesale.customer_memo'); ?>
                        </label>
                        <textarea name="memo" id="memo" rows="4"
                                  class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                  placeholder="기타 메모사항을 입력하세요"><?php echo htmlspecialchars($customer['memo'] ?? ''); ?></textarea>
                    </div>

                    <div class="bg-gray-50 rounded-md p-4">
                        <div class="text-sm text-gray-600">
                            <div class="mb-2">
                                <strong>등록일:</strong> <?php echo date('Y-m-d H:i:s', strtotime($customer['created_at'])); ?>
                            </div>
                            <?php if ($customer['updated_at']): ?>
                            <div>
                                <strong>최종 수정일:</strong> <?php echo date('Y-m-d H:i:s', strtotime($customer['updated_at'])); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="flex justify-between pt-4">
                        <div>
                            <button type="button" id="delete-customer-btn" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                                <i class="fas fa-trash mr-2"></i>
                                거래처 삭제
                            </button>
                        </div>
                        <div class="flex space-x-4">
                            <a href="wholesale_customer_management.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                                <i class="fas fa-arrow-left mr-2"></i>
                                목록으로
                            </a>
                            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                                <i class="fas fa-save mr-2"></i>
                                저장
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- 거래처 삭제 확인 모달 -->
<div id="delete-customer-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
            <div class="p-6">
                <div class="flex items-center mb-4">
                    <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-red-100">
                        <i class="fas fa-trash text-red-600"></i>
                    </div>
                </div>
                <div class="text-center">
                    <h3 class="text-lg leading-6 font-medium text-gray-900 mb-2">거래처 삭제</h3>
                    <div class="text-sm text-gray-500 mb-4">
                        <p><strong><?php echo htmlspecialchars($customer['name']); ?></strong> 거래처를 삭제하시겠습니까?</p>
                        <p class="font-semibold text-red-600 mt-2">삭제된 거래처는 복구할 수 없습니다.</p>
                        <p class="text-xs text-gray-400 mt-2">판매 내역이 있는 거래처는 삭제할 수 없습니다.</p>
                    </div>
                </div>
                <div class="flex space-x-3 justify-center">
                    <button id="cancel-delete" type="button" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-500">
                        취소
                    </button>
                    <button id="confirm-delete" type="button" class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500">
                        삭제
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 거래처 삭제 폼 (숨김) -->
<form id="delete-customer-form" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="customer_id" value="<?php echo $customer_id; ?>">
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const deleteCustomerBtn = document.getElementById('delete-customer-btn');
    const deleteCustomerModal = document.getElementById('delete-customer-modal');
    const cancelDeleteBtn = document.getElementById('cancel-delete');
    const confirmDeleteBtn = document.getElementById('confirm-delete');
    const deleteCustomerForm = document.getElementById('delete-customer-form');
    
    // 거래처 삭제 버튼
    if (deleteCustomerBtn) {
        deleteCustomerBtn.addEventListener('click', function() {
            deleteCustomerModal.classList.remove('hidden');
        });
    }
    
    // 삭제 취소
    if (cancelDeleteBtn) {
        cancelDeleteBtn.addEventListener('click', function() {
            deleteCustomerModal.classList.add('hidden');
        });
    }
    
    // 삭제 확인
    if (confirmDeleteBtn) {
        confirmDeleteBtn.addEventListener('click', function() {
            deleteCustomerForm.submit();
        });
    }
    
    // 모달 외부 클릭시 닫기
    if (deleteCustomerModal) {
        deleteCustomerModal.addEventListener('click', function(e) {
            if (e.target === deleteCustomerModal) {
                deleteCustomerModal.classList.add('hidden');
            }
        });
    }
    
    // ESC 키로 모달 닫기
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && !deleteCustomerModal.classList.contains('hidden')) {
            deleteCustomerModal.classList.add('hidden');
        }
    });
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>