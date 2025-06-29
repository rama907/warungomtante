<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();

// Ambil jumlah permohonan pending untuk indikator sidebar
$pending_requests_count = getPendingRequestCount();

// Handle form submission
if ($_POST['action'] ?? '' === 'submit_manual_duty') {
    $duty_date = $_POST['duty_date'] ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    $reason = $_POST['reason'] ?? '';
    
    if ($duty_date && $start_time && $end_time && $reason) {
        // Validate time logic
        $start_timestamp = strtotime($start_time);
        $end_timestamp = strtotime($end_time);
        
        // Calculate duration considering overnight shifts
        if ($end_timestamp <= $start_timestamp) {
            // Overnight shift - add 24 hours to end time
            $end_timestamp += 24 * 60 * 60;
        }
        
        $duration_minutes = ($end_timestamp - $start_timestamp) / 60;
        
        // Validate reasonable duration (max 24 hours)
        if ($duration_minutes > 1440) { // 24 hours = 1440 minutes
            $error = "Durasi kerja tidak boleh lebih dari 24 jam!";
        } elseif ($duration_minutes < 15) { // minimum 15 minutes
            $error = "Durasi kerja minimal 15 menit!";
        } else {
            $stmt = $conn->prepare("
                INSERT INTO manual_duty_requests (employee_id, duty_date, start_time, end_time, reason)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("issss", $user['id'], $duty_date, $start_time, $end_time, $reason);
            
            if ($stmt->execute()) {
                $duration_hours = floor($duration_minutes / 60);
                $duration_mins = $duration_minutes % 60;
                $duration_text = $duration_hours . "j " . $duration_mins . "m";
                
                sendDiscordNotification([
                'employee_name' => $user['name'],
                'duty_date' => $duty_date,
                'start_time' => $start_time,
                'end_time' => $end_time,
                'duration_text' => $duration_text,
                'reason' => $reason
            ], 'manual_duty_request_submitted');
            $success = "Permohonan input jam manual berhasil diajukan! Durasi: " . $duration_text;
            } else {
                $error = "Gagal mengajukan permohonan input jam manual!";
            }
        }
    } else {
        $error = "Semua field harus diisi!";
    }
}

// Get user's manual duty requests
$stmt = $conn->prepare("
    SELECT mdr.*, e.name as approved_by_name 
    FROM manual_duty_requests mdr
    LEFT JOIN employees e ON mdr.approved_by = e.id
    WHERE mdr.employee_id = ?
    ORDER BY mdr.created_at DESC
");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$manual_requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Input Jam Manual - Warung Om Tante</title>
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
                    Input Jam Manual
                </h1>
                <p>Ajukan permohonan input jam kerja manual</p>
            </div>

            <?php if (isset($success)): ?>
                <div class="success-message"><?= $success ?></div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="error-message"><?= $error ?></div>
            <?php endif; ?>

            <div class="content-grid">
                <div class="card">
                    <div class="card-header">
                        <h3>Form Input Jam Manual</h3>
                    </div>
                    <div class="card-content">
                        <div class="info-message">
                            <strong>Info:</strong> Input jam manual memerlukan persetujuan dari Direktur atau Wakil Direktur.
                            <br><strong>Catatan:</strong> Untuk shift malam (misal: 20:00 - 02:00), sistem akan otomatis menghitung durasi dengan benar.
                        </div>
                        
                        <form method="POST" class="manual-duty-form" id="manual-duty-form">
                            <input type="hidden" name="action" value="submit_manual_duty">
                            
                            <div class="form-group">
                                <label for="duty_date">Tanggal</label>
                                <input type="date" name="duty_date" id="duty_date" class="form-input" required>
                                <small class="form-help">Pilih tanggal mulai shift</small>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="start_time">Jam Mulai</label>
                                    <input type="time" name="start_time" id="start_time" class="form-input" required>
                                </div>
                                <div class="form-group">
                                    <label for="end_time">Jam Selesai</label>
                                    <input type="time" name="end_time" id="end_time" class="form-input" required>
                                </div>
                            </div>
                            
                            <div class="duration-preview" id="duration-preview" style="display: none;">
                                <div class="info-message">
                                    <strong>Durasi Kerja:</strong> <span id="duration-text">-</span>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="reason">Alasan</label>
                                <textarea name="reason" id="reason" rows="4" class="form-textarea" 
                                          placeholder="Jelaskan alasan mengapa perlu input jam manual..." required></textarea>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">Ajukan Permohonan</button>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3>Riwayat Permohonan Input Manual</h3>
                    </div>
                    <div class="card-content">
                        <?php if (empty($manual_requests)): ?>
                            <div class="no-data">Belum ada permohonan input manual</div>
                        <?php else: ?>
                            <div class="requests-list">
                                <?php foreach ($manual_requests as $request): ?>
                                <?php
                                // Calculate duration for display
                                $start_timestamp = strtotime($request['start_time']);
                                $end_timestamp = strtotime($request['end_time']);
                                if ($end_timestamp <= $start_timestamp) {
                                    $end_timestamp += 24 * 60 * 60;
                                }
                                $duration_minutes = ($end_timestamp - $start_timestamp) / 60;
                                $duration_display = formatDuration($duration_minutes);
                                ?>
                                <div class="request-item">
                                    <div class="request-header">
                                        <div class="request-dates">
                                            <?= date('d/m/Y', strtotime($request['duty_date'])) ?>
                                            (<?= date('H:i', strtotime($request['start_time'])) ?> - <?= date('H:i', strtotime($request['end_time'])) ?>)
                                            <br><small>Durasi: <?= $duration_display ?></small>
                                        </div>
                                        <span class="status-badge status-<?= $request['status'] ?>">
                                            <?php 
                                            $status_text = [
                                                'pending' => 'Pending',
                                                'approved' => 'Disetujui', 
                                                'rejected' => 'Ditolak'
                                            ];
                                            echo $status_text[$request['status']] ?? ucfirst($request['status']);
                                            ?>
                                        </span>
                                    </div>
                                    
                                    <div class="request-reason">
                                        <strong>Alasan:</strong> <?= htmlspecialchars($request['reason']) ?>
                                    </div>
                                    
                                    <div class="request-meta">
                                        <span>Diajukan: <?= date('d/m/Y H:i', strtotime($request['created_at'])) ?></span>
                                        <?php if ($request['approved_by_name'] && $request['status'] == 'approved'): ?>
                                            <span>Disetujui oleh: <?= htmlspecialchars($request['approved_by_name']) ?></span>
                                        <?php elseif ($request['approved_by_name'] && $request['status'] == 'rejected'): ?>
                                            <span>Ditolak oleh: <?= htmlspecialchars($request['approved_by_name']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="script.js"></script>
    <script>
        // Real-time duration calculation
        function calculateDuration() {
            const startTime = document.getElementById('start_time').value;
            const endTime = document.getElementById('end_time').value;
            const preview = document.getElementById('duration-preview');
            const durationText = document.getElementById('duration-text');
            
            if (startTime && endTime) {
                const start = new Date('2000-01-01 ' + startTime);
                let end = new Date('2000-01-01 ' + endTime);
                
                // Handle overnight shift
                if (end <= start) {
                    end.setDate(end.getDate() + 1);
                }
                
                const diffMs = end - start;
                const diffMinutes = Math.floor(diffMs / (1000 * 60));
                
                if (diffMinutes > 1440) { // More than 24 hours
                    durationText.textContent = 'Durasi terlalu panjang (max 24 jam)';
                    durationText.style.color = 'var(--danger-color)';
                } else if (diffMinutes < 15) { // Less than 15 minutes
                    durationText.textContent = 'Durasi terlalu pendek (min 15 menit)';
                    durationText.style.color = 'var(--danger-color)';
                } else {
                    const hours = Math.floor(diffMinutes / 60);
                    const minutes = diffMinutes % 60;
                    durationText.textContent = hours + 'j ' + minutes + 'm';
                    durationText.style.color = 'var(--success-color)';
                    
                    // Show overnight indicator
                    if (end.getDate() > start.getDate()) {
                        durationText.textContent += ' (shift malam)';
                    }
                }
                
                preview.style.display = 'block';
            } else {
                preview.style.display = 'none';
            }
        }
        
        // Add event listeners
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('start_time').addEventListener('change', calculateDuration);
            document.getElementById('end_time').addEventListener('change', calculateDuration);
        });
    </script>
</body>
</html>
