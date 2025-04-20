<?php
// Start the session
session_start();

// Include the database connection file
require 'connection.php';

// Initialize variables
$email = '';
$password = '';
$email_error = '';
$login_error = '';

// Check if the form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get the email and password from the form
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email_error = "Invalid email format.";
    } else {
        // Proceed with login logic
        try {
            // Prepare the SQL statement to get user details
            $stmt = $pdo->prepare("SELECT user_id, password, role, is_verified 
                                    FROM Users 
                                    WHERE email = :email");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();

            // Check if user exists and verify password
            if ($user && password_verify($password, $user['password'])) {
                // Store user information in session
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['role'] = $user['role'];

                // Log the successful login action
                $action = "User  logged in: " . $email;
                $log_stmt = $pdo->prepare("INSERT INTO System_Logs (user_id, action) VALUES (?, ?)");
                $log_stmt->execute([$user['user_id'], $action]);

                // Check if the user is verified
                if ($user['is_verified']) {
                    // Fetch first name from Profiles table only if the user is not a resident
                    if ($user['role'] !== 'resident') {
                        $stmt = $pdo->prepare("SELECT first_name FROM Profiles WHERE user_id = :user_id");
                        $stmt->execute(['user_id' => $user['user_id']]);
                        $profile = $stmt->fetch();
                        $_SESSION['first_name'] = $profile['first_name'] ?? ''; // Default to empty if not found
                    } else {
                        $_SESSION['first_name'] = ''; // No first name for unverified residents
                    }

                    // Fetch department ID from Barangay_Officials table
                    $stmt = $pdo->prepare("SELECT department_id FROM Barangay_Officials WHERE user_id = :user_id");
                    $stmt->execute(['user_id' => $user['user_id']]);
                    $official = $stmt->fetch();

                    // Redirect to the appropriate dashboard based on role and department
                    switch ($user['role']) {
                        case 'admin':
                            header("Location: AdminHome.php");
                            exit();
                        case 'barangay_official':
                            if ($official) {
                                // Redirect based on department ID
                                switch ($official['department_id']) {
                                    case 1:
                                        header("Location: BCHome.php");
                                        exit();
                                    case 2:
                                        header("Location: BPHome.php");
                                        exit();
                                    case 3:
                                        header("Location: FAHome.php");
                                        exit();
                                    default:
                                        $login_error = "Invalid department.";
                                        exit();
                                }
                            } else {
                                $login_error = "Department not found for this official.";
                            }
                            exit();
                        case 'resident':
                            // Redirect unverified residents to ResidentHome.php
                            header("Location: ResidentHome.php");
                            exit();
                        default:
                            $login_error = "Invalid role.";
                    }
                } else {
                    // Redirect unverified residents to ResidentHome.php
                    if ($user['role'] === 'resident') {
                        header("Location: ResidentHome.php");
                        exit();
                    } else {
                        $login_error = "Your account is not verified.";
                    }
                }
            } else {
                $login_error = "Invalid email or password.";
            }
        } catch (PDOException $e) {
            $login_error = "Database error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Barangay Management System - Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet"/>
    <style>
        body {
            font-family: 'Roboto', sans-serif;
        }
    </style>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="bg-white shadow-lg rounded-lg flex w-full max-w-4xl overflow-hidden">
        <div class="w-1/2 hidden md:block">
            <img alt="A scenic view of a barangay with houses and trees" class="w-full h-full object-cover" src="https://storage.googleapis.com/a1aa/image/8guJdJ9kl_e9mzmzy8s-j3imgf20P1BH9L5QUlbX-f8.jpg"/>
        </div>
        <div class="w-full md:w-1/2 p-10 flex flex-col justify-center">
            <div class="text-center mb-6">
                <img alt="Barangay San Agustin Logo" class="mx-auto mb-4" height="100" src="URL_OF_BARANGAY_SAN_AGUSTIN_LOGO" width="100"> <!-- Replace with actual URL -->
                <h2 class="text-3xl font-bold text-gray-800">Barangay Management System</h2>
            </div>
            <form method="POST" action="">
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="email">Email</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="email" name="email" placeholder="Enter your email" type="email" value="<?php echo htmlspecialchars($email); ?>"/>
                    <?php if ($email_error): ?>
                        <p class="text-red-500 text-xs italic"><?php echo $email_error; ?></p>
                    <?php endif; ?>
                </div>
                <div class="mb-4 relative">
                    <label class="block text-gray-700 font-medium mb-2" for="password">Password</label>
                    <div class="relative">
                        <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 pr-10" id="password" name="password" placeholder="Enter your password" type="password"/>
                        <span class="absolute inset-y-0 right-0 pr-3 flex items-center text-sm leading-5">
                            <i class="fas fa-eye cursor-pointer" id="togglePassword"></i>
                        </span>
                    </div>
                </div>
                <div class="flex items-center justify-between mb-6">
                    <a class="text-sm text-blue-500 hover:underline" href="forgot.php">Forgot Password?</a>
                    <a class="text-sm text-blue-500 hover:underline" href="registration.php">Register</a>
                </div>
                <button class="w-full bg-blue-500 text-white py-2 rounded-lg hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500" type="submit">Login</button>
                <?php if ($login_error): ?>
                    <p class="text-red-500 text-xs italic mt-4"><?php echo $login_error; ?></p>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <script>
        const togglePassword = document.querySelector('#togglePassword');
        const password = document.querySelector('#password');

        togglePassword.addEventListener('click', function (e) {
            // toggle the type attribute
            const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
            password.setAttribute('type', type);
            // toggle the eye slash icon
            this.classList.toggle('fa-eye-slash');
        });
    </script>
</body>
</html>