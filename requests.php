<?php
require_once 'config.php';

if (!isLoggedIn() || !hasRole(['direktur', 'wakil_direktur'])) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser();

// Handle approval/rejection actions with better error handling and logging
if ($_POST['action'] ?? '' === 'approve_leave') {
    $request_id = (int)($_POST['request_id'] ?? 0);
    if ($request_id > 0) {
        // First check if request exists and is pending
        $stmt = $conn->prepare("SELECT lr.*, e.name as employee_name FROM leave_requests lr JOIN employees e ON lr.employee_id = e.id WHERE lr.id = ? AND lr.status = 'pending'");
        $stmt->bind_param("i", $request_id);
        $stmt->execute();
        $request_data = $stmt->get_result()->fetch_assoc();
        
        if ($request_data) {
            $stmt = $conn->prepare("UPDATE leave_requests SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ? AND status = 'pending'");
            $stmt->bind_param("ii", $user['id'], $request_id);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $success = "Permohonan cuti dari " . htmlspecialchars($request_data['employee_name']) . " berhasil disetujui!";
                sendDiscordNotification("Permohonan cuti " . $request_data['employee_name'] . " telah disetujui oleh " . $user['name'], "success");
            } else {
                $error = "Gagal menyetujui permohonan cuti! Mungkin sudah diproses sebelumnya.";
            }
        } else {
            $error = "Permohonan cuti tidak ditemukan atau sudah diproses!";
        }
    } else {
        $error = "ID permohonan tidak valid!";
    }
}

if ($_POST['action'] ?? '' === 'reject_leave') {
    $request_id = (int)($_POST['request_id'] ?? 0);
    if ($request_id > 0) {
        // First check if request exists and is pending
        $stmt = $conn->prepare("SELECT lr.*, e.name as employee_name FROM leave_requests lr JOIN employees e ON lr.employee_id = e.id WHERE lr.id = ? AND lr.status = 'pending'");
        $stmt->bind_param("i", $request_id);
        $stmt->execute();
        $request_data = $stmt->get_result()->fetch_assoc();
        
        if ($request_data) {
            $stmt = $conn->prepare("UPDATE leave_requests SET status = 'rejected', approved_by = ?, approved_at = NOW() WHERE id = ? AND status = 'pending'");
            $stmt->bind_param("ii", $user['id'], $request_id);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $success = "Permohonan cuti dari " . htmlspecialchars($request_data['employee_name']) . " berhasil ditolak!";
                sendDiscordNotification("Permohonan cuti " . $request_data['employee_name'] . " telah ditolak oleh " . $user['name'], "warning");
            } else {
                $error = "Gagal menolak permohonan cuti! Mungkin sudah diproses sebelumnya.";
            }
        } else {
            $error = "Permohonan cuti tidak ditemukan atau sudah diproses!";
        }
    } else {
        $error = "ID permohonan tidak valid!";
    }
}

if ($_POST['action'] ?? '' === 'approve_resignation') {
    $request_id = (int)($_POST['request_id'] ?? 0);
    if ($request_id > 0) {
        // First check if request exists and is pending
        $stmt = $conn->prepare("SELECT rr.*, e.name as employee_name FROM resignation_requests rr JOIN employees e ON rr.employee_id = e.id WHERE rr.id = ? AND rr.status = 'pending'");
        $stmt->bind_param("i", $request_id);
        $stmt->execute();
        $request_data = $stmt->get_result()->fetch_assoc();
        
        if ($request_data) {
            $stmt = $conn->prepare("UPDATE resignation_requests SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ? AND status = 'pending'");
            $stmt->bind_param("ii", $user['id'], $request_id);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $success = "Permohonan resign dari " . htmlspecialchars($request_data['employee_name']) . " berhasil disetujui!";
                sendDiscordNotification("Permohonan resign " . $request_data['employee_name'] . " telah disetujui oleh " . $user['name'], "success");
            } else {
                $error = "Gagal menyetujui permohonan resign! Mungkin sudah diproses sebelumnya.";
            }
        } else {
            $error = "Permohonan resign tidak ditemukan atau sudah diproses!";
        }
    } else {
        $error = "ID permohonan tidak valid!";
    }
}

if ($_POST['action'] ?? '' === 'reject_resignation') {
    $request_id = (int)($_POST['request_id'] ?? 0);
    if ($request_id > 0) {
        // First check if request exists and is pending
        $stmt = $conn->prepare("SELECT rr.*, e.name as employee_name FROM resignation_requests rr JOIN employees e ON rr.employee_id = e.id WHERE rr.id = ? AND rr.status = 'pending'");
        $stmt->bind_param("i", $request_id);
        $stmt->execute();
        $request_data = $stmt->get_result()->fetch_assoc();
        
        if ($request_data) {
            $stmt = $conn->prepare("UPDATE resignation_requests SET status = 'rejected', approved_by = ?, approved_at = NOW() WHERE id = ? AND status = 'pending'");
            $stmt->bind_param("ii", $user['id'], $request_id);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $success = "Permohonan resign dari " . htmlspecialchars($request_data['employee_name']) . " berhasil ditolak!";
                sendDiscordNotification("Permohonan resign " . $request_data['employee_name'] . " telah ditolak oleh " . $user['name'], "warning");
            } else {
                $error = "Gagal menolak permohonan resign! Mungkin sudah diproses sebelumnya.";
            }
        } else {
            $error = "Permohonan resign tidak ditemukan atau sudah diproses!";
        }
    } else {
        $error = "ID permohonan tidak valid!";
    }
}

if ($_POST['action'] ?? '' === 'approve_manual_duty') {
    $request_id = (int)($_POST['request_id'] ?? 0);
    
    if ($request_id > 0) {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Get the manual duty request details with employee name
            $stmt = $conn->prepare("SELECT mdr.*, e.name as employee_name FROM manual_duty_requests mdr JOIN employees e ON mdr.employee_id = e.id WHERE mdr.id = ? AND mdr.status = 'pending'");
            $stmt->bind_param("i", $request_id);
            $stmt->execute();
            $manual_request = $stmt->get_result()->fetch_assoc();
            
            if ($manual_request) {
                // Calculate duration with overnight shift support
                $start_datetime = $manual_request['duty_date'] . ' ' . $manual_request['start_time'];
                $end_datetime = $manual_request['duty_date'] . ' ' . $manual_request['end_time'];
                
                $start_timestamp = strtotime($start_datetime);
                $end_timestamp = strtotime($end_datetime);
                
                // Handle overnight shift
                if ($end_timestamp <= $start_timestamp) {
                    $end_timestamp += 24 * 60 * 60; // Add 24 hours
                    $end_datetime = date('Y-m-d H:i:s', $end_timestamp);
                }
                
                $duration_minutes = ($end_timestamp - $start_timestamp) / 60;
                
                // Insert into duty_logs
                $stmt = $conn->prepare("
                    INSERT INTO duty_logs (employee_id, duty_start, duty_end, duration_minutes, is_manual, approved_by, status)
                    VALUES (?, ?, ?, ?, 1, ?, 'completed')
                ");
                $stmt->bind_param("issii", $manual_request['employee_id'], $start_datetime, $end_datetime, $duration_minutes, $user['id']);
                
                if ($stmt->execute()) {
                    // Update manual duty request status
                    $stmt = $conn->prepare("UPDATE manual_duty_requests SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ? AND status = 'pending'");
                    $stmt->bind_param("ii", $user['id'], $request_id);
                    
                    if ($stmt->execute() && $stmt->affected_rows > 0) {
                        // Commit transaction
                        $conn->commit();
                        
                        $duration_display = formatDuration($duration_minutes);
                        $success = "Permohonan input jam manual dari " . htmlspecialchars($manual_request['employee_name']) . " berhasil disetujui! Durasi: " . $duration_display;
                        sendDiscordNotification("Input jam manual " . $manual_request['employee_name'] . " telah disetujui oleh " . $user['name'] . " (Durasi: " . $duration_display . ")", "success");
                    } else {
                        throw new Exception("Gagal mengupdate status permohonan");
                    }
                } else {
                    throw new Exception("Gagal menambahkan ke duty logs");
                }
            } else {
                throw new Exception("Permohonan tidak ditemukan atau sudah diproses");
            }
        } catch (Exception $e) {
            // Rollback transaction
            $conn->rollback();
            $error = "Gagal menyetujui permohonan input jam manual: " . $e->getMessage();
        }
    } else {
        $error = "ID permohonan tidak valid!";
    }
}

if ($_POST['action'] ?? '' === 'reject_manual_duty') {
    $request_id = (int)($_POST['request_id'] ?? 0);
    if ($request_id > 0) {
        // First check if request exists and is pending
        $stmt = $conn->prepare("SELECT mdr.*, e.name as employee_name FROM manual_duty_requests mdr JOIN employees e ON mdr.employee_id = e.id WHERE mdr.id = ? AND mdr.status = 'pending'");
        $stmt->bind_param("i", $request_id);
        $stmt->execute();
        $request_data = $stmt->get_result()->fetch_assoc();
        
        if ($request_data) {
            // Only update the request status, do NOT insert into duty_logs
            $stmt = $conn->prepare("UPDATE manual_duty_requests SET status = 'rejected', approved_by = ?, approved_at = NOW() WHERE id = ? AND status = 'pending'");
            $stmt->bind_param("ii", $user['id'], $request_id);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $success = "Permohonan input jam manual dari " . htmlspecialchars($request_data['employee_name']) . " berhasil ditolak!";
                sendDiscordNotification("Input jam manual " . $request_data['employee_name'] . " telah ditolak oleh " . $user['name'], "warning");
            } else {
                $error = "Gagal menolak permohonan input jam manual! Mungkin sudah diproses sebelumnya.";
            }
        } else {
            $error = "Permohonan input jam manual tidak ditemukan atau sudah diproses!";
        }
    } else {
        $error = "ID permohonan tidak valid!";
    }
}

// Get pending leave requests
$leave_requests = $conn->query("
    SELECT lr.*, e.name as employee_name, e.role as employee_role
    FROM leave_requests lr
    JOIN employees e ON lr.employee_id = e.id
    WHERE lr.status = 'pending'
    ORDER BY lr.created_at ASC
")->fetch_all(MYSQLI_ASSOC);

// Get pending resignation requests
$resignation_requests = $conn->query("
    SELECT rr.*, e.name as employee_name, e.role as employee_role
    FROM resignation_requests rr
    JOIN employees e ON rr.employee_id = e.id
    WHERE rr.status = 'pending'
    ORDER BY rr.created_at ASC
")->fetch_all(MYSQLI_ASSOC);

// Get pending manual duty requests
$manual_duty_requests = $conn->query("
    SELECT mdr.*, e.name as employee_name, e.role as employee_role
    FROM manual_duty_requests mdr
    JOIN employees e ON mdr.employee_id = e.id
    WHERE mdr.status = 'pending'
    ORDER BY mdr.created_at ASC
")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permohonan - Warung Om Tante</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="dashboard-container">
        <?php include 'includes/header.php'; ?>
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1>
                    <span class="page-icon">📋</span>
                    Permohonan
                </h1>
                <p>Kelola semua permohonan dari anggota</p>
            </div>

            <?php if (isset($success)): ?>
                <div class="success-message"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="error-message"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="requests-tabs">
                <button class="tab-button active" onclick="showTab('leave')">
                    Cuti (<?= count($leave_requests) ?>)
                </button>
                <button class="tab-button" onclick="showTab('resignation')">
                    Resign (<?= count($resignation_requests) ?>)
                </button>
                <button class="tab-button" onclick="showTab('manual')">
                    Input Manual (<?= count($manual_duty_requests) ?>)
                </button>
            </div>

            <!-- Leave Requests Tab -->
            <div id="leave-tab" class="tab-content active">
                <div class="card">
                    <div class="card-header">
                        <h3>Permohonan Cuti</h3>
                    </div>
                    <div class="card-content">
                        <?php if (empty($leave_requests)): ?>
                            <div class="no-data">Tidak ada permohonan cuti yang pending</div>
                        <?php else: ?>
                            <?php foreach ($leave_requests as $request): ?>
                            <div class="request-item">
                                <div class="request-header">
                                    <div class="employee-info">
                                        <h4><?= htmlspecialchars($request['employee_name']) ?></h4>
                                        <span class="role-badge role-<?= $request['employee_role'] ?>"><?= getRoleDisplayName($request['employee_role']) ?></span>
                                    </div>
                                    <div class="request-dates">
                                        <?= date('d/m/Y', strtotime($request['start_date'])) ?> - 
                                        <?= date('d/m/Y', strtotime($request['end_date'])) ?>
                                    </div>
                                </div>
                                
                                <?php if ($request['reason_ooc']): ?>
                                <div class="request-reason">
                                    <strong>Alasan OOC:</strong> <?= htmlspecialchars($request['reason_ooc']) ?>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($request['reason_ic']): ?>
                                <div class="request-reason">
                                    <strong>Alasan IC:</strong> <?= htmlspecialchars($request['reason_ic']) ?>
                                </div>
                                <?php endif; ?>
                                
                                <div class="request-meta">
                                    Diajukan: <?= date('d/m/Y H:i', strtotime($request['created_at'])) ?>
                                </div>
                                
                                <div class="request-actions">
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Yakin ingin menyetujui permohonan cuti ini?')">
                                        <input type="hidden" name="action" value="approve_leave">
                                        <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                        <button type="submit" class="btn btn-success btn-sm">Setujui</button>
                                    </form>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Yakin ingin menolak permohonan cuti ini?')">
                                        <input type="hidden" name="action" value="reject_leave">
                                        <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Tolak</button>
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Resignation Requests Tab -->
            <div id="resignation-tab" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Permohonan Resign</h3>
                    </div>
                    <div class="card-content">
                        <?php if (empty($resignation_requests)): ?>
                            <div class="no-data">Tidak ada permohonan resign yang pending</div>
                        <?php else: ?>
                            <?php foreach ($resignation_requests as $request): ?>
                            <div class="request-item">
                                <div class="request-header">
                                    <div class="employee-info">
                                        <h4><?= htmlspecialchars($request['employee_name']) ?></h4>
                                        <span class="role-badge role-<?= $request['employee_role'] ?>"><?= getRoleDisplayName($request['employee_role']) ?></span>
                                    </div>
                                    <div class="request-dates">
                                        Tanggal Resign: <?= date('d/m/Y', strtotime($request['start_date'])) ?>
                                    </div>
                                </div>
                                
                                <div class="request-details">
                                    <div><strong>Passport:</strong> <?= htmlspecialchars($request['passport']) ?></div>
                                    <div><strong>CID:</strong> <?= htmlspecialchars($request['cid']) ?></div>
                                </div>
                                
                                <?php if ($request['reason_ooc']): ?>
                                <div class="request-reason">
                                    <strong>Alasan OOC:</strong> <?= htmlspecialchars($request['reason_ooc']) ?>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($request['reason_ic']): ?>
                                <div class="request-reason">
                                    <strong>Alasan IC:</strong> <?= htmlspecialchars($request['reason_ic']) ?>
                                </div>
                                <?php endif; ?>
                                
                                <div class="request-meta">
                                    Diajukan: <?= date('d/m/Y H:i', strtotime($request['created_at'])) ?>
                                </div>
                                
                                <div class="request-actions">
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Yakin ingin menyetujui permohonan resign ini?')">
                                        <input type="hidden" name="action" value="approve_resignation">
                                        <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                        <button type="submit" class="btn btn-success btn-sm">Setujui</button>
                                    </form>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Yakin ingin menolak permohonan resign ini?')">
                                        <input type="hidden" name="action" value="reject_resignation">
                                        <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Tolak</button>
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Manual Duty Requests Tab -->
            <div id="manual-tab" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Permohonan Input Jam Manual</h3>
                    </div>
                    <div class="card-content">
                        <?php if (empty($manual_duty_requests)): ?>
                            <div class="no-data">Tidak ada permohonan input jam manual yang pending</div>
                        <?php else: ?>
                            <?php foreach ($manual_duty_requests as $request): ?>
                            <?php
                            // Calculate duration for display
                            $start_timestamp = strtotime($request['start_time']);
                            $end_timestamp = strtotime($request['end_time']);
                            if ($end_timestamp <= $start_timestamp) {
                                $end_timestamp += 24 * 60 * 60;
                            }
                            $duration_minutes = ($end_timestamp - $start_timestamp) / 60;
                            $duration_display = formatDuration($duration_minutes);
                            $is_overnight = $end_timestamp > strtotime($request['start_time']) + 12 * 60 * 60;
                            ?>
                            <div class="request-item">
                                <div class="request-header">
                                    <div class="employee-info">
                                        <h4><?= htmlspecialchars($request['employee_name']) ?></h4>
                                        <span class="role-badge role-<?= $request['employee_role'] ?>"><?= getRoleDisplayName($request['employee_role']) ?></span>
                                    </div>
                                    <div class="request-dates">
                                        <?= date('d/m/Y', strtotime($request['duty_date'])) ?>
                                        (<?= date('H:i', strtotime($request['start_time'])) ?> - <?= date('H:i', strtotime($request['end_time'])) ?>)
                                        <br><small><strong>Durasi:</strong> <?= $duration_display ?><?= $is_overnight ? ' (shift malam)' : '' ?></small>
                                    </div>
                                </div>
                                
                                <div class="request-reason">
                                    <strong>Alasan:</strong> <?= htmlspecialchars($request['reason']) ?>
                                </div>
                                
                                <div class="request-meta">
                                    Diajukan: <?= date('d/m/Y H:i', strtotime($request['created_at'])) ?>
                                </div>
                                
                                <div class="request-actions">
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Yakin ingin menyetujui dan menambahkan jam duty ini?\nDurasi: <?= $duration_display ?><?= $is_overnight ? ' (shift malam)' : '' ?>')">
                                        <input type="hidden" name="action" value="approve_manual_duty">
                                        <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                        <button type="submit" class="btn btn-success btn-sm">Setujui</button>
                                    </form>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Yakin ingin menolak permohonan input jam manual ini?')">
                                        <input type="hidden" name="action" value="reject_manual_duty">
                                        <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Tolak</button>
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="script.js"></script>
    <script>
        function showTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Add active class to clicked button
            event.target.classList.add('active');
        }
        
        // Prevent double submission and show loading state
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const submitBtn = this.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        // Disable button to prevent double submission
                        submitBtn.disabled = true;
                        
                        // Show loading state
                        const originalText = submitBtn.textContent;
                        submitBtn.innerHTML = '<span class="spinner"></span> Memproses...';
                        
                        // Re-enable after 3 seconds as fallback
                        setTimeout(() => {
                            submitBtn.disabled = false;
                            submitBtn.textContent = originalText;
                        }, 3000);
                    }
                });
            });
            
            // Auto-refresh page after successful action (optional)
            const successMessage = document.querySelector('.success-message');
            if (successMessage) {
                setTimeout(() => {
                    // Fade out success message
                    successMessage.style.opacity = '0.5';
                }, 3000);
            }
        });
    </script>
</body>
</html>
