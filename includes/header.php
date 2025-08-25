<?php
// Ambil jumlah notifikasi yang belum dibaca
$unread_notifications_count = 0;
if (isset($_SESSION['user_id'])) {
    $unread_notifications_count = getUnreadNotificationCount($_SESSION['user_id']);
}
?>
<header class="header">
    <div class="header-content">
        <div class="header-left">
            <button class="sidebar-toggle" id="sidebar-toggle" aria-label="Toggle Menu">
                <span class="hamburger-icon">☰</span>
            </button>
            <div class="logo">
                <span class="logo-icon">🍽️</span>
                <span class="logo-text">Warung Om Tante</span>
            </div>
        </div>
        <div class="header-actions">
            <a href="my-notifications.php" class="notification-bell" aria-label="Notifikasi">
                <span class="bell-icon">🔔</span>
                <?php if ($unread_notifications_count > 0): ?>
                    <span class="badge"><?= $unread_notifications_count ?></span>
                <?php endif; ?>
            </a>
            
            <button class="theme-toggle" onclick="toggleTheme()" aria-label="Toggle Theme">
                <span class="theme-icon">🌙</span>
            </button>
            <div class="user-menu">
                <span class="user-name"><?= htmlspecialchars($_SESSION['name']) ?></span>
                <a href="logout.php" class="btn btn-outline btn-sm">Keluar</a>
            </div>
        </div>
    </div>
</header>