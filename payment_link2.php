<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment QR Code</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 flex items-center justify-center h-screen">
    <div class="bg-white p-8 rounded-lg shadow-md text-center">
        <h1 class="text-2xl font-bold mb-4">Payment QR Code</h1>
        <p class="mb-4">Please scan the QR code below to make your payment.</p>
        <img src="https://api.qrserver.com/v1/create-qr-code/?data=Hello%20World&size=200x200" alt="QR Code" class="mx-auto mb-4"> <!-- Placeholder QR Code -->
        <p class="text-lg">Amount: ₱200.00</p> <!-- Adjust the amount as needed -->
        <p class="text-sm text-gray-500">GCash Number: 09123456789</p> <!-- Adjust the GCash number as needed -->
    </div>
</body>
</html>