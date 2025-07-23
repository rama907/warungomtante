<nav class="sidebar" id="sidebar">
    <div class="sidebar-content">
        <a href="dashboard.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : '' ?>">
            <span class="nav-icon">🏠</span>
            <span class="nav-text">Dashboard</span>
        </a>
        <a href="activities.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'activities.php' ? 'active' : '' ?>">
            <span class="nav-icon">📊</span>
            <span class="nav-text">Aktivitas Saya</span>
        </a>
        <a href="sales.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'sales.php' ? 'active' : '' ?>">
            <span class="nav-icon">💰</span>
            <span class="nav-text">Data Penjualan</span>
        </a>
        <a href="manual-duty.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'manual-duty.php' ? 'active' : '' ?>">
            <span class="nav-icon">⏱️</span>
            <span class="nav-text">Input Manual</span>
        </a>
        <a href="my-payslip.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'my-payslip.php' ? 'active' : '' ?>">
            <span class="nav-icon">📄</span>
            <span class="nav-text">Slip Gaji Saya</span>
        </a>
        
        <div class="nav-divider"></div>
        <a href="all-requests.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'all-requests.php' ? 'active' : '' ?>">
            <span class="nav-icon">📋</span>
            <span class="nav-text">Semua Permohonan</span>
        </a>
        
        <?php if (hasRole(['direktur', 'wakil_direktur', 'manager'])): ?>
        <div class="nav-divider"></div>
        <a href="employees.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'employees.php' ? 'active' : '' ?>">
            <span class="nav-icon">👥</span>
            <span class="nav-text">Daftar Anggota</span>
        </a>
        <a href="employee-activities.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'employee-activities.php' ? 'active' : '' ?>">
            <span class="nav-icon">📈</span>
            <span class="nav-text">Aktivitas Anggota</span>
        </a>
        <a href="salary-recap.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'salary-recap.php' ? 'active' : '' ?>">
            <span class="nav-icon">💸</span>
            <span class="nav-text">Rekap Gaji</span>
        </a>
        <?php endif; ?>
        
        <?php if (hasRole(['direktur', 'wakil_direktur'])): ?>
        <div class="nav-divider"></div>
        <a href="requests.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'requests.php' ? 'active' : '' ?>">
            <span class="nav-icon">📋</span>
            <span class="nav-text">Permohonan</span>
            <?php if (isset($pending_requests_count) && $pending_requests_count > 0): ?>
                <span class="pending-indicator"><?= $pending_requests_count ?></span>
            <?php endif; ?>
        </a>
        <a href="admin.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'admin.php' ? 'active' : '' ?>">
            <span class="nav-icon">⚙️</span>
            <span class="nav-text">Admin Panel</span>
        </a>
        <a href="duty-history-management.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'duty-history-management.php' ? 'active' : '' ?>">
            <span class="nav-icon">⏱️</span>
            <span class="nav-text">Manajemen Jam Kerja</span>
        </a>
        <?php endif; ?>
    </div>
</nav>