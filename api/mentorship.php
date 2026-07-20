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

// Helper function to get mentor's student limit info
function getMentorStudentLimit($conn, $userId) {
    $stmt = $conn->prepare("SELECT pp.name, pp.features 
        FROM mentor_subscriptions ms 
        INNER JOIN pricing_plans pp ON ms.plan_id = pp.id 
        WHERE ms.mentor_id = ? AND ms.is_active = 1");
    $stmt->execute([$userId]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $studentLimit = 0;
    if ($plan) {
        if (strpos(strtolower($plan['name']), 'basic') !== false) $studentLimit = 10;
        elseif (strpos(strtolower($plan['name']), 'pro') !== false) $studentLimit = 50;
        elseif (strpos(strtolower($plan['name']), 'reaganova') !== false) $studentLimit = 1000; // Unlimited
    }
    
    // Get current number of students
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM mentor_students WHERE mentor_id = ?");
    $stmt->execute([$userId]);
    $countResult = $stmt->fetch(PDO::FETCH_ASSOC);
    $currentStudents = $countResult['count'];
    
    return [
        'student_limit' => $studentLimit,
        'current_students' => $currentStudents,
        'can_add_more' => $studentLimit > $currentStudents,
        'has_active_plan' => $plan !== false
    ];
}

try {
    // CSRF Validation for POST requests
    $action = $_GET['action'] ?? '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($action, [''])) {
        validate_csrf();
    }

    $userId = get_authenticated_user_id();
    if (!$userId) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    if ($action === 'get_mentors') {
        // Get all mentors
        $stmt = $conn->prepare("SELECT id, name, email, profile_picture, bio FROM users WHERE is_mentor = 1 OR role = 'admin'");
        $stmt->execute();
        $mentors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'mentors' => $mentors]);
    } elseif ($action === 'get_my_students') {
        // Get mentor's students
        $stmt = $conn->prepare("SELECT u.* FROM users u 
            INNER JOIN mentor_students ms ON u.id = ms.student_id 
            WHERE ms.mentor_id = ?");
        $stmt->execute([$userId]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'students' => $students]);
    } elseif ($action === 'add_student') {
        // Add student to mentor (check plan limit first)
        $input = json_decode(file_get_contents('php://input'));
        $studentId = $input->student_id ?? null;
        $studentEmail = $input->student_email ?? null;

        if (!$studentId && !$studentEmail) {
            echo json_encode(['success' => false, 'message' => 'Student ID or email required']);
            exit;
        }

        if ($studentEmail) {
            // Find student by email
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$studentEmail]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$student) {
                echo json_encode(['success' => false, 'message' => 'Student not found']);
                exit;
            }
            $studentId = $student['id'];
        }

        // Check plan student limit
        $limitInfo = getMentorStudentLimit($conn, $userId);
        
        if (!$limitInfo['has_active_plan']) {
            echo json_encode(['success' => false, 'message' => 'Please subscribe to a plan first to add students']);
            exit;
        }
        
        if (!$limitInfo['can_add_more']) {
            echo json_encode(['success' => false, 'message' => "Student limit reached. Your plan allows up to {$limitInfo['student_limit']} students."]);
            exit;
        }

        // Add the student
        $stmt = $conn->prepare("INSERT IGNORE INTO mentor_students (mentor_id, student_id) VALUES (?, ?)");
        if ($stmt->execute([$userId, $studentId])) {
            echo json_encode(['success' => true, 'message' => 'Student added successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to add student']);
        }
    } elseif ($action === 'remove_student') {
        // Remove student from mentor
        $input = json_decode(file_get_contents('php://input'));
        $studentId = $input->student_id ?? null;

        if (!$studentId) {
            echo json_encode(['success' => false, 'message' => 'Student ID required']);
            exit;
        }

        $stmt = $conn->prepare("DELETE FROM mentor_students WHERE mentor_id = ? AND student_id = ?");
        if ($stmt->execute([$userId, $studentId])) {
            echo json_encode(['success' => true, 'message' => 'Student removed successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to remove student']);
        }
    } elseif ($action === 'get_courses') {
        // Get courses (mentor's own courses or all courses)
        $mentorId = $_GET['mentor_id'] ?? null;
        if ($mentorId) {
            $stmt = $conn->prepare("SELECT * FROM courses WHERE mentor_id = ? ORDER BY created_at DESC");
            $stmt->execute([$mentorId]);
        } else {
            $stmt = $conn->prepare("SELECT c.*, u.name as mentor_name FROM courses c 
                INNER JOIN users u ON c.mentor_id = u.id ORDER BY c.created_at DESC");
            $stmt->execute();
        }
        $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'courses' => $courses]);
    } elseif ($action === 'create_course') {
        // Create a new course
        $input = json_decode(file_get_contents('php://input'));
        $title = $input->title ?? '';
        $description = $input->description ?? '';
        $videoUrl = $input->video_url ?? '';
        $thumbnailUrl = $input->thumbnail_url ?? '';
        $price = $input->price ?? 0.00;

        if (!$title) {
            echo json_encode(['success' => false, 'message' => 'Course title required']);
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO courses (mentor_id, title, description, video_url, thumbnail_url, price) VALUES (?, ?, ?, ?, ?, ?)");
        if ($stmt->execute([$userId, $title, $description, $videoUrl, $thumbnailUrl, $price])) {
            echo json_encode(['success' => true, 'message' => 'Course created successfully', 'course_id' => $conn->lastInsertId()]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to create course']);
        }
    } elseif ($action === 'delete_course') {
        // Delete a course
        $input = json_decode(file_get_contents('php://input'));
        $courseId = $input->course_id ?? null;

        if (!$courseId) {
            echo json_encode(['success' => false, 'message' => 'Course ID required']);
            exit;
        }

        $stmt = $conn->prepare("DELETE FROM courses WHERE id = ? AND mentor_id = ?");
        if ($stmt->execute([$courseId, $userId])) {
            echo json_encode(['success' => true, 'message' => 'Course deleted successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete course']);
        }
    } elseif ($action === 'send_message') {
        // Send message to student/mentor
        $input = json_decode(file_get_contents('php://input'));
        $receiverId = $input->receiver_id ?? null;
        $message = $input->message ?? '';

        if (!$receiverId || !$message) {
            echo json_encode(['success' => false, 'message' => 'Receiver ID and message required']);
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO student_messages (sender_id, receiver_id, message) VALUES (?, ?, ?)");
        if ($stmt->execute([$userId, $receiverId, $message])) {
            echo json_encode(['success' => true, 'message' => 'Message sent successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to send message']);
        }
    } elseif ($action === 'get_messages') {
        // Get messages between two users
        $otherUserId = $_GET['other_user_id'] ?? null;
        if (!$otherUserId) {
            echo json_encode(['success' => false, 'message' => 'Other user ID required']);
            exit;
        }

        $stmt = $conn->prepare("SELECT m.*, 
            sender.name as sender_name, sender.profile_picture as sender_avatar,
            receiver.name as receiver_name, receiver.profile_picture as receiver_avatar
            FROM student_messages m
            INNER JOIN users sender ON m.sender_id = sender.id
            INNER JOIN users receiver ON m.receiver_id = receiver.id
            WHERE (m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)
            ORDER BY m.created_at ASC");
        $stmt->execute([$userId, $otherUserId, $otherUserId, $userId]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Mark messages as read
        $stmt = $conn->prepare("UPDATE student_messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ?");
        $stmt->execute([$otherUserId, $userId]);

        echo json_encode(['success' => true, 'messages' => $messages]);
    } elseif ($action === 'get_pricing_plans') {
        // Get all active pricing plans
        $stmt = $conn->prepare("SELECT * FROM pricing_plans WHERE is_active = 1 ORDER BY price ASC");
        $stmt->execute();
        $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'plans' => $plans]);
    } elseif ($action === 'send_signal_to_students') {
        // Send signal to mentor's students (reuses signals table if available)
        $input = json_decode(file_get_contents('php://input'));
        $pair = $input->pair ?? '';
        $type = $input->type ?? 'BUY';
        $entryPrice = $input->entry_price ?? 0;
        $stopLoss = $input->stop_loss ?? 0;
        $takeProfit = $input->take_profit ?? 0;
        $notes = $input->notes ?? '';

        if (!$pair || !$entryPrice) {
            echo json_encode(['success' => false, 'message' => 'Pair and entry price required']);
            exit;
        }

        // Check if signals table exists (from earlier schema)
        try {
            $stmt = $conn->prepare("INSERT INTO signals (pair, type, entry_price, stop_loss, take_profit, status, created_by) 
                VALUES (?, ?, ?, ?, ?, 'Pending', ?)");
            $stmt->execute([$pair, $type, $entryPrice, $stopLoss, $takeProfit, $userId]);
            echo json_encode(['success' => true, 'message' => 'Signal sent to students successfully']);
        } catch (Exception $e) {
            // If signals table doesn't have created_by column, try without it
            try {
                $stmt = $conn->prepare("INSERT INTO signals (pair, type, entry_price, stop_loss, take_profit, status) 
                    VALUES (?, ?, ?, ?, ?, 'Pending')");
                $stmt->execute([$pair, $type, $entryPrice, $stopLoss, $takeProfit]);
                echo json_encode(['success' => true, 'message' => 'Signal sent to students successfully']);
            } catch (Exception $e2) {
                echo json_encode(['success' => false, 'message' => 'Failed to send signal: ' . $e->getMessage()]);
            }
        }
    } elseif ($action === 'get_my_subscription') {
        // Get mentor's current subscription
        $stmt = $conn->prepare("SELECT ms.*, pp.name as plan_name, pp.price, pp.features, pp.description 
            FROM mentor_subscriptions ms 
            INNER JOIN pricing_plans pp ON ms.plan_id = pp.id 
            WHERE ms.mentor_id = ? AND ms.is_active = 1");
        $stmt->execute([$userId]);
        $subscription = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'subscription' => $subscription]);
    } elseif ($action === 'subscribe_to_plan') {
        // Subscribe mentor to a plan
        $input = json_decode(file_get_contents('php://input'));
        $planId = $input->plan_id ?? null;

        if (!$planId) {
            echo json_encode(['success' => false, 'message' => 'Plan ID required']);
            exit;
        }

        // First deactivate any existing active subscription
        $stmt = $conn->prepare("UPDATE mentor_subscriptions SET is_active = 0, end_date = NOW() WHERE mentor_id = ? AND is_active = 1");
        $stmt->execute([$userId]);

        // Then create new subscription
        $stmt = $conn->prepare("INSERT INTO mentor_subscriptions (mentor_id, plan_id) VALUES (?, ?)");
        if ($stmt->execute([$userId, $planId])) {
            echo json_encode(['success' => true, 'message' => 'Subscribed to plan successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to subscribe to plan']);
        }
    } elseif ($action === 'get_plan_student_limit') {
        // Get maximum number of students allowed for mentor's current plan
        $limitInfo = getMentorStudentLimit($conn, $userId);
        echo json_encode([
            'success' => true, 
            'student_limit' => $limitInfo['student_limit'], 
            'current_students' => $limitInfo['current_students'],
            'can_add_more' => $limitInfo['can_add_more']
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid or missing action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage() . ' File: ' . $e->getFile() . ' Line: ' . $e->getLine()]);
}
?>