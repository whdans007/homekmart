<?php
// favicon.ico 요청을 favicon.svg로 리다이렉트
if (strpos($_SERVER['REQUEST_URI'], 'favicon.ico') !== false) {
    header('HTTP/1.1 301 Moved Permanently');
    header('Location: favicon.svg');
    exit();
}
?>