<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'db_connect.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Fetch all signals
    $stmt = $conn->query("SELECT * FROM signals ORDER BY created_at DESC");
    $signals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($signals);

} elseif ($method === 'POST') {
    // Add new signal
    $data = json_decode(file_get_contents("php://input"));
    
    $pair = $data->pair;
    $type = $data->type;
    $entry = $data->entry_price;
    $sl = $data->stop_loss;
    $tp = $data->take_profit;
    $status = $data->status ?? 'Active'; // 'Active' maps to 'Running' or 'Pending' in DB? 
    // DB schema has ENUM('Pending', 'Running', 'Profit', 'Loss')
    // App.js uses 'Active' as a status in some places, let's align.
    
    // Map App.js 'Active' to DB 'Running' or 'Pending'
    // If status is 'Active', we'll default to 'Running' for now, or respect input if valid.
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