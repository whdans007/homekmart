<?php
require_once '../config/db_config.php';
require_once '../lib/session_helper.php';

// Check if the user is logged in and is an admin
if (!is_logged_in() || !is_admin()) {
    header('Location: login.php');
    exit();
}

$conn = get_db_connection();

$sql_purchases = "
CREATE TABLE IF NOT EXISTS purchases (
    purchase_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT(11) UNSIGNED NOT NULL,
    purchase_date DATE NOT NULL,
    total_amount DECIMAL(10, 2) NOT NULL,
    total_items INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

$sql_purchase_items = "
CREATE TABLE IF NOT EXISTS purchase_items (
    item_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_id INT UNSIGNED NOT NULL,
    product_id INT(11) UNSIGNED NOT NULL,
    purchase_type ENUM('box', 'piece') NOT NULL DEFAULT 'box',
    quantity INT NOT NULL,
    unit_price DECIMAL(10, 2) NOT NULL,
    FOREIGN KEY (purchase_id) REFERENCES purchases(purchase_id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

$sql_alter_products = "
ALTER TABLE products
ADD COLUMN IF NOT EXISTS pieces_per_box INT DEFAULT 1,
ADD COLUMN IF NOT EXISTS barcode VARCHAR(255) DEFAULT NULL,
ADD UNIQUE INDEX IF NOT EXISTS idx_barcode (barcode);
";

$message = "";

try {
    $conn->begin_transaction();

    if ($conn->query($sql_purchases) === TRUE) {
        $message .= "Table 'purchases' created successfully or already exists.<br>";
    } else {
        throw new Exception("Error creating table 'purchases': " . $conn->error);
    }

    if ($conn->query($sql_purchase_items) === TRUE) {
        $message .= "Table 'purchase_items' created successfully or already exists.<br>";
    } else {
        throw new Exception("Error creating table 'purchase_items': " . $conn->error);
    }

    if ($conn->query($sql_alter_products) === TRUE) {
        $message .= "Table 'products' altered successfully to add 'pieces_per_box' and 'barcode' columns.<br>";
    } else {
        throw new Exception("Error altering table 'products': " . $conn->error);
    }

    $conn->commit();
    $message .= "Database setup for purchases completed successfully.";

} catch (Exception $e) {
    $conn->rollback();
    $message = "An error occurred: " . $e->getMessage();
}

$conn->close();

?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase System Setup</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100">
    <div class="container mx-auto mt-10">
        <div class="bg-white p-8 rounded-lg shadow-md">
            <h1 class="text-2xl font-bold mb-4">Purchase System Database Setup</h1>
            <div class="bg-gray-200 p-4 rounded">
                <p><?php echo $message; ?></p>
            </div>
            <a href="index.php" class="mt-4 inline-block bg-blue-500 text-white font-bold py-2 px-4 rounded hover:bg-blue-700">Go to Home</a>
        </div>
    </div>
</body>
</html>
