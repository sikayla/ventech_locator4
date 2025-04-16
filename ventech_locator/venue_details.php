<?php
// Database connection parameters
$host = 'localhost';
$db = 'ventech_db';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// Error handling function
function handle_error($message, $is_warning = false) {
    $style = 'color:red;border:1px solid red;background-color:#ffe0e0;';
    if ($is_warning) {
        $style = 'color: #856404; background-color: #fff3cd; border-color: #ffeeba;';
         echo "<div style='padding:15px; margin-bottom: 15px; border-radius: 4px; {$style}'>" . htmlspecialchars($message) . "</div>";
         return; // Don't die for warnings
    }
    // Log critical errors
    error_log("Venue Details Error: " . $message);
    die("<div style='padding:15px; border-radius: 4px; {$style}'>" . htmlspecialchars($message) . "</div>");
}

// Include the PDO database connection
try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    handle_error("Could not connect to the database: " . $e->getMessage());
}

// --- Session Start & Auth Check ---
// Important: Add session start and check if the logged-in user is authorized to edit this venue
// session_start();
// if (!isset($_SESSION['user_id'])) {
//     header("Location: client_login.php"); exit;
// }
// $loggedInUserId = $_SESSION['user_id'];
// Fetch logged-in user role if needed for finer control (e.g., admin override)

// Get the venue ID from the URL
$venue_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$venue_id) {
    handle_error("No valid venue ID provided.");
}

// Function to fetch data
function fetch_data($pdo, $query, $params = []) {
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        // Log error but let calling code handle it
        error_log("Database Fetch Error: " . $e->getMessage() . " Query: " . $query);
        return false; // Indicate failure
    }
}

// Fetch venue data
$venue_data = fetch_data($pdo, "SELECT * FROM venue WHERE id = ?", [$venue_id]);
if ($venue_data === false) {
     handle_error("Database error fetching venue data.");
}
if (empty($venue_data)) {
    handle_error("Venue not found.");
}
$venue = $venue_data[0];

// **Authorization Check**: Ensure logged-in user owns this venue (or is admin)
// if ($venue['user_id'] !== $loggedInUserId /* && user_role != 'admin' */) {
//     handle_error("You are not authorized to edit this venue.");
// }

// Fetch media
$media_data = fetch_data($pdo, "SELECT * FROM venue_media WHERE venue_id = ?", [$venue_id]);
$media = ($media_data !== false) ? $media_data : [];


// Fetch unavailable dates
$unavailableDatesData = fetch_data($pdo, "SELECT unavailable_date FROM unavailable_dates WHERE venue_id = ?", [$venue_id]);
$unavailableDates = ($unavailableDatesData !== false) ? array_column($unavailableDatesData, 'unavailable_date') : [];

// Fetch client (venue contact) information
$client_info_data = fetch_data($pdo, "SELECT * FROM client_info WHERE venue_id = ?", [$venue_id]);
$client_info = ($client_info_data !== false && !empty($client_info_data)) ? $client_info_data[0] : null;

// Display warning if no contact info found (but don't die)
if (!$client_info) {
    handle_error("Venue Contact information not available. Please add it below.", true);
     // Initialize $client_info as an empty array to avoid errors when accessing keys later
    $client_info = ['client_name' => '', 'client_email' => '', 'client_phone' => '', 'client_address' => ''];
}


// Function to format file sizes
function formatBytes($bytes, $precision = 2) { /* ... function code ... */
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0); $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1); $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// Function to handle file uploads
function handleFileUpload($file, $allowed_extensions, $max_size, $upload_dir, $venue_id, $media_type, $pdo) { /* ... function code ... */
     if (isset($file) && $file['error'] === UPLOAD_ERR_OK) { // Check specifically for UPLOAD_ERR_OK
        $file_tmp = $file['tmp_name'];
        // Sanitize filename and create unique name
        $original_name = basename($file['name']);
        $safe_original_name = preg_replace("/[^A-Za-z0-9\.\-\_]/", '', $original_name); // Basic sanitization
        $file_extension = strtolower(pathinfo($safe_original_name, PATHINFO_EXTENSION));
        $file_name = uniqid($media_type . '_', true) . '.' . $file_extension; // More unique name
        $file_path = 'uploads/' . $file_name; // Relative path for DB storage
        $destination = $upload_dir . $file_name; // Full path for move_uploaded_file

        if (!in_array($file_extension, $allowed_extensions)) { return ["error" => "Invalid file format. Only " . implode(', ', $allowed_extensions) . " are allowed."]; }
        if ($file['size'] > $max_size) { return ["error" => "File size exceeds the " . formatBytes($max_size) . " limit."]; }
        if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) { return ["error" => "Failed to create upload directory."]; }
        if (!move_uploaded_file($file_tmp, $destination)) { return ["error" => "Failed to move uploaded file. Check permissions."]; }

        try {
            $insert_media = $pdo->prepare("INSERT INTO venue_media (venue_id, media_type, media_url) VALUES (?, ?, ?)");
            $insert_media->execute([$venue_id, $media_type, $file_path]);
             return ["success" => true, "path" => $file_path]; // Return success and path
        } catch (PDOException $e) {
             error_log("DB Error inserting media: " . $e->getMessage());
             // Optionally delete the uploaded file if DB insert fails
             unlink($destination);
             return ["error" => "Failed to save media record to database."];
        }
    } elseif (isset($file) && $file['error'] !== UPLOAD_ERR_NO_FILE) {
        // Handle other upload errors (https://www.php.net/manual/en/features.file-upload.errors.php)
         return ["error" => "File upload error code: " . $file['error']];
    }
    return null; // No file uploaded or handled error
}

// --- Handle Form Submission ---
$form_errors = [];
$form_success = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if the submitted venue_id matches the one in the URL (security)
    if (!isset($_POST['venue_id']) || $_POST['venue_id'] != $venue_id) {
        handle_error("Form submission error. Venue ID mismatch.");
    }

    // --- Sanitize & Validate ---
    // Venue Details
    $amenities = htmlspecialchars(trim($_POST['amenities'] ?? ''), ENT_QUOTES, 'UTF-8');
    $reviews = filter_input(INPUT_POST, 'reviews', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $additional_info = htmlspecialchars(trim($_POST['additional_info'] ?? ''), ENT_QUOTES, 'UTF-8');
    $num_persons = filter_input(INPUT_POST, 'num-persons', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $price = filter_input(INPUT_POST, 'price', FILTER_VALIDATE_FLOAT, FILTER_FLAG_ALLOW_FRACTION); // Added Price validation
    $wifi = isset($_POST['wifi']) && $_POST['wifi'] === 'yes' ? 'yes' : 'no'; // Validate radio
    $parking = isset($_POST['parking']) && $_POST['parking'] === 'yes' ? 'yes' : 'no'; // Validate radio
    $virtual_tour_url = filter_input(INPUT_POST, 'virtual_tour_url', FILTER_VALIDATE_URL);
    $latitude = filter_input(INPUT_POST, 'latitude', FILTER_VALIDATE_FLOAT);
    $longitude = filter_input(INPUT_POST, 'longitude', FILTER_VALIDATE_FLOAT);

     // Venue Contact Details
    $client_name = htmlspecialchars(trim($_POST['client-name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $client_email = filter_input(INPUT_POST, 'client-email', FILTER_VALIDATE_EMAIL);
    $client_phone = htmlspecialchars(trim($_POST['client-phone'] ?? ''), ENT_QUOTES, 'UTF-8'); // Further validation needed (e.g., regex)
    $client_address = htmlspecialchars(trim($_POST['client-address'] ?? ''), ENT_QUOTES, 'UTF-8');

     // Unavailable Dates
    $unavailable_dates_input = $_POST['unavailable_dates'] ?? [];
    $unavailable_dates = [];
    foreach ($unavailable_dates_input as $date) {
        // Validate date format (YYYY-MM-DD)
        if (preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $date)) {
            $unavailable_dates[] = $date;
        } else {
            $form_errors[] = "Invalid date format submitted: " . htmlspecialchars($date);
        }
    }

    // --- Validation Checks ---
    if ($reviews === false) $form_errors[] = "Invalid value for Reviews (must be zero or more).";
    if ($num_persons === false) $form_errors[] = "Invalid value for Number of Persons (must be at least 1).";
    if ($price === false || $price < 0) $form_errors[] = "Invalid value for Price (must be zero or more)."; // Price validation
    if ($_POST['virtual_tour_url'] && $virtual_tour_url === false) $form_errors[] = "Invalid Virtual Tour URL format.";
    if (($_POST['latitude'] && $latitude === false) || ($_POST['longitude'] && $longitude === false)) {
         $form_errors[] = "Invalid Latitude or Longitude format (must be numbers).";
    }
    if (empty($client_name)) $form_errors[] = "Venue Contact Name is required.";
    if (empty($client_email)) $form_errors[] = "Venue Contact Email is required.";
     elseif ($client_email === false) $form_errors[] = "Invalid Venue Contact Email format.";
    if (empty($client_phone)) $form_errors[] = "Venue Contact Phone is required.";
    if (empty($client_address)) $form_errors[] = "Venue Contact Address is required.";

    // --- Process Updates if No Errors ---
    if (empty($form_errors)) {
        $upload_dir = __DIR__ . '/uploads/'; // Use absolute path
        if (!is_dir($upload_dir)) {
            if (!mkdir($upload_dir, 0755, true)) {
                 $form_errors[] = "Failed to create upload directory. Check permissions.";
            }
        }

         // Only proceed if upload dir is usable
        if (is_writable($upload_dir)) {
            // Handle image upload
            $image_upload_result = handleFileUpload($_FILES['venue_image'], ['jpg', 'jpeg', 'png', 'gif'], 5 * 1024 * 1024, $upload_dir, $venue_id, 'image', $pdo);
            if ($image_upload_result && isset($image_upload_result['error'])) { $form_errors[] = "Image Upload Error: " . $image_upload_result['error']; }
            elseif($image_upload_result && isset($image_upload_result['success'])) { $form_success[] = "Image uploaded successfully."; }

            // Handle video upload
            $video_upload_result = handleFileUpload($_FILES['venue_video'], ['mp4', 'mov', 'avi', 'wmv'], 50 * 1024 * 1024, $upload_dir, $venue_id, 'video', $pdo); // Increased size limit, added formats
            if ($video_upload_result && isset($video_upload_result['error'])) { $form_errors[] = "Video Upload Error: " . $video_upload_result['error']; }
             elseif($video_upload_result && isset($video_upload_result['success'])) { $form_success[] = "Video uploaded successfully."; }
        } else {
             $form_errors[] = "Upload directory is not writable. File uploads failed.";
        }


        // --- Database Updates (only if file uploads didn't cause new errors) ---
        if (empty($form_errors)) {
             try {
                $pdo->beginTransaction();

                // Update venue details (added price)
                $updateVenue = $pdo->prepare(
                    "UPDATE venue SET amenities = ?, num_persons = ?, reviews = ?, additional_info = ?,
                     price = ?, wifi = ?, parking = ?, virtual_tour_url = ?, latitude = ?, longitude = ?
                     WHERE id = ?"
                );
                $updateVenue->execute([
                    $amenities, $num_persons, $reviews, $additional_info,
                    $price, $wifi, $parking, $virtual_tour_url ?: null, // Store null if empty/invalid
                    $latitude ?: null, $longitude ?: null, // Store null if empty/invalid
                    $venue_id
                ]);
                $form_success[] = "Venue details updated.";

                // Update unavailable dates
                $deleteOldDates = $pdo->prepare("DELETE FROM unavailable_dates WHERE venue_id = ?");
                $deleteOldDates->execute([$venue_id]);
                if (!empty($unavailable_dates)) {
                    $insertDate = $pdo->prepare("INSERT INTO unavailable_dates (venue_id, unavailable_date) VALUES (?, ?)");
                    foreach ($unavailable_dates as $date) {
                        $insertDate->execute([$venue_id, $date]);
                    }
                }
                $form_success[] = "Availability updated.";


                // Update or insert client information
                $stmtCheckClient = $pdo->prepare("SELECT id FROM client_info WHERE venue_id = ?");
                $stmtCheckClient->execute([$venue_id]);
                $existingClient = $stmtCheckClient->fetch();

                if ($existingClient) {
                    $updateClient = $pdo->prepare("UPDATE client_info SET client_name = ?, client_email = ?, client_phone = ?, client_address = ? WHERE venue_id = ?");
                    $updateClient->execute([$client_name, $client_email, $client_phone, $client_address, $venue_id]);
                     $form_success[] = "Contact information updated.";
                } else {
                    $insertClient = $pdo->prepare("INSERT INTO client_info (venue_id, client_name, client_email, client_phone, client_address) VALUES (?, ?, ?, ?, ?)");
                    $insertClient->execute([$venue_id, $client_name, $client_email, $client_phone, $client_address]);
                     $form_success[] = "Contact information added.";
                }

                $pdo->commit();

                // --- Data Refresh after successful update ---
                // Refetch data to show updated values immediately on the page
                $venue_data = fetch_data($pdo, "SELECT * FROM venue WHERE id = ?", [$venue_id]);
                $venue = $venue_data[0];
                $media_data = fetch_data($pdo, "SELECT * FROM venue_media WHERE venue_id = ?", [$venue_id]);
                $media = ($media_data !== false) ? $media_data : [];
                $unavailableDatesData = fetch_data($pdo, "SELECT unavailable_date FROM unavailable_dates WHERE venue_id = ?", [$venue_id]);
                $unavailableDates = ($unavailableDatesData !== false) ? array_column($unavailableDatesData, 'unavailable_date') : [];
                $client_info_data = fetch_data($pdo, "SELECT * FROM client_info WHERE venue_id = ?", [$venue_id]);
                $client_info = ($client_info_data !== false && !empty($client_info_data)) ? $client_info_data[0] : null;
                // Re-init if null after insert
                 if (!$client_info) { $client_info = ['client_name' => $client_name, 'client_email' => $client_email, 'client_phone' => $client_phone, 'client_address' => $client_address]; }
        
                // **ADD YOUR REDIRECTION CODE HERE:**
                header("Location: client_map.php");
                exit();
        
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log("Database Update Error: " . $e->getMessage());
                $form_errors[] = "A database error occurred while saving changes. Please try again.";
            }
        } // end check for file upload errors before DB updates
    } // end check for validation errors
} // end POST request check

// Get calendar navigation parameters
$currentMonth = $_GET['month'] ?? date('n');
$currentYear = $_GET['year'] ?? date('Y');
$currentMonth = filter_var($currentMonth, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]) ?: date('n');
$currentYear = filter_var($currentYear, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1900, 'max_range' => 2100]]) ?: date('Y');

$today = date('Y-m-d'); // Get today's date for calendar styling

// Generate Calendar data for PHP side
try {
     $firstDayOfMonth = new DateTimeImmutable("$currentYear-$currentMonth-01");
     $prevMonthDate = $firstDayOfMonth->modify('-1 month');
     $nextMonthDate = $firstDayOfMonth->modify('+1 month');
     $prevMonth = $prevMonthDate->format('n');
     $prevYear = $prevMonthDate->format('Y');
     $nextMonth = $nextMonthDate->format('n');
     $nextYear = $nextMonthDate->format('Y');
} catch (Exception $e) {
     // Handle invalid date creation, maybe default to current month/year
     error_log("Calendar Date Error: " . $e->getMessage());
     // Use defaults set earlier
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Venue: <?php echo htmlspecialchars($venue['title']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" /> <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
    <link href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" rel="stylesheet" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f3f4f6; /* Light gray bg */ }
        /* Custom Form Styles */
        label { font-weight: 500; color: #374151; /* Gray 700 */ }
        input[type="text"], input[type="number"], input[type="email"], input[type="url"], textarea {
             border-color: #d1d5db; /* Gray 300 */ border-radius: 0.375rem; /* rounded-md */
             padding: 0.5rem 0.75rem; width: 100%;
             transition: border-color 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
        }
        input:focus, textarea:focus { border-color: #fbbf24; /* Amber 400 */ box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.3); outline: none; }
        .card { background-color: white; border-radius: 0.5rem; box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px -1px rgba(0, 0, 0, 0.1); padding: 1.5rem; }
        h2 { font-size: 1.25rem; font-weight: 600; color: #1f2937; /* Gray 800 */ border-bottom: 1px solid #e5e7eb; padding-bottom: 0.75rem; margin-bottom: 1rem; }
        .file-input-label { cursor: pointer; background-color: #4f46e5; /* Indigo 600 */ color: white; padding: 0.5rem 1rem; border-radius: 0.375rem; transition: background-color 0.2s; display: inline-flex; align-items: center; }
        .file-input-label:hover { background-color: #4338ca; /* Indigo 700 */ }
        .file-input-label i { margin-right: 0.5rem; }

        /* Enhanced Calendar Styles (Editing Version) */
        .calendar-edit { width: 100%; background-color: #fff; border: 1px solid #e5e7eb; border-radius: 0.5rem; box-shadow: 0 1px 2px rgba(0,0,0,0.05); margin-top: 1rem; font-size: 0.9rem; }
        .calendar-edit .month-header { padding: 0.75rem; background-color: #f9fafb; border-bottom: 1px solid #e5e7eb; font-weight: 600; display: flex; justify-content: space-between; align-items: center; }
        .calendar-edit .month-header button { background: none; border: none; cursor: pointer; color: #4b5563; padding: 0.25rem 0.5rem; }
        .calendar-edit .month-header button:hover { color: #111827; }
        .calendar-edit .weekdays { display: grid; grid-template-columns: repeat(7, 1fr); padding: 0.5rem 0; font-weight: 500; color: #6b7280; background-color: #f9fafb; border-bottom: 1px solid #e5e7eb; text-align: center; }
        .calendar-edit .days { display: grid; grid-template-columns: repeat(7, 1fr); gap: 1px; background-color: #e5e7eb; }
        .calendar-edit .days div { text-align: center; padding: 0.5rem 0.25rem; background-color: #fff; min-height: 55px; display: flex; flex-direction: column; align-items: center; justify-content: center; font-size: 0.85rem; }
        .calendar-edit .days div.past-date { color: #9ca3af; background-color: #f9fafb; }
        .calendar-edit .days div.unavailable-full { background-color: #fee2e2; color: #991b1b; font-weight: 500; }
        .calendar-edit .days div.unavailable-full input[type="checkbox"] { /* Style checkbox even if unavailable */ }
        .calendar-edit .days div input[type="checkbox"] { margin-top: 0.25rem; cursor: pointer; width: 1rem; height: 1rem; accent-color: #ef4444; /* Red accent for unavailable */ }
         /* Hide checkbox visually but keep it accessible for past dates */
        .calendar-edit .days div.past-date input[type="checkbox"] { opacity: 0; position: absolute; pointer-events: none; }
        .calendar-edit .days div.past-date label { color: #9ca3af; } /* Style label for past dates */
        .calendar-edit .empty { background-color: #f9fafb; }
        #map { height: 300px; width: 100%; border-radius: 0.375rem; border: 1px solid #d1d5db; }
    </style>
</head>
<body class="bg-gray-100 text-gray-900">

    <header class="bg-white shadow-sm">
        <div class="max-w-7xl mx-auto py-4 px-4 sm:px-6 lg:px-8 flex justify-between items-center">
            <h1 class="text-2xl font-bold text-gray-800">
                Edit Venue: <?php echo htmlspecialchars($venue['title']); ?>
            </h1>
            <a href="venue_display.php?id=<?php echo $venue_id; ?>" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">
                 View Venue Page <i class="fas fa-arrow-right ml-1"></i>
            </a>
        </div>
    </header>

    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        <form action="venue_details.php?id=<?php echo $venue_id; ?>" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="venue_id" value="<?php echo $venue_id; ?>">

             <?php if (!empty($form_errors)): ?>
                 <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded shadow" role="alert">
                     <p class="font-bold mb-1">Please fix the following errors:</p>
                     <ul class="list-disc list-inside text-sm">
                         <?php foreach ($form_errors as $error): ?>
                             <li><?= htmlspecialchars($error) ?></li>
                         <?php endforeach; ?>
                     </ul>
                 </div>
             <?php endif; ?>
              <?php if (!empty($form_success) && $_SERVER['REQUEST_METHOD'] === 'POST'): ?>
                 <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded shadow" role="alert">
                     <p class="font-bold mb-1">Success!</p>
                      <ul class="list-disc list-inside text-sm">
                         <?php foreach ($form_success as $msg): ?>
                             <li><?= htmlspecialchars($msg) ?></li>
                         <?php endforeach; ?>
                     </ul>
                 </div>
             <?php endif; ?>


            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <div class="lg:col-span-2 space-y-6">

                    <div class="card">
                        <h2>Core Venue Information</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="num-persons" class="block text-sm">Capacity (Persons):</label>
                                <input type="number" id="num-persons" name="num-persons" value="<?php echo htmlspecialchars($venue['num_persons'] ?? 1); ?>" min="1" required>
                            </div>
                            <div>
                                <label for="price" class="block text-sm">Price (per Hour):</label>
                                <div class="relative">
                                     <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-500">₱</span>
                                     <input type="number" id="price" name="price" value="<?php echo htmlspecialchars($venue['price'] ?? 0.00); ?>" min="0" step="0.01" class="pl-7" required>
                                </div>
                            </div>
                             <div>
                                <label for="reviews" class="block text-sm">Reviews Count:</label>
                                <input type="number" id="reviews" name="reviews" value="<?php echo htmlspecialchars($venue['reviews'] ?? 0); ?>" min="0">
                            </div>
                            <div>
                                <label for="virtual_tour_url" class="block text-sm">Virtual Tour URL:</label>
                                <input type="url" id="virtual_tour_url" name="virtual_tour_url" value="<?php echo htmlspecialchars($venue['virtual_tour_url'] ?? ''); ?>" placeholder="https://...">
                            </div>
                             <div class="md:col-span-1">
                                <span class="block text-sm mb-2">Wifi Available:</span>
                                <div class="flex items-center space-x-4">
                                    <label class="inline-flex items-center">
                                        <input type="radio" id="wifi-yes" name="wifi" value="yes" <?php echo (($venue['wifi'] ?? 'no') == 'yes') ? 'checked' : ''; ?> class="form-radio h-4 w-4 text-indigo-600">
                                        <span class="ml-2 text-sm">Yes</span>
                                    </label>
                                    <label class="inline-flex items-center">
                                        <input type="radio" id="wifi-no" name="wifi" value="no" <?php echo (($venue['wifi'] ?? 'no') == 'no') ? 'checked' : ''; ?> class="form-radio h-4 w-4 text-indigo-600">
                                        <span class="ml-2 text-sm">No</span>
                                    </label>
                                </div>
                            </div>
                            <div class="md:col-span-1">
                                 <span class="block text-sm mb-2">Parking Available:</span>
                                <div class="flex items-center space-x-4">
                                     <label class="inline-flex items-center">
                                        <input type="radio" id="parking-yes" name="parking" value="yes" <?php echo (($venue['parking'] ?? 'no') == 'yes') ? 'checked' : ''; ?> class="form-radio h-4 w-4 text-indigo-600">
                                        <span class="ml-2 text-sm">Yes</span>
                                    </label>
                                     <label class="inline-flex items-center">
                                        <input type="radio" id="parking-no" name="parking" value="no" <?php echo (($venue['parking'] ?? 'no') == 'no') ? 'checked' : ''; ?> class="form-radio h-4 w-4 text-indigo-600">
                                        <span class="ml-2 text-sm">No</span>
                                    </label>
                                </div>
                            </div>
                            <div class="md:col-span-2">
                                <label for="amenities" class="block text-sm">Amenities:</label>
                                <textarea id="amenities" name="amenities" rows="4" placeholder="List amenities separated by commas (e.g., Projector, Sound System, Whiteboard)"><?php echo htmlspecialchars($venue['amenities'] ?? ''); ?></textarea>
                            </div>
                            <div class="md:col-span-2">
                                <label for="additional_info" class="block text-sm">Additional Information:</label>
                                <textarea id="additional_info" name="additional_info" rows="4" placeholder="Any other relevant details, rules, or notes"><?php echo htmlspecialchars($venue['additional_info'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <h2>Location</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="latitude" class="block text-sm">Latitude:</label>
                                <input type="text" id="latitude" name="latitude" value="<?php echo htmlspecialchars($venue['latitude'] ?? ''); ?>" placeholder="e.g., 14.12345">
                            </div>
                            <div>
                                <label for="longitude" class="block text-sm">Longitude:</label>
                                <input type="text" id="longitude" name="longitude" value="<?php echo htmlspecialchars($venue['longitude'] ?? ''); ?>" placeholder="e.g., 121.12345">
                            </div>
                        </div>
                         <div id="map"></div>
                         <p class="text-xs text-gray-500 mt-2">Drag the marker on the map to update coordinates, or enter manually above.</p>
                    </div>

                     <div class="card">
                        <h2>Venue Contact Person</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                             <div>
                                <label for="client-name" class="block text-sm">Contact Name:</label>
                                <input type="text" id="client-name" name="client-name" value="<?php echo htmlspecialchars($client_info['client_name'] ?? ''); ?>" required>
                            </div>
                            <div>
                                <label for="client-email" class="block text-sm">Contact Email:</label>
                                <input type="email" id="client-email" name="client-email" value="<?php echo htmlspecialchars($client_info['client_email'] ?? ''); ?>" required>
                            </div>
                             <div>
                                <label for="client-phone" class="block text-sm">Contact Phone:</label>
                                <input type="text" id="client-phone" name="client-phone" value="<?php echo htmlspecialchars($client_info['client_phone'] ?? ''); ?>" required>
                            </div>
                             <div>
                                <label for="client-address" class="block text-sm">Venue Address:</label>
                                <input type="text" id="client-address" name="client-address" value="<?php echo htmlspecialchars($client_info['client_address'] ?? ''); ?>" required>
                            </div>
                        </div>
                    </div>


                </div><div class="lg:col-span-1 space-y-6">

                    <div class="card">
                         <h2>Media Management</h2>
                          <div class="mb-4">
                             <h3 class="text-sm font-medium text-gray-700 mb-2">Existing Media:</h3>
                             <?php if (!empty($media)): ?>
                                <div class="grid grid-cols-3 gap-2">
                                    <?php foreach ($media as $item): ?>
                                        <div class="relative group">
                                            <?php if ($item['media_type'] === 'image'): ?>
                                                <img src="<?php echo htmlspecialchars($item['media_url']); ?>" alt="Venue Media" class="w-full h-20 object-cover rounded">
                                            <?php elseif ($item['media_type'] === 'video'): ?>
                                                <video preload="metadata" class="w-full h-20 object-cover rounded bg-black">
                                                    <source src="<?php echo htmlspecialchars($item['media_url']); ?>#t=0.5" type="video/mp4">
                                                </video>
                                                 <div class="absolute inset-0 flex items-center justify-center bg-black bg-opacity-30">
                                                    <i class="fas fa-play text-white text-xl"></i>
                                                </div>
                                            <?php endif; ?>
                                            </div>
                                    <?php endforeach; ?>
                                </div>
                             <?php else: ?>
                                <p class="text-sm text-gray-500">No media uploaded yet.</p>
                             <?php endif; ?>
                         </div>
                         <hr class="my-4">
                         <div class="space-y-4">
                             <div>
                                 <label for="venue_image" class="block text-sm mb-2">Upload New Image:</label>
                                 <label class="file-input-label">
                                     <i class="fas fa-image"></i> Choose Image...
                                     <input type="file" id="venue_image" name="venue_image" accept="image/jpeg,image/png,image/gif" class="sr-only">
                                 </label>
                                 <img id="imagePreview" src="#" alt="Image Preview" class="mt-2 w-full h-32 object-cover border border-gray-300 rounded hidden" />
                                 <p class="text-xs text-gray-500 mt-1">Max 5MB. Formats: jpg, png, gif.</p>
                             </div>
                             <div>
                                 <label for="venue_video" class="block text-sm mb-2">Upload New Video:</label>
                                  <label class="file-input-label !bg-green-600 hover:!bg-green-700">
                                     <i class="fas fa-video"></i> Choose Video...
                                     <input type="file" id="venue_video" name="venue_video" accept="video/mp4,video/quicktime,video/x-msvideo,video/x-ms-wmv" class="sr-only">
                                 </label>
                                  <video id="videoPreview" class="mt-2 w-full h-32 border border-gray-300 rounded hidden bg-black" controls>
                                     <source src="#" type="video/mp4"> Your browser does not support the video tag.
                                 </video>
                                 <p class="text-xs text-gray-500 mt-1">Max 50MB. Formats: mp4, mov, avi, wmv.</p>
                             </div>
                         </div>
                    </div>

                    <div class="card">
                         <h2>Manage Availability</h2>
                         <p class="text-sm text-gray-600 mb-2">Check the boxes for dates when the venue is <strong class="text-red-600">unavailable</strong>.</p>
                         <div id="full-calendar-container" class="calendar-edit"> <div class="calendar-navigation text-center mb-3 text-sm space-x-2"> <a href="?id=<?php echo $venue_id; ?>&month=<?php echo $prevMonth; ?>&year=<?php echo $prevYear; ?>" class="inline-block px-3 py-1 bg-gray-200 rounded hover:bg-gray-300">&laquo; Prev</a>
                                 <span class="font-semibold"><?php echo date('F Y', strtotime("$currentYear-$currentMonth-01")); ?></span>
                                 <a href="?id=<?php echo $venue_id; ?>&month=<?php echo $nextMonth; ?>&year=<?php echo $nextYear; ?>" class="inline-block px-3 py-1 bg-gray-200 rounded hover:bg-gray-300">Next &raquo;</a>
                             </div>
                             <div class="weekdays">
                                 <div>Sun</div><div>Mon</div><div>Tue</div><div>Wed</div><div>Thu</div><div>Fri</div><div>Sat</div>
                             </div>
                             <div class="days">
                                 <?php
                                 try {
                                    $daysInMonth = $firstDayOfMonth->format('t');
                                    $dayOfWeek = $firstDayOfMonth->format('w');
                                    $dayCounter = 1;

                                    // Add empty cells for start
                                    for ($i = 0; $i < $dayOfWeek; $i++) { echo '<div class="empty"></div>'; }

                                    // Add day cells
                                    while ($dayCounter <= $daysInMonth) {
                                        $dateString = date('Y-m-d', strtotime("$currentYear-$currentMonth-$dayCounter"));
                                        $isUnavailable = in_array($dateString, $unavailableDates);
                                        $isPastDate = $dateString < $today;
                                        $cellClasses = $isPastDate ? 'past-date' : '';
                                        if ($isUnavailable && !$isPastDate) { $cellClasses .= ' unavailable-full'; }

                                        echo '<div class="' . trim($cellClasses) . '">';
                                        echo '<label for="date-' . $dateString . '" class="flex flex-col items-center cursor-pointer ' . ($isPastDate ? 'cursor-not-allowed' : '') . '">';
                                        echo '<span class="day-number">' . $dayCounter . '</span>';
                                        // Checkbox is always present but disabled/hidden visually for past dates
                                        echo '<input type="checkbox" id="date-' . $dateString . '" name="unavailable_dates[]" value="' . $dateString . '" '
                                            . ($isUnavailable ? 'checked' : '')
                                            . ($isPastDate ? ' disabled ' : '') // Add disabled attribute for past dates
                                            . '>';
                                        echo '</label>';
                                        echo '</div>'; // Close day cell

                                        if (($dayOfWeek + $dayCounter) % 7 == 0) { // End of week
                                             // No need for </tr> <tr> as grid handles it
                                        }
                                        $dayCounter++;
                                    }
                                    // Add empty cells for end
                                     $totalCells = $dayOfWeek + $daysInMonth;
                                     $remainingCells = (7 - ($totalCells % 7)) % 7;
                                     for ($i = 0; $i < $remainingCells; $i++) { echo '<div class="empty"></div>'; }

                                 } catch (Exception $e) {
                                      echo '<div class="col-span-7 text-red-500 p-4">Error generating calendar days.</div>';
                                 }

                                 ?>
                             </div></div><p class="text-xs text-gray-500 mt-2 text-center">Past dates cannot be changed.</p>
                    </div>

                </div></div><div class="mt-8 pt-6 border-t border-gray-200 flex justify-end">
                <a href="venue_display.php?id=<?php echo $venue_id; ?>" class="bg-gray-200 hover:bg-gray-300 text-gray-700 font-medium py-2 px-5 rounded mr-3 transition duration-150 ease-in-out">
                    Cancel
                </a>
                <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-6 rounded focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 shadow transition duration-150 ease-in-out">
                    <i class="fas fa-save mr-2"></i>
                    Save All Changes
                </button>
            </div>

        </form> </div>
         <script>
        document.addEventListener("DOMContentLoaded", function () {
            const imageInput = document.getElementById('venue_image');
            const imagePreview = document.getElementById('imagePreview');
            const videoInput = document.getElementById('venue_video');
            const videoPreview = document.getElementById('videoPreview');
            const videoPreviewSource = videoPreview ? videoPreview.querySelector('source') : null;
             let map = null; // Leaflet map instance
             let marker = null; // Leaflet marker instance

            // Image Preview Handler
            if (imageInput && imagePreview) {
                imageInput.addEventListener('change', function(event) {
                    const file = event.target.files[0];
                    if (file && file.type.startsWith('image/')) {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            imagePreview.src = e.target.result;
                            imagePreview.classList.remove('hidden');
                        }
                        reader.readAsDataURL(file);
                    } else {
                        imagePreview.src = '#';
                        imagePreview.classList.add('hidden');
                    }
                });
            }

            // Video Preview Handler
            if (videoInput && videoPreview && videoPreviewSource) {
                videoInput.addEventListener('change', function(event) {
                    const file = event.target.files[0];
                    if (file && file.type.startsWith('video/')) {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                             videoPreviewSource.src = e.target.result;
                             videoPreview.load(); // Important to load the new source
                             videoPreview.classList.remove('hidden');
                        }
                        reader.readAsDataURL(file);
                    } else {
                         videoPreviewSource.src = '#';
                         videoPreview.classList.add('hidden');
                    }
                });
            }

            // Leaflet Map Initialization
             const latInput = document.getElementById('latitude');
             const lonInput = document.getElementById('longitude');
             const initialLat = parseFloat(latInput.value) || 14.4797; // Default to a reasonable location like Las Piñas if invalid/empty
             const initialLon = parseFloat(lonInput.value) || 120.9936; // Default to a reasonable location like Las Piñas if invalid/empty
             const mapDiv = document.getElementById('map');

             if (mapDiv && typeof L !== 'undefined') {
                 try {
                    map = L.map(mapDiv).setView([initialLat, initialLon], 15);

                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        maxZoom: 19,
                        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> contributors'
                    }).addTo(map);

                     // Add Draggable Marker
                     marker = L.marker([initialLat, initialLon], {
                        draggable: true
                    }).addTo(map);

                    // Update inputs when marker is dragged
                    marker.on('dragend', function(event) {
                        const position = marker.getLatLng();
                         if (latInput) latInput.value = position.lat.toFixed(6);
                         if (lonInput) lonInput.value = position.lng.toFixed(6);
                    });

                     // Update map if lat/lon inputs change manually
                    function updateMapFromInputs() {
                         const newLat = parseFloat(latInput.value);
                         const newLon = parseFloat(lonInput.value);
                         if (!isNaN(newLat) && !isNaN(newLon) && map && marker) {
                            const newLatLng = L.latLng(newLat, newLon);
                            map.setView(newLatLng, map.getZoom());
                            marker.setLatLng(newLatLng);
                         }
                    }

                    if(latInput) latInput.addEventListener('change', updateMapFromInputs);
                    if(lonInput) lonInput.addEventListener('change', updateMapFromInputs);

                 } catch (e) {
                     console.error("Leaflet map initialization failed:", e);
                     mapDiv.innerHTML = '<p class="text-center text-red-500 p-4">Error loading map.</p>';
                 }
             }

              // Optional: Delete Media Functionality Placeholder
             // function deleteMedia(mediaId) {
             //    if (confirm('Are you sure you want to delete this media item? This cannot be undone.')) {
             //       // Use fetch API to send request to a backend script (e.g., delete_media.php)
             //       fetch('delete_media.php', {
             //          method: 'POST',
             //          headers: { 'Content-Type': 'application/json' },
             //          body: JSON.stringify({ media_id: mediaId, venue_id: <?php echo $venue_id; ?> /* Add CSRF token */ })
             //       })
             //       .then(response => response.json())
             //       .then(data => {
             //          if (data.success) {
             //              // Remove the media item from the DOM
             //             // Consider using a more specific selector
             //             document.querySelector(`[onclick*="deleteMedia(${mediaId})"]`).closest('.relative').remove();
             //              alert('Media deleted successfully.');
             //          } else {
             //              alert('Error deleting media: ' + (data.message || 'Unknown error'));
             //          }
             //       })
             //       .catch(error => {
             //          console.error('Error:', error);
             //          alert('An error occurred while trying to delete media.');
             //       });
             //    }
             // }

        }); // End DOMContentLoaded
    </script>

</body>
</html>