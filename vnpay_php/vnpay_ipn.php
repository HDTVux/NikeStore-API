<?php
// vnpay_ipn.php
// IPN (Instant Payment Notification) handler for VNPay
// Put this file in public folder and set VNPay IPN/Notify URL to this file.
// Requires: config.php (defines VNPAY_HASH_SECRET etc) and connect.php ($conn)

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Ho_Chi_Minh');

require_once __DIR__ . '/config.php';   // make sure VNPAY_HASH_SECRET is defined here
require_once __DIR__ . '/../connect.php'; // adjust path to your connect.php

// log helper
function vnp_log($msg) {
    $f = __DIR__ . '/vnpay_debug.log';
    file_put_contents($f, date('c') . " " . $msg . PHP_EOL, FILE_APPEND);
}

// Collect vnp_ params from GET (VNPay sends IPN as GET)
$inputData = [];
foreach ($_GET as $k => $v) {
    if (substr($k, 0, 4) === 'vnp_') {
        $inputData[$k] = $v;
    }
}

vnp_log("IPN RECEIVED: " . json_encode($inputData));

// must have secure hash
$vnp_SecureHash = $inputData['vnp_SecureHash'] ?? '';
unset($inputData['vnp_SecureHash']);

// Build hash string the SAME WAY as when creating payment
ksort($inputData);
$hashData = '';
$i = 0;
foreach ($inputData as $key => $value) {
    if ($i == 1) $hashData .= '&';
    else $i = 1;
    $hashData .= urlencode($key) . "=" . urlencode($value);
}
$ourHash = hash_hmac('sha512', $hashData, VNPAY_HASH_SECRET);

vnp_log("HASH BUILD: data={$hashData} our={$ourHash} theirs={$vnp_SecureHash}");

// Quick helpers for IPN responses expected by VNPay
function ipn_response($code, $message) {
    // VNPay expects a JSON body containing RspCode and Message
    // See VNPay docs: respond with RspCode = '00' on success
    http_response_code(200);
    echo json_encode(["RspCode" => $code, "Message" => $message]);
    vnp_log("IPN RESPOND: {$code} - {$message}");
    exit;
}

// Verify signature
if ($ourHash !== $vnp_SecureHash) {
    vnp_log("SIGNATURE MISMATCH");
    ipn_response("97", "Invalid signature");
}

// Parse required fields
$vnp_TxnRef = $inputData['vnp_TxnRef'] ?? ''; // e.g. "85_1760452215"
$vnp_ResponseCode = $inputData['vnp_ResponseCode'] ?? null;
$vnp_TransactionNo = $inputData['vnp_TransactionNo'] ?? null;
$vnp_Amount_raw = $inputData['vnp_Amount'] ?? 0; // sent as VND * 100

// Determine order id from TxnRef (format used in create_vnpay_payment)
$orderId = 0;
if ($vnp_TxnRef !== '') {
    $parts = explode('_', $vnp_TxnRef);
    $orderId = (int)$parts[0];
}

// Convert amount to the same unit as DB (adjust this if you store total_price in other unit)
$USD_RATE = 25000; // must be same as used when creating payment
$vnpAmount = ((float)$vnp_Amount_raw / 100) / $USD_RATE; // matches create_vnpay_payment

if ($orderId <= 0) {
    vnp_log("INVALID TXNREF: " . $vnp_TxnRef);
    ipn_response("01", "Invalid transaction reference");
}

// Fetch order from DB
$stmt = $conn->prepare("SELECT id, status, total_price FROM orders WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    vnp_log("ORDER NOT FOUND: orderId={$orderId}");
    ipn_response("01", "Order not found");
}

// Check amount (allow small tolerance)
$orderTotal = (float)$order['total_price'];
if (abs($orderTotal - $vnpAmount) > 0.01) {
    vnp_log("AMOUNT MISMATCH: order={$orderTotal} vnp={$vnpAmount}");
    ipn_response("04", "Invalid amount");
}

// If order already processed (idempotency)
if ($order['status'] === 'paid') {
    vnp_log("ORDER ALREADY PAID: " . $orderId);
    ipn_response("02", "Order already confirmed");
}

// Now process based on vnp_ResponseCode
if ($vnp_ResponseCode === '00') {
    // success -> update order and payments
    $conn->begin_transaction();
    try {
        // Update order to paid (only if not paid)
        $u = $conn->prepare("UPDATE orders SET status = 'paid' WHERE id = ? AND status <> 'paid'");
        $u->bind_param("i", $orderId);
        $u->execute();

        // Try update existing payment row (if any)
        $upd = $conn->prepare("UPDATE payments SET status = 'success', transaction_id = ?, amount = ?, created_at = NOW() WHERE order_id = ? AND payment_method = 'vnpay'");
        $upd->bind_param("sdi", $vnp_TransactionNo, $vnpAmount, $orderId);
        $upd->execute();

        if ($upd->affected_rows === 0) {
            // Insert payment record if none exists
            $ins = $conn->prepare("INSERT INTO payments (order_id, payment_method, transaction_id, amount, status, created_at) VALUES (?, 'vnpay', ?, ?, 'success', NOW())");
            $ins->bind_param("isd", $orderId, $vnp_TransactionNo, $vnpAmount);
            $ins->execute();
        }

        $conn->commit();
        vnp_log("IPN PROCESSED SUCCESS: orderId={$orderId} txn={$vnp_TransactionNo} amount={$vnpAmount}");
        ipn_response("00", "Confirm Success");
    } catch (Exception $e) {
        $conn->rollback();
        vnp_log("IPN DB ERROR: " . $e->getMessage());
        ipn_response("99", "DB error");
    }
} else {
    // Payment failed or canceled by user -> mark payment failed, optionally cancel order and restock
    $conn->begin_transaction();
    try {
        $upd = $conn->prepare("UPDATE payments SET status = 'failed', transaction_id = ?, amount = ?, created_at = NOW() WHERE order_id = ? AND payment_method = 'vnpay'");
        $upd->bind_param("sdi", $vnp_TransactionNo, $vnpAmount, $orderId);
        $upd->execute();

        // Optionally change order status to cancelled
        $u2 = $conn->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?");
        $u2->bind_param("i", $orderId);
        $u2->execute();

        // (optional) restock logic if you subtracted stock earlier
        $conn->commit();
        vnp_log("IPN PROCESSED FAILED: orderId={$orderId} txn={$vnp_TransactionNo} code={$vnp_ResponseCode}");
        ipn_response("00", "Processed failure"); // return 00 to tell VNPay you have handled it
    } catch (Exception $e) {
        $conn->rollback();
        vnp_log("IPN DB ERROR ON FAIL: " . $e->getMessage());
        ipn_response("99", "DB error");
    }
}
