<?php
date_default_timezone_set('Asia/Ho_Chi_Minh');

// VNPAY config
define('VNPAY_TMN_CODE', '0A04INQE'); // Mã merchant (TmnCode)
define('VNPAY_HASH_SECRET', 'G1YG5W7AXQMN6IBHX2ZQI1KITJ2KASJC'); // Secret key (giữ bí mật!)
define('VNPAY_URL', 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html');

// URLs public (dùng ngrok trong dev). Khi deploy production, đổi lại URL chính thức.
define('VNPAY_RETURN_URL', 'https://acrimoniously-nonpreservable-maison.ngrok-free.dev/api/project/vnpay_php/vnpay_return.php');
define('VNPAY_IPN_URL',    'https://acrimoniously-nonpreservable-maison.ngrok-free.dev/api/project/vnpay_php/vnpay_ipn.php');

// Thời gian tạo và hết hạn giao dịch (VNPay dùng format YmdHis)
$startTime = date("YmdHis");                 // hiện tại theo timezone đã set
$expire    = date("YmdHis", time() + 15*60); // +15 phút

// Nếu cần dùng timestamp nguyên cho logic khác, bạn có thể dùng:
$startTimestamp = time();
$expireTimestamp = $startTimestamp + 15*60;
