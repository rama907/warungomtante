<?php
require_once 'config.php';

if (!isLoggedIn() || !hasRole(['direktur', 'wakil_direktur'])) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser();

// Handle admin actions
if ($_POST['action'] ?? '' === 'add_employee') {
    $name = $_POST['name'] ?? '';
    $role = $_POST['role'] ?? '';
    $password_input = $_POST['password'] ?? '';
    
    // Use default password if empty, otherwise use provided password
    $password_to_hash = empty($password_input) ? 'password' : $password_input;
    $password = password_hash($password_to_hash, PASSWORD_DEFAULT);
    
    if ($name && $role) {
        $stmt = $conn->prepare("INSERT INTO employees (name, role, password) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $name, $role, $password);
        if ($stmt->execute()) {
            $success = "Anggota baru berhasil ditambahkan! Password default: 'password'";
        } else {
            $error = "Gagal menambahkan anggota baru!";
        }
    } else {
        $error = "Nama dan jabatan harus diisi!";
    }
}

if ($_POST['action'] ?? '' === 'update_role') {
    $employee_id = (int)($_POST['employee_id'] ?? 0);
    $new_role = $_POST['new_role'] ?? '';
    
    if ($employee_id && $new_role) {
        // Get employee name for feedback
        $stmt = $conn->prepare("SELECT name, role FROM employees WHERE id = ? AND status = 'active'");
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        $employee_data = $stmt->get_result()->fetch_assoc();
        
        if ($employee_data) {
            // Check if the new role is different from current role
            if ($employee_data['role'] !== $new_role) {
                $stmt = $conn->prepare("UPDATE employees SET role = ? WHERE id = ? AND status = 'active'");
                $stmt->bind_param("si", $new_role, $employee_id);
                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    $success = "Jabatan " . htmlspecialchars($employee_data['name']) . " berhasil diubah dari " . getRoleDisplayName($employee_data['role']) . " menjadi " . getRoleDisplayName($new_role) . "!";
                    sendDiscordNotification("Jabatan " . $employee_data['name'] . " telah diubah oleh " . $user['name'] . " dari " . getRoleDisplayName($employee_data['role']) . " menjadi " . getRoleDisplayName($new_role), "info");
                } else {
                    $error = "Gagal mengubah jabatan!";
                }
            } else {
                $error = "Jabatan yang dipilih sama dengan jabatan saat ini!";
            }
        } else {
            $error = "Anggota tidak ditemukan!";
        }
    } else {
        $error = "Data tidak lengkap untuk mengubah jabatan!";
    }
}

if ($_POST['action'] ?? '' === 'reset_weekly_data') {
    // Reset duty hours
    $conn->query("UPDATE employees SET total_duty_hours = 0");
    
    // Archive old sales data (optional - you might want to keep historical data)
    $conn->query("UPDATE system_settings SET setting_value = CURDATE() WHERE setting_key = 'last_weekly_reset'");
    
    $success = "Data mingguan berhasil direset!";
    sendDiscordNotification("Data mingguan telah direset oleh " . $user['name'], "info");
}

if ($_POST['action'] ?? '' === 'deactivate_employee') {
    $employee_id = (int)($_POST['employee_id'] ?? 0);
    
    if ($employee_id && $employee_id != $user['id']) {
        // Get employee name for feedback
        $stmt = $conn->prepare("SELECT name FROM employees WHERE id = ? AND status = 'active'");
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        $employee_data = $stmt->get_result()->fetch_assoc();
        
        if ($employee_data) {
            $stmt = $conn->prepare("UPDATE employees SET status = 'inactive' WHERE id = ?");
            $stmt->bind_param("i", $employee_id);
            if ($stmt->execute()) {
                $success = "Anggota " . htmlspecialchars($employee_data['name']) . " berhasil dinonaktifkan!";
                sendDiscordNotification("Anggota " . $employee_data['name'] . " telah dinonaktifkan oleh " . $user['name'], "warning");
            } else {
                $error = "Gagal menonaktifkan anggota!";
            }
        } else {
            $error = "Anggota tidak ditemukan!";
        }
    } else {
        $error = "Tidak dapat menonaktifkan diri sendiri atau ID tidak valid!";
    }
}

// Get all employees for management
$employees = $conn->query("
    SELECT * FROM employees 
    WHERE status = 'active' 
    ORDER BY 
        CASE role 
            WHEN 'direktur' THEN 1
            WHEN 'wakil_direktur' THEN 2
            WHEN 'manager' THEN 3
            WHEN 'chef' THEN 4
            WHEN 'karyawan' THEN 5
            WHEN 'magang' THEN 6
        END,
        name
")->fetch_all(MYSQLI_ASSOC);

// Get system statistics
$stats = [];
$stats['total_employees'] = $conn->query("SELECT COUNT(*) as count FROM employees WHERE status = 'active'")->fetch_assoc()['count'];
$stats['on_duty_count'] = $conn->query("SELECT COUNT(*) as count FROM employees WHERE is_on_duty = 1")->fetch_assoc()['count'];
$stats['pending_requests'] = $conn->query("
    SELECT 
        (SELECT COUNT(*) FROM leave_requests WHERE status = 'pending') +
        (SELECT COUNT(*) FROM resignation_requests WHERE status = 'pending') +
        (SELECT COUNT(*) FROM manual_duty_requests WHERE status = 'pending') as count
")->fetch_assoc()['count'];
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Warung Om Tante</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="dashboard-container">
        <?php include 'includes/header.php'; ?>
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1>
                    <span class="page-icon">⚙️</span>
                    Admin Panel
                </h1>
                <p>Kelola sistem dan anggota Warung Om Tante</p>
            </div>

            <?php if (isset($success)): ?>
                <div class="success-message"><?= $success ?></div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="error-message"><?= $error ?></div>
            <?php endif; ?>

            <!-- System Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">👥</div>
                    <div class="stat-content">
                        <h3>Total Anggota</h3>
                        <p class="stat-value"><?= $stats['total_employees'] ?></p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">🟢</div>
                    <div class="stat-content">
                        <h3>Sedang On Duty</h3>
                        <p class="stat-value"><?= $stats['on_duty_count'] ?></p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">📋</div>
                    <div class="stat-content">
                        <h3>Permohonan Pending</h3>
                        <p class="stat-value"><?= $stats['pending_requests'] ?></p>
                    </div>
                </div>
            </div>

            <div class="admin-tabs">
                <button class="tab-button active" onclick="showAdminTab('employees')">Kelola Anggota</button>
                <button class="tab-button" onclick="showAdminTab('system')">Pengaturan Sistem</button>
            </div>

            <!-- Employee Management Tab -->
            <div id="employees-admin-tab" class="tab-content active">
                <div class="content-grid">
                    <div class="card">
                        <div class="card-header">
                            <h3>Tambah Anggota Baru</h3>
                        </div>
                        <div class="card-content">
                            <form method="POST" class="admin-form">
                                <input type="hidden" name="action" value="add_employee">
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="name">Nama</label>
                                        <input type="text" name="name" id="name" class="form-input" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="role">Jabatan</label>
                                        <select name="role" id="role" class="form-select" required>
                                            <option value="">Pilih Jabatan</option>
                                            <option value="direktur">Direktur</option>
                                            <option value="wakil_direktur">Wakil Direktur</option>
                                            <option value="manager">Manager</option>
                                            <option value="chef">Chef</option>
                                            <option value="karyawan">Karyawan</option>
                                            <option value="magang">Magang</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="password">Password (Opsional)</label>
                                    <input type="password" name="password" id="password" class="form-input" 
                                           placeholder="Kosongkan untuk password default">
                                    <small class="form-help">Jika dikosongkan, password default adalah: <strong>password</strong></small>
                                </div>
                                
                                <button type="submit" class="btn btn-primary">Tambah Anggota</button>
                            </form>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h3>Daftar Anggota</h3>
                        </div>
                        <div class="card-content">
                            <div class="employees-management-list">
                                <?php foreach ($employees as $employee): ?>
                                <div class="employee-management-item">
                                    <div class="employee-info-section">
                                        <div class="employee-avatar-small">👤</div>
                                        <div class="employee-details">
                                            <h4><?= htmlspecialchars($employee['name']) ?></h4>
                                            <span class="role-badge role-<?= $employee['role'] ?>"><?= getRoleDisplayName($employee['role']) ?></span>
                                            <div class="employee-status-inline">
                                                <span class="status-indicator <?= $employee['is_on_duty'] ? 'on-duty' : 'off-duty' ?>"></span>
                                                <span><?= $employee['is_on_duty'] ? 'On Duty' : 'Off Duty' ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="employee-actions">
                                        <!-- Role Change Form -->
                                        <form method="POST" class="role-change-form" onsubmit="return confirmRoleChange('<?= htmlspecialchars($employee['name']) ?>', this)">
                                            <input type="hidden" name="action" value="update_role">
                                            <input type="hidden" name="employee_id" value="<?= $employee['id'] ?>">
                                            <div class="role-change-group">
                                                <select name="new_role" class="form-select-small" required>
                                                    <option value="">Ubah Jabatan</option>
                                                    <option value="direktur" <?= $employee['role'] == 'direktur' ? 'disabled style="color: #ccc;"' : '' ?>>
                                                        Direktur <?= $employee['role'] == 'direktur' ? '(Saat ini)' : '' ?>
                                                    </option>
                                                    <option value="wakil_direktur" <?= $employee['role'] == 'wakil_direktur' ? 'disabled style="color: #ccc;"' : '' ?>>
                                                        Wakil Direktur <?= $employee['role'] == 'wakil_direktur' ? '(Saat ini)' : '' ?>
                                                    </option>
                                                    <option value="manager" <?= $employee['role'] == 'manager' ? 'disabled style="color: #ccc;"' : '' ?>>
                                                        Manager <?= $employee['role'] == 'manager' ? '(Saat ini)' : '' ?>
                                                    </option>
                                                    <option value="chef" <?= $employee['role'] == 'chef' ? 'disabled style="color: #ccc;"' : '' ?>>
                                                        Chef <?= $employee['role'] == 'chef' ? '(Saat ini)' : '' ?>
                                                    </option>
                                                    <option value="karyawan" <?= $employee['role'] == 'karyawan' ? 'disabled style="color: #ccc;"' : '' ?>>
                                                        Karyawan <?= $employee['role'] == 'karyawan' ? '(Saat ini)' : '' ?>
                                                    </option>
                                                    <option value="magang" <?= $employee['role'] == 'magang' ? 'disabled style="color: #ccc;"' : '' ?>>
                                                        Magang <?= $employee['role'] == 'magang' ? '(Saat ini)' : '' ?>
                                                    </option>
                                                </select>
                                                <button type="submit" class="btn btn-primary btn-sm">Ubah</button>
                                            </div>
                                        </form>
                                        
                                        <!-- Deactivate Form -->
                                        <?php if ($employee['id'] != $user['id']): ?>
                                        <form method="POST" class="deactivate-form" onsubmit="return confirm('Yakin ingin menonaktifkan <?= htmlspecialchars($employee['name']) ?>?\n\nAnggota yang dinonaktifkan tidak dapat login lagi.')">
                                            <input type="hidden" name="action" value="deactivate_employee">
                                            <input type="hidden" name="employee_id" value="<?= $employee['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">Nonaktifkan</button>
                                        </form>
                                        <?php else: ?>
                                        <div class="self-indicator">
                                            <small class="text-muted">Anda sendiri</small>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- System Settings Tab -->
            <div id="system-admin-tab" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Pengaturan Sistem</h3>
                    </div>
                    <div class="card-content">
                        <div class="system-actions">
                            <div class="action-item">
                                <div class="action-info">
                                    <h4>Reset Data Mingguan</h4>
                                    <p>Reset total jam duty dan data penjualan semua anggota untuk minggu baru</p>
                                </div>
                                <form method="POST" onsubmit="return confirm('Yakin ingin mereset semua data mingguan? Tindakan ini tidak dapat dibatalkan!')">
                                    <input type="hidden" name="action" value="reset_weekly_data">
                                    <button type="submit" class="btn btn-warning">Reset Data Mingguan</button>
                                </form>
                            </div>
                            
                            <div class="action-item">
                                <div class="action-info">
                                    <h4>Backup Database</h4>
                                    <p>Buat backup database sistem (fitur akan segera tersedia)</p>
                                </div>
                                <button class="btn btn-secondary" disabled>Backup Database</button>
                            </div>
                            
                            <div class="action-item">
                                <div class="action-info">
                                    <h4>Pengaturan Discord</h4>
                                    <p>Konfigurasi integrasi dengan Discord bot (fitur akan segera tersedia)</p>
                                </div>
                                <button class="btn btn-secondary" disabled>Konfigurasi Discord</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="script.js"></script>
    <script>
        function showAdminTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabName + '-admin-tab').classList.add('active');
            
            // Add active class to clicked button
            event.target.classList.add('active');
        }
        
        function confirmRoleChange(employeeName, form) {
            const newRole = form.querySelector('select[name="new_role"]').value;
            const roleNames = {
                'direktur': 'Direktur',
                'wakil_direktur': 'Wakil Direktur', 
                'manager': 'Manager',
                'chef': 'Chef',
                'karyawan': 'Karyawan',
                'magang': 'Magang'
            };
            
            if (!newRole) {
                alert('Pilih jabatan baru terlebih dahulu!');
                return false;
            }
            
            return confirm(`Yakin ingin mengubah jabatan ${employeeName} menjadi ${roleNames[newRole]}?`);
        }
        
        // Prevent double submission and show loading state
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const submitBtn = this.querySelector('button[type="submit"]');
                    if (submitBtn && !submitBtn.disabled) {
                        // Disable button to prevent double submission
                        submitBtn.disabled = true;
                        
                        // Show loading state
                        const originalText = submitBtn.textContent;
                        submitBtn.innerHTML = '<span class="spinner"></span> Memproses...';
                        
                        // Re-enable after 5 seconds as fallback
                        setTimeout(() => {
                            submitBtn.disabled = false;
                            submitBtn.textContent = originalText;
                        }, 5000);
                    }
                });
            });
        });
    </script>
</body>
</html>
