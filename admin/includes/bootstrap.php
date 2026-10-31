<?php
/**
 * includes/bootstrap.php
 *
 * Single entry point included at the very top of every admin page.
 * Sets security headers, starts the hardened session, and loads
 * the other includes. Order matters: headers before any output.
 *
 * CHANGED in the transport upgrade:
 * The old Content-Security-Policy said  script-src 'self'  which made the
 * browser silently BLOCK every inline <script> and the Chart.js / Font Awesome
 * files loaded from cdnjs.cloudflare.com. That is why charts, bulk actions,
 * menu toggles and icons could fail in the admin panel. The policy below
 * still blocks everything except your own site and the two hosts you
 * actually use (cdnjs + Google Fonts).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header(
    "Content-Security-Policy: default-src 'self'; " .
    "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com; " .
    "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; " .
    "font-src 'self' data: https://cdnjs.cloudflare.com https://fonts.gstatic.com; " .
    "img-src 'self' data: blob:; " .
    "connect-src 'self'; " .
    "frame-ancestors 'none'; " .
    "base-uri 'self'; " .
    "form-action 'self'"
);
if (FORCE_SECURE_COOKIES) {
    header('Strict-Transport-Security: max-age=63072000; includeSubDomains');
}
header('X-XSS-Protection: 1; mode=block');

session_start_secure();
