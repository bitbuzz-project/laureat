<?php
// api.php - Debug version with better error handling

// Turn off HTML error display and ensure JSON output
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// CORS Headers - MUST be first
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

// Function to send JSON response safely
function sendJsonResponse($data, $httpCode = 200) {
    // Clear any previous output
    if (ob_get_level()) {
        ob_clean();
    }
    
    http_response_code($httpCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

// Function to handle fatal errors
function handleFatalError() {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_COMPILE_ERROR)) {
        sendJsonResponse([
            'success' => false,
            'message' => 'خطأ في الخادم',
            'debug' => 'Fatal error occurred'
        ], 500);
    }
}
register_shutdown_function('handleFatalError');

// Start output buffering to catch any unwanted output
ob_start();

try {
    // Create uploads directory if it doesn't exist
    $uploadsDir = 'uploads';
    if (!file_exists($uploadsDir)) {
        mkdir($uploadsDir, 0755, true);
    }

    // Database configuration - UPDATE THESE IF NEEDED
    $host = 'localhost';
    $dbname = 'laureat';  // Make sure this database exists
    $username = 'root';   // Your MySQL username
    $password = '';       // Your MySQL password

    // Test database connection first
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Test if laureat table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'laureat'");
        if ($stmt->rowCount() == 0) {
            sendJsonResponse([
                'success' => false,
                'message' => 'جدول البيانات غير موجود',
                'debug' => 'Table "laureat" not found in database'
            ], 500);
        }
        
    } catch (PDOException $e) {
        sendJsonResponse([
            'success' => false,
            'message' => 'خطأ في الاتصال بقاعدة البيانات',
            'debug' => $e->getMessage(),
            'connection_info' => [
                'host' => $host,
                'database' => $dbname,
                'username' => $username
            ]
        ], 500);
    }

    // Create diploma_requests table if it doesn't exist
    createDiplomaRequestsTable($pdo);

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
                    'method' => $_SERVER['REQUEST_METHOD'],
                    'uploads_dir' => is_writable($uploadsDir) ? 'writable' : 'not writable',
                    'database_connected' => 'yes'
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

        case 'check_status':
            handleCheckStatus($pdo);
            break;

        case 'check_existing_request':
            handleCheckExistingRequest($pdo);
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
        'debug' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], 500);
}

function createDiplomaRequestsTable($pdo) {
    try {
        $createTable = "CREATE TABLE IF NOT EXISTS diploma_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            request_number VARCHAR(20) UNIQUE NOT NULL,
            cod_etu VARCHAR(20) NOT NULL,
            student_data TEXT,
            national_id_file VARCHAR(255),
            success_cert_file VARCHAR(255),
            bac_cert_file VARCHAR(255),
            status ENUM('تم ارسال الطلب', 'قيد المعالجة', 'طلب معالج يرجى الالتحاق بالمصلحة لسحب الديبلوم', 'طلب مرفوض') DEFAULT 'تم ارسال الطلب',
            admin_notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_cod_etu (cod_etu),
            INDEX idx_status (status),
            INDEX idx_request_number (request_number),
            UNIQUE KEY unique_student_request (cod_etu)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $pdo->exec($createTable);
    } catch (Exception $e) {
        sendJsonResponse([
            'success' => false,
            'message' => 'خطأ في إنشاء جدول الطلبات',
            'debug' => $e->getMessage()
        ], 500);
    }
}

function handleLogin($pdo) {
    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (!$data) {
            sendJsonResponse([
                'success' => false,
                'message' => 'بيانات غير صحيحة',
                'debug' => 'Invalid JSON input'
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
            // Check if student already has a request
            $checkStmt = $pdo->prepare("SELECT * FROM diploma_requests WHERE cod_etu = ? LIMIT 1");
            $checkStmt->execute([$cod_etu]);
            $existingRequest = $checkStmt->fetch(PDO::FETCH_ASSOC);

            // Convert date to Latin format before sending
            $student['DATE_NAI_IND'] = $storedDate;
            
            sendJsonResponse([
                'success' => true,
                'student' => $student,
                'existing_request' => $existingRequest,
                'message' => 'تم تسجيل الدخول بنجاح'
            ]);
        } else {
            sendJsonResponse([
                'success' => false,
                'message' => 'تاريخ الميلاد غير صحيح',
                'debug' => [
                    'stored_date' => $storedDate,
                    'provided_date' => $date_nai,
                    'original_stored' => $student['DATE_NAI_IND']
                ]
            ]);
        }

    } catch (Exception $e) {
        sendJsonResponse([
            'success' => false,
            'message' => 'خطأ في تسجيل الدخول',
            'debug' => $e->getMessage()
        ], 500);
    }
}

function handleCheckExistingRequest($pdo) {
    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        $codEtu = trim($data['cod_etu'] ?? '');

        if (empty($codEtu)) {
            sendJsonResponse([
                'success' => false,
                'message' => 'رمز الطالب مطلوب'
            ]);
        }

        $stmt = $pdo->prepare("SELECT * FROM diploma_requests WHERE cod_etu = ? LIMIT 1");
        $stmt->execute([$codEtu]);
        $existingRequest = $stmt->fetch(PDO::FETCH_ASSOC);

        sendJsonResponse([
            'success' => true,
            'has_request' => !empty($existingRequest),
            'request' => $existingRequest
        ]);

    } catch (Exception $e) {
        sendJsonResponse([
            'success' => false,
            'message' => 'خطأ في التحقق من الطلب',
            'debug' => $e->getMessage()
        ], 500);
    }
}

function handleUpdateStudent($pdo) {
    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        $codEtu = $data['cod_etu'] ?? '';
        $updates = $data['updates'] ?? [];

        if (empty($codEtu) || empty($updates)) {
            sendJsonResponse([
                'success' => false,
                'message' => 'بيانات غير صحيحة'
            ]);
        }

        // Build update query
        $setClause = [];
        $values = [];
        
        foreach ($updates as $field => $value) {
            // Validate field names to prevent SQL injection
            $allowedFields = [
                'LIB_NOM_IND_ARB', 'LIB_PRN_IND_ARB', 'LIB_NOM_PAT_IND', 
                'LIB_PR1_IND', 'CIN_IND', 'LIB_VIL_NAI_ETU'
            ];
            
            if (in_array($field, $allowedFields)) {
                $setClause[] = "$field = ?";
                $values[] = $value;
            }
        }

        if (!empty($setClause)) {
            $values[] = $codEtu;
            $query = "UPDATE laureat SET " . implode(', ', $setClause) . " WHERE COD_ETU = ?";
            $stmt = $pdo->prepare($query);
            $stmt->execute($values);
        }

        sendJsonResponse([
            'success' => true,
            'message' => 'تم تحديث البيانات بنجاح'
        ]);

    } catch (Exception $e) {
        sendJsonResponse([
            'success' => false,
            'message' => 'خطأ في تحديث البيانات',
            'debug' => $e->getMessage()
        ], 500);
    }
}

function handleSubmitRequest($pdo) {
    try {
        $codEtu = $_POST['cod_etu'] ?? '';
        $studentData = $_POST['student_data'] ?? '';

        if (empty($codEtu) || empty($studentData)) {
            sendJsonResponse([
                'success' => false,
                'message' => 'بيانات الطلب غير مكتملة'
            ]);
        }

        // Check if student already has a request
        $checkStmt = $pdo->prepare("SELECT id FROM diploma_requests WHERE cod_etu = ?");
        $checkStmt->execute([$codEtu]);
        
        if ($checkStmt->fetch()) {
            sendJsonResponse([
                'success' => false,
                'message' => 'لديك طلب موجود بالفعل. لا يمكنك تقديم طلب آخر.'
            ]);
        }

        // Generate unique request number
        $requestNumber = 'REQ-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Check if request number already exists
        $checkStmt = $pdo->prepare("SELECT id FROM diploma_requests WHERE request_number = ?");
        $checkStmt->execute([$requestNumber]);
        
        // If exists, generate a new one
        while ($checkStmt->fetch()) {
            $requestNumber = 'REQ-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $checkStmt->execute([$requestNumber]);
        }

        // Handle file uploads
        $uploadedFiles = [];
        $fileFields = ['national_id_file', 'success_cert_file', 'bac_cert_file'];
        
        foreach ($fileFields as $field) {
            if (isset($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES[$field];
                
                // Validate file size (5MB max)
                if ($file['size'] > 5 * 1024 * 1024) {
                    sendJsonResponse([
                        'success' => false,
                        'message' => 'حجم الملف كبير جداً. الحد الأقصى 5 ميجابايت'
                    ]);
                }
                
                // Validate file type
                $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'application/pdf'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);
                
                if (!in_array($mimeType, $allowedTypes)) {
                    sendJsonResponse([
                        'success' => false,
                        'message' => 'نوع الملف غير مدعوم. يُقبل فقط: JPG, PNG, GIF, PDF'
                    ]);
                }
                
                // Generate unique filename
                $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = $requestNumber . '_' . $field . '_' . time() . '.' . $extension;
                $targetPath = 'uploads/' . $filename;
                
                // Move uploaded file
                if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                    $uploadedFiles[$field] = $filename;
                } else {
                    sendJsonResponse([
                        'success' => false,
                        'message' => 'خطأ في رفع الملف: ' . $field
                    ]);
                }
            }
        }

        // Insert request into database with default status 'تم ارسال الطلب'
        $query = "INSERT INTO diploma_requests 
                  (request_number, cod_etu, student_data, national_id_file, success_cert_file, bac_cert_file, status) 
                  VALUES (?, ?, ?, ?, ?, ?, 'تم ارسال الطلب')";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            $requestNumber,
            $codEtu,
            $studentData,
            $uploadedFiles['national_id_file'] ?? null,
            $uploadedFiles['success_cert_file'] ?? null,
            $uploadedFiles['bac_cert_file'] ?? null
        ]);

        sendJsonResponse([
            'success' => true,
            'message' => 'تم إرسال الطلب بنجاح',
            'request_number' => $requestNumber
        ]);

    } catch (Exception $e) {
        // Check if it's a duplicate entry error
        if ($e->getCode() == 23000 && strpos($e->getMessage(), 'unique_student_request') !== false) {
            sendJsonResponse([
                'success' => false,
                'message' => 'لديك طلب موجود بالفعل. لا يمكنك تقديم طلب آخر.'
            ]);
        }
        
        sendJsonResponse([
            'success' => false,
            'message' => 'خطأ في إرسال الطلب',
            'debug' => $e->getMessage()
        ], 500);
    }
}

function handleCheckStatus($pdo) {
    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        $codEtu = trim($data['cod_etu'] ?? '');

        if (empty($codEtu)) {
            sendJsonResponse([
                'success' => false,
                'message' => 'يرجى إدخال رمز الطالب'
            ]);
        }

        // Get all requests for this student
        $query = "SELECT * FROM diploma_requests WHERE cod_etu = ? ORDER BY created_at DESC";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$codEtu]);
        
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($requests)) {
            sendJsonResponse([
                'success' => false,
                'message' => 'لم يتم العثور على أي طلب لهذا الرمز'
            ]);
        }

        // Convert dates to Latin format in requests
        foreach ($requests as &$request) {
            if (isset($request['created_at'])) {
                $request['created_at_latin'] = date('Y-m-d H:i:s', strtotime($request['created_at']));
            }
            if (isset($request['updated_at'])) {
                $request['updated_at_latin'] = date('Y-m-d H:i:s', strtotime($request['updated_at']));
            }
        }

        sendJsonResponse([
            'success' => true,
            'requests' => $requests,
            'message' => 'تم العثور على ' . count($requests) . ' طلب(ات)'
        ]);

    } catch (Exception $e) {
        sendJsonResponse([
            'success' => false,
            'message' => 'خطأ في البحث عن الطلب',
            'debug' => $e->getMessage()
        ], 500);
    }
}

function convertTextDateToStandard($textDate) {
    if (empty($textDate) || $textDate === '0000-00-00' || $textDate === 'NULL') {
        return null;
    }

    $textDate = trim($textDate);

    // Remove Arabic/Hijri date patterns and extract Latin date
    // Handle patterns like "٤‏/٢‏/١٤٤٧ هـ" - skip Hijri dates
    if (preg_match('/[\u0660-\u0669\u06F0-\u06F9]/', $textDate) || strpos($textDate, 'هـ') !== false) {
        // This is likely a Hijri date, we need to convert or handle it differently
        // For now, return null or a default value
        return null;
    }

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

// Clear output buffer and end
ob_end_clean();
?>