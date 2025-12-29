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

require_once 'db_connect.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $conn->query("SELECT id, title, description, level, thumbnail_url, price, created_at, 
        CASE 
            WHEN COLUMN_NAME IS NOT NULL THEN video_path 
            ELSE '' 
        END AS video_path
        FROM courses");
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

    if (!is_dir($uploadBase)) { @mkdir($uploadBase); }
    if (!is_dir($videoDir)) { @mkdir($videoDir); }
    if (!is_dir($thumbDir)) { @mkdir($thumbDir); }

    $videoPathRel = '';
    if (isset($_FILES['video']) && $_FILES['video']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['video']['name'], PATHINFO_EXTENSION);
        $filename = uniqid('vid_') . '.' . strtolower($ext);
        $dest = $videoDir . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($_FILES['video']['tmp_name'], $dest)) {
            echo json_encode(['success' => false, 'message' => 'Failed to save video']);
            exit;
        }
        $videoPathRel = 'uploads/videos/' . $filename;
    } else {
        echo json_encode(['success' => false, 'message' => 'Video file is required']);
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

    $stmt = $conn->prepare("INSERT INTO courses (title, description, level, thumbnail_url, price, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $ok = $stmt->execute([$title, $description, $level, $thumbPathRel, $price]);
    if (!$ok) {
        echo json_encode(['success' => false, 'message' => 'Failed to create course']);
        exit;
    }
    $courseId = $conn->lastInsertId();

    $hasVideoCol = false;
    try {
        $conn->query("SELECT video_path FROM courses LIMIT 1");
        $hasVideoCol = true;
    } catch (Exception $e) { $hasVideoCol = false; }

    if ($hasVideoCol) {
        $conn->prepare("UPDATE courses SET video_path = ? WHERE id = ?")->execute([$videoPathRel, $courseId]);
    }

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
