<?php
session_start();
require 'connection.php';

// Check if the user is logged in
if (!isset($_SESSION['user_id'])|| $_SESSION['role'] !== 'resident') {
    header("Location: LoginForm.php");
    exit();
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

// Fetch ID types for the dropdown
$id_types = [];
try {
    $stmt = $pdo->query("SELECT id_type_id, id_type_name FROM ID_Types");
    $id_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Handle the business permit application submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['business_name'])) {
    $user_id = $_SESSION['user_id'];
    $business_name = $_POST['business_name'];
    $business_type = $_POST['business_type'];
    $reference_number = $_POST['reference_number'];
    $completed_application_form = $_FILES['completed_application_form']['name'] ?? null;
    $valid_government_id_type = $_POST['valid_government_id_type'];
    $valid_government_id = $_FILES['valid_government_id']['name'] ?? null;
    $dti_certificate = $_FILES['dti_certificate']['name'] ?? null;
    $sec_registration = $_FILES['sec_registration']['name'] ?? null;
    $lease_contract_or_tax_declaration = $_FILES['lease_contract_or_tax_declaration']['name'] ?? null;
    $community_tax_certificate = $_FILES['community_tax_certificate']['name'] ?? null;
    $previous_barangay_clearance = $_FILES['previous_barangay_clearance']['name'] ?? null;

    try {
        // Check for duplicate reference numbers in approved applications
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM Business_Permit_Applications WHERE reference_number = :reference_number AND status = 'approved'");
        $stmt->execute(['reference_number' => $reference_number]);
        $countApproved = $stmt->fetchColumn();

        // Check for duplicate reference numbers in non-rejected applications
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM Business_Permit_Applications WHERE reference_number = :reference_number");
        $stmt->execute(['reference_number' => $reference_number]);
        $countTotal = $stmt->fetchColumn();

        if ($countApproved > 0) {
            $error_message = "Warning: Reference number already exists in an approved application.";
        } else if ($countTotal > 0) {
            $error_message = "Reference number already in use. Please use a different reference number.";
        } else {
            $upload_dir = 'FILES/';
            $files = [
                'completed_application_form' => $completed_application_form,
                'valid_government_id' => $valid_government_id,
                'dti_certificate' => $dti_certificate,
                'sec_registration' => $sec_registration,
                'lease_contract_or_tax_declaration' => $lease_contract_or_tax_declaration,
                'community_tax_certificate' => $community_tax_certificate,
                'previous_barangay_clearance' => $previous_barangay_clearance,
            ];

            foreach ($files as $key => $file_name) {
                if (isset($_FILES[$key]) && $_FILES[$key]['error'] == UPLOAD_ERR_OK) {
                    $tmp_path = $_FILES[$key]['tmp_name'];
                    $new_file_name = basename($file_name);
                    $file_extension = strtolower(pathinfo($new_file_name, PATHINFO_EXTENSION));

                    // Validate file type
                    $allowed_extensions = ['pdf', 'docx', 'doc', 'jpg', 'jpeg', 'png'];
                    if (!in_array($file_extension, $allowed_extensions)) {
                        die("Invalid file type for " . $key . ". Allowed types: pdf, docx, doc, jpg, jpeg, png.");
                    }

                    $file_path = $upload_dir . $new_file_name;

                    if (! move_uploaded_file($tmp_path, $file_path)) {
                        die("Error uploading " . $key);
                    }
                    $files[$key] = $new_file_name; // Store the uploaded file name
                } else {
                    $files[$key] = null; // Set to null if file is not uploaded
                }
            }

            try {
                $stmt = $pdo->prepare("INSERT INTO User_ID_Documents (user_id, id_type_id, document_file) VALUES (?, ?, ?)");
                $stmt->execute([$user_id, $valid_government_id_type, $files['valid_government_id']]);
                $document_id = $pdo->lastInsertId();

                // Generate application ID (permit-{auto incrementing number})
                $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(application_id, 8) AS UNSIGNED)) FROM Business_Permit_Applications");
                $lastId = $stmt->fetchColumn();
                $newId = $lastId ? $lastId + 1 : 1;
                $application_id = "permit-" . $newId;

                $stmt = $pdo->prepare("INSERT INTO Business_Permit_Applications (application_id, user_id, business_name, business_type, reference_number, completed_application_form, valid_government_id, dti_certificate, sec_registration, lease_contract_or_tax_declaration, community_tax_certificate, previous_barangay_clearance) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$application_id, $user_id, $business_name, $business_type, $reference_number, $files['completed_application_form'], $document_id, $files['dti_certificate'], $files['sec_registration'], $files['lease_contract_or_tax_declaration'], $files['community_tax_certificate'], $files['previous_barangay_clearance']]);

                $action = "Applied for business permit for user ID: " . $_SESSION['user_id'];
                $log_stmt = $pdo->prepare("INSERT INTO System_Logs (user_id, action) VALUES (?, ?)");
                $log_stmt->execute([$_SESSION['user_id'], $action]);

                header("Location: ResidentBusinessPermit.php?success=1");
                exit();
            } catch (PDOException $e) {
                die("Database error: " . $e->getMessage());
            }
        }
    } catch (PDOException $e) {
        die("Database error: " . $e->getMessage());
    }
}

// Handle editing documents
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['application_id'])) {
    $application_id = $_POST['application_id'];
    $user_id = $_SESSION['user_id'];
    $reference_number = $_POST['reference_number']; // Get the reference number from the form
    $upload_dir = 'FILES/';

    $files = [
        'completed_application_form',
        'valid_government_id',
        'dti_certificate',
        'sec_registration',
        'lease_contract_or_tax_declaration',
        'community_tax_certificate',
        'previous_barangay_clearance',
    ];

    // Flag to check if any file was uploaded
    $file_uploaded = false;

    foreach ($files as $file_key) {
        if (isset($_FILES[$file_key]) && $_FILES[$file_key]['error'] == UPLOAD_ERR_OK) {
            try {
                $tmp_path = $_FILES[$file_key]['tmp_name'];
                $file_name = basename($_FILES[$file_key]['name']);
                $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

                // Validate file type
                $allowed_extensions = ['pdf', 'docx', 'doc', 'jpg', 'jpeg', 'png'];
                if (!in_array($file_extension, $allowed_extensions)) {
                    die("Invalid file type for " . $file_key . ". Allowed types: pdf, docx, doc, jpg, jpeg, png.");
                }

                $file_path = $upload_dir . $file_name;

                if (!move_uploaded_file($tmp_path, $file_path)) {
                    die("Error uploading new " . $file_key);
                }

                // Update the specific file field in the Business_Permit_Applications table
                $stmt = $pdo->prepare("UPDATE Business_Permit_Applications SET $file_key = ? WHERE application_id = ?");
                $stmt->execute([$file_name, $application_id]);

                // Set the flag to indicate that a file was uploaded
                $file_uploaded = true;

            } catch (PDOException $e) {
                die("Database error: " . $e->getMessage());
            }
        }
    }

    // Update the reference number in the Business_Permit_Applications table if it's provided
    if (!empty($reference_number)) {
        $stmt = $pdo->prepare("UPDATE Business_Permit_Applications SET reference_number = ? WHERE application_id = ?");
        $stmt->execute([$reference_number, $application_id]);
    }

    // Redirect after processing all uploads and updates
    header("Location: ResidentBusinessPermit.php?edit_success=1");
    exit();
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
        return ["Database error: " . $e-> getMessage()];
    }
}

// Function to fetch applications
function fetchApplications($pdo, $user_id, $status)
{
    try {
        $stmt = $pdo->prepare("SELECT bpa.application_id, bpa.business_name, bpa.business_type, bpa.reference_number,bpa.downloadable_file, bpa.status, p.first_name, p.middle_name, p.last_name, p.suffix, p.address
                                             FROM Business_Permit_Applications bpa
                                             JOIN Profiles p ON bpa.user_id = p.user_id
                                             WHERE bpa.user_id = :user_id AND bpa.status = :status");
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
    <title>Resident Dashboard - Business Permit Application</title>
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
                <li><a href="ResidentSettings.php" class="block py-3 px-4 rounded hover:bg-blue-600 text-lg">Settings</a></ li>
                <li><a href="logout.php" class="block py-3 px-4 rounded hover:bg-blue-600 text-lg">Logout</a></li>
            </ul>
        </nav>
    </aside>
    <main id="main-content" class="main-content flex-grow p-8 ml-0">
        <div class="flex justify-between items-center mb-8">
            <h2 class="text-3xl font-bold">Business Permit Application</h2>
            <button class="text-blue-500 focus:outline-none" onclick="toggleSidebar()">
                <i class="fas fa-bars text-2xl"></i>
            </button>
        </div>
        <div class="bg-white p-8 rounded-lg shadow-md mb-8">
            <h2 class="text-2xl font-bold mb-6">Application Form</h2>
            <form action="ResidentBusinessPermit.php" method="POST" enctype="multipart/form-data">
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
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="address">Address</label>
                    <textarea class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="address" readonly><?php echo htmlspecialchars($profile['address']); ?></textarea>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="business_name">Business Name</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="business_name" type="text" name="business_name" placeholder="Enter business name" required/>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="business_type">Business Type</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="business_type" type="text" name="business_type" placeholder="Enter business type" required/>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="reference_number">Reference Number</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="reference_number" type="text" name="reference_number" placeholder="Enter reference number" required/>
                </div>
                <div class="mb-4 flex items-center">
                    <div class="w-3/4">
                        <label class="block text-gray-700 font-medium mb-2" for="completed_application_form">Upload Completed Application Form (pdf, docx, doc, jpg, jpeg, png)</label>
                        <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="completed_application_form" type="file" name=" completed_application_form" accept=".pdf, .docx, .doc, .jpg, .jpeg, .png" required/>
                    </div>
                    <div class="w-1/4 pl-4">
                        <a href="FILES/BusinessPermitApplicationForm.pdf" class="bg-green-500 text-white py-2 px-3 rounded-lg hover:bg-green-600 w-full text-center" target="_blank">Download Application Form</a>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="valid_government_id_type">Select Valid Government ID Type</label>
                    <select class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="valid_government_id_type" name="valid_government_id_type" required>
                        <option value="">Select ID Type</option>
                        <?php foreach ($id_types as $id_type): ?>
                            <option value="<?php echo htmlspecialchars($id_type['id_type_id']); ?>"><?php echo htmlspecialchars($id_type['id_type_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="valid_government_id">Upload Valid Government ID (pdf, docx, doc, jpg, jpeg, png)</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="valid_government_id" type="file" name="valid_government_id" accept=".pdf, .docx, .doc, .jpg, .jpeg, .png" required/>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="dti_certificate">Upload DTI Certificate (pdf, docx, doc, jpg, jpeg, png)</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="dti_certificate" type="file" name="dti_certificate" accept=".pdf, .docx, .doc, .jpg, .jpeg, .png" required/>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="sec_registration">Upload SEC Registration (pdf, docx, doc, jpg, jpeg, png)</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="sec_registration" type="file" name="sec_registration" accept=".pdf, .docx, .doc, .jpg, .jpeg, .png" required/>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="lease_contract_or_tax_declaration">Upload Lease Contract or Tax Declaration (pdf, docx, doc, jpg, jpeg, png)</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="lease_contract_or_tax_declaration" type="file" name="lease_contract_or_tax_declaration" accept=".pdf, .docx, .doc, .jpg, .jpeg, .png" required/>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="community_tax_certificate">Upload Community Tax Certificate (pdf, docx, doc, jpg, jpeg, png)</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="community_tax_certificate" type="file" name="community_tax_certificate" accept=".pdf, .docx, .doc, .jpg, .jpeg, .png" required/>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="previous_barangay_clearance">Upload Previous Barangay Clearance (pdf, docx, doc, jpg, jpeg, png)</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="previous_barangay_clearance" type="file" name="previous_barangay_clearance" accept=".pdf, .docx, .doc, .jpg, .jpeg, .png" required/>
                </div>
                <button class="w-full bg-blue-500 text-white py-2 rounded -lg hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500" type="submit">Submit Application</button>
                <?php if (isset($error_message)): ?>
                    <p class="text-red-500 mt-4"><?php echo $error_message; ?></p>
                <?php endif; ?>
                <?php if (isset($_GET['success'])): ?>
                    <p class="text-green-500 mt-4">Application submitted successfully!</p>
                <?php endif; ?>
                <?php if (isset($_GET['edit_success'])): ?>
                    <p class="text-green-500 mt-4">Documents updated successfully!</p>
                <?php endif; ?>
                <div class="mt-4">
                    <a href="payment_link2.php" class="text-blue-500 hover:underline">Proceed to Payment</a>
                </div>
            </form>
        </div>
        <div class="bg-white p-8 rounded-lg shadow-md">
            <h2 class="text-2xl font-bold mb-6">Previous Applications</h2>
            <div class="mb-4">
                <h3 class="text-xl font-semibold mb-2">Pending Applications</h3>
                <ul class="space-y-4 max-h-64 overflow-y-auto">
                    <?php
                    $pending_apps = fetchApplications($pdo, $_SESSION['user_id'], 'pending');
                    foreach ($pending_apps as $app) {
                        $notes = fetchNotes($pdo, $app['application_id']);
                        echo "<li class='p-4 bg-gray-100 rounded-lg shadow-md flex justify-between items-center'>
                                                <div>
                                                    <p><strong>Name:</strong> " . htmlspecialchars($app['first_name'] . ' ' . $app['middle_name'] . ' ' . $app['last_name'] . ' ' . $app['suffix']) . "</p>
                                                    <p><strong>Address:</strong> " . htmlspecialchars($app['address']) . "</p>
                                                    <p><strong>Business Name:</strong> " . htmlspecialchars($app['business_name']) . "</p>
                                                    <p><strong>Business Type:</strong> " . htmlspecialchars($app['business_type']) . "</p>
                                                    <p><strong>Reference Number:</strong> " . htmlspecialchars($app['reference_number']) . "</p>
                                                    <p><strong>Status:</strong> " . htmlspecialchars($app['status']) . "</p>
                                                    <p><strong>Note:</strong> " . (empty($notes) ? 'Your application is under review.' : implode(', ', $notes)) . "</p>
                                                </div>
                                                <div>
                                                    <button class='bg-yellow-500 text-white py-1 px-3 rounded-lg hover:bg-yellow-600' onclick='openEditModal(\"" . $app['application_id'] . "\")'>Edit</button>
                                                </div>
                                            </li>";
                    }
                    ?>
                </ul>
            </div>
            <div class="mb-4">
                <h3 class="text-xl font-semibold mb-2">Approved Applications</h3>
                <ul class="space-y-4 max-h-64 overflow-y-auto">
                    <?php
                    $approved_apps = fetchApplications($pdo, $_SESSION['user_id'], 'approved');
                    foreach ($approved_apps as $app) {
                        $notes = fetchNotes($pdo, $app['application_id']);
                        echo "<li class='p-4 bg-gray-100 rounded-lg shadow-md flex justify-between items-center'>
                                                <div>
                                                    <p><strong>Name:</strong> " . htmlspecialchars($app['first_name'] . ' ' . $app['middle_name'] . ' ' . $app['last_name'] . ' ' . $app['suffix']) . "</p>
                                                    <p><strong>Address:</strong> " . htmlspecialchars($app['address']) . "</p>
                                                    <p><strong>Business Name:</strong> " . htmlspecialchars($app['business_name']) . "</p>
                                                    <p><strong>Business Type:</strong> " . htmlspecialchars($app['business_type']) . "</p>
                                                    <p><strong>Reference Number:</strong> " . htmlspecialchars($app['reference_number']) . "</p>
                                                    <p><strong>Status:</strong> " . htmlspecialchars($app['status']) . "</p>
                                                    <p><strong>Note:</strong> " . (empty($notes) ? 'Your application has been approved. You can now download your permit.' : implode(', ', $notes)) . "</p>
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
                </ ul>
            </div>
        </div>
    </main>

    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeEditModal()">&times;</span>
            <h2 class="text-2xl font-bold mb-4">Edit Application Documents</h2>
            <form id="editForm" action="ResidentBusinessPermit.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="application_id" id="application_id">
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="reference_number">Reference Number</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="reference_number" type="text" name="reference_number"/>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="completed_application_form">Upload Completed Application Form (pdf, docx, doc, jpg, jpeg, png)</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="completed_application_form" type="file" name="completed_application_form" accept=".pdf, .docx, .doc, .jpg, .jpeg, .png" />
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="valid_government_id">Upload Valid Government ID (pdf, docx, doc, jpg, jpeg, png)</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="valid_government_id" type="file" name="valid_government_id" accept=".pdf, .docx, .doc, .jpg, .jpeg, .png" />
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="dti_certificate">Upload DTI Certificate (pdf, docx, doc, jpg, jpeg, png)</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="dti_certificate" type="file" name="dti_certificate" accept=".pdf, .docx, .doc, .jpg, .jpeg, .png" />
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="sec_registration">Upload SEC Registration (pdf, docx, doc, jpg, jpeg, png)</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="sec_registration" type="file" name="sec_registration" accept=".pdf, .docx, .doc, .jpg, .jpeg, .png" />
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="lease_contract_or_tax_declaration">Upload Lease Contract or Tax Declaration (pdf, docx, doc, jpg, jpeg, png)</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="lease_contract_or_tax_declaration" type="file" name="lease_contract_or_tax_declaration" accept=".pdf, .docx, .doc, .jpg, .jpeg, .png" />
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="community_tax_certificate">Upload Community Tax Certificate (pdf, docx, doc, jpg, jpeg, png)</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="community_tax_certificate" type="file" name="community_tax_certificate" accept=".pdf, .docx, .doc, .jpg, .jpeg, .png" />
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="previous_barangay_clearance">Upload Previous Barangay Clearance (pdf, docx, doc, jpg, jpeg, png)</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="previous_barangay_clearance" type="file" name="previous_barangay_clearance" accept=".pdf, .docx, .doc, .jpg, .jpeg, .png" />
                </div>
                <button class="w-full bg-blue-500 text-white py-2 rounded-lg hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500" type="submit">Update Documents</ button>
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

        function openEditModal(applicationId,referenceNumber) {
            document.getElementById('application_id').value = applicationId;
            document.getElementById('reference_number').value = referenceNumber; // Set the reference number
            document.getElementById('editModal').style.display = "block";
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = "none";
        }

        window.onclick = function (event) {
            if (event.target == document.getElementById('editModal')) {
                document.getElementById('editModal').style.display = "none";
            }
        }
    </script>
</body>
</html>