<?php
// Start the session
session_start();

// Include the database connection file
require 'connection.php';

// Initialize variables
$email = '';
$password = '';
$confirm_password = '';
$email_error = '';
$password_error = '';
$registration_success = '';

// Check if the form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get the email and password from the form
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
    $confirm_password = trim($_POST['confirm-password']);

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email_error = "Invalid email format.";
    } else {
        // Check if the email already exists in the database
        $stmt = $pdo->prepare("SELECT * FROM Users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        if ($stmt->rowCount() > 0) {
            $email_error = "Email already exists.";
        }
    }

    // Validate password
    if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[\W_]/', $password)) {
        $password_error = "Password must be at least 8 characters long, contain at least one uppercase letter, one number, and one special character.";
    } elseif ($password !== $confirm_password) {
        $password_error = "Passwords do not match.";
    }

    // If there are no errors, proceed with registration
    if (empty($email_error) && empty($password_error)) {
        // Hash the password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // Insert the new user into the database with the role set to 'resident'
        try {
            $stmt = $pdo->prepare("INSERT INTO Users (email, password, role) VALUES (:email, :password, 'resident')");
            $stmt->execute(['email' => $email, 'password' => $hashed_password]);

            // Log the successful registration action
            $user_id = $pdo->lastInsertId(); // Get the ID of the newly registered user
            $action = "New user registered: " . $email;
            $log_stmt = $pdo->prepare("INSERT INTO System_Logs (user_id, action) VALUES (?, ?)");
            $log_stmt->execute([$user_id, $action]);

            $registration_success = "Registration successful! You can now log in.";
        } catch (PDOException $e) {
            $registration_success = "Registration failed: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Barangay Management System - Registration</title>
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
                <img alt="Barangay Management System Logo" class="mx-auto mb-4" height="100" src="https://storage.googleapis.com/a1aa/image/ZlS8sK0YNai5MGRJForAKu4_20-Z3HeENsCVvw_X1Vk.jpg" width="100">
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
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="password">Password</label>
                    <div class="relative">
                        <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 pr-10" id="password" name="password" placeholder="Enter your password" type="password"/>
                        <span class="absolute inset-y-0 right-0 pr-3 flex items-center text-sm leading-5">
                            <i class="fas fa-eye cursor-pointer" id="togglePassword"></i>
                        </span>
                    </div>
                    <?php if ($password_error): ?>
                        <p class="text-red-500 text-xs italic"><?php echo $password_error; ?></p>
                    <?php endif; ?>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="confirm-password">Confirm Password</label>
                    <div class="relative">
                        <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 pr-10" id="confirm-password" name="confirm-password" placeholder="Confirm your password" type="password"/>
                        <span class="absolute inset-y-0 right-0 pr-3 flex items-center text-sm leading-5">
                            <i class="fas fa-eye cursor-pointer" id="toggleConfirmPassword"></i>
                        </span>
                    </div>
                </div>
                <div class="flex items-center justify-between mb-6">
                    <a class="text-sm text-blue-500 hover:underline" href="LoginForm.php">Already have an account? Login</a>
                </div>
                <button class="w-full bg-blue-500 text-white py-2 rounded-lg hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500" type="submit">Register</button>
                <?php if ($registration_success): ?>
                    <p class="text-green-500 text-xs italic mt-4"><?php echo $registration_success; ?></p>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <script>
        const togglePassword = document.querySelector('#togglePassword');
        const password = document.querySelector('#password');
        const toggleConfirmPassword = document.querySelector('#toggleConfirmPassword');
        const confirmPassword = document.querySelector('#confirm-password');

        togglePassword.addEventListener('click', function (e) {
            // toggle the type attribute
            const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
            password.setAttribute('type', type);
            // toggle the eye slash icon
            this.classList.toggle('fa-eye-slash');
        });

        toggleConfirmPassword.addEventListener('click', function (e) {
            // toggle the type attribute
            const type = confirmPassword.getAttribute('type') === 'password' ? 'text' : 'password';
            confirmPassword.setAttribute('type', type);
            // toggle the eye slash icon
            this.classList.toggle('fa-eye-slash');
        });
    </script>
</body>
</html>