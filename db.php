<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'td000310_travelb');
define('DB_PASS', 'cxrirslft7Ljftb');
define('DB_NAME', 'td000310_travelb');

function getDB() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        http_response_code(500);
        die(json_encode(['error' => 'Error de conexión: ' . $conn->connect_error]));
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}
?>
