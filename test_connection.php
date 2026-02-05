<?php
/**
 * Test Koneksi MikroTik API
 */

require_once 'RouterOS_API.php';

// Konfigurasi
$ip = '192.168.200.1';
$user = 'api_user';
$pass = '';
$port = 8728;

echo "<h2>Test Koneksi MikroTik API</h2>";
echo "<p>IP: $ip:$port</p>";
echo "<p>User: $user</p>";
echo "<hr>";

$api = new RouterosAPI();
$api->debug = false;

if ($api->connect($ip, $user, $pass, $port)) {
    echo "<p style='color:green;'><b>✅ Koneksi BERHASIL!</b></p>";
    
    // Test ambil daftar user hotspot
    echo "<h3>Daftar User Hotspot:</h3>";
    $users = $api->comm('/ip/hotspot/user/print');
    
    if (!empty($users)) {
        echo "<table border='1' cellpadding='8' cellspacing='0'>";
        echo "<tr><th>No</th><th>Name</th><th>Profile</th><th>Comment</th></tr>";
        $no = 1;
        foreach ($users as $u) {
            echo "<tr>";
            echo "<td>" . $no++ . "</td>";
            echo "<td>" . ($u['name'] ?? '-') . "</td>";
            echo "<td>" . ($u['profile'] ?? '-') . "</td>";
            echo "<td>" . ($u['comment'] ?? '-') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>Tidak ada user hotspot.</p>";
    }
    
    // Test ambil daftar profile
    echo "<h3>Daftar Profile Hotspot:</h3>";
    $profiles = $api->comm('/ip/hotspot/user/profile/print');
    
    if (!empty($profiles)) {
        echo "<ul>";
        foreach ($profiles as $p) {
            echo "<li>" . ($p['name'] ?? '-') . "</li>";
        }
        echo "</ul>";
    }
    
    $api->disconnect();
    
} else {
    echo "<p style='color:red;'><b>❌ Koneksi GAGAL!</b></p>";
    echo "<p>Pastikan:</p>";
    echo "<ul>";
    echo "<li>IP MikroTik benar: $ip</li>";
    echo "<li>Port API aktif: $port</li>";
    echo "<li>User '$user' ada dan punya permission 'api'</li>";
    echo "<li>Firewall tidak memblokir port $port</li>";
    echo "</ul>";
}
