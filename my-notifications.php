<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount();

// Ambil semua notifikasi untuk pengguna ini
$notifications = [];
$stmt = $conn->prepare("SELECT * FROM notifications WHERE employee_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Tandai semua notifikasi sebagai sudah dibaca
$stmt_update = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE employee_id = ? AND is_read = FALSE");
$stmt_update->bind_param("i", $user['id']);
$stmt_update->execute();
$stmt_update->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifikasi Saya - Warung Om Tante</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <style>
        .notifications-list {
            display: flex;
            flex-direction: column;
            gap: var(--spacing-md);
        }
        .notification-item {
            display: flex;
            align-items: center;
            gap: var(--spacing-md);
            padding: var(--spacing-md);
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            transition: all 0.2s ease;
        }
        .notification-item.unread {
            background-color: var(--primary-light);
            border-left: 4px solid var(--primary-color);
        }
        .notification-item-icon {
            font-size: 1.5rem;
        }
        .notification-item-content {
            flex-grow: 1;
        }
        .notification-item-content p {
            margin: 0;
            font-size: 0.9rem;
        }
        .notification-item-content small {
            color: var(--text-muted);
            font-size: 0.75rem;
        }
        .no-notifications {
            text-align: center;
            padding: var(--spacing-xl);
            color: var(--text-secondary);
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
                    <span class="page-icon">🔔</span>
                    Notifikasi Saya
                </h1>
                <p>Semua pemberitahuan dan pembaruan penting untuk akun Anda.</p>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3>Daftar Notifikasi</h3>
                </div>
                <div class="card-content">
                    <?php if (empty($notifications)): ?>
                        <div class="no-notifications">Tidak ada notifikasi baru.</div>
                    <?php else: ?>
                        <div class="notifications-list">
                            <?php foreach ($notifications as $notification): ?>
                                <?php
                                    $icon = 'ℹ️';
                                    $class = '';
                                    if (strpos($notification['type'], 'approved') !== false) {
                                        $icon = '✅';
                                        $class = 'success';
                                    } elseif (strpos($notification['type'], 'rejected') !== false || strpos($notification['type'], 'phk') !== false) {
                                        $icon = '❌';
                                        $class = 'danger';
                                    } elseif (strpos($notification['type'], 'warning') !== false) {
                                        $icon = '❗';
                                        $class = 'warning';
                                    }
                                ?>
                                <a href="<?= htmlspecialchars($notification['link'] ?? '#') ?>" class="notification-item <?= $notification['is_read'] ? '' : 'unread' ?>">
                                    <span class="notification-item-icon"><?= $icon ?></span>
                                    <div class="notification-item-content">
                                        <p><?= htmlspecialchars($notification['message']) ?></p>
                                        <small><?= date('d M Y H:i', strtotime($notification['created_at'])) ?></small>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script src="script.js"></script>
</body>
</html>