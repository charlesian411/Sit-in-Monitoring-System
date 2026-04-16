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
    CONSTRAINT fk_sit_in_user_student_history_page FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$feedback_column_check = $conn->query("SHOW COLUMNS FROM sit_in_records LIKE 'feedback'");
if ($feedback_column_check && $feedback_column_check->num_rows === 0) {
    $conn->query("ALTER TABLE sit_in_records ADD COLUMN feedback TEXT NULL");
}

$feedback_rating_column_check = $conn->query("SHOW COLUMNS FROM sit_in_records LIKE 'feedback_rating'");
if ($feedback_rating_column_check && $feedback_rating_column_check->num_rows === 0) {
    $conn->query("ALTER TABLE sit_in_records ADD COLUMN feedback_rating TINYINT NULL");
}

$alert_message = "";
$alert_type = "success";
$user_id = (int) $_SESSION['user_id'];
$open_feedback_modal = false;
$feedback_modal_mode = 'fill';
$feedback_modal_record = null;
$feedback_form = [
    'feedback_rating' => '',
    'feedback' => ''
];

if (isset($_GET['submitted']) && $_GET['submitted'] === '1') {
    $alert_message = "Feedback submitted successfully.";
    $alert_type = "success";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_feedback') {
    $record_id = (int) ($_POST['record_id'] ?? 0);
    $feedback = trim($_POST['feedback'] ?? '');
    $feedback_rating = (int) ($_POST['feedback_rating'] ?? 0);

    $feedback_form['feedback'] = $feedback;
    $feedback_form['feedback_rating'] = $feedback_rating >= 1 && $feedback_rating <= 5 ? (string) $feedback_rating : '';

    if ($record_id <= 0 || $feedback === '' || $feedback_rating < 1 || $feedback_rating > 5) {
        $alert_message = "Please provide a 1-5 star rating and your feedback comment.";
        $alert_type = "error";
        $open_feedback_modal = true;
        $feedback_modal_mode = 'fill';
        $modal_stmt = $conn->prepare("SELECT id, purpose, sit_lab, started_at, ended_at, feedback, feedback_rating FROM sit_in_records WHERE id = ? AND user_id = ? AND status = 'completed' LIMIT 1");
        $modal_stmt->bind_param("ii", $record_id, $user_id);
        $modal_stmt->execute();
        $modal_res = $modal_stmt->get_result();
        $feedback_modal_record = $modal_res->fetch_assoc();
        $modal_stmt->close();
    } else {
        $feedback_stmt = $conn->prepare("UPDATE sit_in_records SET feedback = ?, feedback_rating = ? WHERE id = ? AND user_id = ? AND status = 'completed'");
        $feedback_stmt->bind_param("siii", $feedback, $feedback_rating, $record_id, $user_id);

        if ($feedback_stmt->execute() && $feedback_stmt->affected_rows > 0) {
            $feedback_stmt->close();
            header("Location: student_history_sitin.php?feedback_record=" . $record_id . "&feedback_mode=view&submitted=1");
            exit();
        } else {
            $alert_message = "Unable to submit feedback.";
            $alert_type = "error";
            $open_feedback_modal = true;
            $feedback_modal_mode = 'fill';
            $modal_stmt = $conn->prepare("SELECT id, purpose, sit_lab, started_at, ended_at, feedback, feedback_rating FROM sit_in_records WHERE id = ? AND user_id = ? AND status = 'completed' LIMIT 1");
            $modal_stmt->bind_param("ii", $record_id, $user_id);
            $modal_stmt->execute();
            $modal_res = $modal_stmt->get_result();
            $feedback_modal_record = $modal_res->fetch_assoc();
            $modal_stmt->close();
        }

        $feedback_stmt->close();
    }
}

if (isset($_GET['feedback_record'])) {
    $modal_record_id = (int) ($_GET['feedback_record'] ?? 0);
    $requested_mode = trim($_GET['feedback_mode'] ?? '');
    $feedback_modal_mode = $requested_mode === 'view' ? 'view' : 'fill';

    if ($modal_record_id > 0) {
        $modal_stmt = $conn->prepare("SELECT id, purpose, sit_lab, started_at, ended_at, feedback, feedback_rating FROM sit_in_records WHERE id = ? AND user_id = ? AND status = 'completed' LIMIT 1");
        $modal_stmt->bind_param("ii", $modal_record_id, $user_id);
        $modal_stmt->execute();
        $modal_res = $modal_stmt->get_result();
        $feedback_modal_record = $modal_res->fetch_assoc();
        $modal_stmt->close();

        if ($feedback_modal_record) {
            $open_feedback_modal = true;
            if ($feedback_modal_mode === 'fill' && trim((string) ($feedback_modal_record['feedback'] ?? '')) !== '' && (int) ($feedback_modal_record['feedback_rating'] ?? 0) >= 1) {
                $feedback_modal_mode = 'view';
            }
        }
    }
}

$sitin_history = [];
$history_stmt = $conn->prepare("SELECT id, purpose, sit_lab, status, started_at, ended_at, feedback, feedback_rating FROM sit_in_records WHERE user_id = ? ORDER BY started_at DESC");
$history_stmt->bind_param("i", $user_id);
$history_stmt->execute();
$history_res = $history_stmt->get_result();
while ($row = $history_res->fetch_assoc()) {
    $sitin_history[] = $row;
}
$history_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CCS | My History Sitin</title>
    <link rel="stylesheet" href="style.css?v=1">
</head>
<body>

<nav>
    <span class="nav-brand">College of Computer Studies Sit-in Monitoring System</span>
    <ul class="nav-links">
        <li><a href="dashboard.php">Home</a></li>
        <li><a href="edit_profile.php">Edit Profile</a></li>
        <li><a href="student_history_sitin.php">My History Sitin</a></li>
        <li><a href="reservation.php">Reservation</a></li>
        <li><a href="logout.php">Logout</a></li>
    </ul>
</nav>

<div class="admin-page student-reservation-page">
    <h1 class="admin-page-title">My History Sitin</h1>

    <?php if ($alert_message !== ''): ?>
        <div class="alert <?php echo $alert_type === 'error' ? 'alert-error' : 'alert-success'; ?> admin-alert"><?php echo htmlspecialchars($alert_message); ?></div>
    <?php endif; ?>

    <section class="admin-card student-history-card">
        <div class="admin-card-title">Sit-in History</div>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Purpose</th>
                        <th>Lab</th>
                        <th>Status</th>
                        <th>Started</th>
                        <th>Ended</th>
                        <th>Feedback</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sitin_history)): ?>
                        <tr>
                            <td colspan="6" class="empty-table">No sit-in history yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($sitin_history as $history): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($history['purpose']); ?></td>
                                <td><?php echo htmlspecialchars($history['sit_lab']); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo htmlspecialchars($history['status']); ?>"><?php echo htmlspecialchars(ucfirst($history['status'])); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($history['started_at']))); ?></td>
                                <td><?php echo $history['ended_at'] ? htmlspecialchars(date('M d, Y h:i A', strtotime($history['ended_at']))) : '-'; ?></td>
                                <td>
                                    <?php if ($history['status'] === 'completed'): ?>
                                        <?php if ((int) ($history['feedback_rating'] ?? 0) >= 1 && trim((string) ($history['feedback'] ?? '')) !== ''): ?>
                                            <a href="student_history_sitin.php?feedback_record=<?php echo (int) $history['id']; ?>&feedback_mode=view" class="admin-btn admin-btn-secondary">View Feedback</a>
                                        <?php else: ?>
                                            <a href="student_history_sitin.php?feedback_record=<?php echo (int) $history['id']; ?>&feedback_mode=fill" class="admin-btn admin-btn-primary">Fill Out</a>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<div class="modal-overlay <?php echo $open_feedback_modal ? 'is-open' : ''; ?>" id="feedback-modal">
    <div class="admin-modal" style="max-width: 640px; width: 95%;">
        <div class="modal-header">
            <h3><?php echo $feedback_modal_mode === 'view' ? 'View Feedback' : 'Fill Out Feedback'; ?></h3>
            <a href="student_history_sitin.php" class="modal-close" aria-label="Close">&times;</a>
        </div>

        <?php if ($feedback_modal_record): ?>
            <div style="margin-bottom: 0.85rem; color: #475569; font-size: 0.95rem;">
                <strong>Purpose:</strong> <?php echo htmlspecialchars($feedback_modal_record['purpose']); ?> &nbsp;|&nbsp;
                <strong>Lab:</strong> <?php echo htmlspecialchars($feedback_modal_record['sit_lab']); ?>
            </div>

            <?php if ($feedback_modal_mode === 'view'): ?>
                <div class="admin-card" style="padding: 0.95rem; box-shadow:none; border:1px solid #e2e8f0;">
                    <div style="font-size:1.1rem; color:#f59e0b; margin-bottom:0.5rem;">
                        <?php echo str_repeat('★', (int) ($feedback_modal_record['feedback_rating'] ?? 0)) . str_repeat('☆', 5 - (int) ($feedback_modal_record['feedback_rating'] ?? 0)); ?>
                    </div>
                    <div><?php echo nl2br(htmlspecialchars((string) ($feedback_modal_record['feedback'] ?? '-'))); ?></div>
                </div>
            <?php else: ?>
                <form method="POST" class="sitin-modal-form">
                    <input type="hidden" name="action" value="submit_feedback">
                    <input type="hidden" name="record_id" value="<?php echo (int) $feedback_modal_record['id']; ?>">

                    <div class="form-group">
                        <label class="form-label">Rating</label>
                        <select class="form-control" name="feedback_rating" required>
                            <option value="" disabled <?php echo $feedback_form['feedback_rating'] === '' ? 'selected' : ''; ?>>Rate 1 to 5 Stars</option>
                            <option value="1" <?php echo $feedback_form['feedback_rating'] === '1' ? 'selected' : ''; ?>>1 Star</option>
                            <option value="2" <?php echo $feedback_form['feedback_rating'] === '2' ? 'selected' : ''; ?>>2 Stars</option>
                            <option value="3" <?php echo $feedback_form['feedback_rating'] === '3' ? 'selected' : ''; ?>>3 Stars</option>
                            <option value="4" <?php echo $feedback_form['feedback_rating'] === '4' ? 'selected' : ''; ?>>4 Stars</option>
                            <option value="5" <?php echo $feedback_form['feedback_rating'] === '5' ? 'selected' : ''; ?>>5 Stars</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Comment Feedback</label>
                        <textarea class="form-control" name="feedback" rows="4" placeholder="Type your feedback comment" required><?php echo htmlspecialchars($feedback_form['feedback']); ?></textarea>
                    </div>

                    <div class="sitin-modal-actions">
                        <a href="student_history_sitin.php" class="admin-btn admin-btn-muted">Cancel</a>
                        <button type="submit" class="admin-btn admin-btn-primary">Submit Feedback</button>
                    </div>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
