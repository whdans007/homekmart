<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>모바일 화면 크기 테스트</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            padding: 20px;
            margin: 0;
        }
        
        .info-box {
            background: #fffbe6;
            border: 2px solid #fadb14;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .info-box h2 {
            margin-top: 0;
            color: #d46b08;
        }
        
        .info-item {
            font-size: 18px;
            margin: 10px 0;
            padding: 10px;
            background: white;
            border-radius: 4px;
        }
        
        .label {
            font-weight: bold;
            color: #595959;
        }
        
        .value {
            color: #1890ff;
            font-size: 20px;
            font-weight: bold;
        }
        
        .media-test {
            margin-top: 20px;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            font-size: 20px;
            font-weight: bold;
        }
        
        .mobile-view {
            display: none;
            background: #52c41a;
            color: white;
        }
        
        .desktop-view {
            display: none;
            background: #1890ff;
            color: white;
        }
        
        @media screen and (max-width: 1024px) {
            .mobile-view {
                display: block;
            }
            .desktop-view {
                display: none;
            }
        }
        
        @media screen and (min-width: 1025px) {
            .mobile-view {
                display: none;
            }
            .desktop-view {
                display: block;
            }
        }
        
        .test-link {
            display: inline-block;
            margin-top: 30px;
            padding: 15px 30px;
            background: #722ed1;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-size: 18px;
        }
    </style>
</head>
<body>
    <div class="info-box">
        <h2>📱 갤럭시 폴드 6 화면 정보</h2>
        
        <div class="info-item">
            <span class="label">화면 너비:</span>
            <span class="value" id="screenWidth">계산중...</span>
        </div>
        
        <div class="info-item">
            <span class="label">화면 높이:</span>
            <span class="value" id="screenHeight">계산중...</span>
        </div>
        
        <div class="info-item">
            <span class="label">픽셀 비율:</span>
            <span class="value" id="pixelRatio">계산중...</span>
        </div>
        
        <div class="info-item">
            <span class="label">실제 화면 너비:</span>
            <span class="value" id="actualWidth">계산중...</span>
        </div>
        
        <div class="info-item">
            <span class="label">User Agent:</span>
            <div style="font-size: 14px; margin-top: 5px;" id="userAgent">계산중...</div>
        </div>
    </div>
    
    <div class="media-test mobile-view">
        ✅ 모바일 레이아웃 적용됨<br>
        (1024px 이하)
    </div>
    
    <div class="media-test desktop-view">
        💻 데스크톱 레이아웃 적용됨<br>
        (1025px 이상)
    </div>
    
    <a href="price_label_print.php" class="test-link">
        가격표 출력 페이지로 이동
    </a>
    
    <script>
        function updateInfo() {
            document.getElementById('screenWidth').textContent = window.innerWidth + 'px';
            document.getElementById('screenHeight').textContent = window.innerHeight + 'px';
            document.getElementById('pixelRatio').textContent = window.devicePixelRatio;
            document.getElementById('actualWidth').textContent = screen.width + 'px';
            document.getElementById('userAgent').textContent = navigator.userAgent;
        }
        
        // 페이지 로드 시 정보 업데이트
        updateInfo();
        
        // 화면 크기 변경 시 정보 업데이트
        window.addEventListener('resize', updateInfo);
        
        // 화면 방향 변경 시 정보 업데이트
        window.addEventListener('orientationchange', updateInfo);
    </script>
</body>
</html>