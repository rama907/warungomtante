<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount();

$warnings = [];
$stmt = $conn->prepare("
    SELECT wl.*, e.name as issuer_name
    FROM warning_letters wl
    JOIN employees e ON wl.issuer_id = e.id
    WHERE wl.employee_id = ?
    ORDER BY wl.issued_at DESC
");
if (!$stmt) {
    die("Error preparing statement: " . $conn->error);
}
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$warnings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Surat Peringatan Saya - Warung Om Tante</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <style>
        .warning-list {
            display: flex;
            flex-direction: column;
            gap: var(--spacing-xl);
        }
        .warning-item {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-2xl);
            padding: var(--spacing-xl);
            box-shadow: var(--shadow-sm);
            position: relative;
        }
        .warning-item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--spacing-md);
            padding-bottom: var(--spacing-md);
            border-bottom: 1px solid var(--border-light);
        }
        .warning-item-header h4 {
            font-size: 1.25rem;
            font-weight: 700;
            margin: 0;
            color: var(--text-primary);
        }
        .warning-item-header .warning-badge {
            margin: 0;
        }
        .warning-reason-text {
            font-size: 0.9rem;
            line-height: 1.6;
            margin-bottom: var(--spacing-lg);
        }
        .warning-meta {
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
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
                    <span class="page-icon">❗</span>
                    Surat Peringatan Saya
                </h1>
                <p>Riwayat surat peringatan yang pernah Anda terima</p>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3>Daftar Surat Peringatan</h3>
                </div>
                <div class="card-content">
                    <?php if (empty($warnings)): ?>
                        <div class="no-data">Anda belum pernah menerima surat peringatan.</div>
                    <?php else: ?>
                        <div class="warning-list">
                            <?php foreach ($warnings as $warning): ?>
                                <div class="warning-item">
                                    <div class="warning-item-header">
                                        <h4>Surat Peringatan</h4>
                                        <span class="warning-badge badge-<?= strtolower($warning['type']) ?>">
                                            <?= $warning['type'] ?>
                                        </span>
                                    </div>
                                    <div class="warning-reason-text">
                                        <strong>Alasan:</strong> <?= htmlspecialchars($warning['reason']) ?>
                                    </div>
                                    <div class="warning-meta">
                                        <span>Dikeluarkan pada: <?= date('d/m/Y H:i', strtotime($warning['issued_at'])) ?></span>
                                        <span>Oleh: <?= htmlspecialchars($warning['issuer_name']) ?></span>
                                    </div>
                                </div>
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