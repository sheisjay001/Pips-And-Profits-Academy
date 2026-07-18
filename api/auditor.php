<?php
// Enable error logging but don't display them to the user
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
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

try {
    require_once 'session_config.php';
    require_once 'db_connect.php';
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Initialization Error: ' . $e->getMessage()]);
    exit;
}

try {
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
        duration_minutes BIGINT,
        day_of_week VARCHAR(15),
        hour_of_day INT,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // Migration: Ensure duration_minutes is BIGINT and add notes column
    try {
        $conn->exec("ALTER TABLE trade_history MODIFY COLUMN duration_minutes BIGINT");
    } catch (Exception $e) {}
    try {
        $conn->exec("ALTER TABLE trade_history ADD COLUMN IF NOT EXISTS notes TEXT AFTER hour_of_day");
    } catch (Exception $e) {}

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
        if (!$header) {
            echo json_encode(['success' => false, 'message' => 'Empty CSV file or invalid format']);
            exit;
        }
        $trades_imported = 0;

        // Map headers to indices
        $mapping = [];
        error_log("Upload called with user_id: $user_id");
        error_log("CSV Header: " . json_encode($header));
        foreach ($header as $index => $col) {
            $col = strtolower(trim($col));
            if ($col === 'ticket' || $col === 'order') $mapping['ticket'] = $index;
            if (strpos($col, 'open time') !== false || strpos($col, 'time') !== false) {
                 if (!isset($mapping['open_time'])) $mapping['open_time'] = $index;
                 else $mapping['close_time'] = $index;
            }
            if ($col === 'type') $mapping['type'] = $index;
            if ($col === 'size' || $col === 'volume' || $col === 'lots') $mapping['lots'] = $index;
            if ($col === 'item' || $col === 'symbol') $mapping['symbol'] = $index;
            if (strpos($col, 'open price') !== false || ($col === 'price' && !isset($mapping['open_price']))) $mapping['open_price'] = $index;
            if (strpos($col, 's / l') !== false || $col === 'sl' || $col === 's/l' || strpos($col, 'stop loss') !== false) $mapping['sl'] = $index;
            if (strpos($col, 't / p') !== false || $col === 'tp' || $col === 't/p' || strpos($col, 'take profit') !== false) $mapping['tp'] = $index;
            if (strpos($col, 'close time') !== false) $mapping['close_time'] = $index;
            if (strpos($col, 'close price') !== false || ($col === 'price' && isset($mapping['open_price']))) $mapping['close_price'] = $index;
            if ($col === 'profit' || strpos($col, 'profit') !== false || strpos($col, 'net profit') !== false) $mapping['profit'] = $index;
        }
        error_log("Mapping after processing: " . json_encode($mapping));

        // Heuristic for MT5 if headers are missing or differently named
        if (!isset($mapping['open_time']) && count($header) >= 10) {
            $mapping = [
                'ticket' => 0, 'open_time' => 1, 'type' => 2, 'lots' => 3, 
                'symbol' => 4, 'open_price' => 5, 'sl' => 6, 'tp' => 7, 
                'close_time' => 8, 'close_price' => 9, 'profit' => count($header)-1
            ];
        }

        // Clear previous history
        $conn->prepare("DELETE FROM trade_history WHERE user_id = ?")->execute([$user_id]);

        $rows_processed = 0;
        while (($data = fgetcsv($handle)) !== FALSE) {
            $rows_processed++;
            if (count($data) < 5) continue;
            if ($rows_processed <= 3) {
                error_log("CSV Row $rows_processed: " . json_encode($data));
            }

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
            
            if ($rows_processed <= 3) {
                error_log("Parsed Row $rows_processed - profit: $profit, type: $type, symbol: $symbol");
            }

            if (empty($ticket) || empty($open_time) || empty($close_time)) continue;

            // Calculate metrics
            // Replace dots with dashes for better compatibility with strtotime
            $open_ts = strtotime(str_replace('.', '-', $open_time));
            $close_ts = strtotime(str_replace('.', '-', $close_time));
            
            if (!$open_ts || !$close_ts) continue;

            // Ensure duration is non-negative and handle massive gaps
            $duration_mins = round(abs($close_ts - $open_ts) / 60);
            
            // Limit duration to a reasonable range (e.g., 10 years in minutes) to prevent overflow
            if ($duration_mins > 5256000) $duration_mins = 0; 

            $day = date('l', $open_ts);
            $hour = date('H', $open_ts);
            
            // Pips calculation (very basic, assuming 5 digits for now)
            $pips = 0;
            if ($open_price > 0 && $close_price > 0) {
                $diff = ($type === 'BUY' || strpos($type, 'BUY') !== false) ? ($close_price - $open_price) : ($open_price - $close_price);
                $pips = $diff * 10000; // Simplified
            }

            $stmt = $conn->prepare("INSERT INTO trade_history (user_id, ticket, symbol, type, lots, open_time, open_price, close_time, close_price, sl, tp, profit, pips, duration_minutes, day_of_week, hour_of_day, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $user_id, $ticket, $symbol, $type, $lots, 
                date('Y-m-d H:i:s', $open_ts), $open_price, 
                date('Y-m-d H:i:s', $close_ts), $close_price, 
                $sl, $tp, $profit, $pips, $duration_mins, $day, $hour, NULL
            ]);
            $trades_imported++;
        }
        fclose($handle);

        echo json_encode(['success' => true, 'message' => "Imported $trades_imported trades successfully."]);

    } elseif ($action === 'save_note') {
        $input = json_decode(file_get_contents('php://input'));
        $trade_id = $input->trade_id ?? null;
        $notes = $input->notes ?? '';

        if (!$trade_id) {
            echo json_encode(['success' => false, 'message' => 'Trade ID required']);
            exit;
        }

        $stmt = $conn->prepare("UPDATE trade_history SET notes = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$notes, $trade_id, $user_id]);

        echo json_encode(['success' => true, 'message' => 'Note saved']);
    } elseif ($action === 'analyze') {
        // Get period filter
        $period = $_GET['period'] ?? 'all'; // all, 7d, 30d, 90d, custom
        $start_date = $_GET['start_date'] ?? null;
        $end_date = $_GET['end_date'] ?? null;

        $query = "SELECT * FROM trade_history WHERE user_id = ?";
        $params = [$user_id];

        if ($period === '7d') {
            $query .= " AND open_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        } elseif ($period === '30d') {
            $query .= " AND open_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        } elseif ($period === '90d') {
            $query .= " AND open_time >= DATE_SUB(NOW(), INTERVAL 90 DAY)";
        } elseif ($period === 'custom' && $start_date && $end_date) {
            $query .= " AND open_time >= ? AND open_time <= ?";
            $params[] = $start_date;
            $params[] = $end_date;
        }

        $query .= " ORDER BY open_time ASC";
        $stmt = $conn->prepare($query);
        $stmt->execute($params);
        $trades = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Debug logs
        error_log("Analyze called with user_id: $user_id, period: $period");
        error_log("Number of trades found: " . count($trades));
        if (!empty($trades)) {
            error_log("First trade: " . json_encode($trades[0]));
            error_log("Last trade: " . json_encode($trades[count($trades)-1]));
        }

        if (empty($trades)) {
            echo json_encode(['success' => false, 'message' => 'No trade history found for this period. Please upload a CSV first.']);
            exit;
        }

        // --- Core Trading Overview ---
        $stats = [
            'total_trades' => count($trades),
            'wins' => 0,
            'losses' => 0,
            'total_profit' => 0,
            'max_profit' => -1e9, // Use a large negative number instead of -INF
            'max_loss' => 1e9, // Use a large positive number instead of INF
            'avg_profit' => 0,
            'avg_win' => 0,
            'avg_loss' => 0,
            'profit_factor' => 0,
            'day_stats' => [],
            'hour_stats' => [],
            'pair_stats' => [],
            'biases' => [],
            'trades' => $trades
        ];

        $gross_profit = 0;
        $gross_loss = 0;
        $equity_curve = [];
        $current_equity = 0;
        $peak_equity = 0;
        $max_drawdown = 0;

        foreach ($trades as $t) {
            $profit = floatval($t['profit']);
            $stats['total_profit'] += $profit;
            $current_equity += $profit;
            $equity_curve[] = $current_equity;

            if ($current_equity > $peak_equity) {
                $peak_equity = $current_equity;
            }
            $drawdown = $peak_equity - $current_equity;
            if ($drawdown > $max_drawdown) {
                $max_drawdown = $drawdown;
            }

            if ($profit > 0) {
                $stats['wins']++;
                $gross_profit += $profit;
                if ($profit > $stats['max_profit']) $stats['max_profit'] = $profit;
            } else {
                $stats['losses']++;
                $gross_loss += abs($profit);
                if ($profit < $stats['max_loss']) $stats['max_loss'] = $profit;
            }

            // Day stats
            $day = $t['day_of_week'];
            if (!isset($stats['day_stats'][$day])) $stats['day_stats'][$day] = ['wins' => 0, 'losses' => 0, 'profit' => 0, 'trades' => 0];
            if ($profit > 0) $stats['day_stats'][$day]['wins']++; else $stats['day_stats'][$day]['losses']++;
            $stats['day_stats'][$day]['profit'] += $profit;
            $stats['day_stats'][$day]['trades']++;

            // Hour stats (sessions)
            $hour = $t['hour_of_day'];
            $session = 'Unknown';
            if ($hour >= 8 && $hour < 12) $session = 'London';
            elseif ($hour >= 12 && $hour < 17) $session = 'London + New York';
            elseif ($hour >= 17 && $hour < 21) $session = 'New York';
            elseif ($hour >= 21 || $hour < 2) $session = 'Sydney';
            elseif ($hour >= 2 && $hour < 8) $session = 'Tokyo';

            if (!isset($stats['hour_stats'][$session])) $stats['hour_stats'][$session] = ['wins' => 0, 'losses' => 0, 'profit' => 0, 'trades' => 0];
            if ($profit > 0) $stats['hour_stats'][$session]['wins']++; else $stats['hour_stats'][$session]['losses']++;
            $stats['hour_stats'][$session]['profit'] += $profit;
            $stats['hour_stats'][$session]['trades']++;

            // Pair stats
            $pair = $t['symbol'];
            if (!isset($stats['pair_stats'][$pair])) $stats['pair_stats'][$pair] = ['wins' => 0, 'losses' => 0, 'profit' => 0, 'trades' => 0];
            if ($profit > 0) $stats['pair_stats'][$pair]['wins']++; else $stats['pair_stats'][$pair]['losses']++;
            $stats['pair_stats'][$pair]['profit'] += $profit;
            $stats['pair_stats'][$pair]['trades']++;
        }

        $stats['avg_profit'] = $stats['total_trades'] > 0 ? $stats['total_profit'] / $stats['total_trades'] : 0;
        $stats['avg_win'] = $stats['wins'] > 0 ? $gross_profit / $stats['wins'] : 0;
        $stats['avg_loss'] = $stats['losses'] > 0 ? -($gross_loss / $stats['losses']) : 0;
        $stats['profit_factor'] = $gross_loss > 0 ? $gross_profit / $gross_loss : ($gross_profit > 0 ? 1e9 : 0);
        $stats['max_drawdown'] = $max_drawdown;
        $stats['peak_equity'] = $peak_equity;
        $stats['current_equity'] = $current_equity;
        $stats['equity_curve'] = $equity_curve;

        // --- Last 50 Trades Analysis ---
        $last_50 = array_slice(array_reverse($trades), 0, 50);
        $last_50_stats = [
            'most_common_mistake' => null,
            'mistakes' => []
        ];

        $possible_mistakes = [
            'early_exit' => 0,
            'late_exit' => 0,
            'oversized_lot' => 0,
            'no_sl' => 0,
            'trading_against_trend' => 0
        ];

        $avg_lots = count($last_50) > 0 ? array_sum(array_column($last_50, 'lots')) / count($last_50) : 0;
        foreach ($last_50 as $t) {
            $profit = floatval($t['profit']);
            $lots = floatval($t['lots']);
            $sl = floatval($t['sl']);
            $tp = floatval($t['tp']);

            if ($sl <= 0) $possible_mistakes['no_sl']++;
            if ($lots > $avg_lots * 1.5 && $profit < 0) $possible_mistakes['oversized_lot']++;
        }

        arsort($possible_mistakes);
        $top_mistake = array_key_first($possible_mistakes);
        $top_mistake_count = reset($possible_mistakes);

        $mistake_messages = [
            'early_exit' => "Closing profitable trades too early ({$top_mistake_count} times in last 50 trades).",
            'late_exit' => "Holding losing trades too long ({$top_mistake_count} times in last 50 trades).",
            'oversized_lot' => "Using oversized position sizes on losing trades ({$top_mistake_count} times in last 50 trades).",
            'no_sl' => "Not using stop losses ({$top_mistake_count} times in last 50 trades).",
            'trading_against_trend' => "Trading against the dominant trend ({$top_mistake_count} times in last 50 trades)."
        ];
        $last_50_stats['most_common_mistake'] = $mistake_messages[$top_mistake] ?? "No recurring mistakes detected in last 50 trades.";
        $last_50_stats['mistakes'] = $possible_mistakes;
        $stats['last_50_stats'] = $last_50_stats;

        // --- Most/Least Profitable Sessions & Pairs ---
        // Fix: To get the actual keys, we need to keep the original array
        $sessionKeys = array_keys($stats['hour_stats']);
        usort($sessionKeys, function($a, $b) use ($stats) {
            return $stats['hour_stats'][$b]['profit'] <=> $stats['hour_stats'][$a]['profit'];
        });
        $stats['most_profitable_session'] = !empty($sessionKeys) ? $sessionKeys[0] : 'N/A';
        $stats['least_profitable_session'] = !empty($sessionKeys) ? end($sessionKeys) : 'N/A';

        $pairKeys = array_keys($stats['pair_stats']);
        usort($pairKeys, function($a, $b) use ($stats) {
            return $stats['pair_stats'][$b]['profit'] <=> $stats['pair_stats'][$a]['profit'];
        });
        $stats['most_profitable_pair'] = !empty($pairKeys) ? $pairKeys[0] : 'N/A';
        $stats['least_profitable_pair'] = !empty($pairKeys) ? end($pairKeys) : 'N/A';

        // --- Last Reward Analysis ---
        $last_trade = end($trades);
        $stats['last_trade'] = $last_trade;
        if ($last_trade) {
            $last_profit = floatval($last_trade['profit']);
            $tp = floatval($last_trade['tp']);
            $open_price = floatval($last_trade['open_price']);
            $close_price = floatval($last_trade['close_price']);
            $potential_reward = abs($tp - $open_price) * 10000; // approximate pips
            $stats['last_reward_analysis'] = [
                'potential_reward' => $potential_reward,
                'actual_reward' => $last_profit,
                'explanation' => ''
            ];
            if ($last_profit > 0 && $last_profit < $stats['avg_win']) {
                $stats['last_reward_analysis']['explanation'] = "Your last trade closed with less profit than your average win. This could be due to early exit, market reversal, or not letting the trade reach your TP.";
            } elseif ($last_profit < 0) {
                $stats['last_reward_analysis']['explanation'] = "Your last trade was a loss. Review the setup, entry timing, and whether you followed your plan.";
            } else {
                $stats['last_reward_analysis']['explanation'] = "Your last trade's reward was in line with expectations.";
            }
        }

        // --- Identify Biases ---
        $biases = [];
        // 1. Friday Bias
        if (isset($stats['day_stats']['Friday'])) {
            $f = $stats['day_stats']['Friday'];
            $total_f = $f['wins'] + $f['losses'];
            if ($total_f > 0) {
                $win_rate = ($f['wins'] / $total_f) * 100;
                if ($win_rate < 40 && $total_f > 3) {
                    $biases[] = "You have a low win rate (" . round($win_rate) . "%) on Fridays. Consider avoiding trading before the weekend.";
                }
            }
        }

        // 2. Overtrading
        $num_days = count($stats['day_stats']);
        if ($num_days > 0) {
            $trades_per_day = $stats['total_trades'] / $num_days;
            if ($trades_per_day > 8) {
                $biases[] = "You average " . round($trades_per_day, 1) . " trades per day. This suggests potential overtrading or lack of patience.";
            }
        }

        // 3. Late Night Bias
        $late_night_losses = 0;
        $late_night_total = 0;
        foreach (['Sydney', 'Tokyo'] as $session) {
            if (isset($stats['hour_stats'][$session])) {
                $late_night_losses += $stats['hour_stats'][$session]['losses'];
                $late_night_total += ($stats['hour_stats'][$session]['wins'] + $stats['hour_stats'][$session]['losses']);
            }
        }
        if ($late_night_total > 5) {
            $loss_rate = ($late_night_losses / $late_night_total) * 100;
            if ($loss_rate > 65) {
                $biases[] = "Your loss rate during late-night/early-morning sessions (" . round($loss_rate) . "%) is significantly high. Fatigue might be affecting your judgment.";
            }
        }
        $stats['biases'] = $biases;

        // Encode JSON properly, handle any potential issues
        $jsonOutput = json_encode(['success' => true, 'report' => $stats]);
        if ($jsonOutput === false) {
            echo json_encode(['success' => false, 'message' => 'JSON encoding error: ' . json_last_error_msg()]);
        } else {
            echo $jsonOutput;
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid or missing action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Analysis Error: ' . $e->getMessage() . ' File: ' . $e->getFile() . ' Line: ' . $e->getLine()]);
}
?>
