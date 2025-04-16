```php
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
    PDO::ATTR_ERRMODE                => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES     => false,
];

// **3. Establish PDO Connection**
try {
    $pdo = new PDO($dsn, $user_db, $pass, $options);
} catch (PDOException $e) {
    error_log("Database Connection Error in client_dashboard: " . $e->getMessage());
    die("Database connection failed. We're working on fixing it. Please try again later.");
}

// **4. Check User Authentication**
if (!isset($_SESSION['user_id'])) {
    header("Location: client_login.php");
    exit;
}
$loggedInOwnerUserId = $_SESSION['user_id']; // Renamed for clarity in this context

// **5. Fetch Logged-in User (Owner) Details**
try {
    $stmt = $pdo->prepare("SELECT id, username, role FROM users WHERE id = ?");
    $stmt->execute([$loggedInOwnerUserId]);
    $owner = $stmt->fetch(); // Renamed for clarity

    if (!$owner) {
        error_log("Invalid user_id in session: " . $loggedInOwnerUserId);
        session_unset();
        session_destroy();
        header("Location: client_login.php?error=invalid_session");
        exit;
    }
} catch (PDOException $e) {
    error_log("Error fetching user details for user ID {$loggedInOwnerUserId}: " . $e->getMessage());
    die("Error loading your information. Please try refreshing the page or contact support.");
}

// **6. Fetch Venues Owned by the Logged-in User**
// This part remains the same - fetches venues WHERE venue.user_id = loggedInOwnerUserId
$venues = [];
$venue_ids_owned = []; // Keep track of owned venue IDs for the reservation query
try {
    $status_filter = $_GET['status'] ?? 'all';
    $allowed_statuses = ['open', 'closed'];

    $sql = "SELECT id, title, price, status, reviews, image_path, created_at FROM venue WHERE user_id = ?";
    $params = [$loggedInOwnerUserId];

    if (in_array($status_filter, $allowed_statuses)) {
        $sql .= " AND status = ?";
        $params[] = $status_filter;
    }
    $sql .= " ORDER BY created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $venues = $stmt->fetchAll();

    // Extract owned venue IDs
    $venue_ids_owned = array_column($venues, 'id');

} catch (PDOException $e) {
    error_log("Error fetching venues for user $loggedInOwnerUserId (status: $status_filter): " . $e->getMessage());
}


// **7. Fetch Reservations FOR Owned Venues** (MODIFIED QUERY)
$venue_reservations = []; // Renamed for clarity
$pending_reservations_count = 0; // Initialize count

if (!empty($venue_ids_owned)) { // Only query if the user owns any venues
    try {
        // Prepare placeholders for IN clause based on owned venue IDs
        $in_placeholders = implode(',', array_fill(0, count($venue_ids_owned), '?'));

        $sql = "SELECT
                    r.id, r.event_date, r.status, r.created_at,
                    v.id as venue_id, v.title as venue_title,     -- Venue details
                    u.id as booker_user_id, u.username as booker_username, u.email as booker_email -- Booker details
                 FROM reservations r
                 JOIN venue v ON r.venue_id = v.id                    -- Join reservation to its venue
                 JOIN users u ON r.user_id = u.id                    -- Join reservation to the user who BOOKED it
                 WHERE r.venue_id IN ($in_placeholders)                    -- Filter: reservation must be for one of the owned venues
                 ORDER BY r.event_date DESC, r.created_at DESC";

        $stmt = $pdo->prepare($sql);
        // Execute with the array of owned venue IDs
        $stmt->execute($venue_ids_owned);
        $venue_reservations = $stmt->fetchAll();

        // Calculate pending count for owned venues
        $pending_reservations_count = count(array_filter($venue_reservations, fn($r) => strtolower($r['status'] ?? '') === 'pending'));

    } catch (PDOException $e) {
        error_log("Error fetching reservations for owned venues (Owner ID: $loggedInOwnerUserId): " . $e->getMessage());
        // $venue_reservations remains empty
    }
}
// **8. Handle "New Venue Added" Message** (No changes needed here)
$new_venue_message = "";
$new_venue_id_for_link = null;
if (isset($_GET['new_venue']) && $_GET['new_venue'] == 'true') {
    $new_venue_message = "Venue successfully added!";
    if (!empty($venues)) {
        $new_venue_id_for_link = $venues[0]['id'];
    }
}

// **9. Handle "Reservation Created" Message**
$reservation_created_message = "";
if (isset($_GET['reservation_created']) && $_GET['reservation_created'] == 'true') {
    $reservation_created_message = "Reservation successfully created!";
}

// **10. Handle Reservation Errors**
$reservation_error_message = "";
if (isset($_GET['error']) && $_GET['error'] == 'reservation_failed') {
    $reservation_error_message = "Failed to create reservation. Please try again.";
} elseif (isset($_GET['error']) && $_GET['error'] == 'invalid_reservation_data') {
    $reservation_error_message = "Invalid reservation data. Please check your input.";
}

// **11. Handle Reservation Action Messages**
$reservation_action_message = "";
if (isset($_GET['action_success']) && $_GET['action_success'] == 'accepted') {
    $reservation_action_message = "<div class='bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded-md mb-4' role='alert'><p class='font-bold'><i class='fas fa-check-circle mr-2'></i>Success!</p><p>Reservation accepted.</p></div>";
} elseif (isset($_GET['action_success']) && $_GET['action_success'] == 'rejected') {
    $reservation_action_message = "<div class='bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 rounded-md mb-4' role='alert'><p class='font-bold'><i class='fas fa-check-circle mr-2'></i>Success!</p><p>Reservation rejected.</p></div>";
} elseif (isset($_GET['action_error']) && $_GET['action_error'] == 'invalid') {
    $reservation_action_message = "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-md mb-4' role='alert'><p class='font-bold'><i class='fas fa-exclamation-triangle mr-2'></i>Error!</p><p>Invalid action or reservation ID.</p></div>";
}


// --- Helper function for status badges --- (No changes needed here)
function getStatusBadgeClass($status) {
    $status = strtolower($status ?? 'unknown');
    switch ($status) {
        case 'open': case 'confirmed': return 'bg-green-100 text-green-800';
        case 'closed': case 'cancelled': case 'rejected': return 'bg-red-100 text-red-800';
        case 'pending': return 'bg-yellow-100 text-yellow-800';
        default: return 'bg-gray-100 text-gray-800';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Owner Dashboard - Ventech Locator</title> <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css"> <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Roboto', sans-serif; }
        .sidebar-link { transition: background-color 0.2s ease-in-out, color 0.2s ease-in-out; }
        nav { position: sticky; top: 0; z-index: 10; }
        aside { position: sticky; top: 64px; height: calc(100vh - 64px); }
        main { min-height: calc(100vh - 64px); }
        /* Custom scrollbar for sidebar if needed */
        aside::-webkit-scrollbar { width: 6px; }
        aside::-webkit-scrollbar-thumb { background-color: #cbd5e1; border-radius: 3px; }
        aside::-webkit-scrollbar-track { background-color: #f1f5f9; }
    </style>
</head>
<body class="bg-gray-100">

    <nav class="bg-orange-600 p-4 text-white shadow-md">
        <div class="container mx-auto flex justify-between items-center">
            <a href="/ventech_locator/index.php" class="text-xl font-bold hover:text-orange-200">Ventech Locator</a>
            <div>
                <span class="mr-4 hidden sm:inline">Welcome, <?= htmlspecialchars($owner['username']) ?>!</span>
                <a href="client_logout.php" class="bg-white text-orange-600 hover:bg-gray-200 py-1 px-3 rounded text-sm font-medium transition duration-150 ease-in-out shadow">Logout</a>
            </div>
        </div>
    </nav>

    <div class="flex">
        <aside class="w-64 bg-white p-5 shadow-lg flex flex-col flex-shrink-0 overflow-y-auto">
            <h2 class="text-lg font-semibold mb-5 border-b pb-3 text-gray-700">Navigation</h2>
            <ul class="space-y-2 flex-grow">
                <li><a href="/ventech_locator/client_dashboard.php" class="sidebar-link flex items-center text-gray-700 font-semibold bg-orange-50 rounded p-2"><i class="fas fa-tachometer-alt fa-fw mr-3 w-5 text-center text-orange-600"></i>Dashboard</a></li>
                <li><a href="/ventech_locator/client/add_venue.php" class="sidebar-link flex items-center text-gray-700 hover:text-orange-600 hover:bg-orange-50 rounded p-2"><i class="fas fa-plus-square fa-fw mr-3 w-5 text-center"></i>Add Venue</a></li>
                <li><a href="/ventech_locator/client_map.php" class="sidebar-link flex items-center text-gray-700 hover:text-orange-600 hover:bg-orange-50 rounded p-2"><i class="fas fa-user-circle fa-fw mr-3 w-5 text-center"></i>Map</a></li>
                <li><a href="/ventech_locator/client/client_profile.php" class="sidebar-link flex items-center text-gray-700 hover:text-orange-600 hover:bg-orange-50 rounded p-2"><i class="fas fa-user-circle fa-fw mr-3 w-5 text-center"></i>Profile</a></li>
                <li><a href="/ventech_locator/reservations_manage.php" class="sidebar-link flex items-center text-gray-700 hover:text-orange-600 hover:bg-orange-50 rounded p-2"><i class="fas fa-calendar-check fa-fw mr-3 w-5 text-center"></i>Manage Bookings</a></li>
                <li><a href="/ventech_locator/client_dashboard.php?status=all" class="sidebar-link flex items-center text-gray-700 hover:text-orange-600 hover:bg-orange-50 rounded p-2"><i class="fas fa-store fa-fw mr-3 w-5 text-center"></i>My Venues</a></li>
            </ul>
            <div class="mt-auto pt-4 border-t">
                <a href="client_logout.php" class="sidebar-link flex items-center text-gray-700 hover:text-red-600 hover:bg-red-50 rounded p-2"><i class="fas fa-sign-out-alt fa-fw mr-3 w-5 text-center"></i>Logout</a>
            </div>
        </aside>

        <main class="flex-1 p-6 md:p-8 lg:p-10 overflow-y-auto bg-gray-50">
            <h1 class="text-3xl font-bold text-gray-800 mb-6">Owner Dashboard</h1>

            <?php if (!empty($new_venue_message)): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded-md mb-6 shadow relative" role="alert">
                    <p class="font-bold"><i class="fas fa-check-circle mr-2"></i>Success!</p>
                    <p><?= htmlspecialchars($new_venue_message) ?>
                        <?php if ($new_venue_id_for_link): ?>
                            You can now view or edit its details.
                            <a href="/ventech_locator/venue_details.php?id=<?= $new_venue_id_for_link ?>" class="font-medium text-blue-600 hover:text-blue-800 underline ml-1">Edit Details</a>.
                        <?php else: ?>
                            Please find it in your list below to add/edit details.
                        <?php endif; ?>
                    </p>
                    <button type="button" class="absolute top-0 right-0 mt-2 mr-2 text-green-700 hover:text-green-900" onclick="this.parentElement.style.display='none';" aria-label="Close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            <?php endif; ?>

            <?php if (!empty($reservation_created_message)): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded-md mb-6 shadow relative" role="alert">
                    <p class="font-bold"><i class="fas fa-check-circle mr-2"></i>Success!</p>
                    <p><?= htmlspecialchars($reservation_created_message) ?></p>
                    <button type="button" class="absolute top-0 right-0 mt-2 mr-2 text-green-700 hover:text-green-900" onclick="this.parentElement.style.display='none';" aria-label="Close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            <?php endif; ?>

            <?php if (!empty($reservation_error_message)): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-md mb-6 shadow relative" role="alert">
                    <p class="font-bold"><i class="fas fa-exclamation-triangle mr-2"></i>Error!</p>
                    <p><?= htmlspecialchars($reservation_error_message) ?></p>
                    <button type="button" class="absolute top-0 right-0 mt-2 mr-2 text-red-700 hover:text-red-900" onclick="this.parentElement.style.display='none';" aria-label="Close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            <?php endif; ?>

            <?= $reservation_action_message ?>

            <section class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                <div class="bg-white p-5 rounded-lg shadow hover:shadow-md transition-shadow flex flex-col">
                    <h3 class="text-lg font-semibold text-gray-600 mb-2 flex items-center"><i class="fas fa-store mr-2 text-blue-500"></i>Your Venues</h3>
                    <p class="text-3xl font-bold text-blue-600 mt-auto"><?= count($venues) ?></p>
                </div>
                <div class="bg-white p-5 rounded-lg shadow hover:shadow-md transition-shadow flex flex-col">
                   <h3 class="text-lg font-semibold text-gray-600 mb-2 flex items-center"><i class="fas fa-calendar-alt mr-2 text-green-500"></i>Venue Bookings</h3>
                    <p class="text-3xl font-bold text-green-600 mt-auto"><?= count($venue_reservations) ?></p>
                    <p class="text-xs text-gray-500 mt-1">Total booking requests received.</p>
                </div>
                <div class="bg-white p-5 rounded-lg shadow hover:shadow-md transition-shadow flex flex-col">
                    <h3 class="text-lg font-semibold text-gray-600 mb-2 flex items-center"><i class="fas fa-hourglass-half mr-2 text-yellow-500"></i>Pending Requests</h3>
                    <p class="text-3xl font-bold text-yellow-600 mt-auto"><?= $pending_reservations_count ?></p>
                    <p class="text-xs text-gray-500 mt-1">Requests needing confirmation.</p>
                </div>
            </section>

            <section class="mb-8">
                <div class="flex justify-between items-center mb-4 flex-wrap gap-4">
                    <h2 class="text-2xl font-semibold text-gray-800">Your Venues</h2>
                    <div>
                        <label for="status-filter" class="text-sm text-gray-600 mr-2">Filter by status:</label>
                        <select id="status-filter" onchange="window.location.href='client_dashboard.php?status='+this.value" class="text-sm border-gray-300 rounded-md shadow-sm focus:border-orange-500 focus:ring focus:ring-orange-200 focus:ring-opacity-50 py-1.5 px-3">
                            <option value="all" <?= ($status_filter ?? 'all') === 'all' ? 'selected' : '' ?>>All</option>
                            <option value="open" <?= ($status_filter ?? '') === 'open' ? 'selected' : '' ?>>Open</option>
                            <option value="closed" <?= ($status_filter ?? '') === 'closed' ? 'selected' : '' ?>>Closed</option>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-3 gap-6">
                    <?php if (count($venues) > 0): ?>
                        <?php foreach ($venues as $venue): ?>
                            <?php
                                // --- IMAGE PATH LOGIC --- (Same as before)
                                $imagePathFromDB = $venue['image_path'] ?? null;
                                $uploadsBaseUrl = '/ventech_locator/uploads/'; // <-- ADJUST IF NEEDED!
                                $placeholderImg = 'https://via.placeholder.com/400x250/fbbf24/ffffff?text=No+Image';
                                $imgSrc = $placeholderImg;
                                if (!empty($imagePathFromDB)) {
                                    $imgSrc = $uploadsBaseUrl . ltrim(htmlspecialchars($imagePathFromDB), '/');
                                }
                            ?>
                            <div class="border rounded-lg shadow-md overflow-hidden bg-white flex flex-col transition duration-300 ease-in-out hover:shadow-lg">
                                <a href="/ventech_locator/venue_display.php?id=<?= $venue['id'] ?>" class="block hover:opacity-90">
                                    <img src="<?= $imgSrc ?>" alt="<?= htmlspecialchars($venue['title']) ?>" class="w-full h-48 object-cover" loading="lazy" />
                                </a>
                                <div class="p-4 flex flex-col flex-grow">
                                    <div class="flex justify-between items-start mb-2">
                                        <h3 class="text-md font-semibold text-gray-800 leading-tight flex-grow mr-2">
                                            <a href="/ventech_locator/venue_display.php?id=<?= $venue['id'] ?>" class="hover:text-orange-600">
                                                <?= htmlspecialchars($venue['title']) ?>
                                            </a>
                                        </h3>
                                        <span class="flex-shrink-0 inline-block px-2 py-0.5 text-xs font-semibold rounded-full <?= getStatusBadgeClass($venue['status']) ?>">
                                            <?= ucfirst(htmlspecialchars($venue['status'] ?? 'unknown')); ?>
                                        </span>
                                    </div>
                                    <div class="text-sm text-gray-600 mb-3">
                                        <p class="text-lg font-bold text-gray-900">₱<?= number_format($venue['price'] ?? 0, 2) ?> <span class="text-xs font-normal">/ Hour</span></p>
                                    </div>
                                    <div class="flex items-center text-xs text-gray-500 mb-4">
                                        <div class="flex text-yellow-400 mr-1.5"><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star-half-alt"></i><i class="far fa-star"></i></div>
                                        <span>(<?= htmlspecialchars($venue['reviews'] ?? 0) ?> Reviews)</span>
                                    </div>
                                    <div class="mt-auto pt-3 border-t border-gray-200 flex space-x-2">
                                        <a href="/ventech_locator/venue_display.php?id=<?= $venue['id'] ?>" title="View Public Page" class="flex-1 inline-flex items-center justify-center bg-gray-500 hover:bg-gray-600 text-white text-xs font-medium py-1.5 px-3 rounded shadow-sm transition duration-150 ease-in-out">
                                            <i class="fas fa-eye mr-1"></i> View
                                        </a>
                                        <a href="/ventech_locator/venue_details.php?id=<?= $venue['id'] ?>" title="Edit Details" class="flex-1 inline-flex items-center justify-center bg-blue-500 hover:bg-blue-600 text-white text-xs font-medium py-1.5 px-3 rounded shadow-sm transition duration-150 ease-in-out">
                                            <i class="fas fa-edit mr-1"></i> Edit
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="col-span-full text-gray-600 bg-white p-6 rounded-lg shadow text-center">
                            You haven't added any venues yet<?php if ($status_filter !== 'all') echo " matching status '{$status_filter}'"; ?>.
                            <a href="/ventech_locator/client/add_venue.php" class="text-orange-600 hover:underline font-medium ml-1">Add your first venue now!</a>
                        </p>
                    <?php endif; ?>
                </div>
            </section>

            <section>
                <div class="flex justify-between items-center mb-4 flex-wrap gap-4">
                    <h2 class="text-2xl font-semibold text-gray-800">Recent Booking Requests for Your Venues</h2>
                    <a href="/ventech_locator/reservations_manage.php" class="text-sm text-blue-600 hover:text-blue-800 font-medium">
                        Manage All Bookings &rarr;
                    </a>
                </div>
                <div class="bg-white shadow-md rounded-lg overflow-x-auto">
                    <?php if (count($venue_reservations) > 0): ?>
                        <table class="w-full table-auto text-sm text-left">
                            <thead class="bg-gray-100 text-xs text-gray-600 uppercase">
                                <tr>
                                    <th scope="col" class="px-6 py-3">Booker</th>
                                    <th scope="col" class="px-6 py-3">Venue Booked</th>
                                    <th scope="col" class="px-6 py-3">Event Date</th>
                                    <th scope="col" class="px-6 py-3">Status</th>
                                    <th scope="col" class="px-6 py-3">Requested On</th>
                                    <th scope="col" class="px-6 py-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($venue_reservations, 0, 10) as $reservation): // Show recent 10 ?>
                                <tr class="bg-white border-b hover:bg-gray-50">
                                    <td class="px-6 py-4 font-medium text-gray-900 whitespace-nowrap" title="<?= htmlspecialchars($reservation['booker_email'] ?? '') ?>">
                                        <?= htmlspecialchars($reservation['booker_username'] ?? 'N/A') ?>
                                    </td>
                                    <td class="px-6 py-4 font-medium text-gray-700 whitespace-nowrap">
                                        <a href="/ventech_locator/venue_display.php?id=<?= $reservation['venue_id'] ?>" class="hover:text-orange-600" title="View Venue">
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
                                        <?php if (strtolower($reservation['status'] ?? '') === 'pending'): ?>
                                            <form method="post" action="process_reservation_action.php" class="inline-block mr-1">
                                                <input type="hidden" name="reservation_id" value="<?= $reservation['id'] ?>">
                                                <input type="hidden" name="action" value="accept">
                                                <button type="submit" class="bg-green-500 hover:bg-green-700 text-white text-xs font-medium py-1 px-2 rounded focus:outline-none focus:shadow-outline">Accept</button>
                                            </form>
                                            <form method="post" action="process_reservation_action.php" class="inline-block">
                                                <input type="hidden" name="reservation_id" value="<?= $reservation['id'] ?>">
                                                <input type="hidden" name="action" value="reject">
                                                <button type="submit" class="bg-red-500 hover:bg-red-700 text-white text-xs font-medium py-1 px-2 rounded focus:outline-none focus:shadow-outline">Reject</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-gray-500 text-xs">No Action Needed</span>
                                        <?php endif; ?>
                                        <a href="/ventech_locator/reservation_manage_details.php?id=<?= $reservation['id'] ?>" class="text-blue-600 hover:text-blue-800 text-xs font-medium ml-2">View Details</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="p-6 text-center text-gray-600">No booking requests received for your venues yet.</p>
                    <?php endif; ?>
                </div>
            </section>


        </main>
    </div>

</body>
</html>