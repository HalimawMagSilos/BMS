<?php
session_start();
require 'connection.php';
require 'PHPMAILER/src/PHPMailer.php';
require 'PHPMAILER/src/SMTP.php';
require 'PHPMAILER/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Check if admin is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: LoginForm.php");
    exit();
}

// Function to fetch all users (residents and barangay officials) for the initial sidebar list
function fetchAllUsers($pdo) {
    try {
        $stmt = $pdo->query("SELECT u.user_id, p.first_name, p.middle_name, p.last_name, p.suffix, p.profile_picture, u.role, u.email
                                    FROM Users u
                                    JOIN Profiles p ON u.user_id = p.user_id
                                    WHERE u.role IN ('resident', 'barangay_official')
                                    ORDER BY u.role, p.last_name, p.first_name, p.middle_name");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        die("Database error fetching users: " . $e->getMessage());
    }
}

// Function to fetch user details by ID
function fetchUserDetails($pdo, $userId) {
    try {
        $stmt = $pdo->prepare("SELECT p.first_name, p.middle_name, p.last_name, p.suffix, p.profile_picture, u.role, u.email
                                    FROM Profiles p JOIN Users u ON p.user_id = u.user_id
                                    WHERE p.user_id = :user_id AND u.role IN ('resident', 'barangay_official')");
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return null;
    }
}

// Function to fetch messages sent by a specific user (resident or barangay official)
function fetchUserMessages($pdo, $userId) {
    try {
        $stmt = $pdo->prepare("SELECT rm.message_id, rm.message_content, rm.created_at, rm.status, rm.attached_file,
                                        (SELECT CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name, p.suffix) FROM Profiles p WHERE p.user_id = rm.user_id) AS sender_name,
                                        (SELECT p.profile_picture FROM Profiles p WHERE p.user_id = rm.user_id) AS sender_profile_picture,
                                        rm.user_id AS sender_id
                                   FROM Resident_Messages rm
                                   WHERE rm.user_id = :user_id
                                   ORDER BY created_at ASC");
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        die("Database error fetching messages: " . $e->getMessage());
    }
}

// Function to update message status and fetch user email
function updateMessageStatusAndGetEmail($pdo, $messageId, $newStatus) {
    try {
        $pdo->beginTransaction();
        $stmtUpdate = $pdo->prepare("UPDATE Resident_Messages SET status = :status WHERE message_id = :message_id");
        $stmtUpdate->bindParam(':status', $newStatus, PDO::PARAM_STR);
        $stmtUpdate->bindParam(':message_id', $messageId, PDO::PARAM_INT);
        $stmtUpdate->execute();

        $stmtEmail = $pdo->prepare("SELECT u.email FROM Resident_Messages rm
                                    JOIN Users u ON rm.user_id = u.user_id
                                    WHERE rm.message_id = :message_id");
        $stmtEmail->bindParam(':message_id', $messageId, PDO::PARAM_INT);
        $stmtEmail->execute();
        $userEmail = $stmtEmail->fetchColumn();

        $pdo->commit();
        return $userEmail;
    } catch (PDOException $e) {
        $pdo->rollBack();
        die("Database error updating status and fetching email: " . $e->getMessage());
    }
}

// Function to send email notification using PHPMailer
function sendStatusUpdateEmail($userEmail, $newStatus) {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'ellema.darrell17@gmail.com'; // Replace with your email
        $mail->Password = 'mvwq ilib rppn ftjk'; // Replace with your email password
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;                       //TCP port to connect to, use 465 for `PHPMailer::ENCRYPTION_SMTPS` above

        //Recipients
        $mail->setFrom('ellema.darrell17@gmail.com', 'Barangay Management Admin');
        $mail->addAddress($userEmail);                               //Add a recipient

        //Content
        $mail->isHTML(true);                                  //Set email format to HTML
        $mail->Subject = 'Message Status Update';
        $mail->Body    = '<p>Your message status has been updated to: <strong>' . ucfirst($newStatus) . '</strong>.</p>';
        $mail->AltBody = 'Your message status has been updated to: ' . ucfirst($newStatus) . '.';

        $mail->send();
        // echo 'Message has been sent';
    } catch (Exception $e) {
        echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
    }
}

// Fetch resident and barangay official users for the initial sidebar
$users = fetchAllUsers($pdo);

// Initialize variables
$selectedUserId = null;
$messages = [];
$selectedUserInfo = null;
$searchActive = false;

// Handle user selection
if (isset($_GET['user_id']) && is_numeric($_GET['user_id'])) {
    $selectedUserId = intval($_GET['user_id']);
    $selectedUserInfo = fetchUserDetails($pdo, $selectedUserId);
    if ($selectedUserInfo) {
        $messages = fetchUserMessages($pdo, $selectedUserId);
    }
    $searchActive = false;
}

// Handle updating message status
if (isset($_POST['update_status']) && isset($_POST['message_id']) && is_numeric($_POST['message_id']) && isset($_POST['status'])) {
    $messageIdToUpdate = intval($_POST['message_id']);
    $newStatus = $_POST['status'];
    $allowedStatuses = ['pending', 'completed', 'rejected'];
    if (in_array($newStatus, $allowedStatuses)) {
        $userEmail = updateMessageStatusAndGetEmail($pdo, $messageIdToUpdate, $newStatus);
        if ($userEmail) {
            sendStatusUpdateEmail($userEmail, $newStatus);
        }
        $redirectUrl = isset($_GET['user_id']) ? "AdminMessages.php?user_id=" . $_GET['user_id'] : "AdminMessages.php";
        header("Location: " . $redirectUrl);
        exit();
    }
}

// Handle search for users
$searchResults = [];
if (isset($_GET['search_query']) && !empty($_GET['search_query'])) {
    $searchQuery = trim($_GET['search_query']);
    try {
        $stmt = $pdo->prepare("SELECT u.user_id, p.first_name, p.middle_name, p.last_name, p.suffix, p.profile_picture, u.role, u.email
                                    FROM Users u
                                    JOIN Profiles p ON u.user_id = p.user_id
                                    WHERE u.role IN ('resident', 'barangay_official') AND CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name, p.suffix) LIKE :search_term
                                    ORDER BY p.last_name, p.first_name, p.middle_name");
        $searchTerm = "%" . $searchQuery . "%";
        $stmt->bindParam(':search_term', $searchTerm, PDO::PARAM_STR);
        $stmt->execute();
        $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $searchActive = true;
    } catch (PDOException $e) {
        die("Database error during search: " . $e->getMessage());
    }
}

// Function to generate the user list HTML
function generateUserList($users, $selectedUserId, $searchResults, $searchActive, $searchQuery = '') {
    $html = '<div class="p-3">';
    $html .= '<h3 class="text-lg font-semibold text-gray-700 mb-2">Users</h3>';
    $html .= '<div class="mb-4">';
    $html .= '<form method="get">';
    $html .= '<input type="text" name="search_query" placeholder="Search users..." class="w-full rounded-md border border-gray-300 p-2 focus:outline-none focus:ring-2 focus:ring-purple-500" value="' . htmlspecialchars($searchQuery) . '">';
    $html .= '</form>';
    $html .= '</div>';
    $html .= '<ul>';

    if ($searchActive && !empty($searchResults)):
        $html .= '<li class="p-2 text-gray-500 font-semibold">Search Results:</li>';
        if (empty($searchResults)):
            $html .= '<li class="p-2 text-gray-500">No matching users found.</li>';
        else:
            foreach ($searchResults as $result):
                $fullNameResult = trim($result['first_name'] . ' ' . (empty($result['middle_name']) ? '' : $result['middle_name'] . ' ') . $result['last_name'] . (empty($result['suffix']) ? '' : ' ' . $result['suffix']));
                $isSelected = ($selectedUserId === $result['user_id']) ? 'bg-gray-100' : '';
                $profilePicturesResult = explode('IMAGE/', $result['profile_picture']);
                $profilePicResult = '';
                foreach ($profilePicturesResult as $pic) {
                    if (!empty(trim($pic))) {
                        $profilePicResult = 'IMAGE/' . trim($pic);
                        break;
                    }
                }
                if (empty($profilePicResult)) {
                    $profilePicResult = 'IMAGE/default_profile.png';
                }
                $html .= '<li class="cursor-pointer hover:bg-gray-100 p-2 ' . $isSelected . '">';
                $html .= '<a href="AdminMessages.php?user_id=' . $result['user_id'] . '&search_query=' . htmlspecialchars($searchQuery) . '" class="block focus:outline-none">';
                $html .= '<div class="flex items-center space-x-2">';
                $html .= '<img src="' . htmlspecialchars($profilePicResult) . '" alt="' . htmlspecialchars($fullNameResult) . '" class="w-8 h-8 rounded-full object-cover">';
                $html .= '<span>' . htmlspecialchars($fullNameResult) . '</span>';
                $html .= '<span class="text-xs text-gray-500 ml-1">(' . htmlspecialchars(ucfirst($result['role'])) . ')</span>';
                $html .= '</div>';
                $html .= '</a>';
                $html .= '</li>';
            endforeach;
        endif;
        $html .= '<li class="p-2 mt-2 text-gray-500"><a href="AdminMessages.php" class="text-purple-500 hover:underline">Show All Users</a></li>';
    else:
        if (empty($users)):
            $html .= '<li class="p-2 text-gray-500">No users found.</li>';
        else:
            foreach ($users as $user):
                $fullName = trim($user['first_name'] . ' ' . (empty($user['middle_name']) ? '' : $user['middle_name'] . ' ') . $user['last_name'] . (empty($user['suffix']) ? '' : ' ' . $user['suffix']));
                $isSelected = ($selectedUserId === $user['user_id']) ? 'bg-gray-100' : '';
                $profilePictures = explode('IMAGE/', $user['profile_picture']);
                $profilePic = '';
                foreach ($profilePictures as $pic) {
                    if (!empty(trim($pic))) {
                        $profilePic = 'IMAGE/' . trim($pic);
                        break;
                    }
                }
                if (empty($profilePic)) {
                    $profilePic = 'IMAGE/default_profile.png';
                }
                $html .= '<li class="cursor-pointer hover:bg-gray-100 p-2 ' . $isSelected . '">';
                $html .= '<a href="AdminMessages.php?user_id=' . $user['user_id'] . '" class="block focus:outline-none">';
                $html .= '<div class="flex items-center space-x-2">';
                $html .= '<img src="' . htmlspecialchars($profilePic) . '" alt="' . htmlspecialchars($fullName) . '" class="w-8 h-8 rounded-full object-cover">';
                $html .= '<span>' . htmlspecialchars($fullName) . '</span>';
                $html .= '<span class="text-xs text-gray-500 ml-1">(' . htmlspecialchars(ucfirst($user['role'])) . ')</span>';
                $html .= '</div>';
                $html .= '</a>';
                $html .= '</li>';
            endforeach;
        endif;
    endif;

    $html .= '</ul>';
    $html .= '</div>';
    return $html;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Admin - User Messages</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css"
        rel="stylesheet"
    />
    <link
        href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap"
        rel="stylesheet"
    />
    <style>
        /* Modern scrollbar styling */
        .overflow-y-auto::-webkit-scrollbar {
            width: 8px;
        }

        .overflow-y-auto::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        .overflow-y-auto::-webkit-scrollbar-thumb {
            background: #a0aec0;
            border-radius: 4px;
        }

        .overflow-y-auto::-webkit-scrollbar-thumb:hover {
            background: #718096;
        }
    </style>
</head>
<body class="bg-gray-100 flex font-roboto">
    <aside
        id="sidebar"
        class="sidebar bg-purple-500 text-white w-64 min-h-screen p-6 fixed inset-y-0 left-0 transform -translate-x-full overflow-y-auto z-20 transition-transform duration-300 ease-in-out"
        aria-label="Sidebar Navigation"
    >
        <div class="text-center mb-8">
            <img
                alt="Admin Dashboard Logo"
                class="mx-auto mb-4"
                height="100"
                width="100"
                src="https://storage.googleapis.com/a1aa/image/oPytm4X-nQDT4FEekiF0fx9TqXQnTYbvl6Dyuau22Ho.jpg"
            />
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

    <main
        id="main-content"
        class="main-content flex-grow p-8 ml-0 transition-margin duration-300 ease-in-out"
        tabindex="-1"
    >
        <div class="flex justify-between items-center mb-8">
            <h2 class="text-3xl font-bold">User Messages</h2>
            <button
                aria-label="Toggle sidebar"
                class="text-purple-500 focus:outline-none focus:ring-2 focus:ring-purple-600 rounded"
                onclick="toggleSidebar()"
            >
                <i class="fas fa-bars text-2xl"></i>
            </button>
        </div>

        <div class="flex h-[calc(100vh-8rem)] rounded-lg shadow bg-white overflow-hidden">
            <aside
                id="usersList"
                class="w-72 border-r border-gray-200 overflow-y-auto bg-gray-50"
                aria-label="User list"
            >
                <?php echo generateUserList($users, $selectedUserId, $searchResults, $searchActive, isset($_GET['search_query']) ? $_GET['search_query'] : ''); ?>
            </aside>

            <section
                class="flex flex-col flex-grow"
                aria-label="Chat area"
            >
                <header
                    id="chatHeader"
                    class="bg-purple-100 text-purple-900 font-semibold text-xl p-4 border-b border-gray-200 flex items-center space-x-2"
                >
                    <?php if ($selectedUserId && $selectedUserInfo): ?>
                        <?php
                        $profilePicturesHeader = explode('IMAGE/', $selectedUserInfo['profile_picture']);
                        $profilePicHeader = '';
                        foreach ($profilePicturesHeader as $pic) {
                            if (!empty(trim($pic))) {
                                $profilePicHeader = 'IMAGE/' . trim($pic);
                                break;
                            }
                        }
                        if (empty($profilePicHeader)) {
                            $profilePicHeader = 'IMAGE/default_profile.png';
                        }
                        ?>
                        <img src="<?php echo htmlspecialchars($profilePicHeader); ?>" alt="<?php echo htmlspecialchars(trim($selectedUserInfo['first_name'] . ' ' . (empty($selectedUserInfo['middle_name']) ? '' : $selectedUserInfo['middle_name'] . ' ') . $selectedUserInfo['last_name'] . (empty($selectedUserInfo['suffix']) ? '' : ' ' . $selectedUserInfo['suffix']))); ?>" class="w-8 h-8 rounded-full object-cover">
                        <span><?php echo htmlspecialchars(trim($selectedUserInfo['first_name'] . ' ' . (empty($selectedUserInfo['middle_name']) ? '' : $selectedUserInfo['middle_name'] . ' ') . $selectedUserInfo['last_name'] . (empty($selectedUserInfo['suffix']) ? '' : ' ' . $selectedUserInfo['suffix']))); ?> (<?php echo htmlspecialchars(ucfirst($selectedUserInfo['role'])); ?>)</span>
                    <?php else: ?>
                        <span>Select a user to view messages</span>
                    <?php endif; ?>
                </header>
                <div
                    id="chatMessages"
                    class="flex-grow p-4 overflow-y-auto space-y-4 bg-gray-50"
                    tabindex="0"
                    aria-live="polite"
                    aria-relevant="additions"
                >
                    <?php if ($messages): ?>
                        <?php foreach ($messages as $message): ?>
                            <div class="flex <?php echo ($message['sender_id'] == $selectedUserId) ? 'justify-start' : 'justify-end'; ?>">
                                <div class="rounded-lg shadow-sm p-3 <?php echo ($message['sender_id'] == $selectedUserId) ? 'bg-gray-200 text-gray-800' : 'bg-purple-200 text-purple-800'; ?> max-w-md">
                                    <p class="text-sm"><?php echo htmlspecialchars($message['message_content']); ?></p>
                                    <?php if (!empty($message['attached_file'])): ?>
                                        <p class="mt-1 text-sm text-blue-500">
                                            <a href="FILES/<?php echo htmlspecialchars($message['attached_file']); ?>" target="_blank">
                                                <i class="fas fa-paperclip mr-1"></i> View Attachment
                                            </a>
                                        </p>
                                    <?php endif; ?>
                                    <div class="flex justify-between items-center mt-1">
                                        <p class="text-xs text-gray-500"><?php echo date('F j, Y, g:i a', strtotime($message['created_at'])); ?></p>
                                        <?php if ($message['sender_id'] == $selectedUserId): ?>
                                            <form method="post" class="inline-block">
                                                <input type="hidden" name="message_id" value="<?php echo $message['message_id']; ?>">
                                                <select name="status" class="text-xs border border-gray-300 rounded">
                                                    <option value="pending" <?php echo ($message['status'] === 'pending') ? 'selected' : ''; ?>>Pending</option>
                                                    <option value="completed" <?php echo ($message['status'] === 'completed') ? 'selected' : ''; ?>>Completed</option>
                                                    <option value="rejected" <?php echo ($message['status'] === 'rejected') ? 'selected' : ''; ?>>Rejected</option>
                                                </select>
                                                <button type="submit" name="update_status" class="ml-2 px-2 py-1 bg-purple-500 text-white rounded text-xs focus:outline-none">Update</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div id="scrollBottom"></div>
                    <?php elseif ($selectedUserId): ?>
                        <p class="text-gray-500 text-center">No messages from this user yet.</p>
                    <?php else: ?>
                        <p class="text-gray-500 text-center">Select a user to view their messages.</p>
                    <?php endif; ?>
                </div>
                <?php if ($selectedUserId): ?>
                <div class="p-4 border-t border-gray-200 bg-purple-100 text-center">
                    <p class="text-sm text-gray-600">You can update the status of messages sent by this user.</p>
                </div>
                <?php else: ?>
                <div class="p-4 border-t border-gray-200 bg-purple-100 text-center">
                    <p class="text-sm text-gray-600">Select a user to view and manage their messages.</p>
                </div>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <script>
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('main-content');
        const dropdown = document.getElementById('dropdown');
        const chatMessages = document.getElementById('chatMessages');

        function toggleSidebar() {
            sidebar.classList.toggle('-translate-x-full');
            mainContent.classList.toggle('ml-64');
        }

        function toggleDropdown() {
            dropdown.classList.toggle('hidden');
        }

        function scrollToBottom() {
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }

        if (chatMessages) {
            setTimeout(scrollToBottom, 100); // Slight delay to ensure elements are rendered
        }
    </script>
</body>
</html>