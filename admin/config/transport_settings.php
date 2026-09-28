<?php
/**
 * admin/config/transport_settings.php
 * ---------------------------------------------------------------
 * ONE place for everything the transport module needs to know about
 * YOUR business. Edit the values marked  <-- EDIT , save, done.
 * This folder is locked from public access by admin/config/.htaccess.
 */
return [
    'site_url' => 'https://biomeenterprises.com',

    'company' => [
        'name'       => 'Biome Enterprises',
        'tagline'    => 'Transport & Logistics',
        'address'    => 'Your full office address, City, Assam - 782138',   // <-- EDIT
        'phone'      => '+91 96784 31656',
        'email'      => 'info@biomeenterprises.com',
        'website'    => 'biomeenterprises.com',
        'gstin'      => 'YOUR-GSTIN-HERE',                                    // <-- EDIT
        'pan'        => 'YOUR-PAN-HERE',                                      // <-- EDIT
        'state'      => 'Assam',
        'state_code' => '18',
    ],

    'invoice' => [
        'sac_code'    => '9965',       // Goods-transport services heading. Confirm exact code with your CA.
        'default_gst' => 18,           // % . Confirm the rate that applies to you with your CA.
        'gst_split'   => 'cgst_sgst',  // 'cgst_sgst' (same state)  or  'igst' (other state)
        'terms' => [
            'Payment is due as per the terms agreed at booking. Delayed payments may attract interest.',
            "Goods are carried at owner's risk unless transit insurance has been taken.",
            'Any claim for shortage or damage must be reported in writing within 48 hours of delivery.',
            'All disputes are subject to the jurisdiction of the courts in Assam.',
        ],
    ],

    'bank' => [
        'account_name' => 'Biome Enterprises',
        'bank_name'    => 'YOUR BANK NAME',        // <-- EDIT
        'account_no'   => 'YOUR ACCOUNT NUMBER',   // <-- EDIT
        'ifsc'         => 'YOUR IFSC',             // <-- EDIT
        'branch'       => 'YOUR BRANCH',           // <-- EDIT
        'upi_id'       => '',                      // optional, e.g. biome@upi
    ],

    /* Outgoing e-mail (booking confirmations / status updates).
       Use the same SMTP values you already have in transportation.php.
       Leave 'password' empty to switch e-mails off without breaking anything. */
    'mail' => [
        'host'       => 'smtp.hostinger.com',
        'port'       => 465,
        'secure'     => 'ssl',                     // 'ssl' (port 465) or 'tls' (port 587)
        'username'   => 'info@biomeenterprises.com',
        'password'   => '',                        // <-- PASTE SMTP PASSWORD HERE
        'from_email' => 'info@biomeenterprises.com',
        'from_name'  => 'Biome Enterprises Transport',
        'notify_to'  => 'director@biomeenterprises.com',  // new online bookings are e-mailed here
    ],
];
