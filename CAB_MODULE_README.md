# BIOME - Cab Services module (Service 1 of the upgrade)

Transport booking is untouched. Only the files listed below were added or changed.

## Install (5 minutes)
1. Back up your database and `cab.php` + `admin/includes/sidebar.php`.
2. Upload this folder over your site root (same paths). `admin/config/config.php` is NOT included - yours is untouched.
3. Log in to admin, open `/admin/cab_install.php`, click **Install / upgrade**. Safe to re-run. It keeps all existing cab requests (they get CAB-26-xxxxx references).
4. **Delete `admin/cab_install.php`** from the server.
5. Go to **Cab Services > Rates & Settings**: the rate card holds SAMPLE prices, all switched OFF. Enter your real prices and tick *Live*. Until then the website says "fare on request".
6. Go to **Fleet & Drivers** and add your vehicles and drivers.
7. (Optional) Cab alert e-mails use your existing transport mail settings.

## What customers get (cab.php, cab-api.php, cab-track.php)
- Same page design, upgraded booking form: pickup time, return date, approx. km, validation, bot trap, rate limit.
- Live fare estimate (server-side, from your rate card, with GST) once rates are live.
- Reference number + confirmation card + e-mail (if SMTP configured). E-mail is sent after the page responds, so slow SMTP never blocks a booking.
- `/cab-track`: reference + last 4 digits of mobile -> status, timeline, vehicle/driver once assigned, fare/balance. Brute-force lockout (8 misses / 15 min / IP).

## What staff get (admin > Cab Services)
Dashboard (KPIs, alerts, upcoming trips, 6-month billing) - Bookings (status tabs, search, filters, CSV) - Booking detail (workflow new > contacted > quoted > confirmed > assigned > on trip > completed / cancelled, fare editor, payments + refunds with receipts, notes + follow-up, timeline) - Add/Edit booking (phone / walk-in) - Fleet & Drivers (document expiry alerts) - Rates & Settings - Reports (by service, vehicle category, fleet utilisation, drivers, top customers, outstanding; CSV) - GST invoice + driver trip sheet (print / save as PDF).
Double-booking of a vehicle or driver on overlapping dates is blocked.

## Files
New: admin/cab_install.php, cab_dashboard.php, cab_bookings.php, cab_view.php, cab_form.php, cab_fleet.php, cab_rates.php, cab_reports.php, cab_invoice.php, admin/includes/cab_lib.php, cab-api.php, cab-track.php
Changed: cab.php (handler + form only), admin/includes/sidebar.php (Cab section; also defines the previously undefined `$sidebarLiveCount`)
Database: cab_booking_requests upgraded in place (status enum -> varchar, 34 columns added); new tables cab_settings, cab_vehicles, cab_drivers, cab_rates, cab_payments, cab_booking_timeline. Uses your existing transport_sequences for numbering.

## Please confirm / know
- Rates are placeholders. GST 5% and SAC 9964 are editable starting values - confirm with your CA.
- No online payment gateway: payments are recorded by staff (cash/UPI/etc).
- Invoice company, GSTIN and bank details come from your existing transport settings.
- Old status "closed" is kept as a legacy label.

## Tested (MariaDB 10.11 + PHP 8.3 on a copy of your dump)
53 automated checks passed: installer idempotency and data preservation, fare maths, full workflow, double-booking blocks, payments/refunds/overpayment, invoice lock, CSRF, auth redirect, XSS escaping, validation, honeypot, rate limits, tracking privacy and lockout. All existing admin and public pages re-checked; transport pages work.
NOT tested: real SMTP delivery, real browsers/devices (layout reuses your existing admin CSS and Bootstrap), live Hostinger environment.

## Problems found elsewhere (not changed - for later phases)
- `bamboo_orders.php` and `bamboo_order_form.php` sit in the site root but require `includes/bootstrap.php`, which only exists in `/admin` -> they crash (HTTP 500). They look like they belong in `/admin`.
- Hotel, Restaurant, NGO and Legal have public pages but NO admin panel and no management tables beyond a few request tables.
