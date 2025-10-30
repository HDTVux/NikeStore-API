<?php
require_once __DIR__ . '/config2.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// ========== Đọc JSON đầu vào hoặc query string ==========
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

$query = '';
if (!empty($data['query'])) {
    $query = trim($data['query']);
} elseif (!empty($_GET['q'])) {
    $query = trim($_GET['q']);
}

if ($query === '') {
    echo json_encode(['success' => false, 'message' => 'Missing query']);
    exit;
}

// ========== Truy vấn sản phẩm liên quan ==========
$like = "%{$query}%";
$sql = "SELECT id, name, description 
        FROM products 
        WHERE is_active = 1 AND (name LIKE ? OR description LIKE ?) 
        LIMIT 8";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $like, $like);
$stmt->execute();
$res = $stmt->get_result();

$context = [];
while ($r = $res->fetch_assoc()) {
    $context[] = "ID:{$r['id']} | {$r['name']} - " . mb_substr($r['description'] ?? '', 0, 200);
}
$stmt->close();

$context_text = !empty($context)
    ? implode("\n", $context)
    : "Không tìm thấy sản phẩm nào phù hợp.";

// ========== Tạo prompt cho Gemini ==========
$prompt = "Người dùng nhập: \"{$query}\"\n\n"
        . "Nhiệm vụ của bạn:\n"
        . "1️⃣ Phân tích ý định tìm kiếm để xác định các loại sản phẩm chính (ví dụ: giày, áo, quần, phụ kiện...).\n"
        . "2️⃣ Trích xuất các từ khóa sản phẩm phù hợp với cơ sở dữ liệu.\n"
        . "3️⃣ Dựa vào danh sách sản phẩm hiện có bên dưới để chọn ra các sản phẩm liên quan nhất.\n"
        . "4️⃣ Nếu không có sản phẩm trùng, hãy tìm các từ khóa tương tự (ví dụ: 'outfit đi chơi' → giày sneaker, áo thun, quần short).\n\n"
        . "Cơ sở dữ liệu sản phẩm hiện có:\n{$context_text}\n\n"
        . "Hãy trả về tối đa 5 gợi ý ngắn gọn, mỗi gợi ý gồm tên và 1 lý do ngắn tại sao phù hợp. "
        . "Nếu có ID trong danh sách, thêm (ID:x). "
        . "Nếu không có sản phẩm khớp, hãy đề xuất danh mục tương ứng có thể tìm trong cửa hàng.\n"
        . "Chỉ sử dụng các sản phẩm hoặc danh mục có thật, không bịa đặt.";


// ========== Lấy key và endpoint ==========
$gemini_key = getenv('GEMINI_API_KEY') ?: (defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '');
if (!$gemini_key) {
    echo json_encode(['success' => false, 'message' => 'Missing GEMINI_API_KEY']);
    exit;
}

$endpoint = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . urlencode($gemini_key);

// ========== Chuẩn bị payload ==========
$payload = [
    "contents" => [
        [
            "role" => "user",
            "parts" => [["text" => $prompt]]
        ]
    ]
];

// ========== Gọi Gemini API ==========
$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 30
]);
$response = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

// ========== Ghi log để debug ==========
$logFile = __DIR__ . '/gemini_debug.log';
file_put_contents(
    $logFile,
    "[" . date('Y-m-d H:i:s') . "]\n" .
    "PROMPT:\n" . $prompt . "\n\n" .
    "RESPONSE:\n" . ($response ?: $err) . "\n\n",
    FILE_APPEND
);

// ========== Xử lý phản hồi ==========
if ($err || !$response) {
    $output = "⚠️ Gợi ý AI tạm thời không khả dụng. Dưới đây là danh sách sản phẩm liên quan.";
} else {
    $result = json_decode($response, true);
    if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        $output = $result['candidates'][0]['content']['parts'][0]['text'];
    } elseif (isset($result['candidates'][0]['output'][0]['text'])) {
        $output = $result['candidates'][0]['output'][0]['text'];
    } else {
        $output = "⚠️ Gợi ý AI tạm thời không khả dụng. Dưới đây là danh sách sản phẩm liên quan.";
    }
}

// ========== Trả kết quả ==========
echo json_encode([
    'success' => true,
    'query' => $query,
    'suggestions' => $output,
    'context' => $context, // danh sách sản phẩm fallback
    'context_count' => count($context)
], JSON_UNESCAPED_UNICODE);
?>
