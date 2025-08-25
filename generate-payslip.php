<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();

$is_admin_or_manager = hasRole(['direktur', 'wakil_direktur', 'manager']);

$employee_id_to_view = 0;

if (isset($_GET['employee_id'])) {
    $requested_id = (int)$_GET['employee_id'];
    
    if ($is_admin_or_manager) {
        $employee_id_to_view = $requested_id;
    } elseif ($requested_id === $user['id']) {
        $employee_id_to_view = $user['id'];
    } else {
        header('Location: my-payslip.php');
        exit;
    }
} else {
    $employee_id_to_view = $user['id'];
}

if ($employee_id_to_view <= 0) {
    die("ID anggota tidak valid atau tidak diberikan.");
}

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

$min_duty_hours_for_base_salary = 5;
$min_duty_minutes_for_base_salary = $min_duty_hours_for_base_salary * 60;

$min_duty_hours_for_bonus = 21;
$min_duty_minutes_for_bonus = $min_duty_hours_for_bonus * 60;

$overtime_cap_hours = 15;
$overtime_cap_minutes = $overtime_cap_hours * 60;

$sales_bonus_threshold = 400;
$sales_bonus_amount = 800000;

$duty_21_hour_bonus = 1000000;

$performance_cut_off_threshold = 400;

$stmt = $conn->prepare("
    SELECT e.id, e.name, e.role,
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
    WHERE e.id = ? AND e.status = 'active'
    GROUP BY e.id
");
$stmt->bind_param("i", $employee_id_to_view);
$stmt->execute();
$employee_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$employee_data) {
    die("Data slip gaji tidak ditemukan untuk ID anggota ini.");
}

$employee_role = $employee_data['role'];
$total_duty_minutes = $employee_data['total_duty_minutes'];
$total_paket_makan_minum_warga = $employee_data['total_paket_makan_minum_warga'];
$total_paket_makan_minum_instansi = $employee_data['total_paket_makan_minum_instansi'];
$total_paket_snack = $employee_data['total_paket_snack'];
$total_masak_paket = $employee_data['total_masak_paket'];
$total_masak_snack = $employee_data['total_masak_snack'];

$overtime_minutes = 0;
$overtime_hours_display = 0;
$overtime_remaining_minutes = 0;
$nominal_bonus_lembur_perjam = 0;
$total_bonus_lembur = 0;

// Perhitungan Gaji Pokok
$gaji_pokok = 0;
if ($total_duty_minutes >= $min_duty_minutes_for_base_salary && isset($base_salaries[$employee_role])) {
    $gaji_pokok = $base_salaries[$employee_role];
}

// Perhitungan Bonus Jam Duty 21 Jam
$bonus_21_jam = 0;
if ($total_duty_minutes >= $min_duty_minutes_for_bonus) {
    $bonus_21_jam = $duty_21_hour_bonus;
}

// Perhitungan Jam Lembur dan Bonus Lembur
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

// Perhitungan Bonus Penjualan
$total_penjualan_paket = $total_paket_makan_minum_warga + $total_paket_makan_minum_instansi + $total_paket_snack;
$bonus_penjualan = 0;
if (in_array($employee_role, ['karyawan', 'magang'])) {
    if ($total_penjualan_paket >= $sales_bonus_threshold) {
        $bonus_penjualan = $sales_bonus_amount;
    }
}

// Perhitungan Potongan 50% untuk Karyawan & Magang (Penjualan) dan Chef (Masak)
$is_bonus_cut = false;
$performance_indicator = 0;
$performance_target_name = '';

if (in_array($employee_role, ['karyawan', 'magang'])) {
    $performance_indicator = $total_penjualan_paket;
    $performance_target_name = 'Penjualan';
    if ($performance_indicator < $performance_cut_off_threshold) {
        $bonus_21_jam *= 0.5;
        $total_bonus_lembur *= 0.5;
        $is_bonus_cut = true;
    }
} elseif ($employee_role === 'chef') {
    $performance_indicator = $total_masak_paket + $total_masak_snack;
    $performance_target_name = 'Memasak';
    if ($performance_indicator < $performance_cut_off_threshold) {
        $bonus_21_jam *= 0.5;
        $total_bonus_lembur *= 0.5;
        $is_bonus_cut = true;
    }
}

// Perhitungan Total Gaji
$total_gajian = $gaji_pokok + $bonus_21_jam + $total_bonus_lembur + $bonus_penjualan;

// Hitung Total Nominal Bonus
$total_nominal_bonus = $bonus_21_jam + $total_bonus_lembur + $bonus_penjualan;

// Fungsi format mata uang
function formatRupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.') . '';
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Slip Gaji - <?= htmlspecialchars($employee_data['name']) ?></title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <style>
        body {
            font-family: 'Arial', sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f4f7f6;
            color: #333;
            line-height: 1.6;
        }
        .payslip-container {
            width: 100%;
            max-width: 800px;
            margin: 20px auto;
            background-color: #fff;
            border: 1px solid #ddd;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            padding: 30px;
            box-sizing: border-box;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 20px;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            padding-left: 120px;
            padding-right: 120px;
            box-sizing: border-box;
        }
        .header h1 {
            margin: 0;
            font-size: 2em;
            color: #3b82f6;
            flex-shrink: 0;
        }
        .header p {
            margin: 5px 0 0;
            font-size: 0.9em;
            color: #666;
            flex-shrink: 0;
        }
        .header-content-center {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            flex-grow: 1;
        }
        .logo-header {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 120px;
            height: auto;
            object-fit: contain;
            z-index: 10;
        }
        .logo-left {
            left: 0px;
        }
        .logo-right {
            right: 0px;
        }
        .section-title {
            font-size: 1.2em;
            font-weight: bold;
            margin-top: 25px;
            margin-bottom: 15px;
            color: #3b82f6;
            border-bottom: 1px solid #eee;
            padding-bottom: 5px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px 20px;
            margin-bottom: 20px;
        }
        .info-item span:first-child {
            font-weight: bold;
            color: #555;
            min-width: 120px;
            display: inline-block;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
        }
        table th, table td {
            border: 1px solid #eee;
            padding: 10px;
            text-align: left;
        }
        table th {
            background-color: #f0f0f0;
            color: #555;
            font-weight: bold;
        }
        .total-row {
            font-weight: bold;
            background-color: #e6f0fa;
            color: #3b82f6;
        }
        .total-row td {
            font-size: 1.1em;
        }
        .signature-section {
            display: flex;
            justify-content: space-between;
            margin-top: 50px;
            font-size: 0.9em;
        }
        .signature-box {
            text-align: center;
            width: 30%;
        }
        .signature-box p {
            margin-top: 60px;
            border-top: 1px solid #333;
            padding-top: 5px;
        }
        .footer {
            text-align: center;
            margin-top: 40px;
            font-size: 0.8em;
            color: #888;
        }
        @media print {
            body {
                background-color: #fff;
                margin: 0;
                padding: 0;
            }
            .payslip-container {
                box-shadow: none;
                border: none;
                margin: 0;
                width: 100%;
                padding: 15px;
            }
            .btn {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="payslip-container">
        <div class="header">
            <img src="LOGO_WOT.png" alt="Logo Kiri" class="logo-header logo-left">
            <div class="header-content-center">
                <h1>SLIP GAJI</h1>
                <p>Warung Om Tante</p>
                <p>Periode: Akumulatif Hingga <?= date('d M Y') ?></p>
            </div>
            <img src="LOGO_WOT.png" alt="Logo Kanan" class="logo-header logo-right">
        </div>

        <div class="section-title">Informasi Karyawan</div>
        <div class="info-grid">
            <div class="info-item"><span>Nama:</span> <?= htmlspecialchars($employee_data['name']) ?></div>
            <div class="info-item"><span>Jabatan:</span> <?= getRoleDisplayName($employee_data['role']) ?></div>
            <div class="info-item"><span>ID Karyawan:</span> <?= $employee_data['id'] ?></div>
            <div class="info-item"><span>Tanggal Cetak:</span> <?= date('d/m/Y H:i') ?></div>
        </div>

        <div class="section-title">Detail Gaji dan Bonus</div>
        <table>
            <thead>
                <tr>
                    <th>Komponen</th>
                    <th style="text-align: right;">Jumlah</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Gaji Pokok </td>
                    <td style="text-align: right;"><?= formatRupiah($gaji_pokok) ?></td>
                </tr>
                <tr>
                    <td>Bonus On Duty >= <?= $min_duty_hours_for_bonus ?> Jam</td>
                    <td style="text-align: right;"><?= formatRupiah($bonus_21_jam) ?>
                    <?php if($is_bonus_cut): ?><br><small style='color: #ef4444'>(Dipotong 50%)</small><?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td>Total Bonus Lembur (<?= $overtime_hours_display ?>j <?= $overtime_remaining_minutes ?>m)</td>
                    <td style="text-align: right;"><?= formatRupiah($total_bonus_lembur) ?>
                    <?php if($is_bonus_cut): ?><br><small style='color: #ef4444'>(Dipotong 50%)</small><?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td>Bonus Penjualan (>= <?= $sales_bonus_threshold ?> Paket)</td>
                    <td style="text-align: right;"><?= formatRupiah($bonus_penjualan) ?></td>
                </tr>
                <tr class="total-row">
                    <td>TOTAL GAJI BERSIH</td>
                    <td style="text-align: right;"><?= formatRupiah($total_gajian) ?></td>
                </tr>
            </tbody>
        </table>

        <div class="section-title">Ringkasan Kinerja</div>
        <div class="info-grid">
            <div class="info-item"><span>Total Jam Duty:</span> <?= formatDuration($total_duty_minutes) ?></div>
            <div class="info-item">
                <span>Total Penjualan:</span>
                <?php if ($employee_role === 'chef'): ?>
                    <?= $total_masak_paket + $total_masak_snack ?> Masak
                <?php else: ?>
                    <?= $total_penjualan_paket ?> Paket
                <?php endif; ?>
            </div>
            <div class="info-item"><span>Jam Lembur:</span> <?= $overtime_hours_display ?>j <?= $overtime_remaining_minutes ?>m</div>
            <div class="info-item"><span>Total Nominal Bonus:</span> <?= formatRupiah($total_nominal_bonus) ?></div>
            <div class="info-item"><span>Total Penjualan M&M Warga:</span> <?= $total_paket_makan_minum_warga ?></div>
            <div class="info-item"><span>Total Penjualan M&M Instansi:</span> <?= $total_paket_makan_minum_instansi ?></div>
            <div class="info-item"><span>Total Penjualan Snack:</span> <?= $total_paket_snack ?></div>
            <div class="info-item"><span>Total Masak Paket:</span> <?= $total_masak_paket ?></div>
            <div class="info-item"><span>Total Masak Snack:</span> <?= $total_masak_snack ?></div>
        </div>

        <div class="signature-section">
            <div class="signature-box">
                <p>Karyawan Ybs.</p>
            </div>
            <div class="signature-box">
                <p>Bagian Keuangan</p>
            </div>
            <div class="signature-box">
                <p>Direktur</p>
            </div>
        </div>

        <div class="footer">
            <p>Slip gaji ini dibuat secara otomatis oleh Warung Om Tante Management System.</p>
        </div>

        <button onclick="window.print()" class="btn btn-primary" style="display: block; margin: 20px auto;">Cetak Slip Gaji</button>
    </div>
</body>
</html>