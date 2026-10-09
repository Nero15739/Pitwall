<?php
/* Pit Wall settings. Copy this file to config.php (next to index.php) and change what you need.
   Everything is optional: with no config.php the site uses SQLite in storage/ and the defaults below. */
return [
    'site_name' => 'Pit Wall',

    // Dates are shown in this time zone until the reader's browser re-renders them in their own.
    'timezone' => 'UTC',

    // Database. SQLite needs no setup and suits a league site well. To use a Hostinger MySQL
    // database instead (hPanel -> Databases -> MySQL Databases), uncomment and fill this in:
    // 'db' => ['driver' => 'mysql', 'host' => 'localhost', 'name' => 'u123456789_pitwall', 'user' => 'u123456789_pitwall', 'pass' => 'your-db-password'],

    // Where the database, published data, sessions and logs live. Installed in public_html (as on
    // Hostinger) the default is a pitwall-storage folder beside public_html, outside the web root;
    // anywhere else it's storage/ next to index.php, protected by .htaccess. Override with:
    // 'storage_dir' => '/home/u123456789/pitwall-storage',

    'api_rate_limit' => 120,       // API requests per key per minute
    'max_upload_mb' => 10,         // largest file accepted (iRacing exports are about 0.5 MB)
    'session_hours' => 12,         // admin sign-in lasts this long when idle

    // Only set true when Cloudflare or Hostinger's CDN sits in front of the site, so sign-in
    // throttling sees visitors' real addresses. Left false, forwarded addresses are ignored.
    'trust_proxy_headers' => false,

    'debug' => false,              // true shows error details on screen; keep false on the live site
];
