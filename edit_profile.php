<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (!empty($_SESSION['is_admin'])) {
    header("Location: admin_dashboard.php");
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

$profile_image_path = "";
$existing_images = glob(__DIR__ . "/uploads/profile_" . $user_id . ".*");
if (!empty($existing_images)) {
    $profile_image_path = "uploads/" . basename($existing_images[0]);
}

$profile_image_url = "";
if (!empty($profile_image_path) && file_exists(__DIR__ . "/" . $profile_image_path)) {
    $profile_image_url = $profile_image_path . "?v=" . filemtime(__DIR__ . "/" . $profile_image_path);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $last_name = trim($_POST['last_name']);
    $first_name = trim($_POST['first_name']);
    $middle_name = trim($_POST['middle_name']);
    $course = trim($_POST['course']);
    $course_level = (int) $_POST['course_level'];
    $email = trim($_POST['email']);
    $address = trim($_POST['address']);

    if (empty($last_name) || empty($first_name) || empty($course) || empty($course_level) || empty($email)) {
        $error = "Please fill in all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    }

    if (empty($error) && isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
            $error = "Image upload failed. Please try again.";
        } elseif ($_FILES['profile_image']['size'] > 2 * 1024 * 1024) {
            $error = "Profile image must be 2MB or less.";
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime_type = $finfo->file($_FILES['profile_image']['tmp_name']);

            $allowed_types = [
                "image/jpeg" => "jpg",
                "image/png" => "png",
                "image/webp" => "webp"
            ];

            if (!isset($allowed_types[$mime_type])) {
                $error = "Only JPG, PNG, and WEBP images are allowed.";
            } else {
                $upload_dir = __DIR__ . "/uploads";
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }

                foreach (glob($upload_dir . "/profile_" . $user_id . ".*") as $old_image) {
                    @unlink($old_image);
                }

                $new_filename = "profile_" . $user_id . "." . $allowed_types[$mime_type];
                $target_path = $upload_dir . "/" . $new_filename;

                if (!move_uploaded_file($_FILES['profile_image']['tmp_name'], $target_path)) {
                    $error = "Unable to save uploaded image.";
                }
            }
        }
    }

    if (empty($error)) {
        // Check email duplicates excluding current user
        $check = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $check->bind_param("si", $email, $user_id);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = "Email is already in use.";
        } else {
            $update = $conn->prepare("UPDATE users SET last_name = ?, first_name = ?, middle_name = ?, course = ?, course_level = ?, email = ?, address = ? WHERE id = ?");
            $update->bind_param("ssssissi", $last_name, $first_name, $middle_name, $course, $course_level, $email, $address, $user_id);

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
    <link rel="stylesheet" href="style.css?v=3">
</head>
<body>

<nav>
    <span class="nav-brand">College of Computer Studies Sit-in Monitoring System</span>
    <ul class="nav-links">
        <li><a href="dashboard.php">Home</a></li>
        <li><a href="edit_profile.php">Edit Profile</a></li>
        <li><a href="reservation.php">Reservation</a></li>
        <li><a href="logout.php">Logout</a></li>
    </ul>
</nav>

<div class="page-wrapper">
    <div class="dashboard-card">
        <h2 class="register-title">Edit Student Profile</h2>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="edit_profile.php" enctype="multipart/form-data">
            <div class="form-group">
                <label class="form-label">ID Number</label>
                <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['id_number']); ?>" readonly>
            </div>

            <div class="form-group">
                <label class="form-label">Profile Image</label>
                <input type="file" class="form-control-file" name="profile_image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                <p class="form-help">JPG, PNG, or WEBP. Maximum file size: 2MB.</p>
                <?php if (!empty($profile_image_url)): ?>
                    <img src="<?php echo htmlspecialchars($profile_image_url); ?>" alt="Profile Preview" class="profile-preview profile-two-by-two">
                <?php endif; ?>
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