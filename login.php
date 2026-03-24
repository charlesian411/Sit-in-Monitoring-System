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

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $id_number = trim($_POST['id_number']);
    $password = $_POST['password'];

    if (empty($id_number) || empty($password)) {
        $error = "Please fill in all fields.";
    } else {
        $stmt = $conn->prepare("SELECT * FROM users WHERE id_number = ?");
        $stmt->bind_param("s", $id_number);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['id_number'] = $user['id_number'];
                $_SESSION['first_name'] = $user['first_name'];
                $_SESSION['last_name'] = $user['last_name'];
                $_SESSION['role'] = isset($user['role']) ? $user['role'] : 'student';
                $_SESSION['is_admin'] = ($_SESSION['role'] === 'admin');

                if ($_SESSION['is_admin']) {
                    header("Location: admin_dashboard.php");
                } else {
                    header("Location: dashboard.php");
                }
                exit();
            } else {
                $error = "Invalid password.";
            }
        } else {
            $error = "User not found.";
        }
        $stmt->close();
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
            <label class="form-label">ID Number</label>
            <input type="text" class="form-control" placeholder="Enter your ID number" name="id_number">
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