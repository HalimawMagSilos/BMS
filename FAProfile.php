<?php
session_start();
require 'connection.php';

// Check if the user is logged in and is a barangay official for Financial Assistance
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'barangay_official') {
    header("Location: LoginForm.php");
    exit();
}

// Fetch barangay official profile information for Financial Assistance Department
try {
    $stmt = $pdo->prepare("
        SELECT p.profile_id, p.user_id, p.first_name, p.middle_name, p.last_name, p.suffix, p.address, p.contact_number,
               d.department_name, bo.position, u.created_at, p.profile_picture, p.birthday, u.email
        FROM Profiles p
        JOIN Users u ON p.user_id = u.user_id
        JOIN Barangay_Officials bo ON u.user_id = bo.user_id
        JOIN Departments d ON bo.department_id = d.department_id
        WHERE u.user_id = :user_id AND d.department_name = 'Financial Assistance'
    ");
    $stmt->execute(['user_id' => $_SESSION['user_id']]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$profile) {
        throw new Exception("Profile not found or not in Financial Assistance Department.");
    }

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
} catch (Exception $e) {
    die($e->getMessage());
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    try {
        $first_name = $_POST['first_name'];
        $middle_name = $_POST['middle_name'];
        $last_name = $_POST['last_name'];
        $suffix = $_POST['suffix'];
        $address = $_POST['address'];
        $contact_number = $_POST['contact_number'];
        $birthday = $_POST['birthday'];

        $stmt = $pdo->prepare("
            UPDATE Profiles
            SET first_name = ?, middle_name = ?, last_name = ?, suffix = ?, address = ?, contact_number = ?, birthday = ?
            WHERE profile_id = ?
        ");
        $stmt->execute([$first_name, $middle_name, $last_name, $suffix, $address, $contact_number, $birthday, $profile['profile_id']]);

        header("Location: FAProfile.php?update_success=1");
        exit();

    } catch (PDOException $e) {
        die("Database error: " . $e->getMessage());
    }
}

// Handle message submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    try {
        $message_content = $_POST['message'];
        $attachment_path = '';

        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] == UPLOAD_ERR_OK) {
            $attachment_tmp_path = $_FILES['attachment']['tmp_name'];
            $attachment_name = basename($_FILES['attachment']['name']);
            $attachment_path = 'FILES/' . $attachment_name;

            if (!move_uploaded_file($attachment_tmp_path, $attachment_path)) {
                die("Error uploading attachment.");
            }
        }

        $stmt = $pdo->prepare("INSERT INTO Resident_Messages (user_id, message_content, attached_file) VALUES (?, ?, ?)");
        $stmt->execute([$_SESSION['user_id'], $message_content, $attachment_path]);

        // Log the message action
        $log_action = "Barangay Official sent a message to admin.";
        $stmt_log = $pdo->prepare("INSERT INTO System_Logs (user_id, action) VALUES (?, ?)");
        $stmt_log->execute([$_SESSION['user_id'], $log_action]);

        header("Location: FAProfile.php?message_sent=1");
        exit();

    } catch (PDOException $e) {
        die("Database error: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Financial Assistance Department Dashboard</title>
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
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgb(0,0,0); background-color: rgba(0,0,0,0.4); }
        .modal-content { background-color: #fefefe; margin: 15% auto; padding: 20px; border: 1px solid #888; width: 80%; }
    </style>
</head>
<body class="bg-gray-100 flex">
    <aside id="sidebar" class="sidebar bg-green-500 text-white w-64 min-h-screen p-6 fixed inset-y-0 left-0 transform -translate-x-full overflow-y-auto">
        <div class="text-center mb-8">
            <img alt="Financial Assistance Department Logo" class="mx-auto mb-4" height="100" src="https://storage.googleapis.com/a1aa/image/FIM565KosH2Eo5Lqps2r-iwpSdYyPwD4FcVD0OtHXMk.jpg" width="100"/>
            <h1 class="text-3xl font-bold">Financial Assistance Dept</h1>
        </div>
        <nav>
            <ul class="space-y-6">
                <li><a href="FAHome.php" class="block py-3 px-4 rounded hover:bg-green-600 text-lg">Home</a></li>
                <li><a href="FAProfile.php" class="block py-3 px-4 rounded hover:bg-green-600 text-lg">Profile</a></li>
                <li><a href="FAApplication.php" class="block py-3 px-4 rounded hover:bg-green-600 text-lg">Financial Assistance Applications</a></li>
                <li><a href="FASettings.php" class="block py-3 px-4 rounded hover:bg-green-600 text-lg">Settings</a></li>
                <li><a href="logout.php" class="block py-3 px-4 rounded hover:bg-green-600 text-lg">Logout</a></li>
            </ul>
        </nav>
    </aside>
    <main id="main-content" class="main-content flex-grow p-8 ml-0">
        <div class="flex justify-between items-center mb-8">
            <h2 class="text-3xl font-bold">Profile</h2>
            <button class="text-green-500 focus:outline-none" onclick="toggleSidebar()">
                <i class="fas fa-bars text-2xl"></i>
            </button>
        </div>
        <div class="bg-white p-8 rounded-lg shadow-md mb-8">
            <div class="text-center mb-6">
                <img alt="Profile picture" class="w-32 h-32 rounded-full mx-auto" height="100" src="<?php echo htmlspecialchars($profile['profile_picture'] ?? 'https://via.placeholder.com/100'); ?>" width="100"/>
            </div>
            <h2 class="text-2xl font-bold mb-6">User Profile</h2>
            <form action="FAProfile.php" method="POST">
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="first_name">First Name</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" id="first_name" type="text" name="first_name" value="<?php echo htmlspecialchars($profile['first_name'] ?? ''); ?>"/>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="middle_name">Middle Name</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" id="middle_name" type="text" name="middle_name" value="<?php echo htmlspecialchars($profile['middle_name'] ?? ''); ?>"/>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="last_name">Last Name</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" id="last_name" type="text" name="last_name" value="<?php echo htmlspecialchars($profile['last_name'] ?? ''); ?>"/>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="suffix">Suffix</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" id="suffix" type="text" name="suffix" value="<?php echo htmlspecialchars($profile['suffix'] ?? ''); ?>"/>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="address">Address</label>
                    <textarea class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" id="address" name="address"><?php echo htmlspecialchars($profile['address'] ?? ''); ?></textarea>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="contact_number">Contact Number</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" id="contact_number" type="text" name="contact_number" value="<?php echo htmlspecialchars($profile['contact_number'] ?? ''); ?>"/>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="email">Email</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" id="email" type="email" name="email" value="<?php echo htmlspecialchars($profile['email'] ?? ''); ?>" readonly/>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="birthday">Birthday</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" id="birthday" type="date" name="birthday" value="<?php echo htmlspecialchars($profile['birthday'] ?? ''); ?>"/>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="department">Department</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" id="department" readonly="" type="text" value="<?php echo htmlspecialchars($profile['department_name'] ?? ''); ?>"/>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="position">Position</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" id="position" readonly="" type="text" value="<?php echo htmlspecialchars($profile['position'] ?? ''); ?>"/>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="created_at">Date Created</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" id="created_at" readonly="" type="text" value="<?php echo htmlspecialchars($profile['created_at'] ?? ''); ?>"/>
                </div>
            </form>
            <a href="#" class="bg-blue-500 text-white py-2 px-4 rounded-lg hover:bg-blue-600 inline-block mt-4" onclick="openModal()">Send Message to Admin</a>
        </div>
    </main>
    <div id="messageModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2 class="text-2xl font-bold mb-4">Send a Message to Admin</h2>
            <form action="FAProfile.php" method="POST" enctype="multipart/form-data">
                <div class="mb-4">
                    <label for="message" class="block text-gray-700 font-medium mb-2">Message</label>
                    <textarea id="message" name="message" rows="4" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" required></textarea>
                </div>
                <div class="mb-4">
                    <label for="attachment" class="block text-gray-700 font-medium mb-2">Attach a File</label>
                    <input type="file" id="attachment" name="attachment" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <button type="submit" name="send_message" class="bg-blue-500 text-white py-2 px-4 rounded-lg hover:bg-blue-600">Send Message</button>
                <?php if (isset($_GET['message_sent'])): ?>
                    <p class="text-green-500 mt-4">Message sent successfully!</p>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('main-content');
            sidebar.classList.toggle('-translate-x-full');
            mainContent.classList.toggle('ml-64');
        }
        function openModal() {
            document.getElementById('messageModal').style.display = "block";
        }
        function closeModal() {
            document.getElementById('messageModal').style.display = "none";
        }
        window.onclick = function(event) {
            const modal = document.getElementById('messageModal');
            if (event.target === modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>