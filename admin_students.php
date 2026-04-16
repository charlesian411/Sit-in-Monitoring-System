<?php
session_start();
if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    header("Location: admin_login.php");
    exit();
}

require_once 'config/db.php';

$conn->query("CREATE TABLE IF NOT EXISTS sit_in_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    purpose VARCHAR(255) NOT NULL,
    sit_lab VARCHAR(50) NOT NULL,
    status ENUM('active', 'completed') NOT NULL DEFAULT 'active',
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ended_at TIMESTAMP NULL,
    INDEX idx_sit_in_user (user_id),
    INDEX idx_sit_in_status (status),
    CONSTRAINT fk_sit_in_user_students FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$alert_message = "";
$alert_type = "success";
$open_add_student_modal = false;
$register_form = [
    'id_number' => '',
    'last_name' => '',
    'first_name' => '',
    'middle_name' => '',
    'course' => '',
    'course_level' => '',
    'email' => '',
    'address' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'register_student') {
        $register_form['id_number'] = trim($_POST['id_number'] ?? '');
        $register_form['last_name'] = trim($_POST['last_name'] ?? '');
        $register_form['first_name'] = trim($_POST['first_name'] ?? '');
        $register_form['middle_name'] = trim($_POST['middle_name'] ?? '');
        $register_form['course'] = trim($_POST['course'] ?? '');
        $register_form['course_level'] = trim($_POST['course_level'] ?? '');
        $register_form['email'] = trim($_POST['email'] ?? '');
        $register_form['address'] = trim($_POST['address'] ?? '');
        $password = $_POST['password'] ?? '';
        $repeat_password = $_POST['repeat_password'] ?? '';

        if ($register_form['id_number'] === '' || $register_form['last_name'] === '' || $register_form['first_name'] === '' || $register_form['course'] === '' || $register_form['course_level'] === '' || $register_form['email'] === '' || $password === '' || $repeat_password === '') {
            $alert_message = "Please fill in all required registration fields.";
            $alert_type = "error";
            $open_add_student_modal = true;
        } elseif ($password !== $repeat_password) {
            $alert_message = "Passwords do not match.";
            $alert_type = "error";
            $open_add_student_modal = true;
        } elseif (strlen($password) < 6) {
            $alert_message = "Password must be at least 6 characters.";
            $alert_type = "error";
            $open_add_student_modal = true;
        } elseif (!in_array($register_form['course'], ['BSIT', 'BSCS', 'BSIS'], true)) {
            $alert_message = "Please select a valid course.";
            $alert_type = "error";
            $open_add_student_modal = true;
        } elseif (!in_array($register_form['course_level'], ['1', '2', '3', '4'], true)) {
            $alert_message = "Please select a valid course level.";
            $alert_type = "error";
            $open_add_student_modal = true;
        } elseif (!filter_var($register_form['email'], FILTER_VALIDATE_EMAIL)) {
            $alert_message = "Please enter a valid email address.";
            $alert_type = "error";
            $open_add_student_modal = true;
        } else {
            $check_stmt = $conn->prepare("SELECT id FROM users WHERE id_number = ? OR email = ? LIMIT 1");
            $check_stmt->bind_param("ss", $register_form['id_number'], $register_form['email']);
            $check_stmt->execute();
            $check_stmt->store_result();

            if ($check_stmt->num_rows > 0) {
                $alert_message = "ID number or email already exists.";
                $alert_type = "error";
                $open_add_student_modal = true;
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $course_level_int = (int) $register_form['course_level'];
                $insert_stmt = $conn->prepare("INSERT INTO users (id_number, last_name, first_name, middle_name, course, course_level, email, address, role, password) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'student', ?)");
                $insert_stmt->bind_param(
                    "sssssisss",
                    $register_form['id_number'],
                    $register_form['last_name'],
                    $register_form['first_name'],
                    $register_form['middle_name'],
                    $register_form['course'],
                    $course_level_int,
                    $register_form['email'],
                    $register_form['address'],
                    $hashed_password
                );

                if ($insert_stmt->execute()) {
                    $alert_message = "Student registered successfully.";
                    $alert_type = "success";
                    $register_form = [
                        'id_number' => '',
                        'last_name' => '',
                        'first_name' => '',
                        'middle_name' => '',
                        'course' => '',
                        'course_level' => '',
                        'email' => '',
                        'address' => ''
                    ];
                    $open_add_student_modal = false;
                } else {
                    $alert_message = "Unable to register student.";
                    $alert_type = "error";
                    $open_add_student_modal = true;
                }

                $insert_stmt->close();
            }

            $check_stmt->close();
        }
    }

    if ($action === 'update_student') {
        $student_id = (int) ($_POST['student_id'] ?? 0);
        $last_name = trim($_POST['last_name'] ?? '');
        $first_name = trim($_POST['first_name'] ?? '');
        $middle_name = trim($_POST['middle_name'] ?? '');
        $course = trim($_POST['course'] ?? '');
        $course_level = (int) ($_POST['course_level'] ?? 0);
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');

        if ($student_id <= 0 || $last_name === '' || $first_name === '' || $course === '' || $course_level <= 0 || $email === '') {
            $alert_message = "Please complete all required student fields.";
            $alert_type = "error";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $alert_message = "Please enter a valid email address.";
            $alert_type = "error";
        } else {
            $email_check_stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
            $email_check_stmt->bind_param("si", $email, $student_id);
            $email_check_stmt->execute();
            $email_check_stmt->store_result();

            if ($email_check_stmt->num_rows > 0) {
                $alert_message = "Email is already used by another account.";
                $alert_type = "error";
            } else {
                $update_stmt = $conn->prepare("UPDATE users SET last_name = ?, first_name = ?, middle_name = ?, course = ?, course_level = ?, email = ?, address = ? WHERE id = ? AND role = 'student'");
                $update_stmt->bind_param("ssssissi", $last_name, $first_name, $middle_name, $course, $course_level, $email, $address, $student_id);

                if ($update_stmt->execute() && $update_stmt->affected_rows >= 0) {
                    $alert_message = "Student updated successfully.";
                    $alert_type = "success";
                } else {
                    $alert_message = "Unable to update student.";
                    $alert_type = "error";
                }
                $update_stmt->close();
            }

            $email_check_stmt->close();
        }
    }

    if ($action === 'reset_sessions') {
        $reset_sql = "DELETE s FROM sit_in_records s
            LEFT JOIN (
                SELECT DISTINCT user_id
                FROM sit_in_records
                WHERE status = 'active'
            ) active_users ON active_users.user_id = s.user_id
            WHERE active_users.user_id IS NULL";

        if ($conn->query($reset_sql)) {
            $deleted_rows = (int) $conn->affected_rows;
            if ($deleted_rows > 0) {
                $alert_message = "Sessions for non-active students were reset. Active students were skipped.";
            } else {
                $alert_message = "No non-active student sessions to reset. Active students were kept unchanged.";
            }
            $alert_type = "success";
        } else {
            $alert_message = "Unable to reset sessions.";
            $alert_type = "error";
        }
    }

    if ($action === 'delete_student') {
        $student_id = (int) ($_POST['student_id'] ?? 0);
        if ($student_id > 0) {
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'student'");
            $stmt->bind_param("i", $student_id);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $alert_message = "Student deleted.";
                $alert_type = "success";
            } else {
                $alert_message = "Student not found or delete failed.";
                $alert_type = "error";
            }
            $stmt->close();
        }
    }
}

$students = [];
$sql = "SELECT
            u.id,
            u.id_number,
            u.first_name,
            u.middle_name,
            u.last_name,
            u.course,
            u.course_level,
            GREATEST(0, 30 - COALESCE(sr.total_sessions, 0)) AS remaining_sessions
        FROM users u
        LEFT JOIN (
            SELECT user_id, COUNT(*) AS total_sessions
            FROM sit_in_records
            GROUP BY user_id
        ) sr ON sr.user_id = u.id
        WHERE u.role = 'student'
        ORDER BY u.last_name ASC, u.first_name ASC";
$res = $conn->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $students[] = $row;
    }
}

$selected_student = null;
$open_student_modal = false;

if (isset($_GET['view_student'])) {
    $view_student_id = (int) $_GET['view_student'];
    if ($view_student_id > 0) {
        $student_stmt = $conn->prepare("SELECT id, id_number, first_name, middle_name, last_name, course, course_level, email, address, created_at FROM users WHERE id = ? AND role = 'student' LIMIT 1");
        $student_stmt->bind_param("i", $view_student_id);
        $student_stmt->execute();
        $student_res = $student_stmt->get_result();
        $selected_student = $student_res->fetch_assoc();
        $student_stmt->close();

        if ($selected_student) {
            $session_stmt = $conn->prepare("SELECT COUNT(*) AS total_sessions, SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_sessions FROM sit_in_records WHERE user_id = ?");
            $session_stmt->bind_param("i", $view_student_id);
            $session_stmt->execute();
            $session_res = $session_stmt->get_result();
            $session_row = $session_res->fetch_assoc();
            $session_stmt->close();

            $total_sessions = (int) ($session_row['total_sessions'] ?? 0);
            $active_sessions = (int) ($session_row['active_sessions'] ?? 0);
            $selected_student['remaining_sessions'] = max(0, 30 - $total_sessions);
            $selected_student['total_sessions'] = $total_sessions;
            $selected_student['active_sessions'] = $active_sessions;

            $student_profile_image = "";
            $student_images = glob(__DIR__ . "/uploads/profile_" . $view_student_id . ".*");
            if (!empty($student_images)) {
                $student_profile_image = "uploads/" . basename($student_images[0]);
            }

            $selected_student['profile_image_url'] = "";
            if ($student_profile_image !== "" && file_exists(__DIR__ . "/" . $student_profile_image)) {
                $selected_student['profile_image_url'] = $student_profile_image . "?v=" . filemtime(__DIR__ . "/" . $student_profile_image);
            }

            $open_student_modal = true;
        } else {
            $alert_message = "Student profile not found.";
            $alert_type = "error";
        }
    }
}

if (isset($_GET['edit_student'])) {
    $edit_student_id = (int) $_GET['edit_student'];
    if ($edit_student_id > 0) {
        $edit_stmt = $conn->prepare("SELECT id, id_number, first_name, middle_name, last_name, course, course_level, email, address FROM users WHERE id = ? AND role = 'student' LIMIT 1");
        $edit_stmt->bind_param("i", $edit_student_id);
        $edit_stmt->execute();
        $edit_res = $edit_stmt->get_result();
        $selected_student = $edit_res->fetch_assoc();
        $edit_stmt->close();

        if ($selected_student) {
            $open_student_modal = true;
        } else {
            $alert_message = "Student profile not found.";
            $alert_type = "error";
        }
    }
}

if (isset($_GET['open']) && $_GET['open'] === 'add_student') {
    $open_add_student_modal = true;
}

if ($open_student_modal) {
    $open_add_student_modal = false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CCS | Student List</title>
    <link rel="stylesheet" href="style.css?v=11">
</head>
<body>

<nav class="admin-top-nav">
    <span class="nav-brand">College of Computer Studies Admin</span>
    <ul class="nav-links admin-links">
        <li><a href="admin_dashboard.php">Home</a></li>
        <li><a href="admin_dashboard.php?open=search">Search</a></li>
        <li><a href="admin_students.php">Students</a></li>
        <li><a href="admin_current_sitin.php">View Current Sitin</a></li>
        <li><a href="admin_sitin_history.php">View Sit-in Records</a></li>
        <li><a href="admin_feedback_reports.php">Feedback Reports</a></li>
        <li><a href="admin_reservations.php">Reservations</a></li>
        <li><a href="logout.php" class="admin-logout-link">Log out</a></li>
    </ul>
</nav>

<div class="admin-page">
    <h1 class="admin-page-title">Students Information</h1>

    <?php if ($alert_message !== ''): ?>
        <div class="alert <?php echo $alert_type === 'error' ? 'alert-error' : 'alert-success'; ?> admin-alert"><?php echo htmlspecialchars($alert_message); ?></div>
    <?php endif; ?>

    <div class="students-toolbar">
        <a href="admin_students.php?open=add_student" class="admin-btn admin-btn-primary">Add Students</a>
        <form method="POST" class="inline-form" onsubmit="return confirm('Reset sessions for non-active students only? Active students will not be reset.');">
            <input type="hidden" name="action" value="reset_sessions">
            <button type="submit" class="admin-btn admin-btn-danger">Reset All Session</button>
        </form>
    </div>

    <div class="admin-table-wrap">
        <table class="admin-table students-table">
            <thead>
                <tr>
                    <th>ID Number</th>
                    <th>Name</th>
                    <th>Year Level</th>
                    <th>Course</th>
                    <th>Remaining Session</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($students)): ?>
                    <tr>
                        <td colspan="6" class="empty-table">No students found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($students as $student): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($student['id_number']); ?></td>
                            <td><?php echo htmlspecialchars($student['first_name'] . ' ' . ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . $student['last_name']); ?></td>
                            <td><?php echo (int) $student['course_level']; ?></td>
                            <td><?php echo htmlspecialchars($student['course']); ?></td>
                            <td><?php echo (int) $student['remaining_sessions']; ?></td>
                            <td>
                                <a href="admin_students.php?edit_student=<?php echo (int) $student['id']; ?>" class="admin-btn admin-btn-secondary">Edit</a>
                                <form method="POST" class="inline-form" onsubmit="return confirm('Delete this student?');">
                                    <input type="hidden" name="action" value="delete_student">
                                    <input type="hidden" name="student_id" value="<?php echo (int) $student['id']; ?>">
                                    <button type="submit" class="admin-btn admin-btn-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal-overlay <?php echo $open_add_student_modal ? 'is-open' : ''; ?>" id="add-student-modal">
    <div class="admin-modal student-profile-modal">
        <div class="modal-header">
            <h3>Add Student</h3>
            <a href="admin_students.php" class="modal-close" aria-label="Close">×</a>
        </div>

        <form method="POST" class="sitin-modal-form">
            <input type="hidden" name="action" value="register_student">

            <div class="form-group">
                <label class="form-label">ID Number</label>
                <input type="text" class="form-control" name="id_number" value="<?php echo htmlspecialchars($register_form['id_number']); ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">Last Name</label>
                <input type="text" class="form-control" name="last_name" value="<?php echo htmlspecialchars($register_form['last_name']); ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">First Name</label>
                <input type="text" class="form-control" name="first_name" value="<?php echo htmlspecialchars($register_form['first_name']); ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">Middle Name</label>
                <input type="text" class="form-control" name="middle_name" value="<?php echo htmlspecialchars($register_form['middle_name']); ?>">
            </div>

            <div class="form-group">
                <label class="form-label">Course</label>
                <select class="form-control" name="course" required>
                    <option value="" disabled <?php echo $register_form['course'] === '' ? 'selected' : ''; ?>>Select Course</option>
                    <option value="BSIT" <?php echo $register_form['course'] === 'BSIT' ? 'selected' : ''; ?>>BSIT - Bachelor of Science in Information Technology</option>
                    <option value="BSCS" <?php echo $register_form['course'] === 'BSCS' ? 'selected' : ''; ?>>BSCS - Bachelor of Science in Computer Science</option>
                    <option value="BSIS" <?php echo $register_form['course'] === 'BSIS' ? 'selected' : ''; ?>>BSIS - Bachelor of Science in Information Systems</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Course Level</label>
                <select class="form-control" name="course_level" required>
                    <option value="" disabled <?php echo $register_form['course_level'] === '' ? 'selected' : ''; ?>>Select Course Level</option>
                    <option value="1" <?php echo $register_form['course_level'] === '1' ? 'selected' : ''; ?>>1st Year</option>
                    <option value="2" <?php echo $register_form['course_level'] === '2' ? 'selected' : ''; ?>>2nd Year</option>
                    <option value="3" <?php echo $register_form['course_level'] === '3' ? 'selected' : ''; ?>>3rd Year</option>
                    <option value="4" <?php echo $register_form['course_level'] === '4' ? 'selected' : ''; ?>>4th Year</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Password</label>
                <input type="password" class="form-control" name="password" required>
            </div>

            <div class="form-group">
                <label class="form-label">Repeat Password</label>
                <input type="password" class="form-control" name="repeat_password" required>
            </div>

            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($register_form['email']); ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">Address</label>
                <input type="text" class="form-control" name="address" value="<?php echo htmlspecialchars($register_form['address']); ?>">
            </div>

            <div class="sitin-modal-actions">
                <a href="admin_students.php" class="admin-btn admin-btn-muted">Cancel</a>
                <button type="submit" class="admin-btn admin-btn-primary">Register Student</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay <?php echo $open_student_modal ? 'is-open' : ''; ?>" id="student-profile-modal">
    <div class="admin-modal student-profile-modal">
        <div class="modal-header">
            <h3>Student Profile</h3>
            <a href="admin_students.php" class="modal-close">×</a>
        </div>

        <?php if ($selected_student): ?>
            <form method="POST" class="sitin-modal-form">
                <input type="hidden" name="action" value="update_student">
                <input type="hidden" name="student_id" value="<?php echo (int) $selected_student['id']; ?>">

                <div class="form-group">
                    <label class="form-label">ID Number</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($selected_student['id_number']); ?>" readonly>
                </div>

                <div class="form-group">
                    <label class="form-label">Last Name</label>
                    <input type="text" class="form-control" name="last_name" value="<?php echo htmlspecialchars($selected_student['last_name']); ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">First Name</label>
                    <input type="text" class="form-control" name="first_name" value="<?php echo htmlspecialchars($selected_student['first_name']); ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Middle Name</label>
                    <input type="text" class="form-control" name="middle_name" value="<?php echo htmlspecialchars($selected_student['middle_name']); ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Course</label>
                    <select class="form-control" name="course" required>
                        <option value="BSIT" <?php echo $selected_student['course'] === 'BSIT' ? 'selected' : ''; ?>>BSIT</option>
                        <option value="BSCS" <?php echo $selected_student['course'] === 'BSCS' ? 'selected' : ''; ?>>BSCS</option>
                        <option value="BSIS" <?php echo $selected_student['course'] === 'BSIS' ? 'selected' : ''; ?>>BSIS</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Course Level</label>
                    <select class="form-control" name="course_level" required>
                        <option value="1" <?php echo (int) $selected_student['course_level'] === 1 ? 'selected' : ''; ?>>1</option>
                        <option value="2" <?php echo (int) $selected_student['course_level'] === 2 ? 'selected' : ''; ?>>2</option>
                        <option value="3" <?php echo (int) $selected_student['course_level'] === 3 ? 'selected' : ''; ?>>3</option>
                        <option value="4" <?php echo (int) $selected_student['course_level'] === 4 ? 'selected' : ''; ?>>4</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($selected_student['email']); ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Address</label>
                    <input type="text" class="form-control" name="address" value="<?php echo htmlspecialchars($selected_student['address'] ?? ''); ?>">
                </div>

                <div class="sitin-modal-actions">
                    <a href="admin_students.php" class="admin-btn admin-btn-muted">Cancel</a>
                    <button type="submit" class="admin-btn admin-btn-primary">Save Changes</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
