<?php
/*
 * 데이터베이스 접속 정보
 */
define('DB_HOST', 'localhost');
define('DB_NAME', 'min1234');
define('DB_USER', 'min1234');
define('DB_PASS', 'Min1234****');
define('DB_CHARSET', 'utf8mb4');

/**
 * 데이터베이스 연결을 생성하고 반환합니다.
 * @return mysqli
 */
function get_db_connection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    $conn->set_charset(DB_CHARSET);

    return $conn;
}
?>