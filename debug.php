<?php
// debug_connection.php - لتشخيص مشكلة الاتصال

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// إظهار الأخطاء للتشخيص
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo json_encode([
    'status' => 'API is working',
    'timestamp' => date('Y-m-d H:i:s'),
    'php_version' => phpversion(),
    'server' => $_SERVER['SERVER_NAME'] ?? 'unknown'
]);

// اختبار الاتصال بقاعدة البيانات
try {
    // **عدّل هذه الإعدادات حسب خادمك**
    $host = 'localhost';
    $dbname = 'laureat';        // اسم قاعدة البيانات
    $username = 'root';         // اسم المستخدم
    $password = '';             // كلمة المرور
    
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "\n" . json_encode([
        'database_status' => 'Connected successfully',
        'database_name' => $dbname
    ]);
    
    // اختبار جدول laureat
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM laureat");
    $count = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "\n" . json_encode([
        'table_status' => 'laureat table accessible',
        'student_count' => $count['count']
    ]);
    
} catch (PDOException $e) {
    echo "\n" . json_encode([
        'database_error' => $e->getMessage()
    ]);
}
?>