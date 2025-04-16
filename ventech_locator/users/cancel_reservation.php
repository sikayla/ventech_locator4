<?php
session_start();

// Include database connection parameters
$host = 'localhost';
$db   = 'ventech_db';
$user_db = 'root';
$pass = '';
$charset = 'utf8mb4';
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE         => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// Check if user is logged in
$user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;
if ($user_id === null) {
    header("Location: login.php"); // Or handle unauthorized access appropriately
    exit();
}

// Check if reservation ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: reservations_list.php?cancel_error=Invalid reservation ID.");
    exit();
}

$reservation_id = intval($_GET['id']);

try {
    $pdo = new PDO($dsn, $user_db, $pass, $options);

    // Check if the reservation belongs to the logged-in user and is currently pending
    $stmt_check = $pdo->prepare("
        SELECT id, status
        FROM reservations
        WHERE id = :reservation_id AND user_id = :user_id
    ");
    $stmt_check->bindParam(':reservation_id', $reservation_id, PDO::PARAM_INT);
    $stmt_check->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt_check->execute();
    $reservation = $stmt_check->fetch();

    if (!$reservation) {
        header("Location: reservations_list.php?cancel_error=Reservation not found or does not belong to you.");
        exit();
    }

    if ($reservation['status'] !== 'pending') {
        header("Location: reservations_list.php?cancel_error=Only pending reservations can be cancelled.");
        exit();
    }

    // Update the reservation status to 'cancelled'
    $stmt_update = $pdo->prepare("
        UPDATE reservations
        SET status = 'cancelled'
        WHERE id = :reservation_id
    ");
    $stmt_update->bindParam(':reservation_id', $reservation_id, PDO::PARAM_INT);
    $stmt_update->execute();

    header("Location: reservations_list.php?cancel_success=true");
    exit();

} catch (PDOException $e) {
    error_log("Database error cancelling reservation: " . $e->getMessage());
    header("Location: reservations_list.php?cancel_error=An error occurred while cancelling the reservation. Please try again later.");
    exit();
} finally {
    $pdo = null; // Close connection
}
?>