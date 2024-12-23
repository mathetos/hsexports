<?php 
/**
 * This script retrieves an access token from the HelpScout API
 * and prints it to the console.
 */
require 'vendor/autoload.php';
require 'hsexports.php';

use Dotenv\Dotenv;

// Load environment variables from .env file
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

if (!isset($_ENV['HELPSCOUT_CLIENT_ID']) || !isset($_ENV['HELPSCOUT_CLIENT_SECRET'])) {
    exit("You must define environment variables to connect to HelpScout\n");
}

function getHelpScoutAccessToken() {
    // Your logic to get the access token from HelpScout API
    return 'your_access_token';
}

echo "Access Token: " . getHelpScoutAccessToken() . "\n";
exit();
