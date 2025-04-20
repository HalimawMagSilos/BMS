<?php
// Start the session
session_start();

// Include the database connection file
require 'connection.php';

// Include PHPMailer autoload file
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Initialize variables
$email = '';
$email_error = '';
$success_message = '';

// Function to send email using PHPMailer
function sendResetEmail($email)
{
    $mail = new PHPMailer(true); // Passing `true` enables exceptions

    try {
        //Server settings
        $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = 'ellema.darrell17@gmail.com';
                $mail->Password = 'mvwq ilib rppn ftjk';
                $mail->SMTPSecure = 'tls';
                $mail->Port = 587;                               // TCP port to connect to, use 465 for `PHPMailer::ENCRYPTION_SMTPS` above

        //Recipients
        $mail->setFrom('ellema.darrell17@gmail.com', 'Barangay Management System'); // Set sender email and name
        $mail->addAddress($email);                               // Add the recipient from the form input

        // Content
        $mail->isHTML(true);                                  // Set email format to HTML
        $mail->Subject = 'Password Reset Request';
        $resetLink = "http://localhost/BARANGAY%20MANAGEMENT%20SYSTEM/views/dashboard/reset.php?email=" . urlencode($email); // Create the reset link
        $mail->Body    = 'To reset your password, please click the following link: <a href="' . $resetLink . '">' . $resetLink . '</a>';
        $mail->AltBody = 'To reset your password, please visit this link: ' . $resetLink;

        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Check if the form is submitted for email
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email_error = "Invalid email format.";
    } else {
        // Check if the email exists in the database
        $stmt = $pdo->prepare("SELECT user_id FROM Users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if ($user) {
            // Send the reset email
            if (sendResetEmail($email)) {
                $success_message = "A reset link has been sent to your email.";
            } else {
                $email_error = "Failed to send email. Please try again.";
            }
        } else {
            $email_error = "Email not found.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barangay Management System - Forgot Password</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Roboto', sans-serif;
        }
    </style>
</head>

<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="bg-white shadow-lg rounded-lg flex w-full max-w-4xl overflow-hidden">
        <div class="w-1/2 hidden md:block">
            <img alt="A scenic view of a barangay with houses and trees" class="w-full h-full object-cover"
                src="https://storage.googleapis.com/a1aa/image/8guJdJ9kl_e9mzmzy8s-j3imgf20P1BH9L5QUlbX-f8.jpg" />
        </div>
        <div class="w-full md:w-1/2 p-10 flex flex-col justify-center">
            <div class="text-center mb-6">
                <img alt="Barangay Management System Logo" class="mx-auto mb-4" height="100"
                    src="https://storage.googleapis.com/a1aa/image/ZlS8sK0YNai5MGRJForAKu4_20-Z3HeENsCVvw_X1Vk.jpg"
                    width="100">
                <h2 class="text-3xl font-bold text-gray-800">Barangay Management System</h2>
            </div>
            <form method="POST" action="">
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2" for="email">Email</label>
                    <input class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                        id="email" name="email" placeholder="Enter your email" type="email"
                        value="<?php echo htmlspecialchars($email); ?>" />
                    <?php if ($email_error): ?>
                        <p class="text-red-500 text-xs italic">
                            <?php echo $email_error; ?>
                        </p>
                    <?php endif; ?>
                </div>
                <div class="flex items-center justify-between mb-6">
                    <a class="text-sm text-blue-500 hover:underline" href="LoginForm.php">Back to Login</a>
                </div>
                <button
                    class="w-full bg-blue-500 text-white py-2 rounded-lg hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    type="submit">Reset Password</button>
                <?php if ($success_message): ?>
                    <p class="text-green-500 text-xs italic mt-4">
                        <?php echo $success_message; ?>
                    </p>
                <?php endif; ?>
            </form>
        </div>
    </div>

</body>

</html>