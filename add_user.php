<?php
/**
 * Add User to MikroTik Hotspot
 * Backend API for UMPKU WiFi Hotspot
 */

// Disable output buffering
while (ob_get_level()) ob_end_clean();

// Set headers early
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');
header('Connection: close');

// MikroTik Configuration
define('MIKROTIK_IP', '192.168.200.1');     // IP MikroTik
define('MIKROTIK_USER', 'api_user');         // Username MikroTik
define('MIKROTIK_PASS', 'newuser');          // Password MikroTik
define('MIKROTIK_PORT', 8728);               // Port API

// Response helper - sends response and exits immediately
function jsonResponse($success, $message, $data = null) {
    $response = json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    
    // Force send response
    ignore_user_abort(true);
    header('Content-Length: ' . strlen($response));
    echo $response;
    flush();
    
    // Close connection before any cleanup
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    
    exit(0);
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
$success = false;
$errorMsg = '';
$addedUsername = '';

try {
    require_once 'RouterOS_API.php';
    
    $api = new RouterosAPI();
    $api->debug = false;
    $api->timeout = 5;
    
    if ($api->connect(MIKROTIK_IP, MIKROTIK_USER, MIKROTIK_PASS, MIKROTIK_PORT)) {
        
        // Check if user already exists
        $allUsers = $api->comm('/ip/hotspot/user/print');
        $userExists = false;
        
        if (is_array($allUsers)) {
            foreach ($allUsers as $u) {
                if (is_array($u) && isset($u['name']) && $u['name'] === $username) {
                    $userExists = true;
                    break;
                }
            }
        }
        
        if ($userExists) {
            @$api->disconnect();
            jsonResponse(false, 'Username sudah terdaftar!');
        }
        
        // Add new user
        $response = $api->comm('/ip/hotspot/user/add', [
            'name' => $username,
            'password' => $password,
            'profile' => $profile,
            'server' => 'Hotspot_UMS',
            'comment' => 'Added via Web Register hospot UMPKU - ' . date('Y-m-d H:i:s')
        ]);
        
        // Disconnect segera
        @$api->disconnect();
        
        // Check for error
        if (is_array($response) && isset($response['!trap'])) {
            $errorMsg = $response['!trap'][0]['message'] ?? 'Unknown error';
        } else {
            $success = true;
            $addedUsername = $username;
        }
        
    } else {
        $errorMsg = 'Gagal terhubung ke MikroTik!';
    }
    
} catch (Exception $e) {
    $errorMsg = 'Error: ' . $e->getMessage();
}

// Kirim response setelah semua koneksi ditutup
if ($success) {
    jsonResponse(true, 'User berhasil ditambahkan!', [
        'username' => $addedUsername,
        'profile' => $profile
    ]);
} else {
    jsonResponse(false, $errorMsg ?: 'Gagal menambahkan user!');
}