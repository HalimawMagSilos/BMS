<?php
// Start the session
session_start();

// Include the database connection file
require 'connection.php';

// Initialize variables
$email = '';
$password = '';
$confirm_password = '';
$password_error = '';
$confirm_password_error = '';
$success_message = '';
$error_message = '';

// Get email from the URL
if (isset($_GET['email'])) {
    $email = trim($_GET['email']);
} else {
    $error_message = "Invalid reset link.";
}

// Process password reset form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // Validate password
    if (strlen($password) < 8) {
        $password_error = "Password must be at least 8 characters long.";
    } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $password_error = "Password must contain at least one letter and one number.";
    }

    // Validate confirm password
    if ($password != $confirm_password) {
        $confirm_password_error = "Passwords do not match.";
    }

    // If no errors, update the password
    if (empty($password_error) && empty($confirm_password_error)) {
        $stmt = $pdo->prepare("SELECT user_id FROM Users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if($user){
            // Hash the new password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            // Update the user's password in the database
            $stmt = $pdo->prepare("UPDATE Users SET password = :password WHERE email = :email");
            $stmt->execute(['password' => $hashed_password, 'email' => $email]);

            $success_message = "Password has been reset successfully. You can now <a href='LoginForm.php'>login</a>.";
        } else {
            $error_message = "Invalid or expired reset link.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barangay Management System - Reset Password</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Roboto', sans-serif;
        }
    </style>
</head>

<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="bg-white shadow-lg rounded-lg flex w-full max-w-md p-6">
        <div class="w-full">
            <h2 class="text-2xl font-bold text-gray-800 mb-4">Reset Your Password</h2>

            <?php if ($error_message): ?>
                <p class="text-red-500 text-sm mb-4"><?php echo $error_message; ?></p>
            <?php elseif ($success_message): ?>
                <p class="text-green-500 text-sm mb-4"><?php echo $success_message; ?></p>
            <?php else: ?>
                <form method="POST" action="">
                    <div class="mb-4">
                        <label for="password" class="block text-gray-700 text-sm font-bold mb-2">New Password</label>
                        <input type="password" id="password" name="password"
                            class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline"
                            required>
                        <?php if ($password_error): ?>
                            <p class="text-red-500 text-xs italic"><?php echo $password_error; ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="mb-6">
                        <label for="confirm_password" class="block text-gray-700 text-sm font-bold mb-2">Confirm New
                            Password</label>
                        <input type="password" id="confirm_password" name="confirm_password"
                            class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline"
                            required>
                        <?php if ($confirm_password_error): ?>
                            <p class="text-red-500 text-xs italic"><?php echo $confirm_password_error; ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="flex items-center justify-between">
                        <button
                            class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline"
                            type="submit">
                            Reset Password
                        </button>
                    </div>
                    <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>