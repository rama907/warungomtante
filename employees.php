<?php
require_once 'config.php';

if (!isLoggedIn() || !hasRole(['direktur', 'wakil_direktur', 'manager'])) {
    header('Location: dashboard.php');
    exit;
}

// Ambil jumlah permohonan pending untuk indikator sidebar
$pending_requests_count = getPendingRequestCount();

// Get all employees
$stmt = $conn->query("
    SELECT e.*, 
           CASE WHEN e.is_on_duty THEN 'On Duty' ELSE 'Off Duty' END as duty_status
    FROM employees e 
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
$employees = $stmt->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Anggota - Warung Om Tante</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="dashboard-container">
        <?php include 'includes/header.php'; ?>
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1>
                    <span class="page-icon">👥</span>
                    Daftar Anggota
                </h1>
                <p>Daftar semua anggota Warung Om Tante</p>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3>Anggota Aktif</h3>
                    <span class="member-count"><?= count($employees) ?> Anggota</span>
                </div>
                <div class="card-content">
                    <div class="employees-grid">
                        <?php foreach ($employees as $employee): ?>
                        <div class="employee-card">
                            <div class="employee-avatar">
                                <span class="avatar-icon">👤</span>
                            </div>
                            <div class="employee-info">
                                <h4 class="employee-name"><?= htmlspecialchars($employee['name']) ?></h4>
                                <p class="employee-role"><?= getRoleDisplayName($employee['role']) ?></p>
                                <div class="employee-status">
                                    <span class="status-indicator <?= $employee['is_on_duty'] ? 'on-duty' : 'off-duty' ?>"></span>
                                    <span class="status-text"><?= $employee['duty_status'] ?></span>
                                </div>
                                <?php if ($employee['is_on_duty'] && $employee['current_duty_start']): ?>
                                    <div class="duty-timer" data-start-time="<?= $employee['current_duty_start'] ?>">
                                        00:00:00
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="script.js"></script>
    <script>
        // Update duty timers for all employees
        function updateAllDutyTimers() {
            const timers = document.querySelectorAll('.duty-timer');
            timers.forEach(timer => {
                const startTime = timer.dataset.startTime;
                if (startTime) {
                    const start = new Date(startTime);
                    const now = new Date();
                    const diff = now - start;
                    
                    const hours = Math.floor(diff / (1000 * 60 * 60));
                    const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                    const seconds = Math.floor((diff % (1000 * 60)) / 1000);
                    
                    timer.textContent = `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                }
            });
        }
        
        setInterval(updateAllDutyTimers, 1000);
    </script>
</body>
</html>
