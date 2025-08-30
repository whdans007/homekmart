<?php
$page_title = "점포 비교 분석";
require_once 'partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// 권한 확인
require_permission('admin_access', 'login.php');

try {
    $conn = get_db_connection();
    
    // 모든 점포 목록 가져오기
    $stores_sql = "SELECT id, name, address FROM stores ORDER BY name";
    $stores_result = $conn->query($stores_sql);
    $stores = [];
    while ($row = $stores_result->fetch_assoc()) {
        $stores[] = $row;
    }
    
    // 점포별 통계 데이터
    $store_stats = [];
    foreach ($stores as $store) {
        $store_id = $store['id'];
        
        // 기본 통계
        $stats_sql = "SELECT 
            COUNT(DISTINCT sp.product_id) as total_products,
            COUNT(DISTINCT CASE WHEN sp.is_featured = 1 THEN sp.product_id END) as featured_products,
            COUNT(DISTINCT p.category_id) as categories_count,
            AVG(sp.price) as avg_price,
            MIN(sp.price) as min_price,
            MAX(sp.price) as max_price
        FROM store_products sp
        LEFT JOIN products p ON sp.product_id = p.id
        WHERE sp.store_id = ? AND sp.is_available = 1";
        
        $stmt = $conn->prepare($stats_sql);
        $stmt->bind_param("i", $store_id);
        $stmt->execute();
        $stats = $stmt->get_result()->fetch_assoc();
        
        // 카테고리별 분포
        $category_sql = "SELECT 
            c.name_kr as category_name,
            COUNT(sp.product_id) as product_count,
            AVG(sp.price) as avg_price
        FROM store_products sp
        LEFT JOIN products p ON sp.product_id = p.id
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE sp.store_id = ? AND sp.is_available = 1
        GROUP BY c.id, c.name_kr
        ORDER BY product_count DESC
        LIMIT 5";
        
        $stmt = $conn->prepare($category_sql);
        $stmt->bind_param("i", $store_id);
        $stmt->execute();
        $categories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // 최근 활동
        $activity_sql = "SELECT 
            COUNT(*) as recent_updates,
            MAX(updated_at) as last_update
        FROM store_products sp
        WHERE sp.store_id = ? AND sp.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        
        $stmt = $conn->prepare($activity_sql);
        $stmt->bind_param("i", $store_id);
        $stmt->execute();
        $activity = $stmt->get_result()->fetch_assoc();
        
        $store_stats[$store_id] = [
            'store' => $store,
            'stats' => $stats,
            'categories' => $categories,
            'activity' => $activity
        ];
    }
    
} catch (Exception $e) {
    error_log("Store comparison error: " . $e->getMessage());
    $error_message = "데이터를 불러오는 중 오류가 발생했습니다.";
}
?>

<div class="container-fluid px-4 py-6">
    <!-- 헤더 -->
    <div class="mb-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 mb-2">점포 비교 분석</h1>
                <p class="text-sm text-gray-600">전체 점포의 상품 현황과 성과를 비교 분석합니다</p>
            </div>
            <div class="mt-4 sm:mt-0">
                <button id="exportBtn" class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                    <i class="fas fa-download mr-2"></i>비교 결과 내보내기
                </button>
            </div>
        </div>
    </div>

    <?php if (isset($error_message)): ?>
    <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
        <div class="flex">
            <i class="fas fa-exclamation-circle text-red-500 mt-1 mr-3"></i>
            <div class="text-red-700"><?php echo htmlspecialchars($error_message); ?></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 전체 요약 카드 -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
            <div class="flex items-center">
                <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center mr-3">
                    <i class="fas fa-store text-blue-600"></i>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-600">총 점포 수</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo count($stores); ?>개</p>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
            <div class="flex items-center">
                <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center mr-3">
                    <i class="fas fa-box-open text-green-600"></i>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-600">총 등록 상품</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo array_sum(array_column(array_column($store_stats, 'stats'), 'total_products')); ?>개</p>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
            <div class="flex items-center">
                <div class="w-10 h-10 bg-yellow-100 rounded-lg flex items-center justify-center mr-3">
                    <i class="fas fa-star text-yellow-600"></i>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-600">특별 상품</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo array_sum(array_column(array_column($store_stats, 'stats'), 'featured_products')); ?>개</p>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
            <div class="flex items-center">
                <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center mr-3">
                    <i class="fas fa-chart-line text-purple-600"></i>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-600">평균 가격</p>
                    <p class="text-2xl font-bold text-gray-900">₩<?php echo number_format(array_sum(array_column(array_column($store_stats, 'stats'), 'avg_price')) / count($store_stats), 0); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- 점포별 비교 표 -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">
                <i class="fas fa-table mr-2 text-gray-600"></i>점포별 상세 비교
            </h3>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">점포명</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">총 상품</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">특별상품</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">카테고리</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">평균가격</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">가격범위</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">최근활동</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">상태</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($store_stats as $store_id => $data): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <div class="w-8 h-8 bg-red-100 rounded-full flex items-center justify-center mr-3">
                                    <i class="fas fa-store text-red-600 text-sm"></i>
                                </div>
                                <div>
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($data['store']['name']); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($data['store']['address'] ?? ''); ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <span class="text-lg font-bold text-gray-900"><?php echo number_format($data['stats']['total_products']); ?></span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <div class="flex items-center justify-center">
                                <span class="text-sm font-medium text-yellow-600"><?php echo number_format($data['stats']['featured_products']); ?></span>
                                <i class="fas fa-star text-yellow-500 ml-1 text-xs"></i>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <span class="text-sm font-medium text-purple-600"><?php echo number_format($data['stats']['categories_count']); ?>개</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <span class="text-sm font-medium text-gray-900">₩<?php echo number_format($data['stats']['avg_price']); ?></span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <div class="text-xs text-gray-600">
                                ₩<?php echo number_format($data['stats']['min_price']); ?> ~ <br>
                                ₩<?php echo number_format($data['stats']['max_price']); ?>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <div class="text-xs">
                                <div class="text-blue-600 font-medium"><?php echo number_format($data['activity']['recent_updates']); ?>건</div>
                                <div class="text-gray-500">
                                    <?php 
                                    if ($data['activity']['last_update']) {
                                        echo date('m/d H:i', strtotime($data['activity']['last_update']));
                                    } else {
                                        echo '활동없음';
                                    }
                                    ?>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <?php 
                            $product_count = $data['stats']['total_products'];
                            if ($product_count > 100) {
                                $status_class = 'bg-green-100 text-green-800';
                                $status_text = '풍부';
                            } else if ($product_count > 50) {
                                $status_class = 'bg-yellow-100 text-yellow-800';
                                $status_text = '보통';
                            } else {
                                $status_class = 'bg-red-100 text-red-800';
                                $status_text = '부족';
                            }
                            ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $status_class; ?>">
                                <?php echo $status_text; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 점포별 카테고리 분포 비교 -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <!-- 상품 분포 차트 -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">
                <i class="fas fa-chart-pie mr-2 text-gray-600"></i>점포별 상품 분포
            </h3>
            <canvas id="storeProductChart" width="400" height="300"></canvas>
        </div>

        <!-- 가격 분포 차트 -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">
                <i class="fas fa-chart-bar mr-2 text-gray-600"></i>점포별 평균 가격
            </h3>
            <canvas id="storePriceChart" width="400" height="300"></canvas>
        </div>
    </div>

    <!-- 점포별 상세 분석 -->
    <div class="space-y-6">
        <?php foreach ($store_stats as $store_id => $data): ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center mr-3">
                            <i class="fas fa-store text-red-600"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($data['store']['name']); ?></h3>
                            <p class="text-sm text-gray-600"><?php echo htmlspecialchars($data['store']['address'] ?? ''); ?></p>
                        </div>
                    </div>
                    <button class="toggle-details text-gray-400 hover:text-gray-600" data-store="<?php echo $store_id; ?>">
                        <i class="fas fa-chevron-down text-lg"></i>
                    </button>
                </div>
            </div>
            
            <div class="store-details hidden" id="details-<?php echo $store_id; ?>">
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <!-- 기본 통계 -->
                        <div class="space-y-4">
                            <h4 class="font-medium text-gray-900">기본 통계</h4>
                            <div class="space-y-3">
                                <div class="flex justify-between">
                                    <span class="text-sm text-gray-600">총 상품 수:</span>
                                    <span class="text-sm font-medium"><?php echo number_format($data['stats']['total_products']); ?>개</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-sm text-gray-600">특별 상품:</span>
                                    <span class="text-sm font-medium text-yellow-600"><?php echo number_format($data['stats']['featured_products']); ?>개</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-sm text-gray-600">카테고리 수:</span>
                                    <span class="text-sm font-medium"><?php echo number_format($data['stats']['categories_count']); ?>개</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-sm text-gray-600">평균 가격:</span>
                                    <span class="text-sm font-medium">₩<?php echo number_format($data['stats']['avg_price']); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- 상위 카테고리 -->
                        <div class="space-y-4">
                            <h4 class="font-medium text-gray-900">상위 카테고리</h4>
                            <div class="space-y-2">
                                <?php foreach ($data['categories'] as $category): ?>
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-gray-600"><?php echo htmlspecialchars($category['category_name'] ?? '미분류'); ?></span>
                                    <div class="text-right">
                                        <div class="text-xs font-medium text-gray-900"><?php echo number_format($category['product_count']); ?>개</div>
                                        <div class="text-xs text-gray-500">₩<?php echo number_format($category['avg_price']); ?></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- 최근 활동 -->
                        <div class="space-y-4">
                            <h4 class="font-medium text-gray-900">최근 활동</h4>
                            <div class="space-y-3">
                                <div class="flex justify-between">
                                    <span class="text-sm text-gray-600">지난 7일 업데이트:</span>
                                    <span class="text-sm font-medium text-blue-600"><?php echo number_format($data['activity']['recent_updates']); ?>건</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-sm text-gray-600">마지막 업데이트:</span>
                                    <span class="text-sm font-medium">
                                        <?php 
                                        if ($data['activity']['last_update']) {
                                            echo date('Y-m-d H:i', strtotime($data['activity']['last_update']));
                                        } else {
                                            echo '활동 없음';
                                        }
                                        ?>
                                    </span>
                                </div>
                                <div class="mt-4">
                                    <div class="flex justify-between text-xs text-gray-600 mb-1">
                                        <span>활동 수준</span>
                                        <span><?php echo $data['activity']['recent_updates'] > 10 ? '높음' : ($data['activity']['recent_updates'] > 5 ? '보통' : '낮음'); ?></span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-2">
                                        <div class="bg-blue-600 h-2 rounded-full" style="width: <?php echo min(100, ($data['activity']['recent_updates'] / 20) * 100); ?>%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // 점포별 상품 분포 도넛 차트
    const storeProductCtx = document.getElementById('storeProductChart').getContext('2d');
    const storeProductData = {
        labels: [
            <?php foreach ($store_stats as $data): ?>
                '<?php echo addslashes($data['store']['name']); ?>',
            <?php endforeach; ?>
        ],
        datasets: [{
            data: [
                <?php foreach ($store_stats as $data): ?>
                    <?php echo $data['stats']['total_products']; ?>,
                <?php endforeach; ?>
            ],
            backgroundColor: [
                '#EF4444', '#F97316', '#EAB308', '#22C55E', '#3B82F6',
                '#8B5CF6', '#EC4899', '#14B8A6', '#F59E0B', '#84CC16'
            ],
            borderWidth: 2,
            borderColor: '#fff'
        }]
    };

    new Chart(storeProductCtx, {
        type: 'doughnut',
        data: storeProductData,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        padding: 20,
                        usePointStyle: true,
                        font: {
                            size: 12
                        }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = ((context.parsed / total) * 100).toFixed(1);
                            return context.label + ': ' + context.parsed.toLocaleString() + '개 (' + percentage + '%)';
                        }
                    }
                }
            }
        }
    });

    // 점포별 평균 가격 막대 차트
    const storePriceCtx = document.getElementById('storePriceChart').getContext('2d');
    const storePriceData = {
        labels: [
            <?php foreach ($store_stats as $data): ?>
                '<?php echo addslashes($data['store']['name']); ?>',
            <?php endforeach; ?>
        ],
        datasets: [{
            label: '평균 가격 (원)',
            data: [
                <?php foreach ($store_stats as $data): ?>
                    <?php echo round($data['stats']['avg_price']); ?>,
                <?php endforeach; ?>
            ],
            backgroundColor: 'rgba(239, 68, 68, 0.8)',
            borderColor: 'rgb(239, 68, 68)',
            borderWidth: 1,
            borderRadius: 4
        }]
    };

    new Chart(storePriceCtx, {
        type: 'bar',
        data: storePriceData,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return '평균 가격: ₩' + context.parsed.y.toLocaleString();
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '₩' + value.toLocaleString();
                        }
                    }
                },
                x: {
                    ticks: {
                        maxRotation: 45,
                        minRotation: 0
                    }
                }
            }
        }
    });

    // 세부사항 토글
    document.querySelectorAll('.toggle-details').forEach(button => {
        button.addEventListener('click', function() {
            const storeId = this.dataset.store;
            const details = document.getElementById('details-' + storeId);
            const icon = this.querySelector('i');
            
            if (details.classList.contains('hidden')) {
                details.classList.remove('hidden');
                icon.classList.remove('fa-chevron-down');
                icon.classList.add('fa-chevron-up');
            } else {
                details.classList.add('hidden');
                icon.classList.remove('fa-chevron-up');
                icon.classList.add('fa-chevron-down');
            }
        });
    });

    // 내보내기 기능
    document.getElementById('exportBtn').addEventListener('click', function() {
        const data = {
            timestamp: new Date().toISOString(),
            stores: [
                <?php foreach ($store_stats as $data): ?>
                {
                    name: '<?php echo addslashes($data['store']['name']); ?>',
                    address: '<?php echo addslashes($data['store']['address'] ?? ''); ?>',
                    total_products: <?php echo $data['stats']['total_products']; ?>,
                    featured_products: <?php echo $data['stats']['featured_products']; ?>,
                    categories_count: <?php echo $data['stats']['categories_count']; ?>,
                    avg_price: <?php echo round($data['stats']['avg_price']); ?>,
                    min_price: <?php echo $data['stats']['min_price']; ?>,
                    max_price: <?php echo $data['stats']['max_price']; ?>,
                    recent_updates: <?php echo $data['activity']['recent_updates']; ?>,
                    last_update: '<?php echo $data['activity']['last_update'] ?? ''; ?>'
                },
                <?php endforeach; ?>
            ]
        };

        const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'store_comparison_' + new Date().toISOString().split('T')[0] + '.json';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
        
        // 성공 알림
        const button = this;
        const originalText = button.innerHTML;
        button.innerHTML = '<i class="fas fa-check mr-2"></i>내보내기 완료';
        button.classList.remove('bg-primary-600', 'hover:bg-primary-700');
        button.classList.add('bg-green-600');
        
        setTimeout(() => {
            button.innerHTML = originalText;
            button.classList.remove('bg-green-600');
            button.classList.add('bg-primary-600', 'hover:bg-primary-700');
        }, 2000);
    });
});
</script>

<?php require_once 'partials/footer.php'; ?>