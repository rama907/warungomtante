<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();

// Ambil jumlah permohonan pending untuk indikator sidebar
$pending_requests_count = getPendingRequestCount();

// Cek surat peringatan terbaru
$latest_warning = getLatestWarning($user['id']);

// Handle duty actions
if ($_POST['action'] ?? '' === 'on_duty') {
    if (!$user['is_on_duty']) {
        $stmt = $conn->prepare("UPDATE employees SET is_on_duty = TRUE, current_duty_start = NOW() WHERE id = ?");
        $stmt->bind_param("i", $user['id']);
        $stmt->execute();
        
        $stmt = $conn->prepare("INSERT INTO duty_logs (employee_id, duty_start) VALUES (?, NOW())");
        $stmt->bind_param("i", $user['id']);
        $stmt->execute();
        
        sendDiscordNotification(['employee_name' => $user['name'], 'event_type' => 'clock_in'], 'clock_event');
        header('Location: dashboard.php');
        exit;
    }
}

if ($_POST['action'] ?? '' === 'off_duty') {
    if ($user['is_on_duty']) {
        $stmt = $conn->prepare("SELECT id FROM duty_logs WHERE employee_id = ? AND duty_end IS NULL ORDER BY id DESC LIMIT 1");
        $stmt->bind_param("i", $user['id']);
        $stmt->execute();
        $log = $stmt->get_result()->fetch_assoc();
        
        if ($log) {
            $stmt = $conn->prepare("UPDATE duty_logs SET duty_end = NOW(), duration_minutes = TIMESTAMPDIFF(MINUTE, duty_start, NOW()), status = 'completed' WHERE id = ?");
            $stmt->bind_param("i", $log['id']);
            $stmt->execute();
        }
        
        $stmt = $conn->prepare("UPDATE employees SET is_on_duty = FALSE, current_duty_start = NULL WHERE id = ?");
        $stmt->bind_param("i", $user['id']);
        $stmt->execute();
        
        $stmt_log = $conn->prepare("SELECT duty_start FROM duty_logs WHERE employee_id = ? AND duty_end IS NOT NULL ORDER BY id DESC LIMIT 1");
        $stmt_log->bind_param("i", $user['id']);
        $stmt_log->execute();
        $last_log = $stmt_log->get_result()->fetch_assoc();
        $stmt_log->close();

        $duration_text = 'N/A';
        if ($last_log) {
            $start_dt = new DateTime($last_log['duty_start']);
            $end_dt = new DateTime(); // Waktu sekarang
            $interval = $start_dt->diff($end_dt);
            $duration_text = $interval->h . 'j ' . $interval->i . 'm ' . $interval->s . 'd'; // Menit dan detik untuk akurasi
        }

        sendDiscordNotification(['employee_name' => $user['name'], 'event_type' => 'clock_out', 'duration' => $duration_text], 'clock_event');
        header('Location: dashboard.php');
        exit;
    }
}

// Get user stats
$stmt = $conn->prepare("SELECT SUM(duration_minutes) as total_minutes FROM duty_logs WHERE employee_id = ? AND status = 'completed'"); // Mengambil semua jam kerja yang completed
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$total_minutes = $stmt->get_result()->fetch_assoc()['total_minutes'] ?? 0;

$stmt = $conn->prepare("SELECT AVG(duration_minutes) as avg_minutes FROM duty_logs WHERE employee_id = ? AND duty_end IS NOT NULL");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$avg_minutes = $stmt->get_result()->fetch_assoc()['avg_minutes'] ?? 0;

// Ambil ringkasan data penjualan KESELURUHAN untuk pengguna (Overall Sales & Masak)
$total_sales_overall_dashboard = [
    'total_paket_makan_minum_warga' => 0,
    'total_paket_makan_minum_instansi' => 0,
    'total_paket_snack' => 0,
    'total_masak_paket' => 0,
    'total_masak_snack' => 0,
];
$stmt_sales_overall = $conn->prepare("
    SELECT
        SUM(paket_makan_minum_warga) as total_paket_makan_minum_warga,
        SUM(paket_makan_minum_instansi) as total_paket_makan_minum_instansi,
        SUM(paket_snack) as total_paket_snack,
        SUM(masak_paket) as total_masak_paket,
        SUM(masak_snack) as total_masak_snack
    FROM sales_data
    WHERE employee_id = ?
");
$stmt_sales_overall->bind_param("i", $user['id']);
$stmt_sales_overall->execute();
$result_sales_overall = $stmt_sales_overall->get_result()->fetch_assoc();
if ($result_sales_overall) {
    $total_sales_overall_dashboard = $result_sales_overall;
}
$stmt_sales_overall->close();

$total_paket_terjual_dashboard = $total_sales_overall_dashboard['total_paket_makan_minum_warga'] +
                                 $total_sales_overall_dashboard['total_paket_makan_minum_instansi'] +
                                 $total_sales_overall_dashboard['total_paket_snack'] +
                                 $total_sales_overall_dashboard['total_masak_paket'] +
                                 $total_sales_overall_dashboard['total_masak_snack'];


// Get recent activities
$stmt = $conn->prepare("SELECT * FROM duty_logs WHERE employee_id = ? ORDER BY duty_start DESC LIMIT 5");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$recent_activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get current duty duration if on duty for alert
$current_duty_duration_seconds = 0;
$long_duty_alert_dashboard = false;
if ($user['is_on_duty'] && $user['current_duty_start']) {
    $start = new DateTime($user['current_duty_start']);
    $now = new DateTime();
    $current_duty_duration_seconds = $now->getTimestamp() - $start->getTimestamp();
    
    // Check if on duty for more than 5 hours (5 * 3600 seconds)
    if ($current_duty_duration_seconds > (5 * 3600)) {
        $long_duty_alert_dashboard = true;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Warung Om Tante</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <style>
        /* CSS untuk logo di dalam stat-card */
        .stat-icon img {
            max-width: 100%; /* Pastikan gambar tidak melebihi lebar kontainer */
            max-height: 100%; /* Pastikan gambar tidak melebihi tinggi kontainer */
            object-fit: contain; /* Mempertahankan rasio aspek gambar */
            display: block; /* Menghilangkan spasi ekstra di bawah gambar */
            margin: auto; /* Pusatkan gambar */
        }
        /* CSS untuk logo Warung Om Tante di header dashboard */
        .profile-avatar {
            /* Pastikan ukuran dan bentuk tetap seperti lingkaran */
            width: 80px; /* Ukuran yang sama dengan sebelumnya */
            height: 80px; /* Ukuran yang sama dengan sebelumnya */
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white; /* Warna teks (jika ada) */
            font-size: 2rem; /* Ukuran ikon (jika ada) */
            box-shadow: var(--shadow-lg);
            border: 4px solid var(--bg-card);
            overflow: hidden; /* Penting agar gambar tidak keluar dari lingkaran */
        }

        .profile-avatar img {
            max-width: 100%; /* Sesuaikan dengan lebar container */
            max-height: 100%; /* Sesuaikan dengan tinggi container */
            object-fit: contain; /* Menjaga rasio aspek gambar */
            transform: scale(0.8); /* Perkecil sedikit agar ada padding visual */
            padding: 5px; /* Tambahkan sedikit padding di dalam lingkaran */
        }

    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include 'includes/header.php'; ?>
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <?php if ($long_duty_alert_dashboard): ?>
                <div class="warning-message" style="margin-bottom: var(--spacing-xl);">
                    <strong>⚠️ Perhatian:</strong> Anda sudah On Duty lebih dari 5 jam. Pastikan Anda beristirahat yang cukup!
                </div>
            <?php endif; ?>

            <div class="dashboard-header">
                <div class="user-profile">
                    <div class="profile-avatar">
                        <img src="LOGO_WOT.png" alt="Logo Warung Om Tante">
                    </div>
                    <div class="profile-info">
                        <h1>Sistem Manajemen Warung Om Tante</h1>
                        <div class="user-details">
                            <span class="user-icon">👤</span>
                            <span class="user-name"><?= htmlspecialchars($user['name']) ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="user-card">
                <div class="user-card-content">
                    <div class="user-info">
                        <h2><?= htmlspecialchars($user['name']) ?></h2>
                        <p class="user-role"><?= getRoleDisplayName($user['role']) ?></p>
                        <?php if ($latest_warning): ?>
                            <span class="warning-badge badge-<?= strtolower($latest_warning['type']) ?>">
                                <?= $latest_warning['type'] ?>
                            </span>
                            <p class="warning-reason">Terakhir: <?= htmlspecialchars($latest_warning['reason']) ?></p>
                        <?php endif; ?>
                        <div class="duty-status">
                            <span class="status-indicator <?= $user['is_on_duty'] ? 'on-duty' : 'off-duty' ?>"></span>
                            <span class="status-text"><?= $user['is_on_duty'] ? 'On Duty' : 'Off Duty' ?></span>
                            <?php if ($user['is_on_duty']): ?>
                                <div class="duty-clock" data-start-time="<?= $user['current_duty_start'] ?>">
                                    00:00:00
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="action-buttons">
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="on_duty">
                    <button type="submit" class="btn btn-success" <?= $user['is_on_duty'] ? 'disabled' : '' ?>>
                        <span class="btn-icon">▶️</span>
                        On Duty
                    </button>
                </form>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="off_duty">
                    <button type="submit" class="btn btn-warning" <?= !$user['is_on_duty'] ? 'disabled' : '' ?>>
                        <span class="btn-icon">⏸️</span>
                        Off Duty
                    </button>
                </form>
                <a href="sales.php" class="btn btn-primary">
                    <span class="btn-icon">💰</span>
                    Input Penjualan
                </a>
                <a href="leave-request.php" class="btn btn-info">
                    <span class="btn-icon">📝</span>
                    Cuti
                </a>
                <a href="resignation-request.php" class="btn btn-danger">
                    <span class="btn-icon">📄</span>
                    Resign
                </a>
                <a href="manual-duty.php" class="btn btn-primary">
                    <span class="btn-icon">⏱️</span>
                    Input Jam Manual
                </a>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">⏰</div>
                    <div class="stat-content">
                        <h3>Total Jam Kerja</h3>
                        <p class="stat-value"><?= formatDuration($total_minutes) ?></p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        💰 </div>
                    <div class="stat-content">
                        <h3>Total Penjualan & Masak</h3>
                        <p class="stat-value"><?= $total_paket_terjual_dashboard ?> Paket</p>
                    </div>
                </div>
            </div>

            <div class="recent-activities">
                <h3>
                    <span class="section-icon">📊</span>
                    Aktivitas Terakhir
                </h3>
                <div class="activities-list">
                    <?php if (empty($recent_activities)): ?>
                        <div class="no-data">Belum ada aktivitas</div>
                    <?php else: ?>
                        <?php foreach ($recent_activities as $activity): ?>
                        <div class="activity-item">
                            <div class="activity-date">
                                <span class="date-icon">📅</span>
                                <?= date('d/m/Y', strtotime($activity['duty_start'])) ?>
                            </div>
                            <div class="activity-time">
                                <span class="time-icon">⏰</span>
                                <?= date('H:i', strtotime($activity['duty_start'])) ?> - 
                                <?= $activity['duty_end'] ? date('H:i', strtotime($activity['duty_end'])) : 'Sedang Berlangsung' ?>
                            </div>
                            <?php if ($activity['duty_end']): ?>
                            <div class="activity-duration">
                                Total Jam: <?= formatDuration($activity['duration_minutes']) ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script src="script.js"></script>
</body>
</html>