<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$email = $input['email'] ?? '';
$amount = $input['amount'] ?? 0;
$plan = $input['plan'] ?? 'pro'; // Default to pro if not set

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
// Append plan to callback so frontend knows what was purchased
$callback_url = "$protocol://$host/Pips And Profits Academy/dashboard.html?payment=verify&plan=" . urlencode($plan);

$fields = [
    'email' => $email,
    'amount' => $amount,
    'callback_url' => $callback_url,
    'metadata' => [
        'plan_code' => $plan,
        'custom_fields' => [
            [
                'display_name' => "Plan",
                'variable_name' => "plan",
                'value' => ucfirst($plan) . " Plan"
            ]
        ]
    ]
];

$fields_string = http_build_query($fields);

// Paystack Secret Key
// Load from config if available, otherwise fail
$config_path = __DIR__ . '/config.php';
if (file_exists($config_path)) {
    require_once $config_path;
    if (isset($paystack_secret_key)) {
        $sk = $paystack_secret_key;
    } else {
        echo json_encode(['status' => false, 'message' => 'Configuration file found but key is missing']);
        exit;
    }
} else {
    echo json_encode(['status' => false, 'message' => 'Server configuration missing (config.php) at ' . $config_path]);
    exit;
}

if (!function_exists('curl_init')) {
    echo json_encode(['status' => false, 'message' => 'CURL is not enabled in your PHP installation.']);
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
