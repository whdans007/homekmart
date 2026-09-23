<?php
/** Serializes every warehouse mutation against opening and finalizing a count. Call inside a transaction. */
function kw_stock_count_lock(mysqli $db): ?int {
    $row = $db->query('SELECT open_session_id FROM kw_stock_count_control WHERE id=1 FOR UPDATE')->fetch_assoc();
    if (!$row) throw new RuntimeException('Stock count migration has not been installed.');
    return $row['open_session_id'] === null ? null : (int)$row['open_session_id'];
}
function kw_assert_no_open_stock_count(mysqli $db): void {
    if (kw_stock_count_lock($db) !== null) throw new RuntimeException('An inventory count is in progress. Inventory changes are blocked.');
}
function kw_sc_session(mysqli $db, int $id, bool $lock=false): array {
    $st=$db->prepare('SELECT * FROM kw_stock_count_sessions WHERE id=?'.($lock?' FOR UPDATE':''));
    $st->bind_param('i',$id); $st->execute(); $row=$st->get_result()->fetch_assoc(); $st->close();
    if (!$row) throw new RuntimeException('Inventory count was not found.');
    return $row;
}
function kw_sc_open(mysqli $db): ?array {
    $id=$db->query('SELECT open_session_id FROM kw_stock_count_control WHERE id=1')->fetch_row()[0] ?? null;
    return $id===null ? null : kw_sc_session($db,(int)$id);
}
function kw_sc_sessions(mysqli $db): array {
    return $db->query('SELECT * FROM kw_stock_count_sessions ORDER BY id DESC')->fetch_all(MYSQLI_ASSOC);
}
function kw_sc_start(mysqli $db,int $user,bool $inboundClosedAck): array {
    if (!$inboundClosedAck) throw new InvalidArgumentException('Confirm that inbound receiving is closed before starting inventory count.');
    $db->begin_transaction();
    try {
        if (kw_stock_count_lock($db)!==null) throw new RuntimeException('An inventory count is already open.');
        $st=$db->prepare('INSERT INTO kw_stock_count_sessions (opened_by,inbound_closed_ack_at,inbound_closed_ack_by) VALUES (?,NOW(),?)');
        $st->bind_param('ii',$user,$user); $st->execute(); $id=$db->insert_id; $st->close();
        $st=$db->prepare("INSERT INTO kw_stock_count_baseline (session_id,product_id,unit,quantity,pieces_per_box) SELECT ?,i.product_id,i.unit,SUM(i.quantity_remain),COALESCE(NULLIF(p.pieces_per_box,0),1) FROM kw_inventory i JOIN kw_products p ON p.id=i.product_id GROUP BY i.product_id,i.unit,p.pieces_per_box");
        $st->bind_param('i',$id); $st->execute(); $st->close();
        $st=$db->prepare('UPDATE kw_stock_count_control SET open_session_id=? WHERE id=1');
        $st->bind_param('i',$id); $st->execute(); $st->close();
        $db->commit(); return kw_sc_session($db,$id);
    } catch(Throwable $e){$db->rollback();throw $e;}
}
function kw_sc_product(mysqli $db,string $barcode): array {
    if ($barcode==='' || strlen($barcode)>100) throw new InvalidArgumentException('Enter a valid barcode.');
    $st=$db->prepare('SELECT id,name_ko,name_en,pieces_per_box,barcode_unit,barcode_box,barcode_logistics FROM kw_products WHERE is_active=1 AND (barcode_unit=? OR barcode_box=? OR barcode_logistics=?)');
    $st->bind_param('sss',$barcode,$barcode,$barcode); $st->execute(); $rows=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
    if (count($rows)!==1) throw new RuntimeException(count($rows) ? 'Barcode matches multiple products.' : 'Barcode was not found.');
    return $rows[0];
}
function kw_sc_unit(mysqli $db,int $session,int $product,string $requested): string {
    if ($requested==='PCS') return 'PCS';
    if ($requested!=='BOX') throw new InvalidArgumentException('Invalid unit.');
    $st=$db->prepare("SELECT unit,quantity,pieces_per_box FROM kw_stock_count_baseline WHERE session_id=? AND product_id=? AND unit IN ('BOX','PACK')");
    $st->bind_param('ii',$session,$product);$st->execute();$rows=$st->get_result()->fetch_all(MYSQLI_ASSOC);$st->close();
    $held=array_values(array_filter($rows,fn($r)=>(int)$r['quantity']!==0));
    if (count($held)>1) throw new RuntimeException('This product has both BOX and PACK stock; resolve its unit before counting.');
    if ($held) return $held[0]['unit'];
    $st=$db->prepare("SELECT DISTINCT unit FROM kw_inventory WHERE product_id=? AND unit IN ('BOX','PACK')");
    $st->bind_param('i',$product);$st->execute();$past=$st->get_result()->fetch_all(MYSQLI_ASSOC);$st->close();
    if (count($past)>1) throw new RuntimeException('This product has ambiguous BOX/PACK history.');
    return $past[0]['unit'] ?? 'BOX';
}
function kw_sc_validate_ppb(mysqli $db,int $product): void {
    $st=$db->prepare("SELECT COUNT(*) AS mismatches FROM kw_inventory i JOIN kw_inbound b ON b.id=i.inbound_id JOIN kw_products p ON p.id=i.product_id WHERE i.product_id=? AND i.quantity_remain>0 AND i.unit IN ('BOX','PACK') AND b.pieces_per_box<>p.pieces_per_box");
    $st->bind_param('i',$product);$st->execute();$n=(int)$st->get_result()->fetch_assoc()['mismatches'];$st->close();
    if($n)throw new RuntimeException('Bundle lot pack size differs from product pack size; resolve before counting.');
}
function kw_sc_lookup(mysqli $db,string $barcode,string $unit): array {
    $session=kw_sc_open($db); if (!$session) throw new RuntimeException('Start an inventory count first.');
    $p=kw_sc_product($db,$barcode); $id=(int)$p['id'];
    kw_sc_unit($db,(int)$session['id'],$id,$unit);kw_sc_validate_ppb($db,$id);
    $st=$db->prepare('SELECT unit,quantity FROM kw_stock_count_baseline WHERE session_id=? AND product_id=?');
    $sid=(int)$session['id'];$st->bind_param('ii',$sid,$id);$st->execute();$rows=$st->get_result()->fetch_all(MYSQLI_ASSOC);$st->close();
    $stock=array_column($rows,'quantity','unit');
    $p['book_box']=(int)($stock['BOX']??0)+(int)($stock['PACK']??0);$p['book_pcs']=(int)($stock['PCS']??0);
    return $p;
}
function kw_sc_save(mysqli $db,int $session,int $product,string $barcode,string $unit,int $quantity,int $user): void {
    if ($quantity<0 || $quantity>100000000) throw new InvalidArgumentException('Quantity must be a nonnegative integer.');
    $db->begin_transaction();try {
        if (kw_stock_count_lock($db)!==$session || kw_sc_session($db,$session,true)['status']!=='open') throw new RuntimeException('Inventory count is closed.');
        $p=kw_sc_product($db,$barcode);if ((int)$p['id']!==$product) throw new RuntimeException('Barcode and product do not match.');
        $actual=kw_sc_unit($db,$session,$product,$unit);kw_sc_validate_ppb($db,$product);$ppb=max(1,(int)$p['pieces_per_box']);
        $st=$db->prepare('INSERT INTO kw_stock_count_entries (session_id,product_id,barcode,unit,quantity,pieces_per_box,entered_by) VALUES (?,?,?,?,?,?,?)');
        $st->bind_param('iissiii',$session,$product,$barcode,$actual,$quantity,$ppb,$user);$st->execute();$st->close();
        $db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function kw_sc_void_entry(mysqli $db,int $session,int $entryId,int $user,bool $isAdmin): void {
    $db->begin_transaction();try {
        if (kw_stock_count_lock($db)!==$session || kw_sc_session($db,$session,true)['status']!=='open') throw new RuntimeException('Inventory count is closed.');
        $st=$db->prepare('SELECT entered_by,voided_at FROM kw_stock_count_entries WHERE id=? AND session_id=? FOR UPDATE');
        $st->bind_param('ii',$entryId,$session);$st->execute();$entry=$st->get_result()->fetch_assoc();$st->close();
        if (!$entry) throw new RuntimeException('Count entry was not found.');
        if ($entry['voided_at']!==null) throw new RuntimeException('Count entry is already voided.');
        if (!$isAdmin && (int)$entry['entered_by']!==$user) throw new RuntimeException('Only your own entries can be voided.');
        $st=$db->prepare("UPDATE kw_stock_count_entries SET voided_at=NOW(),voided_by=?,void_reason='cancel' WHERE id=?");
        $st->bind_param('ii',$user,$entryId);$st->execute();$st->close();$db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function kw_sc_correct_entry(mysqli $db,int $session,int $entryId,int $quantity,int $user,bool $isAdmin): int {
    if ($quantity<0 || $quantity>100000000) throw new InvalidArgumentException('Quantity must be a nonnegative integer.');
    $db->begin_transaction();try {
        if (kw_stock_count_lock($db)!==$session || kw_sc_session($db,$session,true)['status']!=='open') throw new RuntimeException('Inventory count is closed.');
        $st=$db->prepare('SELECT product_id,barcode,unit,quantity,pieces_per_box,entered_by,voided_at FROM kw_stock_count_entries WHERE id=? AND session_id=? FOR UPDATE');
        $st->bind_param('ii',$entryId,$session);$st->execute();$entry=$st->get_result()->fetch_assoc();$st->close();
        if (!$entry) throw new RuntimeException('Count entry was not found.');
        if ($entry['voided_at']!==null) throw new RuntimeException('Voided entries cannot be corrected.');
        if (!$isAdmin && (int)$entry['entered_by']!==$user) throw new RuntimeException('Only your own entries can be corrected.');
        if ((int)$entry['quantity']===$quantity) throw new InvalidArgumentException('Enter a quantity different from the current value.');
        $st=$db->prepare("UPDATE kw_stock_count_entries SET voided_at=NOW(),voided_by=?,void_reason='correction' WHERE id=?");
        $st->bind_param('ii',$user,$entryId);$st->execute();$st->close();
        $product=(int)$entry['product_id'];$barcode=(string)$entry['barcode'];$unit=(string)$entry['unit'];$ppb=(int)$entry['pieces_per_box'];
        $st=$db->prepare('INSERT INTO kw_stock_count_entries (session_id,product_id,barcode,unit,quantity,pieces_per_box,entered_by,corrected_from_entry_id) VALUES (?,?,?,?,?,?,?,?)');
        $st->bind_param('iissiiii',$session,$product,$barcode,$unit,$quantity,$ppb,$user,$entryId);$st->execute();$newId=(int)$db->insert_id;$st->close();
        $db->commit();return $newId;
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function kw_sc_line_metrics(array $book,array $count): array {
    $ppb=max(1,(int)($count['ppb']??$book['ppb']??1));
    $bb=(int)($book['BOX']??0)+(int)($book['PACK']??0);$bc=(int)($book['PCS']??0);
    $boxCounted=array_key_exists('BOX',$count)||array_key_exists('PACK',$count);
    $pcsCounted=array_key_exists('PCS',$count);
    $cb=$boxCounted?(int)($count['BOX']??0)+(int)($count['PACK']??0):$bb;
    $cc=$pcsCounted?(int)$count['PCS']:$bc;
    return ['book_box'=>$bb,'count_box'=>$cb,'delta_box'=>$cb-$bb,'book_pcs'=>$bc,'count_pcs'=>$cc,'delta_pcs'=>$cc-$bc,'book_total_pcs'=>$bb*$ppb+$bc,'count_total_pcs'=>$cb*$ppb+$cc,'delta_total_pcs'=>($cb-$bb)*$ppb+$cc-$bc,'pieces_per_box'=>$ppb,'box_counted'=>$boxCounted,'pcs_counted'=>$pcsCounted];
}
function kw_sc_list(mysqli $db,int $id,?int $enteredBy=null): array {
    $session=kw_sc_session($db,$id);
    $contributorsSt=$db->prepare("SELECT e.entered_by,COALESCE(NULLIF(u.full_name,''),u.username,CONCAT('User #',e.entered_by)) AS entered_by_name,COUNT(*) AS entry_count FROM kw_stock_count_entries e LEFT JOIN users u ON u.id=e.entered_by WHERE e.session_id=? AND e.voided_at IS NULL GROUP BY e.entered_by,u.full_name,u.username ORDER BY entered_by_name");
    $contributorsSt->bind_param('i',$id);$contributorsSt->execute();$contributors=$contributorsSt->get_result()->fetch_all(MYSQLI_ASSOC);$contributorsSt->close();
    $sql="SELECT e.*,p.name_ko,p.name_en,p.barcode_unit,p.barcode_box,p.barcode_logistics,COALESCE(NULLIF(u.full_name,''),u.username,CONCAT('User #',e.entered_by)) AS entered_by_name FROM kw_stock_count_entries e LEFT JOIN kw_products p ON p.id=e.product_id LEFT JOIN users u ON u.id=e.entered_by WHERE e.session_id=?";
    if($enteredBy!==null)$sql.=' AND e.entered_by=?';
    $sql.=' ORDER BY e.scanned_at,e.id';$st=$db->prepare($sql);
    if($enteredBy===null)$st->bind_param('i',$id);else $st->bind_param('ii',$id,$enteredBy);
    $st->execute();$entries=$st->get_result()->fetch_all(MYSQLI_ASSOC);$st->close();
    $st=$db->prepare('SELECT b.*,p.name_ko,p.name_en FROM kw_stock_count_baseline b LEFT JOIN kw_products p ON p.id=b.product_id WHERE b.session_id=?');
    $st->bind_param('i',$id);$st->execute();$baseline=$st->get_result()->fetch_all(MYSQLI_ASSOC);$st->close();
    $books=[];foreach($baseline as $b){$pid=(int)$b['product_id'];$books[$pid][$b['unit']]=(int)$b['quantity'];$books[$pid]['ppb']=(int)$b['pieces_per_box'];}
    $totals=[];$dates=[];
    foreach($entries as $e){
        $pid=(int)$e['product_id'];$u=$e['unit'];$name=trim(($e['name_ko']?:'').' / '.($e['name_en']?:''),' /') ?: 'Product #'.$pid;
        if ($e['voided_at']===null) {
            $totals[$pid][$u]=($totals[$pid][$u]??0)+(int)$e['quantity'];
            $totals[$pid]['name']=$name;$totals[$pid]['name_ko']=$e['name_ko']??'';$totals[$pid]['name_en']=$e['name_en']??'';
            $totals[$pid]['sku']=$e['barcode_unit']?:($e['barcode_box']?:($e['barcode_logistics']?:$e['barcode']));$totals[$pid]['ppb']=(int)$e['pieces_per_box'];
        }
        $sku=$e['barcode_unit']?:($e['barcode_box']?:($e['barcode_logistics']?:$e['barcode']));
        $date=substr($e['scanned_at'],0,10);$dates[$date][]=['id'=>(int)$e['id'],'product_id'=>$pid,'sku'=>$sku,'product_name_ko'=>$e['name_ko']??'','product_name_en'=>$e['name_en']??'','entered_by'=>(int)$e['entered_by'],'entered_by_name'=>$e['entered_by_name'],'scanned_at'=>$e['scanned_at'],'product_name'=>$name,'unit'=>$u,'quantity'=>(int)$e['quantity'],'barcode'=>$e['barcode'],'voided'=>$e['voided_at']!==null,'voided_at'=>$e['voided_at'],'voided_by'=>$e['voided_by']===null?null:(int)$e['voided_by'],'void_reason'=>$e['void_reason']??null,'corrected_from_entry_id'=>$e['corrected_from_entry_id']===null?null:(int)$e['corrected_from_entry_id']];
    }
    $lines=[];foreach($totals as $pid=>$t){
        $lines[]=['product_id'=>$pid,'sku'=>$t['sku'],'product_name'=>$t['name'],'product_name_ko'=>$t['name_ko'],'product_name_en'=>$t['name_en']]+kw_sc_line_metrics($books[$pid]??[],$t);
    }
    $grouped=[];foreach($dates as $date=>$items)$grouped[]=['date'=>$date,'entries'=>$items];
    $active=(int)$db->query('SELECT COUNT(*) FROM kw_products WHERE is_active=1')->fetch_row()[0];
    $uncounted=max(0,$active-count($totals));
    return ['session'=>$session,'lines'=>$lines,'dates'=>$grouped,'contributors'=>$contributors,'selected_user_id'=>$enteredBy,'uncounted_count'=>$uncounted];
}
function kw_sc_adjust(mysqli $db,int $sid,int $pid,string $unit,int $target): void {
    $st=$db->prepare('SELECT id,quantity_remain FROM kw_inventory WHERE product_id=? AND unit=? ORDER BY (expiry_date IS NULL),expiry_date,id FOR UPDATE');
    $st->bind_param('is',$pid,$unit);$st->execute();$lots=$st->get_result()->fetch_all(MYSQLI_ASSOC);$st->close();
    $current=array_sum(array_map(fn($r)=>(int)$r['quantity_remain'],$lots));$delta=$target-$current;
    if ($delta<0){
        $needed=-$delta;
        foreach($lots as $lot){if($needed===0)break;$before=(int)$lot['quantity_remain'];if($before<=0)continue;
            $take=min($needed,$before);$iid=(int)$lot['id'];$st=$db->prepare('UPDATE kw_inventory SET quantity_out=quantity_out+? WHERE id=?');$st->bind_param('ii',$take,$iid);$st->execute();$st->close();
            kw_sc_record_adjustment($db,$sid,$pid,$unit,$iid,$before,$before-$take);$needed-=$take;
        }
        if($needed)throw new RuntimeException('Inventory changed during count.');
    }elseif($delta>0){
            $st=$db->prepare('SELECT COALESCE(NULLIF(pieces_per_box,0),1) FROM kw_products WHERE id=?');$st->bind_param('i',$pid);$st->execute();$ppb=(int)$st->get_result()->fetch_row()[0];$st->close();
            $lot='SC-'.$sid.'-'.$pid.'-'.$unit;$today=date('Y-m-d');
            $st=$db->prepare('SELECT cost_price,cost_price_pcs FROM kw_inbound WHERE product_id=? AND inbound_unit=? ORDER BY id DESC LIMIT 1');
            $st->bind_param('is',$pid,$unit);$st->execute();$costRow=$st->get_result()->fetch_assoc();$st->close();
            $cost=$costRow?(float)$costRow['cost_price']:0.0;$costPcs=$costRow?(float)$costRow['cost_price_pcs']:0.0;
            $note='Inventory count adjustment #'.$sid.($costRow?' (cost copied from latest same-unit inbound)':' (no cost history; cost 0)');
            $st=$db->prepare('INSERT INTO kw_inbound (inbound_date,product_id,lot_number,quantity,inbound_unit,pieces_per_box,cost_price,cost_price_pcs,notes) VALUES (?,?,?,?,?,?,?,?,?)');
            $st->bind_param('sisisidds',$today,$pid,$lot,$delta,$unit,$ppb,$cost,$costPcs,$note);$st->execute();$inbound=$db->insert_id;$st->close();
            $st=$db->prepare('INSERT INTO kw_inventory (inbound_id,product_id,unit,lot_number,quantity_in,quantity_out) VALUES (?,?,?,?,?,0)');$st->bind_param('iissi',$inbound,$pid,$unit,$lot,$delta);$st->execute();$iid=$db->insert_id;$st->close();$before=0;
        kw_sc_record_adjustment($db,$sid,$pid,$unit,$iid,$before,$before+$delta);
    }
}
function kw_sc_record_adjustment(mysqli $db,int $sid,int $pid,string $unit,int $iid,int $before,int $after): void {
    $delta=$after-$before;$st=$db->prepare('INSERT INTO kw_stock_count_adjustments (session_id,product_id,unit,inventory_id,before_quantity,after_quantity,delta) VALUES (?,?,?,?,?,?,?)');
    $st->bind_param('iisiiii',$sid,$pid,$unit,$iid,$before,$after,$delta);$st->execute();$st->close();
}
function kw_sc_finalize(mysqli $db,int $id,int $user): array {
    $db->begin_transaction();try {
        if(kw_stock_count_lock($db)!==$id)throw new RuntimeException('Inventory count is already closed.');
        if(kw_sc_session($db,$id,true)['status']!=='open')throw new RuntimeException('Inventory count is already finalized.');
        $list=kw_sc_list($db,$id);if(!$list['lines'])throw new RuntimeException('There are no counted products.');
        $st=$db->prepare('SELECT product_id,unit,SUM(quantity) AS quantity FROM kw_stock_count_baseline WHERE session_id=? GROUP BY product_id,unit');$st->bind_param('i',$id);$st->execute();$base=$st->get_result()->fetch_all(MYSQLI_ASSOC);$st->close();
        $baseline=[];foreach($base as $b)$baseline[(int)$b['product_id']][$b['unit']]=(int)$b['quantity'];
        $st=$db->prepare('SELECT product_id,unit,SUM(quantity) AS quantity FROM kw_stock_count_entries WHERE session_id=? AND voided_at IS NULL GROUP BY product_id,unit');$st->bind_param('i',$id);$st->execute();$counts=$st->get_result()->fetch_all(MYSQLI_ASSOC);$st->close();
        $validated=[];
        foreach($counts as $c){$pid=(int)$c['product_id'];$unit=$c['unit'];$target=(int)$c['quantity'];
            if(!isset($validated[$pid])){kw_sc_validate_ppb($db,$pid);$validated[$pid]=true;}
            if($target>2147483647)throw new RuntimeException('Count exceeds inventory limit.');
            $st=$db->prepare('SELECT COALESCE(SUM(quantity_remain),0) FROM kw_inventory WHERE product_id=? AND unit=?');$st->bind_param('is',$pid,$unit);$st->execute();$now=(int)$st->get_result()->fetch_row()[0];$st->close();
            if($now!==($baseline[$pid][$unit]??0))throw new RuntimeException('Inventory changed since count began. Finalization stopped.');
            kw_sc_adjust($db,$id,$pid,$unit,$target);
        }
        $st=$db->prepare("UPDATE kw_stock_count_sessions SET status='finalized',finalized_at=NOW(),finalized_by=? WHERE id=?");$st->bind_param('ii',$user,$id);$st->execute();$st->close();
        $db->query('UPDATE kw_stock_count_control SET open_session_id=NULL WHERE id=1');$db->commit();return kw_sc_session($db,$id);
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function kw_sc_cancel(mysqli $db,int $id,int $user): array {
    $db->begin_transaction();try {
        if(kw_stock_count_lock($db)!==$id || kw_sc_session($db,$id,true)['status']!=='open')throw new RuntimeException('Inventory count is already closed.');
        $st=$db->prepare("UPDATE kw_stock_count_sessions SET status='cancelled',cancelled_at=NOW(),cancelled_by=? WHERE id=?");
        $st->bind_param('ii',$user,$id);$st->execute();$st->close();
        $db->query('UPDATE kw_stock_count_control SET open_session_id=NULL WHERE id=1');
        $db->commit();return kw_sc_session($db,$id);
    }catch(Throwable $e){$db->rollback();throw $e;}
}
