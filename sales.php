<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();

// Ambil jumlah permohonan pending untuk indikator sidebar
$pending_requests_count = getPendingRequestCount();

$success_message = null;
$error_message = null;

// --- Handle Delete Sales Entry ---
if (($_SERVER['REQUEST_METHOD'] === 'POST') && (isset($_POST['action']) && $_POST['action'] === 'delete_sales_entry')) {
    $sales_entry_id = (int)($_POST['sales_entry_id'] ?? 0);
    $employee_id_of_entry = $user['id']; // ID karyawan yang sedang login

    if ($sales_entry_id <= 0) {
        $error_message = "ID entri penjualan tidak valid!";
    } else {
        $conn->begin_transaction();
        try {
            // Ambil detail entri sebelum dihapus untuk notifikasi
            $stmt_get_entry = $conn->prepare("
                SELECT paket_makan_minum_warga, paket_makan_minum_instansi, paket_snack, masak_paket, masak_snack, date, input_time
                FROM sales_data
                WHERE id = ? AND employee_id = ?
            ");
            if (!$stmt_get_entry) {
                throw new Exception("Gagal menyiapkan query ambil detail entri penjualan: " . $conn->error);
            }
            $stmt_get_entry->bind_param("ii", $sales_entry_id, $employee_id_of_entry);
            $stmt_get_entry->execute();
            $entry_details = $stmt_get_entry->get_result()->fetch_assoc();
            $stmt_get_entry->close();

            if (!$entry_details) {
                throw new Exception("Entri penjualan tidak ditemukan atau bukan milik Anda.");
            }

            // Hapus entri penjualan
            $stmt_delete = $conn->prepare("DELETE FROM sales_data WHERE id = ? AND employee_id = ?");
            if (!$stmt_delete) {
                throw new Exception("Gagal menyiapkan query hapus entri penjualan: " . $conn->error);
            }
            $stmt_delete->bind_param("ii", $sales_entry_id, $employee_id_of_entry);
            
            if ($stmt_delete->execute() && $stmt_delete->affected_rows > 0) {
                $conn->commit();
                $success_message = "Entri penjualan tanggal " . date('d/m/Y H:i', strtotime($entry_details['input_time'])) . " berhasil dihapus.";
                // Anda bisa menambahkan notifikasi Discord di sini jika diperlukan, seperti pada duty_log_deleted
            } else {
                throw new Exception("Gagal menghapus entri penjualan. Mungkin sudah dihapus atau tidak ada perubahan.");
            }
            $stmt_delete->close();

        } catch (Exception $e) {
            $conn->rollback();
            $error_message = "Terjadi kesalahan: " . $e->getMessage();
        }
        header("Location: sales.php?msg=" . urlencode($success_message ?? $error_message) . "&type=" . urlencode(isset($success_message) ? 'success' : 'error'));
        exit;
    }
}

// Menampilkan pesan feedback setelah redirect
if (isset($_GET['msg']) && isset($_GET['type'])) {
    $feedback_message = htmlspecialchars($_GET['msg']);
    $feedback_type = htmlspecialchars($_GET['type']);
    if ($feedback_type === 'success') {
        $success_message = $feedback_message;
    } else {
        $error_message = $feedback_message;
    }
}


// Handle form submission dengan sistem multiple input per hari
// Menggunakan 'action' di dalam $_POST, jadi pastikan nilai 'action' adalah 'update_sales'
if (($_SERVER['REQUEST_METHOD'] === 'POST') && (isset($_POST['action']) && $_POST['action'] === 'update_sales')) {
    $paket_makan_minum_warga = (int)($_POST['paket_makan_minum_warga'] ?? 0);
    $paket_makan_minum_instansi = (int)($_POST['paket_makan_minum_instansi'] ?? 0);
    $paket_snack = (int)($_POST['paket_snack'] ?? 0);
    $masak_paket = (int)($_POST['masak_paket'] ?? 0);
    $masak_snack = (int)($_POST['masak_snack'] ?? 0);
    $date_input = $_POST['date'] ?? '';
    
    // Inisialisasi $error_message untuk setiap kali submit form
    $error_message = null; 

    // Validasi minimal pembelian untuk Paket Instansi
    if ($paket_makan_minum_instansi > 0 && $paket_makan_minum_instansi < 15) {
        $error_message = "Paket Makan Minum Instansi minimal 15 paket!";
    }
    
    // Validasi input tanggal
    if (empty($date_input) && !$error_message) { 
        $error_message = "Tanggal harus diisi!";
    }

    $date_obj = null;
    $formatted_date = null;

    if (!isset($error_message)) { // Hanya proses jika belum ada error lain
        // Coba parsing tanggal dari format YYYY-MM-DD (format standar input type="date")
        $date_obj = DateTime::createFromFormat('Y-m-d', $date_input);
        $errors = DateTime::getLastErrors(); // Tangkap error/warning dari upaya pertama
        
        // Jika parsing YYYY-MM-DD gagal atau ada warning/error, coba format DD/MM/YYYY
        if (!$date_obj || $errors['warning_count'] > 0 || $errors['error_count'] > 0) {
            DateTime::getLastErrors(); // Bersihkan error sebelumnya sebelum mencoba format lain
            $date_obj = DateTime::createFromFormat('d/m/Y', $date_input);
            $errors = DateTime::getLastErrors(); // Tangkap error/warning dari upaya kedua
        }

        // Jika setelah mencoba kedua format, objek DateTime masih belum valid atau ada error/warning
        if (!$date_obj || $errors['warning_count'] > 0 || $errors['error_count'] > 0) {
            $error_message = "Format tanggal tidak valid! Harap gunakan format YYYY-MM-DD (misal: 2025-07-24) atau DD/MM/YYYY (misal: 24/07/2025) yang lengkap dan akurat.";
        }
        
        if (!$error_message) { // Lanjutkan validasi jika belum ada error format
            $formatted_date = $date_obj->format('Y-m-d'); // Format ulang ke YYYY-MM-DD untuk database

            // Validasi tanggal tidak boleh di masa depan
            $today_limit = new DateTime();
            $today_limit->setTime(23, 59, 59);

            if ($date_obj > $today_limit) {
                $error_message = "Tanggal tidak boleh di masa depan!";
            }
            
            // Validasi tanggal tidak boleh lebih dari 30 hari yang lalu
            $thirty_days_ago = new DateTime();
            $thirty_days_ago->sub(new DateInterval('P30D'));
            $thirty_days_ago->setTime(0, 0, 0);
            
            if ($date_obj < $thirty_days_ago) {
                $error_message = "Tanggal tidak boleh lebih dari 30 hari yang lalu!";
            }
        }
    }

    // Jika ada error_message yang diset di atas, redirect dan exit
    if (isset($error_message)) {
        header("Location: sales.php?msg=" . urlencode($error_message) . "&type=error");
        exit; // PENTING: Hentikan eksekusi setelah redirect error
    }

    // Jika semua validasi berhasil dan tidak ada error, lanjutkan ke proses INSERT
    $week_number = (int)$date_obj->format('W');
    $year = (int)$date_obj->format('Y');
    $input_time = date('Y-m-d H:i:s'); 
    
    try {
        // Modifikasi di sini: Menggunakan STR_TO_DATE untuk konversi eksplisit di MySQL
        $stmt = $conn->prepare("
            INSERT INTO sales_data (employee_id, paket_makan_minum_warga, paket_makan_minum_instansi, paket_snack, masak_paket, masak_snack, date, week_number, year, input_time)
            VALUES (?, ?, ?, ?, ?, ?, STR_TO_DATE(?, '%Y-%m-%d'), ?, ?, ?)
        ");
        
        $stmt->bind_param("iiiiiiisis", 
            $user['id'], 
            $paket_makan_minum_warga,
            $paket_makan_minum_instansi,
            $paket_snack, 
            $masak_paket, 
            $masak_snack, 
            $formatted_date, // Ini adalah string YYYY-MM-DD yang sudah divalidasi
            $week_number, 
            $year,
            $input_time 
        );
        
        $result = $stmt->execute();
        
        if (!$result) {
            $error_message = "Gagal menyimpan data: " . $stmt->error;
            header("Location: sales.php?msg=" . urlencode($error_message) . "&type=error");
            exit;
        } else {
            $success_message = "Data penjualan berhasil disimpan untuk tanggal " . date('d/m/Y', strtotime($formatted_date)) . " pada jam " . date('H:i', strtotime($input_time)) . "!";
            sendDiscordNotification([
                'employee_name' => $user['name'],
                'date' => $formatted_date,
                'input_time' => $input_time,
                'paket_makan_minum_warga' => $paket_makan_minum_warga,
                'paket_makan_minum_instansi' => $paket_makan_minum_instansi,
                'paket_snack' => $paket_snack,
                'masak_paket' => $masak_paket,
                'masak_snack' => $masak_snack
            ], 'sale_input');
            
            header("Location: " . $_SERVER['PHP_SELF'] . "?msg=" . urlencode($success_message) . "&type=success");
            exit;
        }
    } catch (Exception $e) {
        $error_message = "Error database: " . $e->getMessage();
        header("Location: sales.php?msg=" . urlencode($error_message) . "&type=error");
        exit;
    } finally {
        if (isset($stmt)) {
            $stmt->close(); 
        }
    }
}

// Menampilkan pesan feedback setelah redirect
if (isset($_GET['msg']) && isset($_GET['type'])) {
    $feedback_message = htmlspecialchars($_GET['msg']);
    $feedback_type = htmlspecialchars($_GET['type']);
    if ($feedback_type === 'success') {
        $success_message = $feedback_message;
    } else {
        $error_message = $feedback_message;
    }
}

// Perbaikan di sini: Ambil data mingguan dari kolom baru
// Gunakan variabel date_obj dari POST atau default ke NOW() jika halaman baru dimuat
// Agar ringkasan selalu relevan dengan input terakhir atau default hari ini.
$date_obj_for_summary = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['date'])) {
    // Jika ada input POST tanggal, gunakan tanggal tersebut untuk ringkasan
    $temp_date_input = $_POST['date'];
    $temp_date_obj_YMD = DateTime::createFromFormat('Y-m-d', $temp_date_input);
    $temp_date_obj_DMY = DateTime::createFromFormat('d/m/Y', $temp_date_input);

    if ($temp_date_obj_YMD && DateTime::getLastErrors()['warning_count'] == 0 && DateTime::getLastErrors()['error_count'] == 0) {
        $date_obj_for_summary = $temp_date_obj_YMD;
    } elseif ($temp_date_obj_DMY && DateTime::getLastErrors()['warning_count'] == 0 && DateTime::getLastErrors()['error_count'] == 0) {
        $date_obj_for_summary = $temp_date_obj_DMY;
    } else {
        $date_obj_for_summary = new DateTime(); // Fallback to current date
    }
} else {
    $date_obj_for_summary = new DateTime(); // Default to current date when page loads
}


// --- Perbaikan di sini: Query untuk Ringkasan Input Makan Minum (menyeluruh) ---
$overall_sales_summary = [
    'total_paket_makan_minum_warga' => 0,
    'total_paket_makan_minum_instansi' => 0,
    'total_paket_snack' => 0,
    'total_masak_paket' => 0,
    'total_masak_snack' => 0,
    'total_entries' => 0
]; 
$stmt = $conn->prepare("
    SELECT 
        SUM(paket_makan_minum_warga) as total_paket_makan_minum_warga,
        SUM(paket_makan_minum_instansi) as total_paket_makan_minum_instansi,
        SUM(paket_snack) as total_paket_snack,
        SUM(masak_paket) as total_masak_paket,
        SUM(masak_snack) as total_masak_snack,
        COUNT(*) as total_entries
    FROM sales_data 
    WHERE employee_id = ?
"); // Kondisi WHERE untuk tanggal dihapus untuk perhitungan menyeluruh
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$overall_sales_summary_result = $stmt->get_result()->fetch_assoc();
if ($overall_sales_summary_result) {
    $overall_sales_summary = $overall_sales_summary_result;
}
$stmt->close();


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

// Hitung total harian dari kolom baru
$daily_total = [
    'paket_makan_minum_warga' => 0,
    'paket_makan_minum_instansi' => 0,
    'paket_snack' => 0,
    'masak_paket' => 0,
    'masak_snack' => 0,
    'total_entries' => count($today_data)
];

foreach ($today_data as $entry) {
    $daily_total['paket_makan_minum_warga'] += $entry['paket_makan_minum_warga'];
    $daily_total['paket_makan_minum_instansi'] += $entry['paket_makan_minum_instansi'];
    $daily_total['paket_snack'] += $entry['paket_snack'];
    $daily_total['masak_paket'] += $entry['masak_paket'];
    $daily_total['masak_snack'] += $entry['masak_snack'];
}


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

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Penjualan - Warung Om Tante</title>
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
                    <span class="page-icon">💰</span>
                    Data Penjualan
                </h1>
                <p>Input dan kelola data penjualan harian Anda. Anda dapat menginput beberapa kali dalam sehari.</p>
            </div>

            <?php if (isset($success_message)): ?>
                <div class="success-message">🎉 <?= htmlspecialchars($success_message) ?></div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="error-message">❌ <?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>

            <?php // Pindahkan Ringkasan Minggu Ini di sini dan ubah jadi menyeluruh ?>
            <div class="card full-width" style="margin-bottom: var(--spacing-xl);">
                <div class="card-header">
                    <h3>Ringkasan Input</h3>
                    <span class="entry-count">
                        <?= $overall_sales_summary['total_paket_makan_minum_warga'] + $overall_sales_summary['total_paket_makan_minum_instansi'] + $overall_sales_summary['total_paket_snack'] + $overall_sales_summary['total_masak_paket'] + $overall_sales_summary['total_masak_snack'] ?> Total Paket
                    </span>
                </div>
                <div class="card-content">
                    <div class="stats-grid-small">
                        <div class="stat-item">
                            <span class="stat-label">P. M&M Warga</span>
                            <span class="stat-value" style="font-size: 1.2em;"><?= $overall_sales_summary['total_paket_makan_minum_warga'] ?? 0 ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">P. M&M Instansi</span>
                            <span class="stat-value" style="font-size: 1.2em;"><?= $overall_sales_summary['total_paket_makan_minum_instansi'] ?? 0 ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Paket Snack</span>
                            <span class="stat-value" style="font-size: 1.2em;"><?= $overall_sales_summary['total_paket_snack'] ?? 0 ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Masak Paket</span>
                            <span class="stat-value" style="font-size: 1.2em;"><?= $overall_sales_summary['total_masak_paket'] ?? 0 ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Masak Snack</span>
                            <span class="stat-value" style="font-size: 1.2em;"><?= $overall_sales_summary['total_masak_snack'] ?? 0 ?></span>
                        </div>
                    </div>
                </div>
            </div>


            <?php if (!empty($today_data) || $daily_total['total_entries'] > 0): ?>
            <div class="summary-card">
                <div class="summary-icon" style="color: var(--info-color);">📊</div>
                <div class="summary-content">
                    <h4>Ringkasan Hari Ini (<?= date('d/m/Y') ?>)</h4>
                    <p class="summary-value"><?= $daily_total['total_entries'] ?> Input Hari Ini</p>
                    <div class="stats-grid-small" style="margin-top: var(--spacing-md);">
                        <div class="stat-item">
                            <span class="stat-label">P. M&M Warga</span>
                            <span class="stat-value" style="font-size: 1.2em;"><?= $daily_total['paket_makan_minum_warga'] ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">P. M&M Instansi</span>
                            <span class="stat-value" style="font-size: 1.2em;"><?= $daily_total['paket_makan_minum_instansi'] ?></span>
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
                <div class="card full-width"> <?php // Ubah menjadi full-width ?>
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
                                        min="<?= date('Y-m-d', strtotime('-30 days')) ?>"
                                        onchange="formatDateInput(this)"> <small class="form-help">
                                    Pilih tanggal penjualan (maksimal 30 hari ke belakang).
                                    <br>Data akan disimpan pada jam: <strong id="preview-time"><?= date('H:i:s') ?></strong>
                                </small>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="paket_makan_minum_warga">Jumlah Paket Makan & Minum Warga</label>
                                    <input type="number" 
                                            name="paket_makan_minum_warga" 
                                            id="paket_makan_minum_warga" 
                                            value="0" 
                                            min="0" 
                                            max="999"
                                            class="form-input">
                                    <small class="form-help">Per paket Rp 25.000</small>
                                </div>
                                <div class="form-group">
                                    <label for="paket_makan_minum_instansi">Jumlah Paket Makan & Minum Instansi</label>
                                    <input type="number" 
                                            name="paket_makan_minum_instansi" 
                                            id="paket_makan_minum_instansi" 
                                            value="0" 
                                            min="0" 
                                            max="999"
                                            class="form-input">
                                    <small class="form-help">Minimal 15 paket, per paket Rp 18.000</small>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="paket_snack">Jumlah Paket Snack</label>
                                    <input type="number" 
                                            name="paket_snack" 
                                            id="paket_snack" 
                                            value="0" 
                                            min="0" 
                                            max="999"
                                            class="form-input">
                                    <small class="form-help">Per paket Rp 15.000</small>
                                </div>
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
                            </div>
                            
                            <div class="form-row">
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

                <?php // Pindahkan Riwayat Penjualan Terbaru di sini ?>
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
                                            <th>P. M&M Warga</th>
                                            <th>P. M&M Instansi</th>
                                            <th>Paket Snack</th>
                                            <th>Masak Paket</th>
                                            <th>Masak Snack</th>
                                            <th>Total Paket</th>
                                            <th>Aksi</th> </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_sales as $sale): ?>
                                        <?php 
                                            $total_paket_overall = $sale['paket_makan_minum_warga'] + $sale['paket_makan_minum_instansi'] + $sale['paket_snack'] + $sale['masak_paket'] + $sale['masak_snack'];
                                            $is_today = date('Y-m-d', strtotime($sale['date'])) === date('Y-m-d');
                                        ?>
                                        <tr class="<?= $is_today ? 'today-row' : '' ?>">
                                            <td data-label="Tanggal & Waktu">
                                                <div class="datetime-cell">
                                                    <?= date('d/m/Y H:i:s', strtotime($sale['input_time'])) ?>
                                                    <?php if ($is_today): ?>
                                                        <span class="today-badge">Hari Ini</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td data-label="P. M&M Warga"><?= $sale['paket_makan_minum_warga'] ?></td>
                                            <td data-label="P. M&M Instansi"><?= $sale['paket_makan_minum_instansi'] ?></td>
                                            <td data-label="Paket Snack"><?= $sale['paket_snack'] ?></td>
                                            <td data-label="Masak Paket"><?= $sale['masak_paket'] ?></td>
                                            <td data-label="Masak Snack"><?= $sale['masak_snack'] ?></td>
                                            <td data-label="Total Paket">
                                                <strong><?= $total_paket_overall ?></strong>
                                            </td>
                                            <td data-label="Aksi">
                                                <form method="POST" onsubmit="return confirm('Yakin ingin menghapus entri penjualan ini? Aksi ini TIDAK DAPAT DIBATALKAN.')">
                                                    <input type="hidden" name="action" value="delete_sales_entry">
                                                    <input type="hidden" name="sales_entry_id" value="<?= $sale['id'] ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                                                </form>
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

        // Fungsi untuk menormalisasi input tanggal ke YYYY-MM-DD
        function formatDateInput(inputElement) {
            try {
                const dateValue = inputElement.value;
                if (dateValue) {
                    let date = null;

                    // Coba parse sebagai YYYY-MM-DD
                    let parsedYMD = new Date(dateValue + 'T00:00:00'); // Tambahkan T00:00:00 untuk hindari masalah zona waktu
                    // Periksa apakah parsedYMD valid dan string aslinya cocok dengan format YYYY-MM-DD
                    if (!isNaN(parsedYMD.getTime()) && parsedYMD.toISOString().slice(0,10) === dateValue) {
                        date = parsedYMD;
                    }
                    
                    // Jika YYYY-MM-DD gagal, coba parse sebagai DD/MM/YYYY
                    if (!date) {
                        const parts = dateValue.split('/');
                        if (parts.length === 3) {
                            const day = parseInt(parts[0], 10);
                            const month = parseInt(parts[1], 10);
                            const year = parseInt(parts[2], 10);
                            // Periksa validitas angka dan buat objek Date (Month - 1 karena 0-indexed)
                            // Lakukan pengecekan validitas tanggal seperti 31 Feb tidak valid
                            if (day >=1 && day <=31 && month >=1 && month <=12 && year >= 1900) {
                                let tempDate = new Date(year, month - 1, day);
                                if (!isNaN(tempDate.getTime()) && tempDate.getDate() === day && (tempDate.getMonth() + 1) === month) {
                                    date = tempDate;
                                }
                            }
                        }
                    }

                    // Jika objek Date valid, format ke YYYY-MM-DD
                    if (date) {
                        const year = date.getFullYear();
                        const month = String(date.getMonth() + 1).padStart(2, '0');
                        const day = String(date.getDate()).padStart(2, '0');
                        inputElement.value = `${year}-${month}-${day}`;
                    } else {
                        // Jika tidak bisa dinormalisasi, mungkin clear input agar pengguna memasukkan ulang
                        // Atau biarkan saja untuk memicu validasi sisi server
                        // inputElement.value = ''; // Opsional: hapus nilai input yang tidak valid
                    }
                }
            } catch (e) {
                console.error("Error normalizing date input:", e);
            }
        }


        // Enhanced form validation for sales data
        function validateSalesForm() {
            const form = document.getElementById('sales-form');
            const dateInput = document.getElementById('date');
            const paketMakanMinumInstansiInput = document.getElementById('paket_makan_minum_instansi');
            
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

            // Validasi minimal pembelian Paket Makan Minum Instansi
            const instansiValue = parseInt(paketMakanMinumInstansiInput.value);
            if (instansiValue > 0 && instansiValue < 15) {
                showNotification('Paket Makan Minum Instansi minimal 15 paket!', 'error');
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
                // Pastikan input tanggal sudah dinormalisasi sebelum validasi dan submit
                // Panggil formatDateInput secara eksplisit pada saat submit
                formatDateInput(document.getElementById('date')); 

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
                // Panggil normalisasi setiap kali nilai input tanggal berubah
                formatDateInput(this); 
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
    </script>
</body>
</html>