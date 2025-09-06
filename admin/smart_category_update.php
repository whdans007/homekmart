<?php
/**
 * 스마트 카테고리 업데이트 - UPSERT 방식
 * 기존 데이터 확인 후 업데이트/삽입 결정
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/db_config.php';

$message = '';
$error = '';
$analysis_result = [];

// 기존 데이터 분석
function analyzeExistingData($conn) {
    $result = [];
    
    // 기존 카테고리 조회
    $stmt = $conn->query("SELECT id, name, name_en, parent_id FROM categories ORDER BY id");
    $existing = [];
    while ($row = $stmt->fetch_assoc()) {
        $existing[$row['name']] = $row;
    }
    
    // 새로운 카테고리 데이터 정의
    $new_categories = [
        // 대분류
        [1, '신선식품', 'Fresh Foods', NULL],
        [10, '가공식품', 'Processed Foods', NULL],
        [22, '간식/음료', 'Snacks & Beverages', NULL],
        [28, '생활용품', 'Daily Necessities', NULL],
        [34, '주방/가정용품', 'Home & Kitchen', NULL],
        [38, '기타', 'Others', NULL],
        
        // 신선식품 하위
        [2, '과일', 'Fruits', 1],
        [3, '채소', 'Vegetables', 1],
        [4, '정육', 'Meat', 1],
        [5, '수산물', 'Seafood', 1],
        [6, '계란', 'Eggs', 1],
        [7, '유제품/치즈', 'Dairy & Cheese', 1],
        [8, '김치/반찬', 'Kimchi & Side Dishes', 1],
        [9, '베이커리', 'Bakery', 1],
        
        // 가공식품 하위
        [11, '쌀/잡곡', 'Rice & Grains', 10],
        [12, '라면/면류', 'Instant Noodles & Pasta', 10],
        [13, '통조림/레토르트', 'Canned & Retort Foods', 10],
        [14, '장류/양념/쨈', 'Sauces, Seasonings & Jam', 10],
        [15, '오일/조미료', 'Oil & Condiments', 10],
        [16, '냉동식품', 'Frozen Foods', 10],
        [17, '냉동 수산물', 'Frozen Seafood', 10],
        [18, '냉장식품', 'Refrigerated Foods', 10],
        [19, '간편식/밀키트', 'Ready Meals & Meal Kits', 10],
        [20, '아이스크림', 'Ice Cream', 10],
        [21, '건어물/김', 'Dried Seafood & Seaweed', 10],
        
        // 간식/음료 하위
        [23, '과자/스낵', 'Snacks & Chips', 22],
        [24, '초콜릿/사탕', 'Chocolate & Candy', 22],
        [25, '커피/차', 'Coffee & Tea', 22],
        [26, '음료/생수', 'Beverages & Water', 22],
        [27, '주류', 'Alcoholic Beverages', 22],
        
        // 생활용품 하위
        [29, '세제/세정제', 'Detergent & Cleaners', 28],
        [30, '화장지/위생용품', 'Tissue & Hygiene', 28],
        [31, '구강용품', 'Oral Care', 28],
        [32, '헤어/바디용품', 'Hair & Body Care', 28],
        [33, '화장품/뷰티', 'Cosmetics & Beauty', 28],
        
        // 주방/가정용품 하위
        [35, '주방용품', 'Kitchenware', 34],
        [36, '가정생활용품', 'Household Goods', 34],
        [37, '청소용품', 'Cleaning Supplies', 34],
        
        // 기타 하위
        [39, '유아용품', 'Baby Products', 38],
        [40, '애완용품', 'Pet Supplies', 38],
        [41, '약', 'Pharmacy / Medicine', 38],
        [42, '선물세트', 'Gift Sets', 38]
    ];
    
    $to_insert = [];
    $to_update = [];
    $to_keep = [];
    $to_delete = [];
    
    // 분석: 새 카테고리와 기존 카테고리 비교
    foreach ($new_categories as $new_cat) {
        $name = $new_cat[1];
        if (isset($existing[$name])) {
            $existing_cat = $existing[$name];
            
            // 변경사항 확인
            $changes = [];
            if ($existing_cat['name_en'] != $new_cat[2]) $changes[] = 'name_en';
            if ($existing_cat['parent_id'] != $new_cat[3]) $changes[] = 'parent_id';
            
            if (count($changes) > 0) {
                $to_update[] = [
                    'existing' => $existing_cat,
                    'new' => $new_cat,
                    'changes' => $changes
                ];
            } else {
                $to_keep[] = $existing_cat;
            }
            
            unset($existing[$name]); // 처리된 항목 제거
        } else {
            $to_insert[] = $new_cat;
        }
    }
    
    // 남은 기존 카테고리는 삭제 대상
    foreach ($existing as $old_cat) {
        $to_delete[] = $old_cat;
    }
    
    return [
        'to_insert' => $to_insert,
        'to_update' => $to_update,
        'to_keep' => $to_keep,
        'to_delete' => $to_delete
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $conn = get_db_connection();
        
        if ($_POST['action'] === 'analyze') {
            $analysis_result = analyzeExistingData($conn);
            $message = "✅ 데이터 분석이 완료되었습니다.";
        }
        
        if ($_POST['action'] === 'execute') {
            $conn->autocommit(false);
            
            // 외래키 제약 조건 임시 해제
            $conn->query("SET FOREIGN_KEY_CHECKS = 0");
            
            $analysis_result = analyzeExistingData($conn);
            
            $inserted = 0;
            $updated = 0;
            $deleted = 0;
            
            // 1. 삭제 처리
            foreach ($analysis_result['to_delete'] as $cat) {
                $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
                if ($stmt->execute([$cat['id']])) {
                    $deleted++;
                }
            }
            
            // 2. 업데이트 처리
            foreach ($analysis_result['to_update'] as $update_info) {
                $new_cat = $update_info['new'];
                $existing_id = $update_info['existing']['id'];
                
                $stmt = $conn->prepare("UPDATE categories SET name_en = ?, parent_id = ? WHERE id = ?");
                if ($stmt->execute([$new_cat[2], $new_cat[3], $existing_id])) {
                    $updated++;
                }
            }
            
            // 3. 삽입 처리 (부모 먼저, 자식 나중에)
            // 부모 카테고리와 자식 카테고리 분리
            $parent_categories = [];
            $child_categories = [];
            
            foreach ($analysis_result['to_insert'] as $new_cat) {
                if ($new_cat[3] === NULL) {
                    $parent_categories[] = $new_cat;
                } else {
                    $child_categories[] = $new_cat;
                }
            }
            
            // 3-1. 부모 카테고리 먼저 삽입
            foreach ($parent_categories as $new_cat) {
                $desired_id = $new_cat[0];
                
                // ID가 사용 중인지 확인
                $check_stmt = $conn->prepare("SELECT id FROM categories WHERE id = ?");
                $check_stmt->execute([$desired_id]);
                
                if ($check_stmt->rowCount() > 0) {
                    // ID가 사용 중이면 AUTO_INCREMENT로 삽입
                    $stmt = $conn->prepare("INSERT INTO categories (name, name_en, parent_id) VALUES (?, ?, ?)");
                    if ($stmt->execute([$new_cat[1], $new_cat[2], $new_cat[3]])) {
                        $inserted++;
                    }
                } else {
                    // ID가 비어있으면 지정된 ID로 삽입
                    $stmt = $conn->prepare("INSERT INTO categories (id, name, name_en, parent_id) VALUES (?, ?, ?, ?)");
                    if ($stmt->execute([$new_cat[0], $new_cat[1], $new_cat[2], $new_cat[3]])) {
                        $inserted++;
                    }
                }
            }
            
            // 3-2. 자식 카테고리 나중에 삽입
            foreach ($child_categories as $new_cat) {
                $desired_id = $new_cat[0];
                
                // 부모 카테고리가 존재하는지 확인
                $parent_check = $conn->prepare("SELECT id FROM categories WHERE id = ?");
                $parent_check->execute([$new_cat[3]]);
                
                if ($parent_check->rowCount() == 0) {
                    // 부모가 없으면 NULL로 설정
                    $new_cat[3] = NULL;
                }
                
                // ID가 사용 중인지 확인
                $check_stmt = $conn->prepare("SELECT id FROM categories WHERE id = ?");
                $check_stmt->execute([$desired_id]);
                
                if ($check_stmt->rowCount() > 0) {
                    // ID가 사용 중이면 AUTO_INCREMENT로 삽입
                    $stmt = $conn->prepare("INSERT INTO categories (name, name_en, parent_id) VALUES (?, ?, ?)");
                    if ($stmt->execute([$new_cat[1], $new_cat[2], $new_cat[3]])) {
                        $inserted++;
                    }
                } else {
                    // ID가 비어있으면 지정된 ID로 삽입
                    $stmt = $conn->prepare("INSERT INTO categories (id, name, name_en, parent_id) VALUES (?, ?, ?, ?)");
                    if ($stmt->execute([$new_cat[0], $new_cat[1], $new_cat[2], $new_cat[3]])) {
                        $inserted++;
                    }
                }
            }
            
            // 외래키 제약 조건 다시 활성화
            $conn->query("SET FOREIGN_KEY_CHECKS = 1");
            
            $conn->commit();
            $message = "✅ 업데이트 완료! 삽입: {$inserted}개, 수정: {$updated}개, 삭제: {$deleted}개";
        }
        
    } catch (Exception $e) {
        if (isset($conn)) {
            $conn->query("SET FOREIGN_KEY_CHECKS = 1"); // 오류시에도 복구
            $conn->rollback();
        }
        $error = "❌ 오류 발생: " . $e->getMessage();
    } finally {
        if (isset($conn)) {
            $conn->query("SET FOREIGN_KEY_CHECKS = 1"); // 최종 복구
            $conn->autocommit(true);
            $conn->close();
        }
    }
}

// 현재 카테고리 개수 확인
try {
    $conn = get_db_connection();
    $result = $conn->query("SELECT COUNT(*) as total FROM categories");
    $current_count = $result->fetch_assoc()['total'];
    $conn->close();
} catch (Exception $e) {
    $current_count = '알 수 없음';
}

// 분석하지 않았다면 자동 분석 실행
if (empty($analysis_result) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    try {
        $conn = get_db_connection();
        $analysis_result = analyzeExistingData($conn);
        $conn->close();
    } catch (Exception $e) {
        // 분석 실패시 무시
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>스마트 카테고리 업데이트 - HOME K MART</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100">
    <div class="container mx-auto px-4 py-6 max-w-6xl">
        <div class="bg-white rounded-lg shadow-md p-6">
            <h1 class="text-3xl font-bold mb-6 text-center text-blue-600">
                <i class="fas fa-sync-alt"></i> 스마트 카테고리 업데이트
            </h1>
            
            <?php if ($message): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            
            <!-- 현재 상태 -->
            <div class="mb-6">
                <h2 class="text-xl font-semibold mb-3 text-gray-700">📊 현재 상태</h2>
                <div class="bg-gray-50 p-4 rounded border">
                    <p class="mb-2"><strong>현재 카테고리 수:</strong> <span class="text-blue-600"><?= $current_count ?>개</span></p>
                    <p class="text-gray-600">스마트 업데이트: 기존 데이터 보존하며 필요한 부분만 수정/추가</p>
                </div>
            </div>
            
            <!-- 분석 결과 -->
            <?php if (!empty($analysis_result)): ?>
            <div class="mb-6">
                <h2 class="text-xl font-semibold mb-3 text-gray-700">🔍 변경사항 분석</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                    
                    <!-- 신규 추가 -->
                    <div class="bg-green-50 border border-green-200 rounded p-4">
                        <h3 class="font-semibold text-green-700 mb-2">
                            <i class="fas fa-plus-circle"></i> 신규 추가 
                            <span class="bg-green-500 text-white px-2 py-1 rounded-full text-xs ml-1">
                                <?= count($analysis_result['to_insert']) ?>개
                            </span>
                        </h3>
                        <div class="max-h-32 overflow-y-auto text-sm">
                            <?php foreach ($analysis_result['to_insert'] as $cat): ?>
                                <div class="mb-1">• <?= htmlspecialchars($cat[1]) ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- 업데이트 -->
                    <div class="bg-yellow-50 border border-yellow-200 rounded p-4">
                        <h3 class="font-semibold text-yellow-700 mb-2">
                            <i class="fas fa-edit"></i> 업데이트 
                            <span class="bg-yellow-500 text-white px-2 py-1 rounded-full text-xs ml-1">
                                <?= count($analysis_result['to_update']) ?>개
                            </span>
                        </h3>
                        <div class="max-h-32 overflow-y-auto text-sm">
                            <?php foreach ($analysis_result['to_update'] as $update): ?>
                                <div class="mb-1">
                                    • <?= htmlspecialchars($update['existing']['name']) ?>
                                    <span class="text-xs text-gray-500">(<?= implode(', ', $update['changes']) ?>)</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- 유지 -->
                    <div class="bg-blue-50 border border-blue-200 rounded p-4">
                        <h3 class="font-semibold text-blue-700 mb-2">
                            <i class="fas fa-check-circle"></i> 유지 
                            <span class="bg-blue-500 text-white px-2 py-1 rounded-full text-xs ml-1">
                                <?= count($analysis_result['to_keep']) ?>개
                            </span>
                        </h3>
                        <div class="max-h-32 overflow-y-auto text-sm">
                            <?php foreach ($analysis_result['to_keep'] as $cat): ?>
                                <div class="mb-1">• <?= htmlspecialchars($cat['name']) ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- 삭제 -->
                    <div class="bg-red-50 border border-red-200 rounded p-4">
                        <h3 class="font-semibold text-red-700 mb-2">
                            <i class="fas fa-trash"></i> 삭제 
                            <span class="bg-red-500 text-white px-2 py-1 rounded-full text-xs ml-1">
                                <?= count($analysis_result['to_delete']) ?>개
                            </span>
                        </h3>
                        <div class="max-h-32 overflow-y-auto text-sm">
                            <?php foreach ($analysis_result['to_delete'] as $cat): ?>
                                <div class="mb-1">• <?= htmlspecialchars($cat['name']) ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <!-- 요약 -->
                <div class="bg-gray-50 p-4 rounded border">
                    <strong class="text-gray-700">📈 요약:</strong>
                    <span class="ml-2">
                        총 변경사항: <?= count($analysis_result['to_insert']) + count($analysis_result['to_update']) + count($analysis_result['to_delete']) ?>개 |
                        현재 유지: <?= count($analysis_result['to_keep']) ?>개 |
                        최종 예상: <?= count($analysis_result['to_insert']) + count($analysis_result['to_update']) + count($analysis_result['to_keep']) ?>개
                    </span>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- 주요 장점 -->
            <div class="mb-6">
                <h2 class="text-xl font-semibold mb-3 text-gray-700">✨ 스마트 업데이트 장점</h2>
                <div class="bg-blue-50 p-4 rounded border">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <ul class="space-y-2">
                            <li class="flex items-center">
                                <i class="fas fa-shield-alt text-green-600 mr-2"></i>
                                <span>기존 데이터 보존 (불필요한 삭제 방지)</span>
                            </li>
                            <li class="flex items-center">
                                <i class="fas fa-sync-alt text-blue-600 mr-2"></i>
                                <span>필요한 부분만 업데이트</span>
                            </li>
                        </ul>
                        <ul class="space-y-2">
                            <li class="flex items-center">
                                <i class="fas fa-link text-purple-600 mr-2"></i>
                                <span>상품 연결 관계 유지</span>
                            </li>
                            <li class="flex items-center">
                                <i class="fas fa-eye text-orange-600 mr-2"></i>
                                <span>변경사항 미리보기</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <!-- 실행 버튼 -->
            <div class="text-center space-y-4">
                
                <!-- 분석 버튼 -->
                <form method="post" class="inline-block mr-4">
                    <input type="hidden" name="action" value="analyze">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-6 rounded-lg">
                        <i class="fas fa-search mr-2"></i> 데이터 다시 분석
                    </button>
                </form>
                
                <!-- 실행 버튼 -->
                <?php if (!empty($analysis_result)): ?>
                <form method="post" class="inline-block" onsubmit="return confirm('스마트 업데이트를 실행하시겠습니까?\n\n• 추가: <?= count($analysis_result['to_insert']) ?>개\n• 수정: <?= count($analysis_result['to_update']) ?>개\n• 삭제: <?= count($analysis_result['to_delete']) ?>개\n• 유지: <?= count($analysis_result['to_keep']) ?>개');">
                    <input type="hidden" name="action" value="execute">
                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-6 rounded-lg">
                        <i class="fas fa-play mr-2"></i> 스마트 업데이트 실행
                    </button>
                </form>
                <?php endif; ?>
                
                <div class="mt-6">
                    <a href="category_management.php" class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded mr-2">
                        <i class="fas fa-list mr-1"></i> 카테고리 관리
                    </a>
                    <a href="insert_categories.php" class="bg-orange-500 hover:bg-orange-600 text-white font-bold py-2 px-4 rounded">
                        <i class="fas fa-plus mr-1"></i> 전체 교체
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>