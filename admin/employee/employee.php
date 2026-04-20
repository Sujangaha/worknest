<?php
require_once '../../includes/auth_check.php';
require_once '../../includes/db.php';

$pdo = getPDO();

$message = '';
$messageType = '';
$showForm = false;
$editEmployee = null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Add new employee
    if ($action === 'add') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $department = trim($_POST['department'] ?? '');
        $position = trim($_POST['position'] ?? '');
        
        // Validation
        $errors = [];
        
        if (empty($username)) {
            $errors[] = 'Name is required.';
        } elseif (strlen($username) < 2) {
            $errors[] = 'Name must be at least 2 characters.';
        } elseif (strlen($username) > 100) {
            $errors[] = 'Name cannot exceed 100 characters.';
        }
        
        if (empty($email)) {
            $errors[] = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        
        if (empty($password)) {
            $errors[] = 'Password is required.';
        } elseif (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        } elseif (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter.';
        } elseif (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter.';
        } elseif (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number.';
        } elseif (!preg_match('/[^a-zA-Z0-9]/', $password)) {
            $errors[] = 'Password must contain at least one symbol.';
        }
        
        if (strlen($department) > 100) {
            $errors[] = 'Department cannot exceed 100 characters.';
        }
        
        if (strlen($position) > 100) {
            $errors[] = 'Position cannot exceed 100 characters.';
        }
        
        if (!empty($errors)) {
            $_SESSION['employee_message'] = implode(' ', $errors);
            $_SESSION['employee_message_type'] = 'error';
            $_SESSION['show_form'] = true;
            $_SESSION['form_data'] = [
                'username' => $username,
                'email' => $email,
                'department' => $department,
                'position' => $position
            ];
        } else {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $_SESSION['employee_message'] = 'Email already exists.';
                $_SESSION['employee_message_type'] = 'error';
                $_SESSION['show_form'] = true;
                $_SESSION['form_data'] = [
                    'username' => $username,
                    'email' => $email,
                    'department' => $department,
                    'position' => $position
                ];
            } else {
                // Hash password before storing
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, email, password, department, position, role) VALUES (?, ?, ?, ?, ?, 'employee')");
                $stmt->execute([$username, $email, $hashedPassword, $department, $position]);
                $_SESSION['employee_message'] = 'Employee added successfully!';
                $_SESSION['employee_message_type'] = 'success';
            }
        }
        header('Location: employee.php');
        exit;
    }
    
    // Update employee
    if ($action === 'update') {
        $id = (int)$_POST['employee_id'];
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $position = trim($_POST['position'] ?? '');
        $password = $_POST['password'] ?? '';
        
        // Validation
        $errors = [];
        
        if (empty($username)) {
            $errors[] = 'Name is required.';
        } elseif (strlen($username) < 2) {
            $errors[] = 'Name must be at least 2 characters.';
        } elseif (strlen($username) > 100) {
            $errors[] = 'Name cannot exceed 100 characters.';
        }
        
        if (empty($email)) {
            $errors[] = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        
        if (!empty($password)) {
            if (strlen($password) < 8) {
                $errors[] = 'Password must be at least 8 characters.';
            } elseif (!preg_match('/[A-Z]/', $password)) {
                $errors[] = 'Password must contain at least one uppercase letter.';
            } elseif (!preg_match('/[a-z]/', $password)) {
                $errors[] = 'Password must contain at least one lowercase letter.';
            } elseif (!preg_match('/[0-9]/', $password)) {
                $errors[] = 'Password must contain at least one number.';
            } elseif (!preg_match('/[^a-zA-Z0-9]/', $password)) {
                $errors[] = 'Password must contain at least one symbol.';
            }
        }
        
        if (!empty($errors)) {
            $_SESSION['employee_message'] = implode(' ', $errors);
            $_SESSION['employee_message_type'] = 'error';
        } else {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$email, $id]);
            if ($stmt->fetch()) {
                $_SESSION['employee_message'] = 'Email already exists.';
                $_SESSION['employee_message_type'] = 'error';
            } else {
                if (!empty($password)) {
                    // Hash new password before updating
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET username=?, email=?, password=?, department=?, position=? WHERE id=?");
                    $stmt->execute([$username, $email, $hashedPassword, $department, $position, $id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET username=?, email=?, department=?, position=? WHERE id=?");
                    $stmt->execute([$username, $email, $department, $position, $id]);
                }
                $_SESSION['employee_message'] = 'Employee updated successfully!';
                $_SESSION['employee_message_type'] = 'success';
            }
        }
        header('Location: employee.php');
        exit;
    }
    
    // Delete employee
    if ($action === 'delete') {
        $id = (int)$_POST['employee_id'];
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'employee'");
        $stmt->execute([$id]);
        $_SESSION['employee_message'] = 'Employee deleted successfully!';
        $_SESSION['employee_message_type'] = 'success';
        header('Location: employee.php');
        exit;
    }
}

// Check for edit mode
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'employee'");
    $stmt->execute([$editId]);
    $editEmployee = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($editEmployee) $showForm = true;
}

// Get messages from session
if (isset($_SESSION['employee_message'])) {
    $message = $_SESSION['employee_message'];
    $messageType = $_SESSION['employee_message_type'];
    unset($_SESSION['employee_message'], $_SESSION['employee_message_type']);
}
if (isset($_SESSION['show_form'])) {
    $showForm = true;
    unset($_SESSION['show_form']);
}

// Get preserved form data
$formData = $_SESSION['form_data'] ?? [];
if (isset($_SESSION['form_data'])) {
    unset($_SESSION['form_data']);
}

// Fetch all employees
$stmt = $pdo->prepare("SELECT * FROM users WHERE role = 'employee' ORDER BY username ASC");
$stmt->execute();
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalEmployees = count($employees);

// Selected values for department and position (used in form dropdowns)
$selectedDepartment = $editEmployee['department'] ?? $formData['department'] ?? '';
$selectedPosition = $editEmployee['position'] ?? $formData['position'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Employees - WorkNest Admin</title>
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

.page-header-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }
.summary-cards { display: flex; gap: 16px; flex-wrap: wrap; }
.summary-card { background: var(--bg-card); border: 1px solid var(--border-light); border-radius: 14px; padding: 20px; display: flex; align-items: center; gap: 14px; }
.summary-card .card-icon { width: 50px; height: 50px; background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-light)); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 22px; color: #fff; }
.summary-card .card-icon.green { background: linear-gradient(135deg, #16a34a, #22c55e); }
.summary-card .card-info p { margin: 0; color: var(--text-muted); font-size: 13px; }
.summary-card .card-info h2 { margin: 0; font-size: 26px; font-weight: 800; }

.alert { padding: 14px 20px; border-radius: 12px; margin-bottom: 24px; font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 10px; }
.alert.success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
.alert.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

.card { background: var(--bg-card); border: 1px solid var(--border-light); border-radius: 16px; padding: 28px; margin-bottom: 24px; }
.card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid var(--border-light); }
.card-header h2 { font-size: 18px; font-weight: 700; }

.form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }
.form-group label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 8px; }
.form-group input, .form-group select { width: 100%; padding: 12px 14px; border: 2px solid var(--border-light); border-radius: 10px; font-size: 14px; background: var(--bg-input); }
.form-group input:focus, .form-group select:focus { outline: none; border-color: var(--primary-orange); }
.form-actions { display: flex; gap: 12px; margin-top: 20px; }

/* Password Toggle */
.password-wrapper { position: relative; }
.password-wrapper input { padding-right: 45px; }
.password-toggle { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; padding: 4px; display: flex; align-items: center; justify-content: center; }
.password-toggle svg { width: 20px; height: 20px; fill: none; stroke: #65676b; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.password-toggle:hover svg { stroke: var(--primary-orange); }

.btn { padding: 12px 24px; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; transition: all 0.2s; }
.btn-primary { background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-light)); color: #fff; }
.btn-primary:hover { transform: translateY(-2px); box-shadow: var(--shadow-orange); }
.btn-secondary { background: var(--bg-input); color: var(--text-main); border: 1px solid var(--border-accent); }
.btn-danger { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
.btn-close { background: var(--bg-input); border: 1px solid var(--border-accent); width: 36px; height: 36px; border-radius: 10px; cursor: pointer; font-size: 18px; display: flex; align-items: center; justify-content: center; color: var(--text-muted); }
.btn-close:hover { background: #fef2f2; color: #dc2626; }

.form-card { display: none; }
.form-card.active { display: block; animation: slideDown 0.3s ease; }
@keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }

/* Employee Grid */
.employees-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; }
.employee-card { background: var(--bg-input); border: 1px solid var(--border-light); border-radius: 16px; padding: 24px; position: relative; transition: all 0.3s; }
.employee-card::before { content: ''; position: absolute; left: 0; top: 0; width: 5px; height: 100%; background: linear-gradient(180deg, var(--primary-orange), var(--primary-orange-light)); border-radius: 16px 0 0 16px; }
.employee-card.inactive::before { background: linear-gradient(180deg, #dc2626, #ef4444); }
.employee-card:hover { border-color: var(--border-accent); transform: translateY(-4px); box-shadow: var(--shadow-md); }

.employee-header { display: flex; align-items: flex-start; gap: 14px; margin-bottom: 16px; }
.employee-avatar { width: 52px; height: 52px; background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-light)); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 700; color: #fff; flex-shrink: 0; }
.employee-card.inactive .employee-avatar { background: linear-gradient(135deg, #9ca3af, #6b7280); }
.employee-info { flex: 1; min-width: 0; }
.employee-name { font-size: 17px; font-weight: 700; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.employee-position { font-size: 13px; color: var(--primary-orange); font-weight: 600; }
.employee-department { font-size: 12px; color: var(--text-muted); }

.employee-status { position: absolute; top: 16px; right: 16px; padding: 5px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
.employee-status.active { background: #dcfce7; color: #16a34a; }
.employee-status.inactive { background: #fef2f2; color: #dc2626; }

.employee-details { background: rgba(255,255,255,0.6); border: 1px solid var(--border-light); border-radius: 10px; padding: 14px; margin-bottom: 16px; }
.employee-detail { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid var(--border-light); }
.employee-detail:last-child { border-bottom: none; }
.employee-detail .icon { font-size: 16px; width: 24px; text-align: center; }
.employee-detail .value { font-size: 13px; color: var(--text-main); flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.employee-actions { display: flex; gap: 10px; }
.employee-actions a, .employee-actions button { flex: 1; padding: 10px; border-radius: 10px; font-size: 13px; font-weight: 600; cursor: pointer; border: none; display: flex; align-items: center; justify-content: center; gap: 6px; text-decoration: none; transition: all 0.2s; }
.employee-actions .btn-edit { background: #dbeafe; color: #2563eb; }
.employee-actions .btn-edit:hover { background: #bfdbfe; }
.employee-actions .btn-delete { background: #fef2f2; color: #dc2626; }
.employee-actions .btn-delete:hover { background: #fecaca; }

.empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
.empty-state .icon { font-size: 64px; margin-bottom: 16px; opacity: 0.5; }

.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; padding: 20px; }
.modal-overlay.active { display: flex; }
.modal { background: var(--bg-card); border-radius: 16px; padding: 32px; max-width: 400px; width: 100%; text-align: center; }
.modal-icon { font-size: 56px; margin-bottom: 16px; }
.modal h3 { font-size: 20px; font-weight: 700; margin-bottom: 8px; }
.modal p { font-size: 14px; color: var(--text-muted); margin-bottom: 24px; }
.modal-actions { display: flex; gap: 12px; justify-content: center; }

@media (max-width: 1024px) {
    .hamburger { display: flex; }
    .sidebar { transform: translateX(-100%); }
    .sidebar.open { transform: translateX(0); }
    .main { margin-left: 0; }
    .form-grid { grid-template-columns: 1fr; }
}
@media (max-width: 768px) {
    .main { padding: 16px; }
    .employees-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>
<div class="sidebar-overlay" onclick="toggleSidebar()"></div>

<div class="modal-overlay" id="deleteModal">
    <div class="modal">
        <div class="modal-icon">⚠️</div>
        <h3>Delete Employee?</h3>
        <p>This action cannot be undone.</p>
        <form method="POST">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="employee_id" id="deleteEmployeeId">
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-danger">🗑️ Delete</button>
            </div>
        </form>
    </div>
</div>

<div class="wrapper">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <div class="logo-icon">🏢</div>
            <h2>WorkNest</h2>
        </div>
        <nav class="menu">
            <span class="menu-label">Main Menu</span>
            <a href="../dashboard.php"><span class="icon">🏠</span> Dashboard</a>
            <a href="employee.php" class="active"><span class="icon">👥</span> Employees</a>
            <a href="../attendance/attendance.php"><span class="icon">📅</span> Attendance</a>
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
            <h1 class="page-title">Employees</h1>
        </div>
        <p class="subtitle">Manage your team members</p>

        <?php if ($message): ?>
            <div class="alert <?= $messageType; ?>"><?= $messageType === 'success' ? '✅' : '❌'; ?> <?= htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class="page-header-row">
            <div class="summary-cards">
                <div class="summary-card">
                    <div class="card-icon">👥</div>
                    <div class="card-info"><p>Total Employees</p><h2><?= $totalEmployees; ?></h2></div>
                </div>
            </div>
            <?php if (!$editEmployee): ?>
                <button class="btn btn-primary" onclick="toggleForm()" id="addBtn"><span id="btnIcon">➕</span> <span id="btnText">Add Employee</span></button>
            <?php else: ?>
                <a href="employee.php" class="btn btn-secondary">← Back</a>
            <?php endif; ?>
        </div>

        <div class="card form-card <?= $showForm ? 'active' : ''; ?>" id="formCard">
            <div class="card-header">
                <h2><?= $editEmployee ? '✏️ Edit Employee' : '👤 Add Employee'; ?></h2>
                <button class="btn-close" onclick="<?= $editEmployee ? "location='employee.php'" : 'toggleForm()'; ?>">✕</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="<?= $editEmployee ? 'update' : 'add'; ?>">
                <?php if ($editEmployee): ?><input type="hidden" name="employee_id" value="<?= $editEmployee['id']; ?>"><?php endif; ?>
                <div class="form-grid">
                    <div class="form-group"><label>Name *</label><input type="text" name="username" value="<?= htmlspecialchars($editEmployee['username'] ?? $formData['username'] ?? ''); ?>" required minlength="2" maxlength="100"></div>
                    <div class="form-group"><label>Email *</label><input type="email" name="email" value="<?= htmlspecialchars($editEmployee['email'] ?? $formData['email'] ?? ''); ?>" required></div>
                    <div class="form-group"><label>Password <?= $editEmployee ? '(leave blank to keep current)' : '*'; ?></label><div class="password-wrapper"><input type="password" name="password" id="passwordInput" <?= $editEmployee ? '' : 'required'; ?> minlength="8" title="Must be 8+ characters with uppercase, lowercase, number, and symbol (e.g. @#$%)"><button type="button" class="password-toggle" id="toggleBtn" onclick="togglePassword()"><svg id="eyeIcon" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></button></div></div>
                    <div class="form-group">
                        <label>Department / Function</label>
                        <select name="department" id="departmentSelect">
                            <option value="">Select department</option>
                            <option value="Software Development" <?= $selectedDepartment === 'Software Development' ? 'selected' : ''; ?>>Software Development</option>
                            <option value="Quality Assurance (QA)" <?= $selectedDepartment === 'Quality Assurance (QA)' ? 'selected' : ''; ?>>Quality Assurance (QA)</option>
                            <option value="Human Resources (HR)" <?= $selectedDepartment === 'Human Resources (HR)' ? 'selected' : ''; ?>>Human Resources (HR)</option>
                            <option value="IT Support / System Admin" <?= $selectedDepartment === 'IT Support / System Admin' ? 'selected' : ''; ?>>IT Support / System Admin</option>
                            <option value="DevOps / Infrastructure" <?= $selectedDepartment === 'DevOps / Infrastructure' ? 'selected' : ''; ?>>DevOps / Infrastructure</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Position</label>
                        <select name="position" id="positionSelect" data-selected-position="<?= htmlspecialchars($selectedPosition); ?>" disabled>
                            <option value="">Select position</option>
                        </select>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><?= $editEmployee ? '💾 Update' : '➕ Add'; ?></button>
                    <button type="button" class="btn btn-secondary" onclick="<?= $editEmployee ? "location='employee.php'" : 'toggleForm()'; ?>">Cancel</button>
                </div>
            </form>
        </div>

        <?php if (!$editEmployee && !$showForm): ?>
        <div class="card" id="listCard">
            <div class="card-header" style="display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
                <h2 style="margin-bottom: 0;">📋 All Employees</h2>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="font-size: 20px; color: var(--primary-orange);">🔍</span>
                    <input type="text" id="employeeSearch" placeholder="Search employees..." style="padding: 10px 16px; border-radius: 8px; border: 1px solid var(--border-light); font-size: 15px; min-width: 220px;">
                </div>
            </div>
            <?php if (empty($employees)): ?>
                <div class="empty-state"><div class="icon">👥</div><h3>No Employees Yet</h3><p>Add your first employee.</p></div>
            <?php else: ?>
                <div class="employees-grid" id="employeesGrid">
                    <?php foreach ($employees as $emp): ?>
                        <div class="employee-card" data-search="<?= strtolower(htmlspecialchars($emp['username'] . ' ' . $emp['email'] . ' ' . $emp['department'] . ' ' . $emp['position'])); ?>">
                            <div class="employee-header">
                                <div class="employee-avatar"><?= strtoupper(substr($emp['username'], 0, 2)); ?></div>
                                <div class="employee-info">
                                    <div class="employee-name"><?= htmlspecialchars($emp['username']); ?></div>
                                    <div class="employee-position"><?= htmlspecialchars($emp['position'] ?: 'No Position'); ?></div>
                                    <div class="employee-department"><?= htmlspecialchars($emp['department'] ?: 'No Department'); ?></div>
                                </div>
                            </div>
                            <div class="employee-details">
                                <div class="employee-detail"><span class="icon">📧</span><span class="value"><?= htmlspecialchars($emp['email']); ?></span></div>
                                <div class="employee-detail"><span class="icon">🏢</span><span class="value"><?= htmlspecialchars($emp['department'] ?: 'Not Assigned'); ?></span></div>
                                <div class="employee-detail"><span class="icon">💼</span><span class="value"><?= htmlspecialchars($emp['position'] ?: 'Not Assigned'); ?></span></div>
                            </div>
                            <div class="employee-actions">
                                <a href="employee.php?edit=<?= $emp['id']; ?>" class="btn-edit">✏️ Edit</a>
                                <button class="btn-delete" onclick="confirmDelete(<?= $emp['id']; ?>)">🗑️ Delete</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </main>
</div>

<script>
// Department → Position cascading dropdown
document.addEventListener('DOMContentLoaded', function() {
    const departmentSelect = document.getElementById('departmentSelect');
    const positionSelect = document.getElementById('positionSelect');

    if (departmentSelect && positionSelect) {
        const jobMap = {
            'Software Development': [
                'Software Engineer',
                'Backend Developer',
                'Frontend Developer',
                'Full Stack Developer'
            ],
            'Quality Assurance (QA)': [
                'QA Engineer',
                'Software Tester',
                'Automation Tester'
            ],
            'Human Resources (HR)': [
                'HR Manager',
                'HR Executive',
                'Recruiter'
            ],
            'IT Support / System Admin': [
                'IT Support Engineer',
                'System Administrator',
                'Network Administrator'
            ],
            'DevOps / Infrastructure': [
                'DevOps Engineer',
                'Cloud Engineer'
            ]
        };

        const initialSelectedPosition = positionSelect.dataset.selectedPosition || '';

        function populatePositions() {
            const dept = departmentSelect.value;
            const selectedPosition = positionSelect.dataset.selectedPosition || initialSelectedPosition;

            positionSelect.innerHTML = '';
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = 'Select position';
            positionSelect.appendChild(placeholder);

            if (dept && jobMap[dept]) {
                jobMap[dept].forEach(function(pos) {
                    const opt = document.createElement('option');
                    opt.value = pos;
                    opt.textContent = pos;
                    if (pos === selectedPosition) {
                        opt.selected = true;
                    }
                    positionSelect.appendChild(opt);
                });
                positionSelect.disabled = false;
            } else {
                positionSelect.disabled = true;
            }
        }

        departmentSelect.addEventListener('change', function() {
            // Clear any previously selected position when department changes
            positionSelect.dataset.selectedPosition = '';
            populatePositions();
        });

        // Initial population for both Add and Edit forms
        populatePositions();
    }
});

// Employee search filter
// Levenshtein Distance function
function levenshtein(a, b) {
    const m = a.length, n = b.length;
    if (m === 0) return n;
    if (n === 0) return m;
    const dp = Array.from({length: m+1}, () => Array(n+1).fill(0));
    for (let i = 0; i <= m; i++) dp[i][0] = i;
    for (let j = 0; j <= n; j++) dp[0][j] = j;
    for (let i = 1; i <= m; i++) {
        for (let j = 1; j <= n; j++) {
            if (a[i-1] === b[j-1]) {
                dp[i][j] = dp[i-1][j-1];
            } else {
                dp[i][j] = 1 + Math.min(dp[i-1][j], dp[i][j-1], dp[i-1][j-1]);
            }
        }
    }
    return dp[m][n];
}

document.getElementById('employeeSearch')?.addEventListener('input', function() {
    const q = this.value.trim().toLowerCase();
    const cards = document.querySelectorAll('#employeesGrid .employee-card');
    cards.forEach(card => {
        const text = card.getAttribute('data-search');
        
        const fields = text.split(/\s+/);
        let match = !q;
        if (!match && text.includes(q)) match = true;
        if (!match && q.length > 1) {
            for (const field of fields) {
                if (levenshtein(q, field) <= 2) { match = true; break; }
            }
        }
        card.style.display = match ? '' : 'none';
    });
});
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.querySelector('.sidebar-overlay').classList.toggle('active');
}
function toggleForm() {
    const form = document.getElementById('formCard');
    const list = document.getElementById('listCard');
    const icon = document.getElementById('btnIcon');
    const text = document.getElementById('btnText');
    form.classList.toggle('active');
    if (form.classList.contains('active')) {
        icon.textContent = '✕'; text.textContent = 'Cancel';
        if (list) list.style.display = 'none';
    } else {
        icon.textContent = '➕'; text.textContent = 'Add Employee';
        if (list) list.style.display = 'block';
    }
}
function confirmDelete(id) {
    document.getElementById('deleteEmployeeId').value = id;
    document.getElementById('deleteModal').classList.add('active');
}
function closeModal() {
    document.getElementById('deleteModal').classList.remove('active');
}
function togglePassword() {
    const input = document.getElementById('passwordInput');
    const icon = document.getElementById('eyeIcon');
    if (input.type === 'password') {
        input.type = 'text';
        icon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
    } else {
        input.type = 'password';
        icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
    }
}
document.getElementById('deleteModal').addEventListener('click', e => { if (e.target.id === 'deleteModal') closeModal(); });
</script>
</body>
</html>
