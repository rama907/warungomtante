<?php
require_once 'config.php';

// Pastikan fungsi formatDuration dan getRoleDisplayName ada di config.php atau di-include di tempat lain
if (!function_exists('formatDuration')) {
    function formatDuration($minutes) {
        if ($minutes < 0) return "0j 0m"; // Handle negative duration gracefully
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

if (!isLoggedIn() || !hasRole(['direktur', 'wakil_direktur', 'manager'])) {
    header('Location: dashboard.php');
    exit;
}

// Get employee activities summary
$stmt = $conn->query("
    SELECT 
        e.id,
        e.name,
        e.role,
        e.is_on_duty,
        -- Gunakan WEEK(column, 1) dan YEAR(column) untuk konsistensi dengan sales.php
        COALESCE(SUM(CASE WHEN WEEK(dl.duty_start, 1) = WEEK(NOW(), 1) AND YEAR(dl.duty_start) = YEAR(NOW()) THEN dl.duration_minutes ELSE 0 END), 0) as total_duty_minutes,
        COALESCE(SUM(CASE WHEN WEEK(sd.date, 1) = WEEK(NOW(), 1) AND YEAR(sd.date) = YEAR(NOW()) THEN sd.paket_makan_minum ELSE 0 END), 0) as total_paket_makan_minum,
        COALESCE(SUM(CASE WHEN WEEK(sd.date, 1) = WEEK(NOW(), 1) AND YEAR(sd.date) = YEAR(NOW()) THEN sd.paket_snack ELSE 0 END), 0) as total_paket_snack,
        COALESCE(SUM(CASE WHEN WEEK(sd.date, 1) = WEEK(NOW(), 1) AND YEAR(sd.date) = YEAR(NOW()) THEN sd.masak_paket ELSE 0 END), 0) as total_masak_paket,
        COALESCE(SUM(CASE WHEN WEEK(sd.date, 1) = WEEK(NOW(), 1) AND YEAR(sd.date) = YEAR(NOW()) THEN sd.masak_snack ELSE 0 END), 0) as total_masak_snack
    FROM employees e
    LEFT JOIN duty_logs dl ON e.id = dl.employee_id 
    LEFT JOIN sales_data sd ON e.id = sd.employee_id 
    WHERE e.status = 'active'
    GROUP BY e.id, e.name, e.role, e.is_on_duty
    ORDER BY 
        CASE e.role 
            WHEN 'direktur' THEN 1
            WHEN 'wakil_direktur' THEN 2
            WHEN 'manager' THEN 3
            WHEN 'chef' THEN 4
            WHEN 'karyawan' THEN 5
            WHEN 'magang' THEN 6
        END,
        e.name
");
$employee_activities = $stmt->fetch_all(MYSQLI_ASSOC);
$stmt->close(); // Tutup statement setelah mengambil hasil
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aktivitas Anggota - Warung Om Tante</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="dashboard-container">
        <?php include 'includes/header.php'; ?>
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1>
                    <span class="page-icon">📈</span>
                    Aktivitas Anggota
                </h1>
                <p>Ringkasan aktivitas dan performa semua anggota minggu ini</p>
            </div>

            <div class="summary-stats-container">
                <div class="summary-card">
                    <div class="summary-icon" style="color: var(--info-color);">⏰</div>
                    <div class="summary-content">
                        <h4>Total Jam Kerja Minggu Ini</h4>
                        <p class="summary-value">
                            <?= formatDuration(array_sum(array_column($employee_activities, 'total_duty_minutes'))) ?>
                        </p>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon" style="color: var(--primary-color);">📦</div>
                    <div class="summary-content">
                        <h4>Total Paket Terjual</h4>
                        <p class="summary-value">
                            <?= array_sum(array_column($employee_activities, 'total_paket_makan_minum')) + 
                                array_sum(array_column($employee_activities, 'total_paket_snack')) +
                                array_sum(array_column($employee_activities, 'total_masak_paket')) +
                                array_sum(array_column($employee_activities, 'total_masak_snack')) ?>
                        </p>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon" style="color: var(--success-color);">👥</div>
                    <div class="summary-content">
                        <h4>Anggota Aktif On Duty</h4>
                        <p class="summary-value">
                            <?= count(array_filter($employee_activities, function($emp) { return $emp['is_on_duty']; })) ?>
                        </p>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3>Ringkasan Aktivitas per Anggota (Minggu Ini)</h3>
                </div>
                <div class="card-content">
                    <div class="responsive-table-container">
                        <table class="activities-table-improved">
                            <thead>
                                <tr>
                                    <th>Nama</th>
                                    <th>Jabatan</th>
                                    <th>Status</th>
                                    <th>Total Jam Kerja</th>
                                    <th>Paket M&M</th>
                                    <th>Paket Snack</th>
                                    <th>Masak Paket</th>
                                    <th>Masak Snack</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($employee_activities)): ?>
                                    <tr>
                                        <td colspan="8" class="no-data">Belum ada data aktivitas anggota minggu ini.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($employee_activities as $activity): ?>
                                    <tr>
                                        <td data-label="Nama">
                                            <div class="employee-name-cell">
                                                <span class="employee-avatar-small">
                                                    <?= strtoupper(substr(htmlspecialchars($activity['name']), 0, 1)) ?>
                                                </span>
                                                <span><?= htmlspecialchars($activity['name']) ?></span>
                                            </div>
                                        </td>
                                        <td data-label="Jabatan">
                                            <span class="role-badge role-<?= htmlspecialchars($activity['role']) ?>">
                                                <?= htmlspecialchars(getRoleDisplayName($activity['role'])) ?>
                                            </span>
                                        </td>
                                        <td data-label="Status">
                                            <div class="status-cell">
                                                <span class="status-indicator <?= $activity['is_on_duty'] ? 'on-duty' : 'off-duty' ?>"></span>
                                                <span><?= $activity['is_on_duty'] ? 'On Duty' : 'Off Duty' ?></span>
                                            </div>
                                        </td>
                                        <td data-label="Total Jam Kerja">
                                            <strong><?= formatDuration($activity['total_duty_minutes']) ?></strong>
                                        </td>
                                        <td data-label="Paket M&M"><?= $activity['total_paket_makan_minum'] ?></td>
                                        <td data-label="Paket Snack"><?= $activity['total_paket_snack'] ?></td>
                                        <td data-label="Masak Paket"><?= $activity['total_masak_paket'] ?></td>
                                        <td data-label="Masak Snack"><?= $activity['total_masak_snack'] ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="script.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Animasi untuk kartu ringkasan
            const summaryCards = document.querySelectorAll('.summary-card');
            summaryCards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
                card.classList.add('fade-in');
            });

            // Animasi untuk baris tabel
            const tableRows = document.querySelectorAll('.activities-table-improved tbody tr');
            tableRows.forEach((row, index) => {
                row.style.animationDelay = `${(summaryCards.length * 0.1) + (index * 0.05)}s`; // Sedikit tunda setelah kartu
                row.classList.add('fade-in');
            });
        });
    </script>
</body>
</html>