<?php
require_once '../../includes/auth_check.php';
require_once '../../includes/db.php';

$pdo = getPDO();

// ===== ATTENDANCE THRESHOLD, CONCURRENCY & LEAVE ALLOWANCES =====
define('ATTENDANCE_THRESHOLD', 75);
// Maximum number of concurrent approved leaves allowed per day (team slots)
define('MAX_CONCURRENT_LEAVES_PER_DAY', 2);
$leaveAllowances = [
    'casual_leave' => 2,
    'sick_leave' => 3,
    'paid_leave' => 2,
    'unpaid_leave' => -1 // Unlimited
];

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
    
    // Count days employee was present (early + present + late + halfday count as present)
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

// ===== FUNCTION: Get Remaining Leave Balance =====
function getRemainingBalance($pdo, $userId, $leaveType, $leaveAllowances) {
    $currentYear = date('Y');
    $stmt = $pdo->prepare("
        SELECT SUM(DATEDIFF(end_date, start_date) + 1) as days_taken
        FROM leaves 
        WHERE user_id = ? AND leave_type = ? AND status = 'approved' AND YEAR(start_date) = ?
    ");
    $stmt->execute([$userId, $leaveType, $currentYear]);
    $taken = (int)($stmt->fetchColumn() ?? 0);
    
    if ($leaveType === 'unpaid_leave') return -1; // Unlimited
    return $leaveAllowances[$leaveType] - $taken;
}

// ===== HELPER: Days Since Last Approved Leave =====
function getDaysSinceLastLeave($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT MAX(end_date) FROM leaves WHERE user_id = ? AND status = 'approved'");
    $stmt->execute([$userId]);
    $lastEnd = $stmt->fetchColumn();
    if (!$lastEnd) {
        // If employee never took leave, give them a large value so they get priority
        return 365;
    }
    $lastDate = new DateTime($lastEnd);
    $today = new DateTime();
    return $today->diff($lastDate)->days;
}

// ===== HELPER: Total Leave Days Taken This Year =====
function getLeaveDaysTakenThisYear($pdo, $userId) {
    $currentYear = date('Y');
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(DATEDIFF(end_date, start_date) + 1), 0) FROM leaves WHERE user_id = ? AND status = 'approved' AND YEAR(start_date) = ?");
    $stmt->execute([$userId, $currentYear]);
    return (int)$stmt->fetchColumn();
}

// ===== HELPER: Check Per-Day Slot Availability For A Request =====
function canApproveInSlots($pdo, $request, $maxConcurrentPerDay) {
    $start = new DateTime($request['start_date']);
    $end = new DateTime($request['end_date']);

    // Iterate inclusive over each day in the requested range
    $current = clone $start;
    while ($current <= $end) {
        $dateStr = $current->format('Y-m-d');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM leaves WHERE status = 'approved' AND start_date <= ? AND end_date >= ?");
        $stmt->execute([$dateStr, $dateStr]);
        $approvedOnDate = (int)$stmt->fetchColumn();
        if ($approvedOnDate >= $maxConcurrentPerDay) {
            return false; // Slot full for at least one day in the range
        }
        $current->modify('+1 day');
    }
    return true;
}

// ===== AUTO-PROCESS ALGORITHM =====
$autoProcessMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'auto_process') {
    // Fetch all pending leave requests with user info
    $stmt = $pdo->query("
        SELECT l.*, u.username 
        FROM leaves l 
        JOIN users u ON l.user_id = u.id 
        WHERE l.status = 'pending'
    ");
    $pendingRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Weights for priority score P(e)
    $w1 = 1; // days_since_last_leave
    $w2 = 1; // leave_balance_remaining
    $w3 = 1; // leaves_taken_this_year (penalty)

    $requestsWithMetrics = [];
    foreach ($pendingRequests as $request) {
        $userId = $request['user_id'];

        // Attendance
        $attendanceStats = calculateAttendancePercentage($pdo, $userId);
        $request['attendance_percentage'] = $attendanceStats['percentage'];
        $request['attendance_present'] = $attendanceStats['present'];
        $request['attendance_total'] = $attendanceStats['total'];

        // Leave balance for this type
        $remainingBalance = getRemainingBalance($pdo, $userId, $request['leave_type'], $leaveAllowances);
        $request['remaining_balance_auto'] = $remainingBalance;

        // Days since last approved leave
        $daysSinceLastLeave = getDaysSinceLastLeave($pdo, $userId);

        // Total leave days taken this year
        $daysTakenThisYear = getLeaveDaysTakenThisYear($pdo, $userId);

        // Priority score (Round Robin weighted variant)
        $priority = $w1 * $daysSinceLastLeave + $w2 * max(0, $remainingBalance) - $w3 * $daysTakenThisYear;
        $request['priority_score'] = $priority;

        $requestsWithMetrics[] = $request;
    }

    // Sort by priority score descending, then FIFO (created_at ascending)
    usort($requestsWithMetrics, function($a, $b) {
        if ($b['priority_score'] == $a['priority_score']) {
            return strtotime($a['created_at']) <=> strtotime($b['created_at']);
        }
        return $b['priority_score'] <=> $a['priority_score'];
    });

    $approved = 0;
    $rejected = 0;
    $skippedDueToSlots = 0;

    // Process each request in priority/queue order (Round Robin style)
    foreach ($requestsWithMetrics as $index => $request) {
        $leaveType = $request['leave_type'];
        $userId = $request['user_id'];
        $attendancePercentage = $request['attendance_percentage'];
        $requestDays = (new DateTime($request['start_date']))->diff(new DateTime($request['end_date']))->days + 1;

        // 1) Attendance threshold (sick leave exempt)
        if ($attendancePercentage < ATTENDANCE_THRESHOLD && $leaveType !== 'sick_leave') {
            $stmt = $pdo->prepare("UPDATE leaves SET status = 'rejected' WHERE id = ?");
            $stmt->execute([$request['id']]);
            $rejected++;
            continue;
        }

        // 2) Check leave balance
        $remainingBalance = $request['remaining_balance_auto'];
        if ($leaveType !== 'unpaid_leave' && $remainingBalance < $requestDays) {
            $stmt = $pdo->prepare("UPDATE leaves SET status = 'rejected' WHERE id = ?");
            $stmt->execute([$request['id']]);
            $rejected++;
            continue;
        }

        // 3) Check per-day slot availability (max concurrent per day)
        if (!canApproveInSlots($pdo, $request, MAX_CONCURRENT_LEAVES_PER_DAY)) {
            // No slot available for this request's date range; keep it pending (queue position preserved)
            $skippedDueToSlots++;
            continue;
        }

        // All checks passed and slot is available -> approve
        $stmt = $pdo->prepare("UPDATE leaves SET status = 'approved' WHERE id = ?");
        $stmt->execute([$request['id']]);
        $approved++;
    }

    $_SESSION['auto_process_message'] = "Auto-processed " . count($requestsWithMetrics) .
        " pending request(s): $approved approved (respecting max " . MAX_CONCURRENT_LEAVES_PER_DAY .
        " concurrent leave(s) per day), $rejected rejected (attendance/balance), $skippedDueToSlots left pending due to full slots.";
    header('Location: leave.php');
    exit;
}

// Get auto-process message from session
if (isset($_SESSION['auto_process_message'])) {
    $autoProcessMessage = $_SESSION['auto_process_message'];
    unset($_SESSION['auto_process_message']);
}

// Handle approve/reject/delete actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $leaveId = (int)$_POST['leave_id'];
    $action = $_POST['action'];
    
    if ($action === 'approve' || $action === 'reject') {
        $status = $action === 'approve' ? 'approved' : 'rejected';
        $stmt = $pdo->prepare("UPDATE leaves SET status = ? WHERE id = ?");
        $stmt->execute([$status, $leaveId]);
    } elseif ($action === 'delete') {
        // Only allow deleting rejected requests
        $stmt = $pdo->prepare("DELETE FROM leaves WHERE id = ? AND status = 'rejected'");
        $stmt->execute([$leaveId]);
    }
    
    header('Location: leave.php');
    exit;
}

// Get filter
$filter = $_GET['filter'] ?? 'all';

// Fetch leave requests based on filter
$sql = "
    SELECT l.*, u.username, u.email, u.department 
    FROM leaves l 
    JOIN users u ON l.user_id = u.id 
";
if ($filter !== 'all') {
    $sql .= " WHERE l.status = ?";
}
$sql .= " ORDER BY l.created_at DESC";

$stmt = $pdo->prepare($sql);
if ($filter !== 'all') {
    $stmt->execute([$filter]);
} else {
    $stmt->execute();
}
$leaveRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate attendance percentage for each request and add rank
foreach ($leaveRequests as &$leave) {
    $attendanceStats = calculateAttendancePercentage($pdo, $leave['user_id']);
    $leave['attendance_percentage'] = $attendanceStats['percentage'];
    $leave['attendance_present'] = $attendanceStats['present'];
    $leave['attendance_total'] = $attendanceStats['total'];
    $leave['is_eligible'] = $attendanceStats['percentage'] >= ATTENDANCE_THRESHOLD || $leave['leave_type'] === 'sick_leave';
    $leave['remaining_balance'] = getRemainingBalance($pdo, $leave['user_id'], $leave['leave_type'], $leaveAllowances);
}
unset($leave);

// For pending requests, sort by attendance percentage (descending) and assign rank
$pendingLeaves = array_filter($leaveRequests, fn($l) => $l['status'] === 'pending');
usort($pendingLeaves, fn($a, $b) => $b['attendance_percentage'] <=> $a['attendance_percentage']);

$rank = 1;
$attendanceRanks = [];
foreach ($pendingLeaves as $pending) {
    $attendanceRanks[$pending['id']] = $rank++;
}

// Add rank to original array
foreach ($leaveRequests as &$leave) {
    $leave['rank'] = $attendanceRanks[$leave['id']] ?? null;
}
unset($leave);

// Sort all requests by attendance percentage (descending - higher eligibility first)
usort($leaveRequests, fn($a, $b) => $b['attendance_percentage'] <=> $a['attendance_percentage']);

// Get counts for summary cards
$stmt = $pdo->query("SELECT COUNT(*) FROM leaves");
$totalRequests = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM leaves WHERE status = 'pending'");
$pendingCount = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM leaves WHERE status = 'approved'");
$approvedCount = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM leaves WHERE status = 'rejected'");
$rejectedCount = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Leave Requests - WorkNest Admin</title>
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
    --shadow-orange: 0 4px 20px rgba(42, 170, 138, 0.25);
}

* { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
body { background: var(--bg-main); color: var(--text-main); overflow-x: hidden; }
body.sidebar-open { overflow: hidden; }
.wrapper { display: flex; min-height: 100vh; }

/* Sidebar Overlay */
.sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 998; }
.sidebar-overlay.active { display: block; }

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
.sidebar-brand { display: flex; align-items: center; gap: 14px; padding: 8px 12px; margin-bottom: 36px; }
.sidebar-brand .logo-icon {
    width: 48px; height: 48px;
    background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-light));
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 24px; color: #fff;
    box-shadow: var(--shadow-orange);
}
.sidebar-brand h2 {
    font-size: 22px; font-weight: 800;
    background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-dark));
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
}
.menu { flex: 1; }
.menu-label { font-size: 10px; text-transform: uppercase; letter-spacing: 1.5px; color: var(--text-light); padding: 0 14px; margin-bottom: 14px; margin-top: 28px; font-weight: 600; }
.menu a {
    display: flex; align-items: center; gap: 14px; padding: 14px 16px; border-radius: 12px;
    text-decoration: none; color: var(--text-muted); margin-bottom: 6px; font-weight: 500; font-size: 14px;
    transition: all 0.3s ease; border: 1px solid transparent; position: relative; overflow: hidden;
}
.menu a::before { content: ''; position: absolute; left: 0; top: 0; height: 100%; width: 0; background: linear-gradient(90deg, rgba(220,100,0,0.1), transparent); transition: width 0.3s ease; }
.menu a:hover::before { width: 100%; }
.menu a:hover { background: var(--bg-input); border-color: var(--border-accent); color: var(--text-main); transform: translateX(4px); }
.menu a.active { background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-light)); color: #fff; box-shadow: var(--shadow-orange); border: none; }
.menu a.active:hover { transform: translateX(0); }
.menu a .icon { width: 22px; text-align: center; font-size: 18px; }
.sidebar-footer { padding: 16px 12px; border-top: 2px solid var(--border-light); margin-top: auto; }
.sidebar-footer a {
    display: flex; align-items: center; gap: 12px; padding: 14px 16px; border-radius: 12px;
    text-decoration: none; color: #dc2626; font-weight: 600; font-size: 14px;
    background: #fff5f5; border: 1px solid #fecaca; transition: all 0.3s ease;
}
.sidebar-footer a:hover { background: #fef2f2; transform: translateY(-2px); }

/* MAIN */
.main { flex: 1; padding: 32px 40px; margin-left: 260px; animation: fadeIn 0.5s ease; }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

/* Top Bar */
.top-bar { display: flex; align-items: center; gap: 16px; margin-bottom: 8px; }
.page-title { font-size: 28px; font-weight: 800; color: var(--text-main); }
.hamburger { display: none; flex-direction: column; justify-content: center; align-items: center; width: 40px; height: 40px; background: var(--bg-card); border: 1px solid var(--border-accent); border-radius: 8px; cursor: pointer; gap: 5px; }
.hamburger:hover { background: var(--bg-input); }
.hamburger span { display: block; width: 20px; height: 2px; background: var(--text-main); border-radius: 2px; }

.subtitle { color: var(--text-muted); margin-bottom: 30px; font-size: 15px; }

/* ===== Summary Cards ===== */
.summary-cards {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-bottom: 25px;
}

.summary-card {
    background: var(--bg-card);
    border: 1px solid var(--border-light);
    border-radius: 14px;
    padding: 24px;
    box-shadow: var(--shadow-sm);
    transition: all 0.3s ease;
}

.summary-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-md);
    border-color: var(--border-accent);
}

.summary-card p {
    margin: 0;
    color: var(--text-muted);
    font-size: 14px;
    font-weight: 500;
}

.summary-card h2 {
    margin: 10px 0 0;
    font-size: 36px;
    font-weight: 800;
}

.summary-card h2.pending-color { color: var(--primary-orange); }
.summary-card h2.approved-color { color: #16a34a; }
.summary-card h2.rejected-color { color: #dc2626; }
.summary-card h2.total-color { color: var(--text-main); }

.summary-card .card-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    margin-bottom: 12px;
}

.summary-card .card-icon.total { background: #dbeafe; }
.summary-card .card-icon.pending { background: #ffedd5; }
.summary-card .card-icon.approved { background: #dcfce7; }
.summary-card .card-icon.rejected { background: #fef2f2; }

/* ===== Filter Buttons ===== */
.filters {
    margin-bottom: 25px;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.filters a {
    padding: 10px 20px;
    border-radius: 10px;
    border: 1px solid var(--border-accent);
    background: var(--bg-card);
    cursor: pointer;
    font-weight: 600;
    font-size: 14px;
    text-decoration: none;
    color: var(--text-muted);
    transition: all 0.2s ease;
}

.filters a:hover {
    background: var(--bg-input);
    border-color: var(--primary-orange);
    color: var(--text-main);
}

.filters a.active {
    background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-light));
    color: #fff;
    border-color: var(--primary-orange);
    box-shadow: var(--shadow-orange);
}

/* ===== Requests Section ===== */
.section {
    background: var(--bg-card);
    border: 1px solid var(--border-light);
    border-radius: 16px;
    padding: 28px;
    box-shadow: var(--shadow-sm);
}

.section-header {
    margin-bottom: 20px;
}

.section-header h3 {
    font-size: 18px;
    font-weight: 700;
    color: var(--text-main);
    margin: 0 0 6px;
}

.section-header small {
    color: var(--text-muted);
    font-size: 14px;
}

/* ===== Request Card ===== */
.request-list {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.request-card {
    border: 2px solid var(--border-light);
    background: var(--bg-input);
    border-radius: 14px;
    padding: 24px;
    transition: all 0.3s ease;
}

.request-card:hover {
    border-color: var(--border-accent);
    box-shadow: var(--shadow-sm);
}

.request-card.pending-card {
    border-color: #fde68a;
    background: #fffbeb;
}

.request-card.approved-card {
    border-color: #bbf7d0;
    background: #f0fdf4;
}

.request-card.rejected-card {
    border-color: #fecaca;
    background: #fef2f2;
}

.request-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 16px;
}

.employee-info .name {
    font-weight: 700;
    font-size: 18px;
    color: var(--text-main);
    margin-bottom: 4px;
}

.employee-info .department {
    font-size: 13px;
    color: var(--text-muted);
    margin-bottom: 6px;
}

.employee-info .leave-type {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    text-transform: capitalize;
}

.leave-type.sick_leave { background: #fef2f2; color: #dc2626; }
.leave-type.casual_leave { background: #dbeafe; color: #2563eb; }
.leave-type.paid_leave { background: #dcfce7; color: #16a34a; }
.leave-type.unpaid_leave { background: #f3f4f6; color: #6b7280; }

.request-status {
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.request-status.pending { background: #fef3c7; color: #b45309; }
.request-status.approved { background: #dcfce7; color: #16a34a; }
.request-status.rejected { background: #fef2f2; color: #dc2626; }

.request-details {
    display: flex;
    gap: 32px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}

.detail-item {
    display: flex;
    align-items: center;
    gap: 8px;
}

.detail-item .icon {
    font-size: 18px;
}

.detail-item .label {
    font-size: 12px;
    color: var(--text-muted);
}

.detail-item .value {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-main);
}

.request-reason {
    background: rgba(255,255,255,0.6);
    padding: 12px 16px;
    border-radius: 10px;
    margin-bottom: 16px;
    border: 1px solid var(--border-light);
}

.request-reason label {
    font-size: 12px;
    color: var(--text-muted);
    display: block;
    margin-bottom: 4px;
}

.request-reason p {
    font-size: 14px;
    color: var(--text-main);
    margin: 0;
    line-height: 1.5;
}

/* ===== Action Buttons ===== */
.request-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.request-actions form {
    display: inline;
}

.btn-action {
    padding: 10px 20px;
    border-radius: 10px;
    border: none;
    cursor: pointer;
    font-weight: 600;
    font-size: 14px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
}

.btn-approve {
    background: linear-gradient(135deg, #16a34a, #22c55e);
    color: #fff;
}

.btn-approve:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(22, 163, 74, 0.3);
}

.btn-reject {
    background: linear-gradient(135deg, #dc2626, #ef4444);
    color: #fff;
}

.btn-reject:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
}

.btn-delete {
    background: linear-gradient(135deg, #6b7280, #9ca3af);
    color: #fff;
}

.btn-delete:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(107, 114, 128, 0.3);
    background: linear-gradient(135deg, #4b5563, #6b7280);
}

.delete-form {
    display: inline;
    margin-left: 8px;
}

.btn-delete-icon {
    width: 32px;
    height: 32px;
    border: none;
    border-radius: 8px;
    background: #fef2f2;
    color: #dc2626;
    cursor: pointer;
    font-size: 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
    border: 1px solid #fecaca;
}

.btn-delete-icon:hover {
    background: #fee2e2;
    transform: scale(1.1);
    box-shadow: 0 2px 8px rgba(220, 38, 38, 0.2);
}

.action-done {
    color: var(--text-light);
    font-size: 14px;
    font-style: italic;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-muted);
}

.empty-state .icon {
    font-size: 64px;
    margin-bottom: 16px;
    opacity: 0.5;
}

.empty-state h3 {
    font-size: 18px;
    font-weight: 600;
    color: var(--text-main);
    margin-bottom: 8px;
}

/* Responsive */
@media (max-width: 1200px) {
    .summary-cards { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 1024px) {
    .hamburger { display: flex; }
    .sidebar { transform: translateX(-100%); }
    .sidebar.open { transform: translateX(0); }
    .main { margin-left: 0; }
}

@media (max-width: 768px) {
    .main { padding: 16px; }
    .summary-cards { grid-template-columns: 1fr; }
    .request-details { gap: 16px; }
}

/* ===== Attendance Priority Styles ===== */
.attendance-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    margin-left: 8px;
}
.attendance-badge.eligible { background: #dcfce7; color: #16a34a; }
.attendance-badge.ineligible { background: #fef2f2; color: #dc2626; }

.rank-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    font-size: 12px;
    font-weight: 700;
    margin-right: 10px;
}
.rank-badge.rank-1 { background: linear-gradient(135deg, #fbbf24, #f59e0b); color: #fff; }
.rank-badge.rank-2 { background: linear-gradient(135deg, #9ca3af, #6b7280); color: #fff; }
.rank-badge.rank-3 { background: linear-gradient(135deg, #d97706, #b45309); color: #fff; }
.rank-badge.rank-other { background: #e5e7eb; color: #374151; }

.auto-process-section {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    padding: 16px 20px;
    background: var(--bg-card);
    border: 1px solid var(--border-light);
    border-radius: 12px;
    box-shadow: var(--shadow-sm);
}
.auto-process-section .info {
    font-size: 14px;
    color: var(--text-muted);
}
.auto-process-section .info strong { color: var(--text-main); }
.btn-auto-process {
    padding: 12px 24px;
    border-radius: 10px;
    border: none;
    cursor: pointer;
    font-weight: 700;
    font-size: 14px;
    background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-light));
    color: #fff;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
    box-shadow: var(--shadow-orange);
}
.btn-auto-process:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(42, 170, 138, 0.35);
}
.btn-auto-process:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}

.alert-message {
    padding: 14px 20px;
    border-radius: 10px;
    margin-bottom: 20px;
    font-weight: 500;
    font-size: 14px;
}
.alert-message.success {
    background: #dcfce7;
    color: #16a34a;
    border: 1px solid #86efac;
}

.attendance-info {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 12px;
    background: #f8fafc;
    border-radius: 8px;
    margin-bottom: 12px;
    border: 1px solid var(--border-light);
}
.attendance-info .stat {
    font-size: 12px;
    color: var(--text-muted);
}
.attendance-info .stat strong {
    color: var(--text-main);
    font-weight: 700;
}
.attendance-info .percentage {
    font-size: 16px;
    font-weight: 800;
}
.attendance-info .percentage.high { color: #16a34a; }
.attendance-info .percentage.medium { color: #f59e0b; }
.attendance-info .percentage.low { color: #dc2626; }
.attendance-info .balance-status {
    font-size: 11px;
    padding: 3px 8px;
    border-radius: 4px;
    font-weight: 600;
}
.attendance-info .balance-status.sufficient { background: #dcfce7; color: #16a34a; }
.attendance-info .balance-status.insufficient { background: #fef2f2; color: #dc2626; }
</style>
</head>
<body>
<div class="sidebar-overlay" onclick="toggleSidebar()"></div>

<div class="wrapper">
    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <div class="logo-icon">🏢</div>
            <h2>WorkNest</h2>
        </div>
        <nav class="menu">
            <span class="menu-label">Main Menu</span>
            <a href="../dashboard.php"><span class="icon">🏠</span> Dashboard</a>
            <a href="../employee/employee.php"><span class="icon">👥</span> Employees</a>
            <a href="../attendance/attendance.php"><span class="icon">📅</span> Attendance</a>
            <a href="leave.php" class="active"><span class="icon">🗓️</span> Leave Requests</a>
            <a href="../notice/notices.php"><span class="icon">📢</span> Notices</a>
        </nav>
        <div class="sidebar-footer">
            <a href="../../logout.php"><span class="icon">🚪</span> Logout</a>
        </div>
    </aside>

    <!-- MAIN -->
    <main class="main">
        <div class="top-bar">
            <button class="hamburger" onclick="toggleSidebar()">
                <span></span><span></span><span></span>
            </button>
            <h1 class="page-title">Leave Requests</h1>
        </div>
        <p class="subtitle">Manage and approve employee leave requests</p>

        <?php if ($autoProcessMessage): ?>
            <div class="alert-message success">
                <?= htmlspecialchars($autoProcessMessage); ?>
            </div>
        <?php endif; ?>

        <!-- Auto-Process Section -->
        <?php if ($pendingCount > 0): ?>
        <div class="auto-process-section">
            <div class="info">
                <strong>Round Robin Leave Scheduling</strong><br>
                Process <?= $pendingCount; ?> pending request(s) using a fair queue: checks attendance, leave balance
                and per-day team slots (max <?= MAX_CONCURRENT_LEAVES_PER_DAY; ?> concurrent leave(s) per day).
            </div>
            <form method="POST" style="margin: 0;">
                <input type="hidden" name="action" value="auto_process">
                <button type="submit" class="btn-auto-process" onclick="return confirm('This will automatically approve eligible requests in priority order while respecting the maximum concurrent leaves per day, and reject those failing attendance/balance checks. Others stay pending if slots are full. Continue?');">
                    ⚡ Auto-Process All
                </button>
            </form>
        </div>
        <?php endif; ?>

        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card">
                <div class="card-icon total">📋</div>
                <p>Total Requests</p>
                <h2 class="total-color"><?= $totalRequests; ?></h2>
            </div>
            <div class="summary-card">
                <div class="card-icon pending">⏳</div>
                <p>Pending</p>
                <h2 class="pending-color"><?= $pendingCount; ?></h2>
            </div>
            <div class="summary-card">
                <div class="card-icon approved">✅</div>
                <p>Approved</p>
                <h2 class="approved-color"><?= $approvedCount; ?></h2>
            </div>
            <div class="summary-card">
                <div class="card-icon rejected">❌</div>
                <p>Rejected</p>
                <h2 class="rejected-color"><?= $rejectedCount; ?></h2>
            </div>
        </div>

        <!-- Filter Buttons -->
        <div class="filters">
            <a href="leave.php?filter=all" class="<?= $filter === 'all' ? 'active' : ''; ?>">All</a>
            <a href="leave.php?filter=pending" class="<?= $filter === 'pending' ? 'active' : ''; ?>">Pending</a>
            <a href="leave.php?filter=approved" class="<?= $filter === 'approved' ? 'active' : ''; ?>">Approved</a>
            <a href="leave.php?filter=rejected" class="<?= $filter === 'rejected' ? 'active' : ''; ?>">Rejected</a>
        </div>

        <!-- Requests Section -->
        <div class="section">
            <div class="section-header">
                <h3>Leave Requests</h3>
                <small>Showing <?= count($leaveRequests); ?> request(s)</small>
            </div>

            <?php if (empty($leaveRequests)): ?>
                <div class="empty-state">
                    <div class="icon">📭</div>
                    <h3>No Leave Requests</h3>
                    <p>There are no <?= $filter !== 'all' ? $filter : ''; ?> leave requests at the moment.</p>
                </div>
            <?php else: ?>
                <div class="request-list">
                    <?php foreach ($leaveRequests as $leave): ?>
                        <div class="request-card <?= $leave['status']; ?>-card">
                            <div class="request-header">
                                <div class="employee-info">
                                    <div class="name">
                                        <?php if ($leave['rank']): ?>
                                            <span class="rank-badge <?= $leave['rank'] <= 3 ? 'rank-' . $leave['rank'] : 'rank-other'; ?>">#<?= $leave['rank']; ?></span>
                                        <?php endif; ?>
                                        <?= htmlspecialchars($leave['username']); ?>
                                        <span class="attendance-badge <?= $leave['is_eligible'] ? 'eligible' : 'ineligible'; ?>">
                                            <?= $leave['is_eligible'] ? '✓ Eligible' : '✗ Ineligible'; ?>
                                        </span>
                                    </div>
                                    <div class="department"><?= htmlspecialchars($leave['department'] ?? 'No Department'); ?></div>
                                    <span class="leave-type <?= $leave['leave_type']; ?>">
                                        <?= ucfirst(str_replace('_', ' ', $leave['leave_type'])); ?>
                                    </span>
                                </div>
                                <span class="request-status <?= $leave['status']; ?>">
                                    <?= ucfirst($leave['status']); ?>
                                </span>
                                <?php if ($leave['status'] === 'rejected'): ?>
                                    <form method="POST" class="delete-form">
                                        <input type="hidden" name="leave_id" value="<?= $leave['id']; ?>">
                                        <button type="submit" name="action" value="delete" class="btn-delete-icon" onclick="return confirm('Delete this rejected request?');" title="Delete rejected request">
                                            🗑️
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>

                            <!-- Attendance & Balance Info -->
                            <div class="attendance-info">
                                <div class="stat">
                                    Attendance: <span class="percentage <?= $leave['attendance_percentage'] >= 75 ? 'high' : ($leave['attendance_percentage'] >= 50 ? 'medium' : 'low'); ?>"><?= $leave['attendance_percentage']; ?>%</span>
                                </div>
                                <div class="stat">
                                    <strong><?= $leave['attendance_present']; ?></strong> of <strong><?= $leave['attendance_total']; ?></strong> days present
                                </div>
                                <?php if ($leave['leave_type'] !== 'unpaid_leave'): ?>
                                    <?php 
                                    $requestDays = (new DateTime($leave['start_date']))->diff(new DateTime($leave['end_date']))->days + 1;
                                    $hasSufficientBalance = $leave['remaining_balance'] >= $requestDays;
                                    ?>
                                    <span class="balance-status <?= $hasSufficientBalance ? 'sufficient' : 'insufficient'; ?>">
                                        Balance: <?= $leave['remaining_balance']; ?> day(s) <?= $hasSufficientBalance ? '✓' : '✗'; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="balance-status sufficient">Unpaid: Unlimited ✓</span>
                                <?php endif; ?>
                            </div>

                            <div class="request-details">
                                <div class="detail-item">
                                    <span class="icon">📅</span>
                                    <div>
                                        <span class="label">From</span>
                                        <span class="value"><?= date('M d, Y', strtotime($leave['start_date'])); ?></span>
                                    </div>
                                </div>
                                <div class="detail-item">
                                    <span class="icon">📅</span>
                                    <div>
                                        <span class="label">To</span>
                                        <span class="value"><?= date('M d, Y', strtotime($leave['end_date'])); ?></span>
                                    </div>
                                </div>
                                <div class="detail-item">
                                    <span class="icon">📆</span>
                                    <div>
                                        <span class="label">Duration</span>
                                        <?php 
                                        $start = new DateTime($leave['start_date']);
                                        $end = new DateTime($leave['end_date']);
                                        $days = $start->diff($end)->days + 1;
                                        ?>
                                        <span class="value"><?= $days; ?> day(s)</span>
                                    </div>
                                </div>
                            </div>

                            <div class="request-reason">
                                <label>Reason</label>
                                <p><?= htmlspecialchars($leave['reason']); ?></p>
                            </div>

                            <div class="request-actions">
                                <?php if ($leave['status'] === 'pending'): ?>
                                    <form method="POST">
                                        <input type="hidden" name="leave_id" value="<?= $leave['id']; ?>">
                                        <button type="submit" name="action" value="approve" class="btn-action btn-approve">
                                            ✔ Approve
                                        </button>
                                    </form>
                                    <form method="POST">
                                        <input type="hidden" name="leave_id" value="<?= $leave['id']; ?>">
                                        <button type="submit" name="action" value="reject" class="btn-action btn-reject">
                                            ✖ Reject
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="action-done">Action completed on <?= date('M d, Y', strtotime($leave['created_at'])); ?></span>
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
    document.body.classList.toggle('sidebar-open');
}
</script>
</body>
</html>
