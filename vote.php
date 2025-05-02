<?php
// vote.php - User voting page
session_start();
require_once "config.php";

$access_code = "";
$error_msg = "";
$session = null;
$candidates = [];
$user_voted = false;

// Check if access code is provided
if (isset($_GET["code"]) && !empty($_GET["code"])) {
    $access_code = sanitize_input($conn, $_GET["code"]);
    
    // Get session details by access code
    $sql = "SELECT * FROM voting_sessions WHERE access_code = ?";
    
    if ($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, "s", $access_code);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if (mysqli_num_rows($result) == 1) {
            $session = mysqli_fetch_assoc($result);
            
            // Check if session is active
            if (!$session["is_active"]) {
                $error_msg = "This voting session is currently inactive.";
            } else {
                // Check if session has reached maximum participants
                $sql_count = "SELECT COUNT(*) as total FROM voters WHERE session_id = ?";
                if ($stmt_count = mysqli_prepare($conn, $sql_count)) {
                    mysqli_stmt_bind_param($stmt_count, "i", $session["session_id"]);
                    mysqli_stmt_execute($stmt_count);
                    $result_count = mysqli_stmt_get_result($stmt_count);
                    
                    if ($row_count = mysqli_fetch_assoc($result_count)) {
                        if ($row_count["total"] >= $session["max_participants"]) {
                            $error_msg = "This voting session has reached its maximum number of participants.";
                        }
                    }
                    
                    mysqli_stmt_close($stmt_count);
                }
                
                // Check if session is expired
                if (empty($error_msg) && $session["start_time"] != null) {
                    $start_time = new DateTime($session["start_time"]);
                    $end_time = clone $start_time;
                    $end_time->add(new DateInterval('PT' . $session["duration_minutes"] . 'M'));
                    $current_time = new DateTime();
                    
                    if ($current_time > $end_time) {
                        $error_msg = "This voting session has expired.";
                    }
                }
                
                // Get candidates for this session
                if (empty($error_msg)) {
                    $sql_candidates = "SELECT * FROM candidates WHERE session_id = ? ORDER BY candidate_name";
                    
                    if ($stmt_candidates = mysqli_prepare($conn, $sql_candidates)) {
                        mysqli_stmt_bind_param($stmt_candidates, "i", $session["session_id"]);
                        mysqli_stmt_execute($stmt_candidates);
                        $result_candidates = mysqli_stmt_get_result($stmt_candidates);
                        
                        while ($row_candidate = mysqli_fetch_assoc($result_candidates)) {
                            $candidates[] = $row_candidate;
                        }
                        
                        if (empty($candidates)) {
                            $error_msg = "No candidates are available for this voting session.";
                        }
                        
                        mysqli_stmt_close($stmt_candidates);
                    }
                }
            }
        } else {
            $error_msg = "Invalid access code.";
        }
        
        mysqli_stmt_close($stmt);
    }
} else {
    $error_msg = "Please provide an access code.";
}

// Process vote submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["submit_vote"]) && $session != null) {
    if (empty($_POST["voter_name"])) {
        $error_msg = "Please enter your name.";
    } elseif (!isset($_POST["candidate_id"])) {
        $error_msg = "Please select a candidate.";
    } else {
        $voter_name = sanitize_input($conn, $_POST["voter_name"]);
        $candidate_id = (int)$_POST["candidate_id"];
        
        // Check if voter has already voted in this session
        $sql_check_voter = "SELECT voter_id FROM voters WHERE voter_name = ? AND session_id = ?";
        
        if ($stmt_check = mysqli_prepare($conn, $sql_check_voter)) {
            mysqli_stmt_bind_param($stmt_check, "si", $voter_name, $session["session_id"]);
            mysqli_stmt_execute($stmt_check);
            mysqli_stmt_store_result($stmt_check);
            
            if (mysqli_stmt_num_rows($stmt_check) > 0) {
                $error_msg = "You have already voted in this session.";
            } else {
                // Begin transaction
                mysqli_begin_transaction($conn);
                
                try {
                    // Insert voter
                    $sql_insert_voter = "INSERT INTO voters (session_id, voter_name) VALUES (?, ?)";
                    $stmt_voter = mysqli_prepare($conn, $sql_insert_voter);
                    mysqli_stmt_bind_param($stmt_voter, "is", $session["session_id"], $voter_name);
                    mysqli_stmt_execute($stmt_voter);
                    $voter_id = mysqli_insert_id($conn);
                    
                    // Insert vote
                    $sql_insert_vote = "INSERT INTO votes (voter_id, candidate_id) VALUES (?, ?)";
                    $stmt_vote = mysqli_prepare($conn, $sql_insert_vote);
                    mysqli_stmt_bind_param($stmt_vote, "ii", $voter_id, $candidate_id);
                    mysqli_stmt_execute($stmt_vote);
                    
                    // Commit transaction
                    mysqli_commit($conn);
                    
                    // Set success flag
                    $user_voted = true;
                } catch (Exception $e) {
                    // Rollback transaction on error
                    mysqli_rollback($conn);
                    $error_msg = "Error submitting vote. Please try again.";
                }
            }
            
            mysqli_stmt_close($stmt_check);
        }
    }
}

// Check remaining time
$time_remaining = null;
if ($session && $session["is_active"] && $session["start_time"] != null) {
    $start_time = new DateTime($session["start_time"]);
    $end_time = clone $start_time;
    $end_time->add(new DateInterval('PT' . $session["duration_minutes"] . 'M'));
    $current_time = new DateTime();
    
    if ($current_time < $end_time) {
        $interval = $current_time->diff($end_time);
        $time_remaining = $interval->format('%i minutes and %s seconds');
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vote - <?php echo $session ? htmlspecialchars($session["session_name"]) : "Voting System"; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            padding-bottom: 60px;
        }
        .container {
            max-width: 500px;
            margin-top: 30px;
        }
        .card {
            border-radius: 15px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .card-header {
            background-color: #007bff;
            color: white;
            border-radius: 15px 15px 0 0 !important;
            padding: 20px;
            text-align: center;
        }
        .candidate-option {
            margin-bottom: 15px;
            padding: 15px;
            border: 1px solid #dee2e6;
            border-radius: 10px;
            transition: all 0.3s ease;
        }
        .candidate-option:hover {
            background-color: #f8f9fa;
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .candidate-option.selected {
            background-color: #e6f2ff;
            border-color: #007bff;
        }
        .timer {
            font-size: 1.2rem;
            font-weight: bold;
            color: #dc3545;
        }
        .success-msg {
            text-align: center;
            padding: 20px;
        }
        .success-msg i {
            font-size: 5rem;
            color: #28a745;
            margin-bottom: 20px;
        }
        .vote-btn {
            width: 100%;
            padding: 12px;
            font-size: 1.1rem;
            font-weight: bold;
            border-radius: 30px;
        }
        .form-control {
            padding: 12px;
            border-radius: 10px;
        }
        .form-label {
            font-weight: 600;
        }
        .radio-input {
            display: none;
        }
        .candidate-info {
            margin-top: 5px;
            font-size: 0.9rem;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <?php if ($session): ?>
                <div class="card-header">
                    <h2><?php echo htmlspecialchars($session["session_name"]); ?></h2>
                    <?php if ($time_remaining): ?>
                        <p class="mb-0">Time remaining: <span class="timer" id="timer"><?php echo $time_remaining; ?></span></p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="card-header">
                    <h2>Voting System</h2>
                </div>
            <?php endif; ?>
            
            <div class="card-body">
                <?php if ($error_msg): ?>
                    <div class="alert alert-danger">
                        <?php echo $error_msg; ?>
                    </div>
                <?php elseif ($user_voted): ?>
                    <div class="success-msg">
                        <i class="bi bi-check-circle"></i>
                        <h3>Thank You!</h3>
                        <p>Your vote has been successfully submitted.</p>
                    </div>
                <?php elseif ($session && !empty($candidates)): ?>
                    <form id="voting-form" method="post">
                        <div class="mb-4">
                            <label for="voter_name" class="form-label">Your Name</label>
                            <input type="text" class="form-control" id="voter_name" name="voter_name" placeholder="Enter your full name" required>
                            <small class="text-muted">Your name is used to prevent multiple voting</small>
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label">Select a Candidate</label>
                            <?php foreach ($candidates as $candidate): ?>
                                <div class="candidate-option" onclick="selectCandidate(<?php echo $candidate['candidate_id']; ?>)">
                                    <div class="form-check">
                                        <input class="form-check-input radio-input" type="radio" name="candidate_id" id="candidate_<?php echo $candidate['candidate_id']; ?>" value="<?php echo $candidate['candidate_id']; ?>" required>
                                        <label class="form-check-label" for="candidate_<?php echo $candidate['candidate_id']; ?>">
                                            <strong><?php echo htmlspecialchars($candidate['candidate_name']); ?></strong>
                                            <?php if (!empty($candidate['candidate_info'])): ?>
                                                <div class="candidate-info">
                                                    <?php echo nl2br(htmlspecialchars($candidate['candidate_info'])); ?>
                                                </div>
                                            <?php endif; ?>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="mb-3">
                            <input type="hidden" name="submit_vote" value="1">
                            <button type="submit" class="btn btn-primary vote-btn">
                                Submit Your Vote
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function selectCandidate(candidateId) {
            // Remove selected class from all options
            document.querySelectorAll('.candidate-option').forEach(option => {
                option.classList.remove('selected');
            });
            
            // Add selected class to clicked option
            const selectedOption = document.querySelector(`#candidate_${candidateId}`).closest('.candidate-option');
            selectedOption.classList.add('selected');
            
            // Check the radio button
            document.querySelector(`#candidate_${candidateId}`).checked = true;
        }
        
        <?php if ($time_remaining): ?>
        // Timer update function
        function updateTimer() {
            const endTime = new Date("<?php 
                $end_time_formatted = $end_time->format('Y-m-d H:i:s');
                echo $end_time_formatted;
            ?>").getTime();
            
            const timerInterval = setInterval(function() {
                const now = new Date().getTime();
                const timeLeft = endTime - now;
                
                if (timeLeft <= 0) {
                    clearInterval(timerInterval);
                    document.getElementById("timer").innerHTML = "Expired!";
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                    return;
                }
                
                const minutes = Math.floor((timeLeft % (1000 * 60 * 60)) / (1000 * 60));
                const seconds = Math.floor((timeLeft % (1000 * 60)) / 1000);
                
                document.getElementById("timer").innerHTML = minutes + " minutes and " + seconds + " seconds";
            }, 1000);
        }
        
        // Start the timer
        window.onload = updateTimer;
        <?php endif; ?>
    </script>
</body>
</html>