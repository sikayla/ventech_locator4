<?php
// **1. Start Session**
session_start();

// **2. Database Connection Parameters** (Consider a separate config file)
$host = 'localhost';
$db   = 'ventech_db'; // Use your actual database name
$user_db = 'root';     // Use your actual database username
$pass = '';             // Use your actual database password
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
    error_log("Database Connection Error in user_dashboard: " . $e->getMessage());
    die("Sorry, we're experiencing technical difficulties. Please try again later.");
}

// **4. Check User Authentication**
// Assuming user ID is stored in 'user_id' session variable after login
if (!isset($_SESSION['user_id'])) {
    header("Location: user_login.php"); // Redirect to user login page
    exit;
}
$user_id = $_SESSION['user_id'];

// **5. Fetch Logged-in User Details**
try {
    $stmt = $pdo->prepare("SELECT id, username, role FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!$user) {
        // Invalid user ID in session, log out
        error_log("Invalid user_id {$user_id} in session (user_dashboard).");
        session_unset();
        session_destroy();
        header("Location: user_login.php?error=invalid_session");
        exit;
    }
} catch (PDOException $e) {
    error_log("Error fetching user details for user ID {$user_id}: " . $e->getMessage());
    die("Error loading your user information. Please try again later.");
}

// **6. Fetch User's Reservations**
$reservations = [];
$upcoming_reservations_count = 0;
$pending_reservations_count = 0;
$today_date = date('Y-m-d'); // Get today's date for comparison

try {
    $stmt = $pdo->prepare(
        "SELECT r.id, r.event_date, r.status, r.created_at,
                 v.id as venue_id, v.title as venue_title
          FROM reservations r
          JOIN venue v ON r.venue_id = v.id
          WHERE r.user_id = ?
          ORDER BY r.event_date DESC, r.created_at DESC" // Show most recent event dates first
    );
    $stmt->execute([$user_id]);
    $reservations = $stmt->fetchAll();

    // Calculate counts for dashboard stats
    foreach ($reservations as $res) {
        $status_lower = strtolower($res['status'] ?? '');
        if ($status_lower === 'pending') {
            $pending_reservations_count++;
        }
        // Count as upcoming if confirmed and date is today or later
        if ($status_lower === 'confirmed' && ($res['event_date'] ?? '1970-01-01') >= $today_date) {
             $upcoming_reservations_count++;
        }
    }

} catch (PDOException $e) {
    error_log("Error fetching reservations for user $user_id: " . $e->getMessage());
    // $reservations remains empty, user will see a "no reservations" message
}

// --- Helper function for status badges (reused from client_dashboard) ---
function getStatusBadgeClass($status) {
    $status = strtolower($status ?? 'unknown');
    switch ($status) {
        case 'confirmed': return 'bg-green-100 text-green-800';
        case 'cancelled': case 'rejected': return 'bg-red-100 text-red-800';
        case 'pending': return 'bg-yellow-100 text-yellow-800';
        default: return 'bg-gray-100 text-gray-800'; // For completed or other statuses
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Dashboard - Ventech Locator</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Roboto', sans-serif; }
        .sidebar-link { transition: background-color 0.2s ease-in-out, color 0.2s ease-in-out; }
        /* Style for sticky elements */
        nav { position: sticky; top: 0; z-index: 10; }
        aside { position: sticky; top: 64px; /* Height of nav */ height: calc(100vh - 64px); }
        main { min-height: calc(100vh - 64px); }
    </style>
</head>
<body class="bg-gray-100">

    <nav class="bg-indigo-600 p-4 text-white shadow-md">
        <div class="container mx-auto flex justify-between items-center">
            <a href="/ventech_locator/index.php" class="text-xl font-bold hover:text-indigo-200">Ventech Locator</a>
            <div>
                <span class="mr-4 hidden sm:inline">Welcome, <?= htmlspecialchars($user['username']) ?>!</span>
                <a href="/ventech_locator/users/user_logout.php" class="bg-white text-indigo-600 hover:bg-gray-200 py-1 px-3 rounded text-sm font-medium transition duration-150 ease-in-out shadow">Logout</a>
            </div>
        </div>
    </nav>

    <div class="flex">
        <aside class="w-64 bg-white p-5 shadow-lg flex flex-col flex-shrink-0">
            <h2 class="text-lg font-semibold mb-5 border-b pb-3 text-gray-700">Menu</h2>
            <ul class="space-y-2 flex-grow">
                <li><a href="reservations_list.php" class="sidebar-link flex items-center text-gray-700 hover:text-indigo-600 hover:bg-indigo-50 rounded p-2"><i class="fas fa-calendar-check fa-fw mr-3 w-5 text-center"></i>My Reservations</a></li>
                <li><a href="user_dashboard.php" class="sidebar-link flex items-center text-gray-700 font-semibold bg-indigo-50 rounded p-2"><i class="fas fa-home fa-fw mr-3 w-5 text-center text-indigo-600"></i>Dashboard</a></li>
                <li><a href="reservations_list.php" class="sidebar-link flex items-center text-gray-700 hover:text-indigo-600 hover:bg-indigo-50 rounded p-2"><i class="fas fa-calendar-check fa-fw mr-3 w-5 text-center"></i>My Reservations</a></li>
                <li><a href="/ventech_locator/user_venue_list.php" class="sidebar-link flex items-center text-gray-700 hover:text-indigo-600 hover:bg-indigo-50 rounded p-2"><i class="fas fa-search-location fa-fw mr-3 w-5 text-center"></i>Find Venues</a></li>
                <li><a href="user_profile.php" class="sidebar-link flex items-center text-gray-700 hover:text-indigo-600 hover:bg-indigo-50 rounded p-2"><i class="fas fa-user-circle fa-fw mr-3 w-5 text-center"></i>Profile</a></li>
            </ul>
            <div class="mt-auto pt-4 border-t">
                <a href="user_logout.php" class="sidebar-link flex items-center text-gray-700 hover:text-red-600 hover:bg-red-50 rounded p-2"><i class="fas fa-sign-out-alt fa-fw mr-3 w-5 text-center"></i>Logout</a>
            </div>
        </aside>

        <main class="flex-1 p-6 md:p-8 lg:p-10 overflow-y-auto bg-gray-50">
            <h1 class="text-3xl font-bold text-gray-800 mb-6">User Dashboard</h1>

            <section class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                <div class="bg-white p-5 rounded-lg shadow hover:shadow-md transition-shadow">
                    <h3 class="text-lg font-semibold text-gray-600 mb-2 flex items-center"><i class="fas fa-calendar-alt mr-2 text-blue-500"></i>Total Reservations</h3>
                    <p class="text-3xl font-bold text-blue-600"><?= count($reservations) ?></p>
                </div>
                <div class="bg-white p-5 rounded-lg shadow hover:shadow-md transition-shadow">
                     <h3 class="text-lg font-semibold text-gray-600 mb-2 flex items-center"><i class="fas fa-calendar-day mr-2 text-green-500"></i>Upcoming Events</h3>
                    <p class="text-3xl font-bold text-green-600"><?= $upcoming_reservations_count ?></p>
                     <p class="text-xs text-gray-500 mt-1">Confirmed reservations for today or later.</p>
                </div>
                <div class="bg-white p-5 rounded-lg shadow hover:shadow-md transition-shadow">
                    <h3 class="text-lg font-semibold text-gray-600 mb-2 flex items-center"><i class="fas fa-hourglass-half mr-2 text-yellow-500"></i>Pending Requests</h3>
                    <p class="text-3xl font-bold text-yellow-600"><?= $pending_reservations_count ?></p>
                     <p class="text-xs text-gray-500 mt-1">Awaiting confirmation from venue owner.</p>
                </div>
            </section>

            <section class="mb-8">
                <div class="bg-gradient-to-r from-indigo-500 to-purple-600 text-white p-6 rounded-lg shadow-lg flex flex-col md:flex-row justify-between items-center">
                    <div>
                        <h2 class="text-2xl font-bold mb-2">Ready to book a new venue?</h2>
                        <p class="mb-4 md:mb-0">Find the perfect space for your next event.</p>
                    </div>
                    <a href="/ventech_locator/user_venue_list.php" class="bg-white text-indigo-600 hover:bg-gray-100 font-bold py-2 px-5 rounded-full shadow transition duration-300 ease-in-out transform hover:scale-105 flex items-center">
                        <i class="fas fa-search mr-2"></i> Find Venues
                    </a>
                </div>
            </section>

            <section>
                <h2 class="text-2xl font-semibold text-gray-800 mb-4 border-b pb-2">Your Recent Reservations</h2>
                <div class="bg-white shadow-md rounded-lg overflow-x-auto">
                    <?php if (count($reservations) > 0): ?>
                        <table class="w-full table-auto text-sm text-left">
                            <thead class="bg-gray-100 text-xs text-gray-600 uppercase">
                                <tr>
                                    <th scope="col" class="px-6 py-3">Venue</th>
                                    <th scope="col" class="px-6 py-3">Event Date</th>
                                    <th scope="col" class="px-6 py-3">Status</th>
                                    <th scope="col" class="px-6 py-3">Reserved On</th>
                                    <th scope="col" class="px-6 py-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($reservations, 0, 5) as $reservation): // Display recent 5 ?>
                                <tr class="bg-white border-b hover:bg-gray-50">
                                    <td class="px-6 py-4 font-medium text-gray-900 whitespace-nowrap">
                                        <?php // Link to venue display page if available ?>
                                        <a href="/ventech_locator/venue_display.php?id=<?= $reservation['venue_id'] ?>" class="hover:text-indigo-600" title="View Venue Details">
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
                                        <a href="/ventech_locator/reservation_details.php?id=<?= $reservation['id'] ?>" class="text-indigo-600 hover:text-indigo-800 text-xs font-medium">View Details</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if(count($reservations) > 5): ?>
                        <div class="p-4 text-center border-t">
                            <a href="/ventech_locator/users/reservations_list.php" class="text-indigo-600 hover:text-indigo-800 text-sm font-medium">View All Reservations &rarr;</a>
                        </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="p-6 text-center text-gray-600">You haven't made any reservations yet.</p>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>

</body>
</html>