<?php
// index.php - Landing page
session_start();
require_once "config.php";

$code_error = "";

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["access_code"])) {
    if (empty(trim($_POST["access_code"]))) {
        $code_error = "Please enter an access code.";
    } else {
        $access_code = sanitize_input($conn, $_POST["access_code"]);
        
        // Check if the code exists
        $sql = "SELECT session_id FROM voting_sessions WHERE access_code = ?";
        
        if ($stmt = mysqli_prepare($conn, $sql)) {
            mysqli_stmt_bind_param($stmt, "s", $access_code);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_store_result($stmt);
            
            if (mysqli_stmt_num_rows($stmt) == 1) {
                // Redirect to voting page
                header("location: vote.php?code=" . $access_code);
                exit;
            } else {
                $code_error = "Invalid access code.";
            }
            
            mysqli_stmt_close($stmt);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voting System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        .container {
            max-width: 500px;
        }
        .card {
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .card-header {
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            color: white;
            border-radius: 15px 15px 0 0 !important;
            padding: 25px;
            text-align: center;
        }
        .card-header h1 {
            font-size: 2.2rem;
            margin-bottom: 5px;
        }
        .card-body {
            padding: 30px;
        }
        .form-control {
            padding: 15px;
            border-radius: 10px;
            font-size: 1.2rem;
            margin-bottom: 15px;
        }
        .btn-primary {
            width: 100%;
            padding: 12px;
            font-size: 1.2rem;
            font-weight: bold;
            border-radius: 30px;
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            border: none;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #0069d9 0%, #004494 100%);
        }
        .form-label {
            font-weight: 600;
            font-size: 1.1rem;
        }
        .admin-link {
            text-align: center;
            margin-top: 20px;
        }
        .features {
            margin-top: 30px;
        }
        .feature-item {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }
        .feature-icon {
            width: 40px;
            height: 40px;
            background-color: #e6f2ff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            color: #007bff;
            font-size: 1.2rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-header">
                <h1>Mobile Voting System</h1>
                <p>Cast your vote quickly and securely</p>
            </div>
            <div class="card-body">
                <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                    <div class="mb-3">
                        <label for="access_code" class="form-label">Enter Access Code</label>
                        <input type="text" class="form-control <?php echo (!empty($code_error)) ? 'is-invalid' : ''; ?>" 
                               id="access_code" name="access_code" placeholder="Enter the code provided by the admin">
                        <div class="invalid-feedback">
                            <?php echo $code_error; ?>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Join Voting Session</button>
                </form>
                
                <div class="features">
                    <h5>System Features:</h5>
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="bi bi-shield-check"></i>
                        </div>
                        <div>
                            <strong>Secure Voting</strong>
                            <p class="mb-0 small">Your vote is secure and anonymous</p>
                        </div>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="bi bi-phone"></i>
                        </div>
                        <div>
                            <strong>Mobile Friendly</strong>
                            <p class="mb-0 small">Vote from any device with internet access</p>
                        </div>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="bi bi-clock"></i>
                        </div>
                        <div>
                            <strong>Timed Sessions</strong>
                            <p class="mb-0 small">Voting sessions automatically close after the specified time</p>
                        </div>
                    </div>
                </div>
                
                <div class="admin-link">
                    <a href="admin_login.php">Admin Login</a> | <a href="admin_register.php">Create Admin Account</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>