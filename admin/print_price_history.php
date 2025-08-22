<?php
// 기본 설정
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 필수 파일 로드
require_once __DIR__ . '/../lib/lang_helper.php';
session_start();
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/permission_helper.php';

// 인증 및 권한 확인
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (!has_permission('purchase_management')) {
    echo '<script>alert("' . t('price_change.no_permission') . '"); window.close();</script>';
    exit;
}

// 변수 설정
$current_store_name = t('company.name');
$selected_date = $_GET['date'] ?? date('Y-m-d');

// Store 정보 가져오기 (선택적)
$current_store_id = $_SESSION['store_id'] ?? null;
if ($current_store_id) {
    try {
        $conn = get_db_connection();
        $stmt = $conn->prepare("SELECT name FROM stores WHERE id = ?");
        $stmt->bind_param("i", $current_store_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $current_store_name = $row['name'];
        }
        $stmt->close();
        $conn->close();
    } catch (Exception $e) {
        // 실패해도 기본값 유지
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo getCurrentLanguage(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('price_change.print_preview'); ?> - <?php echo t('company.name'); ?></title>
    <link href="../public/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @media screen {
            .print-container {
                max-width: 210mm;
                margin: 0 auto;
                padding: 20px;
                background: white;
                box-shadow: 0 0 10px rgba(0,0,0,0.1);
            }
            
            .no-print {
                display: block;
            }
        }
        
        @media print {
            * {
                -webkit-print-color-adjust: exact !important;
                color-adjust: exact !important;
            }
            
            body {
                margin: 0;
                padding: 0;
                background: white;
            }
            
            .print-container {
                max-width: none;
                margin: 0;
                padding: 0;
                box-shadow: none;
                page-break-inside: avoid;
            }
            
            .no-print {
                display: none !important;
            }
            
            .print-header {
                margin-bottom: 20px;
                border-bottom: 2px solid #000;
                padding-bottom: 10px;
            }
            
            table {
                width: 100%;
                border-collapse: collapse;
                font-size: 12px;
            }
            
            th, td {
                border: 1px solid #000;
                padding: 6px;
                text-align: left;
            }
            
            th {
                background-color: #f5f5f5;
                font-weight: bold;
            }
            
            .page-break {
                page-break-before: always;
            }
        }
        
        .date-navigation {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .nav-btn {
            padding: 8px 12px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        
        .nav-btn:hover {
            background: #0056b3;
        }
        
        .nav-btn:disabled {
            background: #6c757d;
            cursor: not-allowed;
        }
        
        .date-input {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .print-btn {
            padding: 10px 20px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            margin-left: 15px;
        }
        
        .print-btn:hover {
            background: #218838;
        }
        
        .loading {
            text-align: center;
            padding: 40px;
            font-size: 16px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="print-container">
        <!-- 인쇄 헤더 -->
        <div class="print-header">
            <div style="text-align: center; margin-bottom: 10px;">
                <h1 style="margin: 0; font-size: 24px; font-weight: bold;"><?php echo t('company.name'); ?></h1>
                <h2 style="margin: 5px 0; font-size: 18px;"><?php echo t('price_change.history'); ?></h2>
            </div>
            <div style="display: flex; justify-content: space-between; font-size: 14px;">
                <div>
                    <strong><?php echo t('common.store'); ?>:</strong> <?php echo htmlspecialchars($current_store_name); ?>
                </div>
                <div id="print-date-info">
                    <strong><?php echo t('common.date'); ?>:</strong> <span id="selected-date-display"><?php echo $selected_date; ?></span>
                </div>
                <div>
                    <strong><?php echo t('common.print_time'); ?>:</strong> <?php echo date('Y-m-d H:i:s'); ?>
                </div>
            </div>
        </div>
        
        <!-- 날짜 네비게이션 (화면에서만 표시) -->
        <div class="date-navigation no-print">
            <button type="button" class="nav-btn" onclick="goBack()" style="background: #6c757d;">
                <i class="fas fa-arrow-left"></i> <?php echo t('common.back'); ?>
            </button>
            
            <button type="button" class="nav-btn" onclick="changeDate(-1)">
                <i class="fas fa-chevron-left"></i> <?php echo t('common.previous_day'); ?>
            </button>
            
            <input type="date" id="date-picker" class="date-input" value="<?php echo $selected_date; ?>" onchange="loadDateData(this.value)">
            
            <button type="button" class="nav-btn" onclick="changeDate(1)">
                <?php echo t('common.next_day'); ?> <i class="fas fa-chevron-right"></i>
            </button>
            
            <button type="button" class="nav-btn" onclick="setToday()">
                <?php echo t('common.today'); ?>
            </button>
            
            <button type="button" class="print-btn" onclick="window.print()">
                <i class="fas fa-print"></i> <?php echo t('common.print'); ?>
            </button>
        </div>
        
        <!-- 데이터 표시 영역 -->
        <div id="data-container">
            <div class="loading">
                <i class="fas fa-spinner fa-spin"></i> <?php echo t('common.loading'); ?>...
            </div>
        </div>
    </div>
    
    <script>
        let currentDate = '<?php echo $selected_date; ?>';
        
        // 페이지 로드 시 데이터 로드
        document.addEventListener('DOMContentLoaded', function() {
            loadDateData(currentDate);
        });
        
        // 날짜 데이터 로드
        function loadDateData(date) {
            currentDate = date;
            document.getElementById('date-picker').value = date;
            document.getElementById('selected-date-display').textContent = date;
            
            const container = document.getElementById('data-container');
            container.innerHTML = '<div class="loading"><i class="fas fa-spinner fa-spin"></i> <?php echo t('common.loading'); ?>...</div>';
            
            fetch(`ajax_print_data.php?date=${date}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    return response.text();
                })
                .then(html => {
                    container.innerHTML = html;
                })
                .catch(error => {
                    console.error('Error:', error);
                    container.innerHTML = '<div style="text-align: center; color: red; padding: 40px;">데이터 로딩 실패: ' + error.message + '</div>';
                });
        }
        
        // 날짜 변경 (+1일 또는 -1일)
        function changeDate(days) {
            const date = new Date(currentDate);
            date.setDate(date.getDate() + days);
            const newDate = date.toISOString().split('T')[0];
            loadDateData(newDate);
        }
        
        // 오늘 날짜로 설정
        function setToday() {
            const today = new Date().toISOString().split('T')[0];
            loadDateData(today);
        }
        
        // 뒤로가기
        function goBack() {
            window.history.back();
        }
    </script>
</body>
</html>