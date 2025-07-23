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

// Fungsi untuk mengirim notifikasi ke Discord
function sendDiscordNotification($data, $type = 'info') {
    // URL Webhook Discord Anda
    // Webhook pertama (untuk SEMUA notifikasi)
    $general_webhook_url = 'https://discord.com/api/webhooks/1389455043362689189/xdkSg5PDEedaPuxLaxEGOythlsAFGIm5b-fb-GJh4tcCMgGar7t_uk9-XsMzObDF69Tx';

    // Webhook kedua (KHUSUS untuk pengajuan dan pembaruan status permohonan)
    // GANTI DENGAN URL WEBHOOK KEDUA ANDA DI SINI
    $request_webhook_url = 'https://discord.com/api/webhooks/1388990746073497620/OhXDG3FCAs1C2bMSttYJubDnEtwXl0OxSn8TKlbLu0JD5LJD1iaFp6Hwg_jcDkr6sHGF'; // <--- PASTIKAN INI DIGANTI!

    $webhooks_to_send = [$general_webhook_url]; // Default: selalu kirim ke webhook umum

    // Jika tipe notifikasi adalah pengajuan cuti/resign, atau pembaruan status permohonan, tambahkan webhook kedua
    if (in_array($type, ['leave_request_submitted', 'resignation_request_submitted', 'manual_duty_request_submitted', 'request_status_update'])) {
        // Pastikan URL webhook kedua telah diatur dan bukan placeholder
        if (!empty($request_webhook_url) && $request_webhook_url !== 'https://discord.com/api/webhooks/YOUR_SECOND_WEBHOOK_URL_HERE') {
            $webhooks_to_send[] = $request_webhook_url;
        } else {
            error_log("Peringatan: URL webhook khusus permohonan tidak dikonfigurasi. Notifikasi permohonan hanya dikirim ke webhook umum.");
        }
    }

    $username = "Warung Om Tante Bot";
    $avatar_url = ""; // GANTI DENGAN URL AVATAR BOT ANDA, misal logo warung

    // Definisikan warna untuk setiap tipe notifikasi
    $colors = [
        'info' => 3447003,    // Biru (contoh: Clock In/Out)
        'success' => 3066993, // Hijau (contoh: Disetujui)
        'warning' => 16776960,// Kuning (contoh: Permohonan Cuti)
        'danger' => 15158332, // Merah (contoh: Permohonan Resign, Ditolak)
        'employee_action' => 5793266, // Ungu (contoh: Perubahan Peran, Nonaktif Anggota)
    ];
    $color = $colors[$type] ?? 0; // Ambil warna berdasarkan tipe, default hitam

    // Inisialisasi embed dasar
    $embed = [
        'title' => '',
        'description' => '',
        'color' => $color,
        'timestamp' => date('c'), // Waktu saat notifikasi dikirim
        'footer' => [
            'text' => 'Warung Om Tante Management System',
        ],
    ];

    // Logika untuk mengisi embed berdasarkan 'type' dan 'data'
    switch ($type) {
        case 'clock_event': // Data: ['employee_name', 'event_type', 'duration']
            $employee_name = htmlspecialchars($data['employee_name']);
            if ($data['event_type'] === 'clock_in') {
                $embed['title'] = "⏰ Karyawan Mulai Bertugas!";
                $embed['description'] = "**{$employee_name}** telah mulai bertugas.";
                $embed['color'] = $colors['info'];
                $embed['fields'] = [
                    ['name' => 'Waktu Mulai', 'value' => date('H:i:s'), 'inline' => true],
                    ['name' => 'Status', 'value' => '🟢 On Duty', 'inline' => true],
                ];
            } elseif ($data['event_type'] === 'clock_out') {
                $embed['title'] = "⏸️ Karyawan Selesai Bertugas!";
                $embed['description'] = "**{$employee_name}** telah selesai bertugas.";
                $embed['color'] = $colors['info'];
                $embed['fields'] = [
                    ['name' => 'Waktu Selesai', 'value' => date('H:i:s'), 'inline' => true],
                    ['name' => 'Durasi Tugas', 'value' => htmlspecialchars($data['duration']), 'inline' => true],
                    ['name' => 'Status', 'value' => '🔴 Off Duty', 'inline' => true],
                ];
            }
            break;

        case 'leave_request_submitted': // Data: ['employee_name', 'start_date', 'end_date', 'reason_ooc', 'reason_ic']
            $employee_name = htmlspecialchars($data['employee_name']);
            $embed['title'] = "📝 Permohonan Cuti Baru!";
            $embed['description'] = "Karyawan **{$employee_name}** telah mengajukan permohonan cuti.";
            $embed['color'] = $colors['warning'];
            $embed['fields'] = [
                ['name' => 'Periode Cuti', 'value' => date('d/m/Y', strtotime($data['start_date'])) . ' - ' . date('d/m/Y', strtotime($data['end_date'])), 'inline' => true],
                ['name' => 'Status', 'value' => '🟡 Pending', 'inline' => true],
                ['name' => 'Alasan (OOC)', 'value' => empty($data['reason_ooc']) ? '-' : htmlspecialchars($data['reason_ooc'])],
                ['name' => 'Alasan (IC)', 'value' => empty($data['reason_ic']) ? '-' : htmlspecialchars($data['reason_ic'])],
            ];
            break;
        
        case 'resignation_request_submitted': // Data: ['employee_name', 'resignation_date', 'passport', 'cid', 'reason_ooc', 'reason_ic']
            $employee_name = htmlspecialchars($data['employee_name']);
            $embed['title'] = "📄 Permohonan Resign Baru!";
            $embed['description'] = "Karyawan **{$employee_name}** telah mengajukan permohonan resign.";
            $embed['color'] = $colors['danger'];
            $embed['fields'] = [
                ['name' => 'Tanggal Resign', 'value' => date('d/m/Y', strtotime($data['resignation_date'])), 'inline' => true],
                ['name' => 'Status', 'value' => '🟡 Pending', 'inline' => true],
                ['name' => 'Passport', 'value' => htmlspecialchars($data['passport']), 'inline' => true],
                ['name' => 'CID', 'value' => htmlspecialchars($data['cid']), 'inline' => true],
                ['name' => 'Alasan (OOC)', 'value' => empty($data['reason_ooc']) ? '-' : htmlspecialchars($data['reason_ooc'])],
                ['name' => 'Alasan (IC)', 'value' => empty($data['reason_ic']) ? '-' : htmlspecialchars($data['reason_ic'])],
            ];
            break;

        case 'manual_duty_request_submitted': // Data: ['employee_name', 'duty_date', 'start_time', 'end_time', 'duration_text', 'reason']
            $employee_name = htmlspecialchars($data['employee_name']);
            $embed['title'] = "⏱️ Permohonan Input Jam Manual Baru!";
            $embed['description'] = "Karyawan **{$employee_name}** telah mengajukan permohonan input jam manual.";
            $embed['color'] = $colors['info'];
            $embed['fields'] = [
                ['name' => 'Tanggal', 'value' => date('d/m/Y', strtotime($data['duty_date'])), 'inline' => true],
                ['name' => 'Periode Waktu', 'value' => date('H:i', strtotime($data['start_time'])) . ' - ' . date('H:i', strtotime($data['end_time'])), 'inline' => true],
                ['name' => 'Durasi', 'value' => htmlspecialchars($data['duration_text']), 'inline' => true],
                ['name' => 'Status', 'value' => '🟡 Pending', 'inline' => true],
                ['name' => 'Alasan', 'value' => empty($data['reason']) ? '-' : htmlspecialchars($data['reason'])],
            ];
            break;

        case 'request_status_update': // Data: ['employee_name', 'request_type', 'status', 'approved_by_name', 'duration']
            $employee_name = htmlspecialchars($data['employee_name']);
            $approver_name = htmlspecialchars($data['approved_by_name']);
            $status_text = '';
            $icon = '';
            $color_status = $colors['info'];
            $additional_field = [];

            if ($data['status'] === 'approved') {
                $status_text = 'Disetujui';
                $icon = '✅';
                $color_status = $colors['success'];
            } elseif ($data['status'] === 'rejected') {
                $status_text = 'Ditolak';
                $icon = '❌';
                $color_status = $colors['danger'];
            }
            
            $embed['title'] = "{$icon} Permohonan {$data['request_type']} Diperbarui!";
            $embed['description'] = "Permohonan **{$data['request_type']}** dari **{$employee_name}** telah **{$status_text}** oleh **{$approver_name}**.";
            $embed['color'] = $color_status;
            $embed['fields'] = [
                ['name' => 'Karyawan', 'value' => $employee_name, 'inline' => true],
                ['name' => 'Status', 'value' => "{$icon} {$status_text}", 'inline' => true],
                ['name' => 'Diproses Oleh', 'value' => $approver_name, 'inline' => true],
            ];

            if ($data['request_type'] === 'Input Jam Manual' && $data['status'] === 'approved' && !empty($data['duration'])) {
                $embed['fields'][] = ['name' => 'Durasi Jam Manual', 'value' => htmlspecialchars($data['duration']), 'inline' => true];
            }
            break;

        case 'admin_employee_action': // Data: ['action_type', 'target_employee_name', 'old_value', 'new_value', 'admin_name']
            $admin_name = htmlspecialchars($data['admin_name']);
            $target_name = htmlspecialchars($data['target_employee_name']);
            $embed['color'] = $colors['employee_action'];
            
            if ($data['action_type'] === 'update_role') {
                $old_role_display = getRoleDisplayName($data['old_value']);
                $new_role_display = getRoleDisplayName($data['new_value']);
                $embed['title'] = "👥 Perubahan Jabatan Anggota!";
                $embed['description'] = "Jabatan **{$target_name}** telah diubah oleh **{$admin_name}**.";
                $embed['fields'] = [
                    ['name' => 'Anggota', 'value' => $target_name, 'inline' => true],
                    ['name' => 'Jabatan Lama', 'value' => $old_role_display, 'inline' => true],
                    ['name' => 'Jabatan Baru', 'value' => $new_role_display, 'inline' => true],
                ];
            } elseif ($data['action_type'] === 'deactivate_employee') {
                $embed['title'] = "⛔ Anggota Dinonaktifkan!";
                $embed['description'] = "Anggota **{$target_name}** telah dinonaktifkan oleh **{$admin_name}**.";
                $embed['fields'] = [
                    ['name' => 'Anggota', 'value' => $target_name, 'inline' => true],
                    ['name' => 'Status', 'value' => '🔴 Tidak Aktif', 'inline' => true],
                ];
            }
            break;
        
        case 'admin_system_action': // Data: ['action_type', 'admin_name']
            $admin_name = htmlspecialchars($data['admin_name']);
            $embed['color'] = $colors['employee_action'];
            if ($data['action_type'] === 'reset_weekly_data') {
                $embed['title'] = "🔄 Data Mingguan Direset!";
                $embed['description'] = "Data jam tugas dan penjualan mingguan telah direset oleh **{$admin_name}**.";
            }
            break;

        case 'sale_input': // Data: ['employee_name', 'date', 'input_time', 'paket_makan_minum', 'paket_snack', 'masak_paket', 'masak_snack']
            $employee_name = htmlspecialchars($data['employee_name']);
            $total_items = $data['paket_makan_minum'] + $data['paket_snack'] + $data['masak_paket'] + $data['masak_snack'];
            $embed['title'] = "💰 Data Penjualan Baru Diinput!";
            $embed['description'] = "**{$employee_name}** telah menginput data penjualan.";
            $embed['color'] = $colors['success'];
            $embed['fields'] = [
                ['name' => 'Tanggal', 'value' => date('d/m/Y', strtotime($data['date'])), 'inline' => true],
                ['name' => 'Waktu Input', 'value' => date('H:i:s', strtotime($data['input_time'])), 'inline' => true],
                ['name' => 'Paket M&M', 'value' => $data['paket_makan_minum'], 'inline' => true],
                ['name' => 'Paket Snack', 'value' => $data['paket_snack'], 'inline' => true],
                ['name' => 'Masak Paket', 'value' => $data['masak_paket'], 'inline' => true],
                ['name' => 'Masak Snack', 'value' => $data['masak_snack'], 'inline' => true],
                ['name' => 'Total Item', 'value' => $total_items, 'inline' => true],
            ];
            break;
        
        case 'duty_log_deleted': // Tipe notifikasi baru
            $employee_name = htmlspecialchars($data['employee_name']);
            $admin_name = htmlspecialchars($data['admin_name']);
            $duty_start_time = date('d/m/Y H:i', strtotime($data['duty_start']));
            $duty_end_time = $data['duty_end'] ? date('H:i', strtotime($data['duty_end'])) : 'Belum Selesai';
            $duration_display = formatDuration($data['duration_minutes']);

            $embed['title'] = "🗑️ Log Jam Kerja Dihapus!";
            $embed['description'] = "Log jam kerja anggota **{$employee_name}** telah dihapus oleh **{$admin_name}**.";
            $embed['color'] = $colors['danger']; // Merah untuk penghapusan
            $embed['fields'] = [
                ['name' => 'Anggota', 'value' => $employee_name, 'inline' => true],
                ['name' => 'Dihapus Oleh', 'value' => $admin_name, 'inline' => true],
                ['name' => 'Tanggal & Waktu Mulai', 'value' => $duty_start_time, 'inline' => false],
                ['name' => 'Waktu Selesai (estimasi)', 'value' => $duty_end_time, 'inline' => true],
                ['name' => 'Durasi (estimasi)', 'value' => $duration_display, 'inline' => true],
                ['name' => 'Saran', 'value' => "Mohon informasikan anggota tersebut untuk mengajukan `Input Jam Manual` jika periode ini perlu dicatat ulang.", 'inline' => false]
            ];
            break;

        default:
            // Fallback untuk pesan yang tidak dikenali
            $embed['title'] = "ℹ️ Notifikasi Umum";
            $embed['description'] = htmlspecialchars($data); // Data adalah string pesan langsung
            $embed['color'] = $colors['info'];
            break;
    }
    
    // Payload akhir untuk Discord Webhook
    $discord_payload = json_encode([
        'username' => $username,
        'avatar_url' => $avatar_url,
        'embeds' => [$embed],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $options = [
        'http' => [
            'header'  => "Content-type: application/json\r\n",
            'method'  => 'POST',
            'content' => $discord_payload,
        ],
    ];

    // Kirim notifikasi ke setiap URL webhook yang relevan
    foreach ($webhooks_to_send as $webhook_url) {
        $context = stream_context_create($options);
        // Gunakan '@' untuk menekan error jika ada URL webhook yang tidak valid atau tidak dikonfigurasi
        @file_get_contents($webhook_url, false, $context);
    }
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