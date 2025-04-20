<?php
session_start();
require 'connection.php';

// Check if admin is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: LoginForm.php");
    exit();
}

// Function to log actions in the system logs
function logAction($pdo, $user_id, $action) {
    $sql = "INSERT INTO System_Logs (user_id, action, timestamp) VALUES (?, ?, CURRENT_TIMESTAMP)";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(1, $user_id);
    $stmt->bindParam(2, $action);
    $stmt->execute();
}

// Function to fetch all payment data from the database
function getAllPayments($pdo) {
    $sql = "SELECT * FROM Payment_Process ORDER BY created_at DESC";
    $stmt = $pdo->query($sql);
    $payments = [];
    if ($stmt) {
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $payments[] = $row;
        }
    }
    return $payments;
}

// Function to add a new payment record to the database
function addPayment($pdo, $reference_number, $purpose, $amount_fee) {
    $sql = "INSERT INTO Payment_Process (reference_number, purpose, amount_fee) VALUES (?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(1, $reference_number);
    $stmt->bindParam(2, $purpose);
    $stmt->bindParam(3, $amount_fee);
    if ($stmt->execute()) {
        return true;
    } else {
        return "Error adding payment: " . $stmt->errorInfo()[2];
    }
}

// Function to update payment information in the database
function updatePayment($pdo, $payment_id, $reference_number, $purpose, $amount_fee) {
    $sql = "UPDATE Payment_Process SET reference_number = ?, purpose = ?, amount_fee = ?, updated_at = CURRENT_TIMESTAMP WHERE payment_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(1, $reference_number);
    $stmt->bindParam(2, $purpose);
    $stmt->bindParam(3, $amount_fee);
    $stmt->bindParam(4, $payment_id, PDO::PARAM_INT);
    if ($stmt->execute()) {
        return true;
    } else {
        return "Error updating payment: " . $stmt->errorInfo()[2];
    }
}

// Handle form submissions
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user_id = $_SESSION['user_id']; // Get the logged-in user's ID
    if (isset($_POST['action'])) {
        if ($_POST['action'] == 'add') {
            $reference_number = $_POST['reference_number'];
            $purpose = $_POST['purpose'];
            $amount_fee = $_POST['amount_fee'];
            $result = addPayment($pdo, $reference_number, $purpose, $amount_fee);
            if ($result === true) {
                logAction($pdo, $user_id, 'Added Payment');
                $_SESSION['message'] = "Payment added successfully.";
                $_SESSION['message_type'] = 'success';
            } else {
                $_SESSION['message'] = $result; // Display error message
                $_SESSION['message_type'] = 'danger';
            }
        } elseif ($_POST['action'] == 'edit') {
            if (isset($_POST['edit_payment_id'])) {
                $payment_id = $_POST['edit_payment_id'];
                $reference_number = $_POST['reference_number'];
                $purpose = $_POST['purpose'];
                $amount_fee = $_POST['amount_fee'];
                $result = updatePayment($pdo, $payment_id, $reference_number, $purpose, $amount_fee);
                if ($result === true) {
                    logAction($pdo, $user_id, 'Updated Payment ID: ' . $payment_id);
                    $_SESSION['message'] = "Payment updated successfully.";
                    $_SESSION['message_type'] = 'success';
                } else {
                    $_SESSION['message'] = $result; // Display error message
                    $_SESSION['message_type'] = 'danger';
                }
            } else {
                $_SESSION['message'] = "Error: Payment ID not provided for update.";
                $_SESSION['message_type'] = 'danger';
            }
        }
    }
    header("Location: AdminPayment.php"); // Redirect to refresh the page and show message
    exit();
}

// Fetch all payments for display
$payments = getAllPayments($pdo);
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Admin Dashboard - Payment</title>
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
        body {
            font-family: 'Roboto', sans-serif;
            display: flex;
        }
        .sidebar {
            transition: transform 0.3s ease-in-out;
            z-index: 20; /* Ensure sidebar is above main content when toggled */
        }
        .main-content {
            transition: margin-left 0.3s ease-in-out;
             /* Initial margin to accommodate collapsed sidebar */
        }
        .sidebar.open {
            transform: translateX(0);
        }
        .main-content.open {
            margin-left: 240px; /* Margin when sidebar is open */
        }
        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 30;
        }
        .modal {
            background-color: white;
            padding: 2rem;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            width: 90%;
            max-width: 600px;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        .modal-close-btn {
            cursor: pointer;
            font-size: 1.2rem;
        }
        .alert {
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 0.25rem;
        }
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>
<body class="bg-gray-100 flex">
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
            <h2 class="text-3xl font-bold">Payment Management</h2>
            <button class="text-purple-500 focus:outline-none" onclick="toggleSidebar()">
                <i class="fas fa-bars text-2xl"></i>
            </button>
        </div>
        <main class="main-content bg-gray-100 p-4 md:p-8">
            <div class="max-w-7xl mx-auto">
                <?php if (isset($_SESSION['message'])): ?>
                    <div class="alert alert-<?php echo $_SESSION['message_type']; ?>">
                        <?php echo $_SESSION['message']; ?>
                    </div>
                    <?php unset($_SESSION['message']); unset($_SESSION['message_type']); ?>
                <?php endif; ?>
                <div class="flex justify-between items-center mb-8">
                    <div class="relative w-64">

                        <input type="text" id="searchPayment" class="shadow appearance-none border rounded py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" placeholder="Search by Reference Number"  onkeyup="searchPayments()"/>
                        <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
                            <i class="fas fa-search text-gray-400"></i>
                        </div>
                    </div>
                    <button
                        id="addPaymentBtn"
                        class="bg-indigo-600 hover:bg-indigo-700 text-white rounded px-5 py-2 flex items-center gap-2 shadow"
                        onclick="showAddPaymentModal()"
                    >
                        <i class="fas fa-plus"></i> Add Payment
                    </button>
                </div>

                <div class="overflow-x-auto bg-white rounded shadow">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th
                                    scope="col"
                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                                >
                                    Reference Number
                                </th>
                                <th
                                    scope="col"
                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                                >
                                    Purpose
                                </th>
                                <th
                                    scope="col"
                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                                >
                                    Amount Fee
                                </th>
                                <th
                                    scope="col"
                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                                >
                                    Created At
                                </th>
                                <th
                                    scope="col"
                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                                >
                                    Updated At
                                </th>
                                <th scope="col" class="relative px-6 py-3">
                                    <span class="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody
                            id="paymentTableBody"
                            class="bg-white divide-y divide-gray-200"
                        >
                            <?php if (!empty($payments)): ?>
                                <?php foreach ($payments as $payment): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($payment['reference_number']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $payment['purpose']))); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars(number_format($payment['amount_fee'], 2)); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars(date('Y-m-d H:i:s', strtotime($payment['created_at']))); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars(date('Y-m-d H:i:s', strtotime($payment['updated_at']))); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <button onclick="showEditPaymentModal(<?php echo htmlspecialchars(json_encode($payment)); ?>)" class="text-indigo-600 hover:text-indigo-800">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="px-6 py-4 whitespace-nowrap text-center text-gray-500">No payment records found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>

        <div id="paymentModal" class="modal-overlay hidden">
            <div class="modal">
                <div class="modal-header">
                    <h2 id="modalTitle" class="text-xl font-semibold">Add Payment</h2>
                    <button onclick="closePaymentModal()" class="modal-close-btn">&times;</button>
                </div>
                <div class="modal-body">
                    <form id="paymentForm" action="AdminPayment.php" method="post">
                        <input type="hidden" id="edit_payment_id" name="edit_payment_id">
                        <input type="hidden" name="action" id="modalAction" value="add">
                        <div class="mb-4">
                            <label for="reference_number" class="block text-gray-700 text-sm font-bold mb-2">Reference Number:</label>
                            <input type="text" id="reference_number" name="reference_number" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required>
                        </div>
                        <div class="mb-4">
                            <label for="purpose" class="block text-gray-700 text-sm font-bold mb-2">Purpose:</label>
                            <select id="purpose" name="purpose" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required>
                                <option value="barangay_clearance">Barangay Clearance</option>
                                <option value="business_permit">Business Permit</option>
                            </select>
                        </div>
                        <div class="mb-4">
                            <label for="amount_fee" class="block text-gray-700 text-sm font-bold mb-2">Amount Fee:</label>
                            <input type="number" id="amount_fee" name="amount_fee" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" step="0.01" required>
                        </div>
                        <div class="flex justify-end">
                            <button type="button" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline mr-2" onclick="closePaymentModal()">Cancel</button>
                            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline">Save Payment</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.querySelector('.main-content');
        const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
        const dropdown = document.getElementById('dropdown');
        const paymentModal = document.getElementById('paymentModal');
        const paymentForm = document.getElementById('paymentForm');
        const modalTitle = document.getElementById('modalTitle');
        const paymentTableBody = document.getElementById('paymentTableBody');
        const searchPaymentInput = document.getElementById('searchPayment');
        const editPaymentIdInput = document.getElementById('edit_payment_id');
        const modalActionInput = document.getElementById('modalAction');

        let sidebarVisible = window.innerWidth >= 768; // Initially open on larger screens

        function toggleSidebar() {
            sidebarVisible = !sidebarVisible;
            sidebar.classList.toggle('open', sidebarVisible);
            mainContent.classList.toggle('open', sidebarVisible);
        }

        function toggleDropdown() {
            dropdown.classList.toggle('hidden');
            const btn = document.getElementById('manageApplicationsBtn');
            btn.setAttribute('aria-expanded', (!btn.getAttribute('aria-expanded') || btn.getAttribute('aria-expanded') === 'false').toString());
        }

        function showAddPaymentModal() {
            modalActionInput.value = 'add';
            editPaymentIdInput.value = '';
            modalTitle.textContent = 'Add Payment';
            paymentForm.reset();
            paymentModal.classList.remove('hidden');
        }

        function showEditPaymentModal(payment) {
            modalActionInput.value = 'edit';
            editPaymentIdInput.value = payment.payment_id;
            document.getElementById('reference_number').value = payment.reference_number;
            document.getElementById('purpose').value = payment.purpose;
            document.getElementById('amount_fee').value = payment.amount_fee;
            modalTitle.textContent = 'Edit Payment';
            paymentModal.classList.remove('hidden');
        }

        function closePaymentModal() {
            paymentModal.classList.add('hidden');
        }

        function searchPayments() {
            const searchTerm = searchPaymentInput.value.toLowerCase();
            const rows = paymentTableBody.getElementsByTagName('tr');

            for (let i = 0; i < rows.length; i++) {
                const referenceNumberCell = rows[i].getElementsByTagName('td')[0];
                if (referenceNumberCell) {
                    const textValue = referenceNumberCell.textContent.toLowerCase();
                    if (textValue.includes(searchTerm)) {
                        rows[i].style.display = '';
                    } else {
                        rows[i].style.display = 'none';
                    }
                }
            }
        }

        // Set initial sidebar state based on screen width
        window.addEventListener('resize', () => {
            sidebarVisible = window.innerWidth >= 768;
            sidebar.classList.toggle('open', sidebarVisible);
            mainContent.classList.toggle('open', sidebarVisible);
        });
    </script>
</body>
</html>