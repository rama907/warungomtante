<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'warungom_db_absensi_omtante');
define('DB_PASS', 'dWw5rsKaF5q47V9JZHEd');
define('DB_NAME', 'warungom_db_absensi_omtante');

// Create connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset
$conn->set_charset("utf8");

// Start session
session_start();

// Function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Function to check user role
function hasRole($roles) {
    if (!isLoggedIn()) return false;
    return in_array($_SESSION['role'], $roles);
}

// Function to get current user
function getCurrentUser() {
    global $conn;
    if (!isLoggedIn()) return null;
    
    $stmt = $conn->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

// Function to format duration
function formatDuration($minutes) {
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    return $hours . 'j ' . $mins . 'm';
}

// Function to get role display name
function getRoleDisplayName($role) {
    $roles = [
        'direktur' => 'Direktur',
        'wakil_direktur' => 'Wakil Direktur',
        'manager' => 'Manager',
        'chef' => 'Chef',
        'karyawan' => 'Karyawan',
        'magang' => 'Magang'
    ];
    return $roles[$role] ?? ucfirst($role);
}

// Function to send notification (placeholder for Discord integration)
function sendDiscordNotification($message, $type = 'info') {
    // This would integrate with Discord webhook
    // For now, we'll just log it with better formatting
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[$timestamp] Discord Notification [$type]: $message";
    error_log($log_message);
    
    // You can add actual Discord webhook integration here
    // Example:
    /*
    $webhook_url = "YOUR_DISCORD_WEBHOOK_URL";
    $data = [
        'content' => $message,
        'username' => 'Warung Om Tante Bot'
    ];
    
    $options = [
        'http' => [
            'header' => "Content-type: application/json\r\n",
            'method' => 'POST',
            'content' => json_encode($data)
        ]
    ];
    
    $context = stream_context_create($options);
    @file_get_contents($webhook_url, false, $context);
    */
}
// Function to get total pending requests
function getPendingRequestCount() {
    global $conn;
    $count = 0;

    // Count pending leave requests
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM leave_requests WHERE status = 'pending'");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $count += $result['count'];
        $stmt->close();
    }

    // Count pending resignation requests
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM resignation_requests WHERE status = 'pending'");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $count += $result['count'];
        $stmt->close();
    }
    
    // Count pending manual duty requests
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM manual_duty_requests WHERE status = 'pending'");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $count += $result['count'];
        $stmt->close();
    }

    return $count;
}
?>
