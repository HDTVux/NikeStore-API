<?php
// vnpay_return.php - robust include + verify + update DB + clear cart on success
date_default_timezone_set('Asia/Ho_Chi_Minh');

// --- Try load config and DB connection from several likely locations ---
$loaded = false;
$base = __DIR__;

// possible config/connect locations (adjust if your project structure different)
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

// Build hash using urlencode per pair (must match your create code)
ksort($inputData);
$hashData = '';
$i = 0;
foreach ($inputData as $key => $value) {
    if ($i == 1) $hashData .= '&';
    else $i = 1;
    $hashData .= urlencode($key) . '=' . urlencode($value);
}
$ourHash = hash_hmac('sha512', $hashData, defined('VNPAY_HASH_SECRET') ? VNPAY_HASH_SECRET : '');

// Log hash comparison
file_put_contents($base . '/vnpay_debug.log', date('c') . " RETURN HASHCHECK our={$ourHash} theirs={$vnp_SecureHash} hashData={$hashData}" . PHP_EOL, FILE_APPEND);

// Build app deep link to redirect user (append original query)
$appScheme = 'app://vnpay-return';
$appQuery = http_build_query($_GET);
$appUrl = $appScheme . ($appQuery ? ('?' . $appQuery) : '');

// parse order id and amount
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

// If signature invalid -> log and redirect to app (so app may poll); do NOT update DB
if ($ourHash !== $vnp_SecureHash) {
    file_put_contents($base . '/vnpay_debug.log', date('c') . " RETURN SIGN FAIL: order={$orderId} our={$ourHash} their={$vnp_SecureHash}" . PHP_EOL, FILE_APPEND);
    header("Content-Type: text/html; charset=utf-8");
    echo "<html><body><script>window.location.replace(" . json_encode($appUrl) . ");</script></body></html>";
    exit;
}

// verify order exists and include user_id so we can clear cart
$stmt = $conn->prepare("SELECT id, status, total_price, user_id FROM orders WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    file_put_contents($base . '/vnpay_debug.log', date('c') . " RETURN ORDER NOT FOUND: order={$orderId} params=" . json_encode($inputData) . PHP_EOL, FILE_APPEND);
    header("Content-Type: text/html; charset=utf-8");
    echo "<html><body><script>window.location.replace(" . json_encode($appUrl) . ");</script></body></html>";
    exit;
}

// compare amounts (float)
$orderTotal = (float)$order['total_price'];
if (abs($orderTotal - $vnp_Amount) > 0.01) {
    file_put_contents($base . '/vnpay_debug.log', date('c') . " RETURN AMOUNT MISMATCH: order={$orderTotal} vnp={$vnp_Amount} params=" . json_encode($inputData) . PHP_EOL, FILE_APPEND);
    header("Content-Type: text/html; charset=utf-8");
    echo "<html><body><script>window.location.replace(" . json_encode($appUrl) . ");</script></body></html>";
    exit;
}

// Process result
if ($vnp_ResponseCode === '00') {
    $conn->begin_transaction();
    try {
        // mark order paid (idempotent)
        $u = $conn->prepare("UPDATE orders SET status='paid' WHERE id = ? AND status <> 'paid'");
        $u->bind_param("i", $orderId);
        $u->execute();

        // update existing payment if any
        $upd = $conn->prepare("UPDATE payments SET status='success', transaction_id = ?, amount = ?, created_at = NOW() WHERE order_id = ? AND payment_method = 'vnpay'");
        $upd->bind_param("sdi", $vnp_TransactionNo, $vnp_Amount, $orderId);
        $upd->execute();

        if ($upd->affected_rows === 0) {
            $ins = $conn->prepare("INSERT INTO payments (order_id, payment_method, transaction_id, amount, status, created_at) VALUES (?, 'vnpay', ?, ?, 'success', NOW())");
            $ins->bind_param("isd", $orderId, $vnp_TransactionNo, $vnp_Amount);
            $ins->execute();
        }

        // ---------------------------
        // NEW: Clear user's cart items
        // ---------------------------
        $userId = isset($order['user_id']) ? (int)$order['user_id'] : 0;
        if ($userId > 0) {
            // find cart id for user
            $qc = $conn->prepare("SELECT id FROM cart WHERE user_id = ? LIMIT 1");
            $qc->bind_param("i", $userId);
            $qc->execute();
            $cartRow = $qc->get_result()->fetch_assoc();
            if ($cartRow) {
                $cartId = (int)$cartRow['id'];
                $del = $conn->prepare("DELETE FROM cart_items WHERE cart_id = ?");
                $del->bind_param("i", $cartId);
                $del->execute();
                // optional: also delete cart row if you prefer
                // $delCart = $conn->prepare("DELETE FROM cart WHERE id = ?");
                // $delCart->bind_param("i", $cartId); $delCart->execute();
                file_put_contents($base . '/vnpay_debug.log', date('c') . " RETURN CLEARED CART: user={$userId} cart_id={$cartId}" . PHP_EOL, FILE_APPEND);
            } else {
                file_put_contents($base . '/vnpay_debug.log', date('c') . " RETURN NO CART FOR USER: user={$userId}" . PHP_EOL, FILE_APPEND);
            }
        }

        $conn->commit();
        file_put_contents($base . '/vnpay_debug.log', date('c') . " RETURN PROCESSED SUCCESS: order={$orderId} txn={$vnp_TransactionNo} amount={$vnp_Amount}" . PHP_EOL, FILE_APPEND);
    } catch (Exception $e) {
        $conn->rollback();
        file_put_contents($base . '/vnpay_debug.log', date('c') . " RETURN DB ERROR: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
    }
} else {
    // failed -> mark payment failed, cancel order, restock items (idempotent attempts)
    $conn->begin_transaction();
    try {
        $up = $conn->prepare("UPDATE orders SET status='cancelled' WHERE id = ?");
        $up->bind_param("i", $orderId);
        $up->execute();

        $updP = $conn->prepare("UPDATE payments SET status='failed', transaction_id = ?, amount = ?, created_at = NOW() WHERE order_id = ? AND payment_method = 'vnpay'");
        $updP->bind_param("sdi", $vnp_TransactionNo, $vnp_Amount, $orderId);
        $updP->execute();

        // restock items safely
        $getItems = $conn->prepare("SELECT product_id, variant_id, quantity FROM order_items WHERE order_id = ?");
        $getItems->bind_param("i", $orderId);
        $getItems->execute();
        $items = $getItems->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($items as $it) {
            $qty = (int)$it['quantity'];
            if ($it['variant_id'] !== null && $it['variant_id'] !== '') {
                $s = $conn->prepare("UPDATE product_variants SET stock = stock + ? WHERE id = ?");
                $s->bind_param("ii", $qty, $it['variant_id']);
            } else {
                $s = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
                $s->bind_param("ii", $qty, $it['product_id']);
            }
            $s->execute();
        }

        $conn->commit();
        file_put_contents($base . '/vnpay_debug.log', date('c') . " RETURN PROCESSED FAILED: order={$orderId}" . PHP_EOL, FILE_APPEND);
    } catch (Exception $e) {
        $conn->rollback();
        file_put_contents($base . '/vnpay_debug.log', date('c') . " RETURN FAIL DB ERROR: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
    }
}

// Redirect to app deep link so app receives intent (with original query)
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
  <p><a id="openApp" href="<?php echo $appEscaped; ?>">Mở ứng dụng</a></p>
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
