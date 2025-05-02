<?php
// admin_dashboard.php - Admin dashboard
session_start();
require_once "config.php";

// Check if the user is logged in, if not redirect to login page
if(!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true){
    header("location: admin_login.php");
    exit;
}

// Function to generate a random access code
function generateAccessCode($length = 6) {
    $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $code;
}

// Handle create new voting session
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["create_session"])) {
    $session_name = sanitize_input($conn, $_POST["session_name"]);
    $session_description = sanitize_input($conn, $_POST["session_description"]);
    $duration_minutes = (int)$_POST["duration_minutes"];
    $max_participants = (int)$_POST["max_participants"];
    
    // Generate unique access code
    $access_code = generateAccessCode();
    $check_code = true;
    
    while ($check_code) {
        $sql = "SELECT session_id FROM voting_sessions WHERE access_code = ?";
        if ($stmt = mysqli_prepare($conn, $sql)) {
            mysqli_stmt_bind_param($stmt, "s", $access_code);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_store_result($stmt);
            
            if (mysqli_stmt_num_rows($stmt) == 0) {
                $check_code = false;
            } else {
                $access_code = generateAccessCode();
            }
            
            mysqli_stmt_close($stmt);
        }
    }
    
    // Insert new voting session
    $sql = "INSERT INTO voting_sessions (admin_id, session_name, session_description, duration_minutes, max_participants, access_code) 
            VALUES (?, ?, ?, ?, ?, ?)";
    
    if ($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, "ississ", $_SESSION["admin_id"], $session_name, $session_description, $duration_minutes, $max_participants, $access_code);
        
        if (mysqli_stmt_execute($stmt)) {
            $session_id = mysqli_insert_id($conn);
            header("location: admin_session.php?id=" . $session_id);
            exit;
        } else {
            echo "Error: Unable to create voting session.";
        }
        
        mysqli_stmt_close($stmt);
    }
}

// Get all voting sessions for this admin
$sql = "SELECT * FROM voting_sessions WHERE admin_id = ? ORDER BY created_at DESC";
$sessions = [];

if ($stmt = mysqli_prepare($conn, $sql)) {
    mysqli_stmt_bind_param($stmt, "i", $_SESSION["admin_id"]);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    while ($row = mysqli_fetch_assoc($result)) {
        $sessions[] = $row;
    }
    
    mysqli_stmt_close($stmt);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
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
        .btn-create {
            background-color: #28a745;
            border-color: #28a745;
            color: white;
            padding: 10px 20px;
            border-radius: 5px;
            font-weight: bold;
        }
        .session-card {
            cursor: pointer;
            transition: transform 0.3s;
        }
        .session-card:hover {
            transform: translateY(-5px);
        }
        .navbar {
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .status-active {
            color: #28a745;
            font-weight: bold;
        }
        .status-inactive {
            color: #dc3545;
        }
        .session-link {
            text-decoration: none;
            color: inherit;
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
                        <a class="nav-link active" href="#">Dashboard</a>
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
                <h2>Welcome, <?php echo htmlspecialchars($_SESSION["admin_username"]); ?></h2>
                <button type="button" class="btn btn-create" data-bs-toggle="modal" data-bs-target="#createSessionModal">
                    Create New Session
                </button>
            </div>
            <div class="card-body">
                <h4>Your Voting Sessions</h4>
                <?php if (empty($sessions)): ?>
                    <div class="alert alert-info">
                        You haven't created any voting sessions yet. Create your first one by clicking the "Create New Session" button.
                    </div>
                <?php else: ?>
                    <div class="row">
                        <?php foreach ($sessions as $session): ?>
                            <div class="col-md-6 mb-3">
                                <a href="admin_session.php?id=<?php echo $session['session_id']; ?>" class="session-link">
                                    <div class="card session-card">
                                        <div class="card-body">
                                            <h5 class="card-title"><?php echo htmlspecialchars($session['session_name']); ?></h5>
                                            <p class="card-text small"><?php echo htmlspecialchars(substr($session['session_description'], 0, 100)) . (strlen($session['session_description']) > 100 ? '...' : ''); ?></p>
                                            <div class="d-flex justify-content-between">
                                                <span class="badge <?php echo $session['is_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                                                    <?php echo $session['is_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                                <small class="text-muted">Code: <?php echo $session['access_code']; ?></small>
                                            </div>
                                        </div>
                                    </div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Create Session Modal -->
    <div class="modal fade" id="createSessionModal" tabindex="-1" aria-labelledby="createSessionModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="createSessionModalLabel">Create New Voting Session</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                        <div class="mb-3">
                            <label for="session_name" class="form-label">Session Name</label>
                            <input type="text" class="form-control" id="session_name" name="session_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="session_description" class="form-label">Description</label>
                            <textarea class="form-control" id="session_description" name="session_description" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="duration_minutes" class="form-label">Duration (minutes)</label>
                            <select class="form-select" id="duration_minutes" name="duration_minutes" required>
                                <option value="5">5 minutes</option>
                                <option value="10">10 minutes</option>
                                <option value="15">15 minutes</option>
                                <option value="20">20 minutes</option>
                                <option value="30">30 minutes</option>
                                <option value="45">45 minutes</option>
                                <option value="60">60 minutes</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="max_participants" class="form-label">Maximum Participants</label>
                            <input type="number" class="form-control" id="max_participants" name="max_participants" min="1" value="100" required>
                        </div>
                        <input type="hidden" name="create_session" value="1">
                        <button type="submit" class="btn btn-primary">Create Session</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>