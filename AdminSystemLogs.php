<?php
session_start();

// Include the database connection
require 'connection.php';

// Check if admin is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: LoginForm.php");
    exit();
}


// Function to fetch system logs based on the selected date
function fetchSystemLogs($pdo, $date = null) {
    $sql = "SELECT log_id, user_id, action, timestamp FROM System_Logs";
    if ($date) {
        $sql .= " WHERE DATE(timestamp) = :date";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':date', $date);
    } else {
        $stmt = $pdo->prepare($sql);
    }
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle form submission
$logs = [];
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $selected_date = $_POST['log_date'];
    $logs = fetchSystemLogs($pdo, $selected_date);
} else {
    $logs = fetchSystemLogs($pdo);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - System Logs</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet"/>
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
        .log-table-container {
            max-height: 600px; /* Adjust as needed */
            overflow-y: auto;
        }
    </style>
</head>
<body class="bg-gray-100 flex">
    <aside class="sidebar bg-purple-500 text-white w-64 min-h-screen p-6 fixed inset-y-0 left-0 transform -translate-x-full overflow-y-auto" id="sidebar">
        <div class="text-center mb-8">
            <img alt="Admin Dashboard Logo" class="mx-auto mb-4" height="100" src="https://storage.googleapis.com/a1aa/image/oPytm4X-nQDT4FEekiF0fx9TqXQnTYbvl6Dyuau22Ho.jpg" width="100"/>
            <h1 class="text-3xl font-bold">Admin Dashboard</h1>
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
    <main class="main-content flex-grow p-8 ml-0 transition duration-200" id="main-content">
        <div class="flex justify-between items-center mb-8">
            <h2 class="text-3xl font-bold">System Logs</h2>
            <button class="text-purple-600 focus:outline-none" onclick="toggleSidebar()">
                <i class="fas fa-bars text-2xl"></i>
            </button>
        </div>
        <div class="bg-white p-8 rounded-lg shadow-md">
            <h2 class="text-2xl font-bold mb-6">Activity Logs</h2>
            <form method="POST" class="mb-4">
                <label for="log_date" class="block text-sm font-medium text-gray-700">Select Date:</label>
                <input type="date" name="log_date" id="log_date" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-purple-500 focus:ring focus:ring-purple-200" required>
                <button type="submit" class="mt-2 bg-purple-600 text-white py-2 px-4 rounded hover:bg-purple-700">Filter Logs</button>
            </form>
            <div class="log-table-container">
                <table class="min-w-full bg-white border border-gray-200 rounded-lg shadow-sm">
                    <thead>
                        <tr>
                            <th class="py-3 px-4 border-b border-gray-200 bg-gray-100 text-left text-sm font-semibold text-gray-700">Log ID</th>
                            <th class="py-3 px-4 border-b border-gray-200 bg-gray-100 text-left text-sm font-semibold text-gray-700">User  ID</th>
                            <th class="py-3 px-4 border-b border-gray-200 bg-gray-100 text-left text-sm font-semibold text-gray-700">Action</th>
                            <th class="py-3 px-4 border-b border-gray-200 bg-gray-100 text-left text-sm font-semibold text-gray-700">Timestamp</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($logs) > 0): ?>
                            <?php foreach ($logs as $row): ?>
                                <tr class="hover:bg-gray-50 transition duration-200">
                                    <td class="py-3 px-4 border-b border-gray-200 text-sm text-gray-700"><?php echo htmlspecialchars($row['log_id']); ?></td>
                                    <td class="py-3 px-4 border-b border-gray-200 text-sm text-gray-700"><?php echo htmlspecialchars($row['user_id']); ?></td>
                                    <td class="py-3 px-4 border-b border-gray-200 text-sm text-gray-700"><?php echo htmlspecialchars($row['action']); ?></td>
                                    <td class="py-3 px-4 border-b border-gray-200 text-sm text-gray-700"><?php echo htmlspecialchars($row['timestamp']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="py-3 px-4 border-b border-gray-200 text-sm text-gray-700 text-center">No logs found for the selected date.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
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
    </script>
</body>
</html>