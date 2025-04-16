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
    error_log("Database Connection Error in reservations_manage.php: " . $e->getMessage());
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

// Fetch reservations for venues owned by the logged-in user
$reservations = [];
$status_filter = $_GET['status'] ?? 'all';
$allowed_statuses = ['all', 'pending', 'confirmed', 'rejected', 'cancelled'];

if (in_array($status_filter, $allowed_statuses)) {
    try {
        $sql = "SELECT
                    r.id, r.event_date, r.status, r.created_at,
                    v.id as venue_id, v.title as venue_title,
                    u.id as booker_user_id, u.username as booker_username, u.email as booker_email
                FROM reservations r
                JOIN venue v ON r.venue_id = v.id
                JOIN users u ON r.user_id = u.id
                WHERE v.user_id = ?";

        $params = [$loggedInOwnerUserId];

        if ($status_filter !== 'all') {
            $sql .= " AND r.status = ?";
            $params[] = $status_filter;
        }

        $sql .= " ORDER BY r.event_date DESC, r.created_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $reservations = $stmt->fetchAll();

    } catch (PDOException $e) {
        error_log("Error fetching reservations for user $loggedInOwnerUserId (status: $status_filter): " . $e->getMessage());
        $error_message = "An error occurred while fetching reservations. Please try again later.";
    }
} else {
    $error_message = "Invalid status filter.";
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Bookings - Ventech Locator</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Roboto', sans-serif; }
        nav { position: sticky; top: 0; z-index: 10; }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
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
        <h1 class="text-2xl font-semibold text-gray-800 mb-4">Manage Booking Requests</h1>

        <div class="mb-4">
            <label for="status-filter" class="text-sm text-gray-600 mr-2">Filter by status:</label>
            <select id="status-filter" onchange="window.location.href='reservations_manage.php?status='+this.value" class="text-sm border-gray-300 rounded-md shadow-sm focus:border-orange-500 focus:ring focus:ring-orange-200 focus:ring-opacity-50 py-1.5 px-3">
                <option value="all" <?= ($status_filter ?? 'all') === 'all' ? 'selected' : '' ?>>All</option>
                <option value="pending" <?= ($status_filter ?? '') === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="confirmed" <?= ($status_filter ?? '') === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                <option value="rejected" <?= ($status_filter ?? '') === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                <option value="cancelled" <?= ($status_filter ?? '') === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
            </select>
        </div>

        <?php if (isset($error_message)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-md mb-4 shadow" role="alert">
                <p class="font-bold">Error!</p>
                <p><?= $error_message ?></p>
            </div>
        <?php endif; ?>

        <div class="bg-white shadow-md rounded-lg overflow-x-auto">
            <?php if (count($reservations) > 0): ?>
                <table class="w-full table-auto text-sm text-left">
                    <thead class="bg-gray-100 text-xs text-gray-600 uppercase">
                        <tr>
                            <th scope="col" class="px-6 py-3">Booker</th>
                            <th scope="col" class="px-6 py-3">Venue</th>
                            <th scope="col" class="px-6 py-3">Event Date</th>
                            <th scope="col" class="px-6 py-3">Status</th>
                            <th scope="col" class="px-6 py-3">Requested On</th>
                            <th scope="col" class="px-6 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reservations as $reservation): ?>
                            <tr class="bg-white border-b hover:bg-gray-50">
                                <td class="px-6 py-4 font-medium text-gray-900 whitespace-nowrap" title="<?= htmlspecialchars($reservation['booker_email'] ?? '') ?>">
                                    <?= htmlspecialchars($reservation['booker_username'] ?? 'N/A') ?>
                                </td>
                                <td class="px-6 py-4 font-medium text-gray-700 whitespace-nowrap">
                                    <a href="/ventech_locator/venue_display.php?id=<?= $reservation['venue_id'] ?>" class="hover:text-blue-600">
                                        <?= htmlspecialchars($reservation['venue_title'] ?? 'N/A') ?>
                                    </a>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?= htmlspecialchars(date("D, M d, Y", strtotime($reservation['event_date']))) ?>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-0.5 inline-block rounded-full text-xs font-semibold <?= getStatusBadgeClass($reservation['status']) ?>">
                                        <?= htmlspecialchars(ucfirst($reservation['status'] ?? 'N/A')) ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-gray-600 whitespace-nowrap">
                                    <?= htmlspecialchars(date("M d, Y H:i", strtotime($reservation['created_at']))) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <a href="reservation_manage_details.php?id=<?= $reservation['id'] ?>" class="text-blue-600 hover:text-blue-800 text-xs font-medium">Manage</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="p-6 text-center text-gray-600">No booking requests found for your venues with the selected filter.</p>
            <?php endif; ?>
        </div>

        <div class="mt-6">
            <a href="client_dashboard.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded">
                Back to Dashboard
            </a>
        </div>
    </div>

</body>
</html>