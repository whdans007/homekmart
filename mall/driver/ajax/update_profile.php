<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../lib/driver.php';
require_once __DIR__ . '/../../lib/csrf.php';
$driver=mall_driver_current();
if(!$driver){http_response_code(401);echo json_encode(['success'=>false,'error'=>['message'=>'로그인이 필요합니다.']]);exit;}
if(!mall_csrf_verify($_POST['csrf_token']??'')){http_response_code(403);echo json_encode(['success'=>false,'error'=>['message'=>'요청이 만료되었습니다.']]);exit;}
$name=trim($_POST['name']??'');$phone=trim($_POST['phone']??'');$vehicle=trim($_POST['vehicle_info']??'');$password=$_POST['password']??'';
if($name===''||$phone===''||($password!==''&&strlen($password)<8)){http_response_code(422);echo json_encode(['success'=>false,'error'=>['message'=>'입력값을 확인해 주세요.']]);exit;}
try{$conn=mall_get_db_connection();$check=$conn->prepare('SELECT id FROM mall_drivers WHERE phone=? AND id<>?');$check->bind_param('si',$phone,$driver['id']);$check->execute();$duplicate=$check->get_result()->fetch_assoc();$check->close();
if($duplicate){http_response_code(409);echo json_encode(['success'=>false,'error'=>['message'=>'이미 등록된 연락처입니다.']]);exit;}
if($password!==''){$hash=password_hash($password,PASSWORD_DEFAULT);$stmt=$conn->prepare('UPDATE mall_drivers SET name=?,phone=?,vehicle_info=?,password_hash=? WHERE id=?');$stmt->bind_param('ssssi',$name,$phone,$vehicle,$hash,$driver['id']);}
else{$stmt=$conn->prepare('UPDATE mall_drivers SET name=?,phone=?,vehicle_info=? WHERE id=?');$stmt->bind_param('sssi',$name,$phone,$vehicle,$driver['id']);}
$stmt->execute();$stmt->close();echo json_encode(['success'=>true]);}catch(Throwable $e){error_log('driver update profile: '.$e->getMessage());http_response_code(500);echo json_encode(['success'=>false,'error'=>['message'=>'처리 중 오류가 발생했습니다.']]);}
