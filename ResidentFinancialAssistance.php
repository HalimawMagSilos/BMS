<?php
session_start();
require 'connection.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['user_id'])|| $_SESSION['role'] !== 'resident') {
    header('Location: LoginForm.php');
    exit;
}

// Fetch user profile
try {
    $stmt = $pdo->prepare("SELECT first_name, middle_name, last_name, suffix, address FROM Profiles WHERE user_id = :user_id");
    $stmt->execute(['user_id' => $_SESSION['user_id']]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database error fetching profile: " . $e->getMessage());
}

// Handle new application submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reason']) && !isset($_POST['application_id'])) {
    $user_id = $_SESSION['user_id'];
    $reason = $_POST['reason'];

    $files = [
        'valid_government_id' => ['types' => ['pdf', 'docx', 'doc', 'jpeg', 'jpg', 'png'], 'name' => 'Valid Government ID'],
        'barangay_clearance_or_residency' => ['types' => ['pdf', 'docx', 'doc', 'jpeg', 'jpg', 'png'], 'name' => 'Barangay Clearance or Residency'],
        'proof_of_income' => ['types' => ['pdf', 'docx', 'doc', 'jpeg', 'jpg', 'png'], 'name' => 'Proof of Income'],
        'medical_certificate' => ['types' => ['pdf', 'docx', 'doc', 'jpeg', 'jpg', 'png'], 'name' => 'Medical Certificate'],
        'hospital_bills' => ['types' => ['pdf', 'docx', 'doc', 'jpeg', 'jpg', 'png'], 'name' => 'Hospital Bills'],
        'prescriptions' => ['types' => ['pdf', 'docx', 'doc', 'jpeg', 'jpg', 'png'], 'name' => 'Prescriptions'],
        'senior_citizen_id' => ['types' => ['pdf', 'docx', 'doc', 'jpeg', 'jpg', 'png'], 'name' => 'Senior Citizen ID'],
        'osca_certification' => ['types' => ['pdf', 'docx', 'doc', 'jpeg', 'jpg', 'png'], 'name' => 'OSCA Certification'],
        'pwd_id' => ['types' => ['pdf', 'docx', 'doc', 'jpeg', 'jpg', 'png'], 'name' => 'PWD ID'],
        'disability_certificate' => ['types' => ['pdf', 'docx', 'doc', 'jpeg', 'jpg', 'png'], 'name' => 'Disability Certificate']
    ];

    $upload_dir = 'FILES/';
    $uploaded_files = [];
    $upload_errors = [];

    foreach ($files as $key => $file_info) {
        if (isset($_FILES[$key]) && $_FILES[$key]['error'] === UPLOAD_ERR_OK) {
            $file_name = $_FILES[$key]['name'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

            if (in_array($file_ext, $file_info['types'])) {
                $safe_filename = time() . "_" . preg_replace("/[^a-zA-Z0-9\._-]/", "_", basename($file_name));
                $file_path = $upload_dir . $safe_filename;

                if (move_uploaded_file($_FILES[$key]['tmp_name'], $file_path)) {
                    $uploaded_files[$key] = $safe_filename;
                } else {
                    $upload_errors[] = "Error uploading " . $file_info['name'] . ".";
                    $uploaded_files[$key] = null;
                }
            } else {
                $upload_errors[] = "Invalid file type for " . $file_info['name'] . ". Allowed types: " . implode(", ", $file_info['types']) . ".";
                $uploaded_files[$key] = null;
            }
        } else {
            $uploaded_files[$key] = null;
        }
    }

    if (!empty($upload_errors)) {
        foreach ($upload_errors as $error) {
            echo "<p style='color:red;'>$error</p>";
        }
        return; // Stop execution if there are upload errors
    }

    try {
        // Generate application ID (financial-{auto incrementing number})
        $stmt = $pdo->query("SELECT MAX(SUBSTRING_INDEX(application_id, '-', -1)) FROM Financial_Assistance_Applications WHERE application_id LIKE 'financial-%'");
        $lastId = $stmt->fetchColumn();
        $newId = $lastId ? (int)$lastId + 1 : 1;
        $application_id = "financial-" . $newId;

        if (empty($application_id)) {
            throw new Exception("Failed to generate application ID.");
        }

        // Check for duplicate ID
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM Financial_Assistance_Applications WHERE application_id = ?");
        $stmt->execute([$application_id]);
        $count = $stmt->fetchColumn();

        if ($count > 0) {
            // Generate a unique ID if a duplicate is found
            $application_id = trim("financial-" . time() . "-" . rand(1000, 9999));
        }

        // Insert application
        $sql = "INSERT INTO Financial_Assistance_Applications (application_id, user_id, valid_government_id, barangay_clearance_or_residency, proof_of_income, medical_certificate, hospital_bills, prescriptions, senior_citizen_id, osca_certification, pwd_id, disability_certificate, reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $params = [
            trim($application_id),
            $user_id,
            $_POST['valid_government_id'], // Store the ID type ID
            $uploaded_files['barangay_clearance_or_residency'],
            $uploaded_files['proof_of_income'],
            $uploaded_files['medical_certificate'],
            $uploaded_files['hospital_bills'],
            $uploaded_files['prescriptions'],
            $uploaded_files['senior_citizen_id'],
            $uploaded_files['osca_certification'],
            $uploaded_files['pwd_id'],
            $uploaded_files['disability_certificate'],
            $_POST['reason']
        ];

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        // Log the action
        $action = "Applied for financial assistance for user ID: " . $_SESSION['user_id'];
        $log_stmt = $pdo->prepare("INSERT INTO System_Logs (user_id, action) VALUES (?, ?)");
        $log_stmt->execute([$user_id, $action]);

        header("Location: ResidentFinancialAssistance.php?success=1");
        exit;
    } catch (PDOException $e) {
        die("Database error during application submission: " . $e->getMessage());
    } catch (Exception $e) {
        die($e->getMessage());
    }
}

// Handle document editing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['application_id'])) {
    $application_id = $_POST['application_id'];
    $user_id = $_SESSION['user_id'];
    $upload_dir = 'FILES/';

    $files = [
        'valid_government_id', 'barangay_clearance_or_residency', 'proof_of_income',
        'medical_certificate', 'hospital_bills', 'prescriptions',
        'senior_citizen_id', 'osca_certification', 'pwd_id', 'disability_certificate'
    ];

    foreach ($files as $file_key) {
        if (isset($_FILES[$file_key]) && $_FILES[$file_key]['error'] === UPLOAD_ERR_OK) {
            $file_name = $_FILES[$file_key]['name'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $allowedTypes = ['pdf', 'docx', 'doc', 'jpeg', 'jpg', 'png'];

            if (in_array($file_ext, $allowedTypes)) {
                $safe_filename = time() . "_" . preg_replace("/[^a-zA-Z0-9\._-]/", "_", basename($_FILES[$file_key]['name']));
                $file_path = $upload_dir . $safe_filename;

                if (move_uploaded_file($_FILES[$file_key]['tmp_name'], $file_path)) {
                    try {
                        $stmt = $pdo->prepare("UPDATE Financial_Assistance_Applications SET {$file_key} = ? WHERE application_id = ?");
                        $stmt->execute([$safe_filename, trim($application_id)]);
                    } catch (PDOException $e) {
                        die("Database error: " . $e->getMessage());
                    }
                } else {
                    die("Error uploading new " . $file_key);
                }
            } else {
                die("Invalid file type for " . $file_key . ". Allowed types: " . implode(", ", $allowedTypes) . ".");
            }
        }
    }

    header("Location: ResidentFinancialAssistance.php?edit_success=1");
    exit;
}
// Fetch application notes
function fetchNotes($pdo, $application_id) {
    try {
        $stmt = $pdo->prepare("SELECT note FROM Notes WHERE application_id = :application_id");
        $stmt->execute(['application_id' => $application_id]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return ["Database error: " . $e->getMessage()];
    }
}

// Fetch applications by status
function fetchApplications($pdo, $user_id, $status) {
    try {
        $stmt = $pdo->prepare("SELECT
            f.application_id, f.reason, f.status,
            p.first_name, p.middle_name, p.last_name, p.suffix, p.address,
            n.note,
            f.valid_government_id, f.barangay_clearance_or_residency, f.proof_of_income,
            f.medical_certificate, f.hospital_bills, f.prescriptions,
            f.senior_citizen_id, f.osca_certification, f.pwd_id,f.downloadable_file, f.disability_certificate
            FROM Financial_Assistance_Applications f
            JOIN Profiles p ON f.user_id = p.user_id
            LEFT JOIN Notes n ON f.application_id = n.application_id
            WHERE f.user_id = :user_id AND f.status = :status");

        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':status', $status);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// Fetch all applications
$pending_apps = fetchApplications($pdo, $_SESSION['user_id'], 'pending');
$approved_apps = fetchApplications($pdo, $_SESSION['user_id'], 'approved');

// Fetch ID types
try {
    $stmt = $pdo->query("SELECT id_type_id, id_type_name FROM ID_Types");
    $id_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database error fetching ID types: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resident Dashboard - Financial Assistance Application</title>
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
        .application-list { max-height: 200px; overflow-y: auto; }
        .error-message { color: red; }
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
                <li><a href="ResidentHome.php" class=" block py-3 px-4 rounded hover:bg-blue-600 text-lg">Home</a></li>
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
            <h2 class="text-3xl font-bold">Financial Assistance Application</h2>
            <button class="text-blue-500 focus:outline-none" onclick="toggleSidebar()">
                <i class="fas fa-bars text-2xl"></i>
            </button>
        </div>
        <div class="bg-white p-8 rounded-lg shadow-md mb-8">
            <h2 class="text-2xl font-bold mb-6">Application Form</h2>
            <form action="ResidentFinancialAssistance.php" method="POST" enctype="multipart/form-data" id="applicationForm">
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
                    <label class="block text-gray-700 font-medium mb-2" for="valid_government_id">Select Valid Government ID</label>
                    <select class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="valid_government_id_select" name="valid_government_id" required>
                        <option value="">Select ID Type</option>
                        <?php foreach ($id_types as $id_type): ?>
                            <option value="<?php echo htmlspecialchars($id_type['id_type_id']); ?>"><?php echo htmlspecialchars($id_type['id_type_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="valid_government_id">Upload Valid Government ID (pdf, docx, doc, jpeg, jpg, png)</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="valid_government_id" type="file" name="valid_government_id" accept=".pdf, .docx, .doc, .jpg, .jpeg, .png" required/>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="barangay_clearance_or_residency">Upload Barangay Clearance or Residency (pdf, docx, doc, jpeg, jpg, png)</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="barangay_clearance_or_residency" type="file" name="barangay_clearance_or_residency" accept=".pdf, .docx, .doc, .jpg, .jpeg, .png" required/>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="proof_of_income">Upload Proof of Income (pdf, docx, doc, jpeg, jpg, png)</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="proof_of_income" type="file" name="proof_of_income" accept=".pdf, .docx, .doc, .jpg, .jpeg, .png" required/>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="medical_certificate">Upload Medical Certificate (if applicable) (pdf, docx, doc, jpeg, jpg, png)</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="medical_certificate" type="file" name="medical_certificate" accept=".pdf, .docx, .doc, .jpg, .jpeg, .png"/>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="hospital_bills">Upload Hospital Bills (if applicable) (pdf, docx, doc, jpeg, jpg, png)</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="hospital_bills" type="file" name="hospital_bills" accept=".pdf, .docx, .doc, .jpg, .jpeg, .png"/>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="prescriptions">Upload Prescriptions (if applicable) (pdf, docx, doc, jpeg, jpg, png)</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="prescriptions" type="file" name="prescriptions" accept=".pdf, .docx, .doc, .jpg, .jpeg, .png"/>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="senior_citizen_id">Upload Senior Citizen ID (if applicable) (pdf, docx, doc, jpeg, jpg, png)</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="senior_citizen_id" type="file" name="senior_citizen_id" accept=".pdf, .docx, .doc, .jpg, .jpeg, .png"/>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="osca_certification">Upload OSCA Certification (if applicable) (pdf, docx, doc, jpeg, jpg, png)</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="osca_certification" type="file" name="osca_certification" accept=".pdf, .docx, .doc, .jpg, .jpeg, .png"/>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="pwd_id">Upload PWD ID (if applicable) (pdf, docx, doc, jpeg, jpg, png)</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="pwd_id" type="file" name="pwd_id" accept=".pdf, .docx, .doc, .jpg, .jpeg, .png"/>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="disability_certificate">Upload Disability Certificate (if applicable) (pdf, docx, doc, jpeg, jpg, png)</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="disability_certificate" type="file" name="disability_certificate" accept=".pdf, .docx, .doc, .jpg, .jpeg, .png"/>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="reason">Reason for Assistance</label>
                    <textarea class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="reason" name="reason" required></textarea>
                </div>
                <button type="submit" class="w-full bg-blue-500 text-white font-bold py-2 rounded-lg hover:bg-blue-600">Submit Application</button>
                <?php if (isset($_GET['success'])): ?>
                    <p class="text-green-500 mt-4">Application submitted successfully!</p>
                <?php endif; ?>
                <?php if (isset($_GET['edit_success'])): ?>
                    <p class="text-green-500 mt-4">Documents updated successfully!</p>
                <?php endif; ?>
            </form>
        </div>
        <div class="bg-white p-8 rounded-lg shadow-md">
            <h2 class="text-2xl font-bold mb-6">Previous Applications</h2>
            <div class="mb-4">
                <h3 class="text-xl font-semibold mb-2">Pending Applications</h3>
                <ul class="space-y-4 application-list">
                    <?php foreach ($pending_apps as $app): ?>
                        <li class='p-4 bg-gray-100 rounded-lg shadow-md flex justify-between items-center'>
                            <div>
                                <p><strong>Name:</strong> <?php echo htmlspecialchars($app['first_name'] . ' ' . $app['middle_name'] . ' ' . $app['last_name'] . ' ' . $app['suffix']); ?></p>
                                <p><strong>Address:</strong> <?php echo htmlspecialchars($app['address']); ?></p>
                                <p><strong>Reason:</strong> <?php echo htmlspecialchars($app['reason']); ?></p>
                                <p><strong>Status:</strong> <?php echo htmlspecialchars($app['status']); ?></p>
                                <p><strong>Note:</strong> Your application is under review.</p>
                            </div>
                            <button class="bg-blue-500 text-white px-4 py-2 rounded-lg" onclick="openEditModal('<?php echo $app['application_id']; ?>')">Edit</button>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="mb-4">
                <h3 class="text-xl font-semibold mb-2">Approved Applications</h3>
                <ul class="space-y-4 application-list">
                    <?php foreach ($approved_apps as $app): ?>
                        <li class='p-4 bg-gray-100 rounded-lg shadow-md'>
                            <div>
                                <p><strong>Name:</strong> <?php echo htmlspecialchars($app['first_name'] . ' ' . $app['middle_name'] . ' ' . $app['last_name'] . ' ' . $app['suffix']); ?></p>
                                <p><strong>Address:</strong> <?php echo htmlspecialchars($app['address']); ?></p>
                                <p><strong>Reason:</strong> <?php echo htmlspecialchars($app['reason']); ?></p>
                                <p><strong>Status:</strong> <?php echo htmlspecialchars($app['status']); ?></p>
                                <p><strong>Note:</strong> Application Approved.</p>
                                <div class='flex space-x-2'>
                                    <?php if (!empty($app['downloadable_file'])): ?>
                                        <a href='FILES/<?php echo urlencode($app['downloadable_file']); ?>' class='bg-green-500 text-white py-1 px-3 rounded-lg hover:bg-green-600'>Download</a>
                                    <?php else: ?>
                                        <span class='text-red-500'>No file available</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </main>

    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick=" closeEditModal()">&times;</span>
            <h2 class="text-2xl font-bold mb-4">Edit Application Documents</h2>
            <form id="editForm" action="ResidentFinancialAssistance.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="application_id" id="application_id">
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="edit_valid_government_id">Upload Valid Government ID (pdf, docx, doc, jpeg, jpg, png)</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="edit_valid_government_id" type="file" name="valid_government_id" />
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="edit_barangay_clearance_or_residency">Upload Barangay Clearance or Residency (pdf, docx, doc, jpeg, jpg, png)</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="edit_barangay_clearance_or_residency" type="file" name="barangay_clearance_or_residency" accept=".pdf, .docx, .doc, .jpg, .jpeg, .png" />
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="edit_proof_of_income">Upload Proof of Income (pdf, docx, doc, jpeg, jpg, png)</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="edit_proof_of_income" type="file" name="proof_of_income" accept=".pdf, .docx, .doc, .jpg, .jpeg, .png" />
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="edit_medical_certificate">Upload Medical Certificate (if applicable) (pdf, docx, doc, jpeg, jpg, png)</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="edit_medical_certificate" type="file" name="medical_certificate" accept=".pdf, .docx, .doc, .jpg, .jpeg, .png" />
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="edit_hospital_bills">Upload Hospital Bills (if applicable) (pdf, docx, doc, jpeg, jpg, png)</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="edit_hospital_bills" type="file" name="hospital_bills" accept=".pdf, .docx, .doc, .jpg, .jpeg, .png" />
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="edit_prescriptions">Upload Prescriptions (if applicable) (pdf, docx, doc, jpeg, jpg, png)</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="edit_prescriptions" type="file" name="prescriptions" accept=".pdf, .docx, .doc, .jpg, .jpeg, .png" />
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="edit_senior_citizen_id">Upload Senior Citizen ID (if applicable) (pdf, docx, doc, jpeg, jpg, png)</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="edit_senior_citizen_id" type="file" name="senior_citizen_id" accept=".pdf, .docx, .doc, .jpg, .jpeg, .png" />
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="edit_osca_certification">Upload OSCA Certification (if applicable) (pdf, docx, doc, jpeg, jpg, png)</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="edit_osca_certification" type="file" name="osca_certification" accept=".pdf, .docx, .doc, .jpg, .jpeg, .png" />
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="edit_pwd_id">Upload PWD ID (if applicable) (pdf, docx, doc, jpeg, jpg, png)</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="edit_pwd_id" type="file" name="pwd_id" accept=".pdf, .docx, .doc, .jpg, .jpeg, .png" />
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="edit_disability_certificate">Upload Disability Certificate (if applicable) (pdf, docx, doc, jpeg, jpg, png)</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="edit_disability_certificate" type="file" name="disability_certificate" accept=".pdf, .docx, .doc, .jpg, .jpeg, .png" />
                </div>
                <button type="submit" class="w-full bg-blue-500 text-white font-bold py-2 rounded-lg hover:bg-blue-600">Update Documents</button>
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

        function openEditModal(applicationId) {
            document.getElementById('application_id').value = applicationId;
            document.getElementById('editModal').style.display = "block";
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = "none";
        }

        window.onclick = function(event) {
            if (event.target == document.getElementById('editModal')) {
                document.getElementById('editModal').style.display = "none";
            }
        }
    </script>
</body>
</html>