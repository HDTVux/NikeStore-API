<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'C:\xampp\htdocs\api\PHPMailer-master\src\Exception.php';
require 'C:\xampp\htdocs\api\PHPMailer-master\src\PHPMailer.php';
require 'C:\xampp\htdocs\api\PHPMailer-master\src\SMTP.php';

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
function abs_url($url) {
    if (!$url) return '';
    $scheme = isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'http';
    return $scheme . '://' . $_SERVER['HTTP_HOST'] . '/' . ltrim($url, '/');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

// Ẩn warning/notice để khỏi phá JSON
ini_set('display_errors', 0);
error_reporting(0);

// ====== KẾT NỐI MYSQL (điền đúng thông tin InfinityFree) ======
// $host = "sql311.infinityfree.com";
// $user = "if0_40053342";
// $pass = "QCssxh9YqpDssM";
// $db   = "if0_40053342_project";

$host = "localhost";
$user = "root";
$pass = "";              
$db   = "nike_store";

$conn = @new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    echo json_encode(["success" => false, "message" => "DB connect fail"]); exit;
}
$conn->set_charset("utf8mb4");

// Helper: đọc param theo thứ tự ưu tiên JSON > POST > GET
function read_param($key) {
    // JSON body
    static $json = null;
    if ($json === null) {
        $raw = file_get_contents("php://input");
        $json = $raw ? json_decode($raw, true) : [];
        if (!is_array($json)) $json = [];
    }
    if (isset($json[$key])) return trim((string)$json[$key]);
    // POST
    if (isset($_POST[$key])) return trim((string)$_POST[$key]);
    // GET
    if (isset($_GET[$key]))  return trim((string)$_GET[$key]);
    return '';
}

$action = ($_GET['action'] ?? $_POST['action'] ?? '');

// ================== LOGIN: username + password ==================
if ($action === 'login') {
    $username = read_param('username');
    $password = read_param('password'); // plain theo DB của bạn

    if ($username === '' || $password === '') {
        echo json_encode(["success" => false, "message" => "Thiếu username hoặc password"]); exit;
    }

    $sql = "SELECT id, email, username, password, gender, address, role, created_at
            FROM users
            WHERE username = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(["success" => false, "message" => "prepare failed"]); exit;
    }
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $rs = $stmt->get_result();

    if ($row = $rs->fetch_assoc()) {
        // So khớp mật khẩu (plain). Nếu sau này dùng hash, đổi sang password_verify(...)
        if ($row['password'] === $password) {
            unset($row['password']); // không trả password về client
            echo json_encode([
                "success" => true,
                "message" => "Login successful",
                "user"    => $row
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(["success" => false, "message" => "Sai mật khẩu"]);
        }
    } else {
        echo json_encode(["success" => false, "message" => "Username không tồn tại"]);
    }
    exit;
}
// ================== REGISTER: email + username + password
if ($action === 'register') {
    $email    = read_param('email');
    $username = read_param('username');
    $password = read_param('password');
    $gender   = read_param('gender');   // optional: 0/1/2

    if ($email === '' || $username === '' || $password === '') {
        echo json_encode(["success"=>false,"message"=>"Thiếu email/username/password"]); exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(["success"=>false,"message"=>"Email không hợp lệ"]); exit;
    }
    if (strlen($password) < 6) {
        echo json_encode(["success"=>false,"message"=>"Mật khẩu tối thiểu 6 ký tự"]); exit;
    }

    $gVal = (int)$gender;
    if (!in_array($gVal, [0,1,2], true)) $gVal = 0;

    // Check trùng
    $q = $conn->prepare("SELECT id FROM users WHERE email=? OR username=?");
    $q->bind_param("ss", $email, $username);
    $q->execute();
    if ($q->get_result()->fetch_assoc()) {
        echo json_encode(["success"=>false,"message"=>"Email hoặc Username đã tồn tại"]); exit;
    }

    // INSERT KHÔNG có address -> để NULL
    $ins = $conn->prepare("INSERT INTO users(email, username, password, gender, role) VALUES (?,?,?,?, 'customer')");
    // types: s s s i
    $ins->bind_param("sssi", $email, $username, $password, $gVal);

    if (!$ins->execute()) {
        echo json_encode(["success"=>false,"message"=>"Không thể tạo tài khoản"]); exit;
    }

    $newId = $conn->insert_id;
    $u = $conn->prepare("SELECT id, email, username, gender, address, role, created_at FROM users WHERE id=?");
    $u->bind_param("i", $newId);
    $u->execute();
    $res = $u->get_result()->fetch_assoc();

    echo json_encode(["success"=>true,"message"=>"Đăng ký thành công","user"=>$res], JSON_UNESCAPED_UNICODE);
    exit;
}

// ================== REQUEST OTP: email ==================
if ($action === 'request_otp') {
    // Nhận email từ GET/POST cho tiện test và app
    $email = trim($_REQUEST['email'] ?? '');

    if ($email === '') {
        echo json_encode(["success" => false, "message" => "Thiếu email"]); exit;
    }

    // Tìm user theo email
    $q = $conn->prepare("SELECT id, username FROM users WHERE email=? LIMIT 1");
    $q->bind_param("s", $email);
    $q->execute();
    $user = $q->get_result()->fetch_assoc();

    if (!$user) {
        echo json_encode(["success"=>false,"message"=>"Email không tồn tại"]); exit;
    }

    // Sinh OTP 6 chữ số
    $otp = (string)rand(100000, 999999);

    // Xoá OTP cũ (nếu có) cho user này để chỉ còn 1 OTP hợp lệ
    $delOld = $conn->prepare("DELETE FROM user_otp WHERE user_id = ?");
    $delOld->bind_param("i", $user['id']);
    $delOld->execute();

    // Lưu OTP mới với hạn 5 phút, dùng UTC để đồng bộ so sánh
    $ins = $conn->prepare("
        INSERT INTO user_otp (user_id, otp_code, expires_at)
        VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE))
    ");
    $ins->bind_param("is", $user['id'], $otp);
    if (!$ins->execute()) {
        echo json_encode(["success"=>false,"message"=>"Không thể tạo OTP"]); exit;
    }

    // Gửi mail qua Gmail SMTP (PHPMailer)
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'group4.sportstore@gmail.com';    // Gmail của bạn
        $mail->Password = 'szzu twhw yayc spad';            // Mật khẩu ứng dụng (16 ký tự)
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom('group4.sportstore@gmail.com', 'Nike Store');
        $mail->addAddress($email, $user['username']);
        $mail->Subject = 'Mã OTP đặt lại mật khẩu - Nike Store';
        $mail->Body = "Xin chào {$user['username']},\n\nMã OTP của bạn là: {$otp}\nHiệu lực trong 5 phút.\n\nNike Store Support.";

        $mail->send();
        echo json_encode(["success"=>true,"message"=>"Đã gửi OTP đến email của bạn"]);
    } catch (Exception $e) {
        echo json_encode(["success"=>false,"message"=>"Lỗi gửi email: {$mail->ErrorInfo}"]);
    }
    exit;
}


// ================== VERIFY OTP: email + otp ==================
if ($action === 'verify_otp') {
    $email = $_POST['email'] ?? '';
    $otp   = $_POST['otp'] ?? '';

    if ($email === '' || $otp === '') {
        echo json_encode(["success"=>false,"message"=>"Thiếu email hoặc OTP"]); exit;
    }

    // Lấy user_id theo email
    $q = $conn->prepare("SELECT id FROM users WHERE email=?");
    $q->bind_param("s", $email);
    $q->execute();
    $user = $q->get_result()->fetch_assoc();
    if (!$user) { echo json_encode(["success"=>false,"message"=>"Email không tồn tại"]); exit; }

   $sql = "
  SELECT id FROM user_otp
  WHERE user_id = ? AND otp_code = ?
    AND expires_at > UTC_TIMESTAMP()   -- dùng UTC đồng bộ
  ORDER BY id DESC
  LIMIT 1
";
$st = $conn->prepare($sql);
$st->bind_param("is", $user['id'], $otp);
$st->execute();
$otpRow = $st->get_result()->fetch_assoc();

if ($otpRow) {
    echo json_encode(["success"=>true,"message"=>"OTP hợp lệ"]);
} else {
    echo json_encode(["success"=>false,"message"=>"OTP sai hoặc đã hết hạn"]);
}
exit;
}

// ================== RESET PASSWORD: email + otp + new_password ==================
if ($action === 'reset_password') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        echo json_encode(["success"=>false,"message"=>"Thiếu thông tin"]); exit;
    }

    // Lấy user
    $q = $conn->prepare("SELECT id FROM users WHERE email=?");
    $q->bind_param("s", $email);
    $q->execute();
    $user = $q->get_result()->fetch_assoc();
    if (!$user) { echo json_encode(["success"=>false,"message"=>"Email không tồn tại"]); exit; }

    $up = $conn->prepare("UPDATE users SET password=? WHERE id=?");
    $up->bind_param("si", $password, $user['id']);
    if (!$up->execute()) {
        echo json_encode(["success"=>false,"message"=>"Không thể cập nhật mật khẩu"]); exit;
    }

    // Xóa OTP cũ
    $del = $conn->prepare("DELETE FROM user_otp WHERE user_id=?");
    $del->bind_param("i", $user['id']);
    $del->execute();

    echo json_encode(["success"=>true,"message"=>"Đổi mật khẩu thành công"]);
    exit;
}
//done login, register,reset_password


// ================== GET BANNERS ==================
if ($action === 'get_banners') {
    $sql = "
        SELECT id, title, subtitle, image_url, deeplink
        FROM banners
        WHERE is_active = 1
        ORDER BY sort_order DESC, updated_at DESC, id DESC
        LIMIT 10
    ";
    $res = $conn->query($sql);
    $banners = [];
    while ($row = $res->fetch_assoc()) {
        // Nếu muốn trả URL đầy đủ (để Android hiển thị ảnh)
        $row['image_url'] = (isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'http')
            . '://' . $_SERVER['HTTP_HOST'] . '/' . ltrim($row['image_url'], '/');
        $banners[] = $row;
    }

    echo json_encode(["success" => true, "banners" => $banners], JSON_UNESCAPED_UNICODE);
    exit;
}

// ================== GET 4 NEW PRODUCTS ==================
if ($action === 'get_new_products') {
    $sql = "SELECT p.id, p.name, p.price,
                   (SELECT image_url FROM product_images 
                     WHERE product_id=p.id AND is_main=1 LIMIT 1) AS image_url
            FROM products p
            ORDER BY p.created_at DESC
            LIMIT 4";
    $rs = $conn->query($sql);
    if (!$rs) {
        echo json_encode(["success"=>false,"message"=>"SQL error: ".$conn->error]); exit;
    }

    $list = [];
    while ($row = $rs->fetch_assoc()) {
        $row['image_url'] = empty($row['image_url']) ? "" : abs_url($row['image_url']);
        // ép kiểu số cho gọn gàng
        $row['id']    = (int)$row['id'];
        $row['price'] = (float)$row['price'];
        $list[] = $row;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["success"=>true, "products"=>$list], JSON_UNESCAPED_UNICODE);
    exit;
}






// ============ Mặc định: action không hợp lệ ============
echo json_encode(["success" => false, "message" => "Invalid action"], JSON_UNESCAPED_UNICODE);
