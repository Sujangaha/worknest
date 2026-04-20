<?php
require_once '../../includes/auth_check.php';
require_once '../../includes/db.php';

$pdo = getPDO();

// Get selected date (default to today)
$selectedDate = $_GET['date'] ?? date('Y-m-d');
$selectedMonth = $_GET['month'] ?? date('Y-m');

// Get all employees
$stmt = $pdo->prepare("SELECT id, username, email, department, position FROM users WHERE role = 'employee' ORDER BY username");
$stmt->execute();
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get attendance for selected date
$stmt = $pdo->prepare("
    SELECT a.*, u.username, u.department, u.position 
    FROM attendance a 
    JOIN users u ON a.user_id = u.id 
    WHERE a.attendance_date = ?
    ORDER BY u.username
");
$stmt->execute([$selectedDate]);
$attendanceRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Create lookup for quick access
$attendanceLookup = [];
foreach ($attendanceRecords as $record) {
    $attendanceLookup[$record['user_id']] = $record;
}

// Get attendance stats for today
$stmt = $pdo->prepare("
    SELECT 
        COUNT(CASE WHEN status IN ('present', 'early') THEN 1 END) as present_count,
        COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent_count,
        COUNT(CASE WHEN status = 'late' THEN 1 END) as late_count,
        COUNT(CASE WHEN status = 'halfday' THEN 1 END) as halfday_count,
        COUNT(*) as total_count
    FROM attendance 
    WHERE attendance_date = ?
");
$stmt->execute([$selectedDate]);
$stats = $stmt->fetch();

$presentCount = (int)($stats['present_count'] ?? 0) + (int)($stats['late_count'] ?? 0) + (int)($stats['halfday_count'] ?? 0); // early, late, halfday all count as present
$absentCount = (int)($stats['absent_count'] ?? 0);
$totalEmployees = count($employees);
$notMarked = $totalEmployees - ($presentCount + $absentCount);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Attendance - WorkNest Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root {
    --bg-main: #f5f5f5; --bg-card: #FFFFFF; --bg-input: #FFFFFF;
    --border-accent: #2AAA8A; --border-light: #e0e0e0;
    --primary-orange: #2AAA8A; --primary-orange-dark: #238b72; --primary-orange-light: #34d399;
    --text-main: #333333; --text-muted: #666666; --text-light: #888888;
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.04); --shadow-md: 0 4px 16px rgba(0,0,0,0.08);
    --shadow-orange: 0 4px 20px rgba(42, 170, 138, 0.25);
}
* { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
body { background: var(--bg-main); color: var(--text-main); }
.wrapper { display: flex; min-height: 100vh; }

.sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 998; }
.sidebar-overlay.active { display: block; }

.sidebar { width: 260px; background: var(--bg-card); border-right: 2px solid var(--border-accent); padding: 24px 16px; position: fixed; height: 100vh; display: flex; flex-direction: column; z-index: 999; transition: transform 0.3s; }
.sidebar-brand { display: flex; align-items: center; gap: 14px; padding: 8px 12px; margin-bottom: 36px; }
.sidebar-brand .logo-icon { width: 48px; height: 48px; background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-light)); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 24px; color: #fff; box-shadow: var(--shadow-orange); }
.sidebar-brand h2 { font-size: 22px; font-weight: 800; background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-dark)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
.menu { flex: 1; }
.menu-label { font-size: 10px; text-transform: uppercase; letter-spacing: 1.5px; color: var(--text-light); padding: 0 14px; margin-bottom: 14px; font-weight: 600; }
.menu a { display: flex; align-items: center; gap: 14px; padding: 14px 16px; border-radius: 12px; text-decoration: none; color: var(--text-muted); margin-bottom: 6px; font-weight: 500; font-size: 14px; transition: all 0.3s; border: 1px solid transparent; }
.menu a:hover { background: var(--bg-input); border-color: var(--border-accent); color: var(--text-main); }
.menu a.active { background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-light)); color: #fff; box-shadow: var(--shadow-orange); }
.menu a .icon { width: 22px; text-align: center; font-size: 18px; }
.sidebar-footer { padding: 16px 12px; border-top: 2px solid var(--border-light); margin-top: auto; }
.sidebar-footer a { display: flex; align-items: center; gap: 12px; padding: 14px 16px; border-radius: 12px; text-decoration: none; color: #dc2626; font-weight: 600; background: #fff5f5; border: 1px solid #fecaca; }

.main { flex: 1; padding: 32px 40px; margin-left: 260px; }
.top-bar { display: flex; align-items: center; gap: 16px; margin-bottom: 8px; }
.page-title { font-size: 28px; font-weight: 800; }
.hamburger { display: none; flex-direction: column; justify-content: center; align-items: center; width: 40px; height: 40px; background: var(--bg-card); border: 1px solid var(--border-accent); border-radius: 8px; cursor: pointer; gap: 5px; }
.hamburger span { display: block; width: 20px; height: 2px; background: var(--text-main); }
.subtitle { color: var(--text-muted); margin-bottom: 24px; }

/* Stats Grid */
.stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
.stat-card { background: var(--bg-card); border: 1px solid var(--border-light); border-radius: 14px; padding: 20px; text-align: center; transition: all 0.3s; }
.stat-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); border-color: var(--border-accent); }
.stat-card .stat-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 22px; margin: 0 auto 12px; }
.stat-card .stat-icon.total { background: #dbeafe; color: #2563eb; }
.stat-card .stat-icon.present { background: #dcfce7; color: #16a34a; }
.stat-card .stat-icon.late { background: #fef3c7; color: #d97706; }
.stat-card .stat-icon.absent { background: #fef2f2; color: #dc2626; }
.stat-card .stat-icon.pending { background: #f3f4f6; color: #6b7280; }
.stat-card .count { font-size: 28px; font-weight: 800; color: var(--text-main); }
.stat-card .label { font-size: 12px; color: var(--text-muted); margin-top: 4px; }

/* Date Filter */
.filter-bar { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
.date-picker { display: flex; align-items: center; gap: 12px; }
.date-picker label { font-size: 14px; font-weight: 600; color: var(--text-main); }
.date-picker input[type="date"] { padding: 12px 16px; border: 2px solid var(--border-light); border-radius: 10px; font-size: 14px; background: var(--bg-card); cursor: pointer; }
.date-picker input[type="date"]:focus { outline: none; border-color: var(--primary-orange); }
.btn { padding: 12px 24px; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; text-decoration: none; }
.btn-primary { background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-light)); color: #fff; }
.btn-primary:hover { transform: translateY(-2px); box-shadow: var(--shadow-orange); }
.btn-secondary { background: var(--bg-input); color: var(--text-main); border: 1px solid var(--border-accent); }

/* Card */
.card { background: var(--bg-card); border: 1px solid var(--border-light); border-radius: 16px; padding: 24px; margin-bottom: 24px; }
.card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid var(--border-light); }
.card-header h2 { font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 10px; }

/* Attendance Grid */
.attendance-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }
.attendance-card { background: var(--bg-input); border: 1px solid var(--border-light); border-radius: 14px; padding: 20px; position: relative; transition: all 0.3s; }
.attendance-card:hover { border-color: var(--border-accent); transform: translateY(-2px); box-shadow: var(--shadow-sm); }
.attendance-card::before { content: ''; position: absolute; left: 0; top: 0; width: 5px; height: 100%; border-radius: 14px 0 0 14px; }
.attendance-card.present::before { background: linear-gradient(180deg, #16a34a, #22c55e); }
.attendance-card.late::before { background: linear-gradient(180deg, #d97706, #f59e0b); }
.attendance-card.absent::before { background: linear-gradient(180deg, #dc2626, #ef4444); }
.attendance-card.not-marked::before { background: linear-gradient(180deg, #9ca3af, #6b7280); }

.employee-header { display: flex; align-items: center; gap: 14px; margin-bottom: 14px; }
.employee-avatar { width: 48px; height: 48px; background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-light)); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 700; color: #fff; flex-shrink: 0; }
.attendance-card.not-marked .employee-avatar { background: linear-gradient(135deg, #9ca3af, #6b7280); }
.employee-details .name { font-size: 15px; font-weight: 700; color: var(--text-main); margin-bottom: 2px; }
.employee-details .position { font-size: 12px; color: var(--text-muted); }
.employee-details .department { font-size: 11px; color: var(--text-light); }

.attendance-status { position: absolute; top: 16px; right: 16px; padding: 5px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
.attendance-status.present { background: #dcfce7; color: #16a34a; }
.attendance-status.late { background: #fef3c7; color: #d97706; }
.attendance-status.absent { background: #fef2f2; color: #dc2626; }
.attendance-status.not-marked { background: #f3f4f6; color: #6b7280; }

.attendance-times { display: flex; gap: 16px; margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--border-light); }
.time-item { flex: 1; }
.time-item .label { font-size: 11px; color: var(--text-light); margin-bottom: 4px; }
.time-item .value { font-size: 14px; font-weight: 600; color: var(--text-main); }
.time-item .value.empty { color: var(--text-light); font-style: italic; }

/* Empty State */
.empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
.empty-state .icon { font-size: 64px; margin-bottom: 16px; opacity: 0.5; }

@media (max-width: 1200px) { .stats-grid { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 1024px) {
    .hamburger { display: flex; }
    .sidebar { transform: translateX(-100%); }
    .sidebar.open { transform: translateX(0); }
    .main { margin-left: 0; }
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 768px) {
    .main { padding: 16px; }
    .stats-grid { grid-template-columns: 1fr; }
    .attendance-grid { grid-template-columns: 1fr; }
    .filter-bar { flex-direction: column; align-items: stretch; }
}
</style>
</head>
<body>
<div class="sidebar-overlay" onclick="toggleSidebar()"></div>

<div class="wrapper">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <div class="logo-icon">🏢</div>
            <h2>WorkNest</h2>
        </div>
        <nav class="menu">
            <span class="menu-label">Main Menu</span>
            <a href="../dashboard.php"><span class="icon">🏠</span> Dashboard</a>
            <a href="../employee/employee.php"><span class="icon">👥</span> Employees</a>
            <a href="attendance.php" class="active"><span class="icon">📅</span> Attendance</a>
            <a href="../leave/leave.php"><span class="icon">🗓️</span> Leave Requests</a>
            <a href="../notice/notices.php"><span class="icon">📢</span> Notices</a>
        </nav>
        <div class="sidebar-footer">
            <a href="../../logout.php"><span class="icon">🚪</span> Logout</a>
        </div>
    </aside>

    <main class="main">
        <div class="top-bar">
            <button class="hamburger" onclick="toggleSidebar()"><span></span><span></span><span></span></button>
            <h1 class="page-title">Attendance</h1>
        </div>
        <p class="subtitle">Monitor employee attendance for <?= date('F d, Y', strtotime($selectedDate)); ?></p>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon total">👥</div>
                <div class="count"><?= $totalEmployees; ?></div>
                <div class="label">Total Employees</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon present">✅</div>
                <div class="count"><?= $presentCount; ?></div>
                <div class="label">Present</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon absent">❌</div>
                <div class="count"><?= $absentCount; ?></div>
                <div class="label">Absent</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon pending">⏳</div>
                <div class="count"><?= $notMarked; ?></div>
                <div class="label">Not Marked</div>
            </div>
        </div>

        <!-- Date Filter -->
        <div class="filter-bar">
            <form class="date-picker" method="GET">
                <label>📅 Select Date:</label>
                <input type="date" name="date" value="<?= $selectedDate; ?>" max="<?= date('Y-m-d'); ?>" onchange="this.form.submit()">
            </form>
            <div style="display: flex; gap: 10px;">
                <a href="?date=<?= date('Y-m-d'); ?>" class="btn btn-secondary">Today</a>
                <a href="?date=<?= date('Y-m-d', strtotime('-1 day')); ?>" class="btn btn-secondary">Yesterday</a>
            </div>
        </div>

        <!-- Attendance Records -->
        <div class="card">
            <div class="card-header">
                <h2>📋 Attendance Records</h2>
                <span style="font-size: 14px; color: var(--text-muted);"><?= count($employees); ?> employees</span>
            </div>

            <?php if (empty($employees)): ?>
                <div class="empty-state">
                    <div class="icon">👥</div>
                    <h3>No Employees</h3>
                    <p>Add employees to track attendance.</p>
                </div>
            <?php else: ?>
                <div class="attendance-grid">
                    <?php foreach ($employees as $emp): ?>
                        <?php 
                        $record = $attendanceLookup[$emp['id']] ?? null;
                        $status = $record['status'] ?? 'not-marked';
                        $checkIn = $record['check_in'] ?? null;
                        $checkOut = $record['check_out'] ?? null;
                        ?>
                        <div class="attendance-card <?= $status; ?>">
                            <span class="attendance-status <?= $status; ?>">
                                <?= $status === 'not-marked' ? 'Not Marked' : ucfirst($status); ?>
                            </span>
                            <div class="employee-header">
                                <div class="employee-avatar">
                                    <?= strtoupper(substr($emp['username'], 0, 2)); ?>
                                </div>
                                <div class="employee-details">
                                    <div class="name"><?= htmlspecialchars($emp['username']); ?></div>
                                    <div class="position"><?= htmlspecialchars($emp['position'] ?: 'No Position'); ?></div>
                                    <div class="department"><?= htmlspecialchars($emp['department'] ?: 'No Department'); ?></div>
                                </div>
                            </div>
                            <div class="attendance-times">
                                <div class="time-item">
                                    <div class="label">Check In</div>
                                    <div class="value <?= $checkIn ? '' : 'empty'; ?>">
                                        <?= $checkIn ? date('h:i A', strtotime($checkIn)) : '—'; ?>
                                    </div>
                                </div>
                                <div class="time-item">
                                    <div class="label">Check Out</div>
                                    <div class="value <?= $checkOut ? '' : 'empty'; ?>">
                                        <?= $checkOut ? date('h:i A', strtotime($checkOut)) : '—'; ?>
                                    </div>
                                </div>
                                <div class="time-item">
                                    <div class="label">Hours</div>
                                    <div class="value <?= ($checkIn && $checkOut) ? '' : 'empty'; ?>">
                                        <?php 
                                        if ($checkIn && $checkOut) {
                                            $diff = strtotime($checkOut) - strtotime($checkIn);
                                            $hours = floor($diff / 3600);
                                            $mins = floor(($diff % 3600) / 60);
                                            echo $hours . 'h ' . $mins . 'm';
                                        } else {
                                            echo '—';
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.querySelector('.sidebar-overlay').classList.toggle('active');
}
</script>
</body>
</html>
