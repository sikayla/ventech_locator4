// /ventech_locator/includes/db_connection.php
<?php
// Database connection settings
$host = 'localhost';
$db = 'ventech_db';   // Change to your database name
$user = 'root';       // Change to your database username
$pass = '';           // Change to your database password
$charset = 'utf8mb4';

// Data Source Name (DSN) for MySQL
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

// Options for PDO connection
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,  // Enable exceptions for errors
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, // Default fetch mode to associative array
];

// Establish the PDO connection
try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage()); // Handle connection errors
}
?>
