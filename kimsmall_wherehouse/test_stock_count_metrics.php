<?php
// Pure stock count arithmetic test; no database writes.
require_once __DIR__ . '/lib/stock_count_service.php';
function check(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
$line=kw_sc_line_metrics(['BOX'=>2,'PCS'=>1,'ppb'=>12],['BOX'=>3,'PCS'=>5,'ppb'=>12]);
check($line['count_total_pcs']===41,'3 boxes + 5 pieces must be 41 pieces');
check($line['book_total_pcs']===25 && $line['delta_total_pcs']===16,'Baseline and difference');
$pack=kw_sc_line_metrics(['PACK'=>2,'PCS'=>1,'ppb'=>12],['PACK'=>3,'PCS'=>5,'ppb'=>12]);
check($pack['count_box']===3 && $pack['count_total_pcs']===41,'PACK displays as boxes');
$zero=kw_sc_line_metrics(['BOX'=>2,'PCS'=>7,'ppb'=>12],['BOX'=>0,'ppb'=>12]);
check($zero['count_box']===0 && $zero['count_pcs']===7 && $zero['delta_total_pcs']===-24,'Explicit zero and untouched PCS');
$repeated=kw_sc_line_metrics(['BOX'=>1,'ppb'=>12],['BOX'=>2+3,'ppb'=>12]);
check($repeated['count_box']===5,'Repeated scans aggregate');
echo "Stock count arithmetic passed.\n";
