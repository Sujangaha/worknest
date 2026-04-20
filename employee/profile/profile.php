<?php
require_once '../../includes/auth_check.php';
require_once '../../includes/db.php';

$pdo = getPDO();
$userId = $_SESSION['user_id'];

$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    
    // Handle profile image upload
    $profileImage = null;
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $maxSize = 2 * 1024 * 1024; // 2MB
        
        if (!in_array($_FILES['profile_image']['type'], $allowedTypes)) {
            $message = 'Invalid file type. Only JPG, PNG, GIF, and WEBP are allowed.';
            $messageType = 'error';
        } elseif ($_FILES['profile_image']['size'] > $maxSize) {
            $message = 'File too large. Maximum size is 2MB.';
            $messageType = 'error';
        } else {
            $uploadDir = '../../uploads/profiles/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $extension = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
            $filename = 'user_' . $userId . '_' . time() . '.' . $extension;
            $uploadPath = $uploadDir . $filename;
            
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $uploadPath)) {
                $profileImage = 'uploads/profiles/' . $filename;
            } else {
                $message = 'Failed to upload image.';
                $messageType = 'error';
            }
        }
    }
    
    // Update user profile
    if (empty($message)) {
        if ($profileImage) {
            $stmt = $pdo->prepare("UPDATE users SET phone = ?, address = ?, profile_image = ? WHERE id = ?");
            $stmt->execute([$phone, $address, $profileImage, $userId]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET phone = ?, address = ? WHERE id = ?");
            $stmt->execute([$phone, $address, $userId]);
        }
        $_SESSION['message'] = 'Profile updated successfully!';
        $_SESSION['messageType'] = 'success';
    } else {
        $_SESSION['message'] = $message;
        $_SESSION['messageType'] = $messageType;
    }
    
    // Redirect to prevent form resubmission
    header('Location: profile.php');
    exit;
}

// Fetch user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'employee'");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    die('Employee not found');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - WorkNest</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../attendance/style.css">
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
                <a href="profile.php" class="active"><span class="icon">👤</span> Profile</a>
                <a href="../attendance/attendance.php"><span class="icon">📅</span> Attendance</a>
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
                <h1 class="page-title">My Profile</h1>
            </div>

            <?php if (isset($_SESSION['message'])): ?>
                <div class="alert <?= $_SESSION['messageType']; ?>">
                    <?= $_SESSION['messageType'] === 'success' ? '✅' : '❌'; ?> <?= htmlspecialchars($_SESSION['message']); ?>
                </div>
                <?php unset($_SESSION['message'], $_SESSION['messageType']); ?>
            <?php endif; ?>

            <div class="container">
                <!-- Profile Header Card -->
                <div class="card profile-header-card">
                    <div class="profile-header-content">
                        <div class="profile-avatar-section">
                            <?php 
                            $avatarSrc = $user['profile_image'] 
                                ? '../../' . htmlspecialchars($user['profile_image']) 
                                : 'https://ui-avatars.com/api/?name=' . urlencode($user['username']) . '&background=2AAA8A&color=fff&size=120';
                            ?>
                            <img src="<?= $avatarSrc; ?>" alt="Profile" class="profile-avatar">
                            <div class="profile-info">
                                <h2 class="profile-name"><?= htmlspecialchars($user['username']); ?></h2>
                                <p class="profile-position"><?= htmlspecialchars($user['position'] ?? 'Employee'); ?></p>
                                <span class="status-badge present"><?= ucfirst($user['status']); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Profile Details Card -->
                <div class="card">
                    <div style="display: flex; align-items: center; justify-content: space-between;">
                        <h2 style="margin-bottom: 0;">📋 Personal Information</h2>
                        <button id="showUpdateBtn" class="btn-primary" style="margin: 0;">
                            <span class="icon">✏️</span> Update Profile
                        </button>
                    </div>
                    <p class="subtitle">Your account details</p>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">📧 Email</span>
                            <span class="info-value"><?= htmlspecialchars($user['email']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">🏢 Department</span>
                            <span class="info-value"><?= htmlspecialchars($user['department'] ?? 'Not Assigned'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">💼 Position</span>
                            <span class="info-value"><?= htmlspecialchars($user['position'] ?? 'Not Set'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">📞 Phone</span>
                            <span class="info-value"><?= htmlspecialchars($user['phone'] ?? 'Not Set'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">📅 Date of Joining</span>
                            <span class="info-value">
                                <?= $user['created_at'] ? date('F d, Y', strtotime($user['created_at'])) : 'Not Set'; ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">📍 Address</span>
                            <span class="info-value"><?= htmlspecialchars($user['address'] ?? 'Not Set'); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Update Profile Card -->
                <div class="card" id="updateProfileCard" style="display: none; position: relative;">
                    <button id="closeUpdateBtn" type="button" style="position: absolute; top: 18px; right: 18px; background: none; border: none; font-size: 22px; color: #888; cursor: pointer;" title="Close">✕</button>
                    <h2>✏️ Update Profile</h2>
                    <p class="subtitle">Edit your personal information</p>
                    <form method="POST" enctype="multipart/form-data" class="profile-form">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="phone">📞 Phone Number</label>
                                <input type="tel" id="phone" name="phone" 
                                       value="<?= htmlspecialchars($user['phone'] ?? ''); ?>" 
                                       placeholder="Enter your phone number">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="address">📍 Address</label>
                            <textarea id="address" name="address" rows="3"
                                      placeholder="Enter your address"><?= htmlspecialchars($user['address'] ?? ''); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label>📷 Profile Photo</label>
                            <div class="file-upload-wrapper">
                                <input type="file" id="profile_image" name="profile_image" accept="image/*">
                                <label for="profile_image" class="file-upload-btn">
                                    <span class="icon">📷</span> Choose Image (Max 2MB)
                                </label>
                                <span class="file-name" id="fileName">No file chosen</span>
                            </div>
                        </div>
                        <button type="submit" class="btn-primary">
                            <span class="icon">💾</span> Save Changes
                        </button>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <style>
        /* Profile-specific styles */
        .profile-header-card {
            background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-light));
            color: white;
            padding: 32px;
        }
        .profile-header-card:hover {
            box-shadow: var(--shadow-orange);
        }
        .profile-header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .profile-avatar-section {
            display: flex;
            align-items: center;
            gap: 24px;
        }
        .profile-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            border: 4px solid white;
            object-fit: cover;
            box-shadow: 0 4px 16px rgba(0,0,0,0.2);
        }
        .profile-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .profile-name {
            font-size: 24px;
            font-weight: 700;
            color: white;
            margin: 0;
        }
        .profile-position {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 8px;
        }
        .profile-header-card .status-badge {
            width: fit-content;
        }

        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 16px;
            margin-top: 20px;
        }
        .info-item {
            background: var(--bg-input);
            border: 1px solid var(--border-light);
            border-radius: 12px;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .info-label {
            font-size: 12px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        .info-value {
            font-size: 15px;
            font-weight: 600;
            color: var(--text-main);
        }

        /* Form Styles */
        .profile-form {
            margin-top: 20px;
        }
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 16px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-main);
        }
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid var(--border-light);
            border-radius: 12px;
            font-size: 14px;
            font-family: inherit;
            background: var(--bg-input);
            transition: all 0.3s ease;
        }
        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary-orange);
            box-shadow: 0 0 0 4px rgba(42, 170, 138, 0.1);
        }
        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        /* File Upload */
        .file-upload-wrapper {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .file-upload-wrapper input[type="file"] {
            display: none;
        }
        .file-upload-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            background: var(--bg-input);
            border: 2px dashed var(--border-light);
            border-radius: 12px;
            font-size: 14px;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
        }
        .file-upload-btn:hover {
            border-color: var(--primary-orange);
            color: var(--primary-orange);
            background: rgba(42, 170, 138, 0.05);
        }
        .file-name {
            font-size: 13px;
            color: var(--text-muted);
        }

        /* Submit Button */
        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-light));
            color: white;
            border: none;
            padding: 14px 28px;
            font-size: 15px;
            font-weight: 600;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: var(--shadow-orange);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 24px rgba(42, 170, 138, 0.35);
        }

        /* Alert */
        .alert {
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert.success { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .alert.error { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }

        /* Responsive */
        @media (max-width: 768px) {
            .profile-avatar-section {
                flex-direction: column;
                text-align: center;
            }
            .profile-info {
                align-items: center;
            }
            .info-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
            document.querySelector('.sidebar-overlay').classList.toggle('active');
            document.body.classList.toggle('sidebar-open');
        }

        // Show filename when file is selected
        document.getElementById('profile_image')?.addEventListener('change', function(e) {
            const fileName = e.target.files[0]?.name || 'No file chosen';
            document.getElementById('fileName').textContent = fileName;
        });

        // Show update profile card only when button is clicked
        document.getElementById('showUpdateBtn').addEventListener('click', function() {
            const updateCard = document.getElementById('updateProfileCard');
            updateCard.style.display = 'block';
            this.style.display = 'none';
            // Scroll to update section
            setTimeout(() => {
                updateCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }, 100);
        });

        // Close update profile card
        document.getElementById('closeUpdateBtn').addEventListener('click', function() {
            document.getElementById('updateProfileCard').style.display = 'none';
            document.getElementById('showUpdateBtn').style.display = 'inline-flex';
        });
    </script>
</body>
</html>
