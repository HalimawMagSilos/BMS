<?php
session_start();
require 'connection.php';

// Check if the user is logged in
if (!isset($_SESSION['user_id'])|| $_SESSION['role'] !== 'resident') {
    header("Location: LoginForm.php");
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

// Fetch user profile information
try {
    $stmt = $pdo->prepare("SELECT p.first_name, p.middle_name, p.last_name, p.suffix, p.address
                                    FROM Profiles p
                                    WHERE p.user_id = :user_id");
    $stmt->execute(['user_id' => $_SESSION['user_id']]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Handle the barangay clearance application submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['purpose'])) {
    $user_id = $_SESSION['user_id'];
    $purpose = $_POST['purpose'];
    $reference_number = $_POST['reference_number'];
    $id_type = isset($_POST['id_type']) ? $_POST['id_type'] : null;
    $document_id = null; // Initialize document_id

    try {
        // Generate application ID
        $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING_INDEX(application_id, '-', -1) AS UNSIGNED)) FROM Barangay_Clearance_Applications WHERE application_id LIKE 'clearance-%'");
        $lastId = $stmt->fetchColumn();
        $newIdNumber = $lastId ? $lastId + 1 : 1;
        $application_id = "clearance-" . $newIdNumber;

        if (isset($_FILES['id_document']) && $_FILES['id_document']['error'] == UPLOAD_ERR_OK) {
            $id_document_tmp_path = $_FILES['id_document']['tmp_name'];
            $id_document_name = basename($_FILES['id_document']['name']);
            $id_document_extension = strtolower(pathinfo($id_document_name, PATHINFO_EXTENSION));

            // Validate file type
            $allowed_extensions = ['pdf', 'docx', 'doc', 'jpg', 'jpeg', 'png'];
            if (!in_array($id_document_extension, $allowed_extensions)) {
                die("Invalid ID document file type. Allowed types: pdf, docx, doc, jpg, jpeg, png.");
            }

            $id_document_path = 'FILES/' . $id_document_name;

            if (!move_uploaded_file($id_document_tmp_path, $id_document_path)) {
                die("Error uploading ID document.");
            }

            $stmt_doc = $pdo->prepare("INSERT INTO User_ID_Documents (user_id, id_type_id, document_file) VALUES (?, ?, ?)");
            $stmt_doc->execute([$user_id, $id_type, $id_document_name]);
            $document_id = $pdo->lastInsertId();
        }

        // Insert the application data
        $stmt_app = $pdo->prepare("INSERT INTO Barangay_Clearance_Applications (application_id, user_id, id_document, purpose, reference_number) VALUES (?, ?, ?, ?, ?)");
        $stmt_app->execute([$application_id, $user_id, $document_id, $purpose, $reference_number]);

        $action = "Applied for barangay clearance for user ID: " . $_SESSION['user_id'] . " with reference number: " . $reference_number;
        $log_stmt = $pdo->prepare("INSERT INTO System_Logs (user_id, action) VALUES (?, ?)");
        $log_stmt->execute([$_SESSION['user_id'], $action]);

        header("Location: ResidentBarangayClearance.php?success=1");
        exit();

    } catch (PDOException $e) {
        // Check if the error is due to the foreign key constraint on reference_number
        if ($e->getCode() == '23000' && strpos($e->getMessage(), 'foreign key constraint fails') !== false && strpos($e->getMessage(), '`barangay_clearance_applications_ibfk_1`') !== false) {
            $error_message = "Error: The provided Reference Number does not exist. Please ensure you have the correct Reference Number or contact the administrator.";
        } else {
            $error_message = "Database error: " . $e->getMessage();
        }
    }
}


// Handle editing documents
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['application_id'])) {
    $application_id = $_POST['application_id'];
    $user_id = $_SESSION['user_id'];
    $reference_number = $_POST['reference_number']; // Get the reference number from the form

    try {
        // Check if a new ID document is being uploaded
        if (isset($_FILES['id_document']) && $_FILES['id_document']['error'] == UPLOAD_ERR_OK) {
            $id_document_tmp_path = $_FILES['id_document']['tmp_name'];
            $id_document_name = basename($_FILES['id_document']['name']);
            $id_document_extension = strtolower(pathinfo($id_document_name, PATHINFO_EXTENSION));

            // Validate file type
            $allowed_extensions = ['pdf', 'docx', 'doc', 'jpg', 'jpeg', 'png'];
            if (!in_array($id_document_extension, $allowed_extensions)) {
                die("Invalid ID document file type. Allowed types: pdf, docx, doc, jpg, jpeg, png.");
            }

            $id_document_path = 'FILES/' . $id_document_name;

            if (!move_uploaded_file($id_document_tmp_path, $id_document_path)) {
                die("Error uploading new ID document.");
            }

            // Update the document file in the User_ID_Documents table
            $stmt = $pdo->prepare("UPDATE User_ID_Documents SET document_file = ? WHERE document_id = (SELECT id_document FROM Barangay_Clearance_Applications WHERE application_id = ?)");
            $stmt->execute([$id_document_name, $application_id]);
        }

        // Update the reference number in the Barangay_Clearance_Applications table if it's provided
        $stmt = $pdo->prepare("UPDATE Barangay_Clearance_Applications SET reference_number = ? WHERE application_id = ?");
        $stmt->execute([$reference_number, $application_id]);

        header("Location: ResidentBarangayClearance.php?edit_success=1");
        exit();
    } catch (PDOException $e) {
        // Check if the error is due to the foreign key constraint on reference_number
        if ($e->getCode() == '23000' && strpos($e->getMessage(), 'foreign key constraint fails') !== false && strpos($e->getMessage(), '`barangay_clearance_applications_ibfk_1`') !== false) {
            $error_message_edit = "Error: The provided Reference Number does not exist. Please ensure you have the correct Reference Number or contact the administrator.";
        } else {
            $error_message_edit = "Database error: " . $e->getMessage();
        }
    }
}

// Function to fetch notes for an application
function fetchNotes($pdo, $application_id)
{
    try {
        $stmt = $pdo->prepare("SELECT note FROM Notes WHERE application_id = :application_id");
        $stmt->execute(['application_id' => $application_id]);
        $notes = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return $notes;
    } catch (PDOException $e) {
        return ["Database error: " . $e->getMessage()];
    }
}

// Function to fetch applications
function fetchApplications($pdo, $user_id, $status)
{
    try {
        $stmt = $pdo->prepare("SELECT bca.application_id, bca.purpose, bca.reference_number, bca.status, bca.downloadable_file, p.first_name, p.middle_name, p.last_name, p.suffix, p.address
                                        FROM Barangay_Clearance_Applications bca
                                        JOIN Profiles p ON bca.user_id = p.user_id
                                        WHERE bca.user_id = :user_id AND bca.status = :status");
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':status', $status);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}
?>


<!DOCTYPE html>
<html>
<head>
    <title>Resident Dashboard - Barangay Clearance Application</title>
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

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgb(0, 0, 0);
            background-color: rgba(0, 0, 0, 0.4);
        }

        .modal-content {
            background-color: #fefefe;
            margin: 15% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
        }

        .application-list {
            max-height: 200px;
            overflow-y: auto;
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
                <li>
                    <button class="block w-full text-left py-3 px-4 rounded hover:bg-blue-600 focus:outline-none text-lg" onclick="toggleDropdown()">Applications</button>
                    <ul id="dropdown" class="hidden space-y-2 pl-4">
                        <li><a href="ResidentBarangayClearance.php" class="block py-3 px-4 rounded hover:bg-blue-600 text-lg">Barangay Clearance</a></li>
                        <li><a href="ResidentBusinessPermit.php" class="block py-3 px-4 rounded hover:bg-blue-600 text-lg">Business Permit</a></li>
                        <li><a href="ResidentFinancialAssistance.php" class="block py-3 px-4 rounded hover:bg-blue-600 text-lg">Financial Assistance</a></li>
                    </ul>
                </li>
                <li><a href="ResidentSettings.php" class="block py-3 px-4 rounded hover:bg-blue-600 text-lg">Settings</a></li>
                <li><a href="logout.php" class="block py-3 px-4 rounded hover:bg-blue-600 text-lg">Logout</a></li>
            </ul>
        </nav>
    </aside>
    <main id="main-content" class="main-content flex-grow p-8 ml-0">
        <div class="flex justify-between items-center mb-8">
            <h2 class="text-3xl font-bold">Barangay Clearance Application</h2>
            <button class="text-blue-500 focus:outline-none" onclick="toggleSidebar()">
                <i class="fas fa-bars text-2xl"></i>
            </button>
        </div>
        <div class="bg-white p-8 rounded-lg shadow-md mb-8">
            <h2 class="text-2xl font-bold mb-6">Application Form</h2>
            <form action="ResidentBarangayClearance.php" method="POST" enctype="multipart/form-data">
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="first_name">First Name</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="first_name" type="text" value="<?php echo htmlspecialchars($profile['first_name']); ?>" readonly/>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="middle_name">Middle Name</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="middle_name" type="text" value="<?php echo htmlspecialchars($profile['middle_name']); ?>" readonly/>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="last_name">Last Name</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="last_name" type="text" value="<?php echo htmlspecialchars($profile['last_name']); ?>" readonly/>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="suffix">Suffix</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="suffix" type="text" value="<?php echo htmlspecialchars($profile['suffix']); ?>" readonly/>
                </div>
                <div class=" mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="address">Address</label>
                    <textarea class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="address" readonly><?php echo htmlspecialchars($profile['address']); ?></textarea>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="reference_number">Reference Number</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="reference_number" type="text" name="reference_number" placeholder="Enter reference number" required/>
                    <p class="text-sm text-gray-500 mt-1">For school purposes, use: school_studentnumber_<?php echo date('Ymd'); ?></p>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="id_type">Select ID Type</label>
                    <select class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="id_type" name="id_type" required>
                        <option value="">Select ID Type</option>
                        <?php foreach ($id_types as $id_type): ?>
                            <option value="<?php echo htmlspecialchars($id_type['id_type_id']); ?>"><?php echo htmlspecialchars($id_type['id_type_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="id_document">Upload ID Document (PDF, DOCX, DOC, JPG, JPEG, PNG)</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="id_document" type="file" name="id_document" accept=".pdf,.docx,.doc,.jpg,.jpeg,.png" required/>
                </div>
                <div class="mb-4">
                <label class="block text-gray-700 font-medium mb-2" for="purpose">Purpose</label>
                    <select class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" name="purpose" id="purpose">
                        <option value="">Select Purpose</option>
                        <!-- Employment Purposes -->
                        <option value="job_application">Job Application</option>
                        <option value="promotion_transfer">Promotion or Transfer</option>

                        <!-- Business Purposes -->
                        <option value="business_permit">Business Permit Application</option>
                        <option value="dti_registration">DTI Business Name Registration</option>
                        <option value="business_renewal">Business Permit Renewal</option>
                        <option value="home_based_business">Home-based Business Approval</option>

                        <!-- Legal and Government Transactions -->
                        <option value="nbi_clearance">NBI Clearance Application</option>
                        <option value="police_clearance">Police Clearance Application</option>
                        <option value="passport_application">Passport Application</option>
                        <option value="postal_id">Postal ID / UMID Application</option>
                        <option value="sss_registration">SSS / Pag-IBIG / PhilHealth Registration</option>
                        <option value="court_requirement">Court Requirements / Legal Affidavits</option>

                        <!-- Personal Identification -->
                        <option value="proof_of_residency">Proof of Residency</option>
                        <option value="school_requirement">School Requirement / Scholarship</option>
                        <option value="bank_transaction">Bank Transaction / Account Opening</option>
                        <option value="government_assistance">Government Assistance (e.g. 4Ps, SAP)</option>
                        <option value="adoption_custody">Adoption or Custody Proceedings</option>

                        <!-- Construction or Housing -->
                        <option value="building_permit">Building Permit Application</option>
                        <option value="renovation_permit">Renovation Permit</option>
                        <option value="house_ownership">House Ownership or Transfer</option>

                        <!-- Permits and Licenses -->
                        <option value="firearms_license">Firearms License Application</option>
                        <option value="drivers_license">Driver’s License Application</option>
                        <option value="voter_registration">Voter Registration / Transfer</option>
                        <option value="marriage_license">Marriage License Application</option>

                        <!-- Community and Civic Participation -->
                        <option value="barangay_program">Participation in Barangay Program</option>
                        <option value="election_requirement">Candidacy for Election</option>
                        <option value="cooperative_membership">Membership in Cooperative / Association</option>
                    </select>

                </div>
                <button class="w-full bg-blue-500 text-white py-2 rounded-lg hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500" type="submit">Submit Application</button>
                <?php if (isset($error_message)): ?>
                    <p class="text-red-500 mt-4"><?php echo $error_message; ?></p>
                <?php endif; ?>
                <?php if (isset($_GET['success'])): ?>
                    <p class="text-green-500 mt-4">Application submitted successfully!</p>
                <?php endif; ?>
                <?php if (isset($_GET['edit_success'])): ?>
                    <p class="text-green-500 mt-4">Document updated successfully!</p>
                <?php endif; ?>
                <div class="mt-4">
                    <a href="payment_link.php" class="text-blue-500 hover:underline">Proceed to Payment</a>
                </div>
            </form>
        </div>
        <div class="bg-white p-8 rounded-lg shadow-md">
            <h2 class="text-2xl font-bold mb-6">Previous Applications</h2>
            <div class="mb-4">
                <h3 class="text-xl font-semibold mb-2">Pending Applications</h3>
                <ul class="space-y-4 application-list">
                    <?php
                    $pending_apps = fetchApplications($pdo, $_SESSION['user_id'], 'pending');
                    foreach ($pending_apps as $app) {
                        $notes = fetchNotes($pdo, $app['application_id']);
                        echo "<li class='p-4 bg-gray-100 rounded-lg shadow-md flex justify-between items-center'>
                                            <div>
                                                <p><strong>Name:</strong> " . htmlspecialchars($app['first_name'] . ' ' . $app['middle_name'] . ' ' . $app['last_name'] . ' ' . $app['suffix']) . "</p>
                                                <p><strong>Address:</strong> " . htmlspecialchars($app['address']) . "</p>
                                                <p><strong>Purpose:</strong> " . htmlspecialchars($app['purpose']) . "</p>
                                                <p><strong>Reference Number:</strong> " . htmlspecialchars($app['reference_number']) . "</p>
                                                <p><strong>Status:</strong> " . htmlspecialchars($app['status']) . "</p>
                                                <p><strong>Note:</strong> " . (empty($notes) ? 'No notes available' : implode(', ', $notes)) . "</p>
                                            </div>
                                            <div>
                                                <button class='bg-yellow-500 text-white py-1 px-3 rounded-lg hover:bg-yellow-600' onclick='openEditModal(\"" . htmlspecialchars($app['application_id']) . "\")'>Edit</button>
                                            </div>
                                        </li>";
                    }
                    ?>
                </ul>
            </div>
            <div class="mb-4">
                <h3 class="text-xl font-semibold mb-2">Approved Applications</h3>
                <ul class="space-y-4 application-list">
                    <?php
                    $approved_apps = fetchApplications($pdo, $_SESSION['user_id'], 'approved');
                    foreach ($approved_apps as $app) {
                        $notes = fetchNotes($pdo, $app['application_id']);
                        echo "<li class='p-4 bg-gray-100 rounded-lg shadow-md flex justify-between items-center'>
                                            <div>
                                                <p><strong>Name:</strong> " . htmlspecialchars($app['first_name'] . ' ' . $app['middle_name'] . ' ' . $app['last_name'] . ' ' . $app['suffix']) . "</p>
                                                <p><strong>Address:</strong> " . htmlspecialchars($app['address']) . "</p>
                                                <p><strong>Purpose:</strong> " . htmlspecialchars($app['purpose']) . "</p>
                                                <p><strong>Reference Number:</strong> " . htmlspecialchars($app['reference_number']) . "</p>
                                                <p><strong>Status:</strong> " . htmlspecialchars($app['status']) . "</p>
                                                <p><strong>Note:</strong> " . (empty($notes) ? 'No notes available' : implode(', ', $notes)) . "</p>
                                            </div>
                                            <div class='flex space-x-2'>";
                                            if (!empty($app['downloadable_file'])) {
                                                echo "<a href='FILES/" . urlencode($app['downloadable_file']) . "' class='bg-green-500 text-white py-1 px-3 rounded-lg hover:bg-green-600'>Download</a>";
                                            } else {
                                                echo "<span class='text-red-500'>No file available</span>";
                                            }
                        echo "</div>
                                        </li>";
                    }
                    ?>
                </ul>
            </div>
        </div>
    </main>

    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeEditModal()">&times;</span>
            <h2 class="text-2xl font-bold mb-4">Edit Document</h2>
            <form id="editForm" action="ResidentBarangayClearance.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="application_id" id="application_id">
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="reference_number">Reference Number</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="reference_number" type="text" name="reference_number"/>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="id_document">Upload New ID Document (PDF, DOCX, DOC, JPG, JPEG, PNG)</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="id_document" type="file" name="id_document" accept=".pdf,.docx,.doc,.jpg,.jpeg,.png"/>
                </div>
                <button type="submit" class="bg-blue-500 text-white py-2 px-4 rounded-lg hover:bg-blue-600">Update Document</button>
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
            dropdown.classList.toggle('hidden');
        }


        function openEditModal(applicationId, referenceNumber) {
            document.getElementById('application_id').value = applicationId;
            document.getElementById('reference_number').value = referenceNumber; // Set the reference number
            document.getElementById('editModal').style.display = "block"; // Show the modal
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = "none";
        }

        window.onclick = function (event) {
            const modal = document.getElementById('editModal');
            if (event.target == modal) {
                modal.style.display = "none";
            }
        }
    </script>
</body>
</html>