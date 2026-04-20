<?php
session_start();

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin') {
        header('Location: admin/dashboard.php');
    } else {
        header('Location: employee/dashboard.php');
    }
    exit;
}

// Check if user came from landing page
$validAccess = false;

if (isset($_SERVER['HTTP_REFERER'])) {
    $referer = $_SERVER['HTTP_REFERER'];
    if (strpos($referer, 'index.html') !== false || 
        strpos($referer, 'index.php') !== false || 
        strpos($referer, 'login.php') !== false ||
        strpos($referer, 'register.php') !== false) {
        $validAccess = true;
    }
}

// Allow POST requests, URL parameter, or session flag
if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_SESSION['from_landing'])) {
    $validAccess = true;
}

if (isset($_GET['ref']) && $_GET['ref'] === 'landing') {
    $_SESSION['from_landing'] = true;
    $validAccess = true;
}

// Redirect if accessed directly
if (!$validAccess && !isset($_SESSION['from_landing'])) {
    header('Location: index.html');
    exit;
}

require 'includes/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    $email = filter_var($email, FILTER_SANITIZE_EMAIL);

    $pdo = getPDO();

    $stmt = $pdo->prepare('SELECT id, username, password, role, status FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        $loginOk = false;

        // First try secure hash verification
        if (password_verify($password, $user['password'])) {
            $loginOk = true;
        } elseif ($password === $user['password']) {
            // Backward-compatibility: plaintext password stored earlier
            // On successful legacy match, upgrade to a hashed password
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $update = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
            $update->execute([$newHash, $user['id']]);
            $loginOk = true;
        }

        if ($loginOk) {
            if ($user['role'] === 'employee') {
                if ($user['status'] !== 'active') {
                    $error = 'Your account is inactive. Contact admin.';
                } else {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];
                    unset($_SESSION['from_landing']);

                    header('Location: employee/dashboard.php');
                    exit;
                }
            } elseif ($user['role'] === 'admin') {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                unset($_SESSION['from_landing']);

                header('Location: admin/dashboard.php');
                exit;
            } else {
                $error = 'Invalid user role';
            }
        } else {
            $error = 'Invalid email or password';
        }
    } else {
        $error = 'Invalid email or password';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WorkNest - Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        
        body {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background: #f5f5f5;
        }

        header {
            background: #ffffff;
            border-bottom: 1px solid #e0e0e0;
            padding: 16px 24px;
        }

        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .logo-icon { font-size: 24px; }

        .logo-text {
            font-size: 22px;
            font-weight: 600;
            color: #2AAA8A;
        }

        .btn-back {
            padding: 10px 20px;
            background: transparent;
            border: 1px solid #2AAA8A;
            color: #2AAA8A;
            font-weight: 500;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
        }

        .btn-back:hover {
            background: #2AAA8A;
            color: #ffffff;
        }

        main {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 16px;
        }

        .login-container {
            width: 100%;
            max-width: 400px;
        }

        .login-card {
            background: #ffffff;
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            padding: 40px 32px;
        }

        .login-header {
            text-align: center;
            margin-bottom: 32px;
        }

        .login-header h1 {
            font-size: 24px;
            font-weight: 600;
            color: #333333;
            margin-bottom: 8px;
        }

        .login-header p {
            color: #666666;
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: #333333;
            margin-bottom: 8px;
        }

        .form-group input {
            width: 100%;
            padding: 12px 14px;
            background: #ffffff;
            border: 1px solid #d0d0d0;
            border-radius: 8px;
            font-size: 14px;
            color: #333333;
        }

        .password-wrapper {
            display: flex;
            align-items: center;
            border: 1px solid #d0d0d0;
            border-radius: 8px;
            background: #ffffff;
            overflow: hidden;
        }

        .password-wrapper input {
            border: none;
            flex: 1;
            padding-right: 0;
        }

        .password-wrapper input:focus {
            outline: none;
        }

        .password-wrapper:focus-within {
            border-color: #2AAA8A;
        }

        .toggle-password {
            border: none;
            background: transparent;
            padding: 0 10px;
            cursor: pointer;
            color: #666666;
            font-size: 18px;
        }

        .toggle-password:focus {
            outline: none;
        }

        .form-group input::placeholder {
            color: #999999;
        }

        .form-group input:focus {
            outline: none;
            border-color: #2AAA8A;
        }

        .remember-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 24px;
        }

        .remember-group input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: #2AAA8A;
        }

        .remember-group label {
            font-size: 14px;
            color: #666666;
            cursor: pointer;
        }

        .alert {
            padding: 12px 14px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .alert.error {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        .btn-login {
            width: 100%;
            padding: 12px;
            background: #2AAA8A;
            color: #ffffff;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-login:hover {
            background: #238b72;
        }

        .login-footer {
            margin-top: 24px;
            text-align: center;
        }

        .login-footer p {
            font-size: 14px;
            color: #666666;
        }

        .login-footer a {
            color: #2AAA8A;
            text-decoration: none;
        }

        .login-footer a:hover {
            text-decoration: underline;
        }

        footer {
            background: #ffffff;
            border-top: 1px solid #e0e0e0;
            padding: 20px;
            text-align: center;
        }

        footer p {
            color: #666666;
            font-size: 13px;
        }

        @media (max-width: 480px) {
            .login-card { padding: 32px 24px; }
            .login-header h1 { font-size: 22px; }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header>
        <div class="header-content">
            <div class="logo">
                <span class="logo-icon">🏢</span>
                <span class="logo-text">WorkNest</span>
            </div>
            <a href="index.html" class="btn-back">Back to Home</a>
        </div>
    </header>

    <!-- Main Content -->
    <main>
        <div class="login-container">
            <div class="login-card">
                <div class="login-header">
                    <h1>Welcome Back</h1>
                    <p>Sign in to your WorkNest account</p>
                </div>

                <?php if (!empty($error)): ?>
                    <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            placeholder="Enter your email" 
                            value="<?php echo htmlspecialchars($email ?? ''); ?>"
                            required>
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="password-wrapper">
                            <input 
                                type="password" 
                                id="password" 
                                name="password" 
                                placeholder="Enter your password" 
                                required>
                            <button type="button" class="toggle-password" aria-label="Show password">
                                👁
                            </button>
                        </div>
                    </div>

                    <div class="remember-group">
                        <input type="checkbox" id="remember" name="remember">
                        <label for="remember">Remember me</label>
                    </div>

                    <button type="submit" class="btn-login">Sign In</button>
                </form>

    
            </div>
        </div>
    </main>
    <script>
        // Toggle password visibility (hidden by default, optional view)
        document.addEventListener('DOMContentLoaded', function () {
            var toggleBtn = document.querySelector('.toggle-password');
            var passwordInput = document.getElementById('password');

            if (toggleBtn && passwordInput) {
                toggleBtn.addEventListener('click', function () {
                    var isHidden = passwordInput.type === 'password';
                    passwordInput.type = isHidden ? 'text' : 'password';
                    // Use eye icon for both states (no monkey icon)
                    toggleBtn.textContent = '👁';
                    toggleBtn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
                });
            }
        });
    </script>
</body>
</html>
