<?php
require_once '../../includes/auth_check.php';
require_once '../../includes/db.php';

$pdo = getPDO();
$userId = $_SESSION['user_id'];

// Fetch all notices
$stmt = $pdo->prepare("SELECT * FROM notices ORDER BY created_at DESC");
$stmt->execute();
$notices = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Notices - WorkNest</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* ===== CSS VARIABLES ===== */
:root {
    --bg-main: #f5f5f5;
    --bg-card: #FFFFFF;
    --bg-input: #FFFFFF;
    --border-accent: #2AAA8A;
    --border-light: #e0e0e0;
    --primary-orange: #2AAA8A;
    --primary-orange-dark: #238b72;
    --primary-orange-light: #34d399;
    --text-main: #333333;
    --text-muted: #666666;
    --text-light: #888888;
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.04);
    --shadow-md: 0 4px 16px rgba(0,0,0,0.08);
    --shadow-lg: 0 8px 32px rgba(0,0,0,0.12);
    --shadow-orange: 0 4px 20px rgba(42, 170, 138, 0.25);
}

* { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
body { background-color: var(--bg-main); color: var(--text-main); overflow-x: hidden; }
body.sidebar-open { overflow: hidden; }
.wrapper { display: flex; min-height: 100vh; }

/* Sidebar Overlay */
.sidebar-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0;
    width: 100%; height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 998;
    opacity: 0;
    transition: opacity 0.3s ease;
}
.sidebar-overlay.active { display: block; opacity: 1; }

/* SIDEBAR */
.sidebar {
    width: 260px;
    background: var(--bg-card);
    border-right: 2px solid var(--border-accent);
    padding: 24px 16px;
    position: fixed;
    height: 100vh;
    display: flex;
    flex-direction: column;
    z-index: 999;
    transition: transform 0.3s ease;
    box-shadow: 4px 0 20px rgba(0,0,0,0.03);
}
.sidebar-brand {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 8px 12px;
    margin-bottom: 36px;
}
.sidebar-brand .logo {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-light));
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    color: #fff;
    box-shadow: var(--shadow-orange);
}
.sidebar-brand h2 {
    font-size: 22px;
    font-weight: 800;
    background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-dark));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}
.menu { flex: 1; }
.menu-label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    color: var(--text-light);
    padding: 0 14px;
    margin-bottom: 14px;
    margin-top: 28px;
    font-weight: 600;
}
.menu a {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 16px;
    border-radius: 12px;
    text-decoration: none;
    color: var(--text-muted);
    margin-bottom: 6px;
    font-weight: 500;
    font-size: 14px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    border: 1px solid transparent;
    position: relative;
    overflow: hidden;
}
.menu a::before {
    content: '';
    position: absolute;
    left: 0; top: 0;
    height: 100%; width: 0;
    background: linear-gradient(90deg, rgba(42, 170, 138, 0.1), transparent);
    transition: width 0.3s ease;
}
.menu a:hover::before { width: 100%; }
.menu a:hover {
    background: var(--bg-input);
    border-color: var(--border-accent);
    color: var(--text-main);
    transform: translateX(4px);
}
.menu a.active {
    background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-light));
    color: #ffffff;
    box-shadow: var(--shadow-orange);
    border: none;
}
.menu a.active:hover { transform: translateX(0); }
.menu a .icon { width: 22px; text-align: center; font-size: 18px; }
.sidebar-footer {
    padding: 16px 12px;
    border-top: 2px solid var(--border-light);
    margin-top: auto;
}
.sidebar-footer a {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    border-radius: 12px;
    text-decoration: none;
    color: #dc2626;
    font-weight: 600;
    font-size: 14px;
    transition: all 0.3s ease;
    background: #fff5f5;
    border: 1px solid #fecaca;
}
.sidebar-footer a:hover {
    background: #fef2f2;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.15);
}

/* MAIN */
.main { flex: 1; padding: 32px 40px; margin-left: 260px; animation: fadeIn 0.5s ease; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

/* Top Bar */
.top-bar { display: flex; align-items: center; gap: 16px; margin-bottom: 24px; }
.page-title { font-size: 28px; font-weight: 800; color: var(--text-main); }

/* Hamburger */
.hamburger {
    display: none;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    width: 40px; height: 40px;
    background: var(--bg-card);
    border: 1px solid var(--border-accent);
    border-radius: 8px;
    cursor: pointer;
    gap: 5px;
    padding: 8px;
}
.hamburger:hover { background: var(--bg-input); }
.hamburger span { display: block; width: 20px; height: 2px; background: var(--text-main); border-radius: 2px; }

/* Page Header */
.page-header {
    background: linear-gradient(135deg, var(--primary-orange) 0%, var(--primary-orange-light) 100%);
    border-radius: 16px;
    padding: 32px;
    margin-bottom: 24px;
    color: #fff;
    position: relative;
    overflow: hidden;
}
.page-header::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 300px; height: 300px;
    background: rgba(255,255,255,0.1);
    border-radius: 50%;
}
.page-header h1 { font-size: 28px; font-weight: 700; margin-bottom: 8px; position: relative; z-index: 1; }
.page-header p { font-size: 16px; opacity: 0.9; position: relative; z-index: 1; }

/* Notice Cards */
.notices-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 24px;
}
.notice-card {
    background: var(--bg-card);
    border: 1px solid var(--border-light);
    border-radius: 16px;
    padding: 24px;
    box-shadow: var(--shadow-sm);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}
.notice-card::before {
    content: '';
    position: absolute;
    left: 0; top: 0;
    width: 4px; height: 100%;
    background: linear-gradient(180deg, var(--primary-orange), var(--primary-orange-light));
}
.notice-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-md);
    border-color: var(--border-accent);
}
.notice-card h3 {
    font-size: 18px;
    font-weight: 700;
    color: var(--text-main);
    margin-bottom: 12px;
    padding-right: 20px;
}
.notice-card p {
    font-size: 14px;
    color: var(--text-muted);
    line-height: 1.6;
    margin-bottom: 16px;
}
.notice-meta {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: var(--primary-orange);
    font-weight: 600;
}
.notice-meta .icon { font-size: 14px; }

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-muted);
}
.empty-state .icon { font-size: 64px; margin-bottom: 16px; opacity: 0.5; }
.empty-state h3 { font-size: 20px; font-weight: 600; color: var(--text-main); margin-bottom: 8px; }
.empty-state p { font-size: 14px; }

/* Responsive */
@media (max-width: 1024px) {
    .hamburger { display: flex; }
    .sidebar { transform: translateX(-100%); }
    .sidebar.open { transform: translateX(0); }
    .main { margin-left: 0; }
}
@media (max-width: 768px) {
    .main { padding: 16px; }
    .page-header { padding: 24px; }
    .page-header h1 { font-size: 22px; }
    .notices-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>
<!-- Sidebar Overlay -->
<div class="sidebar-overlay" onclick="toggleSidebar()"></div>

<div class="wrapper">
    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <div class="logo">🏢</div>
            <h2>WorkNest</h2>
        </div>
        <nav class="menu">
            <span class="menu-label">Main Menu</span>
            <a href="../dashboard.php"><span class="icon">🏠</span> Dashboard</a>
            <a href="../profile/profile.php"><span class="icon">👤</span> Profile</a>
            <a href="../attendance/attendance.php"><span class="icon">📅</span> Attendance</a>
            <a href="../leave/leave.php"><span class="icon">🗓️</span> Leave</a>
            <a href="#" class="active"><span class="icon">📢</span> Notices</a>
        </nav>
        <div class="sidebar-footer">
            <a href="../../logout.php"><span class="icon">🚪</span> Logout</a>
        </div>
    </aside>

    <!-- MAIN -->
    <main class="main">
        <!-- Top Bar -->
        <div class="top-bar">
            <button class="hamburger" onclick="toggleSidebar()">
                <span></span>
                <span></span>
                <span></span>
            </button>
            <h1 class="page-title">Notices</h1>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <h1>📢 Company Notices</h1>
            <p>Stay updated with the latest announcements and news</p>
        </div>

        <!-- Notices Grid -->
        <?php if (empty($notices)): ?>
            <div class="empty-state">
                <div class="icon">📭</div>
                <h3>No Notices Yet</h3>
                <p>There are no announcements at the moment. Check back later!</p>
            </div>
        <?php else: ?>
            <div class="notices-grid">
                <?php foreach ($notices as $notice): ?>
                    <div class="notice-card">
                        <h3><?php echo htmlspecialchars($notice['title']); ?></h3>
                        <p><?php echo nl2br(htmlspecialchars($notice['description'])); ?></p>
                        <div class="notice-meta">
                            <span class="icon">📅</span>
                            <?php echo date('M d, Y', strtotime($notice['created_at'])); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</div>

<script>
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    sidebar.classList.toggle('open');
    overlay.classList.toggle('active');
    document.body.classList.toggle('sidebar-open');
}
</script>
</body>
</html>
