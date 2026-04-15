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
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $id_number = trim($_POST['id_number']);
    $last_name = trim($_POST['last_name']);
    $first_name = trim($_POST['first_name']);
    $middle_name = trim($_POST['middle_name']);
    $course = trim($_POST['course']);
    $course_level = (int) $_POST['course_level'];
    $email = trim($_POST['email']);
    $address = trim($_POST['address']);
    $password = $_POST['password'];
    $repeat_password = $_POST['repeat_password'];

    if (empty($id_number) || empty($last_name) || empty($first_name) || empty($course) || empty($course_level) || empty($email) || empty($password) || empty($repeat_password)) {
        $error = "Please fill in all required fields.";
    } elseif ($password !== $repeat_password) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        // Check if ID number or email already exists
        $check = $conn->prepare("SELECT id FROM users WHERE id_number = ? OR email = ?");
        $check->bind_param("ss", $id_number, $email);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = "ID number or email already exists.";
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (id_number, last_name, first_name, middle_name, course, course_level, email, address, password) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssisss", $id_number, $last_name, $first_name, $middle_name, $course, $course_level, $email, $address, $hashed);

            if ($stmt->execute()) {
                $success = "Registration successful! You can now login.";
            } else {
                $error = "Registration failed. Please try again.";
            }
            $stmt->close();
        }
        $check->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CCS | Register</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<!-- NAVBAR -->
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

<!-- MAIN -->
<div class="page-wrapper">
    <div class="register-card">

        <!-- Back Button -->
        <a href="login.php" class="back-btn">Back</a>

        <!-- Title -->
        <h2 class="register-title">Sign up</h2>

        <div class="register-body">

            <!-- FORM -->
            <div class="register-form">

                <form method="POST" action="register.php">

                <?php if (!empty($error)): ?>
                    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <?php if (!empty($success)): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>

                <div class="form-group">
                    <input type="text" class="form-control" name="id_number">
                    <span class="form-label">ID Number</span>
                </div>

                <div class="form-group">
                    <input type="text" class="form-control" name="last_name">
                    <span class="form-label">Last Name</span>
                </div>

                <div class="form-group">
                    <input type="text" class="form-control" name="first_name">
                    <span class="form-label">First Name</span>
                </div>

                <div class="form-group">
                    <input type="text" class="form-control" name="middle_name">
                    <span class="form-label">Middle Name</span>
                </div>
                
                <div class="form-group">
                    <select class="form-control" name="course" required>
                        <option value="" disabled selected>Select Course</option>
                        <option value="BSIT">BSIT - Bachelor of Science in Information Technology</option>
                        <option value="BSCS">BSCS - Bachelor of Science in Computer Science</option>
                        <option value="BSIS">BSIS - Bachelor of Science in Information Systems</option>
                    </select>
                    <span class="form-label">Course</span>
                </div>

                <!-- Changed to dropdown -->
                <div class="form-group">
                    <select class="form-control" name="course_level" required>
                        <option value="" disabled selected>Select Course Level</option>
                        <option value="1">1st Year</option>
                        <option value="2">2nd Year</option>
                        <option value="3">3rd Year</option>
                        <option value="4">4th Year</option>
                    </select>
                    <span class="form-label">Course Level</span>
                </div>

                <div class="form-group">
                    <input type="password" class="form-control" name="password">
                    <span class="form-label">Password</span>
                </div>

                <div class="form-group">
                    <input type="password" class="form-control" name="repeat_password">
                    <span class="form-label">Repeat your password</span>
                </div>

                <div class="form-group">
                    <input type="email" class="form-control" name="email">
                    <span class="form-label">Email</span>
                </div>

                <!-- Changed to dropdown -->
                

                <div class="form-group">
                    <input type="text" class="form-control" name="address">
                    <span class="form-label">Address</span>
                </div>

                <button type="submit" class="btn-register">Register</button>

                </form>

                <p class="register-prompt" style="margin-top: 0.9rem;">
                    Already have an account? <a href="login.php">Login</a>
                </p>

            </div>

            <!-- ILLUSTRATION (unchanged) -->
            <div class="register-illustration">
                <svg width="220" height="280" viewBox="0 0 220 280" xmlns="http://www.w3.org/2000/svg">
                    <!-- ... your SVG code remains exactly the same ... -->
                </svg>
            </div>

        </div>
    </div>
</div>

</body>
</html>