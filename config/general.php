<?php
return [
    'reg_role_id' => 2, // Default role assigned to those who register.
    'earning_ref' => [ // Referral earnings based on level (Matching Bonus)
        1 => 0.08, // User
        2 => 0.04, // User's direct upline
        3 => 0.04, // User's indirect upline
    ],
    'earning_safe' => [ // Safe completed earnings (Pool of Share)
        1 => 0.4,
        2 => 0.4,
        3 => 2.8,
        4 => 0.8,
        5 => 48,
        6 => 100,
        7 => 1000,
    ],
    'alert_kta_transfer' => env('ALERT_KTA_TRANSFER', 1.5),
    'alert_to_emails' => env('ALERT_TO_EMAILS', '')
];