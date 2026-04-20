<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';

$pdo = getPDO();

/* Fetch total employees using PDO */
$stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'employee'");
$stmt->execute();
$totalEmployees = $stmt->fetchColumn();

/* Auto-reject pending leave requests where start_date has passed */
$today = date('Y-m-d');
$autoRejectStmt = $pdo->prepare("UPDATE leaves SET status = 'rejected' WHERE status = 'pending' AND start_date < ?");
$autoRejectStmt->execute([$today]);

/* Fetch pending leaves count */
$stmt = $pdo->prepare("SELECT COUNT(*) FROM leaves WHERE status = 'pending'");
$stmt->execute();
$pendingLeaves = $stmt->fetchColumn();

/* Fetch today's present count */
$stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE attendance_date = ? AND status IN ('present', 'late', 'early', 'halfday')");
$stmt->execute([$today]);
$presentToday = $stmt->fetchColumn();

/* Fetch latest notice */
$noticeStmt = $pdo->prepare("
    SELECT title, description, created_at 
    FROM notices 
    ORDER BY created_at DESC 
    LIMIT 1
");
$noticeStmt->execute();
$latestNotice = $noticeStmt->fetch();

/* Fetch recent leave requests */
$leaveStmt = $pdo->prepare("
    SELECT l.*, u.username 
    FROM leaves l 
    JOIN users u ON l.user_id = u.id 
    ORDER BY l.created_at DESC 
    LIMIT 3
");
$leaveStmt->execute();
$recentLeaves = $leaveStmt->fetchAll();

/* Fetch today's attendance records with user info */
$attendanceStmt = $pdo->prepare("
    SELECT a.*, u.username, u.department, u.position 
    FROM attendance a 
    JOIN users u ON a.user_id = u.id 
    WHERE a.attendance_date = ? 
    ORDER BY a.check_in DESC 
    LIMIT 5
");
$attendanceStmt->execute([$today]);
$todayAttendance = $attendanceStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Admin Dashboard - WorkNest</title>

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

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Inter', sans-serif;
}

body {
    background: var(--bg-main);
    color: var(--text-main);
    overflow-x: hidden;
}

.wrapper {
    display: flex;
    min-height: 100vh;
}

/* ===== SIDEBAR ===== */
.sidebar {
    width: 260px;
    background: var(--bg-card);
    border-right: 2px solid var(--border-accent);
    padding: 24px 16px;
    position: fixed;
    height: 100vh;
    display: flex;
    flex-direction: column;
    z-index: 100;
    box-shadow: 4px 0 20px rgba(0,0,0,0.03);
}

.sidebar-brand {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 8px 12px;
    margin-bottom: 36px;
}

.sidebar-brand .logo-icon {
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

.menu {
    flex: 1;
}

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
    left: 0;
    top: 0;
    height: 100%;
    width: 0;
    background: linear-gradient(90deg, rgba(42, 170, 138, 0.1), transparent);
    transition: width 0.3s ease;
}

.menu a:hover::before {
    width: 100%;
}

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

.menu a.active:hover {
    transform: translateX(0);
}

.menu a .icon {
    width: 22px;
    text-align: center;
    font-size: 18px;
}

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

/* ===== MAIN ===== */
.main {
    flex: 1;
    padding: 32px 40px;
    margin-left: 260px;
    animation: fadeIn 0.5s ease;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* ===== HEADER ===== */
.header {
    margin-bottom: 32px;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}

.header-content h1 {
    font-size: 32px;
    font-weight: 800;
    margin-bottom: 8px;
    color: var(--text-main);
}

.header-content p {
    color: var(--text-muted);
    font-size: 15px;
}

.header-date {
    background: var(--bg-card);
    border: 1px solid var(--border-accent);
    padding: 12px 20px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 500;
    color: var(--text-main);
    box-shadow: var(--shadow-sm);
}

.header-date .icon {
    font-size: 20px;
}

/* ===== STATS ===== */
.stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 24px;
    margin-bottom: 32px;
}

.stat-card {
    background: var(--bg-card);
    border: 1px solid var(--border-light);
    border-radius: 16px;
    padding: 24px;
    display: flex;
    gap: 20px;
    align-items: center;
    box-shadow: var(--shadow-sm);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 100px;
    height: 100px;
    background: radial-gradient(circle, rgba(242, 211, 122, 0.3) 0%, transparent 70%);
    transform: translate(30%, -30%);
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-md);
    border-color: var(--border-accent);
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    position: relative;
    z-index: 1;
}

.stat-icon.blue { 
    background: linear-gradient(135deg, #dbeafe, #bfdbfe); 
    color: #2563eb; 
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
}
.stat-icon.green { 
    background: linear-gradient(135deg, #dcfce7, #bbf7d0); 
    color: #16a34a; 
    box-shadow: 0 4px 12px rgba(22, 163, 74, 0.2);
}
.stat-icon.orange { 
    background: linear-gradient(135deg, #d1fae5, #a7f3d0); 
    color: var(--primary-orange); 
    box-shadow: 0 4px 12px rgba(42, 170, 138, 0.2);
}
.stat-icon.purple { 
    background: linear-gradient(135deg, #f3e8ff, #e9d5ff); 
    color: #9333ea; 
    box-shadow: 0 4px 12px rgba(147, 51, 234, 0.2);
}

.stat-info {
    position: relative;
    z-index: 1;
}

.stat-info h3 {
    font-size: 13px;
    color: var(--text-muted);
    font-weight: 500;
    margin-bottom: 6px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stat-info span {
    font-size: 32px;
    font-weight: 800;
    color: var(--text-main);
    line-height: 1;
}

/* ===== CONTENT GRID ===== */
.grid {
    display: grid;
    grid-template-columns: 1.5fr 1fr;
    gap: 24px;
    margin-bottom: 24px;
}

.card {
    background: var(--bg-card);
    border: 1px solid var(--border-light);
    border-radius: 16px;
    padding: 24px;
    box-shadow: var(--shadow-sm);
    transition: all 0.3s ease;
}

.card:hover {
    box-shadow: var(--shadow-md);
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--border-light);
}

.card-header h3 {
    font-size: 17px;
    font-weight: 700;
    color: var(--text-main);
    display: flex;
    align-items: center;
    gap: 10px;
}

.card-header h3 .title-icon {
    font-size: 20px;
}

.card-header a {
    color: var(--primary-orange);
    text-decoration: none;
    font-weight: 600;
    font-size: 13px;
    padding: 8px 16px;
    background: var(--bg-input);
    border-radius: 8px;
    border: 1px solid var(--border-accent);
    transition: all 0.3s ease;
}

.card-header a:hover {
    background: var(--primary-orange);
    color: #fff;
    transform: translateY(-2px);
    box-shadow: var(--shadow-orange);
}

/* ===== LEAVE ===== */
.leave-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.leave-item {
    background: var(--bg-input);
    padding: 16px 20px;
    border-radius: 12px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border: 1px solid var(--border-light);
    transition: all 0.3s ease;
}

.leave-item:hover {
    border-color: var(--border-accent);
    transform: translateX(4px);
}

.leave-info {
    display: flex;
    align-items: center;
    gap: 14px;
}

.leave-avatar {
    width: 42px;
    height: 42px;
    background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-light));
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-weight: 700;
    font-size: 16px;
}

.leave-details strong {
    color: var(--text-main);
    font-size: 14px;
    font-weight: 600;
    display: block;
    margin-bottom: 4px;
}

.leave-details p {
    color: var(--text-muted);
    font-size: 12px;
}

.badge {
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.badge.approved { background: #dcfce7; color: #16a34a; }
.badge.pending { background: #fef3c7; color: #b45309; }
.badge.rejected { background: #fef2f2; color: #dc2626; }
.badge.present { background: #dcfce7; color: #16a34a; }
.badge.late { background: #fef3c7; color: #b45309; }
.badge.absent { background: #fef2f2; color: #dc2626; }

/* ===== ATTENDANCE ===== */
.attendance-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.attendance-item {
    background: var(--bg-input);
    padding: 14px 16px;
    border-radius: 10px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border: 1px solid var(--border-light);
    transition: all 0.3s ease;
}

.attendance-item:hover {
    border-color: var(--border-accent);
    transform: translateX(4px);
}

.attendance-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.attendance-avatar {
    width: 38px;
    height: 38px;
    background: linear-gradient(135deg, #3b82f6, #60a5fa);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-weight: 700;
    font-size: 14px;
}

.attendance-details strong {
    color: var(--text-main);
    font-size: 14px;
    font-weight: 600;
    display: block;
    margin-bottom: 2px;
}

.attendance-details p {
    color: var(--text-muted);
    font-size: 12px;
}

.attendance-time {
    text-align: right;
}

.attendance-time small {
    display: block;
    margin-top: 4px;
    color: var(--text-muted);
    font-size: 11px;
}

/* ===== NOTICE ===== */
.notice {
    background: linear-gradient(135deg, var(--bg-input), #fff);
    border-left: 4px solid var(--primary-orange);
    padding: 20px;
    border-radius: 0 12px 12px 0;
    position: relative;
    overflow: hidden;
}

.notice::before {
    content: '📢';
    position: absolute;
    top: 16px;
    right: 16px;
    font-size: 32px;
    opacity: 0.15;
}

.notice strong {
    color: var(--text-main);
    font-size: 16px;
    font-weight: 700;
    display: block;
    margin-bottom: 10px;
}

.notice p {
    color: var(--text-muted);
    font-size: 14px;
    line-height: 1.6;
}

.notice .date {
    font-size: 12px;
    color: var(--primary-orange);
    margin-top: 12px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 6px;
}

/* ===== LOWER GRID ===== */
.lower {
    display: grid;
    grid-template-columns: 1.5fr 1fr;
    gap: 24px;
}

.empty {
    color: var(--text-muted);
    text-align: center;
    padding: 48px 20px;
    font-size: 14px;
}

.empty-icon {
    font-size: 48px;
    margin-bottom: 16px;
    display: block;
}

.quick-stats {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.quick {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--bg-input);
    padding: 18px 20px;
    border-radius: 12px;
    border: 1px solid var(--border-light);
    transition: all 0.3s ease;
}

.quick:hover {
    border-color: var(--border-accent);
    transform: translateX(4px);
}

.quick-label {
    display: flex;
    align-items: center;
    gap: 12px;
}

.quick-label .quick-icon {
    width: 36px;
    height: 36px;
    background: linear-gradient(135deg, var(--border-accent), #f5e6c8);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
}

.quick-label span {
    color: var(--text-muted);
    font-size: 14px;
    font-weight: 500;
}

.quick strong {
    color: var(--text-main);
    font-size: 20px;
    font-weight: 800;
}

/* ===== RESPONSIVE ===== */
@media (max-width: 1200px) {
    .grid, .lower {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 1024px) {
    .sidebar {
        width: 240px;
    }
    .main {
        margin-left: 240px;
        padding: 24px;
    }
}

@media (max-width: 768px) {
    .sidebar {
        display: none;
    }
    .main {
        margin-left: 0;
        padding: 16px;
    }
    .stats {
        grid-template-columns: repeat(2, 1fr);
        gap: 16px;
    }
    .header {
        flex-direction: column;
        gap: 16px;
    }
    .stat-card {
        padding: 16px;
    }
    .stat-info span {
        font-size: 24px;
    }
}
</style>
</head>

<body>

<div class="wrapper">

    <!-- SIDEBAR -->
    <aside class="sidebar">
        <div class="sidebar-brand">
            <div class="logo-icon">🏢</div>
            <h2>WorkNest</h2>
        </div>
        <nav class="menu">
            <span class="menu-label">Main Menu</span>
            <a class="active" href="#"><span class="icon">🏠</span> Dashboard</a>
            <a href="employee/employee.php"><span class="icon">👥</span> Employees</a>
            <a href="attendance/attendance.php"><span class="icon">📅</span> Attendance</a>
            <a href="leave/leave.php"><span class="icon">🗓️</span> Leave Requests</a>
            <a href="notice/notices.php"><span class="icon">📢</span> Notices</a>
        </nav>
        <div class="sidebar-footer">
            <a href="../logout.php"><span class="icon">🚪</span> Logout</a>
        </div>
    </aside>

    <!-- MAIN -->
    <main class="main">

        <div class="header">
            <div class="header-content">
                <h1>Welcome back, Admin! 👋</h1>
                <p>Here's what's happening with your team today.</p>
            </div>
            <div class="header-date">
                <span class="icon">📅</span>
                <?php echo date('l, F d, Y'); ?>
            </div>
        </div>

        <!-- STATS -->
        <div class="stats">
            <div class="stat-card">
                <div class="stat-icon blue">👥</div>
                <div class="stat-info">
                    <h3>Total Employees</h3>
                    <span><?php echo $totalEmployees; ?></span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon green">✅</div>
                <div class="stat-info">
                    <h3>Present Today</h3>
                    <span><?php echo $presentToday; ?></span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon orange">📋</div>
                <div class="stat-info">
                    <h3>Pending Leaves</h3>
                    <span><?php echo $pendingLeaves; ?></span>
                </div>
            </div>

        </div>

        <!-- GRID -->
        <div class="grid">

            <div class="card">
                <div class="card-header">
                    <h3><span class="title-icon">🗓️</span> Recent Leave Requests</h3>
                    <a href="leave/leave.php">View all →</a>
                </div>

                <div class="leave-list">
                    <?php if (empty($recentLeaves)): ?>
                        <div class="empty">
                            <span class="empty-icon">📭</span>
                            <p>No leave requests yet</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recentLeaves as $leave): ?>
                            <div class="leave-item">
                                <div class="leave-info">
                                    <div class="leave-avatar">
                                        <?php echo strtoupper(substr($leave['username'], 0, 1)); ?>
                                    </div>
                                    <div class="leave-details">
                                        <strong><?php echo htmlspecialchars($leave['username']); ?></strong>
                                        <p><?php echo ucfirst(str_replace('_', ' ', $leave['leave_type'])); ?> • <?php echo date('M d, Y', strtotime($leave['start_date'])); ?></p>
                                    </div>
                                </div>
                                <span class="badge <?php echo $leave['status']; ?>"><?php echo $leave['status']; ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3><span class="title-icon">📢</span> Latest Notice</h3>
                    <a href="notice/notices.php">View all →</a>
                </div>

                <?php if ($latestNotice): ?>
                    <div class="notice">
                        <strong><?php echo htmlspecialchars($latestNotice['title']); ?></strong>
                        <p><?php echo htmlspecialchars($latestNotice['description']); ?></p>
                        <p class="date">📅 <?php echo date('M d, Y', strtotime($latestNotice['created_at'])); ?></p>
                    </div>
                <?php else: ?>
                    <div class="notice">
                        <strong>No notices yet</strong>
                        <p>Create your first notice to keep employees informed.</p>
                    </div>
                <?php endif; ?>
            </div>

        </div>

        <!-- LOWER -->
        <div class="lower">

            <div class="card">
                <div class="card-header">
                    <h3><span class="title-icon">📊</span> Today's Attendance</h3>
                    <a href="attendance/attendance.php">View all →</a>
                </div>
                <?php if (empty($todayAttendance)): ?>
                    <div class="empty">
                        <span class="empty-icon">📋</span>
                        <p>No attendance records for today</p>
                    </div>
                <?php else: ?>
                    <div class="attendance-list">
                        <?php foreach ($todayAttendance as $record): ?>
                            <div class="attendance-item">
                                <div class="attendance-info">
                                    <div class="attendance-avatar">
                                        <?php echo strtoupper(substr($record['username'], 0, 1)); ?>
                                    </div>
                                    <div class="attendance-details">
                                        <strong><?php echo htmlspecialchars($record['username']); ?></strong>
                                        <p><?php echo htmlspecialchars($record['department'] ?? 'N/A'); ?></p>
                                    </div>
                                </div>
                                <div class="attendance-time">
                                    <span class="badge <?php echo $record['status']; ?>"><?php echo ucfirst($record['status']); ?></span>
                                    <small><?php echo $record['check_in'] ? date('h:i A', strtotime($record['check_in'])) : '--:--'; ?></small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3><span class="title-icon">⚡</span> Quick Stats</h3>
                </div>
                <div class="quick-stats">
                    <div class="quick">
                        <div class="quick-label">
                            <div class="quick-icon">👥</div>
                            <span>Total Employees</span>
                        </div>
                        <strong><?php echo $totalEmployees; ?></strong>
                    </div>
                    <div class="quick">
                        <div class="quick-label">
                            <div class="quick-icon">✅</div>
                            <span>Present Today</span>
                        </div>
                        <strong><?php echo $presentToday; ?></strong>
                    </div>
                    <div class="quick">
                        <div class="quick-label">
                            <div class="quick-icon">⏳</div>
                            <span>Pending Requests</span>
                        </div>
                        <strong><?php echo $pendingLeaves; ?></strong>
                    </div>
                </div>
            </div>

        </div>

    </main>
</div>

</body>
</html>
