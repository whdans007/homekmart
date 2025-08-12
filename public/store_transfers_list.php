<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = '점간이동 목록' . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// 점간이동 권한 확인
if (!has_permission('store_transfer_management')) {
    $_SESSION['flash'] = [
        'type' => 'error', 
        'message' => t('messages.permission_denied')
    ];
    header('Location: shop.php');
    exit;
}

$transfers = [];
$error_message = '';
$stores = [];

// 검색 필터 변수
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$from_store_filter = $_GET['from_store_id'] ?? '';
$to_store_filter = $_GET['to_store_id'] ?? '';
$status_filter = $_GET['status'] ?? '';

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 점포 목록 가져오기 (필터용)
    $stores_stmt = $pdo->prepare("SELECT id, name FROM stores ORDER BY name");
    $stores_stmt->execute();
    $stores = $stores_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 검색 조건 구성
    $where_conditions = [];
    $params = [];

    // 날짜 필터
    if (!empty($start_date)) {
        $where_conditions[] = "st.transfer_date >= ?";
        $params[] = $start_date;
    }
    if (!empty($end_date)) {
        $where_conditions[] = "st.transfer_date <= ?";
        $params[] = $end_date;
    }

    // 출발 점포 필터
    if (!empty($from_store_filter)) {
        $where_conditions[] = "st.from_store_id = ?";
        $params[] = $from_store_filter;
    }

    // 목적지 점포 필터
    if (!empty($to_store_filter)) {
        $where_conditions[] = "st.to_store_id = ?";
        $params[] = $to_store_filter;
    }

    // 상태 필터
    if (!empty($status_filter)) {
        $where_conditions[] = "st.status = ?";
        $params[] = $status_filter;
    }

    // 권한 확인 (super_admin이 아닌 경우 자신의 점포 관련 이동만)
    if ($_SESSION['role'] !== 'super_admin') {
        $where_conditions[] = "(st.from_store_id = ? OR st.to_store_id = ?)";
        $params[] = $current_store_id;
        $params[] = $current_store_id;
    }

    // 최종 WHERE 절 구성
    $where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

    // 이동 목록 조회
    $sql = "
        SELECT 
            st.id,
            st.from_store_id,
            st.to_store_id,
            st.transfer_date,
            st.total_amount,
            st.final_amount,
            st.status,
            st.notes,
            st.created_at,
            st.updated_at,
            fs.name as from_store_name,
            ts.name as to_store_name,
            u.full_name as user_name,
            (SELECT COUNT(*) FROM store_transfer_items WHERE transfer_id = st.id) as item_count
        FROM store_transfers st
        LEFT JOIN stores fs ON st.from_store_id = fs.id
        LEFT JOIN stores ts ON st.to_store_id = ts.id
        LEFT JOIN users u ON st.user_id = u.id
        {$where_clause}
        ORDER BY st.created_at DESC
        LIMIT 100
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = '데이터베이스 오류: ' . $e->getMessage();
    error_log("Store transfers list error: " . $e->getMessage());
}

// 플래시 메시지 표시
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}
?>

<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="max-w-7xl mx-auto">
        <!-- 페이지 헤더 -->
        <div class="mb-8 sm:flex sm:items-center sm:justify-between">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">점간이동 목록</h1>
                <p class="mt-2 text-sm text-gray-700">점포간 상품 이동 내역을 조회하고 관리하세요.</p>
            </div>
            <div class="mt-4 sm:mt-0">
                <a href="store_transfers.php" 
                   class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-colors duration-200">
                    <i class="fas fa-plus mr-2"></i>
                    점간이동 등록
                </a>
            </div>
        </div>

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

        <?php if ($error_message): ?>
            <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-md">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-exclamation-triangle text-red-400"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm text-red-800"><?php echo htmlspecialchars($error_message); ?></p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- 검색 필터 -->
        <div class="bg-white shadow rounded-lg border border-gray-200 mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">검색 필터</h3>
            </div>
            <div class="px-6 py-4">
                <form method="GET" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-4">
                        <!-- 시작 날짜 -->
                        <div>
                            <label for="start_date" class="block text-sm font-medium text-gray-700 mb-1">시작 날짜</label>
                            <input type="date" name="start_date" id="start_date" value="<?php echo htmlspecialchars($start_date); ?>"
                                   class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                        </div>

                        <!-- 종료 날짜 -->
                        <div>
                            <label for="end_date" class="block text-sm font-medium text-gray-700 mb-1">종료 날짜</label>
                            <input type="date" name="end_date" id="end_date" value="<?php echo htmlspecialchars($end_date); ?>"
                                   class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                        </div>

                        <!-- 출발 점포 -->
                        <div>
                            <label for="from_store_id" class="block text-sm font-medium text-gray-700 mb-1">출발 점포</label>
                            <select name="from_store_id" id="from_store_id" 
                                    class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                                <option value="">전체</option>
                                <?php foreach ($stores as $store): ?>
                                    <option value="<?php echo $store['id']; ?>" 
                                            <?php echo ($from_store_filter == $store['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($store['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- 목적지 점포 -->
                        <div>
                            <label for="to_store_id" class="block text-sm font-medium text-gray-700 mb-1">목적지 점포</label>
                            <select name="to_store_id" id="to_store_id" 
                                    class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                                <option value="">전체</option>
                                <?php foreach ($stores as $store): ?>
                                    <option value="<?php echo $store['id']; ?>" 
                                            <?php echo ($to_store_filter == $store['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($store['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- 상태 -->
                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700 mb-1">상태</label>
                            <select name="status" id="status" 
                                    class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                                <option value="">전체</option>
                                <option value="draft" <?php echo ($status_filter === 'draft') ? 'selected' : ''; ?>>임시</option>
                                <option value="confirmed" <?php echo ($status_filter === 'confirmed') ? 'selected' : ''; ?>>확정</option>
                                <option value="cancelled" <?php echo ($status_filter === 'cancelled') ? 'selected' : ''; ?>>취소</option>
                            </select>
                        </div>
                    </div>

                    <div class="flex justify-end space-x-3 pt-4">
                        <a href="?" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                            초기화
                        </a>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                            검색
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- 이동 목록 테이블 -->
        <div class="bg-white shadow rounded-lg border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">
                    점간이동 내역 
                    <span class="text-sm font-normal text-gray-500">(총 <?php echo count($transfers); ?>건)</span>
                </h3>
            </div>
            
            <?php if (empty($transfers)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-exchange-alt text-gray-300 text-6xl mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">점간이동 내역이 없습니다</h3>
                    <p class="text-gray-500 mb-6">조건에 맞는 점간이동 내역을 찾을 수 없습니다.</p>
                    <a href="store_transfers.php" 
                       class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700">
                        <i class="fas fa-plus mr-2"></i>
                        첫 번째 점간이동 등록
                    </a>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    번호
                                </th>
                                <th scope="col" class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    이동 날짜
                                </th>
                                <th scope="col" class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    출발 → 목적지
                                </th>
                                <th scope="col" class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    상품 수
                                </th>
                                <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    총 금액
                                </th>
                                <th scope="col" class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    상태
                                </th>
                                <th scope="col" class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    처리자
                                </th>
                                <th scope="col" class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    액션
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($transfers as $transfer): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-3 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900">
                                            #<?php echo str_pad($transfer['id'], 6, '0', STR_PAD_LEFT); ?>
                                        </div>
                                        <div class="text-xs text-gray-500">
                                            <?php echo date('m-d H:i', strtotime($transfer['created_at'])); ?>
                                        </div>
                                    </td>
                                    <td class="px-3 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo date('Y-m-d', strtotime($transfer['transfer_date'])); ?>
                                    </td>
                                    <td class="px-3 py-4">
                                        <div class="text-sm text-gray-900">
                                            <span class="font-medium text-red-600"><?php echo htmlspecialchars($transfer['from_store_name']); ?></span>
                                            <i class="fas fa-arrow-right mx-2 text-gray-400"></i>
                                            <span class="font-medium text-blue-600"><?php echo htmlspecialchars($transfer['to_store_name']); ?></span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-4 whitespace-nowrap text-center text-sm text-gray-900">
                                        <?php echo $transfer['item_count']; ?>개
                                    </td>
                                    <td class="px-3 py-4 whitespace-nowrap text-right text-sm font-medium text-gray-900">
                                        ₩<?php echo number_format($transfer['total_amount'], 2); ?>
                                    </td>
                                    <td class="px-3 py-4 whitespace-nowrap text-center">
                                        <?php 
                                        $status_classes = [
                                            'draft' => 'bg-yellow-100 text-yellow-800',
                                            'confirmed' => 'bg-green-100 text-green-800',
                                            'cancelled' => 'bg-red-100 text-red-800'
                                        ];
                                        $status_names = [
                                            'draft' => '임시',
                                            'confirmed' => '확정',
                                            'cancelled' => '취소'
                                        ];
                                        $status_class = $status_classes[$transfer['status']] ?? 'bg-gray-100 text-gray-800';
                                        $status_name = $status_names[$transfer['status']] ?? $transfer['status'];
                                        ?>
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php echo $status_class; ?>">
                                            <?php echo $status_name; ?>
                                        </span>
                                    </td>
                                    <td class="px-3 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo htmlspecialchars($transfer['user_name'] ?? '알 수 없음'); ?>
                                    </td>
                                    <td class="px-3 py-4 whitespace-nowrap text-center text-sm font-medium">
                                        <div class="flex justify-center space-x-1">
                                            <a href="store_transfer_preview.php?id=<?php echo $transfer['id']; ?>" 
                                               class="text-blue-600 hover:text-blue-900" title="미리보기">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="store_transfers.php?edit=<?php echo $transfer['id']; ?>" 
                                               class="text-green-600 hover:text-green-900" title="수정">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        
        <?php if (count($transfers) >= 100): ?>
            <div class="mt-4 text-center text-sm text-gray-600">
                최근 100건만 표시됩니다. 더 많은 내역을 보려면 날짜 범위를 좁혀서 검색하세요.
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 오늘 날짜를 기본값으로 설정하는 버튼 추가 (선택사항)
    const startDateInput = document.getElementById('start_date');
    const endDateInput = document.getElementById('end_date');
    
    // 빠른 날짜 선택 기능 추가 (선택사항)
    function setDateRange(days) {
        const today = new Date();
        const startDate = new Date(today);
        startDate.setDate(today.getDate() - days);
        
        startDateInput.value = startDate.toISOString().split('T')[0];
        endDateInput.value = today.toISOString().split('T')[0];
    }
    
    // 검색 폼 자동 제출 (선택사항)
    const formInputs = document.querySelectorAll('select[name="from_store_id"], select[name="to_store_id"], select[name="status"]');
    formInputs.forEach(input => {
        input.addEventListener('change', function() {
            // 자동 제출을 원하지 않으면 이 부분을 제거하세요
            // this.form.submit();
        });
    });
});
</script>

<style>
/* 테이블 반응형 스타일 */
@media (max-width: 768px) {
    .overflow-x-auto table {
        font-size: 0.875rem;
    }
    
    .overflow-x-auto th,
    .overflow-x-auto td {
        padding: 0.5rem 0.25rem;
    }
    
    .grid.grid-cols-1.md\\:grid-cols-2.lg\\:grid-cols-3.xl\\:grid-cols-5 {
        grid-template-columns: 1fr;
        gap: 1rem;
    }
}

/* 상태 배지 스타일 개선 */
.bg-yellow-100 {
    background-color: #fef3c7;
}
.text-yellow-800 {
    color: #92400e;
}
.bg-green-100 {
    background-color: #dcfce7;
}
.text-green-800 {
    color: #166534;
}
.bg-red-100 {
    background-color: #fee2e2;
}
.text-red-800 {
    color: #991b1b;
}

/* 호버 효과 */
tbody tr:hover {
    background-color: #f9fafb;
}

.text-red-600 {
    color: #dc2626;
}
.text-blue-600 {
    color: #2563eb;
}
</style>

<?php require_once __DIR__ . '/partials/footer.php'; ?>