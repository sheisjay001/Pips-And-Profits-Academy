<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$email = $input['email'] ?? '';
$amount = $input['amount'] ?? 0;

if (!$email || !$amount) {
    echo json_encode(['status' => false, 'message' => 'Email and amount required']);
    exit;
}

$url = "https://api.paystack.co/transaction/initialize";

// Construct callback URL dynamically
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
// Assuming the app is in "Pips And Profits Academy" folder based on file path
// We want to redirect back to dashboard.html or a success page
$callback_url = "$protocol://$host/Pips And Profits Academy/dashboard.html?payment=success";

$fields = [
    'email' => $email,
    'amount' => $amount,
    'callback_url' => $callback_url
];

$fields_string = http_build_query($fields);

// Paystack Secret Key
// Load from config if available, otherwise fail
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
    $sk = $paystack_secret_key;
} else {
    echo json_encode(['status' => false, 'message' => 'Server configuration missing (config.php)']);
    exit;
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
  "Authorization: Bearer $sk",
  "Cache-Control: no-cache"
));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 

$result = curl_exec($ch);

if(curl_errno($ch)){
    echo json_encode(['status' => false, 'message' => 'Curl error: ' . curl_error($ch)]);
} else {
    echo $result;
}

curl_close($ch);
?>
