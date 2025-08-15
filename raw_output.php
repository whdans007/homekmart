<?php
// Raw 출력 (헤더 함수 없이)
?>
Content-Type: application/json
Access-Control-Allow-Origin: *

{"message": "Raw output test", "php_version": "<?php echo phpversion(); ?>", "time": "<?php echo date('Y-m-d H:i:s'); ?>"}