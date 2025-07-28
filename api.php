<?php
// api.php - مع حل مشكلة CORS للاختبار المحلي

// إعداد CORS Headers لحل مشكلة الوصول المحلي
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

// إظهار الأخطاء للتشخيص
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Function to send JSON response safely
function sendJsonResponse($data, $httpCode = 200) {
    http_response_code($httpCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    // Database configuration - عدّل هذه الإعدادات
    $host = 'localhost';
    $dbname = 'laureat';
    $username = 'root';
    $password = '';

    // Test database connection first
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        sendJsonResponse([
            'success' => false,
            'message' => 'خطأ في الاتصال بقاعدة البيانات: ' . $e->getMessage()
        ], 500);
    }

    // Get action
    $action = $_GET['action'] ?? 'test';

    switch ($action) {
        case 'test':
            sendJsonResponse([
                'success' => true,
                'message' => 'API يعمل بشكل صحيح!',
                'timestamp' => date('Y-m-d H:i:s'),
                'server_info' => [
                    'php_version' => phpversion(),
                    'server' => $_SERVER['SERVER_NAME'] ?? 'localhost',
                    'method' => $_SERVER['REQUEST_METHOD']
                ]
            ]);
            break;

        case 'login':
            handleLogin($pdo);
            break;

        case 'update_student':
            handleUpdateStudent($pdo);
            break;

        case 'submit_request':
            handleSubmitRequest($pdo);
            break;

        default:
            sendJsonResponse([
                'success' => false,
                'message' => 'إجراء غير معروف: ' . $action
            ], 400);
    }

} catch (Exception $e) {
    sendJsonResponse([
        'success' => false,
        'message' => 'خطأ عام في الخادم',
        'error' => $e->getMessage()
    ], 500);
}

function handleLogin($pdo) {
    try {
        // Get JSON input
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (!$data) {
            sendJsonResponse([
                'success' => false,
                'message' => 'بيانات غير صحيحة'
            ], 400);
        }

        $cod_etu = trim($data['cod_etu'] ?? '');
        $date_nai = trim($data['date_nai'] ?? '');

        if (empty($cod_etu) || empty($date_nai)) {
            sendJsonResponse([
                'success' => false,
                'message' => 'يرجى إدخال جميع البيانات المطلوبة'
            ], 400);
        }

        // Query student
        $stmt = $pdo->prepare("SELECT * FROM laureat WHERE COD_ETU = ?");
        $stmt->execute([$cod_etu]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            sendJsonResponse([
                'success' => false,
                'message' => 'رمز الطالب غير موجود'
            ]);
        }

        // Convert stored date to standard format for comparison
        $storedDate = convertTextDateToStandard($student['DATE_NAI_IND']);
        
        if ($storedDate === $date_nai) {
            // Prepare student data for frontend
            $student['DATE_NAI_IND'] = $storedDate;
            
            sendJsonResponse([
                'success' => true,
                'student' => $student,
                'message' => 'تم تسجيل الدخول بنجاح'
            ]);
        } else {
            sendJsonResponse([
                'success' => false,
                'message' => 'تاريخ الميلاد غير صحيح',
                'debug_info' => [
                    'stored_date' => $student['DATE_NAI_IND'],
                    'converted_date' => $storedDate,
                    'input_date' => $date_nai
                ]
            ]);
        }

    } catch (Exception $e) {
        sendJsonResponse([
            'success' => false,
            'message' => 'خطأ في تسجيل الدخول: ' . $e->getMessage()
        ], 500);
    }
}

function handleUpdateStudent($pdo) {
    // Implementation for updating student data
    sendJsonResponse([
        'success' => true,
        'message' => 'تم تحديث البيانات (قيد التطوير)'
    ]);
}

function handleSubmitRequest($pdo) {
    // Implementation for submitting diploma request
    sendJsonResponse([
        'success' => true,
        'message' => 'تم إرسال الطلب (قيد التطوير)'
    ]);
}

function convertTextDateToStandard($textDate) {
    if (empty($textDate) || $textDate === '0000-00-00' || $textDate === 'NULL') {
        return null;
    }

    $textDate = trim($textDate);

    // Handle American format like "6/29/1992" (M/D/YYYY)
    if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $textDate)) {
        $parts = explode('/', $textDate);
        $month = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
        $day = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
        $year = $parts[2];
        return "$year-$month-$day";
    }

    // Handle ISO format from ODS (like "1992-06-29T00:00:00.000Z")
    if (strpos($textDate, 'T') !== false) {
        $datePart = explode('T', $textDate)[0];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $datePart)) {
            return $datePart;
        }
    }

    // Handle already standard format (YYYY-MM-DD)
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $textDate)) {
        return $textDate;
    }

    // Handle European format like "29/6/1992" (D/M/YYYY)
    if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $textDate)) {
        $parts = explode('/', $textDate);
        // Try to detect if it's D/M/Y or M/D/Y based on values
        $first = (int)$parts[0];
        $second = (int)$parts[1];
        
        if ($first > 12) {
            // First number > 12, so it must be day (D/M/Y format)
            $day = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
            $month = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
            $year = $parts[2];
            return "$year-$month-$day";
        } else {
            // Assume American format (M/D/Y)
            $month = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
            $day = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
            $year = $parts[2];
            return "$year-$month-$day";
        }
    }

    // Handle other formats with dashes
    if (preg_match('/^\d{1,2}-\d{1,2}-\d{4}$/', $textDate)) {
        $parts = explode('-', $textDate);
        $first = (int)$parts[0];
        
        if ($first > 12) {
            // D-M-Y format
            $day = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
            $month = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
            $year = $parts[2];
            return "$year-$month-$day";
        } else {
            // M-D-Y format
            $month = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
            $day = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
            $year = $parts[2];
            return "$year-$month-$day";
        }
    }

    // Handle other common formats
    $formats = [
        'n/j/Y',      // American: 6/29/1992
        'j/n/Y',      // European: 29/6/1992  
        'Y-m-d H:i:s',
        'Y/m/d',
        'd/m/Y',
        'd-m-Y',
        'm/d/Y',
        'm-d-Y'
    ];

    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $textDate);
        if ($date !== false) {
            return $date->format('Y-m-d');
        }
    }

    // Try strtotime as last resort
    $timestamp = strtotime($textDate);
    if ($timestamp !== false) {
        return date('Y-m-d', $timestamp);
    }

    return null;
}
?>