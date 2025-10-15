<?php
// connect.php - Đơn giản, dùng chung cho api và vnpay handlers
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';         // sửa nếu bạn có mật khẩu
$DB_NAME = 'nike_store'; // sửa nếu tên DB khác

$conn = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($conn->connect_error) {
    file_put_contents(__DIR__ . '/vnpay_php/vnpay_debug.log', date('c') . " DB CONNECT ERROR: " . $conn->connect_error . PHP_EOL, FILE_APPEND);
    $conn = null;
} else {
    $conn->set_charset('utf8mb4');
}
