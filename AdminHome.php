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

$admin_id = $_SESSION['user_id']; // Get the logged-in admin ID from session

// Handle add, edit, delete requests for announcements
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_announcement'])) {
        $title = trim($_POST['title']);
        $content = trim($_POST['content']);

        $stmt = $pdo->prepare("INSERT INTO Announcements (admin_id, title, content, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$admin_id, $title, $content]);

        $action = "Added announcement: $title";
        $logStmt = $pdo->prepare("INSERT INTO System_Logs (user_id, action) VALUES (?, ?)");
        $logStmt->execute([$admin_id, $action]);
        $_SESSION['success_message'] = 'Announcement added successfully!';
        header('Location: AdminHome.php');
        exit();
    } elseif (isset($_POST['edit_announcement'])) {
        $announcement_id = $_POST['announcement_id'];
        $title = trim($_POST['title']);
        $content = trim($_POST['content']);

        $stmt = $pdo->prepare("UPDATE Announcements SET title = ?, content = ? WHERE announcement_id = ?");
        $stmt->execute([$title, $content, $announcement_id]);

        $action = "Edited announcement ID: $announcement_id, New title: $title";
        $logStmt = $pdo->prepare("INSERT INTO System_Logs (user_id, action) VALUES (?, ?)");
        $logStmt->execute([$admin_id, $action]);
        $_SESSION['success_message'] = 'Announcement updated successfully!';
        header('Location: AdminHome.php');
        exit();
    } elseif (isset($_POST['delete_announcement'])) {
        $announcement_id = $_POST['announcement_id'];

        $stmt = $pdo->prepare("DELETE FROM Announcements WHERE announcement_id = ?");
        $stmt->execute([$announcement_id]);

        $action = "Deleted announcement ID: $announcement_id";
        $logStmt = $pdo->prepare("INSERT INTO System_Logs (user_id, action) VALUES (?, ?)");
        $logStmt->execute([$admin_id, $action]);
        $_SESSION['success_message'] = 'Announcement deleted successfully!';
        header('Location: AdminHome.php');
        exit();
    }
    // Handle add, edit, delete requests for elections
    elseif (isset($_POST['add_election'])) {
        $title = trim($_POST['election_title']);
        $description = trim($_POST['election_description']);
        $election_date = trim($_POST['election_date']);

        // Image upload handling
        $image = $_FILES['election_image']['name'];
        $image_tmp = $_FILES['election_image']['tmp_name'];
        $picture = "IMAGE/" . $image;  // Ensure 'IMAGE/' folder exists

        move_uploaded_file($image_tmp, $picture);

        $stmt = $pdo->prepare("INSERT INTO Elections (title, description, election_date, picture) VALUES (?, ?, ?, ?)");
        $stmt->execute([$title, $description, $election_date, $picture]);

        $action = "Added election: $title";
        $logStmt = $pdo->prepare("INSERT INTO System_Logs (user_id, action) VALUES (?, ?)");
        $logStmt->execute([$admin_id, $action]);
        $_SESSION['success_message'] = 'Election added successfully!';
        header('Location: AdminHome.php#elections-section');
        exit();
    } elseif (isset($_POST['edit_election'])) {
        $election_id = $_POST['election_id'];
        $title = trim($_POST['election_title']);
        $description = trim($_POST['election_description']);
        $election_date = trim($_POST['election_date']);

        // Image upload handling (optional, handle only if a new image is uploaded)
        if (!empty($_FILES['election_image']['name'])) {
            $image = $_FILES['election_image']['name'];
            $image_tmp = $_FILES['election_image']['tmp_name'];
            $picture = "IMAGE/" . $image;
            move_uploaded_file($image_tmp, $picture);

            $stmt = $pdo->prepare("UPDATE Elections SET title = ?, description = ?, election_date = ?, picture = ? WHERE election_id = ?");
            $stmt->execute([$title, $description, $election_date, $picture, $election_id]);
        } else {
            // If no new image, update other fields
            $stmt = $pdo->prepare("UPDATE Elections SET title = ?, description = ?, election_date = ? WHERE election_id = ?");
            $stmt->execute([$title, $description, $election_date, $election_id]);
        }

        $action = "Edited election ID: $election_id, New title: $title";
        $logStmt = $pdo->prepare("INSERT INTO System_Logs (user_id, action) VALUES (?, ?)");
        $logStmt->execute([$admin_id, $action]);
        $_SESSION['success_message'] = 'Election updated successfully!';
        header('Location: AdminHome.php#elections-section');
        exit();
    } elseif (isset($_POST['delete_election'])) {
        $election_id = $_POST['election_id'];

        $stmt = $pdo->prepare("DELETE FROM Elections WHERE election_id = ?");
        $stmt->execute([$election_id]);

        $action = "Deleted election ID: $election_id";
        $logStmt = $pdo->prepare("INSERT INTO System_Logs (user_id, action) VALUES (?, ?)");
        $logStmt->execute([$admin_id, $action]);
        $_SESSION['success_message'] = 'Election deleted successfully!';
        header('Location: AdminHome.php#elections-section');
        exit();
    }
}

// Fetch announcements
$announcements = [];
$stmt = $pdo->query("SELECT * FROM Announcements ORDER BY created_at DESC");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $announcements[] = $row;
}

// Fetch elections
$elections = [];
$stmt = $pdo->query("SELECT * FROM Elections ORDER BY election_date DESC");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $elections[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
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
            z-index: 20; /* Ensure sidebar is above main content when toggled */
        }
        .main-content {
            transition: margin-left 0.3s ease-in-out;
        }
        .election-image { /* Style for election images */
            max-width: 100px; /* Adjust as needed */
            max-height: 100px;
            margin-right: 10px;
            border-radius: 0.25rem;
            box-shadow: 0 1px 3px rgba(0,0,0,.1), 0 1px 2px -1px rgba(0,0,0,.05);
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 10;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
        }
        .modal-content {
            background-color: #fefefe;
            margin: 15% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,.1), 0 2px 4px -1px rgba(0,0,0,.06);
        }
        .close-button {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
        }
        .close-button:hover,
        .close-button:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
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
        .form-group input[type="datetime-local"],
        .form-group input[type="file"] {
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
    <main class="main-content flex-grow p-8 ml-0" id="main-content">
        <div class="flex justify-between items-center mb-8">
            <h2 class="text-3xl font-bold">Home</h2>
            <button class="text-purple-500 focus:outline-none" onclick="toggleSidebar()">
                <i class="fas fa-bars text-2xl"></i>
            </button>
        </div>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert-success mb-4"><?php echo $_SESSION['success_message']; ?></div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert-error mb-4"><?php echo $_SESSION['error_message']; ?></div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
            <div class="card p-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-semibold text-gray-800">Announcements</h2>
                    <button onclick="showAddAnnouncementModal()" class="btn-primary text-sm"><i class="fas fa-plus mr-2"></i>Add</button>
                    </div>
                    <ul class="space-y-3">
                        <?php if (empty($announcements)): ?>
                            <li><p class="text-gray-600">No announcements yet.</p></li>
                        <?php else: ?>
                            <?php foreach ($announcements as $announcement): ?>
                                <li class="p-4 rounded-md bg-gray-100 border border-gray-200">
                                    <div class="flex justify-between items-start mb-2">
                                        <h3 class="text-lg font-semibold text-gray-700"><?php echo htmlspecialchars($announcement['title']); ?></h3>
                                        <div class="flex gap-2">
                                            <button onclick="showEditAnnouncementModal(<?php echo $announcement['announcement_id']; ?>, '<?php echo htmlspecialchars($announcement['title']); ?>', '<?php echo htmlspecialchars($announcement['content']); ?>')" class="text-blue-500 hover:underline focus:outline-none"><i class="fas fa-edit"></i></button>
                                            <form action="" method="POST" style="display:inline;">
                                                <input type="hidden" name="announcement_id" value="<?php echo $announcement['announcement_id']; ?>">
                                                <button type="submit" name="delete_announcement" class="text-red-500 hover:underline focus:outline-none" onclick="return confirm('Are you sure you want to delete this announcement?')"><i class="fas fa-trash-alt"></i></button>
                                            </form>
                                        </div>
                                    </div>
                                    <p class="text-gray-600 text-sm"><?php echo nl2br(htmlspecialchars($announcement['content'])); ?></p>
                                    <p class="text-gray-500 text-xs mt-1">Posted on: <?php echo date('F j, Y h:i A', strtotime($announcement['created_at'])); ?></p>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>

                <div class="card p-6" id="elections-section">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-semibold text-gray-800">Elections</h2>
                        <button onclick="showAddElectionModal()" class="btn-primary text-sm"><i class="fas fa-plus mr-2"></i>Add</button>
                    </div>
                    <ul class="space-y-3">
                        <?php if (empty($elections)): ?>
                            <li><p class="text-gray-600">No elections scheduled yet.</p></li>
                        <?php else: ?>
                            <?php foreach ($elections as $election): ?>
                                <li class="p-4 rounded-md bg-gray-100 border border-gray-200 flex items-center">
                                    <?php if (!empty($election['picture'])): ?>
                                        <img src="<?php echo htmlspecialchars($election['picture']); ?>" alt="Election Image" class="election-image mr-4">
                                    <?php endif; ?>
                                    <div class="flex-grow">
                                        <div class="flex justify-between items-start mb-2">
                                            <h3 class="text-lg font-semibold text-gray-700"><?php echo htmlspecialchars($election['title']); ?></h3>
                                            <div class="flex gap-2">
                                                <button onclick="showEditElectionModal(<?php echo $election['election_id']; ?>, '<?php echo htmlspecialchars($election['title']); ?>', '<?php echo htmlspecialchars($election['description']); ?>', '<?php echo htmlspecialchars($election['election_date']); ?>', '<?php echo htmlspecialchars($election['picture']); ?>')" class="text-blue-500 hover:underline focus:outline-none"><i class="fas fa-edit"></i></button>
                                                <form action="" method="POST" style="display:inline;">
                                                    <input type="hidden" name="election_id" value="<?php echo $election['election_id']; ?>">
                                                    <button type="submit" name="delete_election" class="text-red-500 hover:underline focus:outline-none" onclick="return confirm('Are you sure you want to delete this election?')"><i class="fas fa-trash-alt"></i></button>
                                                </form>
                                            </div>
                                        </div>
                                        <p class="text-gray-600 text-sm"><?php echo nl2br(htmlspecialchars($election['description'])); ?></p>
                                        <p class="text-gray-500 text-sm">Date: <?php echo htmlspecialchars($election['election_date']); ?></p>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <div id="addAnnouncementModal" class="modal">
                <div class="modal-content">
                    <span class="close-button" onclick="closeModal('addAnnouncementModal')">&times;</span>
                    <h2 class="text-xl font-semibold mb-4">Add New Announcement</h2>
                    <form action="" method="POST">
                        <div class="form-group">
                            <label for="title">Title</label>
                            <input type="text" id="title" name="title" class="w-full px-3 py-2 border rounded shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500" required>
                        </div>
                        <div class="form-group">
                            <label for="content">Content</label>
                            <textarea id="content" name="content" rows="4" class="w-full px-3 py-2 border rounded shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500" required></textarea>
                        </div>
                        <div class="form-actions flex justify-end gap-2">
                            <button type="submit" name="add_announcement" class="btn-primary">Add Announcement</button>
                            <button type="button" class="btn-secondary" onclick="closeModal('addAnnouncementModal')">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="editAnnouncementModal" class="modal">
                <div class="modal-content">
                    <span class="close-button" onclick="closeModal('editAnnouncementModal')">&times;</span>
                    <h2 class="text-xl font-semibold mb-4">Edit Announcement</h2>
                    <form action="" method="POST">
                        <input type="hidden" name="announcement_id" id="edit_announcement_id">
                        <div class="form-group">
                            <label for="edit_title">Title</label>
                            <input type="text" id="edit_title" name="title" class="w-full px-3 py-2 border rounded shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_content">Content</label>
                            <textarea id="edit_content" name="content" rows="4" class="w-full px-3 py-2 border rounded shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500" required></textarea>
                        </div>
                        <div class="form-actions flex justify-end gap-2">
                            <button type="submit" name="edit_announcement" class="btn-primary">Update Announcement</button>
                            <button type="button" class="btn-secondary" onclick="closeModal('editAnnouncementModal')">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="addElectionModal" class="modal">
                <div class="modal-content">
                    <span class="close-button" onclick="closeModal('addElectionModal')">&times;</span>
                    <h2 class="text-xl font-semibold mb-4">Add New Election</h2>
                    <form action="" method="POST" enctype="multipart/form-data">
                        <div class="form-group">
                            <label for="election_title">Title</label>
                            <input type="text" id="election_title" name="election_title" class="w-full px-3 py-2 border rounded shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500" required>
                        </div>
                        <div class="form-group">
                            <label for="election_description">Description</label>
                            <textarea id="election_description" name="election_description" rows="4" class="w-full px-3 py-2 border rounded shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500" required></textarea>
                        </div>
                        <div class="form-group">
                            <label for="election_date">Election Date & Time</label>
                            <input type="datetime-local" id="election_date" name="election_date" class="w-full px-3 py-2 border rounded shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500" required>
                        </div>
                        <div class="form-group">
                            <label for="election_image">Image</label>
                            <input type="file" id="election_image" name="election_image" accept="image/*" class="w-full px-3 py-2 border rounded shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500" required>
                        </div>
                        <div class="form-actions flex justify-end gap-2">
                            <button type="submit" name="add_election" class="btn-primary">Add Election</button>
                            <button type="button" class="btn-secondary" onclick="closeModal('addElectionModal')">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="editElectionModal" class="modal">
                <div class="modal-content">
                    <span class="close-button" onclick="closeModal('editElectionModal')">&times;</span>
                    <h2 class="text-xl font-semibold mb-4">Edit Election</h2>
                    <form action="" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="election_id" id="edit_election_id">
                        <div class="form-group">
                            <label for="edit_election_title">Title</label>
                            <input type="text" id="edit_election_title" name="election_title" class="w-full px-3 py-2 border rounded shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_election_description">Description</label>
                            <textarea id="edit_election_description" name="election_description" rows="4" class="w-full px-3 py-2 border rounded shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500" required></textarea>
                        </div>
                        <div class="form-group">
                            <label for="edit_election_date">Election Date & Time</label>
                            <input type="datetime-local" id="edit_election_date" name="election_date" class="w-full px-3 py-2 border rounded shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_election_image">Image (Optional)</label>
                            <input type="file" id="edit_election_image" name="election_image" accept="image/*" class="w-full px-3 py-2 border rounded shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                            <div id="current_election_image_preview" class="mt-2">
                                <?php if (!empty($elections)): ?>
                                    <?php foreach ($elections as $election): ?>
                                        <?php if (isset($_POST['edit_election']) && $_POST['election_id'] == $election['election_id'] && !empty($election['picture'])): ?>
                                            <img src="<?php echo htmlspecialchars($election['picture']); ?>" alt="Current Election Image" class="election-image">
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="form-actions flex justify-end gap-2">
                            <button type="submit" name="edit_election" class="btn-primary">Update Election</button>
                            <button type="button" class="btn-secondary" onclick="closeModal('editElectionModal')">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
        <script>
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('main-content');
            const dropdown = document.getElementById('dropdown');
            const addAnnouncementModal = document.getElementById('addAnnouncementModal');
            const editAnnouncementModal = document.getElementById('editAnnouncementModal');
            const addElectionModal = document.getElementById('addElectionModal');
            const editElectionModal = document.getElementById('editElectionModal');
            const currentElectionImagePreview = document.getElementById('current_election_image_preview');

            

            function showAddAnnouncementModal() {
                addAnnouncementModal.style.display = "block";
            }

            function showEditAnnouncementModal(id, title, content) {
                document.getElementById('edit_announcement_id').value = id;
                document.getElementById('edit_title').value = title;
                document.getElementById('edit_content').value = content;
                editAnnouncementModal.style.display = "block";
            }

            function showAddElectionModal() {
                addElectionModal.style.display = "block";
            }

            function showEditElectionModal(id, title, description, date, imagePath) {
                document.getElementById('edit_election_id').value = id;
                document.getElementById('edit_election_title').value = title;
                document.getElementById('edit_election_description').value = description;
                document.getElementById('edit_election_date').value = date;

                currentElectionImagePreview.innerHTML = '';
                if (imagePath) {
                    const img = document.createElement('img');
                    img.src = imagePath;
                    img.alt = 'Current Election Image';
                    img.classList.add('election-image');
                    currentElectionImagePreview.appendChild(img);
                }

                editElectionModal.style.display = "block";
            }

            function closeModal(modalId) {
                document.getElementById(modalId).style.display = "none";
            }

            window.onclick = function(event) {
                if (event.target == addAnnouncementModal) {
                    closeModal('addAnnouncementModal');
                }
                if (event.target == editAnnouncementModal) {
                    closeModal('editAnnouncementModal');
                }
                if (event.target == addElectionModal) {
                    closeModal('addElectionModal');
                }
                if (event.target == editElectionModal) {
                    closeModal('editElectionModal');
                }
            }
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