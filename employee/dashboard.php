<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';

$pdo = getPDO();

/* Fetch logged-in employee data */
$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT username, email, department, position, created_at, profile_image
    FROM users 
    WHERE id = ? AND role = 'employee'
");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die('Employee not found');
}

// Get attendance statistics for current month
$currentMonth = date('Y-m');
$stmt = $pdo->prepare("
    SELECT 
        COUNT(CASE WHEN status = 'present' THEN 1 END) as present_days,
        COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent_days,
        COUNT(CASE WHEN status = 'late' THEN 1 END) as late_days,
        COUNT(CASE WHEN status = 'early' THEN 1 END) as early_days,
        COUNT(CASE WHEN status = 'halfday' THEN 1 END) as halfday_days,
        COUNT(*) as total_records
    FROM attendance 
    WHERE user_id = ? AND attendance_date LIKE ?
");
$stmt->execute([$userId, $currentMonth . '%']);
$stats = $stmt->fetch();

$presentDays = (int)$stats['present_days'];
$absentDays = (int)$stats['absent_days'];
$lateDays = (int)$stats['late_days'];
$earlyDays = (int)$stats['early_days'];
$halfdayDays = (int)$stats['halfday_days'];
$totalRecords = (int)$stats['total_records'];

// Combine early + present + halfday + late as effective present days
$effectivePresentDays = $earlyDays + $presentDays + $halfdayDays + $lateDays;
$attendancePercentage = $totalRecords > 0 
    ? round(($effectivePresentDays / $totalRecords) * 100, 1) 
    : 0;

// ===== LEAVE STATISTICS =====
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_leaves,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_leaves,
        COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_leaves,
        COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected_leaves
    FROM leaves 
    WHERE user_id = ?
");
$stmt->execute([$userId]);
$leaveStats = $stmt->fetch();

$pendingLeaves = (int)($leaveStats['pending_leaves'] ?? 0);
$approvedLeaves = (int)($leaveStats['approved_leaves'] ?? 0);
$rejectedLeaves = (int)($leaveStats['rejected_leaves'] ?? 0);

// Get recent leave requests - only 1 latest
$stmt = $pdo->prepare("
    SELECT * FROM leaves 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 1
");
$stmt->execute([$userId]);
$latestLeave = $stmt->fetch(PDO::FETCH_ASSOC);

// Get notices count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notices WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$stmt->execute();
$newNoticesCount = $stmt->fetchColumn();

// ===== LEAVE BALANCE =====
$leaveAllowances = [
    'casual_leave' => 2,
    'sick_leave' => 3,
    'paid_leave' => 2,
    'unpaid_leave' => -1 // -1 means unlimited
];

// Get approved leaves count by type for current year
$currentYear = date('Y');
$stmt = $pdo->prepare("
    SELECT 
        leave_type,
        SUM(DATEDIFF(end_date, start_date) + 1) as days_taken
    FROM leaves 
    WHERE user_id = ? AND status = 'approved' AND YEAR(start_date) = ?
    GROUP BY leave_type
");
$stmt->execute([$userId, $currentYear]);
$leavesTaken = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Calculate remaining balance
$leaveBalance = [
    'casual_leave' => [
        'total' => $leaveAllowances['casual_leave'],
        'used' => (int)($leavesTaken['casual_leave'] ?? 0),
        'remaining' => $leaveAllowances['casual_leave'] - (int)($leavesTaken['casual_leave'] ?? 0)
    ],
    'sick_leave' => [
        'total' => $leaveAllowances['sick_leave'],
        'used' => (int)($leavesTaken['sick_leave'] ?? 0),
        'remaining' => $leaveAllowances['sick_leave'] - (int)($leavesTaken['sick_leave'] ?? 0)
    ],
    'paid_leave' => [
        'total' => $leaveAllowances['paid_leave'],
        'used' => (int)($leavesTaken['paid_leave'] ?? 0),
        'remaining' => $leaveAllowances['paid_leave'] - (int)($leavesTaken['paid_leave'] ?? 0)
    ],
    'unpaid_leave' => [
        'total' => -1,
        'used' => (int)($leavesTaken['unpaid_leave'] ?? 0),
        'remaining' => -1 // Unlimited
    ]
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Employee Dashboard</title>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

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

* { margin:0; padding:0; box-sizing:border-box; font-family:'Inter', sans-serif; }
body { background-color: var(--bg-main); color: var(--text-main); overflow-x: hidden; }
body.sidebar-open { overflow: hidden; }
.wrapper { display:flex; min-height:100vh; }

/* Sidebar Overlay */
.sidebar-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 998;
    opacity: 0;
    transition: opacity 0.3s ease;
}
.sidebar-overlay.active {
    display: block;
    opacity: 1;
}

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
.main { flex:1; padding:32px 40px; margin-left: 260px; animation: fadeIn 0.5s ease; }
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

/* Welcome Banner */
.welcome-banner {
    background: linear-gradient(135deg, var(--primary-orange) 0%, var(--primary-orange-light) 100%);
    border-radius: 16px;
    padding: 32px;
    margin-bottom: 24px;
    color: #fff;
    position: relative;
    overflow: hidden;
}
.welcome-banner::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 300px; height: 300px;
    background: rgba(255,255,255,0.1);
    border-radius: 50%;
}
.welcome-banner h1 { font-size: 28px; font-weight: 700; margin-bottom: 8px; position: relative; z-index: 1; }
.welcome-banner p { font-size: 16px; opacity: 0.9; position: relative; z-index: 1; }
.welcome-banner .date { margin-top: 16px; font-size: 14px; opacity: 0.8; position: relative; z-index: 1; }

/* Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 20px;
    margin-bottom: 24px;
}
.stat-card {
    background: var(--bg-card);
    border: 1px solid var(--border-light);
    border-radius: 14px;
    padding: 24px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: var(--shadow-sm);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}
.stat-card::before {
    content: '';
    position: absolute;
    top: 0; right: 0;
    width: 80px; height: 80px;
    background: radial-gradient(circle, rgba(242, 211, 122, 0.2) 0%, transparent 70%);
    transform: translate(20%, -20%);
}
.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-md);
    border-color: var(--border-accent);
}
.stat-icon {
    width: 56px;
    height: 56px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    position: relative;
    z-index: 1;
}
.stat-icon.blue { background: linear-gradient(135deg, #dbeafe, #bfdbfe); color: #2563eb; }
.stat-icon.green { background: linear-gradient(135deg, #dcfce7, #bbf7d0); color: #16a34a; }
.stat-icon.orange { background: linear-gradient(135deg, #ffedd5, #fed7aa); color: var(--primary-orange); }
.stat-icon.purple { background: linear-gradient(135deg, #f3e8ff, #e9d5ff); color: #9333ea; }
.stat-info { position: relative; z-index: 1; }
.stat-info h3 { font-size: 26px; font-weight: 700; color: var(--text-main); }
.stat-info p { font-size: 14px; color: var(--text-muted); margin-top: 4px; }

/* Cards Grid */
.cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 24px;
}

/* COMMON CARD STYLE */
.card {
    background: var(--bg-card);
    border: 1px solid var(--border-light);
    border-radius: 16px;
    padding: 24px;
    box-shadow: var(--shadow-sm);
    transition: all 0.3s ease;
}
.card:hover { box-shadow: var(--shadow-md); }
.card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--border-light);
}
.card-header h2 { font-size: 18px; font-weight: 600; color: var(--text-main); }
.card-header .view-all {
    font-size: 13px;
    color: var(--primary-orange);
    text-decoration: none;
    font-weight: 600;
    padding: 6px 14px;
    background: var(--bg-input);
    border-radius: 8px;
    border: 1px solid var(--border-accent);
    transition: all 0.3s ease;
}
.card-header .view-all:hover {
    background: var(--primary-orange);
    color: #fff;
}

/* Profile Card */
.profile-card { text-align: center; }
.profile-avatar {
    width: 80px;
    height: 80px;
    background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-light));
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
    color: #fff;
    margin: 0 auto 16px;
    box-shadow: var(--shadow-orange);
}
.profile-name { font-size: 20px; font-weight: 700; color: var(--text-main); margin-bottom: 4px; }
.profile-position { font-size: 14px; color: var(--text-muted); margin-bottom: 20px; }
.profile-details { text-align: left; border-top: 1px solid var(--border-light); padding-top: 20px; }
.profile-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 0;
    border-bottom: 1px solid var(--border-light);
}
.profile-item:last-child { border-bottom: none; }
.profile-item .icon {
    width: 36px;
    height: 36px;
    background: var(--bg-input);
    border: 1px solid var(--border-accent);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
}
.profile-item .info label { font-size: 12px; color: var(--text-muted); display: block; }
.profile-item .info span { font-size: 14px; color: var(--text-main); font-weight: 500; }

/* Quick Actions */
.quick-actions { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
.action-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: var(--bg-input);
    border: 1px solid var(--border-light);
    border-radius: 12px;
    text-decoration: none;
    color: var(--text-muted);
    transition: all 0.3s ease;
}
.action-btn:hover {
    background: var(--bg-card);
    border-color: var(--border-accent);
    color: var(--primary-orange);
    transform: translateY(-2px);
    box-shadow: var(--shadow-sm);
}
.action-btn .icon { font-size: 24px; margin-bottom: 8px; }
.action-btn span { font-size: 13px; font-weight: 500; }

/* Empty State */
.empty-state { text-align: center; padding: 40px 20px; color: var(--text-muted); }
.empty-state .icon { font-size: 48px; margin-bottom: 12px; opacity: 0.5; }

/* Responsive */
@media (max-width: 1024px) {
    .hamburger { display: flex; }
    .sidebar { transform: translateX(-100%); }
    .sidebar.open { transform: translateX(0); }
    .main { margin-left: 0; }
}
@media (max-width: 768px) {
    .main { padding: 16px; }
    .welcome-banner { padding: 24px; }
    .welcome-banner h1 { font-size: 22px; }
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
    .cards-grid { grid-template-columns: 1fr; }
}
@media (max-width: 480px) {
    .stats-grid { grid-template-columns: 1fr; }
    .page-title { font-size: 20px; }
}


/* Leave Summary Styles */
.leave-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-bottom: 20px;
}
.leave-stat-item {
    background: var(--bg-input);
    border-radius: 10px;
    padding: 16px;
    text-align: center;
    border-left: 4px solid;
}
.leave-stat-item.pending { border-color: #f59e0b; }
.leave-stat-item.approved { border-color: #16a34a; }
.leave-stat-item.rejected { border-color: #dc2626; }
.leave-stat-item .count {
    font-size: 28px;
    font-weight: 800;
    margin-bottom: 4px;
}
.leave-stat-item .count.pending { color: #f59e0b; }
.leave-stat-item .count.approved { color: #16a34a; }
.leave-stat-item .count.rejected { color: #dc2626; }
.leave-stat-item .label {
    font-size: 12px;
    color: var(--text-muted);
}

/* Latest Leave */
.latest-leave-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px;
    background: var(--bg-input);
    border: 1px solid var(--border-light);
    border-radius: 12px;
}
.leave-info .leave-type {
    font-size: 15px;
    font-weight: 600;
    color: var(--text-main);
    margin-bottom: 4px;
}
.leave-info .leave-dates {
    font-size: 13px;
    color: var(--text-muted);
}
.leave-status-badge {
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
}
.leave-status-badge.pending { background: #fef3c7; color: #b45309; }
.leave-status-badge.approved { background: #dcfce7; color: #16a34a; }
.leave-status-badge.rejected { background: #fef2f2; color: #dc2626; }

/* Leave Balance Styles */
.leave-balance-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    margin-bottom: 20px;
}
.leave-balance-item {
    background: var(--bg-input);
    border: 1px solid var(--border-light);
    border-radius: 10px;
    padding: 14px;
    text-align: center;
    position: relative;
    overflow: hidden;
}
.leave-balance-item::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 4px;
}
.leave-balance-item.casual::before { background: #3b82f6; }
.leave-balance-item.sick::before { background: #ef4444; }
.leave-balance-item.paid::before { background: #16a34a; }
.leave-balance-item.unpaid::before { background: #6b7280; }

.leave-balance-item .type {
    font-size: 12px;
    color: var(--text-muted);
    margin-bottom: 8px;
    font-weight: 500;
}
.leave-balance-item .count {
    font-size: 24px;
    font-weight: 800;
    color: var(--text-main);
}
.leave-balance-item .count.low { color: #dc2626; }
.leave-balance-item .count.unlimited { color: #16a34a; font-size: 14px; }
.leave-balance-item .total {
    font-size: 11px;
    color: var(--text-light);
    margin-top: 4px;
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
            <a class="active" href="#"><span class="icon">🏠</span> Dashboard</a>
            <a href="profile/profile.php"><span class="icon">👤</span> Profile</a>
            <a href="attendance/attendance.php"><span class="icon">📅</span> Attendance</a>
            <a href="leave/leave.php"><span class="icon">🗓️</span> Leave</a>
            <a href="notice/notices.php"><span class="icon">📢</span> Notices</a>
        </nav>
        <div class="sidebar-footer">
            <a href="../logout.php"><span class="icon">🚪</span> Logout</a>
        </div>
    </aside>

    <!-- MAIN -->
    <main class="main">
        <!-- Top Bar with Hamburger -->
        <div class="top-bar">
            <button class="hamburger" onclick="toggleSidebar()">
                <span></span>
                <span></span>
                <span></span>
            </button>
        </div>

        <!-- Welcome Banner -->

        <div class="welcome-banner" style="display: flex; align-items: center; gap:35px; justify-content: flex-start; text-align: left;">
            <?php
                $avatarSrc = !empty($user['profile_image'])
                    ? '../' . htmlspecialchars($user['profile_image'])
                    : 'https://ui-avatars.com/api/?name=' . urlencode($user['username']) . '&background=2AAA8A&color=fff&size=80';
            ?>
            <img src="<?= $avatarSrc; ?>" alt="Profile" class="profile-avatar" style="width:100px;height:100px;border-radius:50%;object-fit:cover;box-shadow:0 2px 8px rgba(0,0,0,0.08); margin-right: 0; margin-left: 0; border-radius: 50%;
            border: 4px solid white;">
            <div style="margin-left: 0;">
                <h1 style="margin-bottom: 4px;">Welcome back, <?= htmlspecialchars($user['username']); ?>! 👋</h1>
                <p style="margin-bottom: 2px;">Here's what's happening with your account today.</p>
                <div class="date">📅 <?= date('l, F d, Y'); ?></div>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon blue">📅</div>
                <div class="stat-info">
                    <h3><?= $effectivePresentDays; ?></h3>
                    <p>Present Days</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green">✓</div>
                <div class="stat-info">
                    <h3><?= $attendancePercentage; ?>%</h3>
                    <p>Attendance Rate</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange">⏳</div>
                <div class="stat-info">
                    <h3><?= $pendingLeaves; ?></h3>
                    <p>Pending Leaves</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon purple">📢</div>
                <div class="stat-info">
                    <h3><?= $newNoticesCount; ?></h3>
                    <p>New Notices</p>
                </div>
            </div>
        </div>

        <!-- Cards Grid -->
        <div class="cards-grid">
            <!-- Profile Card -->
           <!-- <div class="card profile-card">
                <div class="profile-avatar">
                    <?= strtoupper(substr($user['username'], 0, 1)); ?>
                </div>
                <div class="profile-name"><?= htmlspecialchars($user['username']); ?></div>
                <div class="profile-position"><?= htmlspecialchars($user['position']); ?></div>
                
                <div class="profile-details">
                    <div class="profile-item">
                        <div class="icon">📧</div>
                        <div class="info">
                            <label>Email</label>
                            <span><?= htmlspecialchars($user['email']); ?></span>
                        </div>
                    </div>
                    <div class="profile-item">
                        <div class="icon">🏢</div>
                        <div class="info">
                            <label>Department</label>
                            <span><?= htmlspecialchars($user['department']); ?></span>
                        </div>
                    </div>
                    <div class="profile-item">
                        <div class="icon">📅</div>
                        <div class="info">
                            <label>Joined</label>
                            <span><?= date('F d, Y', strtotime($user['created_at'])); ?></span>
                        </div>
                    </div>
                </div>
            </div>-->

            <!-- Quick Actions Card -->
            <div class="card">
                <div class="card-header">
                    <h2>Quick Actions</h2>
                </div>
                <div class="quick-actions">
                    <a href="attendance/attendance.php" class="action-btn">
                        <div class="icon">📅</div>
                        <span>Mark Attendance</span>
                    </a>
                    <a href="leave/leave.php" class="action-btn">
                        <div class="icon">🗓️</div>
                        <span>Apply Leave</span>
                    </a>
                    <a href="notice/notices.php" class="action-btn">
                        <div class="icon">📢</div>
                        <span>View Notices</span>
                    </a>
                    <a href="profile/profile.php" class="action-btn">
                        <div class="icon">👤</div>
                        <span>My Profile</span>
                    </a>
                </div>
            </div>

            <!-- Leave Balance Card -->
            <div class="card">
                <div class="card-header">
                    <h2>Leave Balance</h2>
                    <a href="leave/leave.php" class="view-all">View All</a>
                </div>
                
                <div class="leave-balance-grid">
                    <div class="leave-balance-item casual">
                        <div class="type">Casual Leave</div>
                        <div class="count <?= $leaveBalance['casual_leave']['remaining'] <= 0 ? 'low' : ''; ?>">
                            <?= max(0, $leaveBalance['casual_leave']['remaining']); ?>
                        </div>
                        <div class="total">of <?= $leaveBalance['casual_leave']['total']; ?> day</div>
                    </div>
                    <div class="leave-balance-item sick">
                        <div class="type">Sick Leave</div>
                        <div class="count <?= $leaveBalance['sick_leave']['remaining'] <= 1 ? 'low' : ''; ?>">
                            <?= max(0, $leaveBalance['sick_leave']['remaining']); ?>
                        </div>
                        <div class="total">of <?= $leaveBalance['sick_leave']['total']; ?> days</div>
                    </div>
                    <div class="leave-balance-item paid">
                        <div class="type">Paid Leave</div>
                        <div class="count <?= $leaveBalance['paid_leave']['remaining'] <= 0 ? 'low' : ''; ?>">
                            <?= max(0, $leaveBalance['paid_leave']['remaining']); ?>
                        </div>
                        <div class="total">of <?= $leaveBalance['paid_leave']['total']; ?> days</div>
                    </div>
                    <div class="leave-balance-item unpaid">
                        <div class="type">Unpaid Leave</div>
                        <div class="count unlimited">Unlimited</div>
                        <div class="total"><?= $leaveBalance['unpaid_leave']['used']; ?> day(s) taken</div>
                    </div>
                </div>
            </div>

            <!-- Leave Summary Card -->
            <div class="card">
                <div class="card-header">
                    <h2>Leave Summary</h2>
                    <a href="leave/leave.php" class="view-all">Apply Leave</a>
                </div>
                
                <!-- Leave Stats -->
                <div class="leave-stats">
                    <div class="leave-stat-item pending">
                        <div class="count pending"><?= $pendingLeaves; ?></div>
                        <div class="label">Pending</div>
                    </div>
                    <div class="leave-stat-item approved">
                        <div class="count approved"><?= $approvedLeaves; ?></div>
                        <div class="label">Approved</div>
                    </div>
                    <div class="leave-stat-item rejected">
                        <div class="count rejected"><?= $rejectedLeaves; ?></div>
                        <div class="label">Rejected</div>
                    </div>
                </div>
                
                <!-- Latest Leave -->
                <h4 style="font-size: 13px; color: var(--text-muted); margin-bottom: 12px;">Latest Request</h4>
                <?php if (!$latestLeave): ?>
                    <div class="empty-state" style="padding: 20px;">
                        <div class="icon">🗓️</div>
                        <p>No leave requests yet</p>
                    </div>
                <?php else: ?>
                    <div class="latest-leave-item">
                        <div class="leave-info">
                            <div class="leave-type"><?= ucfirst(str_replace('_', ' ', $latestLeave['leave_type'])); ?></div>
                            <div class="leave-dates">
                                <?= date('M d', strtotime($latestLeave['start_date'])); ?> - <?= date('M d, Y', strtotime($latestLeave['end_date'])); ?>
                            </div>
                        </div>
                        <span class="leave-status-badge <?= $latestLeave['status']; ?>">
                            <?= $latestLeave['status']; ?>
                        </span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

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
