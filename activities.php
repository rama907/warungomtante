<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();

// Ambil jumlah permohonan pending untuk indikator sidebar
$pending_requests_count = getPendingRequestCount();

// Get all activities for current user
$stmt = $conn->prepare("
    SELECT * FROM duty_logs
    WHERE employee_id = ?
    ORDER BY duty_start DESC
    LIMIT 50
");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get weekly summary - THIS SECTION IS NOW COMMENTED OUT/REMOVED AS PER REQUEST
/*
$stmt = $conn->prepare("
    SELECT
        WEEK(duty_start) as week_num,
        YEAR(duty_start) as year,
        SUM(duration_minutes) as total_minutes,
        COUNT(*) as total_sessions
    FROM duty_logs
    WHERE employee_id = ? AND duty_end IS NOT NULL
    GROUP BY WEEK(duty_start), YEAR(duty_start)
    ORDER BY year DESC, week_num DESC
    LIMIT 10
");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$weekly_summary = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
*/
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aktivitas Saya - Warung Om Tante</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="dashboard-container">
        <?php include 'includes/header.php'; ?>
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1>
                    <span class="page-icon">📊</span>
                    Aktivitas Saya
                </h1>
                <p>Riwayat aktivitas dan jam kerja Anda</p>
            </div>

            <div class="content-grid">
                <div class="card">
                    <div class="card-header">
                        <h3>Riwayat Aktivitas</h3>
                    </div>
                    <div class="card-content">
                        <?php if (empty($activities)): ?>
                            <div class="no-data">Belum ada aktivitas</div>
                        <?php else: ?>
                            <div class="activities-table">
                                <div class="table-header">
                                    <div class="table-cell">Tanggal</div>
                                    <div class="table-cell">Mulai</div>
                                    <div class="table-cell">Selesai</div>
                                    <div class="table-cell">Durasi</div>
                                    <div class="table-cell">Status</div>
                                </div>
                                <?php foreach ($activities as $activity): ?>
                                <div class="table-row">
                                    <div class="table-cell">
                                        <?= date('d/m/Y', strtotime($activity['duty_start'])) ?>
                                    </div>
                                    <div class="table-cell">
                                        <?= date('H:i', strtotime($activity['duty_start'])) ?>
                                    </div>
                                    <div class="table-cell">
                                        <?= $activity['duty_end'] ? date('H:i', strtotime($activity['duty_end'])) : '-' ?>
                                    </div>
                                    <div class="table-cell">
                                        <?= $activity['duty_end'] ? formatDuration($activity['duration_minutes']) : 'Berlangsung' ?>
                                    </div>
                                    <div class="table-cell">
                                        <span class="status-badge status-<?= $activity['status'] ?>">
                                            <?= ucfirst($activity['status']) ?>
                                        </span>
                                        <?php if ($activity['is_manual']): ?>
                                            <span class="manual-badge">Manual</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="script.js"></script>
</body>
</html>