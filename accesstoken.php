<?php 

ini_set('memory_limit', '512M');

require 'vendor/autoload.php';

use GuzzleHttp\Client;
use Dotenv\Dotenv;

// Load environment variables from .env file
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

if (!isset($_ENV['HELPSCOUT_CLIENT_ID']) || !isset($_ENV['HELPSCOUT_CLIENT_SECRET'])) {
    exit("You must define environment variables to connect to HelpScout\n");
}


function getAccessToken() {
    $client = new Client();
    $response = $client->post('https://api.helpscout.net/v2/oauth2/token', [
        'form_params' => [
            'grant_type' => 'client_credentials',
            'client_id' => $_ENV['HELPSCOUT_CLIENT_ID'],
            'client_secret' => $_ENV['HELPSCOUT_CLIENT_SECRET'],
        ],
    ]);

    $data = json_decode($response->getBody(), true);
    return $data['access_token'];
}

echo "Access Token: " . getAccessToken() . "\n";
exit();
