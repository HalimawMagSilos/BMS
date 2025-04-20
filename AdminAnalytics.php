<?php
// Start the session
session_start();

// Include the database connection
require 'connection.php';

// Check if admin is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: LoginForm.php");
    exit();
}

// Initialize variables for date filtering
$startDate = isset($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-01');
$endDate = isset($_POST['end_date']) ? $_POST['end_date'] : date('Y-m-t');

// Fetch total users
$stmt = $pdo->query("SELECT COUNT(*) as total_users FROM Users");
$totalUsers = $stmt->fetch(PDO::FETCH_ASSOC)['total_users'];

// Fetch active users (for example, users who registered within the last 30 days)
$stmt = $pdo->query("SELECT COUNT(*) as active_users FROM Users WHERE created_at >= NOW() - INTERVAL 30 DAY");
$totalActiveUsers = $stmt->fetch(PDO::FETCH_ASSOC)['active_users'];

// Fetch monthly transactions for barangay clearance
$stmt = $pdo->prepare("
    SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS total_barangay
    FROM Barangay_Clearance_Applications
    WHERE created_at BETWEEN ? AND ?
    GROUP BY month
");
$stmt->execute([$startDate, $endDate]);
$barangayData = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch monthly transactions for business permits
$stmt = $pdo->prepare("
    SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS total_business
    FROM Business_Permit_Applications
    WHERE created_at BETWEEN ? AND ?
    GROUP BY month
");
$stmt->execute([$startDate, $endDate]);
$businessData = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch monthly transactions for financial assistance
$stmt = $pdo->prepare("
    SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS total_financial
    FROM Financial_Assistance_Applications
    WHERE created_at BETWEEN ? AND ?
    GROUP BY month
");
$stmt->execute([$startDate, $endDate]);
$financialData = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Prepare data for charts
$months = [];
$barangayCounts = [];
$businessCounts = [];
$financialCounts = [];

// Populate data arrays
foreach ($barangayData as $row) {
    $months[] = $row['month'];
    $barangayCounts[] = $row['total_barangay'];
}

foreach ($businessData as $row) {
    $businessCounts[] = $row['total_business'];
}

foreach ($financialData as $row) {
    $financialCounts[] = $row['total_financial'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - View Analytics</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet"/>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        canvas {
            max-width: 100%; /* Ensure the canvas fits within the container */
        }
    </style>
</head>
<body class="bg-gray-100 flex">
    <!-- Sidebar -->
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
    <!-- Main Content -->
    <main class="main-content flex-grow p-8 ml-0" id="main-content">
        <div class="flex justify-between items-center mb-8">
            <h2 class="text-3xl font-bold">View Analytics</h2>
            <button class="text-purple-500 focus:outline-none" onclick="toggleSidebar()">
                <i class="fas fa-bars text-2xl"></i>
            </button>
        </div>
        <div class="bg-white p-8 rounded-lg shadow-md mb-8">
            <h2 class="text-2xl font-bold mb-6">Filter Transactions by Date</h2>
            <form method="POST" class="mb-4">
                <div class="flex space-x-4">
                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="start_date">Start Date</label>
                        <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="start_date" name="start_date" type="date" value="<?php echo $startDate; ?>" required/>
                    </div>
                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="end_date">End Date</label>
                        <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="end_date" name="end_date" type="date" value="<?php echo $endDate; ?>" required/>
                    </div>
                </div>
                <button type="submit" class="bg-purple-500 text-white px-4 py-2 rounded">Filter Data</button>
            </form>
            <canvas id="barangayChart" width="400" height="200" class="mt-6"></canvas>
        </div>
        <div class="bg-white p-8 rounded-lg shadow-md mb-8">
            <h2 class="text-2xl font-bold mb-6">Business Permit Transactions</h2>
            <canvas id="businessChart" width="400" height="200" class="mt-6"></canvas>
        </div>
        <div class="bg-white p-8 rounded-lg shadow-md">
            <h2 class="text-2xl font-bold mb-6">Financial Assistance Transactions</h2>
            <canvas id="financialChart" width="400" height="200" class="mt-6"></canvas>
        </div>
        <div class="bg-white p-8 rounded-lg shadow-md mb-8">
            <h2 class="text-2xl font-bold mb-6">Total Users</h2>
            <p class="text-lg">Total Users: <?php echo $totalUsers; ?></p>
            <h2 class="text-2xl font-bold mb-6">Active Users (Last 30 Days)</h2>
            <p class="text-lg">Active Users: <?php echo $totalActiveUsers; ?></p>
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

        // Chart.js scripts
        const barangayChartCtx = document.getElementById('barangayChart').getContext('2d');
        const barangayChart = new Chart(barangayChartCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_column($barangayData, 'month')); ?>,
                datasets: [{
                    label: 'Barangay Clearance Transactions',
                    data: <?php echo json_encode(array_column($barangayData, 'total_barangay')); ?>,
                    backgroundColor: ['#4c51bf'],
                }]
            },
            options: {
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        const businessChartCtx = document.getElementById('businessChart').getContext('2d');
        const businessChart = new Chart(businessChartCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_column($businessData, 'month')); ?>,
                datasets: [{
                    label: 'Business Permit Transactions',
                    data: <?php echo json_encode(array_column($businessData, 'total_business')); ?>,
                    backgroundColor: ['#4c51bf'],
                }]
            },
            options: {
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        const financialChartCtx = document.getElementById('financialChart').getContext('2d');
        const financialChart = new Chart(financialChartCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_column($financialData, 'month')); ?>,
                datasets: [{
                    label: 'Financial Assistance Transactions',
                    data: <?php echo json_encode(array_column($financialData, 'total_financial')); ?>,
                    backgroundColor: ['#4c51bf'],
                }]
            },
            options: {
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    </script>
</body>
</html>