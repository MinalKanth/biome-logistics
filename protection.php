<?php


declare(strict_types=1);


require_once __DIR__ . '/admin/config/database.php';


if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['quote_csrf_token'])) {
    $_SESSION['quote_csrf_token'] = bin2hex(random_bytes(32));
}
$quoteCsrfToken = $_SESSION['quote_csrf_token'];

$quoteFormErrors = [];
$quoteFormSuccess = false;
if (!empty($_SESSION['quote_flash_success'])) {
    $quoteFormSuccess = true;
    unset($_SESSION['quote_flash_success']);
}

// Keep submitted values so the form can be re-filled if validation fails.
$quoteFormValues = [
    'full_name'         => '',
    'mobile_number'     => '',
    'email'             => '',
    'city_state'        => '',
    'service_required'  => '',
    'company_name'       => '',
    'message'            => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quote_form_submit'])) {

    // ---- CSRF check ----
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['quote_csrf_token'], $postedToken)) {
        $quoteFormErrors[] = 'Your session expired. Please refresh the page and try again.';
    } else {

        // ---- Collect + sanitize input ----
        $fullName  = trim((string) ($_POST['full_name'] ?? ''));
        $mobile    = trim((string) ($_POST['mobile_number'] ?? ''));
        $email     = trim((string) ($_POST['email'] ?? ''));
        $cityState = trim((string) ($_POST['city_state'] ?? ''));
        $service   = trim((string) ($_POST['service_required'] ?? ''));
        $company   = trim((string) ($_POST['company_name'] ?? ''));
        $message   = trim((string) ($_POST['message'] ?? ''));

        $quoteFormValues = compact(
            'fullName', 'mobile', 'email', 'cityState', 'service', 'company', 'message'
        );
        // also keep snake_case keys for the HTML below
        $quoteFormValues = [
            'full_name'        => $fullName,
            'mobile_number'    => $mobile,
            'email'            => $email,
            'city_state'       => $cityState,
            'service_required' => $service,
            'company_name'     => $company,
            'message'          => $message,
        ];

        // ---- Validation ----
        if ($fullName === '' || mb_strlen($fullName) > 150) {
            $quoteFormErrors[] = 'Full name is required (max 150 characters).';
        }

        // Accepts digits, spaces, +, -, ( ) — 7 to 20 chars total
        if ($mobile === '' || !preg_match('/^[0-9+\-\s()]{7,20}$/', $mobile)) {
            $quoteFormErrors[] = 'Please enter a valid mobile number.';
        }

        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150)) {
            $quoteFormErrors[] = 'Please enter a valid email address.';
        }

        if (mb_strlen($cityState) > 150) {
            $quoteFormErrors[] = 'City/State is too long.';
        }

        $allowedServices = [
            'Transportation & Logistics', 'Bamboo Trading', 'Legal & Compliance',
            'GST Registration', 'FSSAI Registration', 'MSME Registration',
            'Company Registration', 'Accounting & Taxation', 'Cab Rental',
        ];
        if ($service !== '' && !in_array($service, $allowedServices, true)) {
            $quoteFormErrors[] = 'Please select a valid service from the list.';
        }

        if (mb_strlen($company) > 150) {
            $quoteFormErrors[] = 'Company/Business name is too long.';
        }

        if (mb_strlen($message) > 2000) {
            $quoteFormErrors[] = 'Message is too long (max 2000 characters).';
        }

        // ---- Basic spam throttle: max 3 submissions per 10 minutes per session ----
        $now = time();
        $bucket = $_SESSION['quote_rate_limit'] ?? ['count' => 0, 'start' => $now];
        if ($now - $bucket['start'] > 600) {
            $bucket = ['count' => 0, 'start' => $now];
        }
        $bucket['count']++;
        $_SESSION['quote_rate_limit'] = $bucket;
        if ($bucket['count'] > 3) {
            $quoteFormErrors[] = 'Too many submissions. Please wait a few minutes and try again.';
        }

        // ---- Insert into DB if everything is valid ----
        if (!$quoteFormErrors) {
            try {
                $pdo = get_db();
                $stmt = $pdo->prepare(
                    'INSERT INTO quote_requests
                        (full_name, mobile_number, email, city_state, service_required, company_name, message, ip_address)
                     VALUES
                        (:full_name, :mobile_number, :email, :city_state, :service_required, :company_name, :message, :ip)'
                );
                $stmt->execute([
                    ':full_name'        => $fullName,
                    ':mobile_number'    => $mobile,
                    ':email'            => $email !== '' ? $email : null,
                    ':city_state'       => $cityState !== '' ? $cityState : null,
                    ':service_required' => $service !== '' ? $service : null,
                    ':company_name'     => $company !== '' ? $company : null,
                    ':message'          => $message !== '' ? $message : null,
                    ':ip'               => $_SERVER['REMOTE_ADDR'] ?? null,
                ]);

                // the page never resubmits the form.
                $_SESSION['quote_flash_success'] = true;
                $_SESSION['quote_csrf_token'] = bin2hex(random_bytes(32));
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;

            } catch (PDOException $e) {
                error_log('Quote form insert failed: ' . $e->getMessage());
                $quoteFormErrors[] = 'Something went wrong on our end. Please try again later.';
            }
        }
    }
}

/** Small escaping helper for use in the HTML below. */
function qf_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>