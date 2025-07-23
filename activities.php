<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();

// Ambil jumlah permohonan pending untuk indikator sidebar
$pending_requests_count = getPendingRequestCount();

// Pastikan fungsi formatDuration ada di config.php atau di-include di tempat lain
if (!function_exists('formatDuration')) {
    function formatDuration($minutes) {
        if ($minutes < 0) return "0j 0m"; // Tangani durasi negatif dengan baik
        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;
        return "{$hours}j {$remainingMinutes}m";
    }
}

if (!function_exists('getRoleDisplayName')) {
    function getRoleDisplayName($role) {
        $roles = [
            'direktur' => 'Direktur',
            'wakil_direktur' => 'Wakil Direktur',
            'manager' => 'Manager',
            'chef' => 'Chef',
            'karyawan' => 'Karyawan',
            'magang' => 'Magang',
            // Tambahkan peran lain jika ada
        ];
        return $roles[$role] ?? ucfirst(str_replace('_', ' ', $role));
    }
}

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
$stmt->close(); // Tutup statement setelah mengambil hasil

// Ambil ringkasan total jam kerja KESELURUHAN pengguna
$stmt = $conn->prepare("SELECT SUM(duration_minutes) as total_minutes FROM duty_logs WHERE employee_id = ? AND status = 'completed'");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$total_duty_minutes = $stmt->get_result()->fetch_assoc()['total_minutes'] ?? 0;
$stmt->close();

// Ambil ringkasan data penjualan KESELURUHAN untuk pengguna
$total_sales_summary = [
    'total_paket_makan_minum' => 0,
    'total_paket_snack' => 0,
    'total_masak_paket' => 0,
    'total_masak_snack' => 0,
    'total_penjualan' => 0, // Baru: total paket makan minum + paket snack
    'total_masak_keseluruhan' => 0 // Baru: total masak paket + masak snack
];
$stmt = $conn->prepare("
    SELECT
        SUM(paket_makan_minum) as total_paket_makan_minum,
        SUM(paket_snack) as total_paket_snack,
        SUM(masak_paket) as total_masak_paket,
        SUM(masak_snack) as total_masak_snack
    FROM sales_data
    WHERE employee_id = ?
");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$result_sales = $stmt->get_result()->fetch_assoc();
if ($result_sales) {
    $total_sales_summary = $result_sales;
    // Hitung total_penjualan (paket makan & minum + paket snack)
    $total_sales_summary['total_penjualan'] =
        $result_sales['total_paket_makan_minum'] +
        $result_sales['total_paket_snack'];
    // Hitung total_masak_keseluruhan (masak paket + masak snack)
    $total_sales_summary['total_masak_keseluruhan'] =
        $result_sales['total_masak_paket'] +
        $result_sales['total_masak_snack'];
}
$stmt->close();

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aktivitas Saya - Warung Om Tante</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
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
                <p>Riwayat aktivitas dan ringkasan performa Anda</p>
            </div>

            <div class="summary-stats-container">
                <div class="summary-card">
                    <div class="summary-icon" style="color: var(--info-color);">⏰</div>
                    <div class="summary-content">
                        <h4>Total Jam Kerja Keseluruhan</h4>
                        <p class="summary-value">
                            <?= formatDuration($total_duty_minutes) ?>
                        </p>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon" style="color: var(--primary-color);">💰</div>
                    <div class="summary-content">
                        <h4>Total Penjualan Keseluruhan</h4>
                        <p class="summary-value">
                            <?= $total_sales_summary['total_penjualan'] ?>
                        </p>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon" style="color: var(--warning-color);">🍜</div> <div class="summary-content">
                        <h4>Total Masak Keseluruhan</h4>
                        <p class="summary-value">
                            <?= $total_sales_summary['total_masak_keseluruhan'] ?>
                        </p>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon" style="color: var(--success-color);">📦</div>
                    <div class="summary-content">
                        <h4>Detail Item Penjualan</h4>
                        <p class="stat-breakdown" style="font-size: 0.9em;">
                            <span>M&M: <strong><?= $total_sales_summary['total_paket_makan_minum'] ?></strong></span>
                            <span>Snack: <strong><?= $total_sales_summary['total_paket_snack'] ?></strong></span>
                            <span>Masak P: <strong><?= $total_sales_summary['total_masak_paket'] ?></strong></span>
                            <span>Masak S: <strong><?= $total_sales_summary['total_masak_snack'] ?></strong></span>
                        </p>
                    </div>
                </div>
            </div>

            <div class="content-grid">
                <div class="card full-width"> <div class="card-header">
                        <h3>Riwayat Aktivitas Anda</h3>
                    </div>
                    <div class="card-content">
                        <?php if (empty($activities)): ?>
                            <div class="no-data">Belum ada aktivitas. Silakan lakukan 'On Duty' untuk mencatat aktivitas pertama Anda.</div>
                        <?php else: ?>
                            <div class="responsive-table-container">
                                <table class="activities-table-improved">
                                    <thead>
                                        <tr>
                                            <th>Tanggal</th>
                                            <th>Mulai</th>
                                            <th>Selesai</th>
                                            <th>Durasi</th>
                                            <th>Tipe</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($activities as $activity): ?>
                                        <tr>
                                            <td data-label="Tanggal">
                                                <?= date('d/m/Y', strtotime($activity['duty_start'])) ?>
                                            </td>
                                            <td data-label="Mulai">
                                                <?= date('H:i', strtotime($activity['duty_start'])) ?>
                                            </td>
                                            <td data-label="Selesai">
                                                <?= $activity['duty_end'] ? date('H:i', strtotime($activity['duty_end'])) : '-' ?>
                                            </td>
                                            <td data-label="Durasi">
                                                <?= $activity['duty_end'] ? formatDuration($activity['duration_minutes']) : 'Berlangsung' ?>
                                            </td>
                                            <td data-label="Tipe">
                                                <span class="status-badge status-<?= $activity['is_manual'] ? 'warning' : 'info' ?>">
                                                    <?= $activity['is_manual'] ? 'Manual' : 'Otomatis' ?>
                                                </span>
                                            </td>
                                            <td data-label="Status">
                                                <span class="status-badge status-<?= $activity['status'] ?>">
                                                    <?= ucfirst($activity['status']) ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="script.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Animasi untuk card (jika ada)
            const cards = document.querySelectorAll('.card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
                card.classList.add('fade-in');
            });

            // Animasi untuk baris tabel
            const tableRows = document.querySelectorAll('.activities-table-improved tbody tr');
            tableRows.forEach((row, index) => {
                row.style.animationDelay = `${(cards.length * 0.1) + (index * 0.05)}s`;
                row.classList.add('fade-in');
            });

            // Animasi untuk summary cards
            const summaryCards = document.querySelectorAll('.summary-card');
            summaryCards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
                card.classList.add('fade-in');
            });
        });
    </script>
</body>
</html>