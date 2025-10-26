<?php
// vnpay_return.php - robust include + verify + update DB + clear cart on success
date_default_timezone_set('Asia/Ho_Chi_Minh');

// --- Try load config and DB connection from several likely locations ---
$loaded = false;
$base = __DIR__;

$candidates = [
    $base . '/config.php',
    $base . '/../config.php',
    $base . '/../../config.php',
    $base . '/../vnpay_php/config.php',
];

foreach ($candidates as $c) {
    if (file_exists($c)) {
        require_once $c;
        $loaded = true;
        break;
    }
}

$connected = false;
$connectCandidates = [
    $base . '/../connect.php',
    $base . '/connect.php',
    $base . '/../../connect.php',
    $base . '/../api/connect.php',
    $base . '/../../../connect.php',
];

foreach ($connectCandidates as $c) {
    if (file_exists($c)) {
        require_once $c;
        $connected = true;
        file_put_contents($base . '/vnpay_debug.log', date('c') . " RETURN - using connect.php: $c" . PHP_EOL, FILE_APPEND);
        break;
    }
}

if (!isset($conn) || !$connected) {
    file_put_contents($base . '/vnpay_debug.log', date('c') . " RETURN - Missing DB connection. Tried: " . json_encode($connectCandidates) . PHP_EOL, FILE_APPEND);
    header('Content-Type: text/html; charset=utf-8');
    echo "<html><body><h3>Server configuration error (DB connection not found).</h3><p>Contact admin.</p></body></html>";
    exit;
}

// --- Log incoming GET params for debugging ---
file_put_contents($base . '/vnpay_debug.log', date('c') . " RETURN CALLED: " . json_encode($_GET) . PHP_EOL, FILE_APPEND);

// Filter vnp_ params
$inputData = [];
foreach ($_GET as $k => $v) {
    if (strpos($k, 'vnp_') === 0) $inputData[$k] = (string)$v;
}

$vnp_SecureHash = $inputData['vnp_SecureHash'] ?? '';
unset($inputData['vnp_SecureHash']);

// Build hash
ksort($inputData);
$hashData = '';
$i = 0;
foreach ($inputData as $key => $value) {
    if ($i == 1) $hashData .= '&';
    else $i = 1;
    $hashData .= urlencode($key) . '=' . urlencode($value);
}
$ourHash = hash_hmac('sha512', $hashData, defined('VNPAY_HASH_SECRET') ? VNPAY_HASH_SECRET : '');

// Deep link redirect
$appScheme = 'app://vnpay-return';
$appQuery = http_build_query($_GET);
$appUrl = $appScheme . ($appQuery ? ('?' . $appQuery) : '');

$orderRef = $inputData['vnp_TxnRef'] ?? ($_GET['vnp_TxnRef'] ?? '');
$orderId = 0;
if ($orderRef !== '') {
    $parts = explode('_', $orderRef);
    $orderId = (int)$parts[0];
}

$USD_RATE = 25000.0;
$vnp_Amount_raw = $inputData['vnp_Amount'] ?? ($_GET['vnp_Amount'] ?? 0);
$vnp_Amount = ((float)$vnp_Amount_raw / 100.0) / $USD_RATE;
$vnp_ResponseCode = $inputData['vnp_ResponseCode'] ?? ($_GET['vnp_ResponseCode'] ?? null);
$vnp_TransactionNo = $inputData['vnp_TransactionNo'] ?? ($_GET['vnp_TransactionNo'] ?? null);

// Validate signature
if ($ourHash !== $vnp_SecureHash) {
    header("Content-Type: text/html; charset=utf-8");
    echo "<html><body><script>window.location.replace(" . json_encode($appUrl) . ");</script></body></html>";
    exit;
}

// Check order
$stmt = $conn->prepare("SELECT id, status, total_price, user_id FROM orders WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
if (!$order) {
    header("Content-Type: text/html; charset=utf-8");
    echo "<html><body><script>window.location.replace(" . json_encode($appUrl) . ");</script></body></html>";
    exit;
}

$orderTotal = (float)$order['total_price'];
if (abs($orderTotal - $vnp_Amount) > 0.01) {
    header("Content-Type: text/html; charset=utf-8");
    echo "<html><body><script>window.location.replace(" . json_encode($appUrl) . ");</script></body></html>";
    exit;
}

// ========================= PROCESS RESULT =========================
if ($vnp_ResponseCode === '00') {
    $conn->begin_transaction();
    try {
        $u = $conn->prepare("UPDATE orders SET status='paid' WHERE id = ? AND status <> 'paid'");
        $u->bind_param("i", $orderId);
        $u->execute();

        $upd = $conn->prepare("UPDATE payments SET status='success', transaction_id=?, amount=?, created_at=NOW() WHERE order_id=? AND payment_method='vnpay'");
        $upd->bind_param("sdi", $vnp_TransactionNo, $vnp_Amount, $orderId);
        $upd->execute();

        if ($upd->affected_rows === 0) {
            $ins = $conn->prepare("INSERT INTO payments (order_id, payment_method, transaction_id, amount, status, created_at) VALUES (?, 'vnpay', ?, ?, 'success', NOW())");
            $ins->bind_param("isd", $orderId, $vnp_TransactionNo, $vnp_Amount);
            $ins->execute();
        }

        // Clear cart
        $userId = isset($order['user_id']) ? (int)$order['user_id'] : 0;
        if ($userId > 0) {
            $qc = $conn->prepare("SELECT id FROM cart WHERE user_id = ? LIMIT 1");
            $qc->bind_param("i", $userId);
            $qc->execute();
            $cartRow = $qc->get_result()->fetch_assoc();
            if ($cartRow) {
                $cartId = (int)$cartRow['id'];
                $del = $conn->prepare("DELETE FROM cart_items WHERE cart_id = ?");
                $del->bind_param("i", $cartId);
                $del->execute();
            }
        }

        $conn->commit();

        // ✅ SEND EMAIL SUCCESS
        require_once __DIR__ . '/../../PHPMailer-master/src/Exception.php';
        require_once __DIR__ . '/../../PHPMailer-master/src/PHPMailer.php';
        require_once __DIR__ . '/../../PHPMailer-master/src/SMTP.php';


        $qUser = $conn->prepare("SELECT email, username FROM users WHERE id = ?");
        $qUser->bind_param("i", $userId);
        $qUser->execute();
        $uinfo = $qUser->get_result()->fetch_assoc();

        if ($uinfo && !empty($uinfo['email'])) {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = 'group4.sportstore@gmail.com';
                $mail->Password = 'szzu twhw yayc spad';
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = 587;
                $mail->CharSet = 'UTF-8';

                $mail->setFrom('group4.sportstore@gmail.com', 'Nike Store');
                $mail->addAddress($uinfo['email'], $uinfo['username']);
                $mail->Subject = "Nike Store - Thanh toán thành công đơn hàng #$orderId";
                $mail->Body = "Xin chào {$uinfo['username']},\n\nĐơn hàng #$orderId của bạn đã được thanh toán thành công qua VNPay.\nChúng tôi sẽ sớm xử lý và giao hàng.\n\nCảm ơn bạn đã mua sắm tại Nike Store!";
                $mail->send();
            } catch (\Exception $e) {
                file_put_contents($base . '/vnpay_debug.log', date('c') . " EMAIL SEND FAIL SUCCESS: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
            }
        }

    } catch (\Exception $e) {
        $conn->rollback();
        file_put_contents($base . '/vnpay_debug.log', date('c') . " DB ERROR: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
    }
} else {
    $conn->begin_transaction();
    try {
        $up = $conn->prepare("UPDATE orders SET status='cancelled' WHERE id=?");
        $up->bind_param("i", $orderId);
        $up->execute();

        $updP = $conn->prepare("UPDATE payments SET status='failed', transaction_id=?, amount=?, created_at=NOW() WHERE order_id=? AND payment_method='vnpay'");
        $updP->bind_param("sdi", $vnp_TransactionNo, $vnp_Amount, $orderId);
        $updP->execute();

        // Restock
        $getItems = $conn->prepare("SELECT product_id, variant_id, quantity FROM order_items WHERE order_id=?");
        $getItems->bind_param("i", $orderId);
        $getItems->execute();
        $items = $getItems->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($items as $it) {
            $qty = (int)$it['quantity'];
            if ($it['variant_id'] !== null && $it['variant_id'] !== '') {
                $s = $conn->prepare("UPDATE product_variants SET stock=stock+? WHERE id=?");
                $s->bind_param("ii", $qty, $it['variant_id']);
            } else {
                $s = $conn->prepare("UPDATE products SET stock=stock+? WHERE id=?");
                $s->bind_param("ii", $qty, $it['product_id']);
            }
            $s->execute();
        }

        $conn->commit();

        // ❌ SEND EMAIL FAILED
        require_once __DIR__ . '/../../PHPMailer-master/src/Exception.php';
        require_once __DIR__ . '/../../PHPMailer-master/src/PHPMailer.php';
        require_once __DIR__ . '/../../PHPMailer-master/src/SMTP.php';


        $qUser = $conn->prepare("SELECT email, username FROM users WHERE id=?");
        $qUser->bind_param("i", $order['user_id']);
        $qUser->execute();
        $uinfo = $qUser->get_result()->fetch_assoc();

        if ($uinfo && !empty($uinfo['email'])) {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = 'group4.sportstore@gmail.com';
                $mail->Password = 'szzu twhw yayc spad';
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = 587;
                $mail->CharSet = 'UTF-8';

                $mail->setFrom('group4.sportstore@gmail.com', 'Nike Store');
                $mail->addAddress($uinfo['email'], $uinfo['username']);
                $mail->Subject = "Nike Store - Thanh toán thất bại đơn hàng #$orderId";
                $mail->Body = "Xin chào {$uinfo['username']},\n\nThanh toán VNPay cho đơn hàng #$orderId không thành công. Đơn hàng đã được hủy và hàng hóa đã hoàn về kho.\n\nNếu bạn cần hỗ trợ, vui lòng liên hệ bộ phận CSKH của chúng tôi.";
                $mail->send();
            } catch (\Exception $e) {
                file_put_contents($base . '/vnpay_debug.log', date('c') . " EMAIL SEND FAIL FAIL: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
            }
        }

    } catch (\Exception $e) {
        $conn->rollback();
        file_put_contents($base . '/vnpay_debug.log', date('c') . " FAIL DB ERROR: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
    }
}

header("Content-Type: text/html; charset=utf-8");
$appEscaped = htmlspecialchars($appUrl, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta http-equiv="refresh" content="1;url=<?php echo $appEscaped; ?>">
  <title>Redirecting...</title>
  <style>body{font-family:Arial,Helvetica,sans-serif;padding:20px;text-align:center}</style>
</head>
<body>
  <h3>Đang chuyển về ứng dụng...</h3>
  <p>Nếu ứng dụng không mở, nhấn nút bên dưới hoặc chờ vài giây.</p>
  <p><a href="<?php echo $appEscaped; ?>">Mở ứng dụng</a></p>
  <script>
    try {
      window.location.replace(<?php echo json_encode($appUrl); ?>);
      setTimeout(function(){ window.location.href = <?php echo json_encode($appUrl); ?>; }, 800);
    } catch(e) {}
  </script>
</body>
</html>
<?php
exit;
