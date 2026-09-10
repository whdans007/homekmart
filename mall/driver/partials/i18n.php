<?php
function driver_lang() {
    $lang = $_GET['lang'] ?? ($_COOKIE['MALL_DRIVER_LANG'] ?? 'ko');
    if (!in_array($lang, ['ko', 'en'], true)) $lang = 'ko';
    if (isset($_GET['lang'])) setcookie('MALL_DRIVER_LANG', $lang, ['expires'=>time()+31536000,'path'=>'/mall/driver/','secure'=>true,'httponly'=>false,'samesite'=>'Lax']);
    return $lang;
}

function driver_i18n_ui() {
    $lang = driver_lang();
    $translations = [
        '배송기사'=>'Driver','드라이버 회원가입'=>'Driver Sign Up','로그인'=>'Log In','로그아웃'=>'Log Out','설정'=>'Settings','언어 설정'=>'Language',
        '드라이버 정보'=>'Driver Information','저장'=>'Save','저장되었습니다.'=>'Saved.','새 비밀번호 (변경할 때만 입력)'=>'New Password (only when changing)',
        '연락처'=>'Phone Number','비밀번호'=>'Password','비밀번호 확인'=>'Confirm Password','이름'=>'Name','차량 정보'=>'Vehicle Information',
        '가입 신청'=>'Submit Application','로그인으로 돌아가기'=>'Back to Login','근무 상태'=>'Work Status','근무 중'=>'On Duty','근무 종료'=>'Off Duty',
        '새 배달을 받을 수 있습니다.'=>'You can receive new deliveries.','새 배달 배정에서 제외됩니다.'=>'You will not receive new deliveries.',
        '진행중'=>'Active','완료'=>'Completed','배정됨'=>'Assigned','배송중'=>'Delivering','도착'=>'Arrived','배송완료'=>'Complete Delivery',
        '배송시작'=>'Start Delivery','배송실패'=>'Delivery Failed','배송실패 제출'=>'Submit Failure','배달목록'=>'Deliveries','배달 목록'=>'Delivery List',
        '채팅'=>'Chat','주문톡'=>'Order Chat','전송'=>'Send','메시지를 입력하세요'=>'Enter a message','읽음'=>'Read','고객'=>'Customer','매장'=>'Store','나'=>'Me',
        '고객 정보'=>'Customer Information','상품 목록'=>'Items','배송지'=>'Delivery Address','지도 길찾기'=>'Directions','위치 전송'=>'Send Location',
        '요청사항'=>'Request','랜드마크'=>'Landmark','배달료'=>'Delivery Fee','합계'=>'Total','취소'=>'Cancel','사유'=>'Reason',
        '배정된 배송이 없습니다.'=>'No assigned deliveries.','완료된 배송이 없습니다.'=>'No completed deliveries.','배정된 주문이 없습니다.'=>'No assigned orders.',
        '아직 대화가 없습니다'=>'No messages yet','대화를 시작해보세요'=>'Start the conversation','등록된 배송지가 없습니다'=>'No delivery address.',
        '가입 신청이 완료되었습니다. 관리자 승인 후 로그인할 수 있습니다.'=>'Application submitted. You can log in after administrator approval.',
        '관리자 승인 대기 중입니다.'=>'Waiting for administrator approval.','사용이 중지된 계정입니다. 관리자에게 문의해 주세요.'=>'This account is disabled. Contact the administrator.',
        '연락처 또는 비밀번호가 올바르지 않습니다.'=>'Incorrect phone number or password.','연락처와 비밀번호를 입력해 주세요.'=>'Enter your phone number and password.',
        '가입 신청 후 관리자의 승인을 받아야 로그인하고 배달을 받을 수 있습니다.'=>'Administrator approval is required before you can log in and receive deliveries.',
        '비밀번호 확인이 일치하지 않습니다.'=>'Passwords do not match.','이미 등록된 연락처입니다.'=>'This phone number is already registered.',
        '가입 처리 중 오류가 발생했습니다.'=>'An error occurred during sign up.','오류가 발생했습니다.'=>'An error occurred.','네트워크 오류가 발생했습니다.'=>'A network error occurred.',
        '로그인이 필요합니다'=>'Login is required','본인에게 배정된 주문이 아닙니다'=>'This order is not assigned to you','요청이 만료되었습니다'=>'The request has expired',
        '새로고침 후 다시 시도해주세요'=>'Refresh and try again','입력값을 확인해주세요'=>'Check the entered information','처리 중 오류가 발생했습니다'=>'An error occurred while processing',
        '처리할 수 없는 상태입니다'=>'This action is not available in the current status','배송중 상태가 아닙니다'=>'The order is not being delivered',
        '메시지는 500자 이내로 입력해주세요'=>'Enter a message of 500 characters or fewer','대상 주문을 찾을 수 없습니다'=>'The order could not be found',
        '배송 실패 사유를 입력해주세요'=>'Enter the delivery failure reason','실패 사유를 입력해주세요'=>'Enter a reason for the failure','전송에 실패했습니다.'=>'Failed to send.',
        '예: 오토바이 ABC-123'=>'Example: Motorcycle ABC-123'
    ];
    ?>
    <?php if ($lang === 'en'): ?><script>
    (function(){var map=<?php echo json_encode($translations, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
      function translate(root){var w=document.createTreeWalker(root,NodeFilter.SHOW_TEXT);var n,nodes=[];while(n=w.nextNode())nodes.push(n);nodes.forEach(function(x){if(x.parentElement&&/^(SCRIPT|STYLE)$/.test(x.parentElement.tagName))return;var raw=x.nodeValue,t=raw.trim();if(map[t])x.nodeValue=raw.replace(t,map[t]);else{var m=t.match(/^(진행중|완료) \((\d+)\)$/);if(m)x.nodeValue=raw.replace(t,map[m[1]]+' ('+m[2]+')');}});document.querySelectorAll('[placeholder]').forEach(function(e){if(map[e.placeholder])e.placeholder=map[e.placeholder];});document.documentElement.lang='en';}
      function start(){translate(document.body);new MutationObserver(function(ms){ms.forEach(function(m){m.addedNodes.forEach(function(n){if(n.nodeType===1)translate(n)})})}).observe(document.body,{childList:true,subtree:true});var oldAlert=window.alert;window.alert=function(v){oldAlert.call(window,map[String(v)]||v)}}
      if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);else start();
    })();</script><?php endif; ?>
    <?php
}
