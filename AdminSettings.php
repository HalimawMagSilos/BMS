<?php
session_start();
require 'connection.php';

// Check if the user is logged in
if (!isset($_SESSION['user_id'])|| $_SESSION['role'] !== 'admin') {
    header("Location: LoginForm.php");
    exit();
}

// Initialize verification status
$is_verified = false;

// Check if the user is verified
try {
    $stmt = $pdo->prepare("SELECT is_verified FROM Users WHERE user_id = :user_id");
    $stmt->execute(['user_id' => $_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        $is_verified = $user['is_verified'];
    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
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
                    $action = "Changed password for user ID: " . $_SESSION['user_id'];
                    $log_stmt = $pdo->prepare("INSERT INTO System_Logs (user_id, action) VALUES (?, ?)");
                    $log_stmt->execute([$_SESSION['user_id'], $action]);

                    // Redirect or show success message
                    header("Location: AdminSettings.php?success=1");
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
        die("Database error: " . $e->getMessage());
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
<html>
 <head>
  <title>
   Admin Dashboard - Settings
  </title>
  <script src="https://cdn.tailwindcss.com">
  </script>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&amp;display=swap" rel="stylesheet"/>
  <style>
   body {
            font-family: 'Roboto', sans-serif;
        }
        .text-lg {
            font-size: 1.125rem;
        }
        .text-xl {
            font-size: 1.25rem;
        }
        .text-2xl {
            font-size: 1.5rem;
        }
        .text-3xl {
            font-size: 1.875rem;
        }
        .sidebar {
            transition: transform 0.3s ease-in-out;
        }
        .main-content {
            transition: margin-left 0.3s ease-in-out;
        }
  </style>
 </head>
 <body class="bg-gray-100 flex">
 <!-- Sidebar -->
 <aside class="sidebar bg-purple-500 text-white w-64 min-h-screen p-6 fixed inset-y-0 left-0 transform -translate-x-full overflow-y-auto" id="sidebar">
   <div class="text-center mb-8">
    <img alt="Admin Dashboard Logo" class="mx-auto mb-4" height="100" src="https://storage.googleapis.com/a1aa/image/oPytm4X-nQDT4FEekiF0fx9TqXQnTYbvl6Dyuau22Ho.jpg" width="100"/>
    <h1 class="text-3xl font-bold">
     Admin Dashboard
    </h1>
   </div>
   <nav>
            <ul class="space-y-6">
                <li><a class="block py-3 px-4 rounded hover:bg-purple-600 text-lg" href="AdminHome.php">Home</a></li>
                <li><a class="block py-3 px-4 rounded hover:bg-purple-600 text-lg" href="AdminManageUser.php">Manage Users</a></li>
                <li>
                    <button class="block w-full text-left py-3 px-4 rounded hover:bg-purple-600 focus:outline-none text-lg" onclick="toggleDropdown()">Manage Applications</button>
                    <ul class="hidden space-y-2 pl-4" id="dropdown">
                        <li><a class="block py-3 px-4 rounded hover:bg-purple-600 text-lg" href="AdminBarangayClearance.php">Barangay Clearance</a></li>
                        <li><a class="block py-3 px-4 rounded hover:bg-purple-600 text-lg" href="AdminBusinessPermit.php">Business Permit</a></li>
                        <li><a class="block py-3 px-4 rounded hover:bg-purple-600 text-lg" href="AdminFinancialAssistance.php">Financial Assistance</a></li>
                    </ul>
                </li>
                <li><a class="block py-3 px-4 rounded hover:bg-purple-600 text-lg" href="AdminAnalytics.php">View Analytics</a></li>
                <li><a class="block py-3 px-4 rounded hover:bg-purple-600 text-lg" href="AdminMessages.php">Messages</a></li>
                <li><a class="block py-3 px-4 rounded hover:bg-purple-600 text-lg" href="AdminComplaints.php">Complaints</a></li>
                <li><a class="block py-3 px-4 rounded hover:bg-purple-600 text-lg" href="AdminPayment.php">Payment</a></li>
                <li><a class="block py-3 px-4 rounded hover:bg-purple-600 text-lg" href="AdminSystemLogs.php">System Logs</a></li>
                <li><a class="block py-3 px-4 rounded hover:bg-purple-600 text-lg" href="AdminSettings.php">Settings</a></li>
                <li><a class="block py-3 px-4 rounded hover:bg-purple-600 text-lg" href="logout.php">Logout</a></li>
            </ul>
        </nav>
  </aside>
  <!-- Main Content -->
  <main class="main-content flex-grow p-8 ml-0" id="main-content">
   <div class="flex justify-between items-center mb-8">
    <h2 class="text-3xl font-bold">
     Settings
    </h2>
    <button class="text-purple-500 focus:outline-none" onclick="toggleSidebar()">
     <i class="fas fa-bars text-2xl">
     </i>
    </button>
   </div>
   <div class="bg-white p-8 rounded-lg shadow-md mb-8">
            <h2 class="text-2xl font-bold mb-6">Settings</h2>
            <form action="ResidentSettings.php" method="POST">
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="current_password">Current Password</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="current_password" type="password" name="current_password" placeholder="Enter current password" required/>
                </div>
                <div class="mb-4 relative">
                    <label class="block text-gray-700 font-medium mb-2" for="new_password">New Password</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="new_password" type="password" name="new_password" placeholder="Enter new password" required/>
                    <i class="fas fa-eye absolute right-3 top-10 cursor-pointer" onclick="togglePasswordVisibility('new_password')"></i>
                </div>
                <div class="mb-4 relative">
                    <label class="block text-gray-700 font-medium mb-2" for="confirm_password">Confirm Password</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="confirm_password" type="password" name="confirm_password" placeholder="Confirm new password" required/>
                    <i class="fas fa-eye absolute right-3 top-10 cursor-pointer" onclick="togglePasswordVisibility('confirm_password')"></i>
                </div>
                <button class="w-full bg-blue-500 text-white py-2 rounded-lg hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500" type="submit">Change Password</button>
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

        function toggleDropdown() {
            const dropdown = document.getElementById('dropdown');
            dropdown.classList.toggle('hidden');
        }

        function togglePasswordVisibility(id) {
            const passwordField = document.getElementById(id);
            const eyeIcon = passwordField.nextElementSibling;
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                eyeIcon.classList.remove('fa-eye');
                eyeIcon.classList.add('fa-eye-slash');
            } else {
                passwordField.type = ('password');
                eyeIcon.classList.remove('fa-eye-slash');
                eyeIcon.classList.add('fa-eye');
            }
        }
  </script>
 </body>
</html>