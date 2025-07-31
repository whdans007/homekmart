<?php
// Check if OPcache is enabled
if (function_exists('opcache_reset')) {
    // Reset the OPcache
    opcache_reset();
    $message = "PHP OPcache has been successfully reset.";
} else {
    $message = "PHP OPcache is not enabled on this server.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Cache Reset</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 flex items-center justify-center h-screen">
    <div class="max-w-md w-full bg-white shadow-lg rounded-lg p-8 text-center">
        <i class="fas fa-check-circle text-5xl text-green-500 mb-4"></i>
        <h1 class="text-2xl font-bold text-gray-800">Cache Status</h1>
        <p class="mt-2 text-gray-600"><?php echo htmlspecialchars($message); ?></p>
        <p class="mt-4 text-sm text-gray-500">You can now try reloading the page that wasn't updating.</p>
        <a href="product_management.php" class="mt-6 inline-block bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
            Go to Product Management
        </a>
    </div>
</body>
</html>
