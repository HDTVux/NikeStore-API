<?php
// ===================== DATABASE CONNECTION =====================
$host = "localhost";         // hoặc host của InfinityFree nếu bạn deploy
$user = "root";              // user MySQL
$pass = "";                  // password MySQL
$db   = "nike_store";        // tên database

$conn = @new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die(json_encode(["success" => false, "message" => "Database connection failed: " . $conn->connect_error]));
}
$conn->set_charset("utf8mb4");


// ===================== OPENAI (Tùy chọn) =====================
// Nếu bạn còn sử dụng OpenAI cho gợi ý sản phẩm
define('OPENAI_API_KEY', ''); // để trống nếu chỉ dùng Gemini

// ===================== GEMINI (Google Generative AI) =====================
// ⚠️ KHÔNG chia sẻ key này công khai, nếu public GitHub hãy ẩn file này hoặc dùng biến môi trường
define('GEMINI_API_KEY', 'AIzaSyALpvUz4xeddmSwNVbCJVG4qhh3KGLGPi8');


// ===================== COMMON HELPER FUNCTIONS =====================
function abs_url($url) {
    if (!$url) return '';
    $scheme = isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'http';
    return $scheme . '://' . $_SERVER['HTTP_HOST'] . '/' . ltrim($url, '/');
}
?>
