<?php
require_once '../../includes/auth_check.php';
require_once '../../includes/db.php';

$pdo = getPDO();

$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$demoMessage = '';
$demoMessageType = 'success';

// App settings for demo time
$pdo->exec("\n    CREATE TABLE IF NOT EXISTS app_settings (\n        setting_key VARCHAR(64) PRIMARY KEY,\n        setting_value VARCHAR(255) NOT NULL\n    )\n");

$settings = [];
$stmt = $pdo->prepare("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('demo_time_enabled', 'demo_time_value', 'demo_time_anchor')");
$stmt->execute();
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_demo_time') {
    if (!$isAdmin) {
        http_response_code(403);
        exit;
    }

    $demoCloseRequested = isset($_POST['demo_close']);
    $demoActionProvided = array_key_exists('demo_enabled', $_POST);
    $demoEnabledInput = $settings['demo_time_enabled'] ?? '0';
    if ($demoActionProvided) {
        $demoEnabledInput = ($_POST['demo_enabled'] ?? '0') === '1' ? '1' : '0';
    }
    $demoTimeInput = trim($_POST['demo_time'] ?? '');
    if ($demoTimeInput !== '' && strlen($demoTimeInput) === 5) {
        $demoTimeInput .= ':00';
    }

    if ($demoTimeInput !== '' && !preg_match('/^\d{2}:\d{2}:\d{2}$/', $demoTimeInput)) {
        $demoMessage = 'Invalid time format. Please use HH:MM or HH:MM:SS.';
        $demoMessageType = 'error';
    } else {
        if ($demoTimeInput === '') {
            $demoTimeInput = $settings['demo_time_value'] ?? date('H:i:s');
        }

        $upsert = $pdo->prepare("\n            INSERT INTO app_settings (setting_key, setting_value)\n            VALUES (?, ?)\n            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)\n        ");
        if ($demoActionProvided) {
            $upsert->execute(['demo_time_enabled', $demoEnabledInput]);
        }
        $upsert->execute(['demo_time_value', $demoTimeInput]);
        if ($demoActionProvided) {
            if ($demoEnabledInput === '1') {
                $demoMessage = 'Demo time enabled.';
                $demoMessageType = 'success';
            } else {
                $demoMessage = 'Demo time disabled.';
                $demoMessageType = 'success';
            }
        } else {
            $demoMessage = '';
        }
    }

    if ($demoActionProvided && $demoEnabledInput === '1') {
        $upsert->execute(['demo_time_anchor', (string)time()]);
    }

    if ($demoCloseRequested) {
        $demoMessage = '';
    }

    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('demo_time_enabled', 'demo_time_value', 'demo_time_anchor')");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

$demoEnabled = ($settings['demo_time_enabled'] ?? '0') === '1';
$demoTimeValue = $settings['demo_time_value'] ?? '';
$demoTimeAnchor = isset($settings['demo_time_anchor']) ? (int)$settings['demo_time_anchor'] : 0;
if ($demoTimeValue !== '' && strlen($demoTimeValue) === 5) {
    $demoTimeValue .= ':00';
}

function timeToSeconds($timeValue) {
    $parts = explode(':', $timeValue);
    $hours = (int)($parts[0] ?? 0);
    $minutes = (int)($parts[1] ?? 0);
    $seconds = (int)($parts[2] ?? 0);
    return ($hours * 3600) + ($minutes * 60) + $seconds;
}

if ($demoEnabled && $demoTimeValue) {
    $baseSeconds = timeToSeconds($demoTimeValue);
    $elapsed = $demoTimeAnchor > 0 ? (time() - $demoTimeAnchor) : 0;
    $effectiveSeconds = ($baseSeconds + $elapsed) % 86400;
    if ($effectiveSeconds < 0) {
        $effectiveSeconds += 86400;
    }
    $effectiveTime = gmdate('H:i:s', $effectiveSeconds);
} else {
    $effectiveTime = date('H:i:s');
}


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
.live-clock { margin-left: auto; font-size: 14px; font-weight: 700; color: var(--text-muted); background: var(--bg-card); border: 1px solid var(--border-light); padding: 6px 12px; border-radius: 999px; letter-spacing: 0.6px; min-width: 120px; text-align: center; }
.menu-button { display: flex; align-items: center; gap: 14px; width: 100%; padding: 14px 16px; border-radius: 12px; border: 1px dashed var(--border-accent); background: var(--bg-input); color: var(--text-main); font-weight: 600; font-size: 14px; cursor: pointer; transition: all 0.3s; }
.menu-button:hover { background: var(--bg-card); box-shadow: var(--shadow-sm); transform: translateX(4px); }
.demo-card { display: none; }
.demo-card.open { display: block; }
.demo-form { display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end; }
.demo-form .field { display: flex; flex-direction: column; gap: 6px; min-width: 180px; }
.demo-form label { font-size: 12px; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.6px; }
.demo-form input[type="time"] { padding: 10px 12px; border: 2px solid var(--border-light); border-radius: 10px; font-size: 14px; background: var(--bg-card); }
.demo-form input[type="time"]:focus { outline: none; border-color: var(--primary-orange); }
.demo-form .actions-group { display: flex; align-items: center; gap: 12px; margin-left: auto; }
.demo-status { font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 999px; text-transform: uppercase; letter-spacing: 0.8px; }
.demo-status.active { background: #dcfce7; color: #16a34a; border: 1px solid #86efac; }
.demo-status.off { background: #f3f4f6; color: #6b7280; border: 1px solid #e5e7eb; }
.demo-close { width: 28px; height: 28px; border: none; border-radius: 8px; background: #fef2f2; color: #dc2626; cursor: pointer; font-size: 16px; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s ease; }
.demo-close:hover { background: #fee2e2; transform: translateY(-1px); }
.demo-message { margin-top: 12px; font-size: 13px; font-weight: 600; }
.demo-message.success { color: #16a34a; }
.demo-message.error { color: #dc2626; }
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
        <?php if ($isAdmin): ?>
            <button type="button" class="menu-button" onclick="toggleDemoPanel()">🧪 Demo Time</button>
        <?php endif; ?>
        <div class="sidebar-footer">
            <a href="../../logout.php"><span class="icon">🚪</span> Logout</a>
        </div>
    </aside>

    <main class="main">
        <div class="top-bar">
            <button class="hamburger" onclick="toggleSidebar()"><span></span><span></span><span></span></button>
            <h1 class="page-title">Attendance</h1>
            <div class="live-clock" id="attendanceClock" aria-live="polite"></div>
        </div>
        <p class="subtitle">Monitor employee attendance for <?= date('F d, Y', strtotime($selectedDate)); ?></p>

        <?php if ($isAdmin): ?>
            <div class="card demo-card <?= $demoMessage ? 'open' : ''; ?>" id="demoPanel">
                <div class="card-header">
                    <h2>🧪 Demo Time Control</h2>
                    <span class="demo-status <?= $demoEnabled ? 'active' : 'off'; ?>">
                        <?= $demoEnabled ? 'Demo Active' : 'Normal Mode'; ?>
                    </span>
                    <form method="POST" style="margin-left: auto;" onsubmit="return closeDemoPanel();">
                        <input type="hidden" name="action" value="update_demo_time">
                        <input type="hidden" name="demo_enabled" value="0">
                        <input type="hidden" name="demo_close" value="1">
                        <button type="submit" class="demo-close" title="Exit demo mode" aria-label="Exit demo mode">✕</button>
                    </form>
                </div>
                <form method="POST" class="demo-form">
                    <input type="hidden" name="action" value="update_demo_time">
                    <div class="field">
                        <label for="demoTimeInput">Demo Time</label>
                        <input type="time" id="demoTimeInput" name="demo_time" step="1" value="<?= htmlspecialchars($demoTimeValue ?: date('H:i:s')); ?>">
                    </div>
                    <div class="actions-group">
                        <button type="submit" class="btn btn-primary" name="demo_enabled" value="1">Enable Demo</button>
                        <button type="submit" class="btn btn-secondary" name="demo_enabled" value="0">Disable Demo</button>
                    </div>
                </form>
                <p style="margin-top: 10px; font-size: 12px; color: var(--text-muted);">When enabled, all attendance logic uses the demo time.</p>
                <?php if ($demoMessage): ?>
                    <div class="demo-message <?= htmlspecialchars($demoMessageType); ?>"><?= htmlspecialchars($demoMessage); ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

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
const DEMO_TIME_ENABLED = <?= $demoEnabled ? 'true' : 'false'; ?>;
const DEMO_TIME_VALUE = <?= json_encode($demoTimeValue); ?>;
const DEMO_TIME_ANCHOR = <?= json_encode($demoTimeAnchor); ?>;

function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.querySelector('.sidebar-overlay').classList.toggle('active');
}

function toggleDemoPanel() {
    const panel = document.getElementById('demoPanel');
    if (!panel) return;
    panel.classList.toggle('open');
}

function closeDemoPanel() {
    const panel = document.getElementById('demoPanel');
    if (panel) {
        panel.classList.remove('open');
    }
    return true;
}

function timeToSeconds(timeValue) {
    const parts = timeValue.split(':');
    const hours = parseInt(parts[0] || '0', 10);
    const minutes = parseInt(parts[1] || '0', 10);
    const seconds = parseInt(parts[2] || '0', 10);
    return (hours * 3600) + (minutes * 60) + seconds;
}

function getEffectiveNow() {
    if (!DEMO_TIME_ENABLED || !DEMO_TIME_VALUE) {
        return new Date();
    }

    const anchor = DEMO_TIME_ANCHOR ? new Date(DEMO_TIME_ANCHOR * 1000) : new Date();
    const elapsedSeconds = Math.floor((Date.now() - anchor.getTime()) / 1000);
    const baseSeconds = timeToSeconds(DEMO_TIME_VALUE);
    const effectiveSeconds = (baseSeconds + elapsedSeconds) % 86400;

    const effective = new Date();
    effective.setHours(0, 0, 0, 0);
    effective.setSeconds(effectiveSeconds);
    return effective;
}

function formatClockTime(date) {
    let hours = date.getHours();
    const minutes = date.getMinutes();
    const seconds = date.getSeconds();
    const period = hours >= 12 ? 'pm' : 'am';
    hours = hours % 12;
    hours = hours ? hours : 12;

    const hh = String(hours).padStart(2, '0');
    const mm = String(minutes).padStart(2, '0');
    const ss = String(seconds).padStart(2, '0');

    return `${hh}:${mm}:${ss} ${period}`;
}

function updateAttendanceClock() {
    const clock = document.getElementById('attendanceClock');
    if (!clock) return;
    clock.textContent = formatClockTime(getEffectiveNow());
}

document.addEventListener('DOMContentLoaded', function() {
    updateAttendanceClock();
    setInterval(updateAttendanceClock, 1000);
});
</script>
</body>
</html>
