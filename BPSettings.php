<?php
session_start();
require 'connection.php';

// Check if the user is logged in and is a barangay official for Business Permit
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'barangay_official') {
    header("Location: LoginForm.php");
    exit();
}

// Handle the password change submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'];
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    // Fetch the current password from the database
    try {
        $stmt = $pdo->prepare("SELECT password FROM Users WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($current_password, $user['password'])) {
            if ($new_password === $confirm_password) {
                if (validatePassword($new_password)) {
                    // Hash the new password
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

                    // Update the password in the database
                    $stmt = $pdo->prepare("UPDATE Users SET password = :password WHERE user_id = :user_id");
                    $stmt->execute(['password' => $hashed_password, 'user_id' => $user_id]);

                    // Log the action in the System_Logs table
                    $action = "Barangay Official (Business Permit) Changed password for user ID: " . $_SESSION['user_id'];
                    $log_stmt = $pdo->prepare("INSERT INTO System_Logs (user_id, action) VALUES (?, ?)");
                    $log_stmt->execute([$_SESSION['user_id'], $action]);

                    // Redirect or show success message
                    header("Location: BPSettings.php?success=1");
                    exit();
                } else {
                    $error_message = "New password does not meet the requirements.";
                }
            } else {
                $error_message = "New passwords do not match.";
            }
        } else {
            $error_message = "Current password is incorrect.";
        }
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        $error_message = "An unexpected error occurred. Please try again later.";
    }
}

// Function to validate the new password
function validatePassword($password) {
    // Check if the password meets the criteria
    return (strlen($password) >= 8 &&
            preg_match('/[A-Z]/', $password) &&
            preg_match('/[0-9]/', $password) &&
            preg_match('/[\W_]/', $password));
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Business Permit Department Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet"/>
    <style>
        body { font-family: 'Roboto', sans-serif; }
        .text-lg { font-size: 1.125rem; }
        .text-xl { font-size: 1.25rem; }
        .text-2xl { font-size: 1.5rem; }
        .text-3xl { font-size: 1.875rem; }
        .sidebar { transition: transform 0.3s ease-in-out; }
        .main-content { transition: margin-left 0.3s ease-in-out; }
    </style>
</head>
<body class="bg-gray-100 flex">
    <aside id="sidebar" class="sidebar bg-green-500 text-white w-64 min-h-screen p-6 fixed inset-y-0 left-0 transform -translate-x-full overflow-y-auto">
        <div class="text-center mb-8">
            <img alt="Business Permit Department Logo" class="mx-auto mb-4" height="100" src="https://storage.googleapis.com/a1aa/image/FIM565KosH2Eo5Lqps2r-iwpSdYyPwD4FcVD0OtHXMk.jpg" width="100"/>
            <h1 class="text-3xl font-bold">Business Permit Dept</h1>
        </div>
        <nav>
            <ul class="space-y-6">
                <li><a href="BPHome.php" class="block py-3 px-4 rounded hover:bg-green-600 text-lg">Home</a></li>
                <li><a href="BPProfile.php" class="block py-3 px-4 rounded hover:bg-green-600 text-lg">Profile</a></li>
                <li><a href="BPApplication.php" class="block py-3 px-4 rounded hover:bg-green-600 text-lg">Business Permit Applications</a></li>
                <li><a href="BPSettings.php" class="block py-3 px-4 rounded hover:bg-green-600 text-lg">Settings</a></li>
                <li><a href="logout.php" class="block py-3 px-4 rounded hover:bg-green-600 text-lg">Logout</a></li>
            </ul>
        </nav>
    </aside>
    <main id="main-content" class="main-content flex-grow p-8 ml-0">
        <div class="flex justify-between items-center mb-8">
            <h2 class="text-3xl font-bold">Settings</h2>
            <button class="text-green-500 focus:outline-none" onclick="toggleSidebar()">
                <i class="fas fa-bars text-2xl"></i>
            </button>
        </div>
        <div class="bg-white p-8 rounded-lg shadow-md mb-8">
            <h2 class="text-2xl font-bold mb-6">Change Password</h2>
            <form action="BPSettings.php" method="POST">
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="current_password">Current Password</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" id="current_password" type="password" name="current_password" placeholder="Enter current password" required/>
                </div>
                <div class="mb-4 relative">
                    <label class="block text-gray-700 font-medium mb-2" for="new_password">New Password</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" id="new_password" type="password" name="new_password" placeholder="Enter new password" required/>
                    <i class="fas fa-eye absolute right-3 top-10 cursor-pointer" onclick="togglePasswordVisibility('new_password')"></i>
                </div>
                <div class="mb-4 relative">
                    <label class="block text-gray-700 font-medium mb-2" for="confirm_password">Confirm Password</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" id="confirm_password" type="password" name="confirm_password" placeholder="Confirm new password" required/>
                    <i class="fas fa-eye absolute right-3 top-10 cursor-pointer" onclick="togglePasswordVisibility('confirm_password')"></i>
                </div>
                <button class="w-full bg-green-500 text-white py-2 rounded-lg hover:bg-green-600 focus:outline-none focus:ring-2 focus:ring-green-500" type="submit">Change Password</button>
            </form>
            <?php if (isset($error_message)): ?>
                <p class="text-red-500 mt-4"><?php echo $error_message; ?></p>
            <?php endif; ?>
            <?php if (isset($_GET['success'])): ?>
                <p class="text-green-500 mt-4">Password changed successfully!</p>
            <?php endif; ?>
        </div>
    </main>
    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('main-content');
            sidebar.classList.toggle('-translate-x-full');
            mainContent.classList.toggle('ml-64');
        }

        function togglePasswordVisibility(id) {
            const input = document.getElementById(id);
            const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
            input.setAttribute('type', type);
        }
    </script>
</body>
</html>