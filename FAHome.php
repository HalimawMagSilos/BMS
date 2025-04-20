<?php
session_start();
require 'connection.php';

// Check if the user is logged in
if (!isset($_SESSION['user_id'])|| $_SESSION['role'] !== 'barangay_official') {
    header('Location: LoginForm.php'); // Redirect to login page if not logged in
    exit;
}
// Fetch announcements
$announcements = [];
try {
    $stmt = $pdo->query("SELECT title, content, created_at FROM Announcements ORDER BY created_at DESC");
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Fetch elections with picture
$elections = [];
try {
    $stmt = $pdo->query("SELECT title, description, election_date, picture FROM Elections ORDER BY election_date ASC");
    $elections = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
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

        .election-image-container {
            width: 80px;
            height: 80px;
            margin-right: 10px;
            border-radius: 0.25rem;
            box-shadow: 0 1px 3px rgba(0,0,0,.1), 0 1px 2px -1px rgba(0,0,0,.05);
            overflow: hidden; /* Clip the image to the container */
            cursor: pointer; /* Indicate it's clickable */
        }

        .election-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block; /* Remove extra space below inline images */
        }

        /* Modal for displaying the full image */
        .image-modal {
            display: none;
            position: fixed;
            z-index: 100;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.9); /* Black w/ opacity */
        }

        .image-modal-content {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            max-width: 90%;
            max-height: 90%;
            display: block;
        }

        .image-modal-close {
            position: absolute;
            top: 15px;
            right: 35px;
            color: #f1f1f1;
            font-size: 40px;
            font-weight: bold;
            transition: 0.3s;
            cursor: pointer;
        }

        .image-modal-close:hover,
        .image-modal-close:focus {
            color: #bbb;
            text-decoration: none;
            cursor: pointer;
        }
    </style>
</head>
<body class="bg-gray-100 flex">
    <aside id="sidebar" class="sidebar bg-green-500 text-white w-64 min-h-screen p-6 fixed inset-y-0 left-0 transform -translate-x-full overflow-y-auto">
        <div class="text-center mb-8">
            <img alt="Barangay Clearance Department Logo" class="mx-auto mb-4" height="100" src="https://storage.googleapis.com/a1aa/image/FIM565KosH2Eo5Lqps2r-iwpSdYyPwD4FcVD0OtHXMk.jpg" width="100"/>
            <h1 class="text-3xl font-bold">Financial Assistance Dept</h1>
        </div>
        <nav>
            <ul class="space-y-6">
                <li><a href="FAHome.php" class="block py-3 px-4 rounded hover:bg-green-600 text-lg">Home</a></li>
                <li><a href="FAProfile.php" class="block py-3 px-4 rounded hover:bg-green-600 text-lg">Profile</a></li>
                <li>
                    <a href="FAApplication.php" class="block py-3 px-4 rounded hover:bg-green-600 text-lg">Financial Assistance Applications</a>
                </li>
                <li><a href="FASettings.php" class="block py-3 px-4 rounded hover:bg-green-600 text-lg">Settings</a></li>
                <li><a href="logout.php" class="block py-3 px-4 rounded hover:bg-green-600 text-lg">Logout</a></li>
            </ul>
        </nav>
    </aside>
    <main id="main-content" class="main-content flex-grow p-8 ml-0">
        <div class="flex justify-between items-center mb-8">
            <h2 class="text-3xl font-bold">Home</h2>
            <button class="text-green-500 focus:outline-none" onclick="toggleSidebar()">
                <i class="fas fa-bars text-2xl"></i>
            </button>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
            <div class="bg-white p-8 rounded-lg shadow-md col-span-1 md:col-span-2 lg:col-span-3">
                <h2 class="text-2xl font-bold mb-6">Announcements</h2>
                <ul>
                    <?php if (empty($announcements)): ?>
                        <li class="mb-4"><p class="text-gray-700 text-lg">No announcements yet.</p></li>
                    <?php else: ?>
                        <?php foreach ($announcements as $announcement): ?>
                            <li class="mb-4">
                                <h3 class="font-semibold text-xl"><?php echo htmlspecialchars($announcement['title']); ?></h3>
                                <p class="text-gray-700 text-lg"><?php echo htmlspecialchars($announcement['content']); ?></p>
                                <p class="text-gray-500 text-sm mt-1">Posted on: <?php echo date('F j, Y h:i A', strtotime($announcement['created_at'])); ?></p>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>
            <div class="bg-white p-8 rounded-lg shadow-md col-span-1 md:col-span-2 lg:col-span-3">
                <h2 class="text-2xl font-bold mb-6">Elections</h2>
                <ul>
                    <?php if (empty($elections)): ?>
                        <li class="mb-4"><p class="text-gray-700 text-lg">No elections scheduled yet.</p></li>
                    <?php else: ?>
                        <?php foreach ($elections as $election): ?>
                            <li class="mb-4 flex items-center">
                                <div class="election-image-container" onclick="showImageModal('<?php echo htmlspecialchars($election['picture']); ?>')">
                                    <?php if (!empty($election['picture'])): ?>
                                        <img src="<?php echo htmlspecialchars($election['picture']); ?>" alt="Election Picture" class="election-image">
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <h3 class="font-semibold text-xl"><?php echo htmlspecialchars($election['title']); ?></h3>
                                    <p class="text-gray-700 text-lg"><?php echo htmlspecialchars($election['description']); ?>. Date: <?php echo htmlspecialchars($election['election_date']); ?></p>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

        <div id="imageModal" class="image-modal" onclick="closeImageModal()">
            <span class="image-modal-close">&times;</span>
            <img class="image-modal-content" id="modalImage">
        </div>
    </main>
    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('main-content');
            sidebar.classList.toggle('-translate-x-full');
            mainContent.classList.toggle('ml-64');
        }

        // Function to show the image modal
        function showImageModal(imageSrc) {
            const modal = document.getElementById("imageModal");
            const modalImg = document.getElementById("modalImage");
            modal.style.display = "block";
            modalImg.src = imageSrc;
        }

        // Function to close the image modal
        function closeImageModal() {
            const modal = document.getElementById("imageModal");
            modal.style.display = "none";
        }
    </script>
</body>
</html>