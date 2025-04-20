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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'check_reference') {
    header('Content-Type: application/json');
    $referenceNumber = $_POST['ref'] ?? '';

    $stmt = $pdo->prepare("SELECT amount_fee AS amount, purpose FROM Payment_Process WHERE reference_number = ? AND purpose = 'barangay_clearance'");
    $stmt->execute([$referenceNumber]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result) {
        echo json_encode($result);
    } else {
        echo json_encode(['exists' => false]);
    }
    exit;
}

// Check if admin is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: LoginForm.php");
    exit();
}

// Function to fetch barangay clearance applications based on their status
function fetchBarangayClearanceApplications($pdo, $status) {
    $stmt = $pdo->prepare("
        SELECT
            bc.application_id,
            bc.user_id,
            bc.purpose,
            bc.reference_number,
            bc.status,
            bc.downloadable_file,
            ud.document_id AS id_document,
            ud.document_file AS id_document_path,
            p.first_name,
            p.middle_name,
            p.last_name,
            p.suffix,
            p.address,
            n.note,
            u.email,
            (SELECT GROUP_CONCAT(CONCAT(c.complaint_details, ' (Fee: ', c.fee, ')') SEPARATOR '; ') FROM Complaints c WHERE c.resident_id = u.user_id AND c.status NOT IN ('resolved', 'dismissed')) AS complaints
        FROM
            Barangay_Clearance_Applications bc
        LEFT JOIN
            Users u ON bc.user_id = u.user_id
        LEFT JOIN
            Profiles p ON u.user_id = p.user_id
        LEFT JOIN
            Notes n ON bc.application_id = n.application_id
        LEFT JOIN
            User_ID_Documents ud ON bc.id_document = ud.document_id
        WHERE
            bc.status = ?
    ");
    $stmt->execute([$status]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
// Fetch applications
$pendingApplications = fetchBarangayClearanceApplications($pdo, 'pending');
$approvedApplications = fetchBarangayClearanceApplications($pdo, 'approved');

// Handle review form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['application_id'])) {
    $applicationId = $_POST['application_id'];
    $status = $_POST['status'];
    $note = $_POST['note'];

    try {
        $pdo->beginTransaction();

        $updateStmt = $pdo->prepare("UPDATE Barangay_Clearance_Applications SET status = ? WHERE application_id = ?");
        $updateStmt->execute([$status, $applicationId]);

        // Check if a note already exists for this application
        $checkNoteStmt = $pdo->prepare("SELECT COUNT(*) FROM Notes WHERE application_id = ?");
        $checkNoteStmt->execute([$applicationId]);
        $noteExists = $checkNoteStmt->fetchColumn();

        if ($noteExists > 0) {
            // Update existing note
            $noteStmt = $pdo->prepare("UPDATE Notes SET note = ? WHERE application_id = ?");
            $noteStmt->execute([$note, $applicationId]);
        } else {
            // Insert new note
            $noteStmt = $pdo->prepare("INSERT INTO Notes (application_id, staff_id, note) VALUES (?, ?, ?)");
            $noteStmt->execute([$applicationId, $_SESSION['user_id'], $note]);
        }

        // Generate PDF if status is approved
        if ($status === 'approved') {
            generatePDF($applicationId);
        }

        $pdo->commit();

        // Log the action
        $admin_id = $_SESSION['user_id'];
        $action = "Updated Barangay Clearance Application (ID: $applicationId): Status changed to $status, Note updated.";
        $logStmt = $pdo->prepare("INSERT INTO System_Logs (user_id, action) VALUES (?, ?)");
        $logStmt->execute([$admin_id, $action]);

        // Send email notification
        $applicationDetails = array_values(array_filter(array_merge($pendingApplications, $approvedApplications), function ($app) use ($applicationId) {
            return $app['application_id'] == $applicationId;
        }))[0] ?? null;

        if ($applicationDetails) {
            $userEmail = $applicationDetails['email'];
            $subject = "Barangay Clearance Application Update";
            $message = "Your Barangay Clearance Application with ID: $applicationId has been updated. Please check your dashboard for updates.";
            sendEmail($userEmail, $subject, $message);
        }

        // Refresh applications after update.
        $pendingApplications = fetchBarangayClearanceApplications($pdo, 'pending');
        $approvedApplications = fetchBarangayClearanceApplications($pdo, 'approved');

        // Redirect to the same page to see the updated data
        header('Location: AdminBarangayClearance.php');
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo "<script>alert('Error updating application: " . htmlspecialchars($e->getMessage()) . "');</script>";
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<script>alert('" . htmlspecialchars($e->getMessage()) . "');</script>";
    }
}

// Handle search functionality
$searchResultsPending = $pendingApplications; // Default to all pending applications
$searchResultsApproved = $approvedApplications; // Default to all approved applications

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['search'])) {
    $searchFirstName = trim($_POST['search_first_name']);
    $searchLastName = trim($_POST['search_last_name']);
    $searchMiddleName = trim($_POST['search_middle_name']);
    $searchSuffix = trim($_POST['search_suffix']);

    // Filter pending applications based on search criteria
    $searchResultsPending = array_filter($pendingApplications, function ($application) use ($searchFirstName, $searchLastName, $searchMiddleName, $searchSuffix) {
        return (stripos($application['first_name'], $searchFirstName) !== false) &&
            (stripos($application['last_name'], $searchLastName) !== false) &&
            (empty($searchMiddleName) || (stripos($application['middle_name'], $searchMiddleName) !== false)) &&
            (empty($searchSuffix) || (stripos($application['suffix'], $searchSuffix) !== false));
    });

    // Filter approved applications based on search criteria
    $searchResultsApproved = array_filter($approvedApplications, function ($application) use ($searchFirstName, $searchLastName, $searchMiddleName, $searchSuffix) {
        return (stripos($application['first_name'], $searchFirstName) !== false) &&
            (stripos($application['last_name'], $searchLastName) !== false) &&
            (empty($searchMiddleName) || (stripos($application['middle_name'], $searchMiddleName) !== false)) &&
            (empty($searchSuffix) || (stripos($application['suffix'], $searchSuffix) !== false));
    });
}

// Function to generate PDF
function generatePDF($applicationId) {
    require 'fpdf/fpdf.php'; // Adjust the path as necessary

    global $pdo;
    $stmt = $pdo->prepare("
        SELECT
            bc.*,
            p.first_name,
            p.middle_name,
            p.last_name,
            p.suffix,
            p.address
        FROM
            Barangay_Clearance_Applications bc
        LEFT JOIN
            Users u ON bc.user_id = u.user_id
        LEFT JOIN
            Profiles p ON u.user_id = p.user_id
        WHERE
            bc.application_id = ?
    ");
    $stmt->execute([$applicationId]);
    $application = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($application) {
        $pdf = new FPDF();
        $pdf->AddPage();

        // Set the title
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, 'Barangay Clearance', 0, 1, 'C');

        // Add Barangay details
        $pdf->SetFont('Arial', 'I', 12);
        $pdf->Cell(0, 10, 'Barangay San Agustin', 0, 1, 'C');
        $pdf->Cell(0, 10, 'Quezon City, Philippines', 0, 1, 'C');
        $pdf->Ln(10); // Add a line break

        // Set the content font
        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(0, 10, "This is to certify that:", 0, 1);
        $pdf->Ln(5); // Add a line break

        // User details
        $pdf->Cell(0, 10, "Name: {$application['first_name']} {$application['middle_name']} {$application['last_name']} {$application['suffix']}", 0, 1);
        $pdf->Cell(0, 10, "Address: {$application['address']}", 0, 1); // Address included here
        $pdf->Cell(0, 10, "Purpose: {$application['purpose']}", 0, 1);
        $pdf->Cell(0, 10, "Reference Number: {$application['reference_number']}", 0, 1);
        $pdf->Cell(0, 10, "Status: {$application['status']}", 0, 1);
        $pdf->Ln(10); // Add a line break

        // Add a footer
        $pdf->Cell(0, 10, 'Issued this ' . date('jS') . ' day of ' . date('F, Y'), 0, 1, 'C');
        $pdf->Ln(20); // Add a line break

        // Signature line
        $pdf->Cell(0, 10, '__________________________', 0, 1, 'C');
        $pdf->Cell(0, 10, 'Barangay Captain', 0, 1, 'C');

        // Save the PDF
        $lastName = $application['last_name'];
        $pdfFilePath = 'FILES/BarangayClearance_' . $lastName . '.pdf';
        $pdf->Output('F', $pdfFilePath);

        // Update the downloadable file path in the database
        $updateStmt = $pdo->prepare("UPDATE Barangay_Clearance_Applications SET downloadable_file = ? WHERE application_id = ?");
        $updateStmt->execute([basename($pdfFilePath), $applicationId]);
    }
}

// Function to send email
function sendEmail($to, $subject, $message) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'ellema.darrell17@gmail.com'; // Replace with your email
        $mail->Password = 'mvwq ilib rppn ftjk'; // Replace with your email password
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;

        $mail->setFrom('ellema.darrell17@gmail.com', 'Barangay Management System');
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $message;

        $mail->send();
    } catch (Exception $e) {
        echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Barangay Clearance Department Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet" />
    <style>
        body {
            font-family: 'Roboto', sans-serif;
            background-color: #f3f4f6;
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .sidebar {
            transition: transform 0.3s ease-in-out;
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            width: 256px;
            transform: translateX(-100%);
            z-index: 100;
        }

        .sidebar.active {
            transform: translateX(0);
        }

        .main-content {
            transition: margin-left 0.3s ease-in-out;
            margin-left: 0;
            flex: 1;
            overflow-x: auto;
        }

        @media (min-width: 768px) {
            .main-content.active {
                margin-left: 256px;
            }
        }

        .table-container {
            overflow-x: auto;
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
            background-color: rgba(0, 0, 0, 0.4);
        }

        .modal-content {
            background-color: #fefefe;
            margin: 15% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 600px;
        }

        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
        }

        .close:hover,
        .close:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }

        #search-results ul {
            list-style: none;
            padding: 0;
        }

        #search-results li {
            border-bottom: 1px solid #ddd;
            padding: 10px 0;
        }

        #search-results li:last-child {
            border-bottom: none;
        }
    </style>
</head>

<body class="bg-gray-100">
    <div class="flex">
        <aside class="sidebar bg-purple-500 text-white w-64 min-h-screen p-6 fixed inset-y-0 left-0 transform -translate-x-full overflow-y-auto" id="sidebar">
            <div class="text-center mb-8">
                <img alt="Admin Dashboard Logo" class="mx-auto mb-4 rounded-full" height="100" src="https://storage.googleapis.com/a1aa/image/oPytm4X-nQDT4FEekiF0fx9TqXQnTYbvl6Dyuau22Ho.jpg" width="100" />
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
        <main id="main-content" class="main-content flex-grow p-8 ml-0">
        <div class="flex justify-between items-center mb-8">
            <h2 class="text-3xl font-bold">Barangay Clearance Applications</h2>
            <div class="flex space-x-2">
                <button class="text-purple-500 focus:outline-none" onclick="toggleSidebar()">
                    <i class="fas fa-bars text-2xl"></i>
                </button>
                <button class="text-purple-500 focus:outline-none" onclick="openSearchModal()">
                    <i class="fas fa-search text-2xl"></i>
                </button>
            </div>
        </div>
        <div class="bg-white p-8 rounded-lg shadow-md mb-8 table-container">
            <h2 class="text-2xl font-bold mb-6">Pending Applications</h2>
            <table class="min-w-full bg-white">
                <thead>
                    <tr>
                        <th class="py-2 px-4 border-b">Name</th>
                        <th class="py-2 px-4 border-b">Address</th>
                        <th class="py-2 px-4 border-b">Purpose</th>
                        <th class="py-2 px-4 border-b">Reference Number</th>
                        <th class="py-2 px-4 border-b">Status</th>
                        <th class="py-2 px-4 border-b">Note</th>
                        <th class="py-2 px-4 border-b">Complaints</th>
                        <th class="py-2 px-4 border-b">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($searchResultsPending as $application) : ?>
                        <tr>
                            <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($application['first_name'] . ' ' . ($application['middle_name'] ? $application['middle_name'] . ' ' : '') . $application['last_name'] . ' ' . ($application['suffix'] ? $application['suffix'] : '')); ?></td>
                            <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($application['address']); ?></td>
                            <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($application['purpose']); ?></td>
                            <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($application['reference_number']); ?></td>
                            <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($application['status']); ?></td>
                            <td class="py-2 px-4 border-b">
                                <textarea class="w-full px-2 py-1 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" readonly><?php echo htmlspecialchars($application['note'] ?? ''); ?></textarea>
                            </td>
                            <td class="py-2 px-4 border-b">
                                <textarea class="w-full px-2 py-1 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" readonly><?php echo htmlspecialchars($application['complaints'] ?? 'No complaints'); ?></textarea>
                            </td>
                            <td class="py-2 px-4 border-b">
                                <button class="bg-blue-500 text-white py-1 px-3 rounded-lg hover:bg-blue-600" onclick="openReviewModal('<?php echo htmlspecialchars($application['application_id']); ?>')">Review</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="bg-white p-8 rounded-lg shadow-md mb-8 table-container">
            <h2 class="text-2xl font-bold mb-6">Approved Applications</h2>
            <div class="max-h-64 overflow-y-auto">
                <ul class="space-y-4">
                    <?php foreach ($searchResultsApproved as $application) : ?>
                        <li class="p-4 bg-gray-100 rounded-lg shadow-md flex justify-between items-center">
                            <div>
                                <p><strong>Name:</strong> <?php echo htmlspecialchars($application['first_name'] . ' ' . ($application['middle_name'] ? $application['middle_name'] . ' ' : '') . $application['last_name'] . ' ' . ($application['suffix'] ? $application['suffix'] : '')); ?></p>
                                <p><strong>Address:</strong> <?php echo htmlspecialchars($application['address']); ?></p>
                                <p><strong>Purpose:</strong> <?php echo htmlspecialchars($application['purpose']); ?></p>
                                <p><strong>Reference Number:</strong> <?php echo htmlspecialchars($application['reference_number']); ?></p>
                                <p><strong>Status:</strong> <?php echo htmlspecialchars($application['status']); ?></p>
                                <p><strong>Note:</strong> <?php echo htmlspecialchars($application['note'] ?? 'No note provided.'); ?></p>
                                <p><strong>Complaints:</strong> <?php echo htmlspecialchars($application['complaints'] ?? 'No complaints'); ?></p>
                            </div>
                            <div>
                                <?php if (!empty($application['downloadable_file'])) : ?>
                                    <a href="FILES/<?php echo htmlspecialchars($application['downloadable_file']); ?>" class="bg-green-500 text-white py-1 px-3 rounded-lg hover:bg-green-600" download>Download</a>
                                <?php else : ?>
                                    <span class="text-red-500">No file available</span>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <div id="review-modal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeReviewModal()">&times;</span>
                <h2 class="text-2xl font-bold mb-6">Review Application</h2>
                <form id="review-form" method="POST">
                    <input type="hidden" name="application_id" id="modalApplicationId">
                    <input type="hidden" name="id_document" id="modalIdDocument">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="mb-4">
                            <label class="block text-gray-700 font-medium mb-2" for="review_name">Name</label>
                            <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" id="review_name" readonly type="text" value="" />
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-700 font-medium mb-2" for="review_address">Address</label>
                            <textarea class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" id="review_address" readonly></textarea>
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-700 font-medium mb-2" for="review_purpose">Purpose</label>
                            <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" id="review_purpose" readonly type="text" value="" />
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-700 font-medium mb-2" for="review_reference_number">Reference Number</label>
                            <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" id="review_reference_number" readonly type="text" value="" />
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-700 font-medium mb-2" for="review_status">Status</label>
                            <select class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" name="status" id="review_status">
                                <option value="pending">Pending</option>
                                <option value="approved">Approved</option>
                            </select>
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-700 font-medium mb-2" for="review_id_document">ID Document</label>
                            <a id="id_document_link" href="#" target="_blank" style="display:none;color:blue">View ID Document</a>
                            <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" id="review_id_document" readonly type="text" value="" />
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="block text-gray-700 font-medium mb-2" for="review_note">Note</label>
                        <textarea class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" name="note" id="review_note"></textarea>
                    </div>
                    <div class="mb-4">
                    <textarea class="w-full px-2 py-1 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" readonly><?php echo htmlspecialchars($application['complaints'] ?? 'No complaints'); ?></textarea>
                    </div>
                <div class="flex justify-between items-center mb-4">
                    <button type="button" id="verifyReferenceBtn" class="bg-blue-500 text-white py-2 px-4 rounded-lg hover:bg-blue-600">Verify Reference Number</button>
                </div>
                <div class="flex justify-end space-x-2">
                    <button class="bg-red-500 text-white py-2 px-4 rounded-lg hover:bg-red-600" type="button" onclick="closeReviewModal()">Cancel</button>
                    <button class="bg-green-500 text-white py-2 px-4 rounded-lg hover:bg-green-600" type="submit">Save</button>
                </div>
                </form>
            </div>
        </div>

        <div id="search-modal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeSearchModal()">&times;</span>
                <h2 class="text-2xl font-bold mb-6">Search Applications</h2>
                <form onsubmit="searchApplications(event)">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="mb-4">
                            <label class="block text-gray-700 font-medium mb-2" for="search_last_name">Last Name</label>
                            <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" id="search_last_name" type="text" placeholder="Enter last name">
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-700 font-medium mb-2" for="search_first_name">First Name</label>
                            <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" id="search_first_name" type="text" placeholder="Enter first name">
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-700 font-medium mb-2" for="search_middle_name">Middle Name</label>
                            <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" id="search_middle_name" type="text" placeholder="Enter middle name">
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-700 font-medium mb-2" for="search_suffix">Suffix</label>
                            <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" id="search_suffix" type="text" placeholder="Enter suffix">
                        </div>
                    </div>
                    <div class="flex justify-end space-x-2">
                        <button class="bg-red-500 text-white py-2 px-4 rounded-lg hover:bg-red-600" type="button" onclick="closeSearchModal()">Cancel</button>
                        <button class="bg-blue-500 text-white py-2 px-4 rounded-lg hover:bg-blue-600" type="submit">Search</button>
                    </div>
                </form>
                <div id="search-results" class="mt-4"></div>
            </div>
        </div>
    </main>
    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('main-content');
            sidebar.classList.toggle('active');
            mainContent.classList.toggle('active');
        }

        function openReviewModal(applicationId) {
    const application = <?php echo json_encode(array_merge($pendingApplications, $approvedApplications)); ?>.find(app => app.application_id == applicationId);
    if (application) {
        document.getElementById('modalApplicationId').value = application.application_id;
        document.getElementById('review_name').value = application.first_name + ' ' + (application.middle_name ? application.middle_name + ' ' : '') + application.last_name + (application.suffix ? ', ' + application.suffix : '');
        document.getElementById('review_address').value = application.address;
        document.getElementById('review_purpose').value = application.purpose;
        document.getElementById('review_reference_number').value = application.reference_number;
        document.getElementById('review_status').value = application.status;
        document.getElementById('review_note').value = application.note || '';

        // Populate ID Document link
        const idDocumentLink = document.getElementById('id_document_link');
        if (application.id_document_path) {
            idDocumentLink.href = 'FILES/' + application.id_document_path;
            idDocumentLink.style.display = 'block';
            document.getElementById('review_id_document').style.display = 'none';
        } else {
            idDocumentLink.style.display = 'none';
            document.getElementById('review_id_document').style.display = 'block';
        }

        document.getElementById('review-modal').style.display = "block";

        // Remove automatic check on modal open
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const verifyBtn = document.getElementById('verifyReferenceBtn');
    verifyBtn.addEventListener('click', () => {
        const refNumber = document.getElementById('review_reference_number').value;
        if (!refNumber) {
            alert('No reference number to verify.');
            return;
        }
        fetch('AdminBarangayClearance.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'check_reference',
                ref: refNumber
            })
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data && data.amount && data.purpose) {
                    alert(`Reference Number Verified:\nAmount Fee: ₱${data.amount}\nPurpose: ${data.purpose}`);
                } else if (data && data.exists === false) {
                    alert('Reference Number not found or not valid for Barangay Clearance payment.');
                } else {
                    alert('Unexpected response from server.');
                }
            })
            .catch(error => {
                console.error('Error checking reference number:', error);
                alert('Error verifying reference number.');
            });
    });
});
        function closeReviewModal() {
            document.getElementById('review-modal').style.display = "none";
        }

        function openSearchModal() {
            document.getElementById('search-modal').style.display = "block";
        }

        function closeSearchModal() {
            document.getElementById('search-modal').style.display = "none";
        }

        function searchApplications(event) {
            event.preventDefault();

            const lastName = document.getElementById('search_last_name').value.toLowerCase();
            const firstName = document.getElementById('search_first_name').value.toLowerCase();
            const middleName = document.getElementById('search_middle_name').value.toLowerCase();
            const suffix = document.getElementById('search_suffix').value.toLowerCase();

            const allPendingApplications = <?php echo json_encode($pendingApplications); ?>;
            const allApprovedApplications = <?php echo json_encode($approvedApplications); ?>;
            const searchResultsDiv = document.getElementById('search-results');
            searchResultsDiv.innerHTML = '';

            const matchingPendingApplications = allPendingApplications.filter(app => {
                const matchesLastName = app.last_name.toLowerCase().includes(lastName);
                const matchesFirstName = app.first_name.toLowerCase().includes(firstName);
                const matchesMiddleName = middleName === '' || (app.middle_name && app.middle_name.toLowerCase().includes(middleName));
                const matchesSuffix = suffix === '' || (app.suffix && app.suffix.toLowerCase().includes(suffix));

                return matchesLastName && matchesFirstName && matchesMiddleName && matchesSuffix;
            });

            const matchingApprovedApplications = allApprovedApplications.filter(app => {
                const matchesLastName = app.last_name.toLowerCase().includes(lastName);
                const matchesFirstName = app.first_name.toLowerCase().includes(firstName);
                const matchesMiddleName = middleName === '' || (app.middle_name && app.middle_name.toLowerCase().includes(middleName));
                const matchesSuffix = suffix === '' || (app.suffix && app.suffix.toLowerCase().includes(suffix));

                return matchesLastName && matchesFirstName && matchesMiddleName && matchesSuffix;
            });

            if (matchingPendingApplications.length > 0 || matchingApprovedApplications.length > 0) {
                let resultsHTML = '<h3>Pending Applications</h3><ul>';
                matchingPendingApplications.forEach(app => {
                    resultsHTML += `
                        <li class="p-4 bg-gray-100 rounded-lg shadow-md mb-2">
                            <strong>Name:</strong> ${app.first_name} ${app.middle_name ? app.middle_name : ''} ${app.last_name} ${app.suffix ? app.suffix : ''}<br>
                            <strong>Address:</strong> ${app.address}<br>
                            <strong>Purpose:</strong> ${app.purpose}<br>
                            <strong>Reference Number:</strong> ${app.reference_number}<br>
                            <strong>Status:</strong> ${app.status}<br>
                            <strong>Note:</strong> ${app.note || 'No note provided.'}<br>
                            <strong>Complaints:</strong> ${app.complaints || 'No complaints'}
                        </li>
                    `;
                });
                resultsHTML += '</ul><h3>Approved Applications</h3><ul>';
                matchingApprovedApplications.forEach(app => {
                    resultsHTML += `
                        <li class="p-4 bg-gray-100 rounded-lg shadow-md mb-2">
                            <strong>Name:</strong> ${app.first_name} ${app.middle_name ? app.middle_name : ''} ${app.last_name} ${app.suffix ? app.suffix : ''}<br>
                            <strong>Address:</strong> ${app.address}<br>
                            <strong>Purpose:</strong> ${app.purpose}<br>
                            <strong>Reference Number:</strong> ${app.reference_number}<br>
                            <strong>Status:</strong> ${app.status}<br>
                            <strong>Note:</strong> ${app.note || 'No note provided.'}<br>
                            <strong>Complaints:</strong> ${app.complaints || 'No complaints'}
                        </li>
                    `;
                });
                resultsHTML += '</ul>';
                searchResultsDiv.innerHTML = resultsHTML;
            } else {
                searchResultsDiv.innerHTML = '<p>No matching applications found.</p>';
            }
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target == document.getElementById('review-modal')) {
                closeReviewModal();
            }
            if (event.target == document.getElementById('search-modal')) {
                closeSearchModal();
            }
        }
        function toggleDropdown() {
            const dropdown = document.getElementById('dropdown');
            dropdown.classList.toggle('hidden');
        }
    </script>
</body>

</html>