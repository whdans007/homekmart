<?php
/**
 * 주문 생성 테스트 스크립트
 */

$url = 'https://192-168-1-123.philsarang.direct.quickconnect.to/homekmart/api/orders/create.php';

$data = [
    'user_id' => 13,
    'delivery_address_id' => 1,
    'payment_method' => 'cod',
    'cod_amount' => 100,
    'special_instructions' => 'Please ring the doorbell'
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

header('Content-Type: application/json; charset=UTF-8');
echo json_encode([
    'http_code' => $http_code,
    'response' => json_decode($response, true) ?? $response
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
