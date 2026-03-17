<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once 'config/db.php';

$error = "";
$user_id = (int) $_SESSION['user_id'];

// Fetch current user data
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $id_number = trim($_POST['id_number']);
    $last_name = trim($_POST['last_name']);
    $first_name = trim($_POST['first_name']);
    $middle_name = trim($_POST['middle_name']);
    $course = trim($_POST['course']);
    $course_level = (int) $_POST['course_level'];
    $email = trim($_POST['email']);
    $address = trim($_POST['address']);

    if (empty($id_number) || empty($last_name) || empty($first_name) || empty($course) || empty($course_level) || empty($email)) {
        $error = "Please fill in all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        // Check duplicates excluding current user
        $check = $conn->prepare("SELECT id FROM users WHERE (id_number = ? OR email = ?) AND id != ?");
        $check->bind_param("ssi", $id_number, $email, $user_id);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = "ID number or email is already in use.";
        } else {
            $update = $conn->prepare("UPDATE users SET id_number = ?, last_name = ?, first_name = ?, middle_name = ?, course = ?, course_level = ?, email = ?, address = ? WHERE id = ?");
            $update->bind_param("sssssissi", $id_number, $last_name, $first_name, $middle_name, $course, $course_level, $email, $address, $user_id);

            if ($update->execute()) {
                $_SESSION['first_name'] = $first_name;
                $_SESSION['last_name'] = $last_name;
                header("Location: dashboard.php?updated=1");
                exit();
            } else {
                $error = "Failed to update profile. Please try again.";
            }

            $update->close();
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
    <title>CCS | Edit Profile</title>
    <link rel="stylesheet" href="style.css?v=2">
</head>
<body>

<nav>
    <span class="nav-brand">College of Computer Studies Sit-in Monitoring System</span>
    <ul class="nav-links">
        <li><a href="dashboard.php">Home</a></li>
        <li><a href="edit_profile.php">Edit Profile</a></li>
        <li><a href="logout.php">Logout</a></li>
    </ul>
</nav>

<div class="page-wrapper">
    <div class="dashboard-card">
        <h2 class="register-title">Edit Student Profile</h2>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="edit_profile.php">
            <div class="form-group">
                <label class="form-label">ID Number</label>
                <input type="text" class="form-control" name="id_number" value="<?php echo htmlspecialchars($user['id_number']); ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">Last Name</label>
                <input type="text" class="form-control" name="last_name" value="<?php echo htmlspecialchars($user['last_name']); ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">First Name</label>
                <input type="text" class="form-control" name="first_name" value="<?php echo htmlspecialchars($user['first_name']); ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">Middle Name</label>
                <input type="text" class="form-control" name="middle_name" value="<?php echo htmlspecialchars($user['middle_name']); ?>">
            </div>

            <div class="form-group">
                <label class="form-label">Course</label>
                <select class="form-control" name="course" required>
                    <option value="BSIT" <?php echo $user['course'] === 'BSIT' ? 'selected' : ''; ?>>BSIT - Bachelor of Science in Information Technology</option>
                    <option value="BSCS" <?php echo $user['course'] === 'BSCS' ? 'selected' : ''; ?>>BSCS - Bachelor of Science in Computer Science</option>
                    <option value="BSIS" <?php echo $user['course'] === 'BSIS' ? 'selected' : ''; ?>>BSIS - Bachelor of Science in Information Systems</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Course Level</label>
                <select class="form-control" name="course_level" required>
                    <option value="1" <?php echo (int) $user['course_level'] === 1 ? 'selected' : ''; ?>>1st Year</option>
                    <option value="2" <?php echo (int) $user['course_level'] === 2 ? 'selected' : ''; ?>>2nd Year</option>
                    <option value="3" <?php echo (int) $user['course_level'] === 3 ? 'selected' : ''; ?>>3rd Year</option>
                    <option value="4" <?php echo (int) $user['course_level'] === 4 ? 'selected' : ''; ?>>4th Year</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">Address</label>
                <input type="text" class="form-control" name="address" value="<?php echo htmlspecialchars($user['address']); ?>">
            </div>

            <div class="actions-row edit-profile-actions">
                <a href="dashboard.php" class="btn-cancel">Cancel</a>
                <button type="submit" class="btn-save">Save Changes</button>
            </div>
        </form>
    </div>
</div>

</body>
</html>