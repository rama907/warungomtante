<?php
require_once 'config.php';

if (!isLoggedIn() || !hasRole(['direktur', 'wakil_direktur'])) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount();

$success = null;
$error = null;
$selected_employee_id = null;
$employee_duty_logs = [];
$selected_employee_name = 'Pilih Anggota';

// Handle delete duty log
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_duty_log') {
    $duty_log_id = (int)($_POST['duty_log_id'] ?? 0);
    $employee_id_of_log = (int)($_POST['employee_id_of_log'] ?? 0); // ID anggota pemilik log

    if ($duty_log_id <= 0 || $employee_id_of_log <= 0) {
        $error = "ID log duty tidak valid!";
    } else {
        $conn->begin_transaction();
        try {
            // Ambil detail log sebelum dihapus untuk notifikasi
            $stmt_get_log = $conn->prepare("
                SELECT dl.*, e.name as employee_name
                FROM duty_logs dl
                JOIN employees e ON dl.employee_id = e.id
                WHERE dl.id = ? AND dl.employee_id = ?
            ");
            if (!$stmt_get_log) {
                throw new Exception("Gagal menyiapkan query ambil detail log: " . $conn->error);
            }
            $stmt_get_log->bind_param("ii", $duty_log_id, $employee_id_of_log);
            $stmt_get_log->execute();
            $log_details = $stmt_get_log->get_result()->fetch_assoc();
            $stmt_get_log->close();

            if (!$log_details) {
                throw new Exception("Log duty tidak ditemukan atau bukan milik anggota tersebut.");
            }

            // Hapus log duty
            $stmt_delete = $conn->prepare("DELETE FROM duty_logs WHERE id = ? AND employee_id = ?");
            if (!$stmt_delete) {
                throw new Exception("Gagal menyiapkan query hapus log duty: " . $conn->error);
            }
            $stmt_delete->bind_param("ii", $duty_log_id, $employee_id_of_log);
            
            if ($stmt_delete->execute() && $stmt_delete->affected_rows > 0) {
                $conn->commit();
                $success = "Log jam kerja pada tanggal " . date('d/m/Y H:i', strtotime($log_details['duty_start'])) . " untuk **" . htmlspecialchars($log_details['employee_name']) . "** berhasil dihapus. Saran: Informasikan anggota untuk menginput ulang jam kerja ini dengan `Input Manual` jika diperlukan.";

                // Kirim notifikasi Discord
                sendDiscordNotification([
                    'employee_name' => $log_details['employee_name'],
                    'admin_name' => $user['name'],
                    'duty_start' => $log_details['duty_start'],
                    'duty_end' => $log_details['duty_end'],
                    'duration_minutes' => $log_details['duration_minutes']
                ], 'duty_log_deleted');

            } else {
                throw new Exception("Gagal menghapus log duty. Mungkin sudah dihapus atau tidak ada perubahan.");
            }
            $stmt_delete->close();

        } catch (Exception $e) {
            $conn->rollback();
            $error = "Terjadi kesalahan: " . $e->getMessage();
        }
    }
}

// Get all active employees for the dropdown
$all_employees = $conn->query("SELECT id, name, role FROM employees WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// Handle employee selection
if (isset($_GET['employee_id']) && !empty($_GET['employee_id'])) {
    $selected_employee_id = (int)$_GET['employee_id'];

    // Get name of selected employee
    foreach ($all_employees as $emp) {
        if ($emp['id'] === $selected_employee_id) {
            $selected_employee_name = htmlspecialchars($emp['name']);
            break;
        }
    }

    // Fetch duty logs for the selected employee
    $stmt_logs = $conn->prepare("
        SELECT dl.*, a.name as approved_by_name
        FROM duty_logs dl
        LEFT JOIN employees a ON dl.approved_by = a.id
        WHERE dl.employee_id = ?
        ORDER BY dl.duty_start DESC
    ");
    if ($stmt_logs) {
        $stmt_logs->bind_param("i", $selected_employee_id);
        $stmt_logs->execute();
        $employee_duty_logs = $stmt_logs->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_logs->close();
    } else {
        $error = "Gagal mengambil log duty: " . $conn->error;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Riwayat Jam Kerja - Warung Om Tante</title>
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
                    <span class="page-icon">⏱️</span>
                    Manajemen Riwayat Jam Kerja
                </h1>
                <p>Lihat dan kelola riwayat jam kerja (duty log) masing-masing anggota</p>
            </div>

            <?php if (isset($success)): ?>
                <div class="success-message"><?= $success ?></div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="error-message"><?= $error ?></div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h3>Pilih Anggota</h3>
                </div>
                <div class="card-content">
                    <form method="GET" class="form-group">
                        <label for="employee_select">Pilih Anggota:</label>
                        <select name="employee_id" id="employee_select" class="form-select" onchange="this.form.submit()">
                            <option value="">-- Pilih Anggota --</option>
                            <?php foreach ($all_employees as $emp): ?>
                                <option value="<?= $emp['id'] ?>" <?= ($selected_employee_id === $emp['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($emp['name']) ?> (<?= getRoleDisplayName($emp['role']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            </div>

            <?php if ($selected_employee_id): ?>
                <div class="card" style="margin-top: var(--spacing-xl);">
                    <div class="card-header">
                        <h3>Riwayat Jam Kerja: <?= $selected_employee_name ?></h3>
                    </div>
                    <div class="card-content">
                        <?php if (empty($employee_duty_logs)): ?>
                            <div class="no-data">Belum ada riwayat jam kerja untuk anggota ini.</div>
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
                                            <th>Disetujui Oleh</th>
                                            <th>Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($employee_duty_logs as $log): ?>
                                        <tr>
                                            <td data-label="Tanggal"><?= date('d/m/Y', strtotime($log['duty_start'])) ?></td>
                                            <td data-label="Mulai"><?= date('H:i', strtotime($log['duty_start'])) ?></td>
                                            <td data-label="Selesai"><?= $log['duty_end'] ? date('H:i', strtotime($log['duty_end'])) : 'Berlangsung' ?></td>
                                            <td data-label="Durasi"><?= $log['duty_end'] ? formatDuration($log['duration_minutes']) : 'Berlangsung' ?></td>
                                            <td data-label="Tipe">
                                                <span class="status-badge status-<?= $log['is_manual'] ? 'warning' : 'info' ?>">
                                                    <?= $log['is_manual'] ? 'Manual' : 'Otomatis' ?>
                                                </span>
                                            </td>
                                            <td data-label="Status">
                                                <span class="status-badge status-<?= $log['status'] ?>">
                                                    <?= ucfirst($log['status']) ?>
                                                </span>
                                            </td>
                                            <td data-label="Disetujui Oleh">
                                                <?= $log['approved_by_name'] ? htmlspecialchars($log['approved_by_name']) : '-' ?>
                                            </td>
                                            <td data-label="Aksi">
                                                <form method="POST" onsubmit="return confirm('Yakin ingin menghapus log jam kerja ini? Aksi ini TIDAK DAPAT DIBATALKAN dan notifikasi akan dikirim ke Discord.')">
                                                    <input type="hidden" name="action" value="delete_duty_log">
                                                    <input type="hidden" name="duty_log_id" value="<?= $log['id'] ?>">
                                                    <input type="hidden" name="employee_id_of_log" value="<?= $selected_employee_id ?>">
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
            <?php endif; ?>
        </main>
    </div>

    <script src="script.js"></script>
</body>
</html>