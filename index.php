<?php
require_once 'config.php';

// Handle login
if ($_POST['action'] ?? '' === 'login') {
    $name = $_POST['name'] ?? '';
    $password = $_POST['password'] ?? '';
    
    $stmt = $conn->prepare("SELECT * FROM employees WHERE name = ? AND status = 'active'");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['name'] = $user['name'];
        $_SESSION['role'] = $user['role'];
        header('Location: dashboard.php');
        exit;
    } else {
        $error = "Nama atau password salah!";
    }
}

// Get all employees for dropdown
$employees = $conn->query("SELECT name, role FROM employees WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Warung Om Tante Management System</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="login-body">
    <div class="login-container">
        <!-- Background Elements -->
        <div class="login-background">
            <div class="bg-shape shape-1"></div>
            <div class="bg-shape shape-2"></div>
            <div class="bg-shape shape-3"></div>
        </div>

        <!-- Header -->
        <header class="login-header">
            <div class="login-header-content">
                <div class="logo-large">
                    <div class="logo-icon-large">🍽️</div>
                    <div class="logo-text-large">
                        <h1>Warung Om Tante</h1>
                        <p>Management System</p>
                    </div>
                </div>
                <button class="theme-toggle-login" onclick="toggleTheme()" aria-label="Toggle Theme">
                    <span class="theme-icon">🌙</span>
                </button>
            </div>
        </header>

        <!-- Main Content -->
        <main class="login-main">
            <div class="login-card">
                <div class="login-card-header">
                    <div class="login-icon">
                        <span>👤</span>
                    </div>
                    <h2>Portal Login Karyawan</h2>
                    <p>Masuk ke sistem manajemen Warung Om Tante</p>
                </div>
                
                <form method="POST" class="login-form" id="loginForm">
                    <input type="hidden" name="action" value="login">
                    
                    <div class="form-group-login">
                        <label for="name" class="form-label-login">
                            <span class="label-icon">👤</span>
                            Nama Anggota
                        </label>
                        <div class="select-wrapper">
                            <select name="name" id="name" required class="form-select-login">
                                <option value="">Pilih Nama Anda</option>
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?= htmlspecialchars($emp['name']) ?>" 
                                            <?= (isset($_POST['name']) && $_POST['name'] === $emp['name']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($emp['name']) ?> 
                                        <span class="role-text">(<?= getRoleDisplayName($emp['role']) ?>)</span>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="select-arrow">▼</span>
                        </div>
                    </div>
                    
                    <div class="form-group-login">
                        <label for="password" class="form-label-login">
                            <span class="label-icon">🔒</span>
                            Password
                        </label>
                        <div class="password-wrapper">
                            <input type="password" name="password" id="password" required class="form-input-login" placeholder="Masukkan password Anda">
                            <button type="button" class="password-toggle" onclick="togglePassword()" aria-label="Show Password" tabindex="-1">
                                <span id="passwordToggleIcon">👁️</span>
                            </button>
                        </div>
                    </div>
                    
                    <?php if (isset($error)): ?>
                        <div class="error-message-login">
                            <span class="error-icon">⚠️</span>
                            <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>
                    
                    <button type="submit" class="btn-login" id="loginButton">
                        <span class="btn-icon">🚀</span>
                        <span class="btn-text">Masuk ke Sistem</span>
                        <div class="btn-loading" style="display: none;">
                            <div class="spinner-login"></div>
                            Memproses...
                        </div>
                    </button>
                </form>
                
                <div class="login-info">
                    <div class="info-header">
                        <span class="info-icon">💡</span>
                        <strong>Informasi Login</strong>
                    </div>
                    <div class="info-content">
                        <div class="info-item">
                            <span class="info-bullet">•</span>
                            Password default untuk semua akun: <code>password</code>
                        </div>
                        <div class="info-item">
                            <span class="info-bullet">•</span>
                            Hubungi admin untuk reset password
                        </div>
                        <div class="info-item">
                            <span class="info-bullet">•</span>
                            Pastikan nama yang dipilih sesuai dengan akun Anda
                        </div>
                    </div>
                </div>
            </div>

            <!-- Features Preview -->
            <div class="features-preview">
                <h3>Fitur Sistem</h3>
                <div class="features-grid">
                    <div class="feature-item">
                        <span class="feature-icon">⏰</span>
                        <span class="feature-text">Manajemen Jam Kerja</span>
                    </div>
                    <div class="feature-item">
                        <span class="feature-icon">💰</span>
                        <span class="feature-text">Data Penjualan</span>
                    </div>
                    <div class="feature-item">
                        <span class="feature-icon">📋</span>
                        <span class="feature-text">Permohonan Cuti</span>
                    </div>
                    <div class="feature-item">
                        <span class="feature-icon">📊</span>
                        <span class="feature-text">Laporan Aktivitas</span>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <footer class="login-footer">
            <p>&copy; 2024 Warung Om Tante Management System. All rights reserved.</p>
        </footer>
    </div>

    <script src="script.js"></script>
</body>
</html>
