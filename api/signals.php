<?php
header('Content-Type: application/json');
// CORS Handling (match session_config.php style)
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self' https: data:; img-src 'self' https: data:; script-src 'self' https:; style-src 'self' https: 'unsafe-inline'; connect-src 'self' https:; frame-ancestors 'self';");

require_once 'session_config.php';
require_once 'db_connect.php';

// Load Termii config if available
$termii_config_available = file_exists(__DIR__ . '/config.php');
if ($termii_config_available) {
    require_once __DIR__ . '/config.php';
}

function sendWhatsAppNotification($to, $pair, $type, $entry, $sl, $tp, $status) {
    global $termii_api_key, $termii_sender_id, $termii_template_id, $termii_device_id, $termii_config_available;
    
    if (!$termii_config_available || empty($termii_api_key) || empty($termii_sender_id) || empty($termii_template_id) || empty($termii_device_id)) {
        error_log("Termii not configured, skipping WhatsApp notification to $to");
        return false;
    }
    
    // Format phone number (remove non-digit characters, add country code if needed)
    $to = preg_replace('/[^0-9]/', '', $to);
    // Ensure number starts with country code (e.g., Nigeria is 234)
    // Adjust this logic based on your users' country
    if (strlen($to) >= 10 && substr($to, 0, 1) === '0') {
        $to = substr($to, 1); // Remove leading zero
        $to = '234' . $to; // Prepend Nigeria country code (adjust as needed)
    }
    
    // Termii Template API endpoint
    $url = "https://api.ng.termii.com/api/send/template";
    
    // Template data - uses numeric keys as per Termii docs
    $data = [
        'api_key' => $termii_api_key,
        'template_id' => $termii_template_id,
        'phone_number' => $to,
        'device_id' => $termii_device_id,
        'data' => [
            '1' => $pair,
            '2' => $type,
            '3' => $entry,
            '4' => $sl,
            '5' => $tp,
            '6' => $status
        ]
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        error_log("WhatsApp notification sent to $to via Termii");
        return true;
    } else {
        error_log("Failed to send WhatsApp notification to $to via Termii: HTTP $httpCode, Response: $response");
        return false;
    }
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $now = time();
    $bucket = $_SESSION['signals_get_bucket'] ?? ['count' => 0, 'reset' => $now + 60];
    if ($now > ($bucket['reset'] ?? $now)) {
        $bucket = ['count' => 0, 'reset' => $now + 60];
    }
    if ($bucket['count'] >= 120) {
        echo json_encode(['success' => false, 'message' => 'Rate limit exceeded']);
        exit;
    }
    $bucket['count']++;
    $_SESSION['signals_get_bucket'] = $bucket;
    // Fetch all signals
    $stmt = $conn->query("SELECT * FROM signals ORDER BY created_at DESC");
    $signals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Add aliases for frontend compatibility
    foreach ($signals as &$s) {
        $s['entry'] = $s['entry_price'];
        $s['sl'] = $s['stop_loss'];
        $s['tp'] = $s['take_profit'];
        $s['date'] = $s['created_at'];
    }
    
    echo json_encode($signals);

} elseif ($method === 'POST') {
    validate_csrf();
    $input = file_get_contents("php://input");
    if (empty($input) && php_sapi_name() === 'cli') {
        $input = file_get_contents("php://stdin");
    }
    $data = json_decode($input);

    // Check for delete action
    if (isset($data->action) && $data->action === 'delete') {
        if (!isset($data->id)) {
            echo json_encode(['success' => false, 'message' => 'ID required for deletion']);
            exit;
        }
        $stmt = $conn->prepare("DELETE FROM signals WHERE id = ?");
        if ($stmt->execute([$data->id])) {
            echo json_encode(['success' => true, 'message' => 'Signal deleted']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete signal']);
        }
        exit;
    }

    // Default: Add new signal
    $pair = $data->pair ?? null;
    $type = $data->type ?? null;
    
    if (!$pair || !$type) {
        echo json_encode(['success' => false, 'message' => 'Pair and Type are required']);
        exit;
    }

    // Accept both admin form keys and API keys
    $entry = $data->entry_price ?? $data->entry ?? null;
    $sl = $data->stop_loss ?? $data->sl ?? null;
    $tp = $data->take_profit ?? $data->tp ?? null;
    $status = $data->status ?? 'Running';
    
    // Map App.js 'Active' to DB 'Running'
    if ($status == 'Active') $status = 'Running';

    $stmt = $conn->prepare("INSERT INTO signals (pair, type, entry_price, stop_loss, take_profit, status) VALUES (?, ?, ?, ?, ?, ?)");
    
    if ($stmt->execute([$pair, $type, $entry, $sl, $tp, $status])) {
        $id = $conn->lastInsertId();
        $newSignal = [
            'id' => $id,
            'pair' => $pair,
            'type' => $type,
            'entry_price' => $entry,
            'stop_loss' => $sl,
            'take_profit' => $tp,
            'status' => $status,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        // Automatic WhatsApp notifications via Termii disabled - using manual wa.me broadcast instead
        
        echo json_encode(['success' => true, 'signal' => $newSignal]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to add signal']);
    }
}
?>