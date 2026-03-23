<?php
header('Content-Type: application/json');

// CORS Handling
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');
} else {
    header('Access-Control-Allow-Origin: *');
}

header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'session_config.php';
require_once 'db_connect.php';

// CSRF Validation for POST requests (Upload)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
}

$user_id = get_authenticated_user_id();

// Ensure user is logged in
if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';

// Self-healing: Create table if not exists
$conn->exec("CREATE TABLE IF NOT EXISTS trade_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    ticket VARCHAR(50),
    symbol VARCHAR(20),
    type VARCHAR(10),
    lots DECIMAL(10, 2),
    open_time DATETIME,
    open_price DECIMAL(10, 5),
    close_time DATETIME,
    close_price DECIMAL(10, 5),
    sl DECIMAL(10, 5),
    tp DECIMAL(10, 5),
    profit DECIMAL(10, 2),
    pips DECIMAL(10, 1),
    duration_minutes INT,
    day_of_week VARCHAR(15),
    hour_of_day INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

if ($action === 'upload') {
    if (!isset($_FILES['trade_file'])) {
        echo json_encode(['success' => false, 'message' => 'No file uploaded']);
        exit;
    }

    $file = $_FILES['trade_file']['tmp_name'];
    $handle = fopen($file, "r");
    
    if ($handle === FALSE) {
        echo json_encode(['success' => false, 'message' => 'Failed to open file']);
        exit;
    }

    $header = fgetcsv($handle);
    $trades_imported = 0;

    // Map headers to indices
    $mapping = [];
    foreach ($header as $index => $col) {
        $col = strtolower(trim($col));
        if ($col === 'ticket') $mapping['ticket'] = $index;
        if (strpos($col, 'open time') !== false) $mapping['open_time'] = $index;
        if ($col === 'type') $mapping['type'] = $index;
        if ($col === 'size' || $col === 'volume' || $col === 'lots') $mapping['lots'] = $index;
        if ($col === 'item' || $col === 'symbol') $mapping['symbol'] = $index;
        if (strpos($col, 'open price') !== false || ($col === 'price' && !isset($mapping['open_price']))) $mapping['open_price'] = $index;
        if (strpos($col, 's / l') !== false || $col === 'sl' || $col === 's/l') $mapping['sl'] = $index;
        if (strpos($col, 't / p') !== false || $col === 'tp' || $col === 't/p') $mapping['tp'] = $index;
        if (strpos($col, 'close time') !== false) $mapping['close_time'] = $index;
        if (strpos($col, 'close price') !== false || ($col === 'price' && isset($mapping['open_price']))) $mapping['close_price'] = $index;
        if ($col === 'profit') $mapping['profit'] = $index;
    }

    // Clear previous history
    $conn->prepare("DELETE FROM trade_history WHERE user_id = ?")->execute([$user_id]);

    while (($data = fgetcsv($handle)) !== FALSE) {
        if (count($data) < 5) continue;

        $ticket = $data[$mapping['ticket'] ?? 0] ?? '';
        $open_time = $data[$mapping['open_time'] ?? 1] ?? '';
        $type = strtoupper($data[$mapping['type'] ?? 2] ?? '');
        $lots = floatval($data[$mapping['lots'] ?? 3] ?? 0);
        $symbol = $data[$mapping['symbol'] ?? 4] ?? '';
        $open_price = floatval($data[$mapping['open_price'] ?? 5] ?? 0);
        $sl = floatval($data[$mapping['sl'] ?? 6] ?? 0);
        $tp = floatval($data[$mapping['tp'] ?? 7] ?? 0);
        $close_time = $data[$mapping['close_time'] ?? 8] ?? '';
        $close_price = floatval($data[$mapping['close_price'] ?? 9] ?? 0);
        $profit = floatval($data[$mapping['profit'] ?? count($data)-1] ?? 0);

        if (empty($ticket) || empty($open_time) || empty($close_time)) continue;

        // Calculate metrics
        $open_ts = strtotime($open_time);
        $close_ts = strtotime($close_time);
        $duration_mins = round(($close_ts - $open_ts) / 60);
        $day = date('l', $open_ts);
        $hour = date('H', $open_ts);
        
        // Pips calculation (very basic, assuming 5 digits for now)
        $pips = 0;
        if ($open_price > 0 && $close_price > 0) {
            $diff = ($type === 'BUY' || strpos($type, 'BUY') !== false) ? ($close_price - $open_price) : ($open_price - $close_price);
            $pips = $diff * 10000; // Simplified
        }

        $stmt = $conn->prepare("INSERT INTO trade_history (user_id, ticket, symbol, type, lots, open_time, open_price, close_time, close_price, sl, tp, profit, pips, duration_minutes, day_of_week, hour_of_day) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $user_id, $ticket, $symbol, $type, $lots, 
            date('Y-m-d H:i:s', $open_ts), $open_price, 
            date('Y-m-d H:i:s', $close_ts), $close_price, 
            $sl, $tp, $profit, $pips, $duration_mins, $day, $hour
        ]);
        $trades_imported++;
    }
    fclose($handle);

    echo json_encode(['success' => true, 'message' => "Imported $trades_imported trades successfully."]);

} elseif ($action === 'analyze') {
    // Fetch trades
    $stmt = $conn->prepare("SELECT * FROM trade_history WHERE user_id = ? ORDER BY open_time DESC");
    $stmt->execute([$user_id]);
    $trades = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($trades)) {
        echo json_encode(['success' => false, 'message' => 'No trade history found. Please upload a CSV first.']);
        exit;
    }

    // Analysis Logic
    $stats = [
        'total_trades' => count($trades),
        'wins' => 0,
        'losses' => 0,
        'total_profit' => 0,
        'day_stats' => [],
        'hour_stats' => [],
        'biases' => []
    ];

    foreach ($trades as $t) {
        $profit = floatval($t['profit']);
        $stats['total_profit'] += $profit;
        if ($profit > 0) $stats['wins']++; else $stats['losses']++;

        // Day stats
        $day = $t['day_of_week'];
        if (!isset($stats['day_stats'][$day])) $stats['day_stats'][$day] = ['wins' => 0, 'losses' => 0, 'profit' => 0];
        if ($profit > 0) $stats['day_stats'][$day]['wins']++; else $stats['day_stats'][$day]['losses']++;
        $stats['day_stats'][$day]['profit'] += $profit;

        // Hour stats
        $hour = $t['hour_of_day'];
        if (!isset($stats['hour_stats'][$hour])) $stats['hour_stats'][$hour] = ['wins' => 0, 'losses' => 0, 'profit' => 0];
        if ($profit > 0) $stats['hour_stats'][$hour]['wins']++; else $stats['hour_stats'][$hour]['losses']++;
        $stats['hour_stats'][$hour]['profit'] += $profit;
    }

    // Identify Biases
    // 1. Friday Bias
    if (isset($stats['day_stats']['Friday'])) {
        $f = $stats['day_stats']['Friday'];
        $win_rate = ($f['wins'] + $f['losses'] > 0) ? ($f['wins'] / ($f['wins'] + $f['losses'])) * 100 : 0;
        if ($win_rate < 40 && ($f['wins'] + $f['losses'] > 3)) {
            $stats['biases'][] = "You have a low win rate (" . round($win_rate) . "%) on Fridays. Consider avoiding trading before the weekend.";
        }
    }

    // 2. Overtrading (e.g., > 10 trades a day)
    $trades_per_day = $stats['total_trades'] / count($stats['day_stats']);
    if ($trades_per_day > 8) {
        $stats['biases'][] = "You average " . round($trades_per_day, 1) . " trades per day. This suggests potential overtrading or lack of patience.";
    }

    // 3. Late Night / Early Morning Bias
    $late_night_losses = 0;
    $late_night_total = 0;
    for ($i = 22; $i <= 23; $i++) {
        if (isset($stats['hour_stats'][$i])) {
            $late_night_losses += $stats['hour_stats'][$i]['losses'];
            $late_night_total += ($stats['hour_stats'][$i]['wins'] + $stats['hour_stats'][$i]['losses']);
        }
    }
    for ($i = 0; $i <= 4; $i++) {
        if (isset($stats['hour_stats'][$i])) {
            $late_night_losses += $stats['hour_stats'][$i]['losses'];
            $late_night_total += ($stats['hour_stats'][$i]['wins'] + $stats['hour_stats'][$i]['losses']);
        }
    }
    if ($late_night_total > 5) {
        $loss_rate = ($late_night_losses / $late_night_total) * 100;
        if ($loss_rate > 65) {
            $stats['biases'][] = "Your loss rate during late-night/early-morning hours (" . round($loss_rate) . "%) is significantly high. Fatigue might be affecting your judgment.";
        }
    }

    // 4. Gold Bias (if they trade Gold a lot)
    $gold_trades = array_filter($trades, function($t) { return strpos(strtoupper($t['symbol']), 'GOLD') !== false || strpos(strtoupper($t['symbol']), 'XAU') !== false; });
    if (count($gold_trades) > 5) {
        $gold_losses = count(array_filter($gold_trades, function($t) { return $t['profit'] <= 0; }));
        $gold_loss_rate = ($gold_losses / count($gold_trades)) * 100;
        if ($gold_loss_rate > 60) {
            $stats['biases'][] = "You consistently lose on Gold trades (" . round($gold_loss_rate) . "% loss rate). Gold's volatility might be outside your current strategy's comfort zone.";
        }
    }

    echo json_encode(['success' => true, 'report' => $stats]);
}
?>
