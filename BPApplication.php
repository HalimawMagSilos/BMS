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

// Check if the user is logged in
if (!isset($_SESSION['user_id'])|| $_SESSION['role'] !== 'barangay_official') {
    header('Location: LoginForm.php'); // Redirect to login page if not logged in
    exit;
}
// Handle AJAX request for reference number verification
if (isset($_POST['action']) && $_POST['action'] === 'verify_reference') {
    $refNumber = $_POST['reference_number'] ?? '';
    $response = ['exists' => false];

    if ($refNumber !== '') {
        $stmt = $pdo->prepare("SELECT payment_id, amount_fee, purpose FROM Payment_Process WHERE reference_number = ? AND purpose = 'business_permit'");
        $stmt->execute([$refNumber]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($payment) {
            $response['exists'] = true;
            $response['amount_fee'] = $payment['amount_fee'];
            $response['purpose'] = $payment['purpose'];
            $response['payment_id'] = $payment['payment_id']; // Include payment_id if needed
        }
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}


// Function to fetch business permit applications based on their status
function fetchBusinessPermitApplications($pdo, $status)
{
    $stmt = $pdo->prepare("
        SELECT
            bp.application_id,
            u.user_id,
            u.email,
            p.first_name,
            p.middle_name,
            p.last_name,
            p.suffix,
            p.address,
            bp.business_name,
            bp.business_type,
            bp.reference_number,
            bp.completed_application_form,
            bp.valid_government_id,
            bp.dti_certificate,
            bp.sec_registration,
            bp.lease_contract_or_tax_declaration,
            bp.community_tax_certificate,
            bp.previous_barangay_clearance,
            bp.status,
            bp.downloadable_file,
            n.note
        FROM
            Business_Permit_Applications bp
        LEFT JOIN Users u ON bp.user_id = u.user_id
        LEFT JOIN Profiles p ON u.user_id = p.user_id
        LEFT JOIN Notes n ON bp.application_id = n.application_id
        WHERE
            bp.status = ?
    ");
    $stmt->execute([$status]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch applications
$pendingApplications = fetchBusinessPermitApplications($pdo, 'pending');
$approvedApplications = fetchBusinessPermitApplications($pdo, 'approved');

// Handle review form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['application_id'])) {
    $applicationId = $_POST['application_id'];
    $status = $_POST['status'];
    $note = filter_input(INPUT_POST, 'note', FILTER_SANITIZE_FULL_SPECIAL_CHARS); // Sanitize the note

    try {
        $pdo->beginTransaction();

        // Update business permit application
        $updateStmt = $pdo->prepare("UPDATE Business_Permit_Applications SET status = ? WHERE application_id = ?");
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
            // The error you're getting indicates that the 'user_id' column doesn't exist in the Notes table.
            // Based on your schema, it SHOULD exist. However, let's double-check and make sure we're using the correct column name.
            // I'm assuming you want to store the ID of the staff/admin who added the note.
            $noteStmt = $pdo->prepare("INSERT INTO Notes (application_id, staff_id, note) VALUES (?, ?, ?)");
            $noteStmt->execute([$applicationId, $_SESSION['user_id'], $note]);
        }

        // Generate PDF if status is approved
        if ($status === 'approved') {
            generatePDF($applicationId, $pdo); // Pass $pdo to the function
        }

        $pdo->commit();

        // Log the action
        $admin_id = $_SESSION['user_id'];
        $action = "Updated Business Permit Application (ID: $applicationId): Status changed to $status, Note updated.";
        $logStmt = $pdo->prepare("INSERT INTO System_Logs (user_id, action) VALUES (?, ?)");
        $logStmt->execute([$admin_id, $action]);

        // Send email notification
        $applicationDetails = array_values(array_filter(array_merge($pendingApplications, $approvedApplications), function ($app) use ($applicationId) {
            return $app['application_id'] == $applicationId;
        }))[0] ?? null;

        if ($applicationDetails) {
            $userEmail = $applicationDetails['email'];
            $subject = "Business Permit Application Update";
            $message = "Your Business Permit Application with ID: $applicationId has been updated. Please check your dashboard for updates.";
            sendEmail($userEmail, $subject, $message);
        }

        // Refresh applications after update.
        $pendingApplications = fetchBusinessPermitApplications($pdo, 'pending');
        $approvedApplications = fetchBusinessPermitApplications($pdo, 'approved');

        // Redirect to the same page to see the updated data
        header('Location: BPApplication.php');
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo "<script>alert('Error updating application: " . htmlspecialchars($e->getMessage()) . "');</script>";
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<script>alert('" . htmlspecialchars($e->getMessage()) . "');</script>";
    }
}

// Function to generate PDF
function generatePDF($applicationId, $pdo)
{ // Receive $pdo
    require 'fpdf/fpdf.php'; // Adjust the path as necessary

    $stmt = $pdo->prepare("
        SELECT
            bp.*,
            p.first_name,
            p.middle_name,
            p.last_name,
            p.suffix,
            p.address
        FROM
            Business_Permit_Applications bp
        LEFT JOIN
            Users u ON bp.user_id = u.user_id
        LEFT JOIN
            Profiles p ON u.user_id = p.user_id
        WHERE
            bp.application_id = ?
    ");
    $stmt->execute([$applicationId]);
    $application = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($application) {
        $pdf = new FPDF();
        $pdf->AddPage();

        // Set the title
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, 'Business Permit', 0, 1, 'C');

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
        $pdf->Cell(0, 10, "Business Name: {$application['business_name']}", 0, 1);
        $pdf->Cell(0, 10, "Business Type: {$application['business_type']}", 0, 1);
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
        $pdfFilePath = 'FILES/BusinessPermit_' . $lastName . '.pdf';
        $pdf->Output('F', $pdfFilePath);

        // Update the downloadable file path in the database
        $updateStmt = $pdo->prepare("UPDATE Business_Permit_Applications SET downloadable_file = ? WHERE application_id = ?");
        $updateStmt->execute([basename($pdfFilePath), $applicationId]);
    }
}

// Function to send email notifications
function sendEmail($to, $subject, $message)
{
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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Business Permit Department Dashboard</title>
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
            transform: translateX(-100%); /* Hidden by default */
            z-index: 100;
        }

        .sidebar.active {
            transform: translateX(0); /* Show sidebar */
        }

        .main-content {
            transition: margin-left 0.3s ease-in-out;
            margin-left: 0; /* Default margin */
            flex: 1;
            overflow-x: auto;
        }

        /* Adjust main content margin on larger screens when sidebar is active */
        @media (min-width: 768px) {
            .main-content.active {
                margin-left: 256px;
            }
        }

        .table-container {
            overflow-x: auto;
        }

        css
Run
Copy code
.modal {
    display: none; /* Hidden by default */
    position: fixed; /* Stay in place */
    z-index: 1000; /* Sit on top */
    left: 0;
    top: 0;
    width: 100%; /* Full width */
    height: 100%; /* Full height */
    overflow: auto; /* Enable scroll if needed */
    background-color: rgba(0, 0, 0, 0.4); /* Black w/ opacity */
    display: flex; /* Use flexbox to center */
    justify-content: center; /* Center horizontally */
    align-items: center; /* Center vertically */
}

.modal-content {
    background-color: #fefefe; /* White background */
    margin: 0; /* Remove margin */
    padding: 20px; /* Padding inside the modal */
    border: 1px solid #888; /* Border */
    width: 80%; /* Width of the modal */
    max-width: 600px; /* Max width */
    border-radius: 8px; /* Rounded corners */
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

<body class="bg-gray-100 flex">
    <aside class="sidebar bg-green-500 text-white w-64 min-h-screen p-6 fixed inset-y-0 left-0 transform -translate-x-full overflow-y-auto" id="sidebar">
        <div class="text-center mb-8">
            <img alt="Business Permit Department Logo" class="mx-auto mb-4" height="100"
                src="https://storage.googleapis.com/a1aa/image/oPytm4X-nQDT4FEekiF0fx9TqXQnTYbvl6Dyuau22Ho.jpg" width="100" />
            <h1 class="text-3xl font-bold">Business Permit Dept</h1>
        </div>
        <nav>
            <ul class="space-y-6">
                <li><a class="block py-3 px-4 rounded hover:bg-green-600 text-lg" href="BPHome.php">Home</a></li>
                <li><a class="block py-3 px-4 rounded hover:bg-green-600 text-lg" href="BPProfile.php">Profile</a></li>
                <li><a class="block py-3 px-4 rounded hover:bg-green-600 text-lg" href="BPApplication.php">Business Permit Application</a></li>
                <li><a class="block py-3 px-4 rounded hover:bg-green-600 text-lg" href="BPSettings.php">Settings</a></li>
                <li><a class="block py-3 px-4 rounded hover:bg-green-600 text-lg" href="logout.php">Logout</a></li>
            </ul>
        </nav>
    </aside>
    <main id="main-content" class="main-content flex-grow p-8 ml-0">
        <div class="flex justify-between items-center mb-8">
            <h2 class="text-3xl font-bold">Barangay Business Permit Applications</h2>
            <div class="flex space-x-2">
                <button class="text-green-500 focus:outline-none" onclick="toggleSidebar()">
                    <i class="fas fa-bars text-2xl"></i>
                </button>
                <button class="text-green-500 focus:outline-none" onclick="openSearchModal()">
                    <i class="fas fa-search text-2xl"></i>
                </button>
            </div>
        </div>
        <div class="bg-white p-8 rounded-lg shadow-md mb-8">
            <h2 class="text-2xl font-bold mb-6">Pending Applications</h2>
            <div class="application-list-container">
                <table class="min-w-full bg-white">
                    <thead>
                        <tr>
                            <th class="py-2 px-4 border-b">Name</th>
                            <th class="py-2 px-4 border-b">Address</th>
                            <th class="py-2 px-4 border-b">Business Name</th>
                            <th class="py-2 px-4 border-b">Reference Number</th>
                            <th class="py-2 px-4 border-b">Status</th>
                            <th class="py-2 px-4 border-b">Note</th>
                            <th class="py-2 px-4 border-b">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingApplications as $application) : ?>
                            <tr>
                                <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($application['first_name'] . ' ' . ($application['middle_name'] ? $application['middle_name'] . ' ' : '') . $application['last_name'] . ' ' . ($application['suffix'] ? $application['suffix'] : '')); ?></td>
                                <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($application['address']); ?></td>
                                <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($application['business_name']); ?></td>
                                <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($application['reference_number']); ?></td>
                                <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($application['status']); ?></td>
                                <td class="py-2 px-4 border-b">
                                    <textarea class="w-full px-2 py-1 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" readonly><?php echo htmlspecialchars($application['note'] ?? ''); ?></textarea>
                                </td>
                                <td class="py-2 px-4 border-b">
                                    <button class="bg-blue-500 text-white py-1 px-3 rounded-lg hover:bg-blue-600" onclick="openReviewModal('<?php echo htmlspecialchars($application['application_id']); ?>')">Review</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="bg-white p-8 rounded-lg shadow-md mb-8">
            <h2 class="text-2xl font-bold mb-6">Approved Applications</h2>
            <div class="application-list-container">
                <ul class="space-y-4">
                    <?php foreach ($approvedApplications as $application) : ?>
                        <li class="p-4 bg-gray-100 rounded-lg shadow-md flex justify-between items-center">
                            <div>
                                <p><strong>Name:</strong> <?php echo htmlspecialchars($application['first_name'] . ' ' . ($application['middle_name'] ? $application['middle_name'] . ' ' : '') . $application['last_name']. ' ' . ($application['suffix'] ? $application['suffix'] : '')); ?></p>
                                <p><strong>Address:</strong> <?php echo htmlspecialchars($application['address']); ?></p>
                                <p><strong>Business Name:</strong> <?php echo htmlspecialchars($application['business_name']); ?></p>
                                <p><strong>Reference Number:</strong> <?php echo htmlspecialchars($application['reference_number']); ?></p>
                                <p><strong>Status:</strong> <?php echo htmlspecialchars($application['status']); ?></p>
                                <p><strong>Note:</strong> <?php echo htmlspecialchars($application['note'] ?? 'No note provided.'); ?></p>
                            </div>
                            <div class="flex space-x-2">
                                <?php if ($application['downloadable_file']) : ?>
                                    <a href="FILES/<?php echo htmlspecialchars(basename($application['downloadable_file'])); ?>" class="bg-green-500 text-white py-1 px-3 rounded-lg hover:bg-green-600" download>Download</a>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <div id="review-modal" class="fixed inset-0 bg-gray-800 bg-opacity-75 flex items-center justify-center hidden">
            <div class="bg-white p-8 rounded-lg shadow-md w-3/4 max-w-3xl">
                <h2 class="text-2xl font-bold mb-6">Review Application</h2>
                <form id="review-form" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="application_id" id="modalApplicationId">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="mb-4">
                            <label class="block text-gray-700 font-medium mb-2" for="review_documents">Documents</label>
                            <div id="review_documents"></div>
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-700 font-medium mb-2" for="review_status">Status</label>
                            <select class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" name="status" id="review_status">
                                <option value="pending">Pending</option>
                                <option value="approved">Approved</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-4">
                            <label class="block text-gray-700 font-medium mb-2" for="review_reference_number">Reference Number</label>
                            <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" id="review_reference_number" readonly type="text" value="" />
                    </div>
                    <div class="mb-4">
                        <label class="block text-gray-700 font-medium mb-2" for="review_note">Note</label>
                        <textarea class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" name="note" id="review_note"></textarea>
                    </div>
                    <div class="mb-4" id="reference_verification_section" style="display: none;">
                        <label class="block text-gray-700 font-medium mb-2">Reference Number Verification</label>
                        <div id="verification_message" class="mt-2 text-sm"></div>
                        <button type="button" class="bg-blue-500 text-white py-2 px-4 rounded-lg hover:bg-blue-600 focus:outline-none mt-2" onclick="verifyExistingReference()">Verify Reference</button>
                    </div>
                    <div class="flex justify-end space-x-2">
                        <button class="bg-red-500 text-white py-2 px-4 rounded-lg hover:bg-red-600" type="button" onclick="closeReviewModal()">Cancel</button>
                        <button class="bg-green-500 text-white py-2 px-4 rounded-lg hover:bg-green-600" type="submit">Save</button>
                    </div>
                </form>
            </div>
        </div>
        <div class="fixed inset-0 bg-gray-800 bg-opacity-75 flex items-center justify-center overflow-y-scroll hidden" id="search-modal">
            <div class="bg-white p-8 rounded-lg shadow-md w-3/4 max-w-3xl">
                <h2 class="text-2xl font-bold mb-6">Search Applications</h2>
                <form onsubmit="searchApplications(event)">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="mb-4">
                            <label class="block text-gray-700 font-medium mb-2" for="search_last_name">Last Name</label>
                            <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" id="search_last_name" placeholder="Enter last name" type="text" />
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-700 font-medium mb-2" for="search_first_name">First Name</label>
                            <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" id="search_first_name" placeholder="Enter first name" type="text" />
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-700 font-medium mb-2" for="search_middle_name">Middle Name</label>
                            <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" id="search_middle_name" placeholder="Enter middle name" type="text" />
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-700 font-medium mb-2" for="search_suffix">Suffix</label>
                            <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" id="search_suffix" placeholder="Enter suffix" type="text" />
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
            sidebar.classList.toggle('-translate-x-full');
            mainContent.classList.toggle('ml-64');
        }
        function openReviewModal(applicationId) {
            const application = <?php echo json_encode(array_merge($pendingApplications, $approvedApplications)); ?>.find(app => app.application_id === applicationId);
            if (application) {
                document.getElementById('modalApplicationId').value = application.application_id;
                document.getElementById('review_note').value = application.note || '';
                document.getElementById('review_reference_number').value = application.reference_number;
                currentReferenceNumber = application.reference_number;

                // Determine if the reference number verification should be shown
                const verificationSection = document.getElementById('reference_verification_section');
                if (currentReferenceNumber && currentReferenceNumber.trim() !== "") {
                    verificationSection.style.display = 'block';
                    currentApplicationPurpose = 'business_permit'; // Assuming this modal is only for business permits
                    document.getElementById('verification_message').textContent = ''; // Clear previous messages
                } else {
                    verificationSection.style.display = 'none';
                    currentApplicationPurpose = null;
                }

                // Clear previous document links
                const documentsDiv = document.getElementById('review_documents');
                documentsDiv.innerHTML = '';

                // List of documents
                const docs = [
                    { label: 'Completed Application Form', file: application.completed_application_form },
                    { label: 'Valid Government ID', file: application.valid_government_id },
                    { label: 'DTI Certificate', file: application.dti_certificate },
                    { label: 'SEC Registration', file: application.sec_registration },
                    { label: 'Lease Contract/Tax Declaration', file: application.lease_contract_or_tax_declaration },
                    { label: 'Community Tax Certificate', file: application.community_tax_certificate },
                    { label: 'Previous Barangay Clearance', file: application.previous_barangay_clearance }
                ];

                // Add document links to the modal
                docs.forEach(doc => {
                    if (doc.file) {
                        const link = document.createElement('a');
                        link.href = `FILES/${doc.file}`;
                        link.textContent = doc.label;
                        link.target = '_blank';
                        link.classList.add('block', 'text-blue-500', 'hover:underline');
                        documentsDiv.appendChild(link);
                    }
                });

                // Show the review modal
                document.getElementById('review-modal').style.display = "flex";
            }
        }
        function verifyExistingReference() {
            if (currentReferenceNumber && currentReferenceNumber.trim() !== '' && currentApplicationPurpose === 'business_permit') {
                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=verify_reference&reference_number=${encodeURIComponent(currentReferenceNumber)}`,
                })
                .then(response => response.json())
                .then(data => {
                    const verificationMessageDiv = document.getElementById('verification_message');
                    if (data.exists) {
                        verificationMessageDiv.textContent = `Reference number exists. Purpose: ${data.purpose}, Fee: ${data.amount_fee}`;
                        verificationMessageDiv.className = 'mt-2 text-sm text-green-500';
                    } else {
                        verificationMessageDiv.textContent = 'Reference number does not exist in Payment Process for a Business Permit.';
                        verificationMessageDiv.className = 'mt-2 text-sm text-red-500';
                    }
                })
                .catch(error => {
                    console.error('Error verifying reference number:', error);
                    document.getElementById('verification_message').textContent = 'Error verifying reference number.';
                    document.getElementById('verification_message').className = 'mt-2 text-sm text-red-500';
                });
            } else if (currentApplicationPurpose !== 'business_permit') {
                document.getElementById('verification_message').textContent = 'Verification is only applicable for Business Permit applications.';
                document.getElementById('verification_message').className = 'mt-2 text-sm text-yellow-500';
            } else {
                document.getElementById('verification_message').textContent = 'No reference number available for verification.';
                document.getElementById('verification_message').className = 'mt-2 text-sm text-gray-500';
            }
        }

        function closeReviewModal() {
            document.getElementById('review-modal').style.display = "none";
        }

        function openSearchModal() {
            document.getElementById('search-modal').style.display = "flex";
        }

        function closeSearchModal() {
            document.getElementById('search-modal').style.display = "none";
        }

        function searchApplications(event) {
            event.preventDefault();

            // Get search input values
            const lastName = document.getElementById('search_last_name').value.toLowerCase();
            const firstName = document.getElementById('search_first_name').value.toLowerCase();
            const middleName = document.getElementById('search_middle_name').value.toLowerCase();
            const suffix = document.getElementById('search_suffix').value.toLowerCase();

            // Get all pending and approved applications
            const allPendingApplications = <?php echo json_encode($pendingApplications); ?>;
            const allApprovedApplications = <?php echo json_encode($approvedApplications); ?>;
            const searchResultsDiv = document.getElementById('search-results');
            searchResultsDiv.innerHTML = ''; // Clear previous results

            // Filter applications based on search criteria
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

            // Display results
            if (matchingPendingApplications.length > 0 || matchingApprovedApplications.length > 0) {
                let resultsHTML = '<h3>Pending Applications</h3><ul>';
                matchingPendingApplications.forEach(app => {
                    resultsHTML += `
                        <li class="p-4 bg-gray-100 rounded-lg shadow-md mb-2">
                            <strong>Name:</strong> ${app.first_name} ${app.middle_name ? app.middle_name : ''} ${app.last_name} ${app.suffix ? app.suffix : ''}<br>
                            <strong>Address:</strong> ${app.address}<br>
                            <strong>Business Name:</strong> ${app.business_name}<br>
                            <strong>Reference Number:</strong> ${app.reference_number}<br>
                            <strong>Status:</strong> ${app.status}<br>
                            <strong>Note:</strong> ${app.note || 'No note provided.'}<br>
                        </li>
                    `;
                });
                resultsHTML += '</ul><h3>Approved Applications</h3><ul>';
                matchingApprovedApplications.forEach(app => {
                    resultsHTML += `
                        <li class="p-4 bg-gray-100 rounded-lg shadow-md mb-2">
                            <strong>Name:</strong> ${app.first_name} ${app.middle_name ? app.middle_name : ''} ${app.last_name} ${app.suffix ? app.suffix : ''}<br>
                            <strong>Address:</strong> ${app.address}<br>
                            <strong>Business Name:</strong> ${app.business_name}<br>
                            <strong>Reference Number:</strong> ${app.reference_number}<br>
                            <strong>Status:</strong> ${app.status}<br>
                            <strong>Note:</strong> ${app.note || 'No note provided.'}<br>
                        </li>
                    `;
                });
                resultsHTML += '</ul>';
                searchResultsDiv.innerHTML = resultsHTML;
            } else {
                searchResultsDiv.innerHTML = '<p>No matching applications found.</p>';
            }

            // If all search fields are empty, reset to show all pending and approved applications
            if (!lastName && !firstName && !middleName && !suffix) {
                searchResultsDiv.innerHTML = ''; // Clear search results
                // Re-render the original pending applications
                let originalResultsHTML = '<h3>Pending Applications</h3><ul>';
                allPendingApplications.forEach(app => {
                    originalResultsHTML += `
                        <li class="p-4 bg-gray-100 rounded-lg shadow-md mb-2">
                            <strong>Name:</strong> ${app.first_name} ${app.middle_name ? app.middle_name : ''} ${app.last_name} ${app.suffix ? app.suffix : ''}<br>
                            <strong>Address:</strong> ${app.address}<br>
                            <strong>Business Name:</strong> ${app.business_name}<br>
                            <strong>Reference Number:</strong> ${app.reference_number}<br>
                            <strong>Status:</strong> ${app.status}<br>
                            <strong>Note:</strong> ${app.note || 'No note provided.'}<br>
                        </li>
                    `;
                });
                originalResultsHTML += '</ul><h3>Approved Applications</h3><ul>';
                allApprovedApplications.forEach(app => {
                    originalResultsHTML += `
                        <li class="p-4 bg-gray-100 rounded-lg shadow-md mb-2">
                            <strong>Name:</strong> ${app.first_name} ${app.middle_name ? app.middle_name : ''} ${app.last_name} ${app.suffix ? app.suffix : ''}<br>
                            <strong>Address:</strong> ${app.address}<br>
                            <strong>Business Name:</strong> ${app.business_name}<br>
                            <strong>Reference Number:</strong> ${app.reference_number}<br>
                            <strong>Status:</strong> ${app.status}<br>
                            <strong>Note:</strong> ${app.note || 'No note provided.'}<br>
                        </li>
                    `;
                });
                originalResultsHTML += '</ul>';
                searchResultsDiv.innerHTML = originalResultsHTML; // Show all applications
            }

            closeSearchModal();
        }
        function toggleDropdown() {
            const dropdown = document.getElementById('dropdown');
            dropdown.classList.toggle('hidden');
        }
    </script>
</body>

</html>