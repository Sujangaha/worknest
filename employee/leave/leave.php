<?php
require_once '../../includes/auth_check.php';
require_once '../../includes/db.php';

$pdo = getPDO();
$userId = $_SESSION['user_id'];

$message = '';
$messageType = '';
$showForm = false;

// ===== ATTENDANCE THRESHOLD CONSTANT =====
define('ATTENDANCE_THRESHOLD', 75); // Minimum attendance % required for leave

// ===== FUNCTION: Calculate Attendance Percentage (Based on Recorded Days) =====
function calculateAttendancePercentage($pdo, $userId) {
    // Count total days where attendance was recorded for this employee
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total_days
        FROM attendance 
        WHERE user_id = ?
    ");
    $stmt->execute([$userId]);
    $result = $stmt->fetch();
    $totalDays = (int)($result['total_days'] ?? 0);
    
    // If no attendance records, return 100% (new employee)
    if ($totalDays == 0) {
        return ['percentage' => 100, 'present' => 0, 'total' => 0];
    }
    
    // Count days employee was present (early + present + halfday + late count as present)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as present_days
        FROM attendance 
        WHERE user_id = ? 
        AND status IN ('present', 'late', 'early', 'halfday')
    ");
    $stmt->execute([$userId]);
    $result = $stmt->fetch();
    $presentDays = (int)($result['present_days'] ?? 0);
    
    // Calculate percentage
    $percentage = round(($presentDays / $totalDays) * 100, 1);
    
    return [
        'percentage' => $percentage,
        'present' => $presentDays,
        'total' => $totalDays
    ];
}

// Get current month's attendance for the logged-in user
$attendanceStats = calculateAttendancePercentage($pdo, $userId);
$attendancePercentage = $attendanceStats['percentage'];
$isEligibleForLeave = $attendancePercentage >= ATTENDANCE_THRESHOLD;

// ===== LEAVE ALLOWANCES =====
$leaveAllowances = [
    'casual_leave' => 2,
    'sick_leave' => 3,
    'paid_leave' => 2,
    'unpaid_leave' => -1
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
    'casual_leave' => $leaveAllowances['casual_leave'] - (int)($leavesTaken['casual_leave'] ?? 0),
    'sick_leave' => $leaveAllowances['sick_leave'] - (int)($leavesTaken['sick_leave'] ?? 0),
    'paid_leave' => $leaveAllowances['paid_leave'] - (int)($leavesTaken['paid_leave'] ?? 0),
    'unpaid_leave' => -1
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'apply';
    
    // Cancel leave request
    if ($action === 'cancel') {
        $leaveId = (int)$_POST['leave_id'];
        $stmt = $pdo->prepare("SELECT * FROM leaves WHERE id = ? AND user_id = ? AND status = 'pending'");
        $stmt->execute([$leaveId, $userId]);
        $leave = $stmt->fetch();
        
        if ($leave) {
            $stmt = $pdo->prepare("DELETE FROM leaves WHERE id = ?");
            $stmt->execute([$leaveId]);
            $_SESSION['leave_message'] = 'Leave request cancelled successfully!';
            $_SESSION['leave_message_type'] = 'success';
        } else {
            $_SESSION['leave_message'] = 'Unable to cancel this leave request.';
            $_SESSION['leave_message_type'] = 'error';
        }
        header('Location: leave.php');
        exit;
    }
    
    // Apply for leave
    if ($action === 'apply') {
        $leaveType = $_POST['leave_type'] ?? '';
        $startDate = $_POST['start_date'] ?? '';
        $endDate = $_POST['end_date'] ?? '';
        $reason = trim($_POST['reason'] ?? '');
        
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        
        // Calculate requested days EXCLUDING Saturdays (Holiday)
        $requestedDays = 0;
        $currentTimestamp = strtotime($startDate);
        $endTimestamp = strtotime($endDate);
        while ($currentTimestamp <= $endTimestamp) {
            // date('N') returns 6 for Saturday - skip Saturdays
            if (date('N', $currentTimestamp) != 6) {
                $requestedDays++;
            }
            $currentTimestamp = strtotime('+1 day', $currentTimestamp);
        }
        
        // Check if there's already a pending leave request
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM leaves WHERE user_id = ? AND status = 'pending'");
        $stmt->execute([$userId]);
        $hasPendingLeave = $stmt->fetchColumn() > 0;
        
        if (empty($leaveType) || empty($startDate) || empty($endDate) || empty($reason)) {
            $message = 'Please fill in all fields.';
            $messageType = 'error';
            $showForm = true;
        } elseif ($hasPendingLeave) {
            $message = 'You already have a pending leave request. Please wait for it to be processed before applying for another.';
            $messageType = 'error';
            $showForm = true;
        } elseif ($startDate > $endDate) {
            $message = 'Start date cannot be after end date.';
            $messageType = 'error';
            $showForm = true;
        } elseif ($startDate < date('Y-m-d')) {
            $message = 'Cannot apply for leave in the past.';
            $messageType = 'error';
            $showForm = true;
        } elseif ($endDate > date('Y-m-t', strtotime('+2 months'))) {
            // Y-m-t gives last day of month, 2 months from now
            $maxDate = date('F Y', strtotime('+2 months'));
            $message = 'Leave can only be applied until the end of ' . $maxDate . '.';
            $messageType = 'error';
            $showForm = true;
        } elseif ($requestedDays <= 0) {
            $message = 'No valid leave days selected (Saturdays are holidays and cannot be counted as leave).';
            $messageType = 'error';
            $showForm = true;
        } elseif (!$isEligibleForLeave && $leaveType !== 'sick_leave') {
            // ATTENDANCE THRESHOLD CHECK - Block leave if attendance < 75% (except sick leave)
            $message = 'Leave request denied. Your attendance is ' . $attendancePercentage . '% which is below the required ' . ATTENDANCE_THRESHOLD . '%. Please improve your attendance before applying for leave.';
            $messageType = 'error';
            $showForm = false; // Close the form if leave is denied due to low attendance
        } else {
            $remainingBalance = $leaveBalance[$leaveType] ?? 0;
            
            if ($leaveType !== 'unpaid_leave' && $remainingBalance <= 0) {
                $message = 'You have exhausted your ' . ucfirst(str_replace('_', ' ', $leaveType)) . ' balance.';
                $messageType = 'error';
                $showForm = true;
            } elseif ($leaveType !== 'unpaid_leave' && $requestedDays > $remainingBalance) {
                $message = 'Insufficient leave balance. You only have ' . $remainingBalance . ' day(s) remaining.';
                $messageType = 'error';
                $showForm = true;
            } else {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM leaves 
                    WHERE user_id = ? AND status != 'rejected'
                    AND ((start_date BETWEEN ? AND ?) OR (end_date BETWEEN ? AND ?) OR (start_date <= ? AND end_date >= ?))
                ");
                $stmt->execute([$userId, $startDate, $endDate, $startDate, $endDate, $startDate, $endDate]);
                
                if ($stmt->fetchColumn() > 0) {
                    $message = 'You already have a leave request for these dates.';
                    $messageType = 'error';
                    $showForm = true;
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO leaves (user_id, leave_type, start_date, end_date, reason, status, created_at) 
                        VALUES (?, ?, ?, ?, ?, 'pending', NOW())
                    ");
                    $stmt->execute([$userId, $leaveType, $startDate, $endDate, $reason]);
                    
                    $_SESSION['leave_message'] = 'Leave request submitted successfully!';
                    $_SESSION['leave_message_type'] = 'success';
                    header('Location: leave.php');
                    exit;
                }
            }
        }
    }
}

// Get messages from session
if (isset($_SESSION['leave_message'])) {
    $message = $_SESSION['leave_message'];
    $messageType = $_SESSION['leave_message_type'];
    unset($_SESSION['leave_message'], $_SESSION['leave_message_type']);
}

// Fetch user's leave requests
$stmt = $pdo->prepare("SELECT * FROM leaves WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$userId]);
$leaveRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recalculate balance
$stmt = $pdo->prepare("
    SELECT leave_type, SUM(DATEDIFF(end_date, start_date) + 1) as days_taken
    FROM leaves WHERE user_id = ? AND status = 'approved' AND YEAR(start_date) = ?
    GROUP BY leave_type
");
$stmt->execute([$userId, $currentYear]);
$leavesTaken = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$leaveBalance = [
    'casual_leave' => $leaveAllowances['casual_leave'] - (int)($leavesTaken['casual_leave'] ?? 0),
    'sick_leave' => $leaveAllowances['sick_leave'] - (int)($leavesTaken['sick_leave'] ?? 0),
    'paid_leave' => $leaveAllowances['paid_leave'] - (int)($leavesTaken['paid_leave'] ?? 0),
    'unpaid_leave' => -1
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Leave - WorkNest</title>
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
.sidebar-brand .logo { width: 48px; height: 48px; background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-light)); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 24px; color: #fff; box-shadow: var(--shadow-orange); }
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

/* Page Header */
.page-header-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }

/* Leave Balance Cards */
.balance-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 16px; }
.balance-card { background: var(--bg-card); border: 1px solid var(--border-light); border-radius: 14px; padding: 20px; text-align: center; position: relative; overflow: hidden; }
.balance-card::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 4px; }
.balance-card.attendance::before { background: linear-gradient(90deg, var(--primary-orange), var(--primary-orange-light)); }
.balance-card.casual::before { background: #3b82f6; }
.balance-card.sick::before { background: #ef4444; }
.balance-card.paid::before { background: #16a34a; }
.balance-card.unpaid::before { background: #6b7280; }
.balance-card .type { font-size: 13px; color: var(--text-muted); margin-bottom: 8px; }
.balance-card .count { font-size: 32px; font-weight: 800; }
.balance-card .count.available { color: #16a34a; }
.balance-card .count.low { color: #f59e0b; }
.balance-card .count.exhausted { color: #dc2626; }
.balance-card .count.unlimited { color: #16a34a; font-size: 16px; }
.balance-card .label { font-size: 11px; color: var(--text-light); margin-top: 4px; }
.balance-card.disabled { opacity: 0.6; }
.balance-card .exhausted-badge { position: absolute; top: 10px; right: 10px; background: #fef2f2; color: #dc2626; font-size: 10px; padding: 4px 8px; border-radius: 10px; font-weight: 600; }
.balance-card .eligible-badge { position: absolute; top: 10px; right: 10px; background: #dcfce7; color: #16a34a; font-size: 10px; padding: 4px 8px; border-radius: 10px; font-weight: 600; }
.balance-card .ineligible-badge { position: absolute; top: 10px; right: 10px; background: #fef2f2; color: #dc2626; font-size: 10px; padding: 4px 8px; border-radius: 10px; font-weight: 600; }
.balance-card .count.eligible { color: #16a34a; }
.balance-card .count.ineligible { color: #dc2626; }

.alert { padding: 14px 20px; border-radius: 12px; margin-bottom: 24px; font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 10px; }
.alert.success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
.alert.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

.card { background: var(--bg-card); border: 1px solid var(--border-light); border-radius: 16px; padding: 24px; margin-bottom: 24px; }
.card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid var(--border-light); }
.card-header h2 { font-size: 18px; font-weight: 700; }

/* Form Card - Hidden by default */
.form-card { display: none; animation: slideDown 0.3s ease; }
.form-card.active { display: block; }
@keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }

.btn-close { background: var(--bg-input); border: 1px solid var(--border-accent); width: 36px; height: 36px; border-radius: 10px; cursor: pointer; font-size: 18px; display: flex; align-items: center; justify-content: center; color: var(--text-muted); }
.btn-close:hover { background: #fef2f2; color: #dc2626; border-color: #fecaca; }

.form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }
.form-group { margin-bottom: 0; }
.form-group.full-width { grid-column: span 2; }
.form-group label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 8px; }
.form-group input, .form-group select, .form-group textarea {
    width: 100%; padding: 12px 14px; border: 2px solid var(--border-light); border-radius: 10px;
    font-size: 14px; background: var(--bg-input); transition: all 0.2s;
}
.form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: var(--primary-orange); }
.form-group textarea { min-height: 100px; resize: vertical; }
.form-group select option:disabled { color: #ccc; }
.form-group .balance-hint { font-size: 11px; color: var(--text-light); margin-top: 4px; }
.form-group .balance-hint.warning { color: #f59e0b; }
.form-group .balance-hint.error { color: #dc2626; }

.btn { padding: 12px 24px; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; text-decoration: none; }
.btn-primary { background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-light)); color: #fff; }
.btn-primary:hover { transform: translateY(-2px); box-shadow: var(--shadow-orange); }
.btn-secondary { background: var(--bg-input); color: var(--text-main); border: 1px solid var(--border-accent); }
.btn-secondary:hover { background: var(--bg-card); }
.form-actions { display: flex; gap: 12px; margin-top: 20px; }

/* Leave Requests */
.requests-list { display: flex; flex-direction: column; gap: 12px; }
.request-card { background: var(--bg-input); border: 1px solid var(--border-light); border-radius: 12px; padding: 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
.request-card:hover { border-color: var(--border-accent); }
.request-info { flex: 1; min-width: 200px; }
.request-type { font-size: 15px; font-weight: 600; color: var(--text-main); margin-bottom: 4px; }
.request-dates { font-size: 13px; color: var(--text-muted); }
.request-reason { font-size: 12px; color: var(--text-light); margin-top: 4px; }
.request-actions { display: flex; align-items: center; gap: 12px; }
.request-status { padding: 6px 14px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
.request-status.pending { background: #fef3c7; color: #b45309; }
.request-status.approved { background: #dcfce7; color: #16a34a; }
.request-status.rejected { background: #fef2f2; color: #dc2626; }

.btn-cancel { padding: 6px 12px; border-radius: 8px; font-size: 12px; font-weight: 600; background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; }
.btn-cancel:hover { background: #fee2e2; border-color: #dc2626; }

.empty-state { text-align: center; padding: 40px; color: var(--text-muted); }
.empty-state .icon { font-size: 48px; margin-bottom: 12px; opacity: 0.5; }

@media (max-width: 1024px) {
    .hamburger { display: flex; }
    .sidebar { transform: translateX(-100%); }
    .sidebar.open { transform: translateX(0); }
    .main { margin-left: 0; }
    .balance-grid { grid-template-columns: repeat(3, 1fr); }
    .form-grid { grid-template-columns: 1fr; }
    .form-group.full-width { grid-column: span 1; }
}
@media (max-width: 768px) {
    .main { padding: 16px; }
    .balance-grid { grid-template-columns: 1fr; }
    .page-header-row { flex-direction: column; align-items: stretch; }
}

/* Flatpickr Saturday styling */
.flatpickr-day.saturday-holiday {
    background: #fef2f2 !important;
    color: #dc2626 !important;
    cursor: not-allowed !important;
    pointer-events: none;
}
.flatpickr-day.saturday-holiday:hover {
    background: #fee2e2 !important;
}
.saturday-note {
    background: #fef2f2;
    padding: 8px 12px;
    font-size: 11px;
    color: #dc2626;
    text-align: center;
    border-top: 1px solid #fecaca;
    font-weight: 500;
}
</style>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
</head>
<body>
<div class="sidebar-overlay" onclick="toggleSidebar()"></div>

<div class="wrapper">
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
            <a href="leave.php" class="active"><span class="icon">🗓️</span> Leave</a>
            <a href="../notice/notices.php"><span class="icon">📢</span> Notices</a>
        </nav>
        <div class="sidebar-footer">
            <a href="../../logout.php"><span class="icon">🚪</span> Logout</a>
        </div>
    </aside>

    <main class="main">
        <div class="top-bar">
            <button class="hamburger" onclick="toggleSidebar()"><span></span><span></span><span></span></button>
            <h1 class="page-title">Leave</h1>
        </div>
        <p class="subtitle">Apply for leave and track your requests</p>

        <?php if ($message): ?>
            <div class="alert <?= $messageType; ?>"><?= $messageType === 'success' ? '✅' : '❌'; ?> <?= htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <!-- Page Header with Balance and Apply Button -->
        <div class="page-header-row">
            <div class="balance-grid">
                <!-- Attendance Status Card -->
                <div class="balance-card attendance <?= !$isEligibleForLeave ? 'disabled' : ''; ?>">
                    <?php if ($isEligibleForLeave): ?>
                        <span class="eligible-badge">Eligible</span>
                    <?php else: ?>
                        <span class="ineligible-badge">Ineligible</span>
                    <?php endif; ?>
                    <div class="type">Monthly Attendance</div>
                    <div class="count <?= $isEligibleForLeave ? 'eligible' : 'ineligible'; ?>"><?= $attendancePercentage; ?>%</div>
                    <div class="label"><?= $attendanceStats['present']; ?> of <?= $attendanceStats['total']; ?> days (min <?= ATTENDANCE_THRESHOLD; ?>%)</div>
                </div>
                <div class="balance-card casual <?= $leaveBalance['casual_leave'] <= 0 ? 'disabled' : ''; ?>">
                    <?php if ($leaveBalance['casual_leave'] <= 0): ?><span class="exhausted-badge">Exhausted</span><?php endif; ?>
                    <div class="type">Casual Leave</div>
                    <div class="count <?= $leaveBalance['casual_leave'] <= 0 ? 'exhausted' : 'available'; ?>"><?= max(0, $leaveBalance['casual_leave']); ?></div>
                    <div class="label">of <?= $leaveAllowances['casual_leave']; ?> day</div>
                </div>
                <div class="balance-card sick <?= $leaveBalance['sick_leave'] <= 0 ? 'disabled' : ''; ?>">
                    <?php if ($leaveBalance['sick_leave'] <= 0): ?><span class="exhausted-badge">Exhausted</span><?php endif; ?>
                    <div class="type">Sick Leave</div>
                    <div class="count <?= $leaveBalance['sick_leave'] <= 0 ? 'exhausted' : ($leaveBalance['sick_leave'] <= 1 ? 'low' : 'available'); ?>"><?= max(0, $leaveBalance['sick_leave']); ?></div>
                    <div class="label">of <?= $leaveAllowances['sick_leave']; ?> days</div>
                </div>
                <div class="balance-card paid <?= $leaveBalance['paid_leave'] <= 0 ? 'disabled' : ''; ?>">
                    <?php if ($leaveBalance['paid_leave'] <= 0): ?><span class="exhausted-badge">Exhausted</span><?php endif; ?>
                    <div class="type">Paid Leave</div>
                    <div class="count <?= $leaveBalance['paid_leave'] <= 0 ? 'exhausted' : 'available'; ?>"><?= max(0, $leaveBalance['paid_leave']); ?></div>
                    <div class="label">of <?= $leaveAllowances['paid_leave']; ?> days</div>
                </div>
                <div class="balance-card unpaid">
                    <div class="type">Unpaid Leave</div>
                    <div class="count unlimited">Unlimited</div>
                    <div class="label">Always available</div>
                </div>
            </div>
        </div>

        <!-- Apply Button -->
        <div style="margin-bottom: 24px;">
            <button class="btn btn-primary" onclick="toggleForm()" id="applyBtn">
                <span id="btnIcon">➕</span> <span id="btnText">Apply for Leave</span>
            </button>
        </div>

        <!-- Apply Leave Form (Hidden by default) -->
        <div class="card form-card <?= $showForm ? 'active' : ''; ?>" id="leaveFormCard">
            <div class="card-header">
                <h2>📝 Apply for Leave</h2>
                <button type="button" class="btn-close" onclick="toggleForm()">✕</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="apply">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Leave Type *</label>
                        <select name="leave_type" id="leaveType" required onchange="updateBalanceHint()">
                            <option value="">Select leave type</option>
                            <option value="casual_leave" <?= $leaveBalance['casual_leave'] <= 0 ? 'disabled' : ''; ?>>
                                Casual Leave <?= $leaveBalance['casual_leave'] <= 0 ? '(Exhausted)' : '(' . $leaveBalance['casual_leave'] . ' day left)'; ?>
                            </option>
                            <option value="sick_leave" <?= $leaveBalance['sick_leave'] <= 0 ? 'disabled' : ''; ?>>
                                Sick Leave <?= $leaveBalance['sick_leave'] <= 0 ? '(Exhausted)' : '(' . $leaveBalance['sick_leave'] . ' days left)'; ?>
                            </option>
                            <option value="paid_leave" <?= $leaveBalance['paid_leave'] <= 0 ? 'disabled' : ''; ?>>
                                Paid Leave <?= $leaveBalance['paid_leave'] <= 0 ? '(Exhausted)' : '(' . $leaveBalance['paid_leave'] . ' days left)'; ?>
                            </option>
                            <option value="unpaid_leave">Unpaid Leave (Unlimited)</option>
                        </select>
                        <div class="balance-hint" id="balanceHint"></div>
                    </div>
                    <div class="form-group">
                        <label>Start Date *</label>
                        <input type="date" name="start_date" min="<?= date('Y-m-d'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>End Date *</label>
                        <input type="date" name="end_date" min="<?= date('Y-m-d'); ?>" required>
                    </div>
                    <div class="form-group full-width">
                        <label>Reason *</label>
                        <textarea name="reason" placeholder="Please provide a reason for your leave request..." required></textarea>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">📤 Submit Request</button>
                    <button type="button" class="btn btn-secondary" onclick="toggleForm()">Cancel</button>
                </div>
            </form>
        </div>

        <!-- Leave Requests (Hidden when form is open) -->
        <div class="card" id="leaveListCard">
            <div class="card-header">
                <h2>📋 My Leave Requests</h2>
            </div>
            <?php if (empty($leaveRequests)): ?>
                <div class="empty-state">
                    <div class="icon">🗓️</div>
                    <p>No leave requests yet</p>
                </div>
            <?php else: ?>
                <div class="requests-list">
                    <?php foreach ($leaveRequests as $request): ?>
                        <div class="request-card">
                            <div class="request-info">
                                <div class="request-type"><?= ucfirst(str_replace('_', ' ', $request['leave_type'])); ?></div>
                                <div class="request-dates">
                                    📅 <?= date('M d, Y', strtotime($request['start_date'])); ?> - <?= date('M d, Y', strtotime($request['end_date'])); ?>
                                    <?php $days = (new DateTime($request['start_date']))->diff(new DateTime($request['end_date']))->days + 1; ?>
                                    (<?= $days; ?> day<?= $days > 1 ? 's' : ''; ?>)
                                </div>
                                <div class="request-reason"><?= htmlspecialchars($request['reason']); ?></div>
                            </div>
                            <div class="request-actions">
                                <span class="request-status <?= $request['status']; ?>"><?= $request['status']; ?></span>
                                <?php if ($request['status'] === 'pending'): ?>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to cancel this leave request?');">
                                        <input type="hidden" name="action" value="cancel">
                                        <input type="hidden" name="leave_id" value="<?= $request['id']; ?>">
                                        <button type="submit" class="btn-cancel">✕ Cancel</button>
                                    </form>
                                <?php endif; ?>
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

function toggleForm() {
    const formCard = document.getElementById('leaveFormCard');
    const listCard = document.getElementById('leaveListCard');
    const btnIcon = document.getElementById('btnIcon');
    const btnText = document.getElementById('btnText');
    
    formCard.classList.toggle('active');
    
    if (formCard.classList.contains('active')) {
        btnIcon.textContent = '✕';
        btnText.textContent = 'Cancel';
        listCard.style.display = 'none';
        formCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } else {
        btnIcon.textContent = '➕';
        btnText.textContent = 'Apply for Leave';
        listCard.style.display = 'block';
        // Reset form
        document.querySelector('#leaveFormCard form').reset();
        document.getElementById('balanceHint').textContent = '';
    }
}

const leaveBalances = {
    casual_leave: <?= $leaveBalance['casual_leave']; ?>,
    sick_leave: <?= $leaveBalance['sick_leave']; ?>,
    paid_leave: <?= $leaveBalance['paid_leave']; ?>,
    unpaid_leave: -1
};

function updateBalanceHint() {
    const select = document.getElementById('leaveType');
    const hint = document.getElementById('balanceHint');
    const value = select.value;
    
    if (!value) {
        hint.textContent = '';
        hint.className = 'balance-hint';
        return;
    }
    
    if (value === 'unpaid_leave') {
        hint.textContent = '✓ Unlimited unpaid leave available';
        hint.className = 'balance-hint';
    } else {
        const balance = leaveBalances[value];
        if (balance <= 0) {
            hint.textContent = '⚠ This leave type is exhausted.';
            hint.className = 'balance-hint error';
        } else if (balance === 1) {
            hint.textContent = '⚠ Only 1 day remaining';
            hint.className = 'balance-hint warning';
        } else {
            hint.textContent = '✓ ' + balance + ' days available';
            hint.className = 'balance-hint';
        }
    }
}

// Flatpickr configuration - disable Saturdays (shown in red)
// Calculate max date (end of month, 2 months from now)
const maxLeaveDate = new Date();
maxLeaveDate.setMonth(maxLeaveDate.getMonth() + 3); // Go to 3 months ahead
maxLeaveDate.setDate(0); // Go back to last day of previous month (2 months ahead)

const flatpickrConfig = {
    dateFormat: 'Y-m-d',
    minDate: 'today',
    maxDate: maxLeaveDate,
    disableMobile: true,
    disable: [
        function(date) {
            // Disable Saturdays (day 6)
            return date.getDay() === 6;
        }
    ],
    onDayCreate: function(dObj, dStr, fp, dayElem) {
        // Add red styling for Saturdays
        const date = dayElem.dateObj;
        if (date && date.getDay() === 6) {
            dayElem.classList.add('saturday-holiday');
            dayElem.title = 'Saturday - Holiday (Disabled)';
        }
    },
    onReady: function(selectedDates, dateStr, instance) {
        // Add note below calendar
        const calendarContainer = instance.calendarContainer;
        const note = document.createElement('div');
        note.className = 'saturday-note';
        note.innerHTML = '🚫 Saturdays are holidays and cannot be selected';
        calendarContainer.appendChild(note);
    },
    onChange: function() {
        calculateLeaveDays();
    }
};

// Initialize Flatpickr on date inputs
let endDatePicker;
const startDatePicker = flatpickr('input[name="start_date"]', {
    ...flatpickrConfig,
    onChange: function(selectedDates, dateStr) {
        // Update end date min to be >= start date
        if (selectedDates.length > 0) {
            endDatePicker.set('minDate', dateStr);
        }
        calculateLeaveDays();
    }
});

endDatePicker = flatpickr('input[name="end_date"]', flatpickrConfig);

function calculateLeaveDays() {
    const startDate = document.querySelector('input[name="start_date"]').value;
    const endDate = document.querySelector('input[name="end_date"]').value;
    
    if (startDate && endDate) {
        let count = 0;
        let current = new Date(startDate + 'T00:00:00');
        const end = new Date(endDate + 'T00:00:00');
        let saturdayCount = 0;
        
        while (current <= end) {
            if (current.getDay() === 6) {
                saturdayCount++;
            } else {
                count++;
            }
            current.setDate(current.getDate() + 1);
        }
        
        // Update balance hint to show excluded Saturdays
        updateBalanceHint(); // Refresh the leave type balance first
        
        const hint = document.getElementById('balanceHint');
        setTimeout(() => {
            const existingHint = hint.innerHTML;
            if (saturdayCount > 0) {
                hint.innerHTML = existingHint + '<br><span style="color: #dc2626;">📅 ' + saturdayCount + ' Saturday(s) skipped - <strong>' + count + ' leave day(s)</strong> will be counted</span>';
            } else if (count > 0) {
                hint.innerHTML = existingHint + '<br><span style="color: #16a34a;">✓ <strong>' + count + ' leave day(s)</strong> will be counted</span>';
            }
        }, 10);
    }
}
</script>
</body>
</html>
