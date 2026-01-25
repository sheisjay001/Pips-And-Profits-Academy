<?php
// Enable error reporting but keep it internal until we format it as JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Start session with consistent cookie parameters
if (session_status() === PHP_SESSION_NONE) {
    // Determine if we are using HTTPS
    $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') 
               || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps, // True if HTTPS, False otherwise
        'httponly' => true,
        'samesite' => $isHttps ? 'None' : 'Lax' // None for HTTPS (Cross-Site), Lax for HTTP (Same-Site/Localhost)
    ]);
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

// CORS Handling
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
    $logEntry = "DEBUG COURSES API - Method: " . $_SERVER['REQUEST_METHOD'] . " | ";
    $logEntry .= "Is HTTPS: " . ($isHttps ? 'Yes' : 'No') . " | ";
    $logEntry .= "Session ID: " . session_id() . " | ";
    $logEntry .= "User ID: " . ($_SESSION['user_id'] ?? 'Not Set');
    
    error_log($logEntry);

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
        $action = $_POST['action'] ?? 'create';

        if ($action === 'delete') {
            $id = $_POST['id'] ?? null;
            if (!$id) {
                echo json_encode(['success' => false, 'message' => 'Course ID required']);
                exit;
            }
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

        } elseif ($action === 'delete_all') {
            try {
                $conn->exec("DELETE FROM user_progress");
                $conn->exec("DELETE FROM courses");
                echo json_encode(['success' => true]);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
            }
            exit;

        } elseif ($action === 'mark_complete') {
            $course_id = $_POST['course_id'] ?? 0;
            $user_id = $_SESSION['user_id'] ?? 0;
            
            if (!$user_id) {
                 echo json_encode(['success' => false, 'message' => 'Not logged in']);
                 exit;
            }
            
            try {
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

        } elseif ($action === 'edit') {
            $id = $_POST['id'] ?? null;
            if (!$id) {
                echo json_encode(['success' => false, 'message' => 'Course ID required for editing']);
                exit;
            }

            // Define allowed fields and only update if they are provided in the POST request
            // This prevents overwriting existing data with empty values if a field is missing
            $allowedFields = [
                'title' => 'title',
                'desc' => 'description',
                'level' => 'level',
                'price' => 'price',
                'video_url' => 'video_path'
            ];

            $setPart = [];
            $params = [];

            foreach ($allowedFields as $postKey => $dbCol) {
                if (isset($_POST[$postKey])) {
                    $setPart[] = "$dbCol = ?";
                    $params[] = $_POST[$postKey];
                }
            }
            
            if (empty($setPart)) {
                 echo json_encode(['success' => false, 'message' => 'No fields to update']);
                 exit;
            }

            $params[] = $id;
            
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

        } elseif ($action === 'create') {
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
            $uploadWarning = '';
            if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
                // Ensure directory exists
                if (!is_dir($thumbDir)) {
                    @mkdir($thumbDir, 0777, true);
                }
                
                // Try to fix permissions if not writable (and dir exists)
                if (is_dir($thumbDir) && !is_writable($thumbDir)) {
                    @chmod($thumbDir, 0777);
                }

                $ext = pathinfo($_FILES['thumbnail']['name'], PATHINFO_EXTENSION);
                $filename = uniqid('thumb_') . '.' . strtolower($ext);
                $dest = $thumbDir . DIRECTORY_SEPARATOR . $filename;
                
                // Try to move file.
                if (@move_uploaded_file($_FILES['thumbnail']['tmp_name'], $dest)) {
                    $thumbPathRel = 'uploads/thumbnails/' . $filename;
                } else {
                    $lastError = error_get_last();
                    $errorMsg = $lastError ? $lastError['message'] : 'Unknown error';
                    
                    // Safe debug info
                    $exists = file_exists($thumbDir) ? 'Yes' : 'No';
                    $perms = ($exists === 'Yes') ? substr(sprintf('%o', fileperms($thumbDir)), -4) : 'N/A';
                    $writable = is_writable($thumbDir) ? 'Yes' : 'No';
                    
                    $uploadWarning = " (Thumbnail upload skipped: Server file system is read-only or permission denied. Debug: Exists=$exists, Writable=$writable, Perms=$perms)";
                    // Proceed without thumbnail
                }
            } 
            elseif (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] !== UPLOAD_ERR_NO_FILE) {
                 $uploadWarning = " (Thumbnail upload failed with error code: " . $_FILES['thumbnail']['error'] . ")";
            }

            // Ensure video_path column exists
            try {
                $check = $conn->query("SHOW COLUMNS FROM courses LIKE 'video_path'");
                if ($check->rowCount() == 0) {
                    $conn->exec("ALTER TABLE courses ADD COLUMN video_path VARCHAR(255) AFTER thumbnail_url");
                }
            } catch (Exception $e) { }

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
                'message' => 'Course created successfully.' . $uploadWarning,
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
    } else {
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Exception: ' . $e->getMessage()]);
}
?>
