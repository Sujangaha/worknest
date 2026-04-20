<?php
require_once '../../includes/auth_check.php';
require_once '../../includes/db.php';

$pdo = getPDO();
$userId = $_SESSION['user_id'];

// Fetch logged-in employee data
$stmt = $pdo->prepare("SELECT username, email, department, position FROM users WHERE id = ? AND role = 'employee'");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die('Employee not found');
}

// Create/update attendance table with status column
$pdo->exec("
    CREATE TABLE IF NOT EXISTS attendance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        attendance_date DATE NOT NULL,
        check_in TIME DEFAULT NULL,
        check_out TIME DEFAULT NULL,
        status ENUM('present', 'absent', 'late') DEFAULT 'present',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_attendance (user_id, attendance_date)
    )
");

// Add status column if not exists (for existing tables)
try {
    $pdo->exec("ALTER TABLE attendance ADD COLUMN status ENUM('present', 'absent', 'late') DEFAULT 'present' AFTER check_out");
} catch (PDOException $e) {
    // Column already exists, ignore
}

// Create late_conversion_tracker table to track late-to-absent conversions
$pdo->exec("
    CREATE TABLE IF NOT EXISTS late_conversion_tracker (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL UNIQUE,
        pending_late_count INT DEFAULT 0,
        last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )
");

// ===== ATTENDANCE RULES (CLEAN VERSION) =====
// Work shift: 6:30 AM – 2:30 PM (8 hours)
// Final status is always based on TOTAL WORK HOURS.

// Check-in time windows
define('EARLY_CHECKIN_START', '06:00:00');   // Earliest allowed check-in
define('SHIFT_START_TIME', '06:30:00');      // Official shift start
define('ON_TIME_CUTOFF', '07:00:00');        // After this = Late
define('LATE_CUTOFF_TIME', '08:30:00');      // After this = Half Day (by arrival window)
define('HALFDAY_CUTOFF_TIME', '10:30:00');   // After this = Absent on check-in attempt

// Checkout and work hours
define('SHIFT_END_TIME', '14:30:00');        // Standard end time (2:30 PM)
define('LATE_CHECKOUT_LIMIT', '16:00:00');   // After 4:00 PM: treated as late checkout cap
define('FULLDAY_WORK_HOURS', 7);             // ≥ 7 hours => Present
define('HALFDAY_WORK_HOURS_MIN', 4);         // 4–<7 hours => Half Day; <4 => Absent

$today = date('Y-m-d');
$message = '';
$messageType = '';
$justCheckedIn = false; // Track if a fresh check-in was just recorded this request

// Function to determine initial check-in status based on time window
function getCheckInStatus($checkInTime) {
    if ($checkInTime < EARLY_CHECKIN_START) {
        return ['status' => 'error', 'message' => 'Check-in not allowed before 6:00 AM'];
    } elseif ($checkInTime >= EARLY_CHECKIN_START && $checkInTime < SHIFT_START_TIME) {
        return ['status' => 'early', 'message' => 'Early check-in (shift starts at 6:30 AM)'];
    } elseif ($checkInTime >= SHIFT_START_TIME && $checkInTime < ON_TIME_CUTOFF) {
        return ['status' => 'present', 'message' => 'On time'];
    } elseif ($checkInTime >= ON_TIME_CUTOFF && $checkInTime < LATE_CUTOFF_TIME) {
        return ['status' => 'late', 'message' => 'Late check-in'];
    } elseif ($checkInTime >= LATE_CUTOFF_TIME && $checkInTime < HALFDAY_CUTOFF_TIME) {
        return ['status' => 'halfday', 'message' => 'Check-in in Half Day window'];
    } else {
        // After 10:30 AM -> direct Absent on attempt
        return ['status' => 'absent', 'message' => 'Check-in after 10:30 AM - marked as Absent'];
    }
}

// Function to determine FINAL status purely based on total work hours
// (Most important rule: hours decide Present / Half Day / Absent)
function getFinalStatusOnCheckout($checkInTime, $checkOutTime) {
    // Validate checkout is after check-in
    if ($checkOutTime <= $checkInTime) {
        return ['status' => 'absent', 'message' => 'Invalid checkout - time is before or equal to check-in'];
    }

    // Cap extremely late checkouts at the configured limit (e.g., 4:00 PM)
    $effectiveCheckoutTime = $checkOutTime;
    if ($checkOutTime > LATE_CHECKOUT_LIMIT) {
        $effectiveCheckoutTime = LATE_CHECKOUT_LIMIT;
    }

    // Calculate work hours between check-in and (capped) checkout
    $checkIn = new DateTime($checkInTime);
    $checkOut = new DateTime($effectiveCheckoutTime);
    $diff = $checkIn->diff($checkOut);
    $hoursWorked = $diff->h + ($diff->i / 60);

    if ($hoursWorked >= FULLDAY_WORK_HOURS) {
        return [
            'status' => 'present',
            'message' => 'Worked ' . number_format($hoursWorked, 2) . ' hours - marked as Present'
        ];
    }

    if ($hoursWorked >= HALFDAY_WORK_HOURS_MIN) {
        return [
            'status' => 'halfday',
            'message' => 'Worked ' . number_format($hoursWorked, 2) . ' hours - marked as Half Day'
        ];
    }

    return [
        'status' => 'absent',
        'message' => 'Worked less than ' . HALFDAY_WORK_HOURS_MIN . ' hours - marked as Absent'
    ];
}

// Function to process late to absent conversion
function processLateConversion($pdo, $userId) {
    // Get current pending late count
    $stmt = $pdo->prepare("SELECT pending_late_count FROM late_conversion_tracker WHERE user_id = ?");
    $stmt->execute([$userId]);
    $tracker = $stmt->fetch();
    
    if (!$tracker) {
        // Initialize tracker
        $pdo->prepare("INSERT INTO late_conversion_tracker (user_id, pending_late_count) VALUES (?, 1)")->execute([$userId]);
        return 0;
    }
    
    $pendingLate = $tracker['pending_late_count'] + 1;
    $conversions = 0;
    
    // Check if we have 3 late days
    if ($pendingLate >= 3) {
        $pendingLate -= 3;
        $conversions = 1;
    }
    
    // Update tracker
    $stmt = $pdo->prepare("UPDATE late_conversion_tracker SET pending_late_count = ? WHERE user_id = ?");
    $stmt->execute([$pendingLate, $userId]);
    
    return $conversions;
}

// Handle Manual Attendance Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // Manual attendance for any date
    if ($_POST['action'] === 'manual_attendance') {
        $attendanceDate = $_POST['attendance_date'] ?? '';
        $checkInTime = $_POST['check_in_time'] ?? '';
        $checkOutTime = $_POST['check_out_time'] ?? '';
        $status = $_POST['status'] ?? 'present';
        
        if (empty($attendanceDate)) {
            $message = 'Please select a date!';
            $messageType = 'error';
        } elseif ($attendanceDate < $today) {
            $message = 'You cannot add attendance for past dates!';
            $messageType = 'error';
        } elseif (date('l', strtotime($attendanceDate)) === 'Saturday') {
            $message = 'Attendance is not allowed on Holidays!';
            $messageType = 'error';
        } else {
            // Check if already exists - NO UPDATES ALLOWED
            $stmt = $pdo->prepare("SELECT id FROM attendance WHERE user_id = ? AND attendance_date = ?");
            $stmt->execute([$userId, $attendanceDate]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                // Silently redirect - button should already be disabled/hidden
                header('Location: attendance.php');
                exit;
            } else {
                // Validation based on status
                if ($status === 'present' || $status === 'late') {
                    // GPS-based check-in: only check-in time required, check-out can be empty
                    if (empty($checkInTime)) {
                        $message = 'Check-in time is required!';
                        $messageType = 'error';
                    } elseif (!empty($checkOutTime) && $checkOutTime <= $checkInTime) {
                        // Validate check-out is after check-in (if provided)
                        $message = 'Check-out time must be after Check-in time!';
                        $messageType = 'error';
                    } else {
                        // Determine status based on check-in time
                        $checkInResult = getCheckInStatus($checkInTime);
                        
                        if ($checkInResult['status'] === 'error') {
                            $message = $checkInResult['message'];
                            $messageType = 'error';
                        } elseif ($checkInResult['status'] === 'absent') {
                            // After 10:30 AM = Absent (per clean rules)
                            $stmt = $pdo->prepare("INSERT INTO attendance (user_id, attendance_date, check_in, check_out, status) VALUES (?, ?, ?, NULL, ?)");
                            $stmt->execute([$userId, $attendanceDate, $checkInTime, 'absent']);
                            $message = 'Check-in after 10:30 AM is considered as Absent!';
                            $messageType = 'error';
                        } else {
                            // Status: early, present, or halfday (late)
                            $status = $checkInResult['status'];
                            
                            // Allow NULL for check_out (GPS check-in without checkout)
                            $checkOutValue = !empty($checkOutTime) ? $checkOutTime : null;
                            
                            $stmt = $pdo->prepare("INSERT INTO attendance (user_id, attendance_date, check_in, check_out, status) VALUES (?, ?, ?, ?, ?)");
                            $stmt->execute([$userId, $attendanceDate, $checkInTime, $checkOutValue, $status]);
                            // Mark that we have just created a check-in so auto-checkout
                            // logic on this page load does not immediately trigger
                            $justCheckedIn = true;
                            
                            $statusLabel = ucfirst($status);
                            if ($status === 'early') {
                                $message = 'Checked in early at ' . date('h:i A', strtotime($checkInTime)) . ' - Great start!';
                            } elseif ($status === 'halfday') {
                                $message = 'Checked in at ' . date('h:i A', strtotime($checkInTime)) . ' (Late - Half Day)';
                            } else {
                                $message = 'Checked in successfully at ' . date('h:i A', strtotime($checkInTime));
                            }
                            $messageType = 'success';
                        }
                    }
                } else {
                    // Absent - only date required, no times
                    $stmt = $pdo->prepare("INSERT INTO attendance (user_id, attendance_date, check_in, check_out, status) VALUES (?, ?, NULL, NULL, ?)");
                    $stmt->execute([$userId, $attendanceDate, 'absent']);
                    $message = 'Attendance marked as Absent successfully!';
                    $messageType = 'success';
                }
            }
        }
    }
    
    // Handle Checkout
    if ($_POST['action'] === 'checkout') {
        // Get today's attendance record
        $stmt = $pdo->prepare("SELECT id, check_in, check_out, status FROM attendance WHERE user_id = ? AND attendance_date = ?");
        $stmt->execute([$userId, $today]);
        $record = $stmt->fetch();
        
        if ($record && $record['check_in'] && !$record['check_out']) {
            $currentTime = date('H:i:s');
            $isAutoCheckout = isset($_POST['auto_checkout']) && $_POST['auto_checkout'] === '1';
            $forceHalfday = isset($_POST['force_halfday']) && $_POST['force_halfday'] === '1';
            
            // Validate: checkout must be after check-in
            if ($currentTime <= $record['check_in']) {
                $message = 'Invalid checkout - current time is before or equal to check-in time!';
                $messageType = 'error';
            } else {
                // Determine final status: either forced Half Day (for auto checkout outside office)
                // or normal calculation based on total work hours
                if ($forceHalfday && $isAutoCheckout) {
                    $finalStatus = 'halfday';
                    $finalMessage = 'Auto checkout at ' . date('h:i A') . ' - outside office area at 3:00 PM (marked as Half Day).';
                } else {
                    $checkoutResult = getFinalStatusOnCheckout($record['check_in'], $currentTime);
                    $finalStatus = $checkoutResult['status'];
                    $finalMessage = ($isAutoCheckout ? 'Auto checked out at ' : 'Checked out at ') . date('h:i A') . ' - ' . $checkoutResult['message'];
                }

                // Update both checkout time and status
                $stmt = $pdo->prepare("UPDATE attendance SET check_out = ?, status = ? WHERE id = ?");
                $stmt->execute([$currentTime, $finalStatus, $record['id']]);

                $message = $finalMessage;
                $messageType = $isAutoCheckout ? 'warning' : 'success';
            }
        } else {
            $message = 'Cannot checkout - no check-in found or already checked out!';
            $messageType = 'error';
        }
        
        header('Location: attendance.php');
        exit;
    }
}

// Get today's attendance
$stmt = $pdo->prepare("SELECT check_in, check_out, status FROM attendance WHERE user_id = ? AND attendance_date = ?");
$stmt->execute([$userId, $today]);
$todayAttendance = $stmt->fetch();

$checkInTime = $todayAttendance['check_in'] ?? null;
$checkOutTime = $todayAttendance['check_out'] ?? null;
$todayStatus = $todayAttendance['status'] ?? null;
$attendanceExists = !empty($todayAttendance); // Track if any attendance record exists
$isSaturday = (date('l') === 'Saturday'); // Check if today is a Holiday (Saturday)

// Calculate duration
$duration = '-';
if ($checkInTime && $checkOutTime) {
    $checkIn = new DateTime($checkInTime);
    $checkOut = new DateTime($checkOutTime);
    
    // Validate checkout is after check-in
    if ($checkOut > $checkIn) {
        $diff = $checkIn->diff($checkOut);
        $duration = $diff->format('%H:%I:%S');
    } else {
        $duration = '<span style="color: #dc2626;">Invalid</span>';
    }
}

// Get attendance statistics for current month
$currentMonth = date('Y-m');
$stmt = $pdo->prepare("
    SELECT 
        COUNT(CASE WHEN status = 'early' THEN 1 END) as early_days,
        COUNT(CASE WHEN status = 'present' THEN 1 END) as present_days,
        COUNT(CASE WHEN status = 'halfday' THEN 1 END) as halfday_days,
        COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent_days,
        COUNT(CASE WHEN status = 'late' THEN 1 END) as late_days,
        COUNT(*) as total_records
    FROM attendance 
    WHERE user_id = ? AND attendance_date LIKE ?
");
$stmt->execute([$userId, $currentMonth . '%']);
$stats = $stmt->fetch();

$earlyDays = (int)$stats['early_days'];
$presentDays = (int)$stats['present_days'];
$halfdayDays = (int)$stats['halfday_days'];
$absentDays = (int)$stats['absent_days'];
$lateDays = (int)$stats['late_days']; // Legacy support
$totalRecords = (int)$stats['total_records'];

// Combine early + present + halfday + late as "Present" for overview
$combinedPresentDays = $earlyDays + $presentDays + $halfdayDays + $lateDays;

// Calculate attendance percentage
$attendancePercentage = $totalRecords > 0 
    ? round(($combinedPresentDays / $totalRecords) * 100, 1) 
    : 0;

// Get last 10 days attendance with status
$stmt = $pdo->prepare("
    SELECT attendance_date, check_in, check_out, status 
    FROM attendance 
    WHERE user_id = ? 
    ORDER BY attendance_date DESC 
    LIMIT 10
");
$stmt->execute([$userId]);
$recentAttendance = $stmt->fetchAll();

// Helper functions
function formatTime($time) {
    if (!$time) return '-';
    return date('h:i:s A', strtotime($time));
}

function formatDate($date) {
    return date('D, M d', strtotime($date));
}

function calculateDuration($checkIn, $checkOut) {
    if (!$checkIn || !$checkOut) return '-';
    $in = new DateTime($checkIn);
    $out = new DateTime($checkOut);
    
    // Check if checkout is before check-in (invalid)
    if ($out <= $in) {
        return '<span style="color: #dc2626;">Invalid</span>';
    }
    
    $diff = $in->diff($out);
    return $diff->format('%H:%I:%S');
}

function getStatusBadge($status) {
    $badges = [
        'early' => '<span class="status-badge early">Early</span>',
        'present' => '<span class="status-badge present">Present</span>',
        'halfday' => '<span class="status-badge halfday">Half Day</span>',
        'absent' => '<span class="status-badge absent">Absent</span>',
        'late' => '<span class="status-badge late">Late</span>'
    ];
    return $badges[$status] ?? '<span class="status-badge">-</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance - WorkNest</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
                <a href="attendance.php" class="active"><span class="icon">📅</span> Attendance</a>
                <a href="../leave/leave.php"><span class="icon">🗓️</span> Leave</a>
                <a href="../notice/notices.php"><span class="icon">📢</span> Notices</a>
            </nav>
            <div class="sidebar-footer">
                <a href="../../logout.php"><span class="icon">🚪</span> Logout</a>
            </div>
        </aside>

        <!-- MAIN CONTENT -->
        <main class="main">
            <!-- Top Bar with Hamburger -->
            <div class="top-bar">
                <button class="hamburger" onclick="toggleSidebar()">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
                <h1 class="page-title">Attendance</h1>
            </div>

            <div class="container">
                <!-- Message Display -->
                <?php if ($message): ?>
                    <div class="alert <?php echo $messageType; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <!-- GPS-Based Attendance Entry -->
                <div class="card location-attendance-card">
                    <div class="welcome-header">
                        <h2>📍 Location-Based Attendance</h2>
                        <h3>Welcome, <?php echo htmlspecialchars($user['username']); ?>!</h3>
                    </div>

                    <div class="location-status" id="locationStatus">
                        <span class="location-icon pending">📡</span>
                        <div class="location-info">
                            <b>Detecting Location...</b><br>
                            <span id="locationDetails">Please allow location access</span>
                        </div>
                    </div>

                    <div class="attendance-grid">
                        <!-- Left Section - Check In/Out Button -->
                        <div class="checkin-section">
                            <?php if ($isSaturday): ?>
                                <div class="attendance-complete holiday-notice">
                                    <span class="complete-icon">🚫</span>
                                    <p>Attendance not allowed on Holiday</p>
                                </div>
                            <?php elseif (!$checkInTime): ?>
                                <form method="POST" id="checkinForm">
                                    <input type="hidden" name="action" value="manual_attendance">
                                    <input type="hidden" name="attendance_date" value="<?php echo $today; ?>">
                                    <input type="hidden" name="status" value="present">
                                    <input type="hidden" name="check_in_time" id="checkinTimeInput">
                                    <input type="hidden" name="check_out_time" id="checkoutTimeInput">
                                    <button type="button" class="checkin-btn" id="checkinBtn" onclick="handleCheckIn()" disabled>
                                        <span class="btn-icon">⏱</span> CHECK IN
                                    </button>
                                </form>
                            <?php elseif (!$checkOutTime): ?>
                                <form method="POST" id="checkoutForm">
                                    <input type="hidden" name="action" value="checkout">
                                    <button type="button" class="checkout-btn" id="checkoutBtn" onclick="handleCheckOut()" disabled>
                                        <span class="btn-icon">🚪</span> CHECK OUT
                                    </button>
                                </form>
                            <?php else: ?>
                                <div class="attendance-complete">
                                    <span class="complete-icon">✅</span>
                                    <p>Attendance Complete for Today!</p>
                                </div>
                            <?php endif; ?>

                            <div class="today-attendance-mini">
                                <div class="mini-stat">
                                    <span>Check-In</span>
                                    <strong><?php echo formatTime($checkInTime); ?></strong>
                                </div>
                                <div class="mini-stat">
                                    <span>Check-Out</span>
                                    <strong><?php echo formatTime($checkOutTime); ?></strong>
                                </div>
                                <div class="mini-stat">
                                    <span>Working Hours</span>
                                    <strong><?php echo $duration; ?></strong>
                                </div>
                            </div>
                        </div>

                        <!-- Right Section - Map -->
                        <div class="map-section">
                            <div class="map-box" id="mapBox">
                                <div class="map-placeholder">
                                    <div class="office-marker" id="officeMarker">
                                        <span class="marker-icon">🏢</span>
                                        <span class="marker-label">Office</span>
                                    </div>
                                    <div class="user-marker" id="userMarker" style="display: none;">
                                        <span class="marker-icon">📍</span>
                                        <span class="marker-label">You</span>
                                    </div>
                                    <div class="radius-circle" id="radiusCircle"></div>
                                </div>
                            </div>
                            <p class="radius-info">🛡 Allowed radius: <strong>100 meters</strong> from office</p>
                        </div>
                    </div>

                    <p class="info-text">
                        ⚠️ You must be within 100m of office to mark attendance<br>
                    </p>
                </div>

                <!-- Attendance Statistics -->
                <div class="stats-grid">
                    <!-- Pie Chart Card -->
                    <div class="card chart-card">
                        <h2>Attendance Overview</h2>
                        <p class="subtitle"><?php echo date('F Y'); ?></p>
                        <div class="chart-container">
                            <canvas id="attendanceChart"></canvas>
                        </div>
                    </div>

                    <!-- Calendar Card -->
                    <div class="card calendar-card">
                        <h2>📅 <?php echo date('F Y'); ?></h2>
                        <div class="calendar-grid">
                            <?php
                            $currentMonth = date('Y-m');
                            $firstDay = date('Y-m-01');
                            $lastDay = date('Y-m-t');
                            $firstDayOfWeek = date('w', strtotime($firstDay)); // 0=Sunday, 6=Saturday
                            $daysInMonth = date('t');
                            
                            // Get all attendance for current month
                            $stmt = $pdo->prepare("SELECT attendance_date, status FROM attendance WHERE user_id = ? AND attendance_date LIKE ?");
                            $stmt->execute([$userId, $currentMonth . '%']);
                            $monthAttendance = [];
                            while ($row = $stmt->fetch()) {
                                $monthAttendance[$row['attendance_date']] = $row['status'];
                            }
                            
                            // Day headers
                            $dayNames = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
                            foreach ($dayNames as $day) {
                                echo "<div class='cal-header'>$day</div>";
                            }
                            
                            // Empty cells before first day
                            for ($i = 0; $i < $firstDayOfWeek; $i++) {
                                echo "<div class='cal-day empty'></div>";
                            }
                            
                            // Days of month
                            for ($day = 1; $day <= $daysInMonth; $day++) {
                                $dateStr = $currentMonth . '-' . str_pad($day, 2, '0', STR_PAD_LEFT);
                                $dayOfWeek = date('w', strtotime($dateStr));
                                $isSaturday = ($dayOfWeek == 6);
                                $isToday = ($dateStr === $today);
                                $isFuture = ($dateStr > $today);
                                
                                $status = $monthAttendance[$dateStr] ?? null;
                                $statusClass = '';
                                if ($isSaturday) {
                                    $statusClass = 'holiday';
                                } elseif ($status === 'present' || $status === 'early') {
                                    $statusClass = 'present';
                                } elseif ($status === 'halfday') {
                                    $statusClass = 'halfday';
                                } elseif ($status === 'absent') {
                                    $statusClass = 'absent';
                                } elseif ($status === 'late') {
                                    $statusClass = 'present';
                                } elseif (!$isFuture && !$isSaturday && $dayOfWeek != 0) {
                                    // Past weekday without attendance = absent (except Sunday)
                                    if ($dateStr < $today) $statusClass = 'unmarked';
                                }
                                
                                $todayClass = $isToday ? 'today' : '';
                                echo "<div class='cal-day $statusClass $todayClass'>$day</div>";
                            }
                            ?>
                        </div>
                        <div class="calendar-legend">
                            <span class="legend-item"><span class="dot present"></span> Present</span>
                            <span class="legend-item"><span class="dot absent"></span> Absent</span>
                            <span class="legend-item"><span class="dot holiday"></span> Holiday</span>
                        </div>
                    </div>
                </div>

                <!-- Stats Summary -->
                <div class="card info-card">
                    <h3>📊 Monthly Summary</h3>
                    <div class="summary-grid">
                        <div class="summary-item present">
                            <span class="summary-value"><?php echo $combinedPresentDays; ?></span>
                            <span class="summary-label">Present Days</span>
                        </div>
                        <div class="summary-item absent">
                            <span class="summary-value"><?php echo $absentDays; ?></span>
                            <span class="summary-label">Absent Days</span>
                        </div>
                        <div class="summary-item percentage">
                            <span class="summary-value"><?php echo $attendancePercentage; ?>%</span>
                            <span class="summary-label">Attendance %</span>
                        </div>
                    </div>
                </div>

                <!-- Attendance Rules Info -->
                <div class="card info-card">
                    <h3>📋 Attendance Rules </h3>
                    <div class="rules-grid">
                        <!-- Check-in Rules -->
                        <div class="rule-item">
                            <span class="rule-time">Before 6:00 AM</span>
                            <span class="rule-status absent">Not Allowed</span>
                        </div>
                        <div class="rule-item">
                            <span class="rule-time">6:00 – 6:30 AM</span>
                            <span class="rule-status early">Early (full shift till 2:30 PM)</span>
                        </div>
                        <div class="rule-item">
                            <span class="rule-time">6:30 – 7:00 AM</span>
                            <span class="rule-status present">On Time</span>
                        </div>
                        <div class="rule-item">
                            <span class="rule-time">7:00 – 8:30 AM</span>
                            <span class="rule-status halfday">Late</span>
                        </div>
                        <div class="rule-item">
                            <span class="rule-time">8:30 – 10:30 AM</span>
                            <span class="rule-status halfday">Half Day Window</span>
                        </div>
                        <div class="rule-item">
                            <span class="rule-time">After 10:30 AM</span>
                            <span class="rule-status absent">Absent on Check-in</span>
                        </div>

                        <!-- Checkout & Shift Time -->
                        <div class="rule-item">
                            <span class="rule-time">Shift End</span>
                            <span class="rule-status info">Standard shift end is 2:30 PM</span>
                        </div>
                        <div class="rule-item">
                            <span class="rule-time">After 4:00 PM</span>
                            <span class="rule-status info">Work hours are capped at 4:00 PM for status</span>
                        </div>

                        <!-- Final Decision by Work Hours -->
                        <div class="rule-item">
                            <span class="rule-time">Total Work Hours</span>
                            <span class="rule-status info">Final Status (Most Important)</span>
                        </div>
                        <div class="rule-item">
                            <span class="rule-time">≥ 7 hours</span>
                            <span class="rule-status present">Present</span>
                        </div>
                        <div class="rule-item">
                            <span class="rule-time">4 – &lt; 7 hours</span>
                            <span class="rule-status halfday">Half Day</span>
                        </div>
                        <div class="rule-item">
                            <span class="rule-time">&lt; 4 hours</span>
                            <span class="rule-status absent">Absent</span>
                        </div>

                        <!-- Location & Auto Rules -->
                        <div class="rule-item">
                            <span class="rule-time">Location</span>
                            <span class="rule-status info">Check-in &amp; Checkout only within 100m of office</span>
                        </div>
                        <div class="rule-item">
                            <span class="rule-time">Auto Checkout</span>
                            <span class="rule-status info">If you forget, system auto-checks out at 3:00 PM.
                                If you are outside office at that time, it marks Half Day.</span>
                        </div>
                    </div>
                </div>

                <!-- Attendance History -->
                <div class="card">
                    <h2>Recent Attendance</h2>
                    <p class="subtitle">Your last 10 attendance records</p>

                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Check-in</th>
                                    <th>Check-out</th>
                                    <th>Duration</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentAttendance)): ?>
                                    <tr>
                                        <td colspan="5" style="text-align: center; color: #6b7280;">No attendance records found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recentAttendance as $record): ?>
                                        <tr>
                                            <td><?php echo formatDate($record['attendance_date']); ?></td>
                                            <td><?php echo formatTime($record['check_in']); ?></td>
                                            <td><?php echo formatTime($record['check_out']); ?></td>
                                            <td><?php echo calculateDuration($record['check_in'], $record['check_out']); ?></td>
                                            <td><?php echo getStatusBadge($record['status']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Update the external CSS link or inline styles -->
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

body { background-color: var(--bg-main); }

/* Validation Warning Styles */
.validation-warning {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: 10px;
    padding: 12px 16px;
    margin-bottom: 16px;
    animation: shake 0.5s ease;
}
.validation-warning .warning-icon {
    font-size: 20px;
}
.validation-warning .warning-text {
    color: #dc2626;
    font-size: 14px;
    font-weight: 500;
}
@keyframes shake {
    0%, 100% { transform: translateX(0); }
    20%, 60% { transform: translateX(-5px); }
    40%, 80% { transform: translateX(5px); }
}

/* Field Error */
.field-error {
    display: block;
    color: #dc2626;
    font-size: 12px;
    margin-top: 6px;
    font-weight: 500;
}

/* Input Error State */
.input-error {
    border-color: #dc2626 !important;
    background: #fef2f2 !important;
}

/* Disabled Button */
.btn.disabled {
    opacity: 0.5;
    cursor: not-allowed;
    pointer-events: none;
}

/* SIDEBAR - Matching other pages */
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

/* Main content area */
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
}
.hamburger:hover { background: var(--bg-input); }
.hamburger span { display: block; width: 20px; height: 2px; background: var(--text-main); border-radius: 2px; }

/* Responsive */
@media (max-width: 1024px) {
    .hamburger { display: flex; }
    .sidebar { transform: translateX(-100%); }
    .sidebar.open { transform: translateX(0); }
    .main { margin-left: 0; }
}

/* ===== LOCATION ATTENDANCE STYLES ===== */
.location-attendance-card {
    background: var(--bg-card);
    border: 1px solid var(--border-light);
    border-radius: 16px;
    padding: 24px;
    margin-bottom: 24px;
}

.welcome-header {
    margin-bottom: 20px;
}

.welcome-header h2 {
    font-size: 18px;
    font-weight: 700;
    margin-bottom: 8px;
    color: var(--text-main);
}

.welcome-header h3 {
    font-size: 16px;
    font-weight: 500;
    color: var(--text-muted);
}

.location-status {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px 20px;
    background: #f8fafc;
    border-radius: 12px;
    margin-bottom: 24px;
    border: 1px solid var(--border-light);
}

.location-status.verified {
    background: #dcfce7;
    border-color: #86efac;
}

.location-status.outside {
    background: #fef2f2;
    border-color: #fecaca;
}

.location-icon {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
}

.location-icon.pending {
    background: #e0e7ff;
}

.location-icon.verified {
    background: #22c55e;
    color: white;
}

.location-icon.outside {
    background: #ef4444;
    color: white;
}

.location-info b {
    font-size: 14px;
    color: var(--text-main);
}

.location-info span {
    font-size: 12px;
    color: var(--text-muted);
}

.attendance-grid {
    display: grid;
    grid-template-columns: 1fr 1.5fr;
    gap: 24px;
    margin-bottom: 20px;
}

.checkin-section {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.checkin-btn, .checkout-btn {
    width: 100%;
    padding: 20px 24px;
    font-size: 18px;
    font-weight: 700;
    border: none;
    border-radius: 50px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    transition: all 0.3s ease;
}

.checkin-btn {
    background: linear-gradient(135deg, #22c55e, #16a34a);
    color: white;
    box-shadow: 0 4px 20px rgba(34, 197, 94, 0.35);
}

.checkin-btn:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 6px 25px rgba(34, 197, 94, 0.45);
}

.checkin-btn:disabled, .checkout-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none !important;
}

.checkout-btn {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
    box-shadow: 0 4px 20px rgba(239, 68, 68, 0.35);
}

.checkout-btn:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 6px 25px rgba(239, 68, 68, 0.45);
}

.checkin-btn.processing, .checkout-btn.processing {
    background: linear-gradient(135deg, #9ca3af, #6b7280);
    cursor: wait;
    pointer-events: none;
    box-shadow: none;
}

.btn-icon {
    font-size: 22px;
}

.attendance-complete {
    text-align: center;
    padding: 24px;
    background: linear-gradient(135deg, #dcfce7, #bbf7d0);
    border-radius: 16px;
    border: 1px solid #86efac;
}

.complete-icon {
    font-size: 48px;
    display: block;
    margin-bottom: 10px;
}

.attendance-complete p {
    font-size: 15px;
    font-weight: 600;
    color: #16a34a;
}

.attendance-complete.holiday-notice {
    background: linear-gradient(135deg, #f3f4f6, #e5e7eb);
    border-color: #9ca3af;
}

.attendance-complete.holiday-notice p {
    color: #6b7280;
}

.today-attendance-mini {
    background: var(--bg-input);
    border: 1px solid var(--border-light);
    border-radius: 12px;
    padding: 16px;
}

.mini-stat {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid var(--border-light);
}

.mini-stat:last-child {
    border-bottom: none;
}

.mini-stat span {
    font-size: 13px;
    color: var(--text-muted);
}

.mini-stat strong {
    font-size: 14px;
    color: var(--text-main);
}

.map-section {
    display: flex;
    flex-direction: column;
}

.map-box {
    height: 220px;
    background: linear-gradient(135deg, #e9edf2, #f1f5f9);
    border-radius: 12px;
    position: relative;
    overflow: hidden;
    border: 1px solid var(--border-light);
}

.map-placeholder {
    width: 100%;
    height: 100%;
    position: relative;
}

.office-marker, .user-marker {
    position: absolute;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    z-index: 10;
}

.office-marker {
    bottom: 50%;
    right: 50%;
    transform: translate(50%, 50%);
}

.user-marker {
    top: 30%;
    left: 35%;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.1); }
}

.marker-icon {
    font-size: 32px;
}

.marker-label {
    background: #1e293b;
    color: white;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
}

.radius-circle {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 120px;
    height: 120px;
    border: 3px dashed var(--primary-orange);
    border-radius: 50%;
    opacity: 0.5;
}

.radius-info {
    margin-top: 12px;
    font-size: 13px;
    color: var(--text-muted);
    text-align: center;
}

.radius-info strong {
    color: var(--primary-orange);
}

@media (max-width: 768px) {
    .attendance-grid {
        grid-template-columns: 1fr;
    }
    
    .map-box {
        height: 180px;
    }
}

/* Updated stats cards for new statuses */
.stats-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
    gap: 16px;
    margin-top: 20px;
}

.stat-card.early {
    background: linear-gradient(135deg, #818cf8, #6366f1);
    color: white;
}

.stat-card.halfday {
    background: linear-gradient(135deg, #fbbf24, #f59e0b);
    color: white;
}

/* Rules Info Card */
.info-card {
    background: var(--bg-card);
    border: 1px solid var(--border-light);
    padding: 20px;
}

.info-card h3 {
    margin-bottom: 16px;
    font-size: 16px;
    font-weight: 600;
    color: var(--text-main);
}

.rules-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 12px;
}

.rule-item {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 12px;
    background: var(--bg-input);
    border-radius: 10px;
    border: 1px solid var(--border-light);
}

.rule-time {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-muted);
}

.rule-status {
    font-size: 13px;
    font-weight: 700;
    padding: 4px 8px;
    border-radius: 6px;
    text-align: center;
}

.rule-status.early {
    background: #ede9fe;
    color: #6366f1;
}

.rule-status.present {
    background: #d1fae5;
    color: #059669;
}

.rule-status.halfday {
    background: #fef3c7;
    color: #d97706;
}

.rule-status.absent {
    background: #fee2e2;
    color: #dc2626;
}

.rule-status.info {
    background: #dbeafe;
    color: #2563eb;
}

/* Calendar Styles */
.calendar-card {
    min-height: 300px;
}

.calendar-card h2 {
    margin-bottom: 16px;
}

.calendar-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 4px;
}

.cal-header {
    text-align: center;
    font-size: 11px;
    font-weight: 700;
    color: var(--text-muted);
    padding: 8px 0;
    text-transform: uppercase;
}

.cal-day {
    text-align: center;
    padding: 8px 4px;
    font-size: 13px;
    font-weight: 500;
    border-radius: 8px;
    color: var(--text-main);
    transition: all 0.2s ease;
}

.cal-day.empty {
    background: transparent;
}

.cal-day.today {
    border: 2px solid var(--primary-orange);
    font-weight: 700;
}

.cal-day.present {
    background: #dcfce7;
    color: #16a34a;
}

.cal-day.halfday {
    background: #fef3c7;
    color: #d97706;
}

.cal-day.absent {
    background: #fef2f2;
    color: #dc2626;
}

.cal-day.holiday {
    background: #f3f4f6;
    color: #9ca3af;
}

.cal-day.unmarked {
    background: #fff7ed;
    color: #ea580c;
}

.calendar-legend {
    display: flex;
    gap: 16px;
    margin-top: 16px;
    padding-top: 12px;
    border-top: 1px solid var(--border-light);
    justify-content: center;
    flex-wrap: wrap;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: var(--text-muted);
}

.legend-item .dot {
    width: 12px;
    height: 12px;
    border-radius: 4px;
}

.legend-item .dot.present { background: #dcfce7; border: 1px solid #16a34a; }
.legend-item .dot.absent { background: #fef2f2; border: 1px solid #dc2626; }
.legend-item .dot.holiday { background: #f3f4f6; border: 1px solid #9ca3af; }

/* Summary Grid */
.summary-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
}

.summary-item {
    text-align: center;
    padding: 16px;
    border-radius: 12px;
    background: var(--bg-input);
    border: 1px solid var(--border-light);
}

.summary-item.present {
    background: linear-gradient(135deg, #dcfce7, #bbf7d0);
    border-color: #86efac;
}

.summary-item.absent {
    background: linear-gradient(135deg, #fef2f2, #fecaca);
    border-color: #fca5a5;
}

.summary-item.percentage {
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    border-color: #93c5fd;
}

.summary-value {
    display: block;
    font-size: 28px;
    font-weight: 800;
    color: var(--text-main);
}

.summary-label {
    display: block;
    font-size: 12px;
    color: var(--text-muted);
    margin-top: 4px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

@media (max-width: 768px) {
    .summary-grid {
        grid-template-columns: 1fr;
    }
}
</style>

    <script>
        // Sidebar Toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            sidebar.classList.toggle('open');
            overlay.classList.toggle('active');
            document.body.classList.toggle('sidebar-open');
        }

        // Pie Chart (Attendance Status Distribution)
        const ctx = document.getElementById('attendanceChart').getContext('2d');
        new Chart(ctx, {
            type: 'pie',
            data: {
                labels: ['Present', 'Absent'],
                datasets: [{
                    data: [<?php echo $combinedPresentDays; ?>, <?php echo $absentDays; ?>],
                    backgroundColor: ['#10b981', '#ef4444'],
                    borderColor: ['#059669', '#dc2626'],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true,
                            font: {
                                size: 14,
                                family: "'Inter', sans-serif"
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = total > 0 ? ((context.raw / total) * 100).toFixed(1) : 0;
                                return context.label + ': ' + context.raw + ' days (' + percentage + '%)';
                            }
                        }
                    }
                }
            }
        });
        
        // ===== GEOLOCATION ATTENDANCE =====
        
       
        const OFFICE_LAT = 27.725677; 
        const OFFICE_LNG = 85.2738099; 
        const ALLOWED_RADIUS = 100; 
        /*
        const OFFICE_LAT = 27.7186653; 
        const OFFICE_LNG = 85.3152641; 
        const ALLOWED_RADIUS = 100; 
         */

        const AUTO_CHECKOUT_HOUR = 15;  // 3:00 PM
        const AUTO_CHECKOUT_MINUTE = 0;
        const FULLDAY_WORK_HOURS_JS = <?php echo FULLDAY_WORK_HOURS; ?>;
        const HALFDAY_WORK_HOURS_MIN_JS = <?php echo HALFDAY_WORK_HOURS_MIN; ?>;
        
        let userLat = null;
        let userLng = null;
        let isWithinRadius = false;
        let hasJustCheckedIn = <?php echo $justCheckedIn ? 'true' : 'false'; ?>; 
       
        function calculateDistance(lat1, lon1, lat2, lon2) {
            const R = 6371e3; 
            const φ1 = lat1 * Math.PI / 180;
            const φ2 = lat2 * Math.PI / 180;
            const Δφ = (lat2 - lat1) * Math.PI / 180;
            const Δλ = (lon2 - lon1) * Math.PI / 180;
            
            const a = Math.sin(Δφ/2) * Math.sin(Δφ/2) +
                      Math.cos(φ1) * Math.cos(φ2) *
                      Math.sin(Δλ/2) * Math.sin(Δλ/2);
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
            
            // Distance in meters using the haversine formula
            return R * c;
        }
        
       
        function updateLocationStatus(status, message, details) {
            const statusDiv = document.getElementById('locationStatus');
            const iconSpan = statusDiv.querySelector('.location-icon');
            const infoDiv = statusDiv.querySelector('.location-info');
            
            statusDiv.className = 'location-status ' + status;
            iconSpan.className = 'location-icon ' + status;
            
            if (status === 'verified') {
                iconSpan.textContent = '✔';
            } else if (status === 'outside') {
                iconSpan.textContent = '✗';
            } else {
                iconSpan.textContent = '📡';
            }
            
            infoDiv.innerHTML = '<b>' + message + '</b><br><span id="locationDetails">' + details + '</span>';
        }
        
        // Get user's current location
        function getLocation() {
            if (!navigator.geolocation) {
                updateLocationStatus('outside', 'Geolocation Not Supported', 'Your browser does not support location services');
                return;
            }
            
            navigator.geolocation.getCurrentPosition(
                (position) => {
                    userLat = position.coords.latitude;
                    userLng = position.coords.longitude;
                    const accuracy = Math.round(position.coords.accuracy);
                    
                    const distance = calculateDistance(userLat, userLng, OFFICE_LAT, OFFICE_LNG);
                    const distanceRounded = Math.round(distance);
                    
                    // Show user marker on map
                    const userMarker = document.getElementById('userMarker');
                    if (userMarker) {
                        userMarker.style.display = 'flex';
                    }
                    
                    if (distance <= ALLOWED_RADIUS) {
                        isWithinRadius = true;
                        updateLocationStatus('verified', 'Location Verified: Inside Office Area', 
                            'Distance to Office: ' + distanceRounded + ' meters | GPS Accuracy: ' + accuracy + ' meters');
                        enableButtons();
                    } else {
                        isWithinRadius = false;
                        updateLocationStatus('outside', 'Outside Office Area', 
                            'You are ' + distanceRounded + ' meters away from office (max: ' + ALLOWED_RADIUS + 'm)');
                        disableButtons();
                    }
                },
                (error) => {
                    let errorMsg = 'Unable to get location';
                    switch(error.code) {
                        case error.PERMISSION_DENIED:
                            errorMsg = 'Location permission denied. Please allow location access.';
                            break;
                        case error.POSITION_UNAVAILABLE:
                            errorMsg = 'Location information unavailable.';
                            break;
                        case error.TIMEOUT:
                            errorMsg = 'Location request timed out.';
                            break;
                    }
                    updateLocationStatus('outside', 'Location Error', errorMsg);
                    disableButtons();
                },
                {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 0
                }
            );
        }
        
        // Enable check-in/out buttons (when within 100m radius)
        function enableButtons() {
            const checkinBtn = document.getElementById('checkinBtn');
            const checkoutBtn = document.getElementById('checkoutBtn');
            const attendanceExists = <?php echo $attendanceExists ? 'true' : 'false'; ?>;
            
            // Only enable check-in if no attendance exists yet AND within radius
            if (checkinBtn && !attendanceExists) checkinBtn.disabled = false;
            // Enable checkout only when within radius
            if (checkoutBtn) checkoutBtn.disabled = false;
        }
        
        // Disable check-in/out buttons (when outside 100m radius)
        function disableButtons() {
            const checkinBtn = document.getElementById('checkinBtn');
            const checkoutBtn = document.getElementById('checkoutBtn');
            if (checkinBtn) checkinBtn.disabled = true;
            if (checkoutBtn) checkoutBtn.disabled = true;
        }
        
        // Handle Check-In
        function handleCheckIn() {
            // Check if today is a Holiday (Saturday)
            const today = new Date();
            if (today.getDay() === 6) {
                alert('Attendance is not allowed on Holidays!');
                return;
            }
            
            // Double-check attendance doesn't already exist
            const attendanceExists = <?php echo $attendanceExists ? 'true' : 'false'; ?>;
            if (attendanceExists) {
                alert('Attendance for today is already recorded.');
                return;
            }
            
            if (!isWithinRadius) {
                alert('You must be within ' + ALLOWED_RADIUS + ' meters of the office to check in!');
                return;
            }
            
            const checkinBtn = document.getElementById('checkinBtn');
            
            // Disable button and show processing state
            checkinBtn.disabled = true;
            checkinBtn.classList.add('processing');
            checkinBtn.innerHTML = '<span class="btn-icon">⏳</span> Processing...';
            
            const now = new Date();
            const timeStr = now.toTimeString().slice(0, 5); // HH:MM format
            
            // Set times for form submission
            document.getElementById('checkinTimeInput').value = timeStr;
            document.getElementById('checkoutTimeInput').value = ''; // Will be set on checkout
            
            // Submit the form
            document.getElementById('checkinForm').submit();
        }
        
        // Handle Check-Out (requires location within 100m)
        function handleCheckOut() {
            // Check if within radius
            if (!isWithinRadius) {
                alert('You must be within ' + ALLOWED_RADIUS + ' meters of the office to check out!');
                return;
            }
            
            // Friendly warnings based on checkout time window
            const now = new Date();
            const minutesNow = now.getHours() * 60 + now.getMinutes();
            const minutes1030 = 10 * 60 + 30;
            const minutes1430 = 14 * 60 + 30;
            const minutes1600 = 16 * 60;

            if (minutesNow < minutes1030) {
                const proceedAbsent = confirm('You are checking out before 10:30 AM. You will be marked ABSENT based on work hours. Do you want to continue?');
                if (!proceedAbsent) return;
            } else if (minutesNow < minutes1430) {
                const proceedHalf = confirm('You are checking out before shift end (2:30 PM). This will be counted as HALF DAY based on work hours. Do you want to continue?');
                if (!proceedHalf) return;
            } else if (minutesNow > minutes1600) {
                const proceedLate = confirm('It is after 4:00 PM. System will cap work hours at 4:00 PM. Continue with checkout?');
                if (!proceedLate) return;
            }
            
            const checkoutBtn = document.getElementById('checkoutBtn');
            
            // Disable button and show processing state
            checkoutBtn.disabled = true;
            checkoutBtn.classList.add('processing');
            checkoutBtn.innerHTML = '<span class="btn-icon">⏳</span> Processing...';
            
            // Submit checkout form
            document.getElementById('checkoutForm').submit();
        }
        
        // Auto-checkout function (triggered at or after 3:00 PM)
        function checkAutoCheckout() {
            const hasCheckedIn = <?php echo ($checkInTime && !$checkOutTime && $todayStatus !== 'absent') ? 'true' : 'false'; ?>;
            if (!hasCheckedIn) return;
            
            const now = new Date();
            const currentHour = now.getHours();
            const currentMinute = now.getMinutes();
            
            // If it's 3:00 PM or later and user hasn't checked out, auto-close the session
            if (currentHour > AUTO_CHECKOUT_HOUR || (currentHour === AUTO_CHECKOUT_HOUR && currentMinute >= AUTO_CHECKOUT_MINUTE)) {
                const checkoutForm = document.getElementById('checkoutForm');
                if (checkoutForm) {
                    const autoFlag = document.createElement('input');
                    autoFlag.type = 'hidden';
                    autoFlag.name = 'auto_checkout';
                    autoFlag.value = '1';
                    checkoutForm.appendChild(autoFlag);

                    // If user is not inside office radius at auto time,
                    // tell backend to force Half Day status for this auto checkout.
                    if (!isWithinRadius) {
                        const halfFlag = document.createElement('input');
                        halfFlag.type = 'hidden';
                        halfFlag.name = 'force_halfday';
                        halfFlag.value = '1';
                        checkoutForm.appendChild(halfFlag);
                    }
                    checkoutForm.submit();
                }
            }
        }
        
        // Initialize geolocation on page load
        document.addEventListener('DOMContentLoaded', function() {
            getLocation();
            
            // Check for auto-checkout on page load
            checkAutoCheckout();
            
            // Refresh location every 30 seconds
            setInterval(getLocation, 30000);
            
            // Check for auto-checkout every minute
            setInterval(checkAutoCheckout, 60000);
        });
    </script>
</body>
</html>
