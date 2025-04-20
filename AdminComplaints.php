<?php
// Start the session
session_start();

// Include the database connection and PHPMailer
require 'connection.php';
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Check if the user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: LoginForm.php'); // Redirect to login page if not logged in or not admin
    exit;
}

// --- Database Table and Column Names (MATCH THESE EXACTLY WITH YOUR DATABASE) ---
$db_users_table = 'Users';
$db_profiles_table = 'Profiles';
$db_complaints_table = 'Complaints';
$db_system_logs_table = 'System_Logs';

$db_user_id_col = 'user_id';
$db_user_email_col = 'email';
$db_user_role_col = 'role';

$db_profile_first_name_col = 'first_name';
$db_profile_middle_name_col = 'middle_name';
$db_profile_last_name_col = 'last_name';
$db_profile_suffix_col = 'suffix';
$db_profile_address_col = 'address';

$db_complaint_id_col = 'complaint_id';
$db_complaint_resident_id_col = 'resident_id'; // Corrected variable name
$db_complaint_details_col = 'complaint_details';
$db_complaint_fee_col = 'fee';
$db_complaint_status_col = 'status';
$db_complaint_created_at_col = 'created_at';
$db_complaint_updated_at_col = 'updated_at';

$db_system_log_user_id_col = 'user_id';
$db_system_log_action_col = 'action';
$db_system_log_timestamp_col = 'timestamp';

// --- PHP Functions ---

// Function to log system actions
function logSystemAction($pdo, $userId, $action, $db_system_logs_table, $db_system_log_user_id_col, $db_system_log_action_col)
{
    $stmt = $pdo->prepare("INSERT INTO $db_system_logs_table ($db_system_log_user_id_col, $db_system_log_action_col) VALUES (?, ?)");
    $stmt->execute([$userId, $action]);
}

// Function to fetch all users (residents, officials, admins)
function fetchAllUsers($pdo, $db_users_table, $db_profiles_table, $db_user_id_col, $db_user_email_col, $db_user_role_col, $db_profile_first_name_col, $db_profile_middle_name_col, $db_profile_last_name_col, $db_profile_suffix_col, $db_profile_address_col)
{
    $stmt = $pdo->prepare("
        SELECT
            u.$db_user_id_col AS user_id,
            u.$db_user_email_col AS email,
            u.$db_user_role_col AS role,
            p.$db_profile_first_name_col AS first_name,
            p.$db_profile_middle_name_col AS middle_name,
            p.$db_profile_last_name_col AS last_name,
            p.$db_profile_suffix_col AS suffix,
            p.$db_profile_address_col AS address
        FROM $db_users_table u
        LEFT JOIN $db_profiles_table p ON u.$db_user_id_col = p.$db_user_id_col
        ORDER BY u.$db_user_role_col, p.$db_profile_last_name_col, p.$db_profile_first_name_col
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to fetch complaints for a specific user (FOR AJAX)
function fetchComplaintsByUserAJAX($pdo, $userId, $db_complaints_table, $db_complaint_id_col, $db_complaint_details_col, $db_complaint_fee_col, $db_complaint_status_col, $db_complaint_created_at_col, $db_complaint_updated_at_col, $db_complaint_resident_id_col)
{
    $stmt = $pdo->prepare("SELECT $db_complaint_id_col, $db_complaint_details_col, $db_complaint_fee_col, $db_complaint_status_col, DATE_FORMAT($db_complaint_created_at_col, '%Y-%m-%d %H:%i:%s') AS created_at, DATE_FORMAT($db_complaint_updated_at_col, '%Y-%m-%d %H:%i:%s') AS updated_at FROM $db_complaints_table WHERE $db_complaint_resident_id_col = ? ORDER BY $db_complaint_created_at_col DESC");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to get user email by ID
function getUserEmail($pdo, $userId, $db_users_table, $db_user_id_col, $db_user_email_col)
{
    $stmt = $pdo->prepare("SELECT $db_user_email_col FROM $db_users_table WHERE $db_user_id_col = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user ? $user[$db_user_email_col] : null;
}

// Function to add a new complaint
function addComplaint($pdo, $userId, $details, $fee, $status, $db_complaints_table, $db_complaint_resident_id_col, $db_complaint_details_col, $db_complaint_fee_col, $db_complaint_status_col,$db_complaint_created_at_col)
{
    $stmt = $pdo->prepare("INSERT INTO $db_complaints_table ($db_complaint_resident_id_col, $db_complaint_details_col, $db_complaint_fee_col, $db_complaint_status_col, $db_complaint_created_at_col) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)");
    return $stmt->execute([$userId, $details, $fee, $status]);
}
// Function to update an existing complaint
function updateComplaint($pdo, $complaintId, $details, $fee, $status, $db_complaints_table, $db_complaint_details_col, $db_complaint_fee_col, $db_complaint_status_col, $db_complaint_id_col, $db_complaint_updated_at_col)
{
    $stmt = $pdo->prepare("UPDATE $db_complaints_table SET $db_complaint_details_col = ?, $db_complaint_fee_col = ?, $db_complaint_status_col = ?, $db_complaint_updated_at_col = CURRENT_TIMESTAMP WHERE $db_complaint_id_col = ?");
    return $stmt->execute([$details, $fee, $status, $complaintId]);
}

// Function to send email notifications
function sendEmail($to, $subject, $message)
{
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com'; // Replace with your SMTP server
        $mail->SMTPAuth = true;
        $mail->Username = 'ellema.darrell17@gmail.com'; // Replace with your email address
        $mail->Password = 'mvwq ilib rppn ftjk'; // Replace with your email password
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;

        $mail->setFrom('ellema.darrell17@gmail.com', 'Barangay Management System'); // Replace with your email and system name
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $message;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email could not be sent. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

// --- Handle AJAX Request for Fetching Complaints ---
if (isset($_GET['user_id']) && is_numeric($_GET['user_id'])) {
    $userId = $_GET['user_id'];
    $complaints = fetchComplaintsByUserAJAX($pdo, $userId, $db_complaints_table, $db_complaint_id_col, $db_complaint_details_col, $db_complaint_fee_col, $db_complaint_status_col, $db_complaint_created_at_col, $db_complaint_updated_at_col, $db_complaint_resident_id_col);
    header('Content-Type: application/json');
    echo json_encode($complaints);
    exit();
}

// --- Handle Form Submissions ---
$message = ''; // Initialize message variable

if (isset($_POST['add_complaint'])) {
    $userId = $_POST['user_id'] ?? null;
    $details = $_POST['complaint_details'] ?? '';
    $fee = $_POST['fee'] ?? 0;
    $status = $_POST['status'] ?? 'pending';

    if ($userId && $details !== '' && is_numeric($fee)) {
        if (addComplaint(
            $pdo,
            $userId,
            $details,
            $fee,
            $status,
            $db_complaints_table,
            $db_complaint_resident_id_col,
            $db_complaint_details_col,
            $db_complaint_fee_col,
            $db_complaint_status_col,
            $db_complaint_created_at_col // Correct call - DO NOT add $db_complaint_created_at_col here
        )) {
            $userEmail = getUserEmail($pdo, $userId, $db_users_table, $db_user_id_col, $db_user_email_col);
            if ($userEmail) {
                $subject = "New Complaint Filed";
                $messageBody = "<p>A new complaint has been filed with the following details:</p>";
                $messageBody .= "<p><strong>Details:</strong> " . htmlspecialchars($details) . "</p>";
                $messageBody .= "<p><strong>Fee:</strong> ₱" . htmlspecialchars(number_format($fee, 2)) . "</p>";
                $messageBody .= "<p><strong>Status:</strong> " . htmlspecialchars(ucfirst($status)) . "</p>";
                $messageBody .= "<p>Please log in to your account for more information.</p>";
                sendEmail($userEmail, $subject, $messageBody);
            }
            logSystemAction($pdo, $_SESSION['user_id'], "Added new complaint for User ID: $userId", $db_system_logs_table, $db_system_log_user_id_col, $db_system_log_action_col);
            $_SESSION['success_message'] = 'Complaint added successfully.';
        } else {
            $_SESSION['error_message'] = 'Failed to add complaint.';
        }
    } else {
        $_SESSION['error_message'] = 'Invalid input for adding complaint.';
    }
    header("Location: AdminComplaints.php");
    exit();
}

if (isset($_POST['edit_complaint'])) {
    $complaintId = $_POST['complaint_id'] ?? null;
    $userId = $_POST['user_id'] ?? null;
    $details = $_POST['complaint_details'] ?? '';
    $fee = $_POST['fee'] ?? 0;
    $status = $_POST['status'] ?? 'pending';

    if ($complaintId && $userId && $details !== '' && is_numeric($fee)) {
        if (updateComplaint($pdo, $complaintId, $details, $fee, $status, $db_complaints_table, $db_complaint_details_col, $db_complaint_fee_col, $db_complaint_status_col, $db_complaint_id_col, $db_complaint_updated_at_col)) {
            $userEmail = getUserEmail($pdo, $userId, $db_users_table, $db_user_id_col, $db_user_email_col);
            if ($userEmail) {
                $subject = "Complaint Updated - ID: " . htmlspecialchars($complaintId);
                $messageBody = "<p>Your complaint (ID: " . htmlspecialchars($complaintId) . ") has been updated with the following details:</p>";
                $messageBody .= "<p><strong>Details:</strong> " . htmlspecialchars($details) . "</p>";
                $messageBody .= "<p><strong>Fee:</strong> ₱" . htmlspecialchars(number_format($fee, 2)) . "</p>";
                $messageBody .= "<p><strong>Status:</strong> " . htmlspecialchars(ucfirst($status)) . "</p>";
                $messageBody .= "<p>Please log in to your account for more information.</p>";
                sendEmail($userEmail, $subject, $messageBody);
            }
            logSystemAction($pdo, $_SESSION['user_id'], "Updated complaint ID: $complaintId for User ID: $userId", $db_system_logs_table, $db_system_log_user_id_col, $db_system_log_action_col);
            $_SESSION['success_message'] = 'Complaint updated successfully.';
        } else {
            $_SESSION['error_message'] = 'Failed to update complaint.';
        }
    } else {
        $_SESSION['error_message'] = 'Invalid input for updating complaint.';
    }
    header("Location: AdminComplaints.php");
    exit();
}

// Fetch all users for the initial table display
$search_term = isset($_GET['search']) ? $_GET['search'] : '';
$users = fetchAllUsers($pdo, $db_users_table, $db_profiles_table, $db_user_id_col, $db_user_email_col, $db_user_role_col, $db_profile_first_name_col, $db_profile_middle_name_col, $db_profile_last_name_col, $db_profile_suffix_col, $db_profile_address_col);

function filterUsers($users, $searchTerm, $db_profile_first_name_col, $db_profile_middle_name_col, $db_profile_last_name_col) {
    $filteredUsers = [];
    $searchTerm = strtoupper($searchTerm);
    foreach ($users as $user) {
        $fullName = strtoupper($user['first_name'] . ' ' . ($user['middle_name'] ? $user['middle_name'] . ' ' : '') . $user['last_name'] . ($user['suffix'] ? ' ' . $user['suffix'] : ''));
        if (strpos(strtoupper($user['user_id']), $searchTerm) !== false || strpos($fullName, $searchTerm) !== false) {
            $filteredUsers[] = $user;
        }
    }
    return $filteredUsers;
}

$users = filterUsers($users, $search_term, $db_profile_first_name_col, $db_profile_middle_name_col, $db_profile_last_name_col);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complaints Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet"/>
    <style>
         
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
        body {
        font-family: 'Roboto', sans-serif;
        display: flex; /* Enable flexbox for the main layout */
        min-height: 100vh; /* Ensure body takes at least the full viewport height */
        overflow:auto; /* Prevent horizontal scrollbar */
    }

/* Sidebar styles */
.sidebar {
    width: 240px; /* Fixed width for the sidebar */
    flex-shrink: 0; /* Prevent sidebar from shrinking */
    position: fixed; /* Keep sidebar fixed */
    top: 0;
    left: 0;
    bottom: 0;
    z-index: 10;
    background-color: #4A5568; /* Sidebar background color */
    transform: translateX(-100%); /* Initially hide the sidebar */
    transition: transform 0.3s ease-in-out; /* Smooth transition */
}

/* Main content styles */
.main-content {
    flex-grow: 1; /* Allow main content to take remaining space */
    padding: 1rem; /* Adjust padding as needed */
    transition: margin-left 0.3s ease-in-out; /* Smooth transition for margin */
    margin-left: 0; /* Initial margin-left */
    overflow-x: auto; /* Enable horizontal scrolling for the main content */
}

/* When sidebar is open */
.sidebar.open {
    transform: translateX(0); /* Show the sidebar */
}

/* When sidebar is open, push the main content */
body.sidebar-open .main-content {
    margin-left: 240px; /* Push content to the right */
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .sidebar {
        width: 200px; /* Adjust width for smaller screens */
    }

    body.sidebar-open .main-content {
        margin-left: 200px; /* Adjust margin-left to match sidebar width when open */
    }
}
        .modal {
            display: none;
            position: fixed;
            z-index:40;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.4);
        }
        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 95%;
            max-width: 900px;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1),
                0 2px 4px -1px rgba(0, 0, 0, 0.06);
            position: relative;
        }
        .modal-title {
            font-size: 1.5rem;
            font-weight: bold;
            margin-bottom: 1rem;
            color: #374151;
        }
        .modal-close-button {
            color: #aaa;
            position: absolute;
            top: 0.5rem;
            right: 1rem;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .modal-close-button:hover,
        .modal-close-button:focus {
            color: black;
            text-decoration: none;
        }
        .form-group {
            margin-bottom: 1rem;
        }
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #374151;
        }
        .form-group input[type="text"],
        .form-group textarea,
        .form-group select,
        .form-group input[type="number"] {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 0.375rem;
            box-sizing: border-box;
        }
        .form-group textarea {
            resize: vertical;
        }
        .form-actions button {
            margin-right: 0.5rem;
        }
        .btn-primary {
            background-color: #647dee;
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 0.375rem;
            cursor: pointer;
            transition: background-color 0.2s ease-in-out;
        }
        .btn-primary:hover {
            background-color: #5a6ee0;
        }
        .btn-secondary {
            background-color: #e2e8f0;
            color: #4a5568;
            padding: 0.75rem 1.5rem;
            border-radius: 0.375rem;
            cursor: pointer;
            transition: background-color 0.2s ease-in-out;
        }
        .btn-secondary:hover {
            background-color: #cbd5e0;
        }
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            padding: 0.75rem 1.25rem;
            margin-bottom: 1rem;
            border-radius: 0.375rem;
        }
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            padding: 0.75rem 1.25rem;
            margin-bottom: 1rem;
            border-radius: 0.375rem;
        }
        .table-container {
            overflow-x: auto;
            margin-top: 1rem;
        }
        #usersTableBodyContainer {
            max-height: 400px;
            overflow-y: auto;
        }
       
        #complaintsModalTable {
            width: 100%;
            border-collapse: collapse;
        }
        #complaintsModalTable th,
        #complaintsModalTable td {
            padding: 0.5rem;
            border-bottom: 1px solid #ddd;
            text-align: left;
        }
        #complaintsModalTable th {
            background-color: #f7f7f7;
            position: sticky;
            top: 0;
            z-index: 1;
        }
        .edit-complaint-form {
            margin-top: 1rem;
            padding: 1rem;
            border: 1px solid #ddd;
            border-radius: 0.375rem;
            background-color: #f9f9f9;
        }
        .edit-complaint-form .form-group {
            margin-bottom: 0.75rem;
        }
        .edit-complaint-form label {
            display: block;
            margin-bottom: 0.25rem;
            font-size: 0.875rem;
            color: #4a5568;
        }
        .edit-complaint-form input[type="text"],
        .edit-complaint-form textarea,
        .edit-complaint-form select,
        .edit-complaint-form input[type="number"] {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #d1d5db;
            border-radius: 0.375rem;
            box-sizing: border-box;
            font-size: 0.875rem;
        }
        .edit-complaint-form .form-actions {
            margin-top: 0.5rem;
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
        }
        /* Table Styling */
.responsive-table {
    width: 100%;
    border-collapse: collapse;
    overflow-x: auto; /* Enable horizontal scrolling for small screens */
}

.responsive-table th,
.responsive-table td {
    border: 1px solid #ddd;
    padding: 8px;
    text-align: left;
    white-space: nowrap; /* Prevent text wrapping in cells */
}

.responsive-table th {
    background-color: #f2f2f2;
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
    <main class="main-content flex-grow p-8 ml-0" id="main-content">
        <div class="flex justify-between items-center mb-8">
            <h2 class="text-3xl font-bold">Complaints</h2>
            <button class="text-purple-500 focus:outline-none ml-2" onclick="toggleSidebar()">
                    <i class="fas fa-bars text-2xl"></i>
                </button>
        </div>
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert-success"><?php echo $_SESSION['success_message']; ?></div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert-error"><?php echo $_SESSION['error_message']; ?></div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>
        <div class="search-filter flex items-center mb-4">
            <form action="" method="GET" class="flex items-center">
                <input
                    type="text"
                    name="search"
                    value="<?php echo htmlspecialchars($search_term); ?>"
                    placeholder="User ID or Name"
                    class="shadow appearance-none border rounded py-2 px-10  text-gray-700 leading-tight focus:outline-none focus:shadow-outline"
                />
                <button type="submit" class="text-purple-500 focus:outline-none ml-2">
                    <i class="fas fa-search mr-2"></i>
                </button>
            </form>
        </div>
        <div class="table-container rounded-md shadow-md">
         <table class="responsive-table min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User ID</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Address</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Complaints</th>
                        <th scope="col" class="relative px-6 py-3">
                            <span class="sr-only">Add Complaint</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200" id="usersTableBodyContainer">
                    <?php foreach ($users as $user) : ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($user['user_id']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo htmlspecialchars($user['first_name'] . ' ' . ($user['middle_name'] ? $user['middle_name'] . ' ' : '') . $user['last_name'] . ($user['suffix'] ? ' ' . $user['suffix'] : '')); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($user['email']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($user['role']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($user['address']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <button onclick="openComplaintsModal(<?php echo htmlspecialchars($user['user_id']); ?>)" class="text-indigo-600 hover:text-indigo-900">
                                    View Complaints
                                </button>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <button onclick="openAddComplaintModal(<?php echo htmlspecialchars($user['user_id']); ?>)" class="bg-green-500 hover:bg-green-700 text-white font-bold py-2 px-4 rounded">
                                    Add Complaint
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div id="complaintsModal" class="modal">
    <div class="modal-content">
            <span class="modal-close-button">&times;</span>
            <h2 class="modal-title">Complaints for User ID: <span id="complaintsModalUserId"></span></h2>
            <div id="complaintsModalTableContainer" class="table-container rounded-md shadow-md overflow-hidden mt-4">
                <table id="complaintsModalTable" class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Complaint ID</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Details</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fee</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created At</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Updated At</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200" id="complaintsModalTbody">
                    </tbody>
                </table>
            </div>
            <div id="editComplaintFormContainer" class="edit-complaint-form" style="display: none;">
                <h3 class="text-lg font-semibold mb-2">Edit Complaint</h3>
                <form id="editComplaintForm" method="POST">
                    <input type="hidden" name="complaint_id" id="edit_complaint_id">
                    <input type="hidden" name="user_id" id="edit_user_id">
                    <div class="form-group">
                        <label for="edit_complaint_details">Details</label>
                        <textarea name="complaint_details" id="edit_complaint_details" rows="3" required></textarea>
                    </div>
                    <div class="form-group">
                        <label for="edit_fee">Fee</label>
                        <input type="number" name="fee" id="edit_fee" value="0" min="0" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_status">Status</label>
                        <select name="status" id="edit_status">
                            <option value="pending">Pending</option>
                            <option value="resolved">Resolved</option>
                            <option value="dismissed">Dismissed</option>
                        </select>
                    </div>
                    <div class="form-actions">
                        <button type="submit" name="edit_complaint" class="btn-primary">Update</button>
                        <button type="button" class="btn-secondary cancel-edit-btn">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div id="addComplaintModal" class="modal">
        <div class="modal-content">
            <span class="modal-close-button">&times;</span>
            <h2 class="modal-title">Add New Complaint</h2>
            <form id="addComplaintForm" method="POST">
                <input type="hidden" name="user_id" id="add_user_id">
                <div class="form-group">
                    <label for="complaint_details">Details</label>
                    <textarea name="complaint_details" id="add_complaint_details" rows="3" required></textarea>
                </div>
                <div class="form-group">
                    <label for="fee">Fee</label>
                    <input type="number" name="fee" id="add_fee" value="0" min="0" required>
                </div>
                <div class="form-group">
                    <label for="status">Status</label>
                    <select name="status" id="add_status">
                        <option value="pending">Pending</option>
                        <option value="in-progress">In Progress</option>
                        <option value="resolved">Resolved</option>
                        <option value="dismissed">Dismissed</option>
                    </select>
                </div>
                <div class="form-actions">
                    <button type="submit" name="add_complaint" class="btn-primary">Add Complaint</button>
                    <button type="button" class="btn-secondary close-add-modal-btn">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        const complaintsModal = document.getElementById("complaintsModal");
        const complaintsModalClose = document.querySelector("#complaintsModal .modal-close-button");
        const complaintsModalUserIdDisplay = document.getElementById("complaintsModalUserId");
        const complaintsModalTbody = document.getElementById("complaintsModalTbody");
        const addComplaintModal = document.getElementById("addComplaintModal");
        const addComplaintModalClose = document.querySelector("#addComplaintModal .modal-close-button");
        const addComplaintForm = document.getElementById("addComplaintForm");
        const addUserIdInput = document.getElementById("add_user_id");
        const editComplaintFormContainer = document.getElementById("editComplaintFormContainer");
        const editComplaintForm = document.getElementById("editComplaintForm");
        const cancelEditBtn = document.querySelector(".cancel-edit-btn");
        const closeAddModalBtn = document.querySelector(".close-add-modal-btn");


        // Function to toggle the sidebar
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('main-content');
    sidebar.classList.toggle('open'); // Toggle sidebar visibility
    document.body.classList.toggle('sidebar-open'); // Toggle body class to push main content
}



        function toggleDropdown() {
            const dropdown = document.getElementById('dropdown');
            dropdown.classList.toggle('hidden');
        }

        function openComplaintsModal(userId) {
            complaintsModal.style.display = "block";
            complaintsModalUserIdDisplay.textContent = userId;
            fetchComplaints(userId);
        }

        function closeComplaintsModal() {
            complaintsModal.style.display = "none";
            complaintsModalTbody.innerHTML = "";
            editComplaintFormContainer.style.display = "none";
        }

        function openAddComplaintModal(userId) {
            addComplaintModal.style.display = "block";
            addUserIdInput.value = userId;
            addComplaintForm.reset();
        }

        function closeAddComplaintModal() {
            addComplaintModal.style.display = "none";
            addComplaintForm.reset();
        }

        complaintsModalClose.addEventListener("click", closeComplaintsModal);
        addComplaintModalClose.addEventListener("click", closeAddComplaintModal);


        window.addEventListener("click", (event) => {
            if (event.target === complaintsModal) {
                closeComplaintsModal();
            }
            if (event.target === addComplaintModal) {
                closeAddComplaintModal();
            }
        });

        function fetchComplaints(userId) {
            fetch(`AdminComplaints.php?user_id=${userId}`)
                .then((response) => response.json())
                .then((data) => {
                    if (data && data.length > 0) {
                        complaintsModalTbody.innerHTML = "";
                        data.forEach((complaint) => {
                            const row = document.createElement("tr");
                            row.innerHTML = `
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${complaint.complaint_id}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">${complaint.complaint_details}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">₱${complaint.fee}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${complaint.status}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${complaint.created_at}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${complaint.updated_at}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <button data-complaint-id="${complaint.complaint_id}"
                                            data-user-id="${userId}"
                                            data-complaint-details="${complaint.complaint_details.replace(/"/g, '&quot;')}"
                                            data-fee="${complaint.fee}"
                                            data-status="${complaint.status}"
                                            class="edit-complaint-btn bg-yellow-500 hover:bg-yellow-700 text-white font-bold py-2 px-4 rounded mr-2">
                                        Edit
                                    </button>
                                </td>
                            `;
                            complaintsModalTbody.appendChild(row);
                        });

                        const editButtons = document.querySelectorAll(".edit-complaint-btn");
                        editButtons.forEach((button) => {
                            button.addEventListener("click", () => {
                                const complaintId = button.dataset.complaintId;
                                const userId = button.dataset.userId;
                                const details = button.dataset.complaintDetails;
                                const fee = button.dataset.fee;
                                const status = button.dataset.status;

                                editComplaintFormContainer.style.display = "block";
                                editComplaintForm.querySelector("#edit_complaint_id").value = complaintId;
                                editComplaintForm.querySelector("#edit_user_id").value = userId;
                                editComplaintForm.querySelector("#edit_complaint_details").value = details;
                                editComplaintForm.querySelector("#edit_fee").value = fee;
                                editComplaintForm.querySelector("#edit_status").value = status;
                            });
                        });

                        cancelEditBtn.addEventListener("click", () => {
                            editComplaintFormContainer.style.display = "none";
                        });
                    } else {
                        complaintsModalTbody.innerHTML = "<tr><td colspan='7' class='px-6 py-4 whitespace-nowrap text-sm text-gray-500'>No complaints found for this user.</td></tr>";
                    }
                })
                .catch((error) => {
                    console.error("Error fetching complaints:", error);
                    complaintsModalTbody.innerHTML = "<tr><td colspan='7' class='px-6 py-4 whitespace-nowrap text-sm text-gray-500'>Failed to fetch complaints.</td></tr>";
                });
        }
    </script>
</body>
</html>