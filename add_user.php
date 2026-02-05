<?php
/**
 * Add User to MikroTik Hotspot
 * Backend API for UMPKU WiFi Hotspot
 */

header('Content-Type: application/json');

// MikroTik Configuration
define('MIKROTIK_IP', '192.168.200.1');     // IP MikroTik
define('MIKROTIK_USER', 'api_user');         // Username MikroTik
define('MIKROTIK_PASS', '');                 // Password MikroTik
define('MIKROTIK_PORT', 8728);               // Port API

// Response helper
function jsonResponse($success, $message, $data = null) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Method not allowed');
}

// Get and validate input
$username = trim($_POST['username'] ?? '');
$password = trim($_POST['password'] ?? '');
$confirmPassword = trim($_POST['confirm_password'] ?? '');
$profile = 'AnakMagang'; // Profile default untuk semua user

// Validation
if (empty($username)) {
    jsonResponse(false, 'Username harus diisi!');
}

if (empty($password)) {
    jsonResponse(false, 'Password harus diisi!');
}

if ($password !== $confirmPassword) {
    jsonResponse(false, 'Password tidak cocok!');
}

if (strlen($password) < 6) {
    jsonResponse(false, 'Password minimal 6 karakter!');
}

// Sanitize username (alphanumeric, underscore, dash only)
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $username)) {
    jsonResponse(false, 'Username hanya boleh mengandung huruf, angka, underscore, dan dash!');
}

// Try to add user to MikroTik
try {
    require_once 'RouterOS_API.php';
    
    $api = new RouterosAPI();
    $api->debug = false;
    
    if ($api->connect(MIKROTIK_IP, MIKROTIK_USER, MIKROTIK_PASS, MIKROTIK_PORT)) {
        
        // Check if user already exists
        $existingUsers = $api->comm('/ip/hotspot/user/print', [
            '?name' => $username
        ]);
        
        if (!empty($existingUsers)) {
            $api->disconnect();
            jsonResponse(false, 'Username sudah terdaftar!');
        }
        
        // Add new user
        $response = $api->comm('/ip/hotspot/user/add', [
            'name' => $username,
            'password' => $password,
            'profile' => $profile,
            'comment' => 'Added via Web Portal - ' . date('Y-m-d H:i:s')
        ]);
        
        $api->disconnect();
        
        if (isset($response['!trap'])) {
            jsonResponse(false, 'Gagal menambahkan user: ' . ($response['!trap'][0]['message'] ?? 'Unknown error'));
        }
        
        jsonResponse(true, 'User berhasil ditambahkan!', [
            'username' => $username,
            'profile' => $profile
        ]);
        
    } else {
        jsonResponse(false, 'Gagal terhubung ke MikroTik!');
    }
    
} catch (Exception $e) {
    jsonResponse(false, 'Error: ' . $e->getMessage());
}
