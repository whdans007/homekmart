<?php
// 간단한 AJAX 테스트 파일
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html>
<html>
<head>
    <title>AJAX 테스트</title>
</head>
<body>
    <h1>상품 검색 AJAX 테스트</h1>
    <input type='text' id='searchTerm' placeholder='검색어 입력' value='test'>
    <button onclick='testAjax()'>검색 테스트</button>
    <div id='result'></div>

    <script>
    function testAjax() {
        const term = document.getElementById('searchTerm').value;
        console.log('검색 시작:', term);
        
        fetch('ajax_search_products.php?term=' + encodeURIComponent(term))
            .then(response => {
                console.log('응답 상태:', response.status);
                console.log('응답 헤더:', response.headers);
                return response.text();
            })
            .then(text => {
                console.log('응답 텍스트:', text);
                document.getElementById('result').innerHTML = '<pre>' + text + '</pre>';
                
                try {
                    const data = JSON.parse(text);
                    console.log('파싱된 데이터:', data);
                } catch (e) {
                    console.error('JSON 파싱 오류:', e);
                }
            })
            .catch(error => {
                console.error('네트워크 오류:', error);
                document.getElementById('result').innerHTML = '<p style=\"color: red;\">오류: ' + error.message + '</p>';
            });
    }
    </script>
</body>
</html>";
?>