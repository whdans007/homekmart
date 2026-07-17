<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = 'HOME K MART - 모바일 관리';

// 간소화된 헤더 포함 (권한 체크 포함)
ob_start();
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../config/db_config.php';
ensure_logged_in();

// 현재 사용자의 점포 정보 가져오기
$current_store_name = '본점';
$current_store_id = null;
if (!empty($_SESSION['user_id'])) {
    try {
        $conn = get_db_connection();
        $user_stmt = $conn->prepare("SELECT s.name as store_name, s.id as store_id FROM users u LEFT JOIN stores s ON u.store_id = s.id WHERE u.id = ?");
        $user_stmt->bind_param("i", $_SESSION['user_id']);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        if ($user_row = $user_result->fetch_assoc()) {
            $current_store_name = $user_row['store_name'] ?? '본점';
            $current_store_id = $user_row['store_id'];
            
            // super_admin이고 store_id가 없는 경우 기본 점포 설정
            if ($_SESSION['role'] === 'super_admin' && empty($current_store_id)) {
                $current_store_id = 1;
                $current_store_name = 'CLARK HILLS';
                $_SESSION['store_id'] = $current_store_id;
            }
        }
        $user_stmt->close();
        $conn->close();
    } catch (Exception $e) {
        error_log("Store info error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#ef4444">
    <title><?php echo $page_title; ?></title>
    <link rel="icon" href="data:,">
    <link href="css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    
    <style>
    /* 모바일 최적화 CSS */
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Noto Sans KR", sans-serif;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        min-height: 100vh;
        padding: 1rem;
    }
    
    .mobile-container {
        max-width: 400px;
        margin: 0 auto;
        background: rgba(255, 255, 255, 0.95);
        border-radius: 20px;
        padding: 2rem;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
        backdrop-filter: blur(10px);
    }
    
    .header {
        text-align: center;
        margin-bottom: 2rem;
    }
    
    .store-info {
        background: linear-gradient(135deg, #ef4444, #dc2626);
        color: white;
        padding: 1rem;
        border-radius: 15px;
        margin-bottom: 1rem;
        text-align: center;
    }
    
    .store-name {
        font-size: 1.1rem;
        font-weight: 700;
        margin-bottom: 0.25rem;
    }
    
    .user-info {
        font-size: 0.9rem;
        opacity: 0.9;
    }
    
    .main-title {
        font-size: 1.8rem;
        font-weight: 700;
        color: #1f2937;
        margin-bottom: 0.5rem;
        display: flex;
        flex-direction: column;
        align-items: center;
        line-height: 1.2;
    }
    
    .title-en {
        font-size: 1rem;
        font-weight: 500;
        color: #6b7280;
        margin-top: 0.25rem;
    }
    
    .subtitle {
        color: #6b7280;
        font-size: 1rem;
        line-height: 1.4;
        text-align: center;
    }
    
    .subtitle-en {
        font-size: 0.85rem;
        color: #9ca3af;
        font-style: italic;
        display: block;
        margin-top: 0.25rem;
    }
    
    .cards-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 1rem;
        margin-bottom: 2rem;
    }

    /* 6개 카드 최적 배치를 위한 추가 스타일 */
    @media (max-width: 374px) {
        .cards-grid {
            gap: 0.75rem;
        }
    }
    
    .function-card {
        background: white;
        border-radius: 20px;
        padding: 2rem 1.5rem;
        text-align: center;
        text-decoration: none;
        color: inherit;
        transition: all 0.3s ease;
        box-shadow: 0 6px 12px rgba(0, 0, 0, 0.1);
        position: relative;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        min-height: 120px;
    }
    
    .function-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        opacity: 0;
        transition: opacity 0.3s ease;
        border-radius: 20px;
    }
    
    .function-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px rgba(0, 0, 0, 0.15);
    }
    
    .function-card:hover::before {
        opacity: 0.1;
    }
    
    .function-card:active {
        transform: translateY(-2px);
    }
    
    .card-icon {
        font-size: 3rem;
        margin-bottom: 1rem;
        display: block;
        filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.2));
    }
    
    .card-title {
        font-size: 1.1rem;
        font-weight: 700;
        color: white;
        margin-bottom: 0.5rem;
        line-height: 1.3;
        text-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
        text-align: center;
    }
    
    .card-title-en {
        font-size: 0.85rem;
        font-weight: 500;
        color: rgba(255, 255, 255, 0.9);
        display: block;
        margin-top: 0.3rem;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
    }
    
    .card-desc {
        font-size: 0.8rem;
        color: #6b7280;
        line-height: 1.4;
    }
    
    .card-desc-en {
        font-size: 0.7rem;
        color: #9ca3af;
        font-style: italic;
        display: block;
        margin-top: 0.3rem;
        line-height: 1.2;
    }
    
    /* 각 카드별 색상 - 더 진하고 선명하게 */
    .card-wholesale {
        background: linear-gradient(135deg, #d97706, #b45309);
        border: 2px solid rgba(245, 158, 11, 0.3);
    }

    .card-wholesale::before {
        background: linear-gradient(135deg, #f59e0b, #d97706);
    }

    .card-wholesale .card-icon {
        color: white;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
    }

    /* 비활성화된 카드 스타일 */
    .disabled-card {
        background: linear-gradient(135deg, #6b7280, #4b5563) !important;
        opacity: 0.7;
        cursor: not-allowed !important;
        pointer-events: none;
        border: 2px solid rgba(107, 114, 128, 0.3) !important;
    }
    
    .disabled-card:hover {
        transform: none !important;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1) !important;
    }
    
    .disabled-card::before {
        display: none !important;
    }
    
    .disabled-card .card-icon {
        color: rgba(255, 255, 255, 0.7) !important;
    }
    
    .disabled-card .card-title {
        color: rgba(255, 255, 255, 0.8) !important;
    }
    
    .disabled-card .card-title-en {
        color: rgba(255, 255, 255, 0.6) !important;
    }
    
    .footer-links {
        display: flex;
        justify-content: center;
        gap: 1rem;
        margin-top: 1.5rem;
        flex-wrap: wrap;
    }
    
    .footer-link {
        color: #6b7280;
        text-decoration: none;
        font-size: 0.9rem;
        padding: 0.5rem 1rem;
        border-radius: 20px;
        background: rgba(255, 255, 255, 0.8);
        transition: all 0.2s ease;
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        line-height: 1.2;
    }
    
    .footer-link:hover {
        background: rgba(255, 255, 255, 1);
        color: #374151;
    }
    
    .link-text {
        display: flex;
        flex-direction: column;
        align-items: center;
    }
    
    .link-text small {
        font-size: 0.7rem;
        opacity: 0.7;
        margin-top: 0.1rem;
    }
    
    @media (max-width: 375px) {
        .mobile-container {
            padding: 1.5rem;
        }
        
        .cards-grid {
            gap: 0.8rem;
        }
        
        .function-card {
            padding: 1.2rem;
        }
        
        .card-icon {
            font-size: 2rem;
        }
        
        .main-title {
            font-size: 1.5rem;
        }
    }
    
    @media (min-width: 768px) {
        .mobile-container {
            max-width: 500px;
            padding: 3rem;
        }
        
        .cards-grid {
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }
        
        .function-card {
            padding: 2rem;
        }
    }
    </style>
</head>
<body>
    <div class="mobile-container">
        <!-- 헤더 정보 -->
        <div class="header">
            <div class="store-info">
                <div class="store-name">
                    <i class="fas fa-store mr-2"></i>
                    <?php echo htmlspecialchars($current_store_name); ?>
                </div>
                <div class="user-info">
                    <?php echo htmlspecialchars($_SESSION['username'] ?? '사용자'); ?>님 
                    (<?php echo htmlspecialchars($_SESSION['role'] ?? 'user'); ?>)
                </div>
            </div>
            
            <h1 class="main-title">
                <i class="fas fa-mobile-alt mr-2"></i>
                빠른 작업
                <span class="title-en">Quick Actions</span>
            </h1>
        </div>

        <!-- 주요 기능 카드 -->
        <div class="cards-grid">
            <!-- 매입 관리 -->
            <?php
            $has_purchase_permission = has_permission('purchase_management') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin']);
            if ($has_purchase_permission):
            ?>
            <a href="mobile_purchase_list.php" class="function-card card-wholesale">
                <i class="fas fa-shopping-cart card-icon"></i>
                <div class="card-title">
                    매입 관리
                    <span class="card-title-en">Purchase</span>
                </div>
            </a>
            <?php else: ?>
            <div class="function-card card-wholesale disabled-card">
                <i class="fas fa-shopping-cart card-icon"></i>
                <div class="card-title">
                    권한 없음
                    <span class="card-title-en">No Permission</span>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- 하단 링크 -->
        <div class="footer-links">
            <a href="shop_dashboard.php" class="footer-link">
                <i class="fas fa-store mr-1"></i>
                <span class="link-text">매장 운영<br><small>Store</small></span>
            </a>
            <a href="logout.php" class="footer-link">
                <i class="fas fa-sign-out-alt mr-1"></i>
                <span class="link-text">로그아웃<br><small>Logout</small></span>
            </a>
        </div>
    </div>

    <script>
    // 디버깅용 정보 출력
    console.log('모바일 메인 페이지 로드됨');
    console.log('User Agent:', navigator.userAgent);
    console.log('화면 크기:', window.innerWidth + 'x' + window.innerHeight);

    // 간단한 페이지 이동 함수
    function navigateToPage(url) {
        console.log('페이지 이동:', url);
        window.location.href = url;
    }

    // 터치 피드백 개선
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.function-card').forEach(card => {
            // 터치 이벤트
            card.addEventListener('touchstart', function(e) {
                if (this.style.cursor !== 'not-allowed') {
                    this.style.transform = 'translateY(-2px) scale(0.98)';
                    console.log('터치 시작:', this.href || this.className);
                }
            });
            
            card.addEventListener('touchend', function(e) {
                if (this.style.cursor !== 'not-allowed') {
                    this.style.transform = '';
                    console.log('터치 종료:', this.href || this.className);
                }
            });

            // 클릭 이벤트
            card.addEventListener('click', function(e) {
                console.log('클릭 이벤트 발생:', this.href || this.className);
                
                // 비활성화된 카드 확인
                if (this.classList.contains('disabled-card')) {
                    e.preventDefault();
                    console.log('비활성화된 카드 클릭');
                    return false;
                }
                
                // 링크가 있는 경우 정상 처리
                if (this.href && this.href !== '#') {
                    console.log('정상 링크 클릭:', this.href);
                    // 기본 링크 동작 허용
                } else {
                    console.log('링크가 없음');
                    e.preventDefault();
                }
            });

            // 마우스 이벤트 (데스크톱 브라우저 확인용)
            card.addEventListener('mouseenter', function() {
                console.log('마우스 진입:', this.href || this.className);
            });
        });

        // 페이지 로드 애니메이션
        const cards = document.querySelectorAll('.function-card');
        cards.forEach((card, index) => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            
            setTimeout(() => {
                card.style.transition = 'all 0.4s ease';
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, index * 100 + 200);
        });

        // 사용자 정보 및 권한 체크 (디버깅)
        console.log('현재 사용자:', '<?php echo $_SESSION['username'] ?? 'Unknown'; ?>');
        console.log('사용자 역할:', '<?php echo $_SESSION['role'] ?? 'Unknown'; ?>');
        console.log('점포 ID:', '<?php echo $current_store_id ?? 'Unknown'; ?>');
        console.log('점포명:', '<?php echo addslashes($current_store_name ?? 'Unknown'); ?>');
        
        // 권한 체크 결과 (디버깅)
        const permissions = {
            purchase_management: <?php echo has_permission('purchase_management') ? 'true' : 'false'; ?>
        };
        console.log('사용자 권한:', permissions);
    });

    // 에러 처리
    window.addEventListener('error', function(e) {
        console.error('JavaScript 오류:', e.error);
        console.error('파일:', e.filename);
        console.error('라인:', e.lineno);
    });

    // 링크 처리 실패시 대체 방법
    function forceNavigate(url) {
        console.log('강제 네비게이션:', url);
        try {
            window.location.assign(url);
        } catch(e) {
            console.error('네비게이션 실패:', e);
            window.location.href = url;
        }
    }
    </script>
</body>
</html>