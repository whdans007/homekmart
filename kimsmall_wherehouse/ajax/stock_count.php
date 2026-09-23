<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/stock_count_service.php';
header('Content-Type: application/json; charset=utf-8');
kw_require_staff();
try {
    $action=(string)($_POST['action']??$_GET['action']??'');
    $write=in_array($action,['start','save','finalize','cancel','void_entry','correct_entry'],true);
    if($write){if($_SERVER['REQUEST_METHOD']!=='POST')throw new InvalidArgumentException('POST required.');kw_verify_csrf();}
    elseif($_SERVER['REQUEST_METHOD']!=='GET')throw new InvalidArgumentException('GET required.');
    if(in_array($action,['start','finalize','cancel'],true) && !kw_is_admin()) { http_response_code(403); throw new RuntimeException('Administrator permission required.'); }
    $db=get_lc_db();$user=kw_current_user_id();
    $sid=filter_var($_POST['session_id']??$_GET['session_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
    switch($action){
      case 'status':$data=['session'=>kw_sc_open($db)];break;
      case 'sessions':$data=['sessions'=>kw_sc_sessions($db)];break;
      case 'start':$data=['session'=>kw_sc_start($db,$user,($_POST['inbound_closed_ack']??'')==='1')];break;
      case 'lookup':$data=['product'=>kw_sc_lookup($db,trim((string)($_GET['barcode']??'')),(string)($_GET['unit']??''))];break;
      case 'save':
        $pid=filter_var($_POST['product_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $qty=filter_var($_POST['quantity']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        if(!$sid||!$pid||$qty===false||$qty===null)throw new InvalidArgumentException('Invalid count entry.');
        kw_sc_save($db,$sid,$pid,trim((string)($_POST['barcode']??'')),(string)($_POST['unit']??''),$qty,$user);$data=['session_id'=>$sid];break;
      case 'void_entry':
        $entryId=filter_var($_POST['entry_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$sid||!$entryId) throw new InvalidArgumentException('Session and entry IDs required.');
        kw_sc_void_entry($db,$sid,$entryId,$user,kw_is_admin());$data=['session_id'=>$sid,'entry_id'=>$entryId];break;
      case 'correct_entry':
        $entryId=filter_var($_POST['entry_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $qty=filter_var($_POST['quantity']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        if (!$sid||!$entryId||$qty===false||$qty===null) throw new InvalidArgumentException('Session, entry, and quantity are required.');
        $newId=kw_sc_correct_entry($db,$sid,$entryId,$qty,$user,kw_is_admin());$data=['session_id'=>$sid,'entry_id'=>$entryId,'corrected_entry_id'=>$newId];break;
      case 'list':
        if(!$sid)throw new InvalidArgumentException('Session ID required.');
        $requestedUser=filter_var($_GET['user_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:null;
        $mine=($_GET['mine']??'')==='1';$filterUser=kw_is_admin()?($mine?$user:$requestedUser):$user;
        $data=kw_sc_list($db,$sid,$filterUser);break;
      case 'preview':
        if(!$sid)throw new InvalidArgumentException('Session ID required.');
        if(!kw_is_admin()){http_response_code(403);throw new RuntimeException('Administrator permission required.');}
        $data=kw_sc_list($db,$sid);break;
      case 'finalize':if(!$sid)throw new InvalidArgumentException('Session ID required.');$data=['session'=>kw_sc_finalize($db,$sid,$user)];break;
      case 'cancel':if(!$sid)throw new InvalidArgumentException('Session ID required.');$data=['session'=>kw_sc_cancel($db,$sid,$user)];break;
      default:throw new InvalidArgumentException('Unknown action.');
    }
    echo json_encode(['success'=>true]+$data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Throwable $e){
    http_response_code($e instanceof InvalidArgumentException?400:409);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
}
