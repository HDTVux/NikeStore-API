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

    $sql = "SELECT id, email, username, password, gender, address, role, created_at, is_active
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
        if ($row['password'] === $password) {
            // Kiểm tra trạng thái is_active
            if ($row['is_active'] == 0) { // Giả định 0 là không hoạt động
                echo json_encode(["success" => false, "message" => "Tài khoản của bạn đã bị vô hiệu hóa. Vui lòng liên hệ quản trị viên."]);
                exit;
            }

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
            WHERE is_active = 1
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
// GET CATEGORIES
if ($action === 'get_categories') {
    $sql = "SELECT id, name FROM categories WHERE is_active=1 ORDER BY id ASC";
    $res = $conn->query($sql);
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
    echo json_encode(["success" => true, "data" => $rows, "meta" => ["count" => count($rows)]], JSON_UNESCAPED_UNICODE);
    exit;
}
// GET PRODUCTS BY CATEGORY with pagination
if ($action === 'get_products_by_category') {
    $category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $page_size = isset($_GET['page_size']) ? (int)$_GET['page_size'] : 20;
    $page_size = min(max(1, $page_size), 50);
    $offset = ($page - 1) * $page_size;
    $sql = "
      SELECT p.id, p.name, p.price,
        (SELECT image_url FROM product_images WHERE product_id = p.id AND is_main = 1 LIMIT 1) AS image_url
      FROM products p
      WHERE p.is_active = 1
    ";
    if ($category_id > 0) {
        $sql .= " AND p.category_id = ?";
    }
    $sql .= " ORDER BY p.created_at DESC LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    if ($category_id > 0) {
        $stmt->bind_param("iii", $category_id, $page_size, $offset);
    } else {
        $stmt->bind_param("ii", $page_size, $offset);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $items = [];
    while ($r = $res->fetch_assoc()) {
        $r['id'] = (int)$r['id'];
        $r['price'] = (float)$r['price'];
        $r['image_url'] = $r['image_url'] ? abs_url($r['image_url']) : '';
        $items[] = $r;
    }
    echo json_encode(["success" => true, "data" => $items, "pagination" => ["page"=>$page, "page_size"=>$page_size]], JSON_UNESCAPED_UNICODE);
    exit;
}

// GET PRODUCT DETAILS
if ($action === 'get_product_details') {
    $product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
    if ($product_id <= 0) {
        echo json_encode(["success"=>false,"message"=>"Missing product_id"]); exit;
    }

    // product
    $pstmt = $conn->prepare("SELECT id,name,description,price,stock,size_type FROM products WHERE id = ? AND is_active = 1 LIMIT 1");
    $pstmt->bind_param("i", $product_id);
    $pstmt->execute();
    $product = $pstmt->get_result()->fetch_assoc();
    if (!$product) { echo json_encode(["success"=>false,"message"=>"Product not found"]); exit; }

    // images: main first then others
    $imgs = [];
    $ipstmt = $conn->prepare("
       SELECT image_url, is_main
       FROM product_images
       WHERE product_id = ?
       ORDER BY is_main DESC, id ASC
    ");
    $ipstmt->bind_param("i", $product_id);
    $ipstmt->execute();
    $r = $ipstmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $imgs[] = $row['image_url'] ? abs_url($row['image_url']) : '';
    }

    // variants (sizes)
    $vars = [];
    $vpstmt = $conn->prepare("SELECT id, size, stock, price FROM product_variants WHERE product_id = ? ORDER BY id ASC");
    $vpstmt->bind_param("i", $product_id);
    $vpstmt->execute();
    $rv = $vpstmt->get_result();
    while ($vv = $rv->fetch_assoc()) {
        $vv['price'] = $vv['price'] === null ? null : (float)$vv['price'];
        $vars[] = $vv;
    }

    // cast types for consistency
    $product['id'] = (int)$product['id'];
    $product['price'] = (float)$product['price'];
    $product['images'] = $imgs;
    $product['variants'] = $vars;

    echo json_encode(["success"=>true,"product"=>$product], JSON_UNESCAPED_UNICODE);
    exit;
}

// GET PRODUCT REVIEWS
if ($action === 'get_product_reviews') {
    $product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
    if ($product_id <= 0) { echo json_encode(["success"=>false,"message"=>"Missing product_id"]); exit; }

    $sql = "
      SELECT r.id, r.user_id, u.username, r.rating, r.comment, r.created_at
      FROM reviews r
      LEFT JOIN users u ON u.id = r.user_id
      WHERE r.product_id = ?
      ORDER BY r.created_at DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $list = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $row['user_id'] = (int)$row['user_id'];
        $row['rating'] = (int)$row['rating'];
        $row['comment'] = $row['comment'] ?? '';
        $row['username'] = $row['username'] ?? 'Guest';
        $list[] = $row;
    }
    echo json_encode(["success" => true, "reviews" => $list], JSON_UNESCAPED_UNICODE);
    exit;
}

// ------------------ CART APIS ------------------
// Helpers: find or create cart id for user
function get_or_create_cart_id($conn, $user_id) {
    $q = $conn->prepare("SELECT id FROM cart WHERE user_id = ? LIMIT 1");
    $q->bind_param("i",$user_id);
    $q->execute();
    $r = $q->get_result()->fetch_assoc();
    if ($r) return (int)$r['id'];
    $ins = $conn->prepare("INSERT INTO cart(user_id) VALUES(?)");
    $ins->bind_param("i",$user_id);
    $ins->execute();
    return (int)$conn->insert_id;
}

// Add to cart (or increase quantity)
if ($action === 'add_to_cart') {
    $user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
    $variant_id = isset($_POST['variant_id']) && $_POST['variant_id'] !== '' ? (int)$_POST['variant_id'] : null;
    $quantity = isset($_POST['quantity']) ? max(1,(int)$_POST['quantity']) : 1;
    if ($user_id <= 0 || $product_id <= 0) {
        echo json_encode(["success"=>false,"message"=>"Missing params"]); exit;
    }
    $cart_id = get_or_create_cart_id($conn, $user_id);

    // find existing item
    if ($variant_id === null) {
        $chk = $conn->prepare("SELECT id, quantity FROM cart_items WHERE cart_id=? AND product_id=? AND variant_id IS NULL LIMIT 1");
        $chk->bind_param("ii", $cart_id, $product_id);
    } else {
        $chk = $conn->prepare("SELECT id, quantity FROM cart_items WHERE cart_id=? AND product_id=? AND variant_id=? LIMIT 1");
        $chk->bind_param("iii", $cart_id, $product_id, $variant_id);
    }
    $chk->execute();
    $exist = $chk->get_result()->fetch_assoc();
    if ($exist) {
        $newQty = (int)$exist['quantity'] + $quantity;
        $up = $conn->prepare("UPDATE cart_items SET quantity=? WHERE id=?");
        $up->bind_param("ii",$newQty, $exist['id']);
        $up->execute();
    } else {
        // insert (handle null variant)
        if ($variant_id === null) {
            $ins = $conn->prepare("INSERT INTO cart_items(cart_id, product_id, variant_id, quantity) VALUES(?,?,NULL,?)");
            $ins->bind_param("iii", $cart_id, $product_id, $quantity); // OK: variant is NULL in SQL text
        } else {
            $ins = $conn->prepare("INSERT INTO cart_items(cart_id, product_id, variant_id, quantity) VALUES(?,?,?,?)");
            $ins->bind_param("iiii", $cart_id, $product_id, $variant_id, $quantity);
        }
        $ins->execute();
    }
    echo json_encode(["success"=>true,"message"=>"Added to cart"]);
    exit;
}

// Get cart by user
if ($action === 'get_cart') {
    $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
    if ($user_id <= 0) { echo json_encode(["success"=>false,"message"=>"Missing user_id"]); exit; }
    $sql = "
      SELECT ci.id AS item_id, ci.quantity, ci.variant_id,
             p.id AS product_id, p.name AS product_name, p.price AS product_price,
             pv.size AS variant_size, pv.price AS variant_price,
             (SELECT image_url FROM product_images WHERE product_id=p.id AND is_main=1 LIMIT 1) AS image_url
      FROM cart c
      JOIN cart_items ci ON ci.cart_id = c.id
      JOIN products p ON p.id = ci.product_id
      LEFT JOIN product_variants pv ON pv.id = ci.variant_id
      WHERE c.user_id = ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $items = [];
    $total = 0.0;
    $count = 0;
    while ($r = $res->fetch_assoc()) {
        $price = $r['variant_price'] === null ? (float)$r['product_price'] : (float)$r['variant_price'];
        $qty = (int)$r['quantity'];
        $subtotal = $price * $qty;
        $total += $subtotal;
        $count += $qty;
        $r['image_url'] = $r['image_url'] ? abs_url($r['image_url']) : '';
        $r['price'] = $price;
        $r['subtotal'] = $subtotal;
        // cast numeric
        $r['product_id'] = (int)$r['product_id'];
        $r['variant_id'] = $r['variant_id'] !== null ? (int)$r['variant_id'] : null;
        $r['quantity'] = $qty;
        $items[] = $r;
    }
    echo json_encode(["success"=>true,"items"=>$items,"total"=>(float)$total,"count"=>$count], JSON_UNESCAPED_UNICODE);
    exit;
}

// Update cart item quantity (by item_id)
if ($action === 'update_cart_item') {
    $item_id = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
    $quantity = isset($_POST['quantity']) ? max(0,(int)$_POST['quantity']) : 0;
    if ($item_id <= 0) { echo json_encode(["success"=>false,"message"=>"Missing item_id"]); exit; }
    if ($quantity <= 0) {
        // remove
        $d = $conn->prepare("DELETE FROM cart_items WHERE id = ?");
        $d->bind_param("i",$item_id); $d->execute();
        echo json_encode(["success"=>true,"message"=>"Removed"]);
        exit;
    } else {
        $u = $conn->prepare("UPDATE cart_items SET quantity=? WHERE id=?");
        $u->bind_param("ii",$quantity, $item_id);
        $u->execute();
        echo json_encode(["success"=>true,"message"=>"Updated"]);
        exit;
    }
}

// Remove single item
if ($action === 'remove_cart_item') {
    $item_id = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
    if ($item_id <= 0) { echo json_encode(["success"=>false,"message"=>"Missing item_id"]); exit; }
    $d = $conn->prepare("DELETE FROM cart_items WHERE id = ?");
    $d->bind_param("i",$item_id); $d->execute();
    echo json_encode(["success"=>true,"message"=>"Removed"]);
    exit;
}

// Clear cart
if ($action === 'clear_cart') {
    $user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    if ($user_id <= 0) { echo json_encode(["success"=>false,"message"=>"Missing user_id"]); exit; }
    // find cart
    $q = $conn->prepare("SELECT id FROM cart WHERE user_id=? LIMIT 1");
    $q->bind_param("i",$user_id); $q->execute(); $r = $q->get_result()->fetch_assoc();
    if ($r) {
        $cart_id = (int)$r['id'];
        $d = $conn->prepare("DELETE FROM cart_items WHERE cart_id = ?");
        $d->bind_param("i",$cart_id); $d->execute();
    }
    echo json_encode(["success"=>true,"message"=>"Cleared"]);
    exit;
}

// MERGE CART: POST JSON { user_id: int, items: [ { product_id, variant_id?, quantity } ] }
if ($action === 'merge_cart') {
    // dùng helper read_param để hỗ trợ JSON body
    $user_id_raw = read_param('user_id');
    $items_raw = null;

    // try reading raw JSON array from body if read_param didn't find items
    // read_param supports JSON bodies, but be defensive:
    $rawJson = file_get_contents("php://input");
    if ($rawJson) {
        $parsed = json_decode($rawJson, true);
        if (is_array($parsed) && isset($parsed['items'])) {
            $items_raw = $parsed['items'];
        }
    }

    // fallback: try read_param for items if still null
    if ($items_raw === null) {
        $items_tmp = read_param('items');
        // if it's a JSON string, try decode
        if ($items_tmp !== '') {
            $decoded = json_decode($items_tmp, true);
            if (is_array($decoded)) $items_raw = $decoded;
        }
    }

    // finally try reading via read_param('user_id') converted to int
    $user_id = (int)$user_id_raw;

    if ($user_id <= 0) {
        echo json_encode(["success" => false, "message" => "Missing user_id"], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!is_array($items_raw) || count($items_raw) === 0) {
        echo json_encode(["success" => false, "message" => "Missing items"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Ensure cart exists for user (create if needed)
    $q = $conn->prepare("SELECT id FROM cart WHERE user_id = ? LIMIT 1");
    $q->bind_param("i", $user_id);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    if ($row) {
        $cart_id = (int)$row['id'];
    } else {
        $ins = $conn->prepare("INSERT INTO cart (user_id) VALUES (?)");
        $ins->bind_param("i", $user_id);
        $ins->execute();
        $cart_id = $conn->insert_id;
    }

    // For simplicity: upsert items by product_id + variant_id
    foreach ($items_raw as $it) {
        $prod = isset($it['product_id']) ? (int)$it['product_id'] : 0;
        $variant = isset($it['variant_id']) && $it['variant_id'] !== '' ? (int)$it['variant_id'] : null;
        $qty = isset($it['quantity']) ? max(1, (int)$it['quantity']) : 1;
        if ($prod <= 0) continue;

        if ($variant === null) {
            // check existing by product_id and variant_id IS NULL
            $s = $conn->prepare("SELECT id, quantity FROM cart_items WHERE cart_id = ? AND product_id = ? AND variant_id IS NULL LIMIT 1");
            $s->bind_param("ii", $cart_id, $prod);
        } else {
            $s = $conn->prepare("SELECT id, quantity FROM cart_items WHERE cart_id = ? AND product_id = ? AND variant_id = ? LIMIT 1");
            $s->bind_param("iii", $cart_id, $prod, $variant);
        }
        $s->execute();
        $ex = $s->get_result()->fetch_assoc();
        if ($ex) {
            $newQty = (int)$ex['quantity'] + $qty;
            $upd = $conn->prepare("UPDATE cart_items SET quantity = ? WHERE id = ?");
            $upd->bind_param("ii", $newQty, $ex['id']);
            $upd->execute();
        } else {
            $ins2 = $conn->prepare("INSERT INTO cart_items (cart_id, product_id, quantity, variant_id) VALUES (?, ?, ?, ?)");
            // variant_id can be null; when binding, pass null as i? bind_param doesn't accept null well; set to NULL or 0 and handle in SQL
            $variant_bind = $variant === null ? null : $variant;
            $ins2->bind_param("iiii", $cart_id, $prod, $qty, $variant_bind);
            $ins2->execute();
        }
    }

    echo json_encode(["success" => true, "message" => "Merged cart"], JSON_UNESCAPED_UNICODE);
    exit;
}

// ================== CREATE ORDER (COD / generic) ================== 
if ($action === 'create_order') {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);

    $raw = file_get_contents("php://input");
    $json = $raw ? json_decode($raw, true) : null;
    if (!$json) {
        echo json_encode(["success" => false, "message" => "Invalid JSON"]);
        exit;
    }

    $user_id = isset($json['user_id']) ? (int)$json['user_id'] : 0;
    $items   = $json['items'] ?? [];
    $address = trim($json['address'] ?? '');
    $phone   = isset($json['phone']) ? trim($json['phone']) : null; // <-- new
    $payment_method = isset($json['payment_method']) ? $json['payment_method'] : 'cash';

    if ($user_id <= 0 || !is_array($items) || empty($items) || $address === '') {
        echo json_encode(["success" => false, "message" => "Missing user_id, items, or address"]);
        exit;
    }

    $conn->begin_transaction();
    try {
        $subtotal = 0.0;
        $calculated_items = [];

        foreach ($items as $it) {
            $pid = (int)($it['product_id'] ?? 0);
            $vid = isset($it['variant_id']) && $it['variant_id'] !== null ? (int)$it['variant_id'] : null;
            $qty = max(0, (int)($it['quantity'] ?? 0));
            if ($pid <= 0 || $qty <= 0) throw new Exception("Invalid item data in order");

            $unit_price = 0.0;

            if ($vid) {
                $stmt_price = $conn->prepare("
                    SELECT pv.id AS vid, IFNULL(pv.price, p.price) AS unit_price, p.id AS product_id
                    FROM product_variants pv
                    JOIN products p ON pv.product_id = p.id
                    WHERE pv.id = ? LIMIT 1
                ");
                $stmt_price->bind_param("i", $vid);
                $stmt_price->execute();
                $price_res = $stmt_price->get_result();
                $price_row = $price_res->fetch_assoc();
                if (!$price_row) throw new Exception("Variant not found for id: " . $vid);
                $unit_price = (float)$price_row['unit_price'];
                $actual_product_id = (int)$price_row['product_id'];

                $u = $conn->prepare("UPDATE product_variants SET stock = stock - ? WHERE id = ? AND stock >= ?");
                $u->bind_param("iii", $qty, $vid, $qty);
                $u->execute();
                if ($u->affected_rows === 0) throw new Exception("Out of stock for variant id: " . $vid);

                // sync product stock from variants
                $sync = $conn->prepare("UPDATE products SET stock = (SELECT IFNULL(SUM(stock),0) FROM product_variants WHERE product_id = ?) WHERE id = ?");
                $sync->bind_param("ii", $actual_product_id, $actual_product_id);
                $sync->execute();

            } else {
                $stmt_price = $conn->prepare("SELECT price, stock FROM products WHERE id = ? LIMIT 1");
                $stmt_price->bind_param("i", $pid);
                $stmt_price->execute();
                $price_res = $stmt_price->get_result();
                $price_row = $price_res->fetch_assoc();
                if (!$price_row) throw new Exception("Product not found for id: " . $pid);
                $unit_price = (float)$price_row['price'];

                $u = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?");
                $u->bind_param("iii", $qty, $pid, $qty);
                $u->execute();
                if ($u->affected_rows === 0) throw new Exception("Out of stock for product ID: " . $pid);
            }

            $subtotal += $unit_price * $qty;
            $calculated_items[] = [
                'product_id' => $pid,
                'variant_id' => $vid,
                'quantity'   => $qty,
                'unit_price' => $unit_price
            ];
        }

        $shipping = ($subtotal > 100) ? 0.0 : 5.0;
        $total = $subtotal + $shipping;

        $ins = $conn->prepare("
            INSERT INTO orders (user_id, status, total_price, shipping_address, phone, payment_method, shipping_fee, subtotal)
            VALUES (?, 'pending', ?, ?, ?, ?, ?, ?)
        ");
        if (!$ins) throw new Exception("Prepare failed (orders insert): " . $conn->error);
        // types: i (user_id), d (total), s (address), s (phone), s (payment_method), d (shipping), d (subtotal)
        $ins->bind_param("idsssdd", $user_id, $total, $address, $phone, $payment_method, $shipping, $subtotal);
        if (!$ins->execute()) throw new Exception("Cannot create order: " . $ins->error);
        $order_id = $conn->insert_id;

        foreach ($calculated_items as $ci) {
            if ($ci['variant_id'] === null) {
                $stmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, variant_id, quantity, price) VALUES (?, ?, NULL, ?, ?)");
                $stmt->bind_param("iiid", $order_id, $ci['product_id'], $ci['quantity'], $ci['unit_price']);
            } else {
                $stmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, variant_id, quantity, price) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("iiiid", $order_id, $ci['product_id'], $ci['variant_id'], $ci['quantity'], $ci['unit_price']);
            }
            if (!$stmt->execute()) throw new Exception("Cannot add order item: " . $stmt->error);
        }

        $method = ($payment_method === 'vnpay') ? 'vnpay' : 'cash';
        $pstmt = $conn->prepare("INSERT INTO payments (order_id, payment_method, amount, status, created_at) VALUES (?, ?, ?, 'pending', NOW())");
        $pstmt->bind_param("isd", $order_id, $method, $total);
        if (!$pstmt->execute()) throw new Exception("Cannot create payment record: " . $pstmt->error);

        // clear user's cart (if exists)
        $q_cart = $conn->prepare("SELECT id FROM cart WHERE user_id = ? LIMIT 1");
        $q_cart->bind_param("i", $user_id);
        $q_cart->execute();
        $cart_res = $q_cart->get_result();
        if ($cart_row = $cart_res->fetch_assoc()) {
            $cart_id = (int)$cart_row['id'];
            $del_items = $conn->prepare("DELETE FROM cart_items WHERE cart_id = ?");
            $del_items->bind_param("i", $cart_id);
            $del_items->execute();
        }

        $conn->commit();

        echo json_encode([
            "success" => true,
            "message" => "Order created successfully",
            "order_id" => $order_id,
            "subtotal" => (float)$subtotal,
            "shipping" => (float)$shipping,
            "total" => (float)$total
        ], JSON_UNESCAPED_UNICODE);
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        $errorMessage = "DEBUG: " . $e->getMessage();
        echo json_encode([
            "success" => false,
            "message" => $errorMessage
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}



// ================== CREATE VNPay PAYMENT ==================
if ($action === 'create_vnpay_payment') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    $raw = file_get_contents("php://input");
    $json = $raw ? json_decode($raw, true) : null;
    if (!$json) { echo json_encode(["success" => false, "message" => "Invalid JSON"]); exit; }

    $user_id = isset($json['user_id']) ? (int)$json['user_id'] : 0;
    $items   = $json['items'] ?? [];
    $address = trim($json['address'] ?? '');
    $phone   = isset($json['phone']) ? trim($json['phone']) : null;

    if ($user_id <= 0 || !is_array($items) || empty($items) || $address === '') {
        echo json_encode(["success" => false, "message" => "Missing params"]); exit;
    }

    $conn->begin_transaction();
    try {
        $subtotal = 0.0;
        $calculated_items = [];

        foreach ($items as $it) {
            $pid = isset($it['product_id']) ? (int)$it['product_id'] : 0;
            $vid = isset($it['variant_id']) && $it['variant_id'] !== null ? (int)$it['variant_id'] : null;
            $qty = isset($it['quantity']) ? max(0,(int)$it['quantity']) : 0;
            if ($pid <= 0 || $qty <= 0) throw new Exception("Invalid item");

            if ($vid) {
                $s = $conn->prepare("SELECT pv.id as vid, pv.price as vprice, pv.stock as vstock, p.price as pprice, p.id as product_id 
                                     FROM product_variants pv 
                                     JOIN products p ON pv.product_id = p.id 
                                     WHERE pv.id = ? LIMIT 1");
                $s->bind_param("i", $vid);
                $s->execute();
                $row = $s->get_result()->fetch_assoc();
                if (!$row) throw new Exception("Variant not found");
                $unit_price = $row['vprice'] === null ? (float)$row['pprice'] : (float)$row['vprice'];

                $u = $conn->prepare("UPDATE product_variants SET stock = stock - ? WHERE id = ? AND stock >= ?");
                $u->bind_param("iii", $qty, $vid, $qty);
                $u->execute();
                if ($u->affected_rows === 0) throw new Exception("Out of stock or concurrent order for variant " . $vid);

                $productIdForSync = (int)$row['product_id'];
                $sync = $conn->prepare("UPDATE products SET stock = (SELECT IFNULL(SUM(stock),0) FROM product_variants WHERE product_id = ?) WHERE id = ?");
                $sync->bind_param("ii", $productIdForSync, $productIdForSync);
                $sync->execute();

            } else {
                $s = $conn->prepare("SELECT price, stock FROM products WHERE id = ? LIMIT 1");
                $s->bind_param("i", $pid);
                $s->execute();
                $row = $s->get_result()->fetch_assoc();
                if (!$row) throw new Exception("Product not found");
                $unit_price = (float)$row['price'];

                $u = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?");
                $u->bind_param("iii", $qty, $pid, $qty);
                $u->execute();
                if ($u->affected_rows === 0) throw new Exception("Out of stock or concurrent order for product " . $pid);
            }

            $subtotal += $unit_price * $qty;
            $calculated_items[] = [
                'product_id' => $pid,
                'variant_id' => $vid,
                'quantity'   => $qty,
                'unit_price' => $unit_price
            ];
        }

        $shipping = ($subtotal > 100) ? 0.0 : 5.0;
        $total = $subtotal + $shipping;

        $ins = $conn->prepare("INSERT INTO orders (user_id, status, total_price, shipping_address, phone, payment_method, shipping_fee, subtotal) VALUES (?, 'pending_payment', ?, ?, ?, 'vnpay', ?, ?)");
        if (!$ins) throw new Exception("Prepare failed (orders insert): " . $conn->error);
        // types: i (user_id), d (total), s (address), s (phone), d (shipping), d (subtotal)
        $ins->bind_param("idssdd", $user_id, $total, $address, $phone, $shipping, $subtotal);
        if (!$ins->execute()) throw new Exception("Cannot create order");
        $order_id = $conn->insert_id;

        foreach ($calculated_items as $ci) {
            if ($ci['variant_id'] === null) {
                $stmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, variant_id, quantity, price) VALUES (?, ?, NULL, ?, ?)");
                $stmt->bind_param("iiid", $order_id, $ci['product_id'], $ci['quantity'], $ci['unit_price']);
            } else {
                $stmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, variant_id, quantity, price) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("iiiid", $order_id, $ci['product_id'], $ci['variant_id'], $ci['quantity'], $ci['unit_price']);
            }
            if (!$stmt->execute()) throw new Exception("Cannot add order item: " . $stmt->error);
        }

        $pstmt = $conn->prepare("INSERT INTO payments (order_id, payment_method, amount, status, created_at) VALUES (?, 'vnpay', ?, 'pending', NOW())");
        $pstmt->bind_param("id", $order_id, $total);
        if (!$pstmt->execute()) throw new Exception("Cannot create payment record: " . $pstmt->error);

        $conn->commit();

        require_once __DIR__ . '/vnpay_php/config.php';
        $vnp_TmnCode    = VNPAY_TMN_CODE;
        $vnp_HashSecret = VNPAY_HASH_SECRET;
        $vnp_Url        = VNPAY_URL;
        $returnUrl      = VNPAY_RETURN_URL;

        $vnp_Params = [];
        $vnp_Params['vnp_Version']    = '2.1.0';
        $vnp_Params['vnp_Command']    = 'pay';
        $vnp_Params['vnp_TmnCode']    = $vnp_TmnCode;

        $USD_RATE = 25000;
        $vnp_Params['vnp_Amount'] = intval($total * $USD_RATE * 100);

        $vnp_Params['vnp_CreateDate'] = date('YmdHis');
        $vnp_Params['vnp_CurrCode']   = 'VND';
        $vnp_Params['vnp_IpAddr']     = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $vnp_Params['vnp_Locale']     = 'vn';
        $vnp_Params['vnp_OrderInfo']  = 'Thanh toan don hang #' . $order_id;
        $vnp_Params['vnp_OrderType']  = 'other';
        $vnp_Params['vnp_ReturnUrl']  = $returnUrl;
        $vnp_Params['vnp_TxnRef']     = $order_id . '_' . time();

        ksort($vnp_Params);
        $hashData = '';
        $i = 0;
        foreach ($vnp_Params as $key => $value) {
            if ($i == 1) $hashData .= '&';
            else $i = 1;
            $hashData .= urlencode($key) . "=" . urlencode($value);
        }
        $vnpSecureHash = hash_hmac('sha512', $hashData, $vnp_HashSecret);
        $vnp_Params['vnp_SecureHash'] = $vnpSecureHash;

        $vnp_UrlFull = $vnp_Url . '?' . http_build_query($vnp_Params);

        echo json_encode([
            "success" => true,
            "payment_url" => $vnp_UrlFull,
            "order_id" => $order_id
        ], JSON_UNESCAPED_UNICODE);
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        $errorMessage = "DEBUG: " . $e->getMessage();
        echo json_encode(["success" => false, "message" => $errorMessage], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ================== GET ORDER STATUS (used by app polling) ==================
if ($action === 'get_order_status') {
    header('Content-Type: application/json; charset=utf-8');

    $order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
    if ($order_id <= 0) {
        echo json_encode(["success" => false, "message" => "Missing order_id"]);
        exit;
    }

    $stmt = $conn->prepare("SELECT id, user_id, status, total_price, shipping_fee, subtotal, payment_method, shipping_address, phone, created_at FROM orders WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $order_id);
    if (!$stmt->execute()) {
        echo json_encode(["success" => false, "message" => "DB error"]);
        exit;
    }
    $order = $stmt->get_result()->fetch_assoc();
    if (!$order) {
        echo json_encode(["success" => false, "message" => "Order not found"]);
        exit;
    }

    // Lấy payment (latest) cho order
    $pstmt = $conn->prepare("SELECT id, order_id, payment_method, transaction_id, amount, status, created_at FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
    $pstmt->bind_param("i", $order_id);
    $pstmt->execute();
    $payment = $pstmt->get_result()->fetch_assoc();

    // Cast numeric strings
    if ($order) {
        $order['id'] = (int)$order['id'];
        $order['total_price'] = is_null($order['total_price']) ? null : (float)$order['total_price'];
        $order['shipping_fee'] = is_null($order['shipping_fee']) ? null : (float)$order['shipping_fee'];
        $order['subtotal'] = is_null($order['subtotal']) ? null : (float)$order['subtotal'];
    }
    if ($payment) {
        $payment['id'] = (int)$payment['id'];
        $payment['order_id'] = (int)$payment['order_id'];
        $payment['amount'] = is_null($payment['amount']) ? null : (float)$payment['amount'];
    }

    echo json_encode([
        "success" => true,
        "order" => $order,
        "payment" => $payment
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ================== SEARCH PRODUCTS ==================
// GET: api.php?action=search_products&q=...
if ($action === 'search_products') {
    $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
    $page = isset($_GET['page']) ? max(1,(int)$_GET['page']) : 1;
    $page_size = isset($_GET['page_size']) ? (int)$_GET['page_size'] : 50;
    $page_size = min(max(1,$page_size), 100);
    $offset = ($page - 1) * $page_size;

    if ($q === '') {
        echo json_encode(["success" => true, "products" => [], "pagination" => ["page"=>$page, "page_size"=>$page_size]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // NOTE: không dùng COLLATE ở đây — để MySQL dùng collation mặc định của cột.
    $sql = "
      SELECT p.id, p.name, p.price,
        (SELECT image_url FROM product_images WHERE product_id = p.id AND is_main = 1 LIMIT 1) AS image_url
      FROM products p
      WHERE p.is_active = 1
        AND p.name LIKE ?
      ORDER BY p.created_at DESC
      LIMIT ? OFFSET ?
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(["success"=>false,"message"=>"prepare failed: ".$conn->error], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $like = "%{$q}%";
    // bind types: s = string, i = int
    $stmt->bind_param("sii", $like, $page_size, $offset);
    $stmt->execute();
    $res = $stmt->get_result();
    $items = [];
    while ($r = $res->fetch_assoc()) {
        $r['id'] = (int)$r['id'];
        $r['price'] = (float)$r['price'];
        $r['image_url'] = $r['image_url'] ? (isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/' . ltrim($r['image_url'], '/') : '';
        $items[] = $r;
    }
    echo json_encode(["success"=>true, "products"=>$items, "pagination"=>["page"=>$page,"page_size"=>$page_size]], JSON_UNESCAPED_UNICODE);
    exit;
}

// ================== GET USER ORDERS (order history) ==================
if ($action === 'get_user_orders') {
    header('Content-Type: application/json; charset=utf-8');

    $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
    if ($user_id <= 0) {
        echo json_encode(["success" => false, "message" => "Missing user_id"]);
        exit;
    }

    // Lấy các đơn hàng của user (mới nhất trước)
    $stmt = $conn->prepare("SELECT id, status, total_price, shipping_fee, subtotal, payment_method, shipping_address, phone, created_at FROM orders WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->bind_param("i", $user_id);
    if (!$stmt->execute()) {
        echo json_encode(["success" => false, "message" => "DB error"]);
        exit;
    }
    $orders_res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $orders = [];
    foreach ($orders_res as $ord) {
        $orderId = (int)$ord['id'];

        // Lấy payment mới nhất cho đơn
        $pstmt = $conn->prepare("SELECT id, order_id, payment_method, transaction_id, amount, status, created_at FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
        $pstmt->bind_param("i", $orderId);
        $pstmt->execute();
        $payment = $pstmt->get_result()->fetch_assoc();

        // convert types
        $ord['id'] = (int)$ord['id'];
        $ord['total_price'] = is_null($ord['total_price']) ? null : (float)$ord['total_price'];
        $ord['shipping_fee'] = is_null($ord['shipping_fee']) ? null : (float)$ord['shipping_fee'];
        $ord['subtotal'] = is_null($ord['subtotal']) ? null : (float)$ord['subtotal'];

        if ($payment) {
            $payment['id'] = (int)$payment['id'];
            $payment['order_id'] = (int)$payment['order_id'];
            $payment['amount'] = is_null($payment['amount']) ? null : (float)$payment['amount'];
        }

        $orders[] = [
            "order" => $ord,
            "payment" => $payment
        ];
    }

    echo json_encode(["success" => true, "orders" => $orders], JSON_UNESCAPED_UNICODE);
    exit;
}

// ================== GET ORDER DETAIL ==================
if ($action === 'get_order_detail') {
    header('Content-Type: application/json; charset=utf-8');

    $order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
    $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0; // optional check
    if ($order_id <= 0) {
        echo json_encode(["success" => false, "message" => "Missing order_id"]);
        exit;
    }

    // Get order
    $stmt = $conn->prepare("SELECT id, user_id, status, total_price, shipping_fee, subtotal, payment_method, shipping_address, phone, created_at FROM orders WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $order_id);
    if (!$stmt->execute()) {
        echo json_encode(["success" => false, "message" => "DB error"]);
        exit;
    }
    $order = $stmt->get_result()->fetch_assoc();
    if (!$order) {
        echo json_encode(["success" => false, "message" => "Order not found"]);
        exit;
    }

    // If user_id provided, ensure ownership
    if ($user_id > 0 && (int)$order['user_id'] !== $user_id) {
        echo json_encode(["success" => false, "message" => "Forbidden"]);
        exit;
    }

    // Get items
    $it = $conn->prepare("SELECT oi.id, oi.product_id, oi.variant_id, oi.quantity, oi.price, p.name AS product_name
                          FROM order_items oi
                          LEFT JOIN products p ON oi.product_id = p.id
                          WHERE oi.order_id = ?");
    $it->bind_param("i", $order_id);
    $it->execute();
    $items = $it->get_result()->fetch_all(MYSQLI_ASSOC);

    // Get latest payment
    $pstmt = $conn->prepare("SELECT id, order_id, payment_method, transaction_id, amount, status, created_at FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
    $pstmt->bind_param("i", $order_id);
    $pstmt->execute();
    $payment = $pstmt->get_result()->fetch_assoc();

    // cast numeric types
    $order['id'] = (int)$order['id'];
    $order['total_price'] = is_null($order['total_price']) ? null : (float)$order['total_price'];
    $order['shipping_fee'] = is_null($order['shipping_fee']) ? null : (float)$order['shipping_fee'];
    $order['subtotal'] = is_null($order['subtotal']) ? null : (float)$order['subtotal'];

    if ($items) {
        foreach ($items as &$itrow) {
            $itrow['id'] = (int)$itrow['id'];
            $itrow['product_id'] = (int)$itrow['product_id'];
            $itrow['variant_id'] = is_null($itrow['variant_id']) ? null : (int)$itrow['variant_id'];
            $itrow['quantity'] = (int)$itrow['quantity'];
            $itrow['price'] = (float)$itrow['price'];
        }
        unset($itrow);
    } else {
        $items = [];
    }

    if ($payment) {
        $payment['id'] = (int)$payment['id'];
        $payment['order_id'] = (int)$payment['order_id'];
        $payment['amount'] = is_null($payment['amount']) ? null : (float)$payment['amount'];
    }

    echo json_encode([
        "success" => true,
        "order" => $order,
        "items" => $items,
        "payment" => $payment
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ================== GET PRODUCT REVIEW ==================
if ($action === 'get_product_review') {
    $user_id = (int)($_GET['user_id'] ?? 0);
    $product_id = (int)($_GET['product_id'] ?? 0);
    if ($user_id <= 0 || $product_id <= 0) die(json_encode(['success'=>false]));
    $stmt = $conn->prepare("SELECT * FROM reviews WHERE user_id = ? AND product_id = ? LIMIT 1");
    $stmt->bind_param("ii", $user_id, $product_id);
    $stmt->execute();
    $review = $stmt->get_result()->fetch_assoc();
    echo json_encode(['success'=>true, 'review'=>$review]);
    exit;
}
// POST: api.php?action=submit_review
if ($action === 'submit_review') {
    $raw = file_get_contents("php://input");
    $json = $raw ? json_decode($raw, true) : null;
    $user_id = (int)($json['user_id'] ?? 0);
    $product_id = (int)($json['product_id'] ?? 0);
    $rating = (int)($json['rating'] ?? 0);
    $comment = trim($json['comment'] ?? '');
    if ($user_id <= 0 || $product_id <= 0 || $rating < 1 || $rating > 5) die(json_encode(['success'=>false, 'message'=>'Invalid']));

    // Kiểm tra đã từng review chưa
    $stmt = $conn->prepare("SELECT id FROM reviews WHERE user_id = ? AND product_id = ?");
    $stmt->bind_param("ii", $user_id, $product_id);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();

    if ($exists) {
        // Nếu muốn update review cũ (không thì bỏ else)
        $stmt = $conn->prepare("UPDATE reviews SET rating=?, comment=?, created_at=NOW() WHERE id=?");
        $stmt->bind_param("isi", $rating, $comment, $exists['id']);
        $stmt->execute();
    } else {
        $stmt = $conn->prepare("INSERT INTO reviews (user_id, product_id, rating, comment, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param("iiis", $user_id, $product_id, $rating, $comment);
        $stmt->execute();
    }

    echo json_encode(['success'=>true]);
    exit;
}

// ================== GET USER PROFILE (by user_id) ==================
if ($action === 'get_user_profile') {
    header('Content-Type: application/json; charset=utf-8');
    $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
    if ($user_id <= 0) {
        echo json_encode(["success" => false, "message" => "Thiếu user_id"]);
        exit;
    }

    $stmt = $conn->prepare("SELECT id, email, username, gender, address, is_active, created_at, role FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $info = $stmt->get_result()->fetch_assoc();

    if ($info) {
        // Trả về thông tin user (không bao gồm mật khẩu)
        echo json_encode([
            "success" => true,
            "user" => $info
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            "success" => false,
            "message" => "Không tìm thấy user"
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ================== UPDATE USER PROFILE ==================
if ($action === 'update_user_profile') {
    header('Content-Type: application/json; charset=utf-8');
    $raw = file_get_contents("php://input");
    $json = $raw ? json_decode($raw, true) : null;
    if (!$json) {
        echo json_encode(["success" => false, "message" => "Invalid JSON"]);
        exit;
    }

    $user_id = isset($json['user_id']) ? (int)$json['user_id'] : 0;
    $username = trim($json['username'] ?? '');
    $address = trim($json['address'] ?? '');
    $gender = isset($json['gender']) ? (int)$json['gender'] : null;

    if ($user_id <= 0 || $username === '' || $gender === null) {
        echo json_encode(["success" => false, "message" => "Thiếu thông tin bắt buộc"]);
        exit;
    }

    // Nếu có validate thêm, check username độ dài, address hợp lệ v.v.

    $stmt = $conn->prepare("UPDATE users SET username = ?, address = ?, gender = ? WHERE id = ?");
    $stmt->bind_param("ssii", $username, $address, $gender, $user_id);
    if ($stmt->execute()) {
        // Lấy lại thông tin mới nhất trả về client
        $q = $conn->prepare("SELECT id, email, username, gender, address, created_at, role FROM users WHERE id = ? LIMIT 1");
        $q->bind_param("i", $user_id);
        $q->execute();
        $info = $q->get_result()->fetch_assoc();

        echo json_encode([
            "success" => true,
            "message" => "Cập nhật thành công",
            "user" => $info
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            "success" => false,
            "message" => "Cập nhật thất bại: " . $stmt->error
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}








// ============ Mặc định: action không hợp lệ ============
echo json_encode(["success" => false, "message" => "Invalid action"], JSON_UNESCAPED_UNICODE);
