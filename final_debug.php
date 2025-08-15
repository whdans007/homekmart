<?php
// 최종 디버그 - 가장 간단한 형태
echo "Content-Type: application/json\r\n";
echo "Access-Control-Allow-Origin: *\r\n";
echo "\r\n";
echo '{"status":"working","php_version":"' . phpversion() . '","timestamp":"' . date('Y-m-d H:i:s') . '"}';
?>