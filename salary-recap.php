<?php
require_once 'config.php';

if (!isLoggedIn() || !hasRole(['direktur', 'wakil_direktur', 'manager'])) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount();

// Definisi gaji pokok per jabatan
$base_salaries = [
    'direktur' => 1415000,
    'wakil_direktur' => 1215000,
    'manager' => 915000,
    'chef' => 815000,
    'karyawan' => 685000,
    'magang' => 615000,
];

// Definisi bonus lembur per jam per jabatan (untuk jam di atas 21 jam)
$overtime_hourly_bonus = [
    'direktur' => 35000,
    'wakil_direktur' => 35000,
    'manager' => 30000,
    'chef' => 25000,
    'karyawan' => 20000,
    'magang' => 15000,
];

$min_duty_hours_for_base_salary = 8; // Perubahan: Minimal jam kerja untuk mendapatkan gaji pokok
$min_duty_minutes_for_base_salary = $min_duty_hours_for_base_salary * 60;

$min_duty_hours_for_bonus = 21;
$min_duty_minutes_for_bonus = $min_duty_hours_for_bonus * 60;

$overtime_cap_hours = 15;
$overtime_cap_minutes = $overtime_cap_hours * 60;

$sales_bonus_threshold = 400;
$sales_bonus_amount = 800000;

$duty_21_hour_bonus = 1000000;

$performance_cut_off_threshold = 400;

$success_message = null;
$error_message = null;

// --- Handle Payment Status Actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $employee_id = (int)($_POST['employee_id'] ?? 0);

    $conn->begin_transaction();
    try {
        if ($action === 'reset_all_paid_status') {
             // Aksi ini tidak memerlukan employee_id, jadi proses langsung
            $stmt = $conn->prepare("UPDATE employees SET is_paid = FALSE WHERE status = 'active'");
            if (!$stmt) {
                throw new Exception("Gagal menyiapkan query reset semua status pembayaran: " . $conn->error);
            }

            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $conn->commit();
                $success_message = "Semua status gaji anggota berhasil diubah menjadi **Belum Dibayar**.";
                sendDiscordNotification([
                    'admin_name' => $user['name']
                ], 'salary_unpaid_all');
            } else {
                throw new Exception("Gagal mereset semua status pembayaran. Mungkin tidak ada yang perlu direset.");
            }
            $stmt->close();
        } else {
            // Aksi-aksi berikut memerlukan employee_id
            if ($employee_id <= 0) {
                throw new Exception("ID anggota tidak valid.");
            }
            $employee_name = getEmployeeNameById($employee_id);

            if ($action === 'mark_paid') {
                $new_status = TRUE;
                $status_text = 'Sudah Dibayar';

                $stmt = $conn->prepare("UPDATE employees SET is_paid = ? WHERE id = ?");
                if (!$stmt) {
                    throw new Exception("Gagal menyiapkan query update status pembayaran: " . $conn->error);
                }
                $stmt->bind_param("ii", $new_status, $employee_id);

                if (!$stmt->execute() || $stmt->affected_rows === 0) {
                    throw new Exception("Gagal mengubah status pembayaran. Mungkin sudah dalam status yang sama atau ID tidak ditemukan.");
                }
                $stmt->close();

                $conn->commit();
                $success_message = "Status gaji **" . htmlspecialchars($employee_name) . "** berhasil diubah menjadi **{$status_text}**.";
                
                sendDiscordNotification([
                    'employee_name' => $employee_name,
                    'status' => $status_text,
                    'admin_name' => $user['name']
                ], 'salary_paid_single');
                
            } elseif ($action === 'mark_unpaid') {
                $new_status = FALSE;
                $status_text = 'Belum Dibayar';

                $stmt = $conn->prepare("UPDATE employees SET is_paid = ? WHERE id = ?");
                if (!$stmt) {
                    throw new Exception("Gagal menyiapkan query update status pembayaran: " . $conn->error);
                }
                $stmt->bind_param("ii", $new_status, $employee_id);

                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    $conn->commit();
                    $success_message = "Status gaji **" . htmlspecialchars($employee_name) . "** berhasil diubah menjadi **{$status_text}**.";
                    sendDiscordNotification([
                        'employee_name' => $employee_name,
                        'status' => $status_text,
                        'admin_name' => $user['name']
                    ], 'salary_unpaid_single');
                } else {
                    throw new Exception("Gagal mengubah status pembayaran. Mungkin sudah dalam status yang sama atau ID tidak ditemukan.");
                }
                $stmt->close();
            } elseif ($action === 'delete_sales_data') {
                // Hapus data penjualan dan masak
                $stmt_delete_sales = $conn->prepare("DELETE FROM sales_data WHERE employee_id = ?");
                if (!$stmt_delete_sales) {
                    throw new Exception("Gagal menyiapkan query hapus data penjualan: " . $conn->error);
                }
                $stmt_delete_sales->bind_param("i", $employee_id);
                $stmt_delete_sales->execute();
                $stmt_delete_sales->close();

                // Hapus log jam kerja dengan status 'completed'
                $stmt_delete_duty = $conn->prepare("DELETE FROM duty_logs WHERE employee_id = ? AND status = 'completed'");
                if (!$stmt_delete_duty) {
                    throw new Exception("Gagal menyiapkan query hapus log jam kerja: " . $conn->error);
                }
                $stmt_delete_duty->bind_param("i", $employee_id);
                $stmt_delete_duty->execute();
                $stmt_delete_duty->close();

                $conn->commit();
                $success_message = "Semua data penjualan, masak, dan jam kerja (completed) untuk **" . htmlspecialchars($employee_name) . "** telah berhasil dihapus.";
            }
        }

    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Terjadi kesalahan: " . $e->getMessage();
    }
    header("Location: salary-recap.php?msg=" . urlencode($success_message ?? $error_message) . "&type=" . urlencode(isset($success_message) ? 'success' : 'error'));
    exit;
}

if (isset($_GET['msg']) && isset($_GET['type'])) {
    $feedback_message = htmlspecialchars($_GET['msg']);
    $feedback_type = htmlspecialchars($_GET['type']);
    if ($feedback_type === 'success') {
        $success_message = $feedback_message;
    } else {
        $error_message = $feedback_message;
    }
}

$employees_data = [];
$total_payroll_expenditure = 0;

$stmt = $conn->query("
    SELECT e.id, e.name, e.role, e.is_on_duty, e.is_paid,
           COALESCE(duty_summary.total_duty_minutes, 0) as total_duty_minutes,
           COALESCE(sales_summary.total_paket_makan_minum_warga, 0) as total_paket_makan_minum_warga,
           COALESCE(sales_summary.total_paket_makan_minum_instansi, 0) as total_paket_makan_minum_instansi,
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
            SUM(paket_makan_minum_warga) as total_paket_makan_minum_warga,
            SUM(paket_makan_minum_instansi) as total_paket_makan_minum_instansi,
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
$employees_raw_data = $stmt->fetch_all(MYSQLI_ASSOC);

foreach ($employees_raw_data as $employee) {
    $employee_id = $employee['id'];
    $employee_role = $employee['role'];
    $total_duty_minutes = $employee['total_duty_minutes'];
    $total_paket_makan_minum_warga = $employee['total_paket_makan_minum_warga'];
    $total_paket_makan_minum_instansi = $employee['total_paket_makan_minum_instansi'];
    $total_paket_snack = $employee['total_paket_snack'];
    $total_masak_paket = $employee['total_masak_paket'];
    $total_masak_snack = $employee['total_masak_snack'];

    $overtime_minutes = 0;
    $overtime_hours_display = 0;
    $overtime_remaining_minutes = 0;
    $nominal_bonus_lembur_perjam = 0;
    $total_bonus_lembur = 0;

    $gaji_pokok = 0;
    if ($total_duty_minutes >= $min_duty_minutes_for_base_salary && isset($base_salaries[$employee_role])) {
        $gaji_pokok = $base_salaries[$employee_role];
    }

    $bonus_21_jam = 0;
    if ($total_duty_minutes >= $min_duty_minutes_for_bonus) {
        $bonus_21_jam = $duty_21_hour_bonus;
    }

    if ($total_duty_minutes > $min_duty_minutes_for_bonus) {
        $overtime_minutes_raw = $total_duty_minutes - $min_duty_minutes_for_bonus;
        $overtime_minutes = min($overtime_minutes_raw, $overtime_cap_minutes);
        
        $overtime_hours_display = floor($overtime_minutes / 60);
        $overtime_remaining_minutes = $overtime_minutes % 60;

        if (isset($overtime_hourly_bonus[$employee_role])) {
            $nominal_bonus_lembur_perjam = $overtime_hourly_bonus[$employee_role];
            $total_bonus_lembur = ($overtime_minutes / 60) * $nominal_bonus_lembur_perjam;
        }
    }
    
    $total_penjualan_paket = $total_paket_makan_minum_warga + $total_paket_makan_minum_instansi + $total_paket_snack;
    $bonus_penjualan = 0;
    if (in_array($employee_role, ['karyawan', 'magang'])) {
        if ($total_penjualan_paket >= $sales_bonus_threshold) {
            $bonus_penjualan = $sales_bonus_amount;
        }
    }

    $is_bonus_cut = false;
    $performance_indicator_text = '';

    if (in_array($employee_role, ['karyawan', 'magang'])) {
        $performance_indicator = $total_penjualan_paket;
        if ($performance_indicator < $performance_cut_off_threshold) {
            $bonus_21_jam *= 0.5;
            $total_bonus_lembur *= 0.5;
            $is_bonus_cut = true;
        }
    } elseif ($employee_role === 'chef') {
        $performance_indicator = $total_masak_paket + $total_masak_snack;
        if ($performance_indicator < $performance_cut_off_threshold) {
            $bonus_21_jam *= 0.5;
            $total_bonus_lembur *= 0.5;
            $is_bonus_cut = true;
        }
    }
    
    $total_gajian = $gaji_pokok + $bonus_21_jam + $total_bonus_lembur + $bonus_penjualan;
    $total_payroll_expenditure += $total_gajian;

    $employees_data[] = [
        'id' => $employee['id'],
        'name' => $employee['name'],
        'role' => $employee['role'],
        'is_paid' => (bool)$employee['is_paid'],
        'total_duty_minutes' => $total_duty_minutes,
        'total_paket_makan_minum_warga' => $total_paket_makan_minum_warga,
        'total_paket_makan_minum_instansi' => $total_paket_makan_minum_instansi,
        'total_paket_snack' => $total_paket_snack,
        'total_masak_paket' => $total_masak_paket,
        'total_masak_snack' => $total_masak_snack,
        'total_sales_packages' => $total_penjualan_paket,
        'total_masak_packages' => $total_masak_paket + $total_masak_snack,
        'overtime_hours_display' => $overtime_hours_display,
        'overtime_remaining_minutes' => $overtime_remaining_minutes,
        'gaji_pokok' => $gaji_pokok,
        'bonus_penjualan' => $bonus_penjualan,
        'nominal_bonus_lembur_perjam' => $nominal_bonus_lembur_perjam,
        'total_bonus_lembur' => $total_bonus_lembur,
        'bonus_21_jam' => $bonus_21_jam,
        'total_gajian' => $total_gajian,
        'is_bonus_cut' => $is_bonus_cut
    ];
}

// === START EXPORT LOGIC ===
if (isset($_GET['export']) && $_GET['export'] == 'spreadsheet') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="rekap_gajian_' . date('Ymd_His') . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    $headers = [
        'Nama',
        'Jabatan',
        'Total Jam Duty (Jam)',
        'Total Jam Duty (Menit)',
        'Total Paket M&M Warga',
        'Total Paket M&M Instansi',
        'Total Paket Snack',
        'Total Masak Paket',
        'Total Masak Snack',
        'Total Penjualan Paket',
        'Jam Lembur (Jam)',
        'Menit Lembur (Sisa)',
        'Gaji Pokok (Rp)',
        'Bonus Penjualan (Rp)',
        'Bonus Lembur Per Jam (Rp)',
        'Total Bonus Lembur (Rp)',
        'Bonus On Duty 21 Jam (Rp)',
        'Total Gajian (Rp)',
        'Status Pembayaran'
    ];
    fputcsv($output, $headers);

    foreach ($employees_data as $row) {
        $data_row = [
            htmlspecialchars_decode($row['name']),
            getRoleDisplayName($row['role']),
            floor($row['total_duty_minutes'] / 60),
            $row['total_duty_minutes'],
            $row['total_paket_makan_minum_warga'],
            $row['total_paket_makan_minum_instansi'],
            $row['total_paket_snack'],
            $row['total_masak_paket'],
            $row['total_masak_snack'],
            $row['total_sales_packages'],
            $row['overtime_hours_display'],
            $row['overtime_remaining_minutes'],
            $row['gaji_pokok'],
            $row['bonus_penjualan'],
            $row['nominal_bonus_lembur_perjam'],
            $row['total_bonus_lembur'],
            $row['bonus_21_jam'],
            $row['total_gajian'],
            $row['is_paid'] ? 'Sudah Dibayar' : 'Belum Dibayar'
        ];
        fputcsv($output, $data_row);
    }

    fclose($output);
    exit;
}
// === END EXPORT LOGIC ===
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekap Gaji - Warung Om Tante</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <style>
        .payslip-status {
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-md);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .payslip-status.paid {
            background-color: var(--success-light);
            color: var(--success-color);
        }
        .payslip-status.unpaid {
            background-color: var(--warning-light);
            color: var(--warning-color);
        }
        .action-column {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            flex-wrap: wrap;
            justify-content: flex-end;
            align-items: flex-end;
        }
        .action-column .btn {
            padding: 0.4rem 0.8rem;
            font-size: 0.75rem;
            white-space: nowrap;
        }
        @media (max-width: 1024px) {
            .activities-table-improved th,
            .activities-table-improved td {
                padding: 0.6rem;
            }
            .action-column {
                align-items: stretch;
            }
        }
        @media (max-width: 768px) {
            .activities-table-improved td:before {
                width: 40%;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include 'includes/header.php'; ?>
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1>
                    <span class="page-icon">💸</span>
                    Rekap Gaji
                </h1>
                <p>Ringkasan perhitungan gaji untuk semua anggota Warung Om Tante</p>
                <div class="page-actions" style="margin-top: var(--spacing-md);">
                    <a href="salary-recap.php?export=spreadsheet" class="btn btn-info" target="_blank">
                        <span class="btn-icon">⬇️</span>
                        Unduh Rekap Spreadsheet
                    </a>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Yakin ingin MERESET status pembayaran semua anggota menjadi Belum Dibayar? Tindakan ini tidak dapat dibatalkan untuk semua!')">
                        <input type="hidden" name="action" value="reset_all_paid_status">
                        <button type="submit" class="btn btn-warning">
                            <span class="btn-icon">🔄</span> Reset Semua Status Bayar
                        </button>
                    </form>
                </div>
            </div>

            <?php if (isset($success_message)): ?>
                <div class="success-message">🎉 <?= htmlspecialchars($success_message) ?></div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="error-message">❌ <?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>

            <div class="summary-stats-container">
                <div class="summary-card">
                    <div class="summary-icon" style="color: var(--info-color);">💲</div>
                    <div class="summary-content">
                        <h4>Total Pengeluaran Gaji</h4>
                        <p class="summary-value">
                            <?= 'Rp ' . number_format($total_payroll_expenditure, 0, ',', '.') ?>
                        </p>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon" style="color: var(--primary-color);">👥</div>
                    <div class="summary-content">
                        <h4>Jumlah Anggota</h4>
                        <p class="summary-value">
                            <?= count($employees_data) ?> Orang
                        </p>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon" style="color: var(--success-color);">✅</div>
                    <div class="summary-content">
                        <h4>Anggota Aktif On Duty</h4>
                        <p class="summary-value">
                            <?= count(array_filter($employees_raw_data, function($emp) { return $emp['is_on_duty']; })) ?>
                        </p>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon" style="color: var(--warning-color);">⏰</div>
                    <div class="summary-content">
                        <h4>Rata-rata Gaji per Anggota</h4>
                        <p class="summary-value">
                            <?= count($employees_data) > 0 ? 'Rp ' . number_format($total_payroll_expenditure / count($employees_data), 0, ',', '.') : 'Rp 0' ?>
                        </p>
                    </div>
                </div>
            </div>

            <div class="card full-width">
                <div class="card-header">
                    <h3>Detail Perhitungan Gaji</h3>
                </div>
                <div class="card-content">
                    <div class="responsive-table-container">
                        <table class="activities-table-improved">
                            <thead>
                                <tr>
                                    <th>Nama</th>
                                    <th>Jabatan</th>
                                    <th>Total Jam Duty</th>
                                    <th>Total Penjualan/Masak</th>
                                    <th>Jam Lembur</th>
                                    <th>Gaji Pokok</th>
                                    <th>Bonus Penjualan</th>
                                    <th>Bonus Lembur</th>
                                    <th>Bonus On Duty 21 Jam</th>
                                    <th>Total Gaji</th>
                                    <th>Status</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($employees_data)): ?>
                                    <tr>
                                        <td colspan="12" class="no-data">Belum ada data anggota atau aktivitas untuk rekap gaji.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($employees_data as $employee): ?>
                                    <tr data-employee-id="<?= $employee['id'] ?>">
                                        <td data-label="Nama">
                                            <div class="employee-name-cell">
                                                <span class="employee-avatar-small">
                                                    <?= strtoupper(substr(htmlspecialchars($employee['name']), 0, 1)) ?>
                                                </span>
                                                <span><?= htmlspecialchars($employee['name']) ?></span>
                                            </div>
                                        </td>
                                        <td data-label="Jabatan">
                                            <span class="role-badge role-<?= htmlspecialchars($employee['role']) ?>">
                                                <?= htmlspecialchars(getRoleDisplayName($employee['role'])) ?>
                                            </span>
                                        </td>
                                        <td data-label="Total Jam Duty">
                                            <?= formatDuration($employee['total_duty_minutes']) ?>
                                        </td>
                                        <td data-label="Total Penjualan">
                                            <?= $employee['role'] === 'chef' ? $employee['total_masak_packages'] . ' Masak' : $employee['total_sales_packages'] . ' Paket' ?>
                                        </td>
                                        <td data-label="Jam Lembur">
                                            <?php 
                                            echo $employee['overtime_hours_display'] . 'j ' . $employee['overtime_remaining_minutes'] . 'm';
                                            ?>
                                            <?php if ($employee['is_bonus_cut']): ?>
                                                <small style="display: block; color: var(--danger-color); font-weight: 600;">(Potongan 50%)</small>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Gaji Pokok">
                                            <?= 'Rp ' . number_format($employee['gaji_pokok'], 0, ',', '.') ?>
                                        </td>
                                        <td data-label="Bonus Penjualan">
                                            <?= 'Rp ' . number_format($employee['bonus_penjualan'], 0, ',', '.') ?>
                                        </td>
                                        <td data-label="Bonus Lembur">
                                            <?= 'Rp ' . number_format($employee['total_bonus_lembur'], 0, ',', '.') ?>
                                            <?php if ($employee['total_bonus_lembur'] > 0): ?>
                                                <small style="display: block; color: var(--text-muted);">(<?= 'Rp ' . number_format($employee['nominal_bonus_lembur_perjam'], 0, ',', '.') ?>/jam)</small>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Bonus On Duty 21 Jam">
                                            <?= 'Rp ' . number_format($employee['bonus_21_jam'], 0, ',', '.') ?>
                                        </td>
                                        <td data-label="Total Gaji">
                                            <strong><?= 'Rp ' . number_format($employee['total_gajian'], 0, ',', '.') ?></strong>
                                        </td>
                                        <td data-label="Status">
                                            <span class="payslip-status <?= $employee['is_paid'] ? 'paid' : 'unpaid' ?>">
                                                <?= $employee['is_paid'] ? 'Sudah Dibayar' : 'Belum Dibayar' ?>
                                            </span>
                                        </td>
                                        <td data-label="Aksi" class="action-column">
                                            <?php if (!$employee['is_paid']): ?>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Yakin ingin menandai gaji <?= htmlspecialchars($employee['name']) ?> sebagai Sudah Dibayar? Status akan tersimpan.')">
                                                <input type="hidden" name="action" value="mark_paid">
                                                <input type="hidden" name="employee_id" value="<?= $employee['id'] ?>">
                                                <button type="submit" class="btn btn-success btn-sm">Tandai Dibayar</button>
                                            </form>
                                            <?php else: ?>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Yakin ingin menandai gaji <?= htmlspecialchars($employee['name']) ?> sebagai Belum Dibayar? Status akan tersimpan.')">
                                                <input type="hidden" name="action" value="mark_unpaid">
                                                <input type="hidden" name="employee_id" value="<?= $employee['id'] ?>">
                                                <button type="submit" class="btn btn-warning btn-sm">Batal Dibayar</button>
                                            </form>
                                            <?php endif; ?>
                                            <form method="POST" style="display: inline; margin-top: 5px;" onsubmit="return confirm('Yakin ingin menghapus data penjualan dan masak untuk <?= htmlspecialchars($employee['name']) ?>? Tindakan ini tidak dapat dibatalkan.')">
                                                <input type="hidden" name="action" value="delete_sales_data">
                                                <input type="hidden" name="employee_id" value="<?= $employee['id'] ?>">
                                                <button type="submit" class="btn btn-danger btn-sm">Hapus Data Penjualan</button>
                                            </form>
                                            <a href="generate-payslip.php?employee_id=<?= $employee['id'] ?>" target="_blank" class="btn btn-primary btn-sm">Unduh Slip Gaji</a>
                                        </td>
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
</body>
</html>