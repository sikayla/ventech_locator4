<?php
// **1. Start Session**
session_start();

// **2. Database Connection Parameters**
$host = 'localhost';
$db   = 'ventech_db';
$user_db = 'root';
$pass = '';
$charset = 'utf8mb4';
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE             => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// **3. Establish PDO Connection**
try {
    $pdo = new PDO($dsn, $user_db, $pass, $options);
} catch (PDOException $e) {
    error_log("Database Connection Error in process_reservation_action.php: " . $e->getMessage());
    header("Location: client_dashboard.php?action_error=db_connect_failed");
    exit;
}

// **4. Check User Authentication**
if (!isset($_SESSION['user_id'])) {
    header("Location: client_login.php");
    exit;
}
$loggedInOwnerUserId = $_SESSION['user_id'];

// **5. Retrieve and Validate Input**
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: client_dashboard.php?action_error=invalid_method");
    exit;
}

$reservationId = $_POST['reservation_id'] ?? null;
$action = $_POST['action'] ?? null;

if (!is_numeric($reservationId) || !in_array($action, ['accept', 'reject'])) {
    header("Location: client_dashboard.php?action_error=invalid_input");
    exit;
}

$reservationId = intval($reservationId);

// **6. Authorize Action: Verify Ownership**
try {
    $stmt = $pdo->prepare("SELECT v.user_id FROM reservations r JOIN venue v ON r.venue_id = v.id WHERE r.id = ?");
    $stmt->execute([$reservationId]);
    $reservationVenueOwner = $stmt->fetchColumn();

    if ($reservationVenueOwner !== $loggedInOwnerUserId) {
        error_log("Unauthorized access attempt to reservation ID {$reservationId} by user ID {$loggedInOwnerUserId}");
        header("Location: client_dashboard.php?action_error=unauthorized");
        exit;
    }
} catch (PDOException $e) {
    error_log("Error checking reservation ownership for ID {$reservationId}: " . $e->getMessage());
    header("Location: client_dashboard.php?action_error=db_error_ownership");
    exit;
}

// **7. Update Reservation Status**
$newStatus = '';
if ($action === 'accept') {
    $newStatus = 'confirmed';
} elseif ($action === 'reject') {
    $newStatus = 'rejected';
}

if (empty($newStatus)) {
    header("Location: client_dashboard.php?action_error=invalid_action");
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE reservations SET status = ? WHERE id = ?");
    $stmt->execute([$newStatus, $reservationId]);

    if ($stmt->rowCount() > 0) {
        header("Location: client_dashboard.php?action_success=" . urlencode($action));
        exit;
    } else {
        // No rows were updated, possibly reservation doesn't exist or status is already updated
        header("Location: client_dashboard.php?action_error=update_failed");
        exit;
    }
} catch (PDOException $e) {
    error_log("Error updating reservation status for ID {$reservationId} to {$newStatus}: " . $e->getMessage());
    header("Location: client_dashboard.php?action_error=db_error_update");
    exit;
}

// **8. Fallback: Should not reach here**

?>