<?php
// admin_api.php - Fixed Admin API with proper table handling and status updates

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// Start session for authentication
session_start();

// Database configuration - same as api.php
$host = 'localhost';
$dbname = 'laureat';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

$action = $_GET['action'] ?? '';

switch($action) {
    case 'login':
        adminLogin($pdo);
        break;
    case 'check_auth':
        checkAuthentication();
        break;
    case 'logout':
        adminLogout();
        break;
    case 'get_requests':
        if (isAuthenticated()) {
            getRequests($pdo);
        } else {
            echo json_encode(['success' => false, 'message' => 'غير مخول للوصول']);
        }
        break;
    case 'update_status':
        if (isAuthenticated()) {
            updateRequestStatus($pdo);
        } else {
            echo json_encode(['success' => false, 'message' => 'غير مخول للوصول']);
        }
        break;
    case 'save_notes':
        if (isAuthenticated()) {
            saveAdminNotes($pdo);
        } else {
            echo json_encode(['success' => false, 'message' => 'غير مخول للوصول']);
        }
        break;
    case 'get_statistics':
        if (isAuthenticated()) {
            getStatistics($pdo);
        } else {
            echo json_encode(['success' => false, 'message' => 'غير مخول للوصول']);
        }
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

function adminLogin($pdo) {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    $username = trim($data['username'] ?? '');
    $password = $data['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        echo json_encode([
            'success' => false,
            'message' => 'يرجى إدخال اسم المستخدم وكلمة المرور'
        ]);
        return;
    }
    
    // Create/fix admin users table if needed
    createAdminUsersTable($pdo);
    
    // Special handling for default admin credentials
    if ($username === 'admin' && $password === 'admin123') {
        try {
            $hashedPassword = password_hash('admin123', PASSWORD_DEFAULT);
            
            // Check if admin user exists
            $checkStmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = ?");
            $checkStmt->execute(['admin']);
            $existingAdmin = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingAdmin) {
                // Update existing admin with correct password
                $updateStmt = $pdo->prepare("UPDATE admin_users SET password_hash = ? WHERE username = ?");
                $updateStmt->execute([$hashedPassword, 'admin']);
                
                $admin = [
                    'id' => $existingAdmin['id'],
                    'username' => 'admin',
                    'full_name' => $existingAdmin['full_name'] ?: 'مدير النظام'
                ];
            } else {
                // Create new admin user
                $insertStmt = $pdo->prepare("INSERT INTO admin_users (username, password_hash, full_name, email) VALUES (?, ?, ?, ?)");
                $insertStmt->execute(['admin', $hashedPassword, 'مدير النظام', 'admin@laureat.ma']);
                
                $admin = [
                    'id' => $pdo->lastInsertId(),
                    'username' => 'admin',
                    'full_name' => 'مدير النظام'
                ];
            }
            
            // Set session variables
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            $_SESSION['admin_name'] = $admin['full_name'];
            $_SESSION['admin_logged_in'] = true;
            
            // Try to update last_login if column exists
            try {
                $updateLoginStmt = $pdo->prepare("UPDATE admin_users SET last_login = NOW() WHERE id = ?");
                $updateLoginStmt->execute([$admin['id']]);
            } catch (Exception $e) {
                // Ignore if last_login column doesn't exist
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'تم تسجيل الدخول بنجاح',
                'admin' => [
                    'name' => $admin['full_name'],
                    'username' => $admin['username']
                ]
            ]);
            return;
            
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'خطأ في المصادقة: ' . $e->getMessage()
            ]);
            return;
        }
    }
    
    // Try with database stored credentials
    try {
        $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = ?");
        $stmt->execute([$username]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Check if user exists and is active (if is_active column exists)
        $isActive = true;
        if ($admin && isset($admin['is_active'])) {
            $isActive = (bool)$admin['is_active'];
        }
        
        if ($admin && $isActive && password_verify($password, $admin['password_hash'])) {
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            $_SESSION['admin_name'] = $admin['full_name'] ?? 'مدير النظام';
            $_SESSION['admin_logged_in'] = true;
            
            // Try to update last_login if column exists
            try {
                $updateStmt = $pdo->prepare("UPDATE admin_users SET last_login = NOW() WHERE id = ?");
                $updateStmt->execute([$admin['id']]);
            } catch (Exception $e) {
                // Ignore if last_login column doesn't exist
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'تم تسجيل الدخول بنجاح',
                'admin' => [
                    'name' => $admin['full_name'] ?? 'مدير النظام',
                    'username' => $admin['username']
                ]
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'اسم المستخدم أو كلمة المرور غير صحيحة'
            ]);
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'خطأ في قاعدة البيانات: ' . $e->getMessage()
        ]);
    }
}

function createAdminUsersTable($pdo) {
    try {
        // Check if table exists first
        $stmt = $pdo->query("SHOW TABLES LIKE 'admin_users'");
        $tableExists = $stmt->rowCount() > 0;
        
        if (!$tableExists) {
            // Create table with all required columns
            $createTable = "CREATE TABLE admin_users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) UNIQUE NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                full_name VARCHAR(100) NOT NULL,
                email VARCHAR(100),
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_login TIMESTAMP NULL,
                INDEX idx_username (username)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            $pdo->exec($createTable);
        } else {
            // Table exists, check for missing columns and add them
            $columns = [];
            $stmt = $pdo->query("DESCRIBE admin_users");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $columns[] = $row['Field'];
            }
            
            // Add missing columns
            $requiredColumns = [
                'is_active' => 'TINYINT(1) DEFAULT 1',
                'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
                'last_login' => 'TIMESTAMP NULL'
            ];
            
            foreach ($requiredColumns as $columnName => $columnDefinition) {
                if (!in_array($columnName, $columns)) {
                    try {
                        $pdo->exec("ALTER TABLE admin_users ADD COLUMN $columnName $columnDefinition");
                    } catch (Exception $e) {
                        // Ignore errors for missing columns
                    }
                }
            }
        }
        
        // Ensure there's always a default admin user
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM admin_users WHERE username = 'admin'");
        $checkStmt->execute();
        
        if ($checkStmt->fetchColumn() == 0) {
            $defaultPassword = password_hash('admin123', PASSWORD_DEFAULT);
            $insertStmt = $pdo->prepare("INSERT INTO admin_users (username, password_hash, full_name, email) VALUES (?, ?, ?, ?)");
            $insertStmt->execute(['admin', $defaultPassword, 'مدير النظام', 'admin@laureat.ma']);
        }
        
    } catch (Exception $e) {
        // Log error but continue - we'll handle authentication differently if needed
        error_log("Admin table creation error: " . $e->getMessage());
    }
}

function isAuthenticated() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

function checkAuthentication() {
    if (isAuthenticated()) {
        echo json_encode([
            'success' => true,
            'authenticated' => true,
            'admin' => [
                'name' => $_SESSION['admin_name'] ?? 'مدير النظام',
                'username' => $_SESSION['admin_username'] ?? 'admin'
            ]
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'authenticated' => false
        ]);
    }
}

function adminLogout() {
    session_destroy();
    echo json_encode([
        'success' => true,
        'message' => 'تم تسجيل الخروج بنجاح'
    ]);
}

function getRequests($pdo) {
    try {
        // Create diploma_requests table if it doesn't exist with updated status options
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
            UNIQUE KEY unique_student_request (cod_etu)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $pdo->exec($createTable);
        
        // Get all requests with student info
        $query = "SELECT dr.*, l.LIB_NOM_PAT_IND, l.LIB_PR1_IND, l.LIB_NOM_IND_ARB, l.LIB_PRN_IND_ARB 
                  FROM diploma_requests dr 
                  LEFT JOIN laureat l ON dr.cod_etu = l.COD_ETU 
                  ORDER BY dr.created_at DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Convert dates to Latin format
        foreach ($requests as &$request) {
            if (isset($request['created_at'])) {
                $request['created_at_latin'] = date('Y-m-d H:i:s', strtotime($request['created_at']));
            }
            if (isset($request['updated_at'])) {
                $request['updated_at_latin'] = date('Y-m-d H:i:s', strtotime($request['updated_at']));
            }
        }
        
        echo json_encode([
            'success' => true, 
            'requests' => $requests
        ]);
        
    } catch(PDOException $e) {
        echo json_encode([
            'success' => false, 
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
}

function updateRequestStatus($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    $requestId = $data['request_id'] ?? '';
    $status = $data['status'] ?? '';
    
    $validStatuses = ['تم ارسال الطلب', 'قيد المعالجة', 'طلب معالج يرجى الالتحاق بالمصلحة لسحب الديبلوم', 'طلب مرفوض'];
    
    if (!in_array($status, $validStatuses)) {
        echo json_encode(['success' => false, 'message' => 'حالة غير صحيحة']);
        return;
    }
    
    try {
        $query = "UPDATE diploma_requests SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$status, $requestId]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode([
                'success' => true, 
                'message' => 'تم تحديث حالة الطلب بنجاح'
            ]);
        } else {
            echo json_encode([
                'success' => false, 
                'message' => 'لم يتم العثور على الطلب'
            ]);
        }
        
    } catch(PDOException $e) {
        echo json_encode([
            'success' => false, 
            'message' => 'خطأ في قاعدة البيانات: ' . $e->getMessage()
        ]);
    }
}

function saveAdminNotes($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    $requestId = $data['request_id'] ?? '';
    $notes = $data['notes'] ?? '';
    
    try {
        $query = "UPDATE diploma_requests SET admin_notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$notes, $requestId]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode([
                'success' => true, 
                'message' => 'تم حفظ الملاحظات بنجاح'
            ]);
        } else {
            echo json_encode([
                'success' => false, 
                'message' => 'لم يتم العثور على الطلب'
            ]);
        }
        
    } catch(PDOException $e) {
        echo json_encode([
            'success' => false, 
            'message' => 'خطأ في قاعدة البيانات: ' . $e->getMessage()
        ]);
    }
}

function getStatistics($pdo) {
    try {
        $query = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'تم ارسال الطلب' THEN 1 ELSE 0 END) as submitted,
                    SUM(CASE WHEN status = 'قيد المعالجة' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'طلب معالج يرجى الالتحاق بالمصلحة لسحب الديبلوم' THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN status = 'طلب مرفوض' THEN 1 ELSE 0 END) as rejected
                  FROM diploma_requests";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true, 
            'statistics' => $stats
        ]);
        
    } catch(PDOException $e) {
        echo json_encode([
            'success' => false, 
            'message' => 'خطأ في قاعدة البيانات: ' . $e->getMessage()
        ]);
    }
}
?>