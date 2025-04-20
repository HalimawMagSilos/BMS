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



// Function to fetch users with profiles, ID documents, barangay official details, AND PASSWORD
function fetchUsers($pdo) {
    $sql = "SELECT
                u.user_id, u.email, u.role, u.is_verified,u.password,
                p.first_name, p.last_name, p.middle_name, p.suffix, p.address, p.contact_number, p.birthday, p.profile_picture,
                MAX(it.id_type_name) AS id_type_name, MAX(uid.document_file) AS document_file,
                MAX(bo.position) AS position, MAX(d.department_name) AS department_name
            FROM Users u
            LEFT JOIN Profiles p ON u.user_id = p.user_id
            LEFT JOIN User_ID_Documents uid ON u.user_id = uid.user_id
            LEFT JOIN ID_Types it ON uid.id_type_id = it.id_type_id
            LEFT JOIN Barangay_Officials bo ON u.user_id = bo.user_id
            LEFT JOIN Departments d ON bo.department_id = d.department_id
            GROUP BY u.user_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to fetch ID types
function fetchIDTypes($pdo) {
    $sql = "SELECT id_type_id, id_type_name FROM ID_Types";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to fetch departments
function fetchDepartments($pdo) {
    $sql = "SELECT department_id, department_name FROM Departments";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to log actions
function logAction($pdo, $user_id, $action) {
    $sql = "INSERT INTO System_Logs (user_id, action) VALUES (:user_id, :action)";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->bindParam(':action', $action);
    $stmt->execute();
}

// Function to add a new user
function addUser($pdo, $email, $password, $first_name, $last_name, $middle_name, $suffix, $address, $contact_number, $department_id, $position, $role, $is_verified, $id_type_id, $document_file, $birthday, $profile_picture) {
    try {
        $pdo->beginTransaction();

        $sql = "INSERT INTO Users (email, password, role, is_verified) VALUES (:email, :password, :role, :is_verified)";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':password', password_hash($password, PASSWORD_DEFAULT));
        $stmt->bindParam(':role', $role);
        $stmt->bindParam(':is_verified', $is_verified);
        $stmt->execute();
        $user_id = $pdo->lastInsertId();

        $sql = "INSERT INTO Profiles (user_id, first_name, last_name, middle_name, suffix, address, contact_number, birthday, profile_picture) VALUES (:user_id, :first_name, :last_name, :middle_name, :suffix, :address, :contact_number, :birthday, :profile_picture)";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':first_name', $first_name);
        $stmt->bindParam(':last_name', $last_name);
        $stmt->bindParam(':middle_name', $middle_name);
        $stmt->bindParam(':suffix', $suffix);
        $stmt->bindParam(':address', $address);
        $stmt->bindParam(':contact_number', $contact_number);
        $stmt->bindParam(':birthday', $birthday);
        $stmt->bindParam(':profile_picture', $profile_picture);
        $stmt->execute();

        if ($department_id && $role === 'barangay_official') {
            $sql = "INSERT INTO Barangay_Officials (user_id, department_id, position) VALUES (:user_id, :department_id, :position)";
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->bindParam(':department_id', $department_id);
            $stmt->bindParam(':position', $position);
            $stmt->execute();
        }

        if ($id_type_id && $document_file) {
            $sql = "INSERT INTO User_ID_Documents (user_id, id_type_id, document_file) VALUES (:user_id, :id_type_id, :document_file)";
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->bindParam(':id_type_id', $id_type_id);
            $stmt->bindParam(':document_file', $document_file);
            $stmt->execute();
        }

        logAction($pdo, $_SESSION['user_id'], "Added user with ID: $user_id");
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "Error: " . $e->getMessage();
    }
}

// Handle form submissions
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['add_user'])) {
        $email = $_POST['email'];
        $password = $_POST['password'];
        $first_name = $_POST['first_name'];
        $last_name = $_POST['last_name'];
        $middle_name = $_POST['middle_name'] ?? null;
        $suffix = $_POST['suffix'] ?? null;
        $address = $_POST['address'] ?? null;
        $contact_number = $_POST['contact_number'] ?? null;
        $department_id = $_POST['department_id'] ?? null;
        $position = $_POST['position'] ?? null;
        $role = $_POST['role'];
        $is_verified = isset($_POST['is_verified']) ? 1 : 0;
        $id_type_id = $_POST['id_type_id'] ?? null;
        $birthday = $_POST['birthday'] ?? null;
        $document_file = $_FILES['document_file']['name'] ?? null;

        $profile_picture = null;
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == 0) {
            $target_dir = "IMAGE/";
            $target_file = $target_dir . basename($_FILES['profile_picture']['name']);
            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $target_file)) {
                $profile_picture = $target_file;
            } else {
                echo "Error uploading profile picture.";
            }
        }

        if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] == 0) {
            $document_target_dir = "FILES/";
            $document_target_file = $document_target_dir . basename($_FILES['document_file']['name']);
            if (move_uploaded_file($_FILES['document_file']['tmp_name'], $document_target_file)) {
                $document_file = $document_target_file;
            } else {
                echo "Error uploading document file.";
            }
        }

        addUser($pdo, $email, $password, $first_name, $last_name, $middle_name, $suffix, $address, $contact_number, $department_id, $position, $role, $is_verified, $id_type_id, $document_file, $birthday, $profile_picture);
        header("Location: AdminManageUser.php");
        exit();
    }
    if (isset($_POST['verify_user'])) {
        $userId = $_POST['user_id'];
        $sql = "UPDATE Users SET is_verified = 1 WHERE user_id = :user_id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        logAction($pdo, $_SESSION['user_id'], "Verified user with ID: $userId");
        header("Location: AdminManageUser.php");
        exit();
    }
    if (isset($_POST['delete_user'])) {
        $userId = $_POST['user_id'];
        $sql = "DELETE FROM Users WHERE user_id = :user_id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        logAction($pdo, $_SESSION['user_id'], "Deleted user with ID: $userId");
        header("Location: AdminManageUser.php");
        exit();
    }
}

if (isset($_POST['edit_user'])) {
    $user_id = $_POST['user_id'];
    $email = $_POST['email'];
    $password = $_POST['edit_password'];
    $role = $_POST['role'];
    $is_verified = isset($_POST['is_verified']) ? 1 : 0;

    $first_name = $_POST['first_name'];
    $last_name = $_POST['last_name'];
    $middle_name = $_POST['middle_name'];
    $suffix = $_POST['suffix'];
    $address = $_POST['address'];
    $contact_number = $_POST['contact_number'];
    $birthday = $_POST['birthday'];
    $id_type_id = $_POST['id_type_id'];

    $department_id = !empty($_POST['department_id']) ? $_POST['department_id'] : null;
    $position = !empty($_POST['position']) ? $_POST['position'] : null;

    $profile_path = null;
    if (!empty($_FILES['profile_picture']['name'])) {
        $profile_name = basename($_FILES['profile_picture']['name']);
        $profile_path = 'IMAGE/' . $profile_name;
        move_uploaded_file($_FILES['profile_picture']['tmp_name'], $profile_path);
    }

    $document_path = null;
    if (!empty($_FILES['document_file']['name'])) {
        $document_name = basename($_FILES['document_file']['name']);
        $document_path = 'FILES/' . $document_name;
        move_uploaded_file($_FILES['document_file']['tmp_name'], $document_path);
    }

    try {
        $pdo->beginTransaction();

        // Update Users table
        $user_sql = "UPDATE Users SET email = :email, role = :role, is_verified = :is_verified";
        if (!empty($password)) {
            $user_sql .= ", password = :password";
        }
        $user_sql .= " WHERE user_id = :user_id";
        $stmt = $pdo->prepare($user_sql);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':role', $role);
        $stmt->bindParam(':is_verified', $is_verified);
        if (!empty($password)) {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt->bindParam(':password', $hashed_password);
        }
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();

        // Update Profiles table
        $profile_sql = "UPDATE Profiles SET first_name = :first_name, last_name = :last_name, middle_name = :middle_name, suffix = :suffix,
                        address = :address, contact_number = :contact_number, birthday = :birthday";
        if ($profile_path) {
            $profile_sql .= ", profile_picture = :profile_picture";
        }
        $profile_sql .= " WHERE user_id = :user_id";
        $stmt = $pdo->prepare($profile_sql);
        $stmt->bindParam(':first_name', $first_name);
        $stmt->bindParam(':last_name', $last_name);
        $stmt->bindParam(':middle_name', $middle_name);
        $stmt->bindParam(':suffix', $suffix);
        $stmt->bindParam(':address', $address);
        $stmt->bindParam(':contact_number', $contact_number);
        $stmt->bindParam(':birthday', $birthday);
        if ($profile_path) {
            $stmt->bindParam(':profile_picture', $profile_path);
        }
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();

        // Update or Insert User_ID_Documents
        if ($document_path && $id_type_id) {
            $doc_check = $pdo->prepare("SELECT * FROM User_ID_Documents WHERE user_id = ?");
            $doc_check->execute([$user_id]);
            if ($doc_check->rowCount() > 0) {
                $sql = "UPDATE User_ID_Documents SET id_type_id = :id_type_id, document_file = :document_file WHERE user_id = :user_id";
            } else {
                $sql = "INSERT INTO User_ID_Documents (user_id, id_type_id, document_file) VALUES (:user_id, :id_type_id, :document_file)";
            }
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->bindParam(':id_type_id', $id_type_id);
            $stmt->bindParam(':document_file', $document_path);
            $stmt->execute();
        }

        // Update or Insert Barangay_Officials
        if ($role === 'barangay_official') {
            $bo_check = $pdo->prepare("SELECT * FROM Barangay_Officials WHERE user_id = ?");
            $bo_check->execute([$user_id]);
            if ($bo_check->rowCount() > 0) {
                $sql = "UPDATE Barangay_Officials SET department_id = :department_id, position = :position WHERE user_id = :user_id";
            } else {
                $sql = "INSERT INTO Barangay_Officials (user_id, department_id, position) VALUES (:user_id, :department_id, :position)";
            }
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->bindParam(':department_id', $department_id);
            $stmt->bindParam(':position', $position);
            $stmt->execute();
        } else {
            // If user is no longer a barangay official, remove record if exists
            $stmt = $pdo->prepare("DELETE FROM Barangay_Officials WHERE user_id = ?");
            $stmt->execute([$user_id]);
        }

        logAction($pdo, $_SESSION['user_id'], "Updated user with ID: $user_id");
        $pdo->commit();
        $_SESSION['success'] = "User updated successfully!";
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Update failed: " . $e->getMessage();
    }

    header("Location: AdminManageUser.php");
    exit();
}

// Fetch users, ID types, and departments
$users = fetchUsers($pdo);
$departments = fetchDepartments($pdo);
$id_types = fetchIDTypes($pdo);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Manage Users</title>
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
        .table-container {
            overflow-x: auto; /* Enable horizontal scrolling */
        }
        .table-container table {
            min-width: 100%; /* Ensure table takes full width */
        }
        .table-body-overflow {
            max-height: 400px; /* Adjust as needed */
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

    <main class="main-content flex-grow p-8 ml-0" id="main-content">
        <div class="flex justify-between items-center mb-8">
            <h2 class="text-3xl font-bold">Manage Users</h2>
            <div class="flex items-center">
                <input class="shadow appearance-none border rounded py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="search" placeholder="Search" type="text"/>
                <button class="text-purple-500 focus:outline-none ml-2" onclick="searchResidents()">
                    <i class="fas fa-search text-2xl"></i>
                </button>
                <button class="text-purple-500 focus:outline-none ml-2" onclick="toggleSidebar()">
                    <i class="fas fa-bars text-2xl"></i>
                </button>
            </div>
        </div>
        <div class="bg-white p-8 rounded-lg shadow-md mb-8 table-container">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold">Residents</h2>
                <button class="bg-purple-500 text-white px-4 py-2 rounded" onclick="openModal()">Add User</button>
            </div>
            <h3 class="text-xl font-bold mb-4">Verified Residents</h3>
            <div class="max-h-[400px] overflow-y-auto border rounded-md">
                <table class="min-w-full bg-white mb-8">
                    <thead>
                        <tr>
                            <th class="py-2 px-4 border-b-2 border-gray-200 bg-gray-100 text-left text-sm font-semibold text-gray-600 uppercase tracking-wider">Profile Picture</th>
                            <th class="py-2 px-4 border-b-2 border-gray-200 bg-gray-100 text-left text-sm font-semibold text-gray-600 uppercase tracking-wider">Name</th>
                            <th class="py-2 px-4 border-b-2 border-gray-200 bg-gray-100 text-left text-sm font-semibold text-gray-600 uppercase tracking-wider">Email</th>
                            <th class="py-2 px-4 border-b-2 border-gray-200 bg-gray-100 text-left text-sm font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="verified-residents-list">
                        <?php foreach ($users as $user): ?>
                            <?php if ($user['is_verified']): ?>
                                <tr>
                                    <td class="py-2 px-4 border-b border-gray-200">
                                        <?php if ($user['profile_picture']): ?>
                                            <img src="<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="Profile Picture" class="w-10 h-10 rounded-full">
                                        <?php else: ?>
                                            <span class="text-gray-500">No Image</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-2 px-4 border-b border-gray-200"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></td>
                                    <td class="py-2 px-4 border-b border-gray-200"><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td class="py-2 px-4 border-b border-gray-200">
                                        <button class="text-blue-500 mr-2" onclick="viewUser(<?php echo $user['user_id']; ?>)">View</button>
                                        <button class="text-blue-500 mr-2" onclick="openEditModal(<?php echo $user['user_id']; ?>)">Edit</button>
                                        <button type="button" class="text-red-500" onclick="showConfirmDeleteModal(<?php echo $user['user_id']; ?>)">Delete</button>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <h3 class="text-xl font-bold mb-4">Non-Verified Residents</h3>
            <div class="max-h-[400px] overflow-y-auto border rounded-md mb-8">
                <table class="min-w-full bg-white">
                    <thead class="sticky top-0 bg-gray-100 z-10">
                        <tr>
                            <th class="py-2 px-4 border-b-2 border-gray-200 text-left text-sm font-semibold text-gray-600 uppercase tracking-wider">Profile Picture</th>
                            <th class="py-2 px-4 border-b-2 border-gray-200 text-left text-sm font-semibold text-gray-600 uppercase tracking-wider">Name</th>
                            <th class="py-2 px-4 border-b-2 border-gray-200 text-left text-sm font-semibold text-gray-600 uppercase tracking-wider">Email</th>
                            <th class="py-2 px-4 border-b-2 border-gray-200 text-left text-sm font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="non-verified-residents-list">
                        <?php foreach ($users as $user): ?>
                            <?php if (!$user['is_verified']): ?>
                                <tr>
                                    <td class="py-2 px-4 border-b border-gray-200">
                                        <?php if ($user['profile_picture']): ?>
                                            <img src="<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="Profile Picture" class="w-10 h-10 rounded-full">
                                        <?php else: ?>
                                            <span class="text-gray-500">No Image</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-2 px-4 border-b border-gray-200"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></td>
                                    <td class="py-2 px-4 border-b border-gray-200"><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td class="py-2 px-4 border-b border-gray-200">
                                        <button class="text-blue-500 mr-2" onclick="viewUser(<?php echo $user['user_id']; ?>)">View</button>
                                        <button class="text-blue-500 mr-2" onclick="openEditModal(<?php echo $user['user_id']; ?>)">Edit</button>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                            <button type="submit" name="verify_user" class="text-green-500">Verify</button>
                                        </form>
                                        <button type="button" class="text-red-500" onclick="showConfirmDeleteModal(<?php echo $user['user_id']; ?>)">Delete</button>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </main>

    <div class="fixed z-10 inset-0 overflow-y-auto hidden" id="modal">
        <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 transition-opacity" aria-hidden="true">
                <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
            </div>
            <span aria-hidden="true" class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
            <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                    <div class="sm:flex sm:items-start">
                        <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                            <h3 class="text-lg leading-6 font-medium text-gray-900" id="modal-title">Add User</h3>
                            <div class="mt-2">
                                <form id="modal-form" method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="add_user" value="1">
                                    <div class="mb-4">
                                        <label class="block text-gray-700 text-sm font-bold mb-2" for="role">Role</label>
                                        <select class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="role" name="role" onchange="toggleRoleFields()">
                                            <option value="">Select Role</option>
                                            <option value="resident">Resident</option>
                                            <option value="barangay_official">Barangay Official</option>
                                            <option value="admin">Admin</option>
                                        </select>
                                    </div>
                                    <div class="mb-4">
                                        <label class="block text-gray-700 text-sm font-bold mb-2" for="email">Email</label>
                                        <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="email" name="email" type="email" required>
                                    </div>
                                    <div class="mb-4">
                                        <label class="block text-gray-700 text-sm font-bold mb-2" for="password">Password</label>
                                        <div class="relative">
                                            <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="password" name="password" type="password" required>
                                            <span class="absolute inset-y-0 right-0 flex items-center pr-3 cursor-pointer" onclick="togglePasswordVisibility()">
                                                <i id="password-eye" class="fas fa-eye"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="mb-4">
                                        <label class="block text-gray-700 text-sm font-bold mb-2" for="first_name">First Name</label>
                                        <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="first_name" name="first_name" type="text" required>
                                    </div>
                                    <div class="mb-4">
                                        <label class="block text-gray-700 text-sm font-bold mb-2" for="last_name">Last Name</label>
                                        <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="last_name" name="last_name" type="text" required>
                                    </div>
                                    <div class="mb-4">
                                        <label class="block text-gray-700 text-sm font-bold mb-2" for="middle_name">Middle Name</label>
                                        <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="middle_name" name="middle_name" type="text">
                                    </div>
                                    <div class="mb-4">
                                        <label class="block text-gray-700 text-sm font-bold mb-2" for="suffix">Suffix</label>
                                        <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="suffix" name="suffix" type="text">
                                    </div>
                                    <div class="mb-4">
                                        <label class="block text-gray-700 text-sm font-bold mb-2" for="address">Address</label>
                                        <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="address" name="address" type="text">
                                    </div>
                                    <div class="mb-4">
                                        <label class="block text-gray-700 text-sm font-bold mb-2" for="contact_number">Contact Number</label>
                                        <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="contact_number" name="contact_number" type="text">
                                    </div>
                                    <div class="mb-4">
                                        <label class="block text-gray-700 text-sm font-bold mb-2" for="birthday">Birthday</label>
                                        <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="birthday" name="birthday" type="date">
                                    </div>
                                    <div class="mb-4">
                                        <label class="block text-gray-700 text-sm font-bold mb-2" for="id_type_id">Select ID Type</label>
                                        <select class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="id_type_id" name="id_type_id" required>
                                            <option value="">Select ID Type</option>
                                            <?php foreach ($id_types as $id_type): ?>
                                                <option value="<?php echo $id_type['id_type_id']; ?>"><?php echo htmlspecialchars($id_type['id_type_name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="mb-4">
                                        <label class="block text-gray-700 text-sm font-bold mb-2" for="document_file">Document File</label>
                                        <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="document_file" name="document_file" type="file" required>
                                    </div>
                                    <div class="mb-4">
                                        <label class="block text-gray-700 text-sm font-bold mb-2" for="profile_picture">Profile Picture</label>
                                        <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="profile_picture" name="profile_picture" type="file">
                                    </div>
                                    <div class="mb-4">
                                        <label class="block text-gray-700 text-sm font-bold mb-2" for="is_verified">Is Verified</label>
                                        <input type="checkbox" id="is_verified" name="is_verified" value="1">
                                    </div>
                                    <div id="barangay-official-fields" class="hidden">
                                        <div class="mb-4">
                                            <label class="block text-gray-700 text-sm font-bold mb-2" for="department_id">Department</label>
                                            <select class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="department_id" name="department_id">
                                                <option value="">Select Department</option>
                                                <?php foreach ($departments as $department): ?>
                                                    <option value="<?php echo $department['department_id']; ?>"><?php echo htmlspecialchars($department['department_name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mb-4">
                                            <label class="block text-gray-700 text-sm font-bold mb-2" for="position">Position</label>
                                            <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="position" name="position" type="text" value="Staff">
                                        </div>
                                    </div>
                                    <div class="flex justify-end">
                                        <button type="button" class="bg-gray-300 text-gray-700 px-4 py-2 rounded mr-2" onclick="closeModal()">Cancel</button>
                                        <button type="submit" class="bg-purple-500 text-white px-4 py-2 rounded">Add User</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="fixed z-10 inset-0 overflow-y-auto hidden" id="viewModal">
        <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 transition-opacity" aria-hidden="true">
                <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
            </div>
            <span aria-hidden="true" class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
            <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                    <div class="sm:flex sm:items-start">
                        <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                            <h3 class="text-lg leading-6 font-medium text-gray-900" id="viewModal-title">User Details</h3>
                            <div class="mt-2" id="viewModal-content">
                                <div class="grid grid-cols-1 gap-4">
                                    <div class="bg-gray-100 p-4 rounded-lg shadow-md">
                                        <h4 class="text-lg font-bold mb-2">Personal Information</h4>
                                        <p><strong>Email:</strong> <span id="view_email"></span></p>
                                        <p><strong>First Name:</strong> <span id="view_first_name"></span></p>
                                        <p><strong>Last Name:</strong> <span id="view_last_name"></span></p>
                                        <p><strong>Middle Name:</strong> <span id="view_middle_name"></span></p>
                                        <p><strong>Suffix:</strong> <span id="view_suffix"></span></p>
                                    </div>
                                    <div class="bg-gray-100 p-4 rounded-lg shadow-md">
                                        <h4 class="text-lg font-bold mb-2">Contact Information</h4>
                                        <p><strong>Address:</strong> <span id="view_address"></span></p>
                                        <p><strong>Contact Number:</strong> <span id="view_contact_number"></span></p>
                                    </div>
                                    <div class="mb-4">
                                        <label class="block text-gray-700 text-sm font-bold mb-2" for="view_password">Password</label>
                                        <div class="relative">
                                            <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="view_password" name="view_password" type="password" required>
                                            <span class="absolute inset-y-0 right-0 flex items-center pr-3 cursor-pointer" onclick="togglePasswordVisibility2()">
                                                <i id="password-eye2" class="fas fa-eye"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="bg-gray-100 p-4 rounded-lg shadow-md">
                                    <h4 class="text-lg font-bold mb-2">Additional Information</h4>
                                        <p><strong>Birthday:</strong> <span id="view_birthday"></span></p>
                                        <p><strong>Role:</strong> <span id="view_role"></span></p>
                                        <p><strong>Is Verified:</strong> <span id="view_is_verified"></span></p>
                                        <p><strong>ID Type:</strong> <span id="view_id_type_name"></span></p>
                                        <p><strong>Document File:</strong> <a id="view_document_file" href="#" target="_blank">View Document</a></p>
                                        <p><strong>Profile Picture:</strong> <img id="view_profile_picture" src="" alt="Profile Picture" class="w-20 h-20 rounded-full"></p>
                                    </div>
                                    <div class="bg-gray-100 p-4 rounded-lg shadow-md">
                                        <h4 class="text-lg font-bold mb-2">Barangay Official Details</h4>
                                        <p><strong>Department:</strong> <span id="view_department_name"></span></p>
                                        <p><strong>Position:</strong> <span id="view_position"></span></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                    <button type="button" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm" onclick="closeViewModal()">Close</button>
                </div>
            </div>
        </div>
    </div>

    <div class="fixed z-10 inset-0 overflow-y-auto hidden" id="editModal">
        <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 transition-opacity" aria-hidden="true">
                <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
            </div>
            <span aria-hidden="true" class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
            <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                    <div class="sm:flex sm:items-start">
                        <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                            <h3 class="text-lg leading-6 font-medium text-gray-900" id="editModal-title">Edit User</h3>
                            <div class="mt-2" id="editModal-content">
                                <form id="edit-modal-form" method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="edit_user" value="1">
                                    <input type="hidden" id="edit_user_id" name="user_id" value="">
                                    <div class="mb-4">
                                        <label class="block text-gray-700 text-sm font-bold mb-2" for="edit_role">Role</label>
                                        <select class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="edit_role" name="role" onchange="toggleEditRoleFields()">
                                            <option value="">Select Role</option>
                                            <option value="resident">Resident</option>
                                            <option value="barangay_official">Barangay Official</option>
                                            <option value="admin">Admin</option>
                                        </select>
                                    </div>
                                    <div class="mb-4">
                                        <label class="block text-gray-700 text-sm font-bold mb-2" for="edit_email">Email</label>
                                        <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="edit_email" name="email" type="email">
                                    </div>
                                    <div class="mb-4">
                                        <label class="block text-gray-700 text-sm font-bold mb-2" for="edit_password">Password</label>
                                        <div class="relative">
                                            <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="edit_password" name="edit_password" type="password">
                                            <span class="absolute inset-y-0 right-0 flex items-center pr-3 cursor-pointer" onclick="togglePasswordVisibility3()">
                                                <i id="password-eye" class="fas fa-eye"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="mb-4">
                                        <label class="block text-gray-700 text-sm font-bold mb-2" for="edit_first_name">First Name</label>
                                        <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="edit_first_name" name="first_name" type="text">
                                    </div>
                                    <div class="mb-4">
                                        <label class="block text-gray-700 text-sm font-bold mb-2" for="edit_last_name">Last Name</label>
                                        <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="edit_last_name" name="last_name" type="text" >
                                    </div>
                                    <div class="mb-4">
                                        <label class="block text-gray-700 text-sm font-bold mb-2" for="edit_middle_name">Middle Name</label>
                                        <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="edit_middle_name" name="middle_name" type="text">
                                    </div>
                                    <div class="mb-4">
                                        <label class="block text-gray-700 text-sm font-bold mb-2" for="edit_suffix">Suffix</label>
                                        <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="edit_suffix" name="suffix" type="text">
                                    </div>
                                    <div class="mb-4">
                                        <label class="block text-gray-700 text-sm font-bold mb-2" for="edit_address">Address</label>
                                        <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="edit_address" name="address" type="text">
                                    </div>
                                    <div class="mb-4">
                                        <label class="block text-gray-700 text-sm font-bold mb-2" for="edit_contact_number">Contact Number</label>
                                        <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="edit_contact_number" name="contact_number" type="text">
                                    </div>
                                    <div class="mb-4">
                                        <label class="block text-gray-700 text-sm font-bold mb-2" for="edit_birthday">Birthday</label>
                                        <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="edit_birthday" name="birthday" type="date">
                                    </div>
                                    <div class="mb-4">
                                        <label class="block text-gray-700 text-sm font-bold mb-2" for="edit_id_type_id">Select ID Type</label>
                                        <select class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="edit_id_type_id" name="id_type_id">
                                            <option value="">Select ID Type</option>
                                            <?php foreach ($id_types as $id_type): ?>
                                                <option value="<?php echo $id_type['id_type_id']; ?>"><?php echo htmlspecialchars($id_type['id_type_name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="mb-4">
                                        <label class="block text-gray-700 text-sm font-bold mb-2" for="edit_document_file">Document File</label>
                                        <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="edit_document_file" name="document_file" type="file">
                                    </div>
                                    <div class="mb-4">
                                        <label class="block text-gray-700 text-sm font-bold mb-2" for="edit_profile_picture">Profile Picture</label>
                                        <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="edit_profile_picture" name="profile_picture" type="file">
                                    </div>
                                    <div class="mb-4">
                                        <label class="block text-gray-700 text-sm font-bold mb-2" for="edit_is_verified">Is Verified</label>
                                        <input type="checkbox" id="edit_is_verified" name="is_verified" value="1">
                                    </div>
                                    <div id="edit-barangay-official-fields" class="hidden">
                                        <div class="mb-4">
                                            <label class="block text-gray-700 text-sm font-bold mb-2" for="edit_department_id">Department</label>
                                            <select class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="edit_department_id" name="department_id">
                                                <option value="">Select Department</option>
                                                <?php foreach ($departments as $department): ?>
                                                    <option value="<?php echo $department['department_id']; ?>"><?php echo htmlspecialchars($department['department_name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mb-4">
                                            <label class="block text-gray-700 text-sm font-bold mb-2" for="edit_position">Position</label>
                                            <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="edit_position" name="position" type="text" value="Staff">
                                        </div>
                                    </div>
                                    <div class="flex justify-end">
                                        <button type="button" class="bg-gray-300 text-gray-700 px-4 py-2 rounded mr-2" onclick="closeEditModal()">Cancel</button>
                                        <button type="submit" class="bg-purple-500 text-white px-4 py-2 rounded">Save Changes</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <!-- Delete Confirmation Modal -->
<div id="confirmDeleteModal" class="fixed z-10 inset-0 overflow-y-auto hidden">
    <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div class="fixed inset-0 transition-opacity" aria-hidden="true">
            <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
        </div>
        <span aria-hidden="true" class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
        <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
            <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                <div class="sm:flex sm:items-start">
                    <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                        <h3 class="text-lg leading-6 font-medium text-gray-900">Delete User</h3>
                        <div class="mt-2">
                            <p>Are you sure you want to delete this user?</p>
                        </div>
                        <div class="flex justify-end mt-4">
                            <button type="button" class="bg-gray-300 text-gray-700 px-4 py-2 rounded mr-2" onclick="closeConfirmDeleteModal()">Cancel</button>
                            <button type="button" class="bg-red-500 text-white px-4 py-2 rounded" onclick="confirmDeletion()">Delete</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Hidden form for deleting the user -->
<form id="deleteUserForm" method="POST" action="AdminManageUser.php" style="display: none;">
    <input type="hidden" name="delete_user" value="1">
    <input type="hidden" name="user_id" value="">
</form>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('main-content');
            sidebar.classList.toggle('-translate-x-full');
            mainContent.classList.toggle('ml-0');
            mainContent.classList.toggle('ml-64');
        }

        function toggleDropdown() {
            const dropdown = document.getElementById('dropdown');
            dropdown.classList.toggle('hidden');
        }

        function openModal() {
            document.getElementById('modal').classList.remove('hidden');
        }

        function closeModal() {
            document.getElementById('modal').classList.add('hidden');
        }

        function openViewModal() {
            document.getElementById('viewModal').classList.remove('hidden');
        }

        function closeViewModal() {
            document.getElementById('viewModal').classList.add('hidden');
        }

        function openEditModal(userId) {
            document.getElementById('editModal').classList.remove('hidden');
            fetchUserDetails(userId);
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.add('hidden');
        }

        function togglePasswordVisibility() {
            const passwordInput = document.getElementById('password');
            const passwordEye = document.getElementById('password-eye');
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                passwordEye.classList.remove('fa-eye');
                passwordEye.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                passwordEye.classList.remove('fa-eye-slash');
                passwordEye.classList.add('fa-eye');
            }
        }
        function togglePasswordVisibility2() {
            const passwordInput = document.getElementById("view_password");
            const eyeIcon = document.getElementById("password-eye2");

            if (passwordInput.type === "password") {
                passwordInput.type = "text";
                eyeIcon.classList.remove("fa-eye");
                eyeIcon.classList.add("fa-eye-slash");
            } else {
                passwordInput.type = "password";
                eyeIcon.classList.remove("fa-eye-slash");
                eyeIcon.classList.add("fa-eye");
            }
        }
        function togglePasswordVisibility3() {
            const passwordInput = document.getElementById('edit_password');
            const passwordEye = document.getElementById('password-eye3');
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                passwordEye.classList.remove('fa-eye');
                passwordEye.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                passwordEye.classList.remove('fa-eye-slash');
                passwordEye.classList.add('fa-eye');
            }
        }
        function toggleRoleFields() {
            const roleSelect = document.getElementById('role');
            const barangayOfficialFields = document.getElementById('barangay-official-fields');
            if (roleSelect.value === 'barangay_official') {
                barangayOfficialFields.classList.remove('hidden');
            } else {
                barangayOfficialFields.classList.add('hidden');
            }
        }

        function toggleEditRoleFields() {
            const roleSelect = document.getElementById('edit_role');
            const barangayOfficialFields = document.getElementById('edit-barangay-official-fields');
            if (roleSelect.value === 'barangay_official') {
                barangayOfficialFields.classList.remove('hidden');
            } else {
                barangayOfficialFields.classList.add('hidden');
            }
        }

        function viewUser(userId) {
            fetchUserDetails(userId);
            openViewModal();
        }

        const userData = <?php echo json_encode($users); ?>;

        function fetchUserDetails(userId) {
            const user = userData.find(user => user.user_id == userId);
            if (user) {
                document.getElementById('view_email').textContent = user.email;
                document.getElementById('view_first_name').textContent = user.first_name;
                document.getElementById('view_last_name').textContent = user.last_name;
                document.getElementById('view_middle_name').textContent = user.middle_name || '';
                document.getElementById('view_suffix').textContent = user.suffix || '';
                document.getElementById('view_address').textContent = user.address || '';
                document.getElementById('view_contact_number').textContent = user.contact_number || '';
                document.getElementById('view_birthday').textContent = user.birthday || '';
                document.getElementById('view_role').textContent = user.role;
                document.getElementById('view_is_verified').textContent = user.is_verified ? 'Yes' : 'No';
                document.getElementById('view_id_type_name').textContent = user.id_type_name || '';
                document.getElementById('view_document_file').href = user.document_file || '#';
                document.getElementById('view_profile_picture').src = user.profile_picture || '';
                document.getElementById('view_department_name').textContent = user.department_name || '';
                document.getElementById('view_position').textContent = user.position || '';
                document.getElementById('view_password').value = user.password; 

                document.getElementById('edit_user_id').value = userId;
                document.getElementById('edit_email').value = user.email;
                document.getElementById('edit_first_name').value = user.first_name;
                document.getElementById('edit_last_name').value = user.last_name;
                document.getElementById('edit_middle_name').value = user.middle_name || '';
                document.getElementById('edit_suffix').value = user.suffix || '';
                document.getElementById('edit_address').value = user.address || '';
                document.getElementById('edit_contact_number').value = user.contact_number || '';
                document.getElementById('edit_birthday').value = user.birthday || '';
                document.getElementById('edit_role').value = user.role;
                document.getElementById('edit_is_verified').checked = !!user.is_verified; // Convert to boolean
                document.getElementById('edit_id_type_id').value = user.id_type_id || '';
                document.getElementById('edit_department_id').value = user.department_id || '';
                document.getElementById('edit_position').value = user.position || '';
                document.getElementById('edit_password').value = user.password || ''; // Populate the password in the edit modal

                const editBarangayOfficialFields = document.getElementById('edit-barangay-official-fields');
                if (user.role === "barangay_official") {
                    editBarangayOfficialFields.classList.remove('hidden');
                } else {
                    editBarangayOfficialFields.classList.add('hidden');
                }
            } else {
                console.error('User not found:', userId);
            }
        }

        function searchResidents() {
            const searchInput = document.getElementById('search').value.toLowerCase();
            const verifiedResidentsList = document.getElementById('verified-residents-list');
            const nonVerifiedResidentsList = document.getElementById('non-verified-residents-list');
            const allResidents = [...verifiedResidentsList.children, ...nonVerifiedResidentsList.children];

            allResidents.forEach(resident => {
                const residentName = resident.querySelector('td:nth-child(2)').textContent.toLowerCase();
                const residentEmail = resident.querySelector('td:nth-child(3)').textContent.toLowerCase();
                if (residentName.includes(searchInput) || residentEmail.includes(searchInput)) {
                    resident.style.display = '';
                } else {
                    resident.style.display = 'none';
                }
            });
        }

        let currentUserId = null;

function showConfirmDeleteModal(userId) {
    currentUserId = userId;
    // Display the modal
    document.getElementById('confirmDeleteModal').classList.remove('hidden');
}

function closeConfirmDeleteModal() {
    // Hide the modal
    document.getElementById('confirmDeleteModal').classList.add('hidden');
}

function confirmDeletion() {
    // Submit the form to delete the user with the confirmed ID
    const form = document.getElementById('deleteUserForm');
    form.user_id.value = currentUserId;
    form.submit();
}

    </script>
</body>
</html>