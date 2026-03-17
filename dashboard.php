<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once 'config/db.php';

// Fetch full user data
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CCS | Dashboard</title>
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
        <?php if (isset($_GET['updated']) && $_GET['updated'] === '1'): ?>
            <div class="alert alert-success">Profile updated successfully.</div>
        <?php endif; ?>

        <div class="dashboard-header">
            <div class="avatar">
                <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
            </div>
            <h1>Welcome, <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>!</h1>
            <p class="dashboard-subtitle">You are now logged in to the CCS Sit-in Monitoring System.</p>
        </div>

        <div class="card-divider"></div>

        <div class="info-grid">
            <div class="info-item">
                <span class="info-label">ID Number</span>
                <span class="info-value"><?php echo htmlspecialchars($user['id_number']); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Name</span>
                <span class="info-value"><?php echo htmlspecialchars($user['first_name'] . ' ' . ($user['middle_name'] ? $user['middle_name'] . ' ' : '') . $user['last_name']); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Course</span>
                <span class="info-value"><?php echo htmlspecialchars($user['course']); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Year Level</span>
                <span class="info-value"><?php echo htmlspecialchars($user['course_level']); ?><?php
                    $lvl = $user['course_level'];
                    if ($lvl == 1) echo 'st';
                    elseif ($lvl == 2) echo 'nd';
                    elseif ($lvl == 3) echo 'rd';
                    else echo 'th';
                ?> Year</span>
            </div>
            <div class="info-item">
                <span class="info-label">Email</span>
                <span class="info-value"><?php echo htmlspecialchars($user['email']); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Address</span>
                <span class="info-value"><?php echo htmlspecialchars($user['address']); ?></span>
            </div>
        </div>

        <div class="card-divider"></div>

        <div class="actions-row dashboard-actions">
            <a href="edit_profile.php" class="btn-edit-profile">Edit Profile</a>
            <a href="logout.php" class="btn-logout">Logout</a>
        </div>
    </div>
</div>

</body>
</html>
