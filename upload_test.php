<?php
// upload_test.php - Test file upload functionality

header('Content-Type: application/json; charset=utf-8');

// Create uploads directory if it doesn't exist
$uploadsDir = 'uploads';
if (!file_exists($uploadsDir)) {
    if (mkdir($uploadsDir, 0755, true)) {
        echo json_encode(['status' => 'uploads directory created successfully']);
    } else {
        echo json_encode(['error' => 'Failed to create uploads directory']);
        exit;
    }
} else {
    echo json_encode(['status' => 'uploads directory exists']);
}

// Check if directory is writable
if (is_writable($uploadsDir)) {
    echo "\n" . json_encode(['status' => 'uploads directory is writable']);
} else {
    echo "\n" . json_encode(['error' => 'uploads directory is not writable']);
    echo "\n" . json_encode(['suggestion' => 'Run: chmod 755 uploads']);
}

// Check current permissions
$perms = fileperms($uploadsDir);
echo "\n" . json_encode(['directory_permissions' => substr(sprintf('%o', $perms), -4)]);

// Test file creation
$testFile = $uploadsDir . '/test.txt';
if (file_put_contents($testFile, 'Test file created at ' . date('Y-m-d H:i:s'))) {
    echo "\n" . json_encode(['status' => 'Test file created successfully']);
    unlink($testFile); // Clean up
} else {
    echo "\n" . json_encode(['error' => 'Failed to create test file']);
}

// Display PHP upload settings
echo "\n" . json_encode([
    'php_settings' => [
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'max_file_uploads' => ini_get('max_file_uploads'),
        'file_uploads' => ini_get('file_uploads') ? 'enabled' : 'disabled'
    ]
]);
?>