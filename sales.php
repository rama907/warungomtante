<?php
require_once 'config.php'; // Pastikan file config.php sudah ada dan berisi fungsi isLoggedIn(), getCurrentUser(), dan koneksi $conn

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();

// Ambil jumlah permohonan pending untuk indikator sidebar
$pending_requests_count = getPendingRequestCount();

// Inisialisasi variabel untuk pesan feedback
$success = null;
$error = null;

// Handle form submission dengan sistem multiple input per hari
// Menggunakan 'action' di dalam $_POST, jadi pastikan nilai 'action' adalah 'update_sales'
if (($_POST['action'] ?? '') === 'update_sales') {
    $paket_makan_minum = (int)($_POST['paket_makan_minum'] ?? 0);
    $paket_snack = (int)($_POST['paket_snack'] ?? 0);
    $masak_paket = (int)($_POST['masak_paket'] ?? 0);
    $masak_snack = (int)($_POST['masak_snack'] ?? 0);
    $date_input = $_POST['date'] ?? '';
    
    // Debug: Catat data mentah yang diterima
    error_log("Raw date input: " . $date_input);
    error_log("POST data: " . print_r($_POST, true)); // Menampilkan semua data POST untuk debugging

    // Validasi input tanggal
    if (empty($date_input)) {
        $error = "Tanggal harus diisi!";
        error_log("Error: Tanggal input kosong.");
    } else {
        $formatted_date = null;
        $date_obj = null;

        // **Prioritas utama: Coba format YYYY-MM-DD (dari input type="date" HTML)**
        $date_obj = DateTime::createFromFormat('Y-m-d', $date_input);
        
        // Jika gagal, coba format DD/MM/YYYY (jika user input manual atau dari sumber lain)
        if (!$date_obj) {
            $date_obj = DateTime::createFromFormat('d/m/Y', $date_input);
        }

        // Jika date_obj masih null setelah mencoba semua format, maka input tanggal tidak valid
        if (!$date_obj) {
            $error = "Format tanggal tidak valid! Gunakan format DD/MM/YYYY atau YYYY-MM-DD.";
            error_log("Error: Gagal mem-parsing tanggal dari format yang dikenal. Input: '$date_input'");
        } else {
            // Periksa apakah ada warning atau error saat parsing (misal: '2023-02-30')
            $errors_check = DateTime::getLastErrors();
            if ($errors_check['warning_count'] > 0 || $errors_check['error_count'] > 0) {
                $error = "Tanggal tidak valid! Terjadi kesalahan saat parsing tanggal.";
                error_log("Warning/Error saat parsing tanggal: " . json_encode($errors_check) . ". Input: '$date_input'");
            } else {
                $formatted_date = $date_obj->format('Y-m-d'); // Format ulang ke YYYY-MM-DD untuk database
                error_log("Tanggal berhasil diformat: $formatted_date");

                // Validasi tanggal tidak boleh di masa depan
                $today_limit = new DateTime();
                $today_limit->setTime(23, 59, 59); // Set sampai akhir hari ini

                if ($date_obj > $today_limit) {
                    $error = "Tanggal tidak boleh di masa depan!";
                    error_log("Error: Tanggal ($formatted_date) di masa depan.");
                } else {
                    // Validasi tanggal tidak boleh lebih dari 30 hari yang lalu
                    $thirty_days_ago = new DateTime();
                    $thirty_days_ago->sub(new DateInterval('P30D'));
                    $thirty_days_ago->setTime(0, 0, 0); // Set ke awal hari 30 hari yang lalu
                    
                    if ($date_obj < $thirty_days_ago) {
                        $error = "Tanggal tidak boleh lebih dari 30 hari yang lalu!";
                        error_log("Error: Tanggal ($formatted_date) lebih dari 30 hari yang lalu.");
                    } else {
                        // Semua validasi tanggal berhasil
                        $week_number = (int)$date_obj->format('W');
                        $year = (int)$date_obj->format('Y');
                        
                        // Gunakan waktu saat ini untuk input_time (timestamp saat data disimpan)
                        $input_time = date('Y-m-d H:i:s'); 
                        
                        try {
                            // Selalu INSERT data baru (sesuai kebutuhan "multiple input per hari")
                            $stmt = $conn->prepare("
                                INSERT INTO sales_data (employee_id, paket_makan_minum, paket_snack, masak_paket, masak_snack, date, week_number, year, input_time)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            
                            // Bind parameter dengan tipe yang tepat
                            $stmt->bind_param("iiiississ", 
                                $user['id'], 
                                $paket_makan_minum, 
                                $paket_snack, 
                                $masak_paket, 
                                $masak_snack, 
                                $formatted_date, 
                                $week_number, 
                                $year,
                                $input_time 
                            );
                            
                            error_log("Percobaan INSERT data: employee_id={$user['id']}, date=$formatted_date, input_time=$input_time, week=$week_number, year=$year");
                            
                            $result = $stmt->execute();
                            
                            if (!$result) {
                                error_log("Kesalahan INSERT data: " . $stmt->error);
                                error_log("SQL State: " . $stmt->sqlstate);
                                $error = "Gagal menyimpan data: " . $stmt->error;
                            } else {
                                error_log("Berhasil menyimpan record untuk tanggal: $formatted_date pada jam: $input_time");
                                $success = "Data penjualan berhasil disimpan untuk tanggal " . date('d/m/Y', strtotime($formatted_date)) . " pada jam " . date('H:i', strtotime($input_time)) . "!";
                                sendDiscordNotification([
                                    'employee_name' => $user['name'],
                                    'date' => $formatted_date,
                                    'input_time' => $input_time,
                                    'paket_makan_minum' => $paket_makan_minum,
                                    'paket_snack' => $paket_snack,
                                    'masak_paket' => $masak_paket,
                                    'masak_snack' => $masak_snack
                                ], 'sale_input');
                                
                                header("Location: " . $_SERVER['PHP_SELF'] . "?saved=1");
                                exit;
                            }
                        } catch (Exception $e) {
                            error_log("Exception Database: " . $e->getMessage());
                            $error = "Error database: " . $e->getMessage();
                        } finally {
                            if (isset($stmt)) {
                                $stmt->close(); 
                            }
                        }
                    }
                }
            }
        }
    }
}

if (isset($_GET['saved']) && $_GET['saved'] == '1') {
    $success = "Data penjualan berhasil disimpan!";
}

$today = date('Y-m-d');
$today_data = []; 
$stmt = $conn->prepare("
    SELECT *, TIME(input_time) as input_hour 
    FROM sales_data 
    WHERE employee_id = ? AND date = ? 
    ORDER BY input_time DESC
");
$stmt->bind_param("is", $user['id'], $today);
$stmt->execute();
$today_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$weekly_total = [
    'total_paket_makan_minum' => 0,
    'total_paket_snack' => 0,
    'total_masak_paket' => 0,
    'total_masak_snack' => 0,
    'total_entries' => 0
]; 
$stmt = $conn->prepare("
    SELECT 
        SUM(paket_makan_minum) as total_paket_makan_minum,
        SUM(paket_snack) as total_paket_snack,
        SUM(masak_paket) as total_masak_paket,
        SUM(masak_snack) as total_masak_snack,
        COUNT(*) as total_entries
    FROM sales_data 
    WHERE employee_id = ? AND WEEK(date, 1) = WEEK(NOW(), 1) AND YEAR(date) = YEAR(NOW())
");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$weekly_total_result = $stmt->get_result()->fetch_assoc();
if ($weekly_total_result) {
    $weekly_total = $weekly_total_result;
}
$stmt->close();

$recent_sales = []; 
$stmt = $conn->prepare("
    SELECT *, TIME(input_time) as input_hour 
    FROM sales_data 
    WHERE employee_id = ? 
    ORDER BY input_time DESC 
    LIMIT 20
");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$recent_sales = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$daily_total = [
    'paket_makan_minum' => 0,
    'paket_snack' => 0,
    'masak_paket' => 0,
    'masak_snack' => 0,
    'total_entries' => count($today_data)
];

foreach ($today_data as $entry) {
    $daily_total['paket_makan_minum'] += $entry['paket_makan_minum'];
    $daily_total['paket_snack'] += $entry['paket_snack'];
    $daily_total['masak_paket'] += $entry['masak_paket'];
    $daily_total['masak_snack'] += $entry['masak_snack'];
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Penjualan - Warung Om Tante</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="dashboard-container">
        <?php include 'includes/header.php'; ?>
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1>
                    <span class="page-icon">💰</span>
                    Data Penjualan
                </h1>
                <p>Input dan kelola data penjualan harian Anda. Anda dapat menginput beberapa kali dalam sehari.</p>
            </div>

            <?php if (isset($success)): ?>
                <div class="success-message">🎉 <?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="error-message">❌ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if (!empty($today_data) || $daily_total['total_entries'] > 0): ?>
            <div class="summary-card">
                <div class="summary-icon" style="color: var(--info-color);">📊</div>
                <div class="summary-content">
                    <h4>Ringkasan Hari Ini (<?= date('d/m/Y') ?>)</h4>
                    <p class="summary-value"><?= $daily_total['total_entries'] ?> Input Hari Ini</p>
                    <div class="stats-grid-small" style="margin-top: var(--spacing-md);">
                        <div class="stat-item">
                            <span class="stat-label">Paket M&M</span>
                            <span class="stat-value" style="font-size: 1.2em;"><?= $daily_total['paket_makan_minum'] ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Paket Snack</span>
                            <span class="stat-value" style="font-size: 1.2em;"><?= $daily_total['paket_snack'] ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Masak Paket</span>
                            <span class="stat-value" style="font-size: 1.2em;"><?= $daily_total['masak_paket'] ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Masak Snack</span>
                            <span class="stat-value" style="font-size: 1.2em;"><?= $daily_total['masak_snack'] ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="content-grid">
                <div class="card">
                    <div class="card-header">
                        <h3>Input Data Penjualan</h3>
                        <div class="current-time">
                            <span class="time-icon">🕐</span>
                            <span id="current-time"><?= date('H:i:s') ?></span>
                        </div>
                    </div>
                    <div class="card-content">
                        <div class="info-message">
                            <strong>💡 Info:</strong> Anda bisa memasukkan data penjualan beberapa kali. Setiap entri akan dicatat dengan waktu saat ini.
                        </div>
                        
                        <form method="POST" class="sales-form" id="sales-form">
                            <input type="hidden" name="action" value="update_sales">
                            
                            <div class="form-group"> <label for="date">Tanggal Penjualan</label>
                                <input type="date" 
                                        name="date" 
                                        id="date" 
                                        value="<?= htmlspecialchars(date('Y-m-d')) ?>" 
                                        class="form-input" 
                                        required
                                        max="<?= date('Y-m-d') ?>"
                                        min="<?= date('Y-m-d', strtotime('-30 days')) ?>">
                                <small class="form-help">
                                    Pilih tanggal penjualan (maksimal 30 hari ke belakang).
                                    <br>Data akan disimpan pada jam: <strong id="preview-time"><?= date('H:i:s') ?></strong>
                                </small>
                            </div>
                            
                            <div class="form-row"> <div class="form-group">
                                    <label for="paket_makan_minum">Jumlah Paket Makan & Minum</label>
                                    <input type="number" 
                                            name="paket_makan_minum" 
                                            id="paket_makan_minum" 
                                            value="0" 
                                            min="0" 
                                            max="999"
                                            class="form-input">
                                </div>
                                <div class="form-group">
                                    <label for="paket_snack">Jumlah Paket Snack</label>
                                    <input type="number" 
                                            name="paket_snack" 
                                            id="paket_snack" 
                                            value="0" 
                                            min="0" 
                                            max="999"
                                            class="form-input">
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="masak_paket">Jumlah Masak Paket</label>
                                    <input type="number" 
                                            name="masak_paket" 
                                            id="masak_paket" 
                                            value="0" 
                                            min="0" 
                                            max="999"
                                            class="form-input">
                                </div>
                                <div class="form-group">
                                    <label for="masak_snack">Jumlah Masak Snack</label>
                                    <input type="number" 
                                            name="masak_snack" 
                                            id="masak_snack" 
                                            value="0" 
                                            min="0" 
                                            max="999"
                                            class="form-input">
                                </div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="button" class="btn btn-secondary" onclick="resetForm()">
                                    <span class="btn-icon">🔄</span>
                                    Reset Form
                                </button>
                                <button type="submit" class="btn btn-primary" id="submit-btn">
                                    <span class="btn-icon">💾</span>
                                    Simpan Data (<?= date('H:i') ?>)
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3>Ringkasan Minggu Ini</h3>
                        <span class="entry-count"><?= $weekly_total['total_entries'] ?? 0 ?> Total Input</span>
                    </div>
                    <div class="card-content">
                        <div class="stats-grid-small">
                            <div class="stat-item">
                                <span class="stat-label">Paket M&M</span>
                                <span class="stat-value" style="font-size: 1.2em;"><?= $weekly_total['total_paket_makan_minum'] ?? 0 ?></span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-label">Paket Snack</span>
                                <span class="stat-value" style="font-size: 1.2em;"><?= $weekly_total['total_paket_snack'] ?? 0 ?></span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-label">Masak Paket</span>
                                <span class="stat-value" style="font-size: 1.2em;"><?= $weekly_total['total_masak_paket'] ?? 0 ?></span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-label">Masak Snack</span>
                                <span class="stat-value" style="font-size: 1.2em;"><?= $weekly_total['total_masak_snack'] ?? 0 ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($today_data)): ?>
                <div class="card">
                    <div class="card-header">
                        <h3>Detail Input Hari Ini (<?= date('d/m/Y') ?>)</h3>
                        <span class="entry-count"><?= count($today_data) ?> Input</span>
                    </div>
                    <div class="card-content">
                        <div class="today-entries-list">
                            <?php foreach ($today_data as $entry): ?>
                            <div class="activity-item"> <div class="activity-date">
                                    <span class="time-icon">🕐</span>
                                    <span class="time-text"><?= date('H:i:s', strtotime($entry['input_time'])) ?></span>
                                </div>
                                <div class="activity-content">
                                    <p class="activity-description">
                                        Paket M&M: <strong><?= $entry['paket_makan_minum'] ?></strong>, 
                                        Snack: <strong><?= $entry['paket_snack'] ?></strong>, 
                                        Masak P: <strong><?= $entry['masak_paket'] ?></strong>, 
                                        Masak S: <strong><?= $entry['masak_snack'] ?></strong>
                                    </p>
                                    <div class="activity-details" style="display: flex; justify-content: flex-end;">
                                        <strong>Total: <?= $entry['paket_makan_minum'] + $entry['paket_snack'] + $entry['masak_paket'] + $entry['masak_snack'] ?></strong>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="card full-width">
                    <div class="card-header">
                        <h3>Riwayat Penjualan Terbaru</h3>
                        <span class="entry-count">20 Terakhir</span>
                    </div>
                    <div class="card-content">
                        <?php if (empty($recent_sales)): ?>
                            <div class="no-data">Belum ada data penjualan. Silakan input data pertama Anda!</div>
                        <?php else: ?>
                            <div class="responsive-table-container">
                                <table class="activities-table-improved"> <thead>
                                        <tr>
                                            <th>Tanggal & Waktu</th>
                                            <th>Paket M&M</th>
                                            <th>Paket Snack</th>
                                            <th>Masak Paket</th>
                                            <th>Masak Snack</th>
                                            <th>Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_sales as $sale): ?>
                                        <?php 
                                            $total = $sale['paket_makan_minum'] + $sale['paket_snack'] + $sale['masak_paket'] + $sale['masak_snack'];
                                            $is_today = date('Y-m-d', strtotime($sale['date'])) === date('Y-m-d');
                                        ?>
                                        <tr class="<?= $is_today ? 'today-row' : '' ?>">
                                            <td data-label="Tanggal & Waktu">
                                                <div class="datetime-cell">
                                                    <span class="date-part"><?= date('d/m/Y', strtotime($sale['date'])) ?></span>
                                                    <span class="time-part"><?= date('H:i:s', strtotime($sale['input_time'])) ?></span>
                                                    <?php if ($is_today): ?>
                                                        <span class="today-badge">Hari Ini</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td data-label="Paket M&M"><?= $sale['paket_makan_minum'] ?></td>
                                            <td data-label="Paket Snack"><?= $sale['paket_snack'] ?></td>
                                            <td data-label="Masak Paket"><?= $sale['masak_paket'] ?></td>
                                            <td data-label="Masak Snack"><?= $sale['masak_snack'] ?></td>
                                            <td data-label="Total">
                                                <strong><?= $total ?></strong>
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
        // Update current time display
        function updateCurrentTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('id-ID', { 
                hour12: false,
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
            
            const currentTimeElement = document.getElementById('current-time');
            const previewTimeElement = document.getElementById('preview-time');
            const submitBtn = document.getElementById('submit-btn');
            
            if (currentTimeElement) {
                currentTimeElement.textContent = timeString;
            }
            
            if (previewTimeElement) {
                previewTimeElement.textContent = timeString;
            }
            
            if (submitBtn) {
                const shortTime = now.toLocaleTimeString('id-ID', { 
                    hour12: false,
                    hour: '2-digit',
                    minute: '2-digit'
                });
                submitBtn.innerHTML = `<span class="btn-icon">💾</span> Simpan Data (${shortTime})`;
            }
        }

        // Enhanced form validation for sales data
        function validateSalesForm() {
            const form = document.getElementById('sales-form');
            const dateInput = document.getElementById('date');
            
            if (!dateInput.value) {
                showNotification('Tanggal harus diisi!', 'error');
                return false;
            }

            const selectedDate = new Date(dateInput.value);
            const today = new Date();
            today.setHours(23, 59, 59, 999); 
            
            if (selectedDate > today) {
                showNotification('Tanggal tidak boleh di masa depan!', 'error');
                return false;
            }
            
            const thirtyDaysAgo = new Date();
            thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 30);
            thirtyDaysAgo.setHours(0, 0, 0, 0); 
            
            if (selectedDate < thirtyDaysAgo) {
                showNotification('Tanggal tidak boleh lebih dari 30 hari yang lalu!', 'error');
                return false;
            }
            
            return true;
        }
        
        function resetForm() {
            const form = document.getElementById('sales-form');
            const today = new Date().toISOString().split('T')[0];
            
            document.getElementById('date').value = today;
            
            const numberInputs = form.querySelectorAll('input[type="number"]');
            numberInputs.forEach(input => {
                input.value = 0;
            });
            
            showNotification('Form telah direset!', 'info');
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('sales-form');
            
            updateCurrentTime();
            setInterval(updateCurrentTime, 1000);
            
            form.addEventListener('submit', function(e) {
                if (!validateSalesForm()) {
                    e.preventDefault();
                    return false;
                }
                
                const submitBtn = document.getElementById('submit-btn');
                // showLoading(submitBtn); // Aktifkan jika Anda ingin efek loading

                const now = new Date();
                const timeString = now.toLocaleTimeString('id-ID', { 
                    hour12: false,
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit'
                });
                
                if (!confirm(`Yakin ingin menyimpan data penjualan pada jam ${timeString}?`)) {
                    e.preventDefault();
                    // hideLoading(submitBtn); // Sembunyikan loading jika dibatalkan
                    return false;
                }
            });
            
            const dateInput = document.getElementById('date');
            dateInput.addEventListener('change', function() {
                if (this.value && validateSalesForm()) {
                    console.log('Date changed to:', this.value);
                }
            });
            
            // Add animation to today's entries (using the new activity-item class)
            const todayEntries = document.querySelectorAll('.activity-item');
            todayEntries.forEach((entry, index) => {
                entry.style.animationDelay = `${index * 0.1}s`;
                entry.classList.add('fade-in');
            });
        });

        setInterval(() => {
            updateCurrentTime();
        }, 1000);

        // showMultipleEntriesInfo() (commented out by default)
        // setTimeout(showMultipleEntriesInfo, 2000);
    </script>
</body>
</html>