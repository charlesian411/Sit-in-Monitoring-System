<?php
session_start();

if (isset($_SESSION['user_id']) && !empty($_SESSION['is_admin'])) {
    header("Location: admin_dashboard.php");
    exit();
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $admin_id = trim($_POST['admin_id'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($admin_id === '' || $password === '') {
        $error = "Please fill in all fields.";
    } else {
        $valid_admin_id = 'admin123';
        $valid_password = 'Password123!';

        if (hash_equals($valid_admin_id, $admin_id) && hash_equals($valid_password, $password)) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = -1;
            $_SESSION['id_number'] = 'ADMIN';
            $_SESSION['first_name'] = 'System';
            $_SESSION['last_name'] = 'Admin';
            $_SESSION['role'] = 'admin';
            $_SESSION['is_admin'] = true;

            header("Location: admin_dashboard.php");
            exit();
        }

        $error = "Invalid admin credentials.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CCS | Admin Login</title>
    <link rel="stylesheet" href="style.css?v=7">
</head>
<body>

<nav>
    <span class="nav-brand">College of Computer Studies Sit-in Monitoring System</span>
    <ul class="nav-links">
        <li><a href="#">Home</a></li>
        <li><a href="#">About</a></li>
        <li><a href="login.php">Student Login</a></li>
        <li><a href="admin_login.php">Admin Login</a></li>
    </ul>
</nav>

<div class="page-wrapper">
    <div class="login-card">

        <div class="logo-section">
            <img src="ccslogo.png" alt="CCS Logo">
            <h1>Admin Portal</h1>
            <p>Sign in as administrator</p>
        </div>

        <div class="card-divider"></div>

        <form method="POST" action="admin_login.php">

        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="form-group">
            <label class="form-label">Admin ID</label>
            <input type="text" class="form-control" placeholder="Enter admin ID" name="admin_id" autocomplete="username">
        </div>

        <div class="form-group">
            <label class="form-label">Password</label>
            <input type="password" class="form-control" placeholder="Enter password" name="password" autocomplete="current-password">
        </div>

        <button type="submit" class="btn-login">Login</button>

        </form>

        <p class="register-prompt">
            Student account? <a href="login.php">Go to student login</a>
        </p>

    </div>
</div>

</body>
</html>
