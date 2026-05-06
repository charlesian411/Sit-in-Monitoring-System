<?php
session_start();
if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    header("Location: admin_login.php");
    exit();
}

require_once 'config/db.php';

$conn->query("CREATE TABLE IF NOT EXISTS lab_software (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lab_name VARCHAR(50) NOT NULL,
    software_name VARCHAR(150) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lab_software (lab_name, software_name)
)");

$alert_message = "";
$alert_type = "success";
$lab_options = ['524', '526', '528', '530', '542', '544'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_software') {
        $lab_name = trim($_POST['lab_name'] ?? '');
        $software_name = trim($_POST['software_name'] ?? '');

        if ($lab_name === '' || !in_array($lab_name, $lab_options, true)) {
            $alert_message = "Please select a valid laboratory.";
            $alert_type = "error";
        } elseif ($software_name === '') {
            $alert_message = "Software name cannot be empty.";
            $alert_type = "error";
        } else {
            $stmt = $conn->prepare("INSERT IGNORE INTO lab_software (lab_name, software_name) VALUES (?, ?)");
            $stmt->bind_param("ss", $lab_name, $software_name);
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    $alert_message = "Software added successfully.";
                } else {
                    $alert_message = "This software already exists for Lab " . $lab_name . ".";
                    $alert_type = "error";
                }
            } else {
                $alert_message = "Failed to add software.";
                $alert_type = "error";
            }
            $stmt->close();
        }
    }

    if ($action === 'import_csv') {
        $lab_name = trim($_POST['import_lab'] ?? '');

        if ($lab_name === '' || !in_array($lab_name, $lab_options, true)) {
            $alert_message = "Please select a valid laboratory for import.";
            $alert_type = "error";
        } elseif (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $alert_message = "Please select a valid CSV file.";
            $alert_type = "error";
        } else {
            $file_path = $_FILES['csv_file']['tmp_name'];
            $file_ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));

            if ($file_ext !== 'csv' && $file_ext !== 'txt') {
                $alert_message = "Only CSV or TXT files are allowed.";
                $alert_type = "error";
            } else {
                $handle = fopen($file_path, 'r');
                $imported = 0;
                $skipped = 0;

                if ($handle) {
                    $stmt = $conn->prepare("INSERT IGNORE INTO lab_software (lab_name, software_name) VALUES (?, ?)");

                    while (($line = fgetcsv($handle)) !== false) {
                        foreach ($line as $cell) {
                            $sw = trim($cell);
                            if ($sw === '') continue;
                            $stmt->bind_param("ss", $lab_name, $sw);
                            $stmt->execute();
                            if ($stmt->affected_rows > 0) {
                                $imported++;
                            } else {
                                $skipped++;
                            }
                        }
                    }

                    $stmt->close();
                    fclose($handle);
                    $alert_message = "Import complete: {$imported} added, {$skipped} skipped (duplicates).";
                } else {
                    $alert_message = "Could not read the uploaded file.";
                    $alert_type = "error";
                }
            }
        }
    }

    if ($action === 'delete_software') {
        $software_id = (int) ($_POST['software_id'] ?? 0);
        if ($software_id > 0) {
            $stmt = $conn->prepare("DELETE FROM lab_software WHERE id = ?");
            $stmt->bind_param("i", $software_id);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $alert_message = "Software removed.";
            } else {
                $alert_message = "Unable to remove software.";
                $alert_type = "error";
            }
            $stmt->close();
        }
    }
}

$software_by_lab = [];
foreach ($lab_options as $lab) {
    $software_by_lab[$lab] = [];
}

$sw_res = $conn->query("SELECT id, lab_name, software_name FROM lab_software ORDER BY lab_name ASC, software_name ASC");
if ($sw_res) {
    while ($row = $sw_res->fetch_assoc()) {
        $lab = $row['lab_name'];
        if (isset($software_by_lab[$lab])) {
            $software_by_lab[$lab][] = $row;
        }
    }
}

$pending_count = 0;
$pending_count_res = $conn->query("SELECT COUNT(*) AS total FROM reservations WHERE status = 'pending'");
if ($pending_count_res && $pending_count_row = $pending_count_res->fetch_assoc()) {
    $pending_count = (int) $pending_count_row['total'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CCS | Lab Software Management</title>
    <link rel="stylesheet" href="style.css?v=13">
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
        <li><a href="admin_reports.php">Reports</a></li>
        <li><a href="admin_feedback_reports.php">Feedback Reports</a></li>
        <li><a href="admin_leaderboard.php">Leaderboard</a></li>
        <li><a href="admin_lab_software.php">Lab Software</a></li>
        <li><a href="admin_reservations.php">Reservations<?php if ($pending_count > 0): ?> <span class="badge-pill"><?php echo $pending_count; ?></span><?php endif; ?></a></li>
        <li><a href="logout.php" class="admin-logout-link">Log out</a></li>
    </ul>
</nav>

<div class="admin-page">
    <h1 class="admin-page-title">Lab Software Management</h1>

    <?php if ($alert_message !== ''): ?>
        <div class="alert <?php echo $alert_type === 'error' ? 'alert-error' : 'alert-success'; ?> admin-alert"><?php echo htmlspecialchars($alert_message); ?></div>
    <?php endif; ?>

    <div class="admin-grid" style="grid-template-columns: 1fr 1fr; margin-bottom: 1.2rem;">
        <section class="admin-card">
            <div class="admin-card-title">Add Software</div>
            <form method="POST" class="lab-sw-form">
                <input type="hidden" name="action" value="add_software">
                <div class="form-group">
                    <label class="form-label">Laboratory</label>
                    <select class="form-control" name="lab_name" required>
                        <option value="" disabled selected>Select Lab</option>
                        <?php foreach ($lab_options as $lab): ?>
                            <option value="<?php echo htmlspecialchars($lab); ?>"><?php echo htmlspecialchars($lab); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Software Name</label>
                    <input type="text" class="form-control" name="software_name" placeholder="e.g. Visual Studio Code" required>
                </div>
                <button type="submit" class="admin-btn admin-btn-primary">Add Software</button>
            </form>
        </section>

        <section class="admin-card">
            <div class="admin-card-title">Import from CSV</div>
            <form method="POST" enctype="multipart/form-data" class="lab-sw-form">
                <input type="hidden" name="action" value="import_csv">
                <div class="form-group">
                    <label class="form-label">Laboratory</label>
                    <select class="form-control" name="import_lab" required>
                        <option value="" disabled selected>Select Lab</option>
                        <?php foreach ($lab_options as $lab): ?>
                            <option value="<?php echo htmlspecialchars($lab); ?>"><?php echo htmlspecialchars($lab); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">CSV File</label>
                    <input type="file" class="form-control-file" name="csv_file" accept=".csv,.txt" required>
                    <p class="form-help">One software name per cell. Multiple rows/columns supported.</p>
                </div>
                <button type="submit" class="admin-btn admin-btn-secondary">Import</button>
            </form>
        </section>
    </div>

    <?php foreach ($lab_options as $lab): ?>
        <section class="admin-card" style="margin-bottom: 0.85rem;">
            <div class="admin-card-title">Lab <?php echo htmlspecialchars($lab); ?> — Software (<?php echo count($software_by_lab[$lab]); ?>)</div>
            <div class="lab-sw-grid">
                <?php if (empty($software_by_lab[$lab])): ?>
                    <p class="empty-text" style="padding: 0.75rem;">No software added yet.</p>
                <?php else: ?>
                    <?php foreach ($software_by_lab[$lab] as $sw): ?>
                        <div class="lab-sw-chip">
                            <span class="lab-sw-name"><?php echo htmlspecialchars($sw['software_name']); ?></span>
                            <form method="POST" class="inline-form" onsubmit="return confirm('Remove this software?');">
                                <input type="hidden" name="action" value="delete_software">
                                <input type="hidden" name="software_id" value="<?php echo (int) $sw['id']; ?>">
                                <button type="submit" class="lab-sw-delete" title="Remove">&times;</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>


<script src="theme.js"></script>
</body>
</html>
