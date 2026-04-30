<?php
/*
 * 데이터베이스 접속 정보
 */
define('DB_HOST', 'localhost');
define('DB_NAME', 'sunset');
define('DB_USER', 'sunset');
define('DB_PASS', '4x7wh]QLOPtGsNJO');
define('DB_CHARSET', 'utf8mb4');

/**
 * 데이터베이스 연결을 생성하고 반환합니다.
 * @return mysqli
 */
function get_db_connection() {
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    } catch (Exception $e) {
        error_log("DB connection failed: " . $e->getMessage());
        throw new Exception("데이터베이스 연결에 실패했습니다: " . $e->getMessage());
    }

    if ($conn->connect_error) {
        error_log("DB connect_error: " . $conn->connect_error);
        throw new Exception("데이터베이스 연결에 실패했습니다: " . $conn->connect_error);
    }

    $conn->set_charset(DB_CHARSET);

    return $conn;
}
?>