<?php
// admin_session.php - Manage individual voting sessions (continued)
session_start();
require_once "config.php";

// Check if the user is logged in, if not redirect to login page
if(!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true){
    header("location: admin_login.php");
    exit;
}

// Check if session ID is provided
if (!isset($_GET["id"]) || empty($_GET["id"])) {
    header("location: admin_dashboard.php");
    exit;
}

$session_id = (int)$_GET["id"];

// Verify this session belongs to the logged-in admin
$sql = "SELECT * FROM voting_sessions WHERE session_id = ? AND admin_id = ?";
$session = null;

if ($stmt = mysqli_prepare($conn, $sql)) {
    mysqli_stmt_bind_param($stmt, "ii", $session_id, $_SESSION["admin_id"]);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if (mysqli_num_rows($result) == 1) {
        $session = mysqli_fetch_assoc($result);
    } else {
        // Session not found or not owned by this admin
        header("location: admin_dashboard.php");
        exit;
    }
    
    mysqli_stmt_close($stmt);
}

// Handle adding a new candidate
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["add_candidate"])) {
    $candidate_name = sanitize_input($conn, $_POST["candidate_name"]);
    $candidate_info = sanitize_input($conn, $_POST["candidate_info"]);
    
    $sql = "INSERT INTO candidates (session_id, candidate_name, candidate_info) VALUES (?, ?, ?)";
    
    if ($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, "iss", $session_id, $candidate_name, $candidate_info);
        
        if (!mysqli_stmt_execute($stmt)) {
            echo "Error: Unable to add candidate.";
        }
        
        mysqli_stmt_close($stmt);
    }
}

// Handle starting/stopping the voting session
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["toggle_session"])) {
    if ($session["is_active"]) {
        // Stop the session
        $sql = "UPDATE voting_sessions SET is_active = 0 WHERE session_id = ?";
    } else {
        // Start the session
        $sql = "UPDATE voting_sessions SET is_active = 1, start_time = NOW() WHERE session_id = ?";
    }
    
    if ($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $session_id);
        
        if (mysqli_stmt_execute($stmt)) {
            // Update the session variable
            $session["is_active"] = !$session["is_active"];
            if (!$session["is_active"]) {
                $session["start_time"] = date("Y-m-d H:i:s");
            }
        } else {
            echo "Error: Unable to update session status.";
        }
        
        mysqli_stmt_close($stmt);
    }
}

// Handle deleting a candidate
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["delete_candidate"])) {
    $candidate_id = (int)$_POST["candidate_id"];
    
    $sql = "DELETE FROM candidates WHERE candidate_id = ? AND session_id = ?";
    
    if ($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, "ii", $candidate_id, $session_id);
        
        if (!mysqli_stmt_execute($stmt)) {
            echo "Error: Unable to delete candidate.";
        }
        
        mysqli_stmt_close($stmt);
    }
}

// Get all candidates for this session
$sql = "SELECT * FROM candidates WHERE session_id = ? ORDER BY candidate_name";
$candidates = [];

if ($stmt = mysqli_prepare($conn, $sql)) {
    mysqli_stmt_bind_param($stmt, "i", $session_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    while ($row = mysqli_fetch_assoc($result)) {
        $candidates[] = $row;
    }
    
    mysqli_stmt_close($stmt);
}

// Get vote counts if session is active or completed
$vote_counts = [];
if ($session["is_active"] || $session["start_time"] != null) {
    $sql = "SELECT c.candidate_id, c.candidate_name, COUNT(v.vote_id) as vote_count 
            FROM candidates c
            LEFT JOIN votes v ON c.candidate_id = v.candidate_id
            WHERE c.session_id = ?
            GROUP BY c.candidate_id
            ORDER BY vote_count DESC";
    
    if ($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $session_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $vote_counts[$row['candidate_id']] = $row['vote_count'];
        }
        
        mysqli_stmt_close($stmt);
    }
}

// Check if the session is expired (if active)
$is_expired = false;
if ($session["is_active"] && $session["start_time"] != null) {
    $start_time = new DateTime($session["start_time"]);
    $end_time = clone $start_time;
    $end_time->add(new DateInterval('PT' . $session["duration_minutes"] . 'M'));
    $current_time = new DateTime();
    
    if ($current_time > $end_time) {
        $is_expired = true;
        
        // Auto-deactivate expired session
        $sql = "UPDATE voting_sessions SET is_active = 0 WHERE session_id = ?";
        if ($stmt = mysqli_prepare($conn, $sql)) {
            mysqli_stmt_bind_param($stmt, "i", $session_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $session["is_active"] = 0;
        }
    }
}

// Count total votes
$total_votes = 0;
$sql = "SELECT COUNT(*) as total FROM votes v 
        JOIN voters vt ON v.voter_id = vt.voter_id 
        WHERE vt.session_id = ?";

if ($stmt = mysqli_prepare($conn, $sql)) {
    mysqli_stmt_bind_param($stmt, "i", $session_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        $total_votes = $row['total'];
    }
    
    mysqli_stmt_close($stmt);
}

// Count total voters
$total_voters = 0;
$sql = "SELECT COUNT(*) as total FROM voters WHERE session_id = ?";

if ($stmt = mysqli_prepare($conn, $sql)) {
    mysqli_stmt_bind_param($stmt, "i", $session_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        $total_voters = $row['total'];
    }
    
    mysqli_stmt_close($stmt);
}

// Generate the voting link
$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
$voting_link = $base_url . "/vote.php?code=" . $session["access_code"];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Voting Session</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            padding-bottom: 60px;
        }
        .container {
            max-width: 800px;
            margin-top: 30px;
        }
        .card {
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .card-header {
            background-color: #343a40;
            color: white;
            border-radius: 10px 10px 0 0 !important;
            padding: 15px;
        }
        .candidate-card {
            position: relative;
            transition: transform 0.3s;
            margin-bottom: 15px;
        }
        .candidate-card:hover {
            transform: translateY(-3px);
        }
        .delete-btn {
            position: absolute;
            top: 10px;
            right: 10px;
        }
        .navbar {
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .status-badge {
            font-size: 1rem;
            padding: 5px 10px;
        }
        .sharing-box {
            background-color: #f8f9fa;
            border-radius: 5px;
            padding: 15px;
            margin-top: 20px;
        }
        .vote-count {
            font-size: 1.2rem;
            font-weight: bold;
        }
        .progress {
            height: 25px;
            margin-top: 10px;
        }
        .timer {
            font-size: 1.2rem;
            font-weight: bold;
            color: #dc3545;
        }
        .qr-code {
            text-align: center;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">Voting System</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="admin_dashboard.php">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="admin_profile.php">Profile</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="admin_logout.php">Logout</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2><?php echo htmlspecialchars($session["session_name"]); ?></h2>
                <?php if($session["start_time"] == null || $session["is_active"]): ?>
                    <form method="post" class="d-inline">
                        <input type="hidden" name="toggle_session" value="1">
                        <button type="submit" class="btn <?php echo $session["is_active"] ? 'btn-danger' : 'btn-success'; ?>">
                            <?php echo $session["is_active"] ? 'Stop Voting' : 'Start Voting'; ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="row mb-4">
                    <div class="col-md-6">
                        <h5>Session Details</h5>
                        <p><?php echo nl2br(htmlspecialchars($session["session_description"])); ?></p>
                        <p><strong>Duration:</strong> <?php echo $session["duration_minutes"]; ?> minutes</p>
                        <p><strong>Max Participants:</strong> <?php echo $session["max_participants"]; ?></p>
                        <p><strong>Status:</strong> 
                            <span class="badge <?php echo $session["is_active"] ? 'bg-success' : 'bg-secondary'; ?> status-badge">
                                <?php echo $session["is_active"] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </p>
                        <?php if($session["is_active"] && $session["start_time"] != null): ?>
                            <p><strong>Started at:</strong> <?php echo date('Y-m-d H:i:s', strtotime($session["start_time"])); ?></p>
                            <p><strong>Ends at:</strong> 
                                <?php 
                                    $start_time = new DateTime($session["start_time"]);
                                    $end_time = clone $start_time;
                                    $end_time->add(new DateInterval('PT' . $session["duration_minutes"] . 'M'));
                                    echo $end_time->format('Y-m-d H:i:s');
                                ?>
                            </p>
                            <?php if(!$is_expired): ?>
                                <p><strong>Time Remaining:</strong> <span class="timer" id="timer">Calculating...</span></p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <h5>Voting Access</h5>
                        <p>Share the following link with participants:</p>
                        <div class="sharing-box">
                            <div class="input-group mb-3">
                                <input type="text" class="form-control" value="<?php echo $voting_link; ?>" id="voting-link" readonly>
                                <button class="btn btn-outline-secondary" type="button" onclick="copyLink()">Copy</button>
                            </div>
                            <div class="text-center">
                                <p>Access Code: <strong><?php echo $session["access_code"]; ?></strong></p>
                            </div>
                        </div>
                        <div class="mt-3">
                            <p><strong>Statistics:</strong></p>
                            <p>Total Voters: <?php echo $total_voters; ?> / <?php echo $session["max_participants"]; ?></p>
                            <p>Total Votes: <?php echo $total_votes; ?></p>
                        </div>
                    </div>
                </div>

                <?php if($session["start_time"] != null && !$session["is_active"]): ?>
                    <!-- Results Section -->
                    <div class="row">
                        <div class="col-12">
                            <h4>Voting Results</h4>
                            <?php if(count($candidates) > 0): ?>
                                <div class="results-container">
                                    <?php foreach($candidates as $candidate): ?>
                                        <?php 
                                            $votes = isset($vote_counts[$candidate['candidate_id']]) ? $vote_counts[$candidate['candidate_id']] : 0;
                                            $percentage = $total_votes > 0 ? ($votes / $total_votes) * 100 : 0;
                                        ?>
                                        <div class="card mb-3">
                                            <div class="card-body">
                                                <h5 class="card-title"><?php echo htmlspecialchars($candidate['candidate_name']); ?></h5>
                                                <p class="vote-count"><?php echo $votes; ?> votes (<?php echo number_format($percentage, 1); ?>%)</p>
                                                <div class="progress">
                                                    <div class="progress-bar" role="progressbar" style="width: <?php echo $percentage; ?>%" 
                                                        aria-valuenow="<?php echo $percentage; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-warning">No candidates were added to this session.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Candidates Section -->
                    <div class="row">
                        <div class="col-md-6">
                            <h4>Candidates</h4>
                            <?php if(count($candidates) > 0): ?>
                                <div class="candidates-container">
                                    <?php foreach($candidates as $candidate): ?>
                                        <div class="card candidate-card">
                                            <div class="card-body">
                                                <h5 class="card-title"><?php echo htmlspecialchars($candidate['candidate_name']); ?></h5>
                                                <p class="card-text"><?php echo nl2br(htmlspecialchars($candidate['candidate_info'])); ?></p>
                                                <?php if(!$session["is_active"]): ?>
                                                    <form method="post" class="d-inline">
                                                        <input type="hidden" name="candidate_id" value="<?php echo $candidate['candidate_id']; ?>">
                                                        <input type="hidden" name="delete_candidate" value="1">
                                                        <button type="submit" class="btn btn-sm btn-danger delete-btn" 
                                                                onclick="return confirm('Are you sure you want to delete this candidate?')">
                                                            <i class="bi bi-trash"></i> Delete
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info">No candidates added yet. Use the form to add candidates.</div>
                            <?php endif; ?>
                        </div>
                        <?php if(!$session["is_active"]): ?>
                            <div class="col-md-6">
                                <h4>Add Candidate</h4>
                                <form method="post">
                                    <div class="mb-3">
                                        <label for="candidate_name" class="form-label">Candidate Name</label>
                                        <input type="text" class="form-control" id="candidate_name" name="candidate_name" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="candidate_info" class="form-label">Candidate Info</label>
                                        <textarea class="form-control" id="candidate_info" name="candidate_info" rows="3"></textarea>
                                    </div>
                                    <input type="hidden" name="add_candidate" value="1">
                                    <button type="submit" class="btn btn-primary">Add Candidate</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function copyLink() {
            var copyText = document.getElementById("voting-link");
            copyText.select();
            copyText.setSelectionRange(0, 99999);
            document.execCommand("copy");
            alert("Voting link copied to clipboard!");
        }
        
        <?php if($session["is_active"] && $session["start_time"] != null && !$is_expired): ?>
        // Timer update function
        function updateTimer() {
            const startTime = new Date("<?php echo $session["start_time"]; ?>").getTime();
            const duration = <?php echo $session["duration_minutes"]; ?> * 60 * 1000; // convert to milliseconds
            const endTime = startTime + duration;
            
            const timerInterval = setInterval(function() {
                const now = new Date().getTime();
                const timeLeft = endTime - now;
                
                if (timeLeft <= 0) {
                    clearInterval(timerInterval);
                    document.getElementById("timer").innerHTML = "Expired! Refresh to see results.";
                    setTimeout(function() {
                        location.reload();
                    }, 3000);
                    return;
                }
                
                const minutes = Math.floor((timeLeft % (1000 * 60 * 60)) / (1000 * 60));
                const seconds = Math.floor((timeLeft % (1000 * 60)) / 1000);
                
                document.getElementById("timer").innerHTML = minutes + "m " + seconds + "s";
            }, 1000);
        }
        
        // Start the timer
        window.onload = updateTimer;
        <?php endif; ?>
    </script>
</body>
</html>