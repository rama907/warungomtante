<?php
require_once 'config.php';

// Ambil jumlah permohonan pending untuk indikator sidebar
$pending_requests_count = getPendingRequestCount();

// Pastikan fungsi formatDuration dan getRoleDisplayName ada di config.php atau di-include di tempat lain
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

if (!isLoggedIn() || !hasRole(['direktur', 'wakil_direktur', 'manager'])) {
    header('Location: dashboard.php');
    exit;
}

// Dapatkan ringkasan aktivitas karyawan (mengambil data keseluruhan)
$stmt = $conn->query("
    SELECT
        e.id,
        e.name,
        e.role,
        e.is_on_duty,
        COALESCE(duty_summary.total_duty_minutes, 0) as total_duty_minutes,
        COALESCE(sales_summary.total_paket_makan_minum, 0) as total_paket_makan_minum,
        COALESCE(sales_summary.total_paket_snack, 0) as total_paket_snack,
        COALESCE(sales_summary.total_masak_paket, 0) as total_masak_paket,
        COALESCE(sales_summary.total_masak_snack, 0) as total_masak_snack
    FROM employees e
    LEFT JOIN (
        SELECT
            employee_id,
            SUM(duration_minutes) as total_duty_minutes
        FROM duty_logs
        WHERE status = 'completed' -- Hanya mengambil yang sudah selesai
        GROUP BY employee_id
    ) as duty_summary ON e.id = duty_summary.employee_id
    LEFT JOIN (
        SELECT
            employee_id,
            SUM(paket_makan_minum) as total_paket_makan_minum,
            SUM(paket_snack) as total_paket_snack,
            SUM(masak_paket) as total_masak_paket,
            SUM(masak_snack) as total_masak_snack
        FROM sales_data
        GROUP BY employee_id
    ) as sales_summary ON e.id = sales_summary.employee_id
    WHERE e.status = 'active'
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

// Hitung total penjualan (paket makan minum + paket snack) secara keseluruhan
$total_paket_terjual_keseluruhan = array_sum(array_column($employee_activities, 'total_paket_makan_minum')) +
                        array_sum(array_column($employee_activities, 'total_paket_snack'));

// Hitung total masak (masak paket + masak snack) secara keseluruhan
$total_masak_keseluruhan = array_sum(array_column($employee_activities, 'total_masak_paket')) +
                            array_sum(array_column($employee_activities, 'total_masak_snack'));

// === START EXPORT LOGIC ===
if (isset($_GET['export']) && $_GET['export'] == 'spreadsheet') {
    // Kueri ekspor juga diubah untuk mencerminkan data keseluruhan
    $export_stmt = $conn->query("
        SELECT
            e.id,
            e.name,
            e.role,
            e.is_on_duty,
            COALESCE(duty_summary.total_duty_minutes, 0) as total_duty_minutes,
            COALESCE(sales_summary.total_paket_makan_minum, 0) as total_paket_makan_minum,
            COALESCE(sales_summary.total_paket_snack, 0) as total_paket_snack,
            COALESCE(sales_summary.total_masak_paket, 0) as total_masak_paket,
            COALESCE(sales_summary.total_masak_snack, 0) as total_masak_snack
        FROM employees e
        LEFT JOIN (
            SELECT
                employee_id,
                SUM(duration_minutes) as total_duty_minutes
            FROM duty_logs
            WHERE status = 'completed'
            GROUP BY employee_id
        ) as duty_summary ON e.id = duty_summary.employee_id
        LEFT JOIN (
            SELECT
                employee_id,
                SUM(paket_makan_minum) as total_paket_makan_minum,
                SUM(paket_snack) as total_paket_snack,
                SUM(masak_paket) as total_masak_paket,
                SUM(masak_snack) as total_masak_snack
            FROM sales_data
            GROUP BY employee_id
        ) as sales_summary ON e.id = sales_summary.employee_id
        WHERE e.status = 'active'
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
    $export_data = $export_stmt->fetch_all(MYSQLI_ASSOC);
    $export_stmt->close();

    // Set header untuk unduhan CSV
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="aktivitas_anggota_' . date('Ymd_His') . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    // Tambahkan UTF-8 BOM untuk kompatibilitas Excel (penting untuk karakter non-ASCII)
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    // Definisikan CSV headers (ramah pengguna)
    $headers = [
        'Nama',
        'Jabatan',
        'Status On Duty',
        'Total Jam Kerja Keseluruhan',
        'Total Paket Makan & Minum Terjual',
        'Total Paket Snack Terjual',
        'Total Masak Paket',
        'Total Masak Snack'
    ];
    fputcsv($output, $headers);

    // Tulis baris data
    foreach ($export_data as $row) {
        $data_row = [
            htmlspecialchars_decode($row['name']), // Dekode entitas HTML jika ada
            getRoleDisplayName($row['role']),
            $row['is_on_duty'] ? 'On Duty' : 'Off Duty',
            formatDuration($row['total_duty_minutes']),
            $row['total_paket_makan_minum'],
            $row['total_paket_snack'],
            $row['total_masak_paket'],
            $row['total_masak_snack']
        ];
        fputcsv($output, $data_row);
    }

    fclose($output);
    exit; // Hentikan eksekusi lebih lanjut setelah mengirim file
}
// === AKHIR LOGIKA EKSPOR ===
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
                    <span class="page-icon">📊</span>
                    Aktivitas Anggota
                </h1>
                <p>Ringkasan aktivitas dan performa semua anggota</p>
                <div class="page-actions" style="margin-top: var(--spacing-md);">
                    <a href="employee-activities.php?export=spreadsheet" class="btn btn-info" target="_blank">
                        <span class="btn-icon">⬇️</span>
                        Unduh Data Spreadsheet
                    </a>
                </div>
            </div>

            <div class="summary-stats-container">
                <div class="summary-card">
                    <div class="summary-icon" style="color: var(--info-color);">⏰</div>
                    <div class="summary-content">
                        <h4>Total Jam Kerja Keseluruhan</h4>
                        <p class="summary-value">
                            <?= formatDuration(array_sum(array_column($employee_activities, 'total_duty_minutes'))) ?>
                        </p>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon" style="color: var(--primary-color);">💰</div>
                    <div class="summary-content">
                        <h4>Total Penjualan Keseluruhan</h4>
                        <p class="summary-value">
                            <?= $total_paket_terjual_keseluruhan ?>
                        </p>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon" style="color: var(--warning-color);">🍜</div> <div class="summary-content">
                        <h4>Total Masak Keseluruhan</h4>
                        <p class="summary-value">
                            <?= $total_masak_keseluruhan ?>
                        </p>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon" style="color: var(--success-color);">✅</div>
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
                    <h3>Ringkasan Aktivitas per Anggota</h3>
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
                                        <td colspan="8" class="no-data">Belum ada data aktivitas anggota.</td>
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