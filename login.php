<?php
require_once 'config/db.php';
session_start();

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    if (!empty($_SESSION['is_admin'])) {
        header("Location: admin_dashboard.php");
    } else {
        header("Location: dashboard.php");
    }
    exit();
}

$error = "";

$conn->query("CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(150) NOT NULL DEFAULT 'System Admin',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$default_admin_id = 'admin123';
$default_admin_password = 'Password123!';

$seed_stmt = $conn->prepare("SELECT id FROM admins WHERE admin_id = ? LIMIT 1");
$seed_stmt->bind_param("s", $default_admin_id);
$seed_stmt->execute();
$seed_stmt->store_result();

if ($seed_stmt->num_rows === 0) {
    $default_hash = password_hash($default_admin_password, PASSWORD_DEFAULT);
    $insert_admin_stmt = $conn->prepare("INSERT INTO admins (admin_id, password_hash, full_name, is_active) VALUES (?, ?, 'System Admin', 1)");
    $insert_admin_stmt->bind_param("ss", $default_admin_id, $default_hash);
    $insert_admin_stmt->execute();
    $insert_admin_stmt->close();
}

$seed_stmt->close();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'];

    if (empty($login) || empty($password)) {
        $error = "Please fill in all fields.";
    } else {
        $stmt = $conn->prepare("SELECT id, admin_id, full_name, password_hash FROM admins WHERE admin_id = ? AND is_active = 1 LIMIT 1");
        $stmt->bind_param("s", $login);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $admin = $result->fetch_assoc();
            if (password_verify($password, $admin['password_hash'])) {
                $stmt->close();
                session_regenerate_id(true);
                $_SESSION['user_id'] = -1;
                $_SESSION['id_number'] = strtoupper($admin['admin_id']);
                $_SESSION['first_name'] = $admin['full_name'];
                $_SESSION['last_name'] = '';
                $_SESSION['role'] = 'admin';
                $_SESSION['is_admin'] = true;

                header("Location: admin_dashboard.php");
                exit();
            }
        }

        $stmt->close();

        $stmt = $conn->prepare("SELECT * FROM users WHERE id_number = ? AND role = 'student' LIMIT 1");
        $stmt->bind_param("s", $login);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            if (password_verify($password, $user['password'])) {
                $stmt->close();
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['id_number'] = $user['id_number'];
                $_SESSION['first_name'] = $user['first_name'];
                $_SESSION['last_name'] = $user['last_name'];
                $_SESSION['role'] = 'student';
                $_SESSION['is_admin'] = false;

                header("Location: dashboard.php");
                exit();
            }
        }

        $stmt->close();
        $error = "Invalid login credentials.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CCS | Login</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<nav>
    <span class="nav-brand">College of Computer Studies Sit-in Monitoring System</span>
    <ul class="nav-links">
        <li><a href="#">Home</a></li>
        <li class="dropdown">
            <button class="dropdown-toggle">Community</button>
            <div class="dropdown-menu">
                <a href="#">Forums</a>
                <a href="#">Members</a>
            </div>
        </li>
        <li><a href="#">About</a></li>
        <li><a href="login.php">Login</a></li>
        <li><a href="Register.php">Register</a></li>
    </ul>
</nav>

<div class="page-wrapper">
    <div class="login-card">

        <div class="logo-section">
            <img src="ccslogo.png" alt="CCS Logo">
            <h1>CCS Sit-in Monitoring</h1>
            <p>Sign in to your account</p>
        </div>

        <div class="card-divider"></div>

        <form method="POST" action="login.php">

        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="form-group">
            <label class="form-label">Login</label>
            <input type="text" class="form-control" placeholder="Enter your ID number or admin ID" name="login">
        </div>

        <div class="form-group">
            <label class="form-label">Password</label>
            <input type="password" class="form-control" placeholder="Enter your password" name="password">
        </div>

        <div class="form-options">
            <label class="remember-me">
                <input type="checkbox" name="remember"> Remember me
            </label>
            <a href="#" class="forgot-link">Forgot password?</a>
        </div>

        <button type="submit" class="btn-login">Login</button>

        </form>

        <p class="register-prompt">
            Don't have an account? <a href="Register.php">Register</a>
        </p>

    </div>
</div>

</body>
</html>