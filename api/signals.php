<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

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

if ($method === 'GET') {
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
        echo json_encode(['success' => true, 'signal' => $newSignal]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to add signal']);
    }
}
?>
