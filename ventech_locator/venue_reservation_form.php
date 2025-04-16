
<?php
// **1. Start Session** (Needed to get logged-in user ID)
// Removed session_start() as it's already started in client_dashboard.php

$date_format_error = "";

// **2. Database Connection Parameters** (Use consistent method as other files)
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

// **3. Get Data from Previous Page (GET Request)**
$venue_id = isset($_GET['venue_id']) ? intval($_GET['venue_id']) : 0;
$venue_name = isset($_GET['venue_name']) ? trim(htmlspecialchars($_GET['venue_name'])) : 'Selected Venue'; // Get venue name for display
$event_date_from_get = isset($_GET['event_date']) ? trim($_GET['event_date']) : '';

// **4. Validate Venue ID**
// Modified to not die if included in another script
if (!$venue_id && !defined('IS_INCLUDED_DASHBOARD')) {
    die("Error: Venue ID is required to make a reservation.");
}

// **5. Validate Date Format (YYYY-MM-DD)**
if ($event_date_from_get && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $event_date_from_get)) {
    error_log("Invalid date format received from GET: " . $event_date_from_get);
    $event_date_from_get = '';
    $date_format_error = "An invalid date format was received."; // Error message
}

// **6. Check if Date is in the Past**
$today = date('Y-m-d');
$past_date_error = ''; // Initialize error message variable
if ($event_date_from_get && $event_date_from_get < $today) {
     error_log("Attempt to book past date: " . $event_date_from_get);
     $past_date_error = "Reservations cannot be made for past dates ($event_date_from_get).";
     // Keep the date in the input for context but rely on backend validation to block submission
}

// **7. Fetch Venue Details (Price, Title, Image)**
$venue_price_per_hour = 0; // Initialize
$venue_title = '';
$venue_img_src = '';

try {
    $pdo = new PDO($dsn, $user_db, $pass, $options);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // Ensure error mode is set

    $stmt_price = $pdo->prepare("SELECT title, price, image_path FROM venue WHERE id = :venue_id");
    $stmt_price->bindParam(':venue_id', $venue_id, PDO::PARAM_INT);
    $stmt_price->execute();
    $venue_details = $stmt_price->fetch(PDO::FETCH_ASSOC);

    if ($venue_details) {
        $venue_price_per_hour = $venue_details['price'];
        $venue_title = htmlspecialchars($venue_details['title']);
        $venue_image_path = $venue_details['image_path'];

        // Construct image path (similar to index.php)
        $uploadsBaseUrl = '/ventech_locator/uploads/';
        $placeholderImg = 'https://via.placeholder.com/150/e2e8f0/64748b?text=No+Image';
        $venue_img_src = $placeholderImg;
        if (!empty($venue_image_path)) {
            if (filter_var($venue_image_path, FILTER_VALIDATE_URL)) {
                $venue_img_src = htmlspecialchars($venue_image_path);
            } else {
                $venue_img_src = $uploadsBaseUrl . ltrim(htmlspecialchars($venue_image_path), '/');
            }
        }

    } else if (!defined('IS_INCLUDED_DASHBOARD') && $venue_id > 0) {
        // Handle case where venue ID is not found when accessed directly
        die("Error: Venue not found.");
    }

} catch (PDOException $e) {
    error_log("Database error fetching venue price: " . $e->getMessage());
    // Optionally display an error message to the user
} finally {
    $pdo = null; // Close connection
}


// **7. Get User ID from Session**
$user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;

// **8. Form Handling Initialization**
$form_data = $_POST ?? [];
$errors = [];
$success_message = '';

// **9. Handle Form Submission (POST Request)**
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // 9.1. Retrieve form data
    $event_date = trim($_POST['event_date'] ?? '');
    $start_time = trim($_POST['start_time'] ?? '');
    $end_time = trim($_POST['end_time'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $mobile_country_code = trim($_POST['mobile_country_code'] ?? '');
    $mobile_number = trim($_POST['mobile_number'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $voucher_code = trim($_POST['voucher_code'] ?? '');
    $status = 'pending';

    // Store POST data back into $form_data for repopulating form
    $form_data = $_POST;

    // 9.2. Validate form data
    if (empty($event_date)) { $errors['event_date'] = "Event date is required."; }
    elseif ($event_date < $today) { $errors['event_date'] = "Event date cannot be in the past."; }
    if (empty($start_time)) { $errors['start_time'] = "Start time is required."; }
    if (empty($end_time)) { $errors['end_time'] = "End time is required."; }
    elseif ($start_time >= $end_time) { $errors['end_time'] = "End time must be after start time."; }
    if (empty($first_name)) { $errors['first_name'] = "First name is required."; }
    if (empty($last_name)) { $errors['last_name'] = "Last name is required."; }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors['email'] = "Valid email is required."; }
    if (!empty($mobile_number) && !preg_match('/^\d+$/', $mobile_number)) { $errors['mobile_number'] = "Mobile number should contain only digits."; }
    // Add more validation as needed...

    // 9.3. If no validation errors, process the reservation
    if (empty($errors)) {
        try {
            $pdo = new PDO($dsn, $user_db, $pass, $options);

            // Check for conflicts (adjust logic if needed for time slots)
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE venue_id = :venue_id AND event_date = :event_date AND status IN ('confirmed', 'pending')");
             $checkStmt->execute([':venue_id' => $venue_id, ':event_date' => $event_date]);
             $conflictCount = $checkStmt->fetchColumn();

             if ($conflictCount > 0) {
                 $errors['event_date'] = "Sorry, the selected date ($event_date) is already booked or has a pending request. Please choose another date or contact the venue owner.";
             } else {
                 // Prepare INSERT
                 $stmt = $pdo->prepare(
                     "INSERT INTO reservations (venue_id, user_id, event_date, start_time, end_time, first_name, last_name, email, mobile_country_code, mobile_number, address, country, notes, voucher_code, status, created_at)
                      VALUES (:venue_id, :user_id, :event_date, :start_time, :end_time, :first_name, :last_name, :email, :mobile_country_code, :mobile_number, :address, :country, :notes, :voucher_code, :status, NOW())"
                 );

                 // Bind parameters
                 $stmt->bindParam(':venue_id', $venue_id, PDO::PARAM_INT);
                 $stmt->bindParam(':user_id', $user_id, $user_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                 $stmt->bindParam(':event_date', $event_date);
                 $stmt->bindParam(':start_time', $start_time);
                 $stmt->bindParam(':end_time', $end_time);
                 $stmt->bindParam(':first_name', $first_name);
                 $stmt->bindParam(':last_name', $last_name);
                 $stmt->bindParam(':email', $email);
                 $stmt->bindParam(':mobile_country_code', $mobile_country_code);
                 $stmt->bindParam(':mobile_number', $mobile_number);
                 $stmt->bindParam(':address', $address);
                 $stmt->bindParam(':country', $country);
                 $stmt->bindParam(':notes', $notes);
                 $stmt->bindParam(':voucher_code', $voucher_code);
                 $stmt->bindParam(':status', $status);

                 $stmt->execute();

                 $success_message = "Your reservation request for $venue_name on $event_date has been submitted successfully! You will receive a notification upon confirmation.";
                 // $form_data = []; // Clear form data on success
             }

        } catch (PDOException $e) {
            error_log("Database error during reservation insertion: " . $e->getMessage());
            $errors['general'] = "We encountered an error submitting your reservation. Please try again later.";
        } finally {
            $pdo = null;
        }
    } else {
          // Add a general error if specific field errors exist
          $errors['general'] = "Please check the highlighted fields below.";
    }
}

// **10. Determine Value for Date Input Field**
$event_date_value_for_input = '';
if (!empty($form_data['event_date'])) {
    $event_date_value_for_input = htmlspecialchars($form_data['event_date']);
} elseif (!empty($event_date_from_get)) {
    $event_date_value_for_input = htmlspecialchars($event_date_from_get);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reserve: <?= $venue_name; ?> - Ventech Locator</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script> <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css" rel="stylesheet"> <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"> <style>
        body { font-family: 'Inter', sans-serif; background-color: #f3f4f6; /* Lighter gray background */ }
        /* Custom focus ring color */
        input:focus, select:focus, textarea:focus {
            --tw-ring-color: #fbbf24; /* Tailwind orange-300 */
            --tw-ring-offset-shadow: var(--tw-ring-inset) 0 0 0 var(--tw-ring-offset-width) var(--tw-ring-offset-color);
            --tw-ring-shadow: var(--tw-ring-inset) 0 0 0 calc(2px + var(--tw-ring-offset-width)) var(--tw-ring-color);
            box-shadow: var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow, 0 0 #0000);
            border-color: #f59e0b; /* Tailwind orange-500 */
       }
        /* Style for icons inside inputs */
        .input-icon-container { position: relative; }
        .input-icon { position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); color: #9ca3af; /* gray-400 */ pointer-events: none; }
        input[type="text"].pl-10, input[type="email"].pl-10, input[type="tel"].pl-10 { padding-left: 2.5rem; } /* Adjust padding for icon */
        input[type="date"], input[type="time"] { padding-right: 0.75rem; /* Ensure space for default browser icons if needed */}

        /* Section styling */
        .form-section {
            background-color: #ffffff;
            padding: 1.5rem; /* p-6 */
            border-radius: 0.5rem; /* rounded-lg */
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px -1px rgba(0, 0, 0, 0.1); /* shadow-md */
            margin-bottom: 1.5rem; /* mb-6 */
            border: 1px solid #e5e7eb; /* border-gray-200 */
        }
        .form-section-title {
            font-size: 1.125rem; /* text-lg */
            font-weight: 600; /* font-semibold */
            color: #1f2937; /* gray-800 */
            margin-bottom: 1rem; /* mb-4 */
            padding-bottom: 0.5rem; /* pb-2 */
            border-bottom: 1px solid #e5e7eb; /* border-gray-200 */
        }
        /* Enhance mobile number group */
        .mobile-group select { border-top-right-radius: 0; border-bottom-right-radius: 0; border-right-width: 0; }
        .mobile-group input { border-top-left-radius: 0; border-bottom-left-radius: 0; }

        /* Button styles */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.625rem 1.5rem; /* py-2.5 px-6 */
            border-radius: 0.375rem; /* rounded-md */
            font-weight: 600; /* font-semibold */
            text-align: center;
            transition: background-color 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
            cursor: pointer;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); /* shadow-sm */
        }
        .btn-primary {
            background-color: #f59e0b; /* orange-500 */
            color: white;
        }
        .btn-primary:hover {
            background-color: #d97706; /* orange-600 */
             box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1); /* shadow-md */
        }
        .btn-secondary {
            background-color: #4f46e5; /* indigo-600 */
            color: white;
        }
        .btn-secondary:hover {
            background-color: #4338ca; /* indigo-700 */
             box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1); /* shadow-md */
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            color: #4f46e5; /* indigo-600 */
            font-weight: 500;
            text-decoration: none;
            transition: color 0.2s;
        }
        .back-link:hover { color: #3730a3; /* indigo-800 */ text-decoration: underline; }
        .back-link i { margin-right: 0.375rem; } /* mr-1.5 */

    </style>
</head>
<body class="bg-gray-100 flex flex-col items-center min-h-screen py-8 px-4">

    <div class="w-full max-w-3xl mb-5"> <a href="venue_display.php?id=<?= $venue_id ?>" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Venue Details
        </a>
    </div>

    <div class="w-full max-w-3xl"> <div class="text-center mb-8">
            <h1 class="text-3xl md:text-4xl font-bold text-gray-800 mb-1">Reservation Request</h1>
            <p class="text-lg text-orange-600 font-semibold"><?= $venue_name; ?></p>
        </div>

        <?php if (!empty($errors['general']) || $past_date_error || $date_format_error): ?>
            <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 rounded-md relative mb-6 shadow-md" role="alert">
                <strong class="font-bold block mb-1"><i class="fas fa-exclamation-circle mr-2"></i>Please correct the following:</strong>
                <ul class="list-disc list-inside text-sm">
                    <?php if (!empty($errors['general'])): ?>
                        <li><?= htmlspecialchars($errors['general']); ?></li>
                    <?php endif; ?>
                     <?php if ($past_date_error): ?>
                         <li><?= htmlspecialchars($past_date_error); ?></li>
                     <?php endif; ?>
                     <?php if ($date_format_error): ?>
                         <li><?= htmlspecialchars($date_format_error); ?></li>
                     <?php endif; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($success_message): ?>
            <div class="bg-green-50 border-l-4 border-green-500 text-green-700 p-4 rounded-md relative mb-6 shadow-md" role="alert">
                <strong class="font-bold block mb-1"><i class="fas fa-check-circle mr-2"></i>Success!</strong>
                <span class="block text-sm"><?= htmlspecialchars($success_message); ?></span>
                <p class="mt-2 text-sm">View status on your <a href="client_dashboard.php" class="font-medium underline hover:text-green-800">dashboard</a>.</p>
            </div>
        <?php else: ?>
            <div id="reservation-summary" class="form-section mt-6 hidden">
                <h2 class="form-section-title">Reservation Summary</h2>
                <div class="flex items-center mb-4">
                    <img id="summary-venue-image" src="<?= $venue_img_src ?>" alt="<?= $venue_title ?>" class="w-20 h-20 object-cover rounded mr-4 shadow">
                    <div>
                        <h3 id="summary-venue-name" class="font-semibold text-lg text-gray-800"><?= $venue_title ?></h3>
                        <p class="text-sm text-gray-600">Price per hour: <span id="summary-venue-price">₱ <?= number_format($venue_price_per_hour, 2) ?></span></p>
                    </div>
                </div>
                <p class="mb-2"><span class="font-semibold">Event Date:</span> <span id="summary-event-date"><?= $event_date_value_for_input ?></span></p>
                <p class="mb-2"><span class="font-semibold">Start Time:</span> <span id="summary-start-time"></span></p>
                <p class="mb-2"><span class="font-semibold">End Time:</span> <span id="summary-end-time"></span></p>
                <p class="text-lg font-semibold text-orange-600">Estimated Total: <span id="summary-total-cost">₱ 0.00</span></p>
            </div>
            <form action="/ventech_locator/process_reservation.php?venue_id=<?= $venue_id ?>&venue_name=<?= urlencode($venue_name) ?><?= $event_date_from_get ? '&event_date='.$event_date_from_get : '' ?>" method="POST">

                <div class="form-section">
                    <h2 class="form-section-title">Event Details</h2>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                        <div class="md:col-span-3">
                            <label for="event-date" class="block text-sm font-medium text-gray-700 mb-1">Event Date*</label>
                            <div class="input-icon-container">
                                <i class="fas fa-calendar-alt input-icon"></i>
                                <input type="date" id="event-date" name="event_date"
                                       min="<?= $today ?>"
                                       class="block w-full pl-10 rounded-md border-gray-300 shadow-sm focus:border-orange-500 focus:ring focus:ring-orange-300 focus:ring-opacity-50 <?= isset($errors['event_date']) ? 'border-red-500 focus:ring-red-500 focus:border-red-500' : '' ?>"
                                       value="<?= $event_date_value_for_input ?>" required aria-describedby="event-date-error">
                            </div>
                            <?php if (isset($errors['event_date'])): ?><p id="event-date-error" class="error-message"><?= htmlspecialchars($errors['event_date']); ?></p><?php endif; ?>
                        </div>
                        <div>
                            <label for="start-time" class="block text-sm font-medium text-gray-700 mb-1">Start time*</label>
                            <div class="input-icon-container">
                                <i class="fas fa-clock input-icon"></i>
                                <input type="time" id="start-time" name="start_time"
                                       class="block w-full pl-10 rounded-md border-gray-300 shadow-sm focus:border-orange-500 focus:ring focus:ring-orange-300 focus:ring-opacity-50 <?= isset($errors['start_time']) ? 'border-red-500 focus:ring-red-500 focus:border-red-500' : '' ?>"
                                       value="<?= htmlspecialchars($form_data['start_time'] ?? '') ?>" required aria-describedby="start-time-error">
                            </div>
                            <?php if (isset($errors['start_time'])): ?><p id="start-time-error" class="error-message"><?= htmlspecialchars($errors['start_time']); ?></p><?php endif; ?>
                        </div>
                        <div>
                            <label for="end-time" class="block text-sm font-medium text-gray-700 mb-1">End time*</label>
                            <div class="input-icon-container">
                                <i class="fas fa-clock input-icon"></i>
                                <input type="time" id="end-time" name="end_time"
                                       class="block w-full pl-10 rounded-md border-gray-300 shadow-sm focus:border-orange-500 focus:ring focus:ring-orange-300 focus:ring-opacity-50 <?= isset($errors['end_time']) ? 'border-red-500 focus:ring-red-500 focus:border-red-500' : '' ?>"
                                       value="<?= htmlspecialchars($form_data['end_time'] ?? '') ?>" required aria-describedby="end-time-error">
                            </div>
                            <?php if (isset($errors['end_time'])): ?><p id="end-time-error" class="error-message"><?= htmlspecialchars($errors['end_time']); ?></p><?php endif; ?>
                        </div>
                    </div>
                </div><div class="form-section">
                    <h2 class="form-section-title">Contact Information</h2>
                     <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                         <div>
                             <label for="first-name" class="block text-sm font-medium text-gray-700 mb-1">First name*</label>
                             <div class="input-icon-container">
                                     <i class="fas fa-user input-icon"></i>
                                     <input type="text" id="first-name" name="first_name"
                                            class="block w-full pl-10 rounded-md border-gray-300 shadow-sm focus:border-orange-500 focus:ring focus:ring-orange-300 focus:ring-opacity-50 <?= isset($errors['first_name']) ? 'border-red-500 focus:ring-red-500 focus:border-red-500' : '' ?>"
                                            value="<?= htmlspecialchars($form_data['first_name'] ?? '') ?>" required aria-describedby="first-name-error">
                             </div>
                             <?php if (isset($errors['first_name'])): ?><p id="first-name-error" class="error-message"><?= htmlspecialchars($errors['first_name']); ?></p><?php endif; ?>
                         </div>
                         <div>
                             <label for="last-name" class="block text-sm font-medium text-gray-700 mb-1">Last name*</label>
                              <div class="input-icon-container">
                                     <i class="fas fa-user input-icon"></i>
                                     <input type="text" id="last-name" name="last_name"
                                            class="block w-full pl-10 rounded-md border-gray-300 shadow-sm focus:border-orange-500 focus:ring focus:ring-orange-300 focus:ring-opacity-50 <?= isset($errors['last_name']) ? 'border-red-500 focus:ring-red-500 focus:border-red-500' : '' ?>"
                                            value="<?= htmlspecialchars($form_data['last_name'] ?? '') ?>" required aria-describedby="last-name-error">
                             </div>
                             <?php if (isset($errors['last_name'])): ?><p id="last-name-error" class="error-message"><?= htmlspecialchars($errors['last_name']); ?></p><?php endif; ?>
                         </div>
                          <div class="md:col-span-2">
                              <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email address*</label>
                               <div class="input-icon-container">
                                       <i class="fas fa-envelope input-icon"></i>
                                       <input type="email" id="email" name="email"
                                              class="block w-full pl-10 rounded-md border-gray-300 shadow-sm focus:border-orange-500 focus:ring focus:ring-orange-300 focus:ring-opacity-50 <?= isset($errors['email']) ? 'border-red-500 focus:ring-red-500 focus:border-red-500' : '' ?>"
                                              value="<?= htmlspecialchars($form_data['email'] ?? '') ?>" required aria-describedby="email-error">
                               </div>
                               <?php if (isset($errors['email'])): ?><p id="email-error" class="error-message"><?= htmlspecialchars($errors['email']); ?></p><?php endif; ?>
                           </div>
                           <div>
                               <label for="mobile" class="block text-sm font-medium text-gray-700 mb-1">Mobile Number</label>
                             <div class="flex mobile-group">
                                 <select id="mobile-country-code" name="mobile_country_code" class="rounded-l-md border-gray-300 shadow-sm focus:border-orange-500 focus:ring focus:ring-orange-300 focus:ring-opacity-50 <?= isset($errors['mobile_number']) ? 'border-red-500 focus:ring-red-500 focus:border-red-500' : '' ?>">
                                         <option value="+63" <?= (($form_data['mobile_country_code'] ?? '+63') == '+63') ? 'selected' : ''; ?>>PH (+63)</option>
                                         <option value="+1" <?= (($form_data['mobile_country_code'] ?? '') == '+1') ? 'selected' : ''; ?>>US (+1)</option>
                                         <option value="+65" <?= (($form_data['mobile_country_code'] ?? '') == '+65') ? 'selected' : ''; ?>>SG (+65)</option>
                                         </select>
                                         <div class="input-icon-container flex-grow">
                                             <i class="fas fa-mobile-alt input-icon"></i>
                                             <input type="tel" id="mobile" name="mobile_number"
                                                    class="block w-full pl-10 rounded-r-md border-gray-300 shadow-sm focus:border-orange-500 focus:ring focus:ring-orange-300 focus:ring-opacity-50 <?= isset($errors['mobile_number']) ? 'border-red-500 focus:ring-red-500 focus:border-red-500' : '' ?>"
                                                    value="<?= htmlspecialchars($form_data['mobile_number'] ?? '') ?>" placeholder="9171234567" aria-describedby="mobile-number-error">
                                         </div>
                             </div>
                             <?php if (isset($errors['mobile_number'])): ?><p id="mobile-number-error" class="error-message"><?= htmlspecialchars($errors['mobile_number']); ?></p><?php endif; ?>
                         </div>
                         <div>
                             <label for="address" class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                              <div class="input-icon-container">
                                     <i class="fas fa-map-marker-alt input-icon"></i>
                                     <input type="text" id="address" name="address"
                                            class="block w-full pl-10 rounded-md border-gray-300 shadow-sm focus:border-orange-500 focus:ring focus:ring-orange-300 focus:ring-opacity-50 <?= isset($errors['address']) ? 'border-red-500 focus:ring-red-500 focus:border-red-500' : '' ?>"
                                            value="<?= htmlspecialchars($form_data['address'] ?? '') ?>" aria-describedby="address-error">
                             </div>
                             <?php if (isset($errors['address'])): ?><p id="address-error" class="error-message"><?= htmlspecialchars($errors['address']); ?></p><?php endif; ?>
                         </div>
                         <div class="md:col-span-2">
                              <label for="country" class="block text-sm font-medium text-gray-700 mb-1">Country</label>
                               <div class="input-icon-container">
                                       <i class="fas fa-globe input-icon"></i>
                                       <select id="country" name="country" class="block w-full pl-10 rounded-md border-gray-300 shadow-sm focus:border-orange-500 focus:ring focus:ring-orange-300 focus:ring-opacity-50 <?= isset($errors['country']) ? 'border-red-500 focus:ring-red-500 focus:border-red-500' : '' ?>" aria-describedby="country-error">
                                               <option value="Philippines" <?= (($form_data['country'] ?? 'Philippines') == 'Philippines') ? 'selected' : ''; ?>>Philippines</option>
                                               <option value="USA" <?= (($form_data['country'] ?? '') == 'USA') ? 'selected' : ''; ?>>USA</option>
                                               <option value="Singapore" <?= (($form_data['country'] ?? '') == 'Singapore') ? 'selected' : ''; ?>>Singapore</option>
                                               </select>
                               </div>
                               <?php if (isset($errors['country'])): ?><p id="country-error" class="error-message"><?= htmlspecialchars($errors['country']); ?></p><?php endif; ?>
                           </div>
                     </div>
                </div><div class="form-section">
                    <h2 class="form-section-title">Additional Information</h2>
                     <div class="grid grid-cols-1 gap-4">
                         <div>
                             <label for="notes" class="block text-sm font-medium text-gray-700 mb-1">Notes / Requests</label>
                              <div class="input-icon-container">
                                     <i class="fas fa-sticky-note input-icon" style="top: 0.75rem; transform: translateY(0);"></i> <textarea id="notes" name="notes" rows="4"
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             class="block w-full pl-10 rounded-md border-gray-300 shadow-sm focus:border-orange-500 focus:ring focus:ring-orange-300 focus:ring-opacity-50 <?= isset($errors['notes']) ? 'border-red-500 focus:ring-red-500 focus:border-red-500' : '' ?>"
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             placeholder="Any special requests? (e.g., setup time, specific equipment needs)" aria-describedby="notes-error"><?= htmlspecialchars($form_data['notes'] ?? '') ?></textarea>
                              </div>
                              <?php if (isset($errors['notes'])): ?><p id="notes-error" class="error-message"><?= htmlspecialchars($errors['notes']); ?></p><?php endif; ?>
                         </div>
                         <div>
                             <label for="voucher" class="block text-sm font-medium text-gray-700 mb-1">Voucher Code (Optional)</label>
                            <div class="input-icon-container">
                                     <i class="fas fa-tag input-icon"></i>
                                     <input type="text" id="voucher" name="voucher_code"
                                            class="block w-full pl-10 rounded-md border-gray-300 shadow-sm focus:border-orange-500 focus:ring focus:ring-orange-300 focus:ring-opacity-50 <?= isset($errors['voucher_code']) ? 'border-red-500 focus:ring-red-500 focus:border-red-500' : '' ?>"
                                            value="<?= htmlspecialchars($form_data['voucher_code'] ?? '') ?>" placeholder="Enter code if applicable" aria-describedby="voucher-error">
                            </div>
                            <?php if (isset($errors['voucher_code'])): ?><p id="voucher-error" class="error-message"><?= htmlspecialchars($errors['voucher_code']); ?></p><?php endif; ?>
                         </div>
                     </div>
                </div><div class="mt-6 flex flex-col sm:flex-row justify-between items-center gap-4">
                         <p class="text-xs text-gray-500">* Required field</p>
                    <button type="submit" class="btn btn-primary w-full sm:w-auto">
                            <i class="fas fa-paper-plane mr-2"></i>SUBMIT REQUEST
                        </button>
                    </div>

            </form>
        <?php endif; ?> </div>

<script>
    const startTimeInput = document.getElementById('start-time');
    const endTimeInput = document.getElementById('end-time');
    const eventDateInput = document.getElementById('event-date');
    const reservationSummary = document.getElementById('reservation-summary');
    const summaryStartTime = document.getElementById('summary-start-time');
    const summaryEndTime = document.getElementById('summary-end-time');
    const summaryEventDate = document.getElementById('summary-event-date');
    const summaryVenuePrice = document.getElementById('summary-venue-price');
    const summaryTotalCost = document.getElementById('summary-total-cost');
    const summaryVenueImage = document.getElementById('summary-venue-image');
    const summaryVenueName = document.getElementById('summary-venue-name');

    const venuePricePerHour = parseFloat('<?= $venue_price_per_hour ?>');
    const venueTitle = '<?= $venue_title ?>';
    const venueImgSrc = '<?= $venue_img_src ?>';

    function updateSummary() {
        const eventDate = eventDateInput.value;
        const startTime = startTimeInput.value;
        const endTime = endTimeInput.value;

        if (eventDate && startTime && endTime) {
            summaryEventDate.textContent = eventDate;
            summaryStartTime.textContent = formatTime(startTime);
            summaryEndTime.textContent = formatTime(endTime);
            summaryVenuePrice.textContent = '₱ ' + venuePricePerHour.toFixed(2);
            summaryVenueImage.src = venueImgSrc;
            summaryVenueImage.alt = venueTitle;
            summaryVenueName.textContent = venueTitle;

            const start = new Date(`${eventDate}T${startTime}`);
            const end = new Date(`${eventDate}T${endTime}`);

            if (start < end) {
                const durationMs = end.getTime() - start.getTime();
                const durationHours = durationMs / (1000 * 60 * 60);
                const totalCost = durationHours * venuePricePerHour;
                summaryTotalCost.textContent = '₱ ' + totalCost.toFixed(2);
                reservationSummary.classList.remove('hidden');
            } else {
                summaryTotalCost.textContent = '₱ 0.00';
                reservationSummary.classList.add('hidden');
            }
        } else {
            reservationSummary.classList.add('hidden');
        }
    }

    function formatTime(timeString) {
        const [hours, minutes] = timeString.split(':');
        const hourInt = parseInt(hours, 10);
        const period = hourInt < 12 ? 'AM' : 'PM';
        const displayHour = hourInt === 0 || hourInt === 12 ? 12 : hourInt % 12;
        return `${displayHour}:${minutes} ${period}`;
    }

    eventDateInput.addEventListener('change', updateSummary);
    startTimeInput.addEventListener('change', updateSummary);
    endTimeInput.addEventListener('change', updateSummary);

    // Initial call to update summary if date and times are pre-filled
    if (eventDateInput.value && startTimeInput.value && endTimeInput.value) {
        updateSummary();
    }
</script>

</body>
</html>
