<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self' https: data:; img-src 'self' https: data:; script-src 'self' https:; style-src 'self' https: 'unsafe-inline'; connect-src 'self' https:; frame-ancestors 'self';");

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => isset($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

// CLI fallback for testing
if (php_sapi_name() === 'cli') {
    if (!isset($_SERVER['REQUEST_METHOD'])) {
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'db_connect.php';

$method = $_SERVER['REQUEST_METHOD'];

// Helper to get global settings
function getSetting($conn, $key, $default = '') {
    try {
        $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        return $stmt->fetchColumn() ?: $default;
    } catch (Exception $e) {
        return $default;
    }
}

// Function to send Webhook/Telegram notifications
function dispatchSignalNotification($conn, $signal) {
    $enabled = getSetting($conn, 'signal_notifications_enabled', '0');
    if ($enabled !== '1') return;

    $webhookUrl = getSetting($conn, 'webhook_url');
    $tgToken = getSetting($conn, 'telegram_bot_token');
    $tgChatId = getSetting($conn, 'telegram_chat_id');

    $message = "🚀 NEW SIGNAL: {$signal['pair']} {$signal['type']}\n";
    $message .= "🔹 Entry: {$signal['entry_price']}\n";
    $message .= "🛑 SL: {$signal['stop_loss']}\n";
    $message .= "🎯 TP: {$signal['take_profit']}\n";
    $message .= "⏰ Time: " . date('Y-m-d H:i');

    // 1. Send to Custom Webhook if configured
    if ($webhookUrl) {
        $ch = curl_init($webhookUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($signal));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_exec($ch);
        curl_close($ch);
    }

    // 2. Send to Telegram if configured
    if ($tgToken && $tgChatId) {
        $tgUrl = "https://api.telegram.org/bot{$tgToken}/sendMessage";
        $data = [
            'chat_id' => $tgChatId,
            'text' => $message,
            'parse_mode' => 'HTML'
        ];
        $ch = curl_init($tgUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_exec($ch);
        curl_close($ch);
    }
}

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';

    // Machine-readable feed for EAs/Bots
    if ($action === 'feed') {
        // No rate limit for feed to allow EAs to poll
        $stmt = $conn->query("SELECT * FROM signals WHERE status = 'Running' ORDER BY created_at DESC LIMIT 1");
        $latest = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($latest) {
            // Clean format for easy parsing in MT4/MT5
            echo json_encode([
                'status' => 'success',
                'signal' => [
                    'id' => $latest['id'],
                    'symbol' => str_replace('/', '', $latest['pair']),
                    'type' => $latest['type'],
                    'entry' => (float)$latest['entry_price'],
                    'sl' => (float)$latest['stop_loss'],
                    'tp' => (float)$latest['take_profit'],
                    'timestamp' => strtotime($latest['created_at'])
                ]
            ]);
        } else {
            echo json_encode(['status' => 'empty', 'message' => 'No active signals']);
        }
        exit;
    }

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
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
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
        
        // Dispatch notifications (Webhook/Telegram)
        dispatchSignalNotification($conn, $newSignal);
        
        echo json_encode(['success' => true, 'signal' => $newSignal]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to add signal']);
    }
}
?>
