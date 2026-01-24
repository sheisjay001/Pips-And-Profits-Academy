<?php
// Enable error reporting but keep it internal until we format it as JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Start session to access user_id
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Custom error handler to catch warnings/notices and return JSON
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // Only handle if error_reporting respects this level
    if (!(error_reporting() & $errno)) {
        return false;
    }
    
    $errorType = 'Unknown Error';
    switch ($errno) {
        case E_ERROR: $errorType = 'Fatal Error'; break;
        case E_WARNING: $errorType = 'Warning'; break;
        case E_NOTICE: $errorType = 'Notice'; break;
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => "$errorType: $errstr in $errfile on line $errline"
    ]);
    exit;
});

// Catch fatal errors that set_error_handler doesn't catch
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_CORE_ERROR || $error['type'] === E_COMPILE_ERROR)) {
        http_response_code(500);
        // Clean any previous output
        if (ob_get_length()) ob_clean();
        echo json_encode([
            'success' => false, 
            'message' => "Fatal Error: {$error['message']} in {$error['file']} on line {$error['line']}"
        ]);
    }
});

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

try {
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
    
    // Try to write log, ignore if fails
    @file_put_contents($logFile, $logEntry, FILE_APPEND);

    require_once 'db_connect.php';

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        // Handle Progress Check
        if (isset($_GET['action']) && $_GET['action'] === 'check_progress') {
            $course_id = $_GET['course_id'] ?? 0;
            $user_id = $_SESSION['user_id'] ?? 0;
            
            if (!$user_id) {
                echo json_encode(['success' => false, 'message' => 'Not logged in']);
                exit;
            }
            
            $stmt = $conn->prepare("SELECT progress_percentage FROM user_progress WHERE user_id = ? AND course_id = ?");
            $stmt->execute([$user_id, $course_id]);
            $progress = $stmt->fetchColumn();
            
            echo json_encode(['success' => true, 'progress' => $progress ?: 0]);
            exit;
        }

        // Fetch courses with progress if user is logged in
        if (isset($_SESSION['user_id'])) {
            $user_id = $_SESSION['user_id'];
            $sql = "SELECT c.*, COALESCE(up.progress_percentage, 0) as progress 
                    FROM courses c 
                    LEFT JOIN user_progress up ON c.id = up.course_id AND up.user_id = ? 
                    ORDER BY c.created_at DESC";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$user_id]);
        } else {
            $stmt = $conn->query("SELECT *, 0 as progress FROM courses ORDER BY created_at DESC");
        }
        
        $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($courses);
    } elseif ($method === 'POST') {
        // Handle Delete Actions
        if (isset($_POST['action'])) {
            if ($_POST['action'] === 'delete') {
                $id = $_POST['id'] ?? null;
                if (!$id) {
                    echo json_encode(['success' => false, 'message' => 'Course ID required']);
                    exit;
                }
                // Delete related progress first
                try {
                    $conn->prepare("DELETE FROM user_progress WHERE course_id = ?")->execute([$id]);
                    $stmt = $conn->prepare("DELETE FROM courses WHERE id = ?");
                    if ($stmt->execute([$id])) {
                        echo json_encode(['success' => true]);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Failed to delete course']);
                    }
                } catch (PDOException $e) {
                    echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
                }
                exit;
            } elseif ($_POST['action'] === 'delete_all') {
                try {
                    // Disable foreign key checks temporarily if needed, but safe order is better
                    $conn->exec("DELETE FROM user_progress");
                    $conn->exec("DELETE FROM courses");
                    // Reset Auto Increment (optional but good for clean slate)
                    // $conn->exec("ALTER TABLE courses AUTO_INCREMENT = 1"); 
                    echo json_encode(['success' => true]);
                } catch (PDOException $e) {
                    echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
                }
                exit;
            } elseif ($_POST['action'] === 'mark_complete') {
                $course_id = $_POST['course_id'] ?? 0;
                $user_id = $_SESSION['user_id'] ?? 0;
                
                if (!$user_id) {
                     echo json_encode(['success' => false, 'message' => 'Not logged in']);
                     exit;
                }
                
                try {
                    // Check if exists
                    $stmt = $conn->prepare("SELECT id FROM user_progress WHERE user_id = ? AND course_id = ?");
                    $stmt->execute([$user_id, $course_id]);
                    
                    if ($stmt->fetch()) {
                        $stmt = $conn->prepare("UPDATE user_progress SET progress_percentage = 100 WHERE user_id = ? AND course_id = ?");
                        $stmt->execute([$user_id, $course_id]);
                    } else {
                        $stmt = $conn->prepare("INSERT INTO user_progress (user_id, course_id, progress_percentage) VALUES (?, ?, 100)");
                        $stmt->execute([$user_id, $course_id]);
                    }
                    echo json_encode(['success' => true]);
                } catch (PDOException $e) {
                    echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
                }
                exit;
            }
        }

        $title = $_POST['title'] ?? '';
                $description = $_POST['desc'] ?? '';
                $level = $_POST['level'] ?? 'Beginner';
                $price = $_POST['price'] ?? 0.00;
                $videoPathRel = $_POST['video_url'] ?? '';

                // Optional: Handle Thumbnail Update (only if new file/url provided)
                // If not provided, we keep existing. But here we need to know the existing one if we don't update it?
                // SQL UPDATE will only change fields we specify.
                
                $updateFields = [
                    'title' => $title,
                    'description' => $description,
                    'level' => $level,
                    'price' => $price,
                    'video_path' => $videoPathRel
                ];
                
                // Handle Thumbnail Upload if present
                $uploadBase = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
                $thumbDir = $uploadBase . DIRECTORY_SEPARATOR . 'thumbnails';
                if (!is_dir($thumbDir)) { @mkdir($thumbDir, 0777, true); }
                
                $thumbPathRel = null;
                if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
                     // Upload logic same as create
                     $ext = pathinfo($_FILES['thumbnail']['name'], PATHINFO_EXTENSION);
                     $filename = uniqid('thumb_') . '.' . strtolower($ext);
                     $dest = $thumbDir . DIRECTORY_SEPARATOR . $filename;
                     if (move_uploaded_file($_FILES['thumbnail']['tmp_name'], $dest)) {
                         $thumbPathRel = 'uploads/thumbnails/' . $filename;
                     }
                } elseif (isset($_POST['thumbnail_url']) && !empty($_POST['thumbnail_url'])) {
                    $thumbPathRel = $_POST['thumbnail_url'];
                }
                
                if ($thumbPathRel) {
                    $updateFields['thumbnail_url'] = $thumbPathRel;
                }
                
                // Build SQL
                $setPart = [];
                $params = [];
                foreach ($updateFields as $key => $val) {
                    $setPart[] = "$key = ?";
                    $params[] = $val;
                }
                $params[] = $id; // For WHERE clause
                
                try {
                    $sql = "UPDATE courses SET " . implode(', ', $setPart) . " WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    if ($stmt->execute($params)) {
                        echo json_encode(['success' => true, 'message' => 'Course updated successfully']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Failed to update course']);
                    }
                } catch (PDOException $e) {
                    echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
                }
                exit;
            }
        }

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
        
        // Handle File Upload
        if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
            // Check if we can write to the directory
            if (!is_writable($thumbDir) && !@mkdir($thumbDir, 0777, true)) {
                 // If we have a fallback URL, use it and ignore upload failure
                 if (isset($_POST['thumbnail_url']) && !empty($_POST['thumbnail_url'])) {
                     $thumbPathRel = $_POST['thumbnail_url'];
                 } else {
                     throw new Exception("Server file system is read-only (e.g. Vercel). Please use the 'Thumbnail Image URL' field instead.");
                 }
            } else {
                $ext = pathinfo($_FILES['thumbnail']['name'], PATHINFO_EXTENSION);
                $filename = uniqid('thumb_') . '.' . strtolower($ext);
                $dest = $thumbDir . DIRECTORY_SEPARATOR . $filename;
                
                if (move_uploaded_file($_FILES['thumbnail']['tmp_name'], $dest)) {
                    $thumbPathRel = 'uploads/thumbnails/' . $filename;
                } else {
                    // Fallback to URL if upload fails (e.g. permissions)
                    if (isset($_POST['thumbnail_url']) && !empty($_POST['thumbnail_url'])) {
                        $thumbPathRel = $_POST['thumbnail_url'];
                    } else {
                        throw new Exception("Failed to save uploaded file. Check server permissions.");
                    }
                }
            }
        } 
        // Handle URL (if no file uploaded or file upload failed/skipped)
        elseif (isset($_POST['thumbnail_url']) && !empty($_POST['thumbnail_url'])) {
            $thumbPathRel = $_POST['thumbnail_url'];
        }
        // Error if file upload attempted but failed (and not just empty)
        elseif (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] !== UPLOAD_ERR_NO_FILE) {
             throw new Exception("Upload failed with error code: " . $_FILES['thumbnail']['error']);
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
    } else {
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Exception: ' . $e->getMessage()]);
}
?>
