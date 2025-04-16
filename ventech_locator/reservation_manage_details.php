<?php
session_start();

// Database connection parameters
$host = 'localhost';
$db   = 'ventech_db';
$user_db = 'root';
$pass = '';
$charset = 'utf8mb4';
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// Establish PDO connection
try {
    $pdo = new PDO($dsn, $user_db, $pass, $options);
} catch (PDOException $e) {
    error_log("Database Connection Error in reservation_manage_details.php: " . $e->getMessage());
    die("Database connection failed. Please try again later.");
}

// Check user authentication
if (!isset($_SESSION['user_id'])) {
    header("Location: client_login.php");
    exit;
}
$loggedInOwnerUserId = $_SESSION['user_id'];

// Function to get status badge class (reused from client_dashboard.php)
function getStatusBadgeClass($status) {
    $status = strtolower($status ?? 'unknown');
    switch ($status) {
        case 'open': case 'confirmed': return 'bg-green-100 text-green-800';
        case 'closed': case 'cancelled': case 'rejected': return 'bg-red-100 text-red-800';
        case 'pending': return 'bg-yellow-100 text-yellow-800';
        default: return 'bg-gray-100 text-gray-800';
    }
}

// Initialize messages
$update_success_message = "";
$update_error_message = "";

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $reservation_id = filter_input(INPUT_POST, 'reservation_id', FILTER_SANITIZE_NUMBER_INT);
    $new_status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
    $allowed_statuses = ['pending', 'confirmed', 'rejected', 'cancelled'];

    if ($reservation_id && in_array($new_status, $allowed_statuses)) {
        try {
            // Verify if the logged-in user owns the venue for this reservation
            $stmt_check_ownership = $pdo->prepare("
                SELECT COUNT(*)
                FROM reservations r
                JOIN venue v ON r.venue_id = v.id
                WHERE r.id = ? AND v.user_id = ?
            ");
            $stmt_check_ownership->execute([$reservation_id, $loggedInOwnerUserId]);
            $is_owner = $stmt_check_ownership->fetchColumn();

            if ($is_owner) {
                $stmt_update = $pdo->prepare("UPDATE reservations SET status = ? WHERE id = ?");
                $stmt_update->execute([$new_status, $reservation_id]);

                if ($stmt_update->rowCount() > 0) {
                    $update_success_message = "Reservation status updated to: " . htmlspecialchars(ucfirst($new_status));
                } else {
                    $update_error_message = "Failed to update reservation status. Please try again.";
                }
            } else {
                $update_error_message = "You are not authorized to manage this reservation.";
            }
        } catch (PDOException $e) {
            error_log("Error updating reservation status (ID: $reservation_id, User ID: $loggedInOwnerUserId): " . $e->getMessage());
            $update_error_message = "An error occurred while updating the status. Please contact support.";
        }
    } else {
        $update_error_message = "Invalid reservation ID or status.";
    }
}

// Fetch reservation details
$reservation = null;
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $reservation_id = filter_var($_GET['id'], FILTER_SANITIZE_NUMBER_INT);
    try {
        $stmt = $pdo->prepare("
            SELECT
                r.id, r.event_date, r.status, r.created_at,
                v.id as venue_id, v.title as venue_title,
                u.id as booker_user_id, u.username as booker_username, u.email as booker_email
            FROM reservations r
            JOIN venue v ON r.venue_id = v.id
            JOIN users u ON r.user_id = u.id
            WHERE r.id = ? AND v.user_id = ?
        ");
        $stmt->execute([$reservation_id, $loggedInOwnerUserId]);
        $reservation = $stmt->fetch();

        if (!$reservation) {
            // Check if the reservation exists at all (regardless of ownership) for a better error message
            $stmt_check_existence = $pdo->prepare("SELECT id FROM reservations WHERE id = ?");
            $stmt_check_existence->execute([$reservation_id]);
            if ($stmt_check_existence->fetch()) {
                $error_message = "You are not authorized to view details for this reservation.";
            } else {
                $error_message = "Reservation not found.";
            }
        }
    } catch (PDOException $e) {
        error_log("Error fetching reservation details (ID: $reservation_id, User ID: $loggedInOwnerUserId): " . $e->getMessage());
        $error_message = "An error occurred while fetching reservation details. Please try again later.";
    }
} else {
    $error_message = "Invalid reservation ID.";
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Booking Details - Ventech Locator</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Roboto', sans-serif; }
        nav { position: sticky; top: 0; z-index: 10; }
        .container { max-width: 960px; margin: 0 auto; padding: 20px; }
        .shadow-md { box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06); }
        .rounded-md { border-radius: 0.375rem; }
        .bg-white { background-color: #fff; }
        .p-4 { padding: 1rem; }
        .mb-4 { margin-bottom: 1rem; }
        .font-semibold { font-weight: 600; }
        .text-gray-800 { color: #2d3748; }
        .text-gray-600 { color: #4a5568; }
        .text-blue-600 { color: #2563eb; }
        .hover\:text-blue-800:hover { color: #1a4599; }
        .block { display: block; }
        .mt-4 { margin-top: 1rem; }
        .border { border: 1px solid #e0e6ed; }
        .rounded-lg { border-radius: 0.5rem; }
        .overflow-hidden { overflow: hidden; }
        .table-auto { table-layout: auto; }
        .w-full { width: 100%; }
        .text-sm { font-size: 0.875rem; }
        .text-left { text-align: left; }
        .bg-gray-100 { background-color: #f7fafc; }
        .text-xs { font-size: 0.75rem; }
        .uppercase { text-transform: uppercase; }
        .px-6 { padding-left: 1.5rem; padding-right: 1.5rem; }
        .py-3 { padding-top: 0.75rem; padding-bottom: 0.75rem; }
        .font-medium { font-weight: 500; }
        .text-gray-900 { color: #1a202c; }
        .whitespace-nowrap { white-space: nowrap; }
        .border-b { border-bottom: 1px solid #e0e6ed; }
        .hover\:bg-gray-50:hover { background-color: #f9fafb; }
        .inline-block { display: inline-block; }
        .bg-green-100 { background-color: #f0fff4; }
        .text-green-800 { color: #2f6f44; }
        .bg-red-100 { background-color: #fff5f5; }
        .text-red-800 { color: #b91c1c; }
        .bg-yellow-100 { background-color: #fffbeb; }
        .text-yellow-800 { color: #a16207; }
        .bg-gray-100 { background-color: #f7fafc; }
        .text-gray-800 { color: #2d3748; }
        .shadow { box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06); }
        .rounded { border-radius: 0.25rem; }
        .p-2 { padding: 0.5rem; }
        .text-center { text-align: center; }
        .mt-6 { margin-top: 1.5rem; }
        .bg-orange-600 { background-color: #ea580c; }
        .text-white { color: #fff; }
        .flex { display: flex; }
        .items-center { align-items: center; }
        .justify-between { justify-content: space-between; }
        .mr-4 { margin-right: 1rem; }
        .bg-orange-50 { background-color: #fff7ed; }
        .hover\:text-orange-600:hover { color: #ea580c; }
        .mr-2 { margin-right: 0.5rem; }
        .select-none { user-select: none; }
        .cursor-pointer { cursor: pointer; }
    </style>
</head>
<body class="bg-gray-100">

    <nav class="bg-orange-600 p-4 text-white shadow-md">
        <div class="container mx-auto flex justify-between items-center">
            <a href="/ventech_locator/client/client_dashboard.php" class="text-xl font-bold hover:text-orange-200">Ventech Locator</a>
            <div>
                <span class="mr-4 hidden sm:inline">Welcome, <?= htmlspecialchars($_SESSION['username'] ?? 'Owner') ?>!</span>
                <a href="client_logout.php" class="bg-white text-orange-600 hover:bg-gray-200 py-1 px-3 rounded text-sm font-medium transition duration-150 ease-in-out shadow">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container mt-6">
        <h1 class="text-2xl font-semibold text-gray-800 mb-4">Booking Request Details</h1>

        <?php if ($update_success_message): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded-md mb-4 shadow" role="alert">
                <p class="font-bold">Success!</p>
                <p><?= $update_success_message ?></p>
            </div>
        <?php endif; ?>

        <?php if ($update_error_message): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-md mb-4 shadow" role="alert">
                <p class="font-bold">Error!</p>
                <p><?= $update_error_message ?></p>
            </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-md mb-4 shadow" role="alert">
                <p class="font-bold">Error!</p>
                <p><?= $error_message ?></p>
            </div>
        <?php elseif ($reservation): ?>
            <div class="bg-white shadow-md rounded-lg overflow-hidden">
                <div class="p-6">
                    <h2 class="text-xl font-semibold text-gray-800 mb-3">Reservation Details</h2>
                    <p class="text-gray-600 mb-2"><span class="font-semibold">Reservation ID:</span> <?= htmlspecialchars($reservation['id']) ?></p>
                    <p class="text-gray-600 mb-2"><span class="font-semibold">Venue:</span> <a href="/ventech_locator/venue_display.php?id=<?= $reservation['venue_id'] ?>" class="text-blue-600 hover:text-blue-800"><?= htmlspecialchars($reservation['venue_title']) ?></a></p>
                    <p class="text-gray-600 mb-2"><span class="font-semibold">Booker:</span> <?= htmlspecialchars($reservation['booker_username']) ?> (<?= htmlspecialchars($reservation['booker_email']) ?>)</p>
                    <p class="text-gray-600 mb-2"><span class="font-semibold">Event Date:</span> <?= htmlspecialchars(date("D, M d, Y", strtotime($reservation['event_date']))) ?></p>
                    <p class="text-gray-600 mb-2"><span class="font-semibold">Requested On:</span> <?= htmlspecialchars(date("M d, Y H:i", strtotime($reservation['created_at']))) ?></p>
                    <p class="text-gray-600 mb-4"><span class="font-semibold">Status:</span> <span class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold <?= getStatusBadgeClass($reservation['status']) ?>"><?= htmlspecialchars(ucfirst($reservation['status'])) ?></span></p>

                    <h3 class="text-lg font-semibold text-gray-800 mt-4 mb-2">Update Status</h3>
                    <form method="post" action="">
                        <input type="hidden" name="reservation_id" value="<?= htmlspecialchars($reservation['id']) ?>">
                        <div class="mb-4">
                            <label for="status" class="block text-gray-700 text-sm font-bold mb-2">New Status:</label>
                            <select name="status" id="status" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                                <option value="pending" <?= ($reservation['status'] === 'pending') ? 'selected' : '' ?>>Pending</option>
                                <option value="confirmed" <?= ($reservation['status'] === 'confirmed') ? 'selected' : '' ?>>Confirmed</option>
                                <option value="rejected" <?= ($reservation['status'] === 'rejected') ? 'selected' : '' ?>>Rejected</option>
                                <option value="cancelled" <?= ($reservation['status'] === 'cancelled') ? 'selected' : '' ?>>Cancelled</option>
                            </select>
                        </div>
                        <div>
                            <button type="submit" name="update_status" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline">Update Status</button>
                            <a href="reservations_manage.php" class="inline-block bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded ml-2">Back to Bookings</a>
                        </div>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <p class="text-gray-600">No reservation details found.</p>
        <?php endif; ?>
    </div>

</body>
</html>