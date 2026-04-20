<?php
require_once '../../includes/auth_check.php';
require_once '../../includes/db.php';

$pdo = getPDO();

// Create notices table if not exists
$pdo->exec("
    CREATE TABLE IF NOT EXISTS notices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        description TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )
");

$message = '';
$messageType = '';
$editNotice = null;
$showForm = false;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Create new notice
    if ($action === 'create') {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        
        if (empty($title) || empty($description)) {
            $message = 'Please fill in all fields.';
            $messageType = 'error';
            $_SESSION['show_form'] = true;
        } else {
            $stmt = $pdo->prepare("INSERT INTO notices (title, description) VALUES (?, ?)");
            $stmt->execute([$title, $description]);
            $message = 'Notice created successfully!';
            $messageType = 'success';
        }
        
        $_SESSION['notice_message'] = $message;
        $_SESSION['notice_message_type'] = $messageType;
        header('Location: notices.php');
        exit;
    }
    
    // Update notice
    if ($action === 'update') {
        $id = (int)$_POST['notice_id'];
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        
        if (empty($title) || empty($description)) {
            $message = 'Please fill in all fields.';
            $messageType = 'error';
        } else {
            $stmt = $pdo->prepare("UPDATE notices SET title = ?, description = ? WHERE id = ?");
            $stmt->execute([$title, $description, $id]);
            $message = 'Notice updated successfully!';
            $messageType = 'success';
        }
    }
    
    // Delete notice
    if ($action === 'delete') {
        $id = (int)$_POST['notice_id'];
        $stmt = $pdo->prepare("DELETE FROM notices WHERE id = ?");
        $stmt->execute([$id]);
        $message = 'Notice deleted successfully!';
        $messageType = 'success';
    }
}

// Check for edit mode
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM notices WHERE id = ?");
    $stmt->execute([$editId]);
    $editNotice = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($editNotice) {
        $showForm = true;
    }
}

// Get messages from session
if (isset($_SESSION['notice_message'])) {
    $message = $_SESSION['notice_message'];
    $messageType = $_SESSION['notice_message_type'];
    unset($_SESSION['notice_message'], $_SESSION['notice_message_type']);
}

if (isset($_SESSION['show_form'])) {
    $showForm = true;
    unset($_SESSION['show_form']);
}

// Fetch all notices
$stmt = $pdo->prepare("SELECT * FROM notices ORDER BY created_at DESC");
$stmt->execute();
$notices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count notices
$totalNotices = count($notices);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Notices - WorkNest Admin</title>
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
    border-radius: 14px; display: flex; align-items: center; justify-content: center;
    font-size: 24px; color: #fff; box-shadow: var(--shadow-orange);
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
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

/* Top Bar */
.top-bar { display: flex; align-items: center; gap: 16px; margin-bottom: 8px; }
.page-title { font-size: 28px; font-weight: 800; color: var(--text-main); }
.hamburger { display: none; flex-direction: column; justify-content: center; align-items: center; width: 40px; height: 40px; background: var(--bg-card); border: 1px solid var(--border-accent); border-radius: 8px; cursor: pointer; gap: 5px; }
.hamburger:hover { background: var(--bg-input); }
.hamburger span { display: block; width: 20px; height: 2px; background: var(--text-main); border-radius: 2px; }

.subtitle { color: var(--text-muted); margin-bottom: 30px; font-size: 15px; }

/* Page Header Row */
.page-header-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 16px;
}
.page-header-row .summary-card {
    margin-bottom: 0;
}

/* Alert Messages */
.alert {
    padding: 14px 20px;
    border-radius: 12px;
    margin-bottom: 24px;
    font-size: 14px;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 10px;
}
.alert.success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
.alert.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

/* Summary Card */
.summary-card {
    background: var(--bg-card);
    border: 1px solid var(--border-light);
    border-radius: 14px;
    padding: 24px;
    box-shadow: var(--shadow-sm);
    margin-bottom: 24px;
    display: inline-flex;
    align-items: center;
    gap: 16px;
}
.summary-card .card-icon {
    width: 56px; height: 56px;
    background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-light));
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 28px; color: #fff;
    box-shadow: var(--shadow-orange);
}
.summary-card .card-info p { margin: 0; color: var(--text-muted); font-size: 14px; }
.summary-card .card-info h2 { margin: 4px 0 0; font-size: 32px; font-weight: 800; color: var(--text-main); }

/* Cards */
.card {
    background: var(--bg-card);
    border: 1px solid var(--border-light);
    border-radius: 16px;
    padding: 28px;
    box-shadow: var(--shadow-sm);
    margin-bottom: 24px;
}
.card:hover { box-shadow: var(--shadow-md); }
.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--border-light);
}
.card-header h2 { font-size: 18px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 10px; }
.card-header h2 .title-icon { font-size: 22px; }

/* Form */
.form-grid { display: grid; grid-template-columns: 1fr; gap: 20px; }
.form-group { margin-bottom: 0; }
.form-group label { display: block; font-size: 14px; font-weight: 600; color: var(--text-main); margin-bottom: 8px; }
.form-group input, .form-group textarea {
    width: 100%;
    padding: 14px 16px;
    border: 2px solid var(--border-light);
    border-radius: 12px;
    font-size: 14px;
    background: var(--bg-input);
    transition: all 0.2s ease;
    color: var(--text-main);
}
.form-group input:focus, .form-group textarea:focus {
    outline: none;
    border-color: var(--primary-orange);
    box-shadow: 0 0 0 4px rgba(42, 170, 138, 0.1);
}
.form-group textarea { min-height: 120px; resize: vertical; }
.form-group input::placeholder, .form-group textarea::placeholder { color: var(--text-light); }

/* Buttons */
.btn {
    padding: 12px 24px;
    border: none;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.btn-primary {
    background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-light));
    color: #fff;
}
.btn-primary:hover { transform: translateY(-2px); box-shadow: var(--shadow-orange); }
.btn-secondary {
    background: var(--bg-input);
    color: var(--text-main);
    border: 1px solid var(--border-accent);
}
.btn-secondary:hover { background: var(--bg-card); }
.btn-danger {
    background: #fef2f2;
    color: #dc2626;
    border: 1px solid #fecaca;
}
.btn-danger:hover { background: #fee2e2; }
.form-actions { display: flex; gap: 12px; margin-top: 20px; }

/* Notice Grid */
.notices-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    gap: 20px;
}
.notice-card {
    background: var(--bg-input);
    border: 1px solid var(--border-light);
    border-radius: 14px;
    padding: 24px;
    position: relative;
    transition: all 0.3s ease;
}
.notice-card::before {
    content: '';
    position: absolute;
    left: 0; top: 0;
    width: 5px; height: 100%;
    background: linear-gradient(180deg, var(--primary-orange), var(--primary-orange-light));
    border-radius: 14px 0 0 14px;
}
.notice-card:hover {
    border-color: var(--border-accent);
    transform: translateY(-4px);
    box-shadow: var(--shadow-md);
}
.notice-card h3 {
    font-size: 17px;
    font-weight: 700;
    color: var(--text-main);
    margin-bottom: 10px;
    padding-right: 80px;
}
.notice-card p {
    font-size: 14px;
    color: var(--text-muted);
    line-height: 1.6;
    margin-bottom: 16px;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.notice-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
}
.notice-date {
    font-size: 12px;
    color: var(--primary-orange);
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 6px;
}
.notice-actions {
    display: flex;
    gap: 8px;
}
.notice-actions a, .notice-actions button {
    padding: 8px 14px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
    border: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.btn-edit {
    background: #dbeafe;
    color: #2563eb;
}
.btn-edit:hover { background: #bfdbfe; }
.btn-delete {
    background: #fef2f2;
    color: #dc2626;
}
.btn-delete:hover { background: #fecaca; }

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-muted);
}
.empty-state .icon { font-size: 64px; margin-bottom: 16px; opacity: 0.5; }
.empty-state h3 { font-size: 18px; font-weight: 600; color: var(--text-main); margin-bottom: 8px; }

/* Delete Modal */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0;
    width: 100%; height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.modal-overlay.active { display: flex; }
.modal {
    background: var(--bg-card);
    border-radius: 16px;
    padding: 32px;
    max-width: 400px;
    width: 100%;
    text-align: center;
    animation: modalSlide 0.3s ease;
    box-shadow: var(--shadow-lg);
}
@keyframes modalSlide { from { opacity: 0; transform: scale(0.9); } to { opacity: 1; transform: scale(1); } }
.modal-icon { font-size: 56px; margin-bottom: 16px; }
.modal h3 { font-size: 20px; font-weight: 700; color: var(--text-main); margin-bottom: 8px; }
.modal p { font-size: 14px; color: var(--text-muted); margin-bottom: 24px; line-height: 1.5; }
.modal-actions { display: flex; gap: 12px; justify-content: center; }

/* Form Card - Hidden by default */
.form-card {
    display: none;
    animation: slideDown 0.3s ease;
}
.form-card.active {
    display: block;
}
@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Close Button */
.btn-close {
    background: var(--bg-input);
    border: 1px solid var(--border-accent);
    width: 36px;
    height: 36px;
    border-radius: 10px;
    cursor: pointer;
    font-size: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
    color: var(--text-muted);
}
.btn-close:hover {
    background: #fef2f2;
    color: #dc2626;
    border-color: #fecaca;
}

/* Responsive */
@media (max-width: 1024px) {
    .hamburger { display: flex; }
    .sidebar { transform: translateX(-100%); }
    .sidebar.open { transform: translateX(0); }
    .main { margin-left: 0; }
}
@media (max-width: 768px) {
    .main { padding: 16px; }
    .notices-grid { grid-template-columns: 1fr; }
    .page-header-row { flex-direction: column; align-items: stretch; }
}
</style>
</head>
<body>
<!-- Sidebar Overlay -->
<div class="sidebar-overlay" onclick="toggleSidebar()"></div>

<!-- Delete Confirmation Modal -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal">
        <div class="modal-icon">⚠️</div>
        <h3>Delete Notice?</h3>
        <p>Are you sure you want to delete this notice? This action cannot be undone.</p>
        <form method="POST" id="deleteForm">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="notice_id" id="deleteNoticeId" value="">
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                <button type="submit" class="btn btn-danger">🗑️ Delete</button>
            </div>
        </form>
    </div>
</div>

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
            <a href="../leave/leave.php"><span class="icon">🗓️</span> Leave Requests</a>
            <a href="notices.php" class="active"><span class="icon">📢</span> Notices</a>
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
            <h1 class="page-title">Notices</h1>
        </div>
        <p class="subtitle">Create and manage company announcements</p>

        <!-- Alert Messages -->
        <?php if ($message): ?>
            <div class="alert <?= $messageType; ?>">
                <?= $messageType === 'success' ? '✅' : '❌'; ?>
                <?= htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Header with Summary and Add Button -->
        <div class="page-header-row">
            <div class="summary-card">
                <div class="card-icon">📢</div>
                <div class="card-info">
                    <p>Total Notices</p>
                    <h2><?= $totalNotices; ?></h2>
                </div>
            </div>
            
            <?php if (!$editNotice): ?>
                <button class="btn btn-primary" onclick="toggleForm()" id="addNoticeBtn">
                    <span id="btnIcon">➕</span> <span id="btnText">Add New Notice</span>
                </button>
            <?php else: ?>
                <a href="notices.php" class="btn btn-secondary">← Back to All Notices</a>
            <?php endif; ?>
        </div>

        <!-- Create/Edit Notice Form (Hidden by default) -->
        <div class="card form-card <?= ($showForm || $editNotice) ? 'active' : ''; ?>" id="noticeFormCard">
            <div class="card-header">
                <h2>
                    <span class="title-icon"><?= $editNotice ? '✏️' : '📝'; ?></span>
                    <?= $editNotice ? 'Edit Notice' : 'Create New Notice'; ?>
                </h2>
                <button type="button" class="btn-close" onclick="<?= $editNotice ? "window.location.href='notices.php'" : 'toggleForm()'; ?>" title="Close">✕</button>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="<?= $editNotice ? 'update' : 'create'; ?>">
                <?php if ($editNotice): ?>
                    <input type="hidden" name="notice_id" value="<?= $editNotice['id']; ?>">
                <?php endif; ?>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="title">Notice Title *</label>
                        <input type="text" id="title" name="title" placeholder="Enter notice title" 
                               value="<?= htmlspecialchars($editNotice['title'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="description">Description *</label>
                        <textarea id="description" name="description" placeholder="Enter notice description" required><?= htmlspecialchars($editNotice['description'] ?? ''); ?></textarea>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <?= $editNotice ? '💾 Update Notice' : '📤 Publish Notice'; ?>
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="<?= $editNotice ? "window.location.href='notices.php'" : 'toggleForm()'; ?>">
                        Cancel
                    </button>
                </div>
            </form>
        </div>

        <!-- Notices List (Hidden when adding/editing) -->
        <?php if (!$editNotice && !$showForm): ?>
        <div class="card" id="noticeListCard">
            <div class="card-header">
                <h2><span class="title-icon">📋</span> All Notices</h2>
            </div>
            
            <?php if (empty($notices)): ?>
                <div class="empty-state">
                    <div class="icon">📭</div>
                    <h3>No Notices Yet</h3>
                    <p>Create your first notice using the button above.</p>
                </div>
            <?php else: ?>
                <div class="notices-grid">
                    <?php foreach ($notices as $notice): ?>
                        <div class="notice-card">
                            <h3><?= htmlspecialchars($notice['title']); ?></h3>
                            <p><?= nl2br(htmlspecialchars($notice['description'])); ?></p>
                            <div class="notice-meta">
                                <span class="notice-date">
                                    📅 <?= date('M d, Y \a\t h:i A', strtotime($notice['created_at'])); ?>
                                </span>
                                <div class="notice-actions">
                                    <a href="notices.php?edit=<?= $notice['id']; ?>" class="btn-edit">
                                        ✏️ Edit
                                    </a>
                                    <button type="button" class="btn-delete" onclick="confirmDelete(<?= $notice['id']; ?>, '<?= htmlspecialchars(addslashes($notice['title'])); ?>')">
                                        🗑️ Delete
                                    </button>
                                </div>
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
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.querySelector('.sidebar-overlay').classList.toggle('active');
    document.body.classList.toggle('sidebar-open');
}

function toggleForm() {
    const formCard = document.getElementById('noticeFormCard');
    const listCard = document.getElementById('noticeListCard');
    const btnIcon = document.getElementById('btnIcon');
    const btnText = document.getElementById('btnText');
    
    formCard.classList.toggle('active');
    
    if (formCard.classList.contains('active')) {
        btnIcon.textContent = '✕';
        btnText.textContent = 'Cancel';
        if (listCard) listCard.style.display = 'none';
        formCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } else {
        btnIcon.textContent = '➕';
        btnText.textContent = 'Add New Notice';
        if (listCard) listCard.style.display = 'block';
        document.getElementById('title').value = '';
        document.getElementById('description').value = '';
    }
}

function confirmDelete(noticeId, noticeTitle) {
    document.getElementById('deleteNoticeId').value = noticeId;
    document.getElementById('deleteModal').classList.add('active');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('active');
}

// Close modal when clicking outside
document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeDeleteModal();
    }
});
</script>
</body>
</html>
