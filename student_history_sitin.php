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
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
</head>
<body>

<nav>
    <span class="nav-brand">College of Computer Studies Sit-in Monitoring System</span>
    <ul class="nav-links">
        <li><a href="dashboard.php">Home</a></li>
        <li><a href="edit_profile.php">Edit Profile</a></li>
        <li><a href="student_history_sitin.php">My History Sitin</a></li>
        <li><a href="student_lab_software.php">Lab Software</a></li>
        <li><a href="reservation.php">Reservation</a></li>
        <li><a href="logout.php">Logout</a></li>
    </ul>
</nav>

<div class="admin-page student-reservation-page">
    <h1 class="admin-page-title">My History Sitin</h1>

    <?php if ($alert_message !== ''): ?>
        <div class="alert <?php echo $alert_type === 'error' ? 'alert-error' : 'alert-success'; ?> admin-alert"><?php echo htmlspecialchars($alert_message); ?></div>
    <?php endif; ?>

    <div class="history-cards-container">
        <?php if (empty($sitin_history)): ?>
            <div class="empty-state-full" style="grid-column: 1 / -1;">No sit-in history yet.</div>
        <?php else: ?>
            <?php foreach ($sitin_history as $history): ?>
                <div class="history-card">
                    <div class="history-card-header">
                        <span class="status-badge status-<?php echo htmlspecialchars($history['status']); ?>">
                            <?php echo htmlspecialchars(ucfirst($history['status'])); ?>
                        </span>
                        <span class="history-lab-room">Lab <?php echo htmlspecialchars($history['sit_lab']); ?></span>
                    </div>
                    <div class="history-card-body">
                        <h3 class="history-purpose"><?php echo htmlspecialchars($history['purpose']); ?></h3>
                        <div class="history-time-wrap">
                            <div class="history-time-item">
                                <span class="time-label">Started</span>
                                <span class="time-value"><?php echo htmlspecialchars(date('M d, Y', strtotime($history['started_at']))); ?><br><?php echo htmlspecialchars(date('h:i A', strtotime($history['started_at']))); ?></span>
                            </div>
                            <div class="history-time-divider"></div>
                            <div class="history-time-item">
                                <span class="time-label">Ended</span>
                                <span class="time-value">
                                    <?php if ($history['ended_at']): ?>
                                        <?php echo htmlspecialchars(date('M d, Y', strtotime($history['ended_at']))); ?><br><?php echo htmlspecialchars(date('h:i A', strtotime($history['ended_at']))); ?>
                                    <?php else: ?>
                                        <span class="text-muted">In Progress</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="history-card-footer">
                        <?php if ($history['status'] === 'completed'): ?>
                            <?php if ((int) ($history['feedback_rating'] ?? 0) >= 1 && trim((string) ($history['feedback'] ?? '')) !== ''): ?>
                                <a href="student_history_sitin.php?feedback_record=<?php echo (int) $history['id']; ?>&feedback_mode=view" class="history-btn history-btn-view">View Feedback</a>
                            <?php else: ?>
                                <a href="student_history_sitin.php?feedback_record=<?php echo (int) $history['id']; ?>&feedback_mode=fill" class="history-btn history-btn-fill">⭐ Leave Feedback</a>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="history-waiting-msg">Session active</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
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
                <div class="feedback-view-card">
                    <div class="feedback-stars-display">
                        <?php 
                        $rating = (int) ($feedback_modal_record['feedback_rating'] ?? 0);
                        for($i=1; $i<=5; $i++) {
                            echo $i <= $rating ? '★' : '☆';
                        }
                        ?>
                    </div>
                    <p class="feedback-text-display"><?php echo nl2br(htmlspecialchars((string) ($feedback_modal_record['feedback'] ?? '-'))); ?></p>
                </div>
            <?php else: ?>
                <form method="POST" class="feedback-premium-form">
                    <input type="hidden" name="action" value="submit_feedback">
                    <input type="hidden" name="record_id" value="<?php echo (int) $feedback_modal_record['id']; ?>">

                    <div class="rating-selection-wrap">
                        <label class="rating-label">How was your session?</label>
                        <div class="star-rating-input">
                            <input type="radio" name="feedback_rating" id="star5" value="5" required <?php echo $feedback_form['feedback_rating'] === '5' ? 'checked' : ''; ?>><label for="star5">★</label>
                            <input type="radio" name="feedback_rating" id="star4" value="4" <?php echo $feedback_form['feedback_rating'] === '4' ? 'checked' : ''; ?>><label for="star4">★</label>
                            <input type="radio" name="feedback_rating" id="star3" value="3" <?php echo $feedback_form['feedback_rating'] === '3' ? 'checked' : ''; ?>><label for="star3">★</label>
                            <input type="radio" name="feedback_rating" id="star2" value="2" <?php echo $feedback_form['feedback_rating'] === '2' ? 'checked' : ''; ?>><label for="star2">★</label>
                            <input type="radio" name="feedback_rating" id="star1" value="1" <?php echo $feedback_form['feedback_rating'] === '1' ? 'checked' : ''; ?>><label for="star1">★</label>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Your Message</label>
                        <textarea class="form-control feedback-textarea" name="feedback" rows="4" placeholder="Tell us more about your experience..." required><?php echo htmlspecialchars($feedback_form['feedback']); ?></textarea>
                    </div>

                    <div class="feedback-modal-actions">
                        <a href="student_history_sitin.php" class="feedback-cancel-btn">Cancel</a>
                        <button type="submit" class="feedback-submit-btn">Submit Review</button>
                    </div>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script src="theme.js"></script>
</body>
</html>
