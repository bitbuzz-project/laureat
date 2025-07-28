<?php
// admin_api.php - Admin API for managing diploma requests

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

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
    case 'get_requests':
        getRequests($pdo);
        break;
    case 'update_status':
        updateRequestStatus($pdo);
        break;
    case 'save_notes':
        saveAdminNotes($pdo);
        break;
    case 'get_statistics':
        getStatistics($pdo);
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

function getRequests($pdo) {
    try {
        // Create diploma_requests table if it doesn't exist
        $createTable = "CREATE TABLE IF NOT EXISTS diploma_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            request_number VARCHAR(20) UNIQUE NOT NULL,
            cod_etu VARCHAR(20) NOT NULL,
            student_data TEXT,
            national_id_file VARCHAR(255),
            success_cert_file VARCHAR(255),
            bac_cert_file VARCHAR(255),
            status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            admin_notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_cod_etu (cod_etu),
            INDEX idx_status (status)
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
        
        // If no requests exist, create some sample data for testing
        if (empty($requests)) {
            createSampleRequests($pdo);
            $stmt->execute();
            $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
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

function createSampleRequests($pdo) {
    // Create some sample diploma requests for testing
    $sampleRequests = [
        [
            'request_number' => 'REQ-' . date('Ymd') . '-0001',
            'cod_etu' => '11001289',
            'student_data' => json_encode([
                'COD_ETU' => '11001289',
                'LIB_NOM_PAT_IND' => 'FAHMI',
                'LIB_PR1_IND' => 'HAJAR',
                'LIB_NOM_IND_ARB' => 'فهمي',
                'LIB_PRN_IND_ARB' => 'هاجر',
                'CIN_IND' => 'W369920'
            ]),
            'status' => 'pending'
        ],
        [
            'request_number' => 'REQ-' . date('Ymd') . '-0002',
            'cod_etu' => '11001299',
            'student_data' => json_encode([
                'COD_ETU' => '11001299',
                'LIB_NOM_PAT_IND' => 'HACHIMI',
                'LIB_PR1_IND' => 'YASSINE',
                'LIB_NOM_IND_ARB' => 'هاشيمي',
                'LIB_PRN_IND_ARB' => 'ياسين',
                'CIN_IND' => 'W372149'
            ]),
            'status' => 'approved'
        ]
    ];
    
    $insertQuery = "INSERT IGNORE INTO diploma_requests 
                    (request_number, cod_etu, student_data, status) 
                    VALUES (?, ?, ?, ?)";
    $stmt = $pdo->prepare($insertQuery);
    
    foreach ($sampleRequests as $request) {
        $stmt->execute([
            $request['request_number'],
            $request['cod_etu'],
            $request['student_data'],
            $request['status']
        ]);
    }
}

function updateRequestStatus($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    $requestId = $data['request_id'] ?? '';
    $status = $data['status'] ?? '';
    
    if (!in_array($status, ['pending', 'approved', 'rejected'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        return;
    }
    
    try {
        $query = "UPDATE diploma_requests SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$status, $requestId]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode([
                'success' => true, 
                'message' => 'Status updated successfully'
            ]);
        } else {
            echo json_encode([
                'success' => false, 
                'message' => 'Request not found'
            ]);
        }
        
    } catch(PDOException $e) {
        echo json_encode([
            'success' => false, 
            'message' => 'Database error: ' . $e->getMessage()
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
                'message' => 'Notes saved successfully'
            ]);
        } else {
            echo json_encode([
                'success' => false, 
                'message' => 'Request not found'
            ]);
        }
        
    } catch(PDOException $e) {
        echo json_encode([
            'success' => false, 
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
}

function getStatistics($pdo) {
    try {
        // Ensure table exists first
        $pdo->exec("CREATE TABLE IF NOT EXISTS diploma_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            request_number VARCHAR(20) UNIQUE NOT NULL,
            cod_etu VARCHAR(20) NOT NULL,
            student_data TEXT,
            status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        $query = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
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
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
}
?>