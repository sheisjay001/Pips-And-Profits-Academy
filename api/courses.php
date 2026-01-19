<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (php_sapi_name() === 'cli') {
    if (!isset($_SERVER['REQUEST_METHOD'])) {
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// DEBUG: Log all requests
$logFile = __DIR__ . '/debug_courses.txt';
$logEntry = date('Y-m-d H:i:s') . " - Method: " . $_SERVER['REQUEST_METHOD'] . "\n";
$logEntry .= "POST Data: " . print_r($_POST, true) . "\n";
$logEntry .= "FILES Data: " . print_r($_FILES, true) . "\n";
file_put_contents($logFile, $logEntry, FILE_APPEND);

require_once 'db_connect.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $conn->query("SELECT * FROM courses ORDER BY created_at DESC");
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($courses);
} elseif ($method === 'POST') {
    $title = $_POST['title'] ?? '';
    $description = $_POST['desc'] ?? '';
    $level = $_POST['level'] ?? 'Beginner';
    $price = $_POST['price'] ?? 0.00;

    $uploadBase = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
    $videoDir = $uploadBase . DIRECTORY_SEPARATOR . 'videos';
    $thumbDir = $uploadBase . DIRECTORY_SEPARATOR . 'thumbnails';

    if (!is_dir($uploadBase)) { @mkdir($uploadBase, 0777, true); }
    if (!is_dir($videoDir)) { @mkdir($videoDir, 0777, true); }
    if (!is_dir($thumbDir)) { @mkdir($thumbDir, 0777, true); }

    $videoPathRel = '';
    if (isset($_POST['video_url']) && !empty($_POST['video_url'])) {
        $videoPathRel = $_POST['video_url'];
    } else {
        echo json_encode(['success' => false, 'message' => 'Video URL is required']);
        exit;
    }

    $thumbPathRel = '';
    if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['thumbnail']['name'], PATHINFO_EXTENSION);
        $filename = uniqid('thumb_') . '.' . strtolower($ext);
        $dest = $thumbDir . DIRECTORY_SEPARATOR . $filename;
        if (move_uploaded_file($_FILES['thumbnail']['tmp_name'], $dest)) {
            $thumbPathRel = 'uploads/thumbnails/' . $filename;
        }
    }

    // Ensure video_path column exists
    try {
        $check = $conn->query("SHOW COLUMNS FROM courses LIKE 'video_path'");
        if ($check->rowCount() == 0) {
            $conn->exec("ALTER TABLE courses ADD COLUMN video_path VARCHAR(255) AFTER thumbnail_url");
        }
    } catch (Exception $e) {
        // Ignore check error, insert might fail but we tried
    }

    $stmt = $conn->prepare("INSERT INTO courses (title, description, level, thumbnail_url, video_path, price, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
    
    try {
        $ok = $stmt->execute([$title, $description, $level, $thumbPathRel, $videoPathRel, $price]);
        if (!$ok) {
            $errorInfo = $stmt->errorInfo();
            echo json_encode(['success' => false, 'message' => 'Failed to create course: ' . $errorInfo[2]]);
            exit;
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
        exit;
    }
    
    $courseId = $conn->lastInsertId();

    echo json_encode([
        'success' => true,
        'course' => [
            'id' => $courseId,
            'title' => $title,
            'description' => $description,
            'level' => $level,
            'thumbnail_url' => $thumbPathRel,
            'video_path' => $videoPathRel,
            'price' => (float)$price
        ]
    ]);
}
?>
