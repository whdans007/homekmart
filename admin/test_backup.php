<?php
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true, 'message' => 'Test backup file access OK', 'file' => 'test_backup.php']);
