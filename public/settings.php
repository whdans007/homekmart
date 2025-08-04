<?php
$page_title = "환경설정";
require_once __DIR__ . '/partials/header.php';

// 환경설정 권한 확인
if (!has_permission('settings')) {
    $_SESSION['flash'] = [
        'type' => 'error', 
        'message' => '환경설정에 접근할 권한이 없습니다.'
    ];
    header('Location: shop.php');
    exit;
}

// Get flash message
$flash_message = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>

<!-- Page header -->
<div class="mb-8">
    <h1 class="text-3xl font-bold text-gray-900">환경설정</h1>
    <p class="mt-2 text-sm text-gray-600">시스템 관리 및 데이터 관리 도구를 제공합니다.</p>
</div>

<?php if ($flash_message): ?>
    <div class="mb-6 rounded-lg p-4 border <?php echo $flash_message['type'] === 'success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800'; ?>">
        <?php echo htmlspecialchars($flash_message['message']); ?>
    </div>
<?php endif; ?>

<!-- Quick Actions -->
<div class="mt-8">
    <h2 class="text-lg font-medium text-gray-900 mb-4">빠른 작업</h2>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <a href="import_products.php" class="relative group bg-white p-6 focus-within:ring-2 focus-within:ring-inset focus-within:ring-primary-500 rounded-lg shadow hover:shadow-md transition-shadow duration-200">
            <div>
                <span class="rounded-lg inline-flex p-3 bg-green-50 text-green-700 ring-4 ring-white">
                    <i class="fas fa-file-excel text-lg"></i>
                </span>
            </div>
            <div class="mt-4">
                <h3 class="text-lg font-medium">
                    <span class="absolute inset-0" aria-hidden="true"></span>
                    상품정보 가져오기
                </h3>
                <p class="mt-2 text-sm text-gray-500">Excel 파일에서 상품정보를 가져와 데이터베이스에 저장합니다.</p>
            </div>
            <span class="pointer-events-none absolute top-6 right-6 text-gray-300 group-hover:text-gray-400" aria-hidden="true">
                <i class="fas fa-arrow-right"></i>
            </span>
        </a>
    </div>
</div>


<?php
require_once __DIR__ . '/partials/footer.php';
?>
