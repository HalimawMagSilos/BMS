<?php
// Start the session
session_start();

require 'connection.php';

// Check if the user is logged in
if (!isset($_SESSION['user_id'])|| $_SESSION['role'] !== 'resident') {
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

// Initialize variables
$profile = [
    'first_name' => '',
    'middle_name' => '',
    'last_name' => '',
    'suffix' => '',
    'address' => '',
    'contact_number' => '',
    'profile_picture' => '',
    'birthday' => '',
    'user_id' => $_SESSION['user_id'] // Store user_id for later use
];

// Fetch user profile information only if verified
if ($is_verified) {
    try {
        $stmt = $pdo->prepare("SELECT p.first_name, p.middle_name, p.last_name, p.suffix, p.address, p.contact_number, p.profile_picture, p.birthday
                                FROM Profiles p
                                WHERE p.user_id = :user_id");
        $stmt->execute(['user_id' => $_SESSION['user_id']]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        die("Database error: " . $e->getMessage());
    }
}

// Handle the profile update submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_profile_update'])) {
    // Get profile data from the form
    $first_name = $_POST['first_name'];
    $middle_name = $_POST['middle_name'];
    $last_name = $_POST['last_name'];
    $suffix = $_POST['suffix'];
    $address = $_POST['address'];
    $contact_number = $_POST['contact_number'];
    $birthday = $_POST['birthday'];
    $id_type_id = $_POST['id_type']; // Get the id_type_id

    // Handle file upload for ID document
    $id_document_path = '';
    if (isset($_FILES['id_document']) && $_FILES['id_document']['error'] == UPLOAD_ERR_OK) {
        $id_document_tmp_path = $_FILES['id_document']['tmp_name'];
        $id_document_name = basename($_FILES['id_document']['name']);
        $id_document_extension = strtolower(pathinfo($id_document_name, PATHINFO_EXTENSION));

        // Validate file type
        $allowed_extensions = ['pdf', 'docx', 'jpg', 'jpeg', 'png'];
        if (!in_array($id_document_extension, $allowed_extensions)) {
            die("Invalid ID document file type. Allowed types: pdf, docx, jpg, jpeg, png.");
        }

        $id_document_path = 'FILES/' . $id_document_name; // Specify the directory

        // Move the uploaded file to the FILES directory
        if (!move_uploaded_file($id_document_tmp_path, $id_document_path)) {
            die("Error uploading ID document.");
        }

        // Insert the ID document into User_ID_Documents table
        try {
            $stmt = $pdo->prepare("INSERT INTO User_ID_Documents (user_id, id_type_id, document_file) VALUES (?, ?, ?)");
            $stmt->execute([$profile['user_id'], $id_type_id, $id_document_path]);
        } catch (PDOException $e) {
            die("Database error: " . $e->getMessage());
        }
    }

    // Handle file upload for profile picture
    $profile_picture_path = '';
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == UPLOAD_ERR_OK) {
        $profile_picture_tmp_path = $_FILES['profile_picture']['tmp_name'];
        $profile_picture_name = basename($_FILES['profile_picture']['name']);
        $profile_picture_extension = strtolower(pathinfo($profile_picture_name, PATHINFO_EXTENSION));

        // Validate file type
        $allowed_extensions = ['jpg', 'jpeg', 'png'];
        if (!in_array($profile_picture_extension, $allowed_extensions)) {
            die("Invalid profile picture file type. Allowed types: jpg, jpeg, png.");
        }

        $profile_picture_path = 'IMAGE/' . $profile_picture_name; // Specify the directory

        // Move the uploaded file to the IMAGE directory
        if (!move_uploaded_file($profile_picture_tmp_path, $profile_picture_path)) {
            die("Error uploading profile picture.");
        }
    }

    // Update the profile in the database
    try {
        $stmt = $pdo->prepare("INSERT INTO Profiles (user_id, first_name, middle_name, last_name, suffix, address, contact_number, profile_picture, birthday)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE
                                first_name = ?, middle_name = ?, last_name = ?, suffix = ?, address = ?, contact_number = ?, profile_picture = ?, birthday = ?");
        $stmt->execute([$profile['user_id'], $first_name, $middle_name, $last_name, $suffix, $address, $contact_number, $profile_picture_path, $birthday,
                        $first_name, $middle_name, $last_name, $suffix, $address, $contact_number, $profile_picture_path, $birthday]);

        // Log the action in the System_Logs table
        $action = "Updated profile for user ID: " . $_SESSION['user_id'];
        $log_stmt = $pdo->prepare("INSERT INTO System_Logs (user_id, action) VALUES (?, ?)");
        $log_stmt->execute([$_SESSION['user_id'], $action]);

        // Redirect or show success message
        header("Location: ResidentProfile.php");
        exit();
    } catch (PDOException $e) {
        die("Database error: " . $e->getMessage());
    }
}

// Handle message submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $message_content = $_POST['message'];
    $attachment_path = '';

    // Handle file upload for message attachment
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] == UPLOAD_ERR_OK) {
        $attachment_tmp_path = $_FILES['attachment']['tmp_name'];
        $attachment_name = basename($_FILES['attachment']['name']);
        $attachment_extension = strtolower(pathinfo($attachment_name, PATHINFO_EXTENSION));

        // Validate file type
        $allowed_extensions = ['pdf', 'docx', 'jpg', 'jpeg', 'png'];
        if (!in_array($attachment_extension, $allowed_extensions)) {
            die("Invalid attachment file type. Allowed types: pdf, docx, jpg, jpeg, png.");
        }

        $attachment_path = 'FILES/' . $attachment_name; // Specify the directory

        // Move the uploaded file to the FILES directory
        if (!move_uploaded_file($attachment_tmp_path, $attachment_path)) {
            die("Error uploading attachment.");
        }
    }

    // Insert the message into Resident_Messages table
    try {
        $stmt = $pdo->prepare("INSERT INTO Resident_Messages (user_id, message_content, attached_file) VALUES (?, ?, ?)");
        $stmt->execute([$_SESSION['user_id'], $message_content, $attachment_path]);

        // Log the message action in the System_Logs table
        $action = "Sent message to admin from user ID: " . $_SESSION['user_id'];
        $log_stmt = $pdo->prepare("INSERT INTO System_Logs (user_id, action) VALUES (?, ?)");
        $log_stmt->execute([$_SESSION['user_id'], $action]);

    } catch (PDOException $e) {
        die("Database error: " . $e->getMessage());
    }

    // Optionally, redirect or show a success message
    header("Location: ResidentProfile.php");
    exit();
}

// Fetch ID types for the dropdown
$id_types = [];
try {
    $stmt = $pdo->query("SELECT id_type_id, id_type_name FROM ID_Types");
    $id_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Resident Dashboard - Profile</title>
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
        .submit-button {
            width: 100%;
            padding: 12px;
            font-size: 1.125rem;
        }
        .terms-checkbox {
            margin-right: 0.5rem;
        }
        .profile-picture {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgb(0,0,0);
            background-color: rgba(0,0,0,0.4);
        }
        .modal-content {
            background-color: #fefefe;
            margin: 15% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
        }
    </style>
</head>
<body class="bg-gray-100 flex">
    <aside id="sidebar" class="sidebar bg-blue-500 text-white w-64 min-h-screen p-6 fixed inset-y-0 left-0 transform -translate-x-full overflow-y-auto">
        <div class="text-center mb-8">
            <img alt="Resident Dashboard Logo" class="mx-auto mb-4" height="100" src="https://storage.googleapis.com/a1aa/image/ZlS8sK0YNai5MGRJForAKu4_20-Z3HeENsCVvw_X1Vk.jpg" width="100">
            <h1 class="text-3xl font-bold">Resident Dashboard</h1>
        </div>
        <nav>
            <ul class="space-y-6">
                <li><a href="ResidentHome.php" class="block py-3 px-4 rounded hover:bg-blue-600 text-lg">Home</a></li>
                <li><a href="ResidentProfile.php" class="block py-3 px-4 rounded hover:bg-blue-600 text-lg">Profile</a></li>
                <?php if ($is_verified): ?>
                    <li>
                        <button class="block w-full text-left py-3 px-4 rounded hover:bg-blue-600 focus:outline-none text-lg" onclick="toggleDropdown()">Applications</button>
                        <ul id="dropdown" class="hidden space-y-2 pl-4">
                            <li><a href="ResidentBarangayClearance.php" class="block py-3 px-4 rounded hover:bg-blue-600 text-lg">Barangay Clearance</a></li>
                            <li><a href="ResidentBusinessPermit.php" class="block py-3 px-4 rounded hover:bg-blue-600 text-lg">Business Permit</a></li>
                            <li><a href="ResidentFinancialAssistance.php" class="block py-3 px-4 rounded hover:bg-blue-600 text-lg">Financial Assistance</a></li>
                        </ul>
                    </li>
                <?php else: ?>
                    <li>
                        <button class="block w-full text-left py-3 px-4 rounded text-gray-400 cursor-not-allowed" disabled>Applications (Not Verified)</button>
                    </li>
                <?php endif; ?>
                <li><a href="ResidentSettings.php" class="block py-3 px-4 rounded hover:bg-blue-600 text-lg">Settings</a></li>
                <li><a href="logout.php" class="block py-3 px-4 rounded hover:bg-blue-600 text-lg">Logout</a></li>
            </ul>
        </nav>
    </aside>

    <main id="main-content" class="main-content flex-grow p-8 ml-0">
        <div class="flex justify-between items-center mb-8">
            <h2 class="text-3xl font-bold">Profile</h2>
            <button class="text-blue-500 focus:outline-none" onclick="toggleSidebar()">
                <i class="fas fa-bars text-2xl"></i></button>
            </div>
            <div class="bg-white p-8 rounded-lg shadow-md">
                <h2 class="text-2xl font-bold mb-6">Personal Information</h2>
                <div class="flex items-center mb-4">
                    <img src="<?php echo htmlspecialchars($is_verified ? $profile['profile_picture'] : 'placeholder.jpg'); ?>" alt="Profile Picture" class="profile-picture mr-4">
                    <div>
                        <h3 class="text-xl font-semibold"><?php echo htmlspecialchars($is_verified ? $profile['first_name'] . ' ' . $profile['last_name'] : ''); ?></h3>
                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($is_verified ? $profile['address'] : ''); ?></p>
                    </div>
                </div>
                <form action="ResidentProfile.php" method="POST" enctype="multipart/form-data">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="first_name" class="block text-lg">First Name</label>
                            <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($is_verified ? $profile['first_name'] : ''); ?>" <?php echo $is_verified ? 'readonly' : ''; ?> class="border rounded p-2 w-full" required>
                        </div>
                        <div>
                            <label for="middle_name" class="block text-lg">Middle Name</label>
                            <input type="text" id="middle_name" name="middle_name" value="<?php echo htmlspecialchars($is_verified ? $profile['middle_name'] : ''); ?>" <?php echo $is_verified ? 'readonly' : ''; ?> class="border rounded p-2 w-full">
                        </div>
                        <div>
                            <label for="last_name" class="block text-lg">Last Name</label>
                            <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($is_verified ? $profile['last_name'] : ''); ?>" <?php echo $is_verified ? 'readonly' : ''; ?> class="border rounded p-2 w-full" required>
                        </div>
                        <div>
                            <label for="suffix" class="block text-lg">Suffix</label>
                            <input type="text" id="suffix" name="suffix" value="<?php echo htmlspecialchars($is_verified ? $profile['suffix'] : ''); ?>" <?php echo $is_verified ? 'readonly' : ''; ?> class="border rounded p-2 w-full">
                        </div>
                        <div>
                            <label for="address" class="block text-lg">Address</label>
                            <input type="text" id="address" name="address" value="<?php echo htmlspecialchars($is_verified ? $profile['address'] : ''); ?>" <?php echo $is_verified ? 'readonly' : ''; ?> class="border rounded p-2 w-full" required>
                        </div>
                        <div>
                            <label for="contact_number" class="block text-lg">Contact Number</label>
                            <input type="text" id="contact_number" name="contact_number" value="<?php echo htmlspecialchars($is_verified ? $profile['contact_number'] : ''); ?>" <?php echo $is_verified ? 'readonly' : ''; ?> class="border rounded p-2 w-full" required>
                        </div>
                        <div>
                            <label for="birthday" class="block text-lg">Birthday</label>
                            <input type="date" id="birthday" name="birthday" value="<?php echo htmlspecialchars($is_verified ? $profile['birthday'] : ''); ?>" <?php echo $is_verified ? 'readonly' : ''; ?> class="border rounded p-2 w-full" required>
                        </div>
                        <div>
                            <label for="id_type" class="block text-lg">Select ID Type</label>
                            <select id="id_type" name="id_type" <?php echo $is_verified ? 'disabled' : ''; ?> class="border rounded p-2 w-full" required>
                                <option value="" disabled selected>Select ID Type</option>
                                <?php foreach ($id_types as $type): ?>
                                    <option value="<?php echo $type['id_type_id']; ?>" <?php echo (isset($profile['id_type']) && $profile['id_type'] == $type['id_type_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($type['id_type_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="id_document" class="block text-lg">Upload ID Document (PDF, DOCX, JPG, JPEG, PNG)</label>
                            <input type="file" id="id_document" name="id_document" accept=".jpg,.jpeg,.png,.pdf,.docx" <?php echo $is_verified ? 'disabled' : ''; ?> class="border rounded p-2 w-full" required>
                        </div>
                        <div>
                            <label for="profile_picture" class="block text-lg">Upload Profile Picture (JPG, JPEG, PNG)</label>
                            <input type="file" id="profile_picture" name="profile_picture" accept="image/*" <?php echo $is_verified ? 'disabled' : ''; ?> class="border rounded p-2 w-full">
                        </div>
                    </div>
                    <div class="mt-4">
                        <label class="block text-lg">
                            <input type="checkbox" id="terms" name="terms" <?php echo $is_verified ? 'disabled' : ''; ?> class="terms-checkbox mr-2 leading-tight"/>
                            <span class="text-sm">I accept the <a href="#" class="text-blue-500 underline">terms and conditions</a></span>
                        </label>
                    </div>
                    <button type="submit" name="submit_profile_update" class="submit-button bg-blue-500 text-white rounded hover:bg-blue-600 mt-4" <?php echo $is_verified ? 'disabled' : ''; ?>>Submit Profile Update</button>
                </form>
                <?php if (!$is_verified): ?>
                    <p class="mt-4 text-red-500">Your profile is not verified. Please wait for admin verification.</p>
                <?php endif; ?>
            </div>
            <footer class="mt-8">
                <p class="text-center">
                    <a href="#" class="text-blue-500 underline" onclick="openModal()">Need to update your profile? Click here.</a>
                </p>
            </footer>
        </main>

        <div id="messageModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeModal()">&times;</span>
                <h2 class="text-2xl font-bold mb-4">Send a Message to Admin</h2>
                <form action="ResidentProfile.php" method="POST" enctype="multipart/form-data">
                    <div class="mb-4">
                        <label for="message" class="block text-lg">Message</label>
                        <textarea id="message" name="message" rows="4" class="border rounded p-2 w-full" required></textarea>
                    </div>
                    <div class="mb-4">
                        <label for="attachment" class="block text-lg">Attach a File (PDF, DOCX, JPG, JPEG, PNG)</label>
                        <input type="file" id="attachment" name="attachment" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" class="border rounded p-2 w-full">
                    </div>
                    <button type="submit" name="send_message" class="submit-button bg-blue-500 text-white rounded hover:bg-blue-600">Send Message</button>
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

            function toggleDropdown() {
                const dropdown = document.getElementById('dropdown');
                dropdown.classList.toggle ('hidden');
            }

            function openModal() {
                document.getElementById('messageModal').style.display = "block";
            }

            function closeModal() {
                document.getElementById('messageModal').style.display = "none";
            }

            // Close the modal when clicking outside of it
            window.onclick = function(event) {
                const modal = document.getElementById('messageModal');
                if (event.target === modal) {
                    closeModal();
                }
            }
        </script>
    </body>
    </html>