<?php
require_once 'config.php';

if (!isLoggedIn() || !hasRole(['direktur', 'wakil_direktur', 'manager'])) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount();

$success = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'issue_warning') {
    $employee_id = (int)($_POST['employee_id'] ?? 0);
    $warning_type = $_POST['warning_type'] ?? '';
    $reason = $_POST['reason'] ?? '';

    if ($employee_id <= 0 || empty($warning_type) || empty($reason)) {
        $error = "Semua field harus diisi!";
    } else {
        $conn->begin_transaction();
        try {
            $stmt_get_employee = $conn->prepare("SELECT name, status, last_warning_level FROM employees WHERE id = ?");
            $stmt_get_employee->bind_param("i", $employee_id);
            $stmt_get_employee->execute();
            $employee_data = $stmt_get_employee->get_result()->fetch_assoc();
            $stmt_get_employee->close();

            if (!$employee_data || $employee_data['status'] === 'inactive') {
                throw new Exception("Anggota tidak ditemukan atau sudah tidak aktif.");
            }

            // Insert new warning letter
            $stmt_insert = $conn->prepare("INSERT INTO warning_letters (employee_id, issued_by_employee_id, type, reason) VALUES (?, ?, ?, ?)");
            $stmt_insert->bind_param("iiss", $employee_id, $user['id'], $warning_type, $reason);
            
            if (!$stmt_insert->execute()) {
                throw new Exception("Gagal mengeluarkan surat peringatan: " . $stmt_insert->error);
            }
            $stmt_insert->close();
            
            // Update last_warning_level on employee profile
            $stmt_update_employee = $conn->prepare("UPDATE employees SET last_warning_level = ? WHERE id = ?");
            $stmt_update_employee->bind_param("si", $warning_type, $employee_id);
            $stmt_update_employee->execute();
            $stmt_update_employee->close();

            // Special handling for PHK
            if ($warning_type === 'PHK') {
                $stmt_deactivate = $conn->prepare("UPDATE employees SET status = 'inactive' WHERE id = ?");
                $stmt_deactivate->bind_param("i", $employee_id);
                $stmt_deactivate->execute();
                $stmt_deactivate->close();
            }

            $conn->commit();
            $success = "Surat peringatan **{$warning_type}** berhasil dikeluarkan untuk **" . htmlspecialchars($employee_data['name']) . "**.";

            // Tambahkan notifikasi ke database
            addNotification(
                $employee_id,
                'warning_issued',
                "Anda telah menerima surat peringatan **{$warning_type}** dari " . htmlspecialchars($user['name']) . ". Cek riwayat peringatan Anda.",
                "my-warnings.php"
            );
            
            sendDiscordNotification([
                'employee_name' => htmlspecialchars($employee_data['name']),
                'issuer_name' => $user['name'],
                'warning_type' => $warning_type,
                'reason' => $reason
            ], 'warning_issued');

        } catch (Exception $e) {
            $conn->rollback();
            $error = "Terjadi kesalahan: " . $e->getMessage();
        }
        header("Location: warning-letters.php?msg=" . urlencode($success ?? $error) . "&type=" . urlencode(isset($success) ? 'success' : 'error'));
        exit;
    }
}

$all_active_employees = $conn->query("SELECT id, name, role, last_warning_level FROM employees WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);

if (isset($_GET['msg']) && isset($_GET['type'])) {
    $feedback_message = htmlspecialchars($_GET['msg']);
    $feedback_type = htmlspecialchars($_GET['type']);
    if ($feedback_type === 'success') {
        $success = $feedback_message;
    } else {
        $error = $feedback_message;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Surat Peringatan - Warung Om Tante</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <style>
        .warning-form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--spacing-xl);
        }
        .warning-preview {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-xl);
            padding: var(--spacing-lg);
            min-height: 200px;
        }
        .warning-preview-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--danger-color);
            margin-bottom: var(--spacing-md);
            border-bottom: 2px solid var(--danger-color);
            padding-bottom: var(--spacing-xs);
        }
        .warning-preview-text {
            color: var(--text-primary);
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
                    <span class="page-icon">📝</span>
                    Surat Peringatan
                </h1>
                <p>Kelola surat peringatan dan pemecatan untuk anggota</p>
            </div>

            <?php if (isset($success)): ?>
                <div class="success-message">🎉 <?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="error-message">❌ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h3>Keluarkan Surat Peringatan</h3>
                </div>
                <div class="card-content">
                    <form method="POST">
                        <input type="hidden" name="action" value="issue_warning">
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="employee_id">Pilih Anggota</label>
                                <select name="employee_id" id="employee_id" class="form-select" required>
                                    <option value="">-- Pilih Anggota --</option>
                                    <?php foreach ($all_active_employees as $emp): ?>
                                        <option value="<?= $emp['id'] ?>">
                                            <?= htmlspecialchars($emp['name']) ?> (<?= getRoleDisplayName($emp['role']) ?>) - Peringatan: <?= $emp['last_warning_level'] ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="warning_type">Tipe Peringatan</label>
                                <select name="warning_type" id="warning_type" class="form-select" required>
                                    <option value="">-- Pilih Tipe --</option>
                                    <option value="SP1">SP 1 (Surat Peringatan 1)</option>
                                    <option value="SP2">SP 2 (Surat Peringatan 2)</option>
                                    <option value="SP3">SP 3 (Surat Peringatan 3)</option>
                                    <option value="PHK">PHK (Pemutusan Hubungan Kerja)</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="reason">Alasan</label>
                            <textarea name="reason" id="reason" rows="5" class="form-textarea" placeholder="Jelaskan alasan pengeluaran surat peringatan..." required></textarea>
                        </div>
                        
                        <button type="submit" class="btn btn-danger">Keluarkan Surat Peringatan</button>
                    </form>
                </div>
            </div>

        </main>
    </div>

    <script src="script.js"></script>
</body>
</html>