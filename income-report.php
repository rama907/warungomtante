<?php
require_once 'config.php';

if (!isLoggedIn() || !hasRole(['direktur', 'wakil_direktur', 'manager'])) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount(); // Untuk sidebar

// Definisi harga per paket
$price_warga = 25000;
$price_instansi = 18000;
$price_snack = 15000; // Harga per paket snack

$total_income_warga = 0;
$total_income_instansi = 0;
$total_income_snack = 0; // Inisialisasi total pemasukan snack
$overall_total_income = 0;

$total_warga_packages = 0;
$total_instansi_packages = 0;
$total_snack_packages = 0; // Inisialisasi total paket snack

// Ambil total paket makan minum warga, instansi, dan snack dari sales_data secara menyeluruh
$stmt = $conn->prepare("
    SELECT
        COALESCE(SUM(paket_makan_minum_warga), 0) as sum_warga,
        COALESCE(SUM(paket_makan_minum_instansi), 0) as sum_instansi,
        COALESCE(SUM(paket_snack), 0) as sum_snack
    FROM sales_data
");
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($result) {
    $total_warga_packages = $result['sum_warga'];
    $total_instansi_packages = $result['sum_instansi'];
    $total_snack_packages = $result['sum_snack']; // Ambil jumlah paket snack

    $total_income_warga = $total_warga_packages * $price_warga;
    $total_income_instansi = $total_instansi_packages * $price_instansi;
    $total_income_snack = $total_snack_packages * $price_snack; // Hitung pemasukan snack

    $overall_total_income = $total_income_warga + $total_income_instansi + $total_income_snack; // Tambahkan pemasukan snack ke total
}

// --- Data untuk Grafik Omset Per Hari ---
$daily_revenue_data = [];
$stmt_daily = $conn->prepare("
    SELECT
        date,
        SUM(paket_makan_minum_warga) as sum_warga_daily,
        SUM(paket_makan_minum_instansi) as sum_instansi_daily,
        SUM(paket_snack) as sum_snack_daily
    FROM sales_data
    GROUP BY date
    ORDER BY date ASC
");
$stmt_daily->execute();
$daily_results = $stmt_daily->get_result();

$chart_labels = [];
$chart_data_revenue = [];

while ($row = $daily_results->fetch_assoc()) {
    $date_label = date('d/m/Y', strtotime($row['date']));
    $daily_omset = ($row['sum_warga_daily'] * $price_warga) +
                   ($row['sum_instansi_daily'] * $price_instansi) +
                   ($row['sum_snack_daily'] * $price_snack);
    
    $chart_labels[] = $date_label;
    $chart_data_revenue[] = $daily_omset;
}
$stmt_daily->close();

// Encode data grafik ke JSON untuk JavaScript
$chart_labels_json = json_encode($chart_labels);
$chart_data_revenue_json = json_encode($chart_data_revenue);


// --- Data untuk Logs Omset (Detail per Input) ---
$omset_logs = [];
$stmt_logs = $conn->prepare("
    SELECT
        sd.id,
        sd.date,
        sd.input_time,
        sd.paket_makan_minum_warga,
        sd.paket_makan_minum_instansi,
        sd.paket_snack,
        -- Hapus sd.masak_paket, sd.masak_snack karena tidak dihitung dalam omset
        e.name as employee_name
    FROM sales_data sd
    JOIN employees e ON sd.employee_id = e.id
    ORDER BY sd.input_time DESC
");
$stmt_logs->execute();
$log_results = $stmt_logs->get_result();

while ($row = $log_results->fetch_assoc()) {
    $transaction_omset = ($row['paket_makan_minum_warga'] * $price_warga) +
                         ($row['paket_makan_minum_instansi'] * $price_instansi) +
                         ($row['paket_snack'] * $price_snack);
    
    $omset_logs[] = [
        'id' => $row['id'],
        'date_time' => date('d/m/Y H:i:s', strtotime($row['input_time'])),
        'employee_name' => $row['employee_name'],
        'paket_makan_minum_warga' => $row['paket_makan_minum_warga'],
        'paket_makan_minum_instansi' => $row['paket_makan_minum_instansi'],
        'paket_snack' => $row['paket_snack'],
        // Hapus 'masak_paket' => $row['masak_paket'],
        // Hapus 'masak_snack' => $row['masak_snack'],
        'omset_transaksi' => $transaction_omset
    ];
}
$stmt_logs->close();


// --- Fungsionalitas Unduh Laporan Detail untuk Audit ---
if (isset($_GET['export']) && $_GET['export'] == 'detailed_income') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="laporan_pemasukan_detail_' . date('Ymd_His') . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

    $headers = [
        'ID Transaksi',
        'Tanggal & Waktu Input',
        'Nama Anggota',
        'Paket M&M Warga (Jumlah)',
        'Paket M&M Instansi (Jumlah)',
        'Paket Snack (Jumlah)',
        'Omset Transaksi (Rp)' // Masak paket/snack dihilangkan dari sini juga
    ];
    fputcsv($output, $headers);

    foreach ($omset_logs as $log) {
        $data_row = [
            $log['id'],
            $log['date_time'],
            htmlspecialchars_decode($log['employee_name']),
            $log['paket_makan_minum_warga'],
            $log['paket_makan_minum_instansi'],
            $log['paket_snack'],
            $log['omset_transaksi']
        ];
        fputcsv($output, $data_row);
    }

    fclose($output);
    exit;
}


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
    <title>Laporan Pemasukan - Warung Om Tante</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .income-report-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: var(--spacing-xl);
            margin-bottom: var(--spacing-2xl);
        }
        .income-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-2xl);
            padding: var(--spacing-xl);
            box-shadow: var(--shadow-sm);
            transition: all 0.3s ease;
            text-align: center;
        }
        .income-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        .income-card .icon {
            font-size: 3.5rem;
            margin-bottom: var(--spacing-md);
        }
        .income-card h4 {
            font-size: 1rem;
            color: var(--text-secondary);
            margin-bottom: var(--spacing-xs);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .income-card .value {
            font-size: 2.25rem;
            font-weight: 700;
            color: var(--primary-color);
            letter-spacing: -0.025em;
            margin: 0;
        }
        .income-card.total .value {
            color: var(--success-color);
            font-size: 2.8rem;
        }
        .income-card .detail-text {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-top: var(--spacing-sm);
        }
        .chart-container {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-sm);
            padding: var(--spacing-xl);
            margin-top: var(--spacing-2xl);
            width: 100%; /* Pastikan grafik mengisi lebar penuh */
            height: 550px; /* PERBAIKAN: Tinggi grafik diperbesar */
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .chart-container h3 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: var(--spacing-xl);
            text-align: center;
        }
        .chart-canvas-wrapper {
            position: relative; /* Untuk memastikan canvas mengisi container */
            width: 100%;
            height: 100%;
        }
        #dailyRevenueChart {
            max-width: 100%; /* Pastikan canvas tidak melebihi lebar container */
            max-height: 100%; /* Pastikan canvas tidak melebihi tinggi container */
        }

        /* Styles for Logs Omset table */
        .logs-omset-section {
            margin-top: var(--spacing-2xl);
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-sm);
            padding: var(--spacing-xl);
        }
        .logs-omset-section .card-header {
            padding: 0; /* Override default card-header padding as it's already in section */
            border-bottom: none;
            margin-bottom: var(--spacing-xl);
        }
        .logs-omset-section h3 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: var(--spacing-xl);
        }
        /* Penyesuaian untuk tampilan tabel agar lebih padat di log */
        .logs-omset-section .activities-table-improved th,
        .logs-omset-section .activities-table-improved td {
            padding: var(--spacing-sm); /* Padding lebih kecil */
            font-size: 0.85rem; /* Ukuran font lebih kecil */
            vertical-align: middle; /* Pusatkan teks vertikal */
        }
        .logs-omset-section .activities-table-improved .employee-name-cell {
            white-space: normal; /* Izinkan wrapping untuk nama anggota */
        }
        /* PERBAIKAN PENTING: Gaya untuk memastikan display tabel standar pada layar lebar */
        @media (min-width: 769px) { /* Jika di atas 768px, gunakan display tabel normal */
            .logs-omset-section .activities-table-improved thead {
                display: table-header-group;
            }
            .logs-omset-section .activities-table-improved tr {
                display: table-row;
            }
            .logs-omset-section .activities-table-improved td {
                display: table-cell;
                padding-left: var(--spacing-sm); /* Reset padding-left mobile */
                text-align: left; /* Default text alignment */
                white-space: nowrap; /* Cegah wrapping default kecuali yang diizinkan */
            }
            .logs-omset-section .activities-table-improved td:first-child { /* Tanggal & Waktu */
                 width: 18%;
                 min-width: 120px;
                 text-align: left;
            }
            .logs-omset-section .activities-table-improved td:nth-child(2) { /* Nama Anggota */
                 width: 20%;
                 min-width: 150px;
                 white-space: normal; /* Izinkan wrapping untuk nama */
            }
            .logs-omset-section .activities-table-improved td:nth-child(3), /* P. M&M Warga */
            .logs-omset-section .activities-table-improved td:nth-child(4), /* P. M&M Instansi */
            .logs-omset-section .activities-table-improved td:nth-child(5) { /* Paket Snack */
                width: 15%;
                min-width: 80px;
                text-align: center;
            }
            .logs-omset-section .activities-table-improved td:last-child { /* Omset Transaksi */
                width: 17%;
                min-width: 100px;
                text-align: right;
            }

            .logs-omset-section .activities-table-improved td:before {
                content: none; /* Sembunyikan data-label pada layar lebar */
            }
        }
        /* Mobile specific adjustments (re-apply mobile styles within this section's context) */
        @media (max-width: 768px) {
            .logs-omset-section .activities-table-improved thead {
                display: none; /* Hide header on small screens */
            }
            .logs-omset-section .activities-table-improved,
            .logs-omset-section .activities-table-improved tbody,
            .logs-omset-section .activities-table-improved tr,
            .logs-omset-section .activities-table-improved td {
                display: block; /* Stack on small screens */
            }
            .logs-omset-section .activities-table-improved tr {
                margin-bottom: var(--spacing-md);
                padding: var(--spacing-md);
            }
            .logs-omset-section .activities-table-improved td {
                padding: var(--spacing-xs) 0; /* Adjust padding for stacked cells */
                padding-left: 45%; /* Space for data-label */
                position: relative;
                text-align: left;
                white-space: normal; /* Allow wrapping */
            }
            .logs-omset-section .activities-table-improved td:before {
                content: attr(data-label) ": "; /* Show data-label */
                position: absolute;
                left: 6px;
                width: 40%; /* Width of the label */
                white-space: nowrap;
                font-weight: bold;
                color: var(--text-secondary);
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
                    <span class="page-icon">📈</span>
                    Laporan Pemasukan
                </h1>
                <p>Ikhtisar total pemasukan dari penjualan paket makan minum.</p>
                <div class="page-actions" style="margin-top: var(--spacing-md);">
                    <a href="income-report.php?export=detailed_income" class="btn btn-info" target="_blank">
                        <span class="btn-icon">⬇️</span>
                        Unduh Laporan Detail
                    </a>
                </div>
            </div>

            <div class="income-report-grid">
                <div class="income-card">
                    <div class="icon" style="color: #60a5fa;">👨‍👩‍👧‍👦</div>
                    <h4>Pemasukan Paket Warga</h4>
                    <p class="value"><?= formatRupiah($total_income_warga) ?></p>
                    <p class="detail-text"><?= $total_warga_packages ?> paket @ <?= formatRupiah($price_warga) ?></p>
                </div>

                <div class="income-card">
                    <div class="icon" style="color: #fbbf24;">🏢</div>
                    <h4>Pemasukan Paket Instansi</h4>
                    <p class="value"><?= formatRupiah($total_income_instansi) ?></p>
                    <p class="detail-text"><?= $total_instansi_packages ?> paket @ <?= formatRupiah($price_instansi) ?></p>
                </div>

                <div class="income-card">
                    <div class="icon" style="color: #10b981;">🍰</div>
                    <h4>Pemasukan Paket Snack</h4>
                    <p class="value"><?= formatRupiah($total_income_snack) ?></p>
                    <p class="detail-text"><?= $total_snack_packages ?> paket @ <?= formatRupiah($price_snack) ?></p>
                </div>

                <div class="income-card total">
                    <div class="icon" style="color: var(--success-color);">💰</div>
                    <h4>Total Pemasukan Keseluruhan</h4>
                    <p class="value"><?= formatRupiah($overall_total_income) ?></p>
                    <p class="detail-text">Gabungan dari semua penjualan paket makan minum & snack</p>
                </div>
            </div>

            <div class="chart-container">
                <h3>Grafik Omset Harian</h3>
                <div class="chart-canvas-wrapper">
                    <canvas id="dailyRevenueChart"></canvas>
                </div>
            </div>

            <div class="logs-omset-section">
                <div class="card-header">
                    <h3>Logs Omset Penjualan (Detail per Input)</h3>
                </div>
                <div class="card-content" style="padding-top: 0;">
                    <?php if (empty($omset_logs)): ?>
                        <div class="no-data">Belum ada data penjualan untuk ditampilkan di log.</div>
                    <?php else: ?>
                        <div class="responsive-table-container">
                            <table class="activities-table-improved">
                                <thead>
                                    <tr>
                                        <th>Tanggal & Waktu</th>
                                        <th>Nama Anggota</th>
                                        <th>P. M&M Warga</th>
                                        <th>P. M&M Instansi</th>
                                        <th>Paket Snack</th>
                                        <th>Omset Transaksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($omset_logs as $log): ?>
                                    <tr>
                                        <td data-label="Tanggal & Waktu"><?= $log['date_time'] ?></td>
                                        <td data-label="Nama Anggota" class="employee-name-cell"><?= htmlspecialchars($log['employee_name']) ?></td>
                                        <td data-label="P. M&M Warga"><?= $log['paket_makan_minum_warga'] ?></td>
                                        <td data-label="P. M&M Instansi"><?= $log['paket_makan_minum_instansi'] ?></td>
                                        <td data-label="Paket Snack"><?= $log['paket_snack'] ?></td>
                                        <td data-label="Omset Transaksi"><?= formatRupiah($log['omset_transaksi']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </main>
    </div>

    <script src="script.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Data dari PHP
            const chartLabels = <?= $chart_labels_json ?>;
            const chartDataRevenue = <?= $chart_data_revenue_json ?>;

            const ctx = document.getElementById('dailyRevenueChart').getContext('2d');
            new Chart(ctx, {
                type: 'bar', // Anda bisa mencoba 'line' juga
                data: {
                    labels: chartLabels,
                    datasets: [{
                        label: 'Omset Harian (Rp)',
                        data: chartDataRevenue,
                        backgroundColor: 'rgba(59, 130, 246, 0.6)', // Warna biru primary-color
                        borderColor: 'rgba(59, 130, 246, 1)',
                        borderWidth: 1,
                        borderRadius: 5, // Sudut bulat untuk bar
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false, // Penting agar grafik mengisi container parent
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Omset (Rp)'
                            },
                            ticks: {
                                callback: function(value, index, ticks) {
                                    return 'Rp ' + value.toLocaleString('id-ID'); // Format mata uang
                                }
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Tanggal'
                            }
                        }
                    },
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    if (context.parsed.y !== null) {
                                        label += 'Rp ' + context.parsed.y.toLocaleString('id-ID');
                                    }
                                    return label;
                                }
                            }
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>