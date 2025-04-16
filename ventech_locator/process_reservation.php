<?php
session_start(); // Make sure the session is started

// Include your database connection details here (same as in venue_reservation_form.php)
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

// Get data from the form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $venue_id = isset($_GET['venue_id']) ? intval($_GET['venue_id']) : 0;
    $venue_name = isset($_GET['venue_name']) ? trim(htmlspecialchars($_GET['venue_name'])) : 'Selected Venue';
    $event_date_from_get = isset($_GET['event_date']) ? trim($_GET['event_date']) : '';

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
    $user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;

    $errors = [];

    // Perform your validation here (same as in venue_reservation_form.php)
    if (empty($event_date)) { $errors['event_date'] = "Event date is required."; }
    elseif ($event_date < date('Y-m-d')) { $errors['event_date'] = "Event date cannot be in the past."; }
    if (empty($start_time)) { $errors['start_time'] = "Start time is required."; }
    if (empty($end_time)) { $errors['end_time'] = "End time is required."; }
    elseif ($start_time >= $end_time) { $errors['end_time'] = "End time must be after start time."; }
    if (empty($first_name)) { $errors['first_name'] = "First name is required."; }
    if (empty($last_name)) { $errors['last_name'] = "Last name is required."; }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors['email'] = "Valid email is required."; }
    if (!empty($mobile_number) && !preg_match('/^\d+$/', $mobile_number)) { $errors['mobile_number'] = "Mobile number should contain only digits."; }

    if (empty($errors)) {
        try {
            $pdo = new PDO($dsn, $user_db, $pass, $options);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Check for conflicts (adjust logic if needed for time slots)
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE venue_id = :venue_id AND event_date = :event_date AND status IN ('confirmed', 'pending')");
            $checkStmt->execute([':venue_id' => $venue_id, ':event_date' => $event_date]);
            $conflictCount = $checkStmt->fetchColumn();

            if ($conflictCount > 0) {
                // Redirect back to the form with an error message (using GET parameters)
                header("Location: venue_reservation_form.php?venue_id=" . urlencode($venue_id) . "&venue_name=" . urlencode($venue_name) . ($event_date_from_get ? '&event_date=' . urlencode($event_date_from_get) : '') . "&error=date_conflict");
                exit();
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO reservations (venue_id, user_id, event_date, start_time, end_time, first_name, last_name, email, mobile_country_code, mobile_number, address, country, notes, voucher_code, status, created_at)
                     VALUES (:venue_id, :user_id, :event_date, :start_time, :end_time, :first_name, :last_name, :email, :mobile_country_code, :mobile_number, :address, :country, :notes, :voucher_code, :status, NOW())"
                );

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

                // Redirect to the user dashboard upon successful submission
                header("Location: user_dashboard.php?reservation_success=true");
                exit();
            }

        } catch (PDOException $e) {
            error_log("Database error during reservation insertion: " . $e->getMessage());
            // Redirect back to the form with a general error message
            header("Location: venue_reservation_form.php?venue_id=" . urlencode($venue_id) . "&venue_name=" . urlencode($venue_name) . ($event_date_from_get ? '&event_date=' . urlencode($event_date_from_get) : '') . "&error=general");
            exit();
        } finally {
            $pdo = null;
        }
    } else {
        // Redirect back to the form with validation errors (you might want to pass these errors as GET parameters to display them)
        header("Location: venue_reservation_form.php?venue_id=" . urlencode($venue_id) . "&venue_name=" . urlencode($venue_name) . ($event_date_from_get ? '&event_date=' . urlencode($event_date_from_get) : '') . "&error=validation");
        exit();
    }
} else {
    // If someone tries to access this page directly
    header("Location: index.php"); // Or wherever you want to redirect them
    exit();
}
?>