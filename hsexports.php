<?php
ini_set('memory_limit', '512M');

require 'vendor/autoload.php';

use GuzzleHttp\Client;
use Dotenv\Dotenv;

// Check if .env file exists, and if not, run setup script
if (!file_exists(__DIR__ . '/.env')) {
    echo "The .env file is missing. Running setup to generate it...\n";
    setupEnv();
}

// Load environment variables from .env file
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

if (!isset($_ENV['HELPSCOUT_CLIENT_ID']) || !isset($_ENV['HELPSCOUT_CLIENT_SECRET'])) {
    exit("You must define environment variables to connect to HelpScout\n");
}

// Function to set up the .env file interactively
function setupEnv() {
    echo "Setting up your .env file...\n";
    $clientId = readline("Enter your HELPSCOUT_CLIENT_ID: ");
    $clientSecret = readline("Enter your HELPSCOUT_CLIENT_SECRET: ");

    $envContent = "HELPSCOUT_CLIENT_ID={$clientId}\nHELPSCOUT_CLIENT_SECRET={$clientSecret}\n";
    file_put_contents(__DIR__ . '/.env', $envContent);

    echo ".env file created successfully! Please rerun the script.\n";
    exit();
}

// Function to get OAuth Access Token from HelpScout
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

// Function to parse natural date ranges like "May 2024" or "May - June 2024"
function parseDateRange($dateInput) {
    $dateInput = strtolower(trim($dateInput));

    // Match single month-year pattern (e.g., "May 2024")
    if (preg_match('/^(\w+)\s+(\d{4})$/', $dateInput, $matches)) {
        $startDate = date("Y-m-d", strtotime("first day of {$matches[1]} {$matches[2]}"));
        $endDate = date("Y-m-d", strtotime("last day of {$matches[1]} {$matches[2]}"));
        return [$startDate, $endDate];
    }

    // Match range of months (e.g., "May - June 2024")
    if (preg_match('/^(\w+)\s*-\s*(\w+)\s+(\d{4})$/', $dateInput, $matches)) {
        $startDate = date("Y-m-d", strtotime("first day of {$matches[1]} {$matches[3]}"));
        $endDate = date("Y-m-d", strtotime("last day of {$matches[2]} {$matches[3]}"));
        return [$startDate, $endDate];
    }

    throw new Exception("Invalid date format. Please use 'Month Year' or 'Month - Month Year'.");
}

// Function to sanitize HTML before processing
function preSanitizeHtml($html) {
    // Remove unwanted special characters
    $html = preg_replace('/[\x00-\x1F\x7F]/u', '', $html);

    // Strip high-level ASCII characters (non-UTF8-safe)
    $html = preg_replace('/[\x80-\xFF]/u', '', $html);

    // Basic stripping of unnecessary whitespace
    $html = trim($html);

    return $html;
}

// Function to fetch mailboxes and prompt for selection
function selectMailbox($accessToken) {
    $client = new Client([
        'base_uri' => 'https://api.helpscout.net/v2/',
        'headers' => [
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type' => 'application/json',
        ],
    ]);

    $response = $client->get('mailboxes');
    $data = json_decode($response->getBody(), true);

    if (!isset($data['_embedded']['mailboxes'])) {
        throw new Exception("No mailboxes found.");
    }

    $mailboxes = $data['_embedded']['mailboxes'];
    foreach ($mailboxes as $index => $mailbox) {
        echo ($index + 1) . ". " . $mailbox['name'] . " (ID: " . $mailbox['id'] . ")\n";
    }

    echo "Select a mailbox by number: ";
    $selection = trim(fgets(STDIN));

    if (!is_numeric($selection) || $selection < 1 || $selection > count($mailboxes)) {
        throw new Exception("Invalid selection.");
    }

    return $mailboxes[$selection - 1];
}

// Function to fetch tags
function fetchTags($conversation) {
    if (!isset($conversation['tags'])) {
        return '';
    }

    $tags = [];
    foreach ($conversation['tags'] as $tag) {
        $tags[] = $tag['tag'];
    }

    return implode(', ', $tags);
}

// Function to fetch conversations from HelpScout within the specified date range and stream to CSV
function fetchAndStreamConversations($startDate, $endDate, $accessToken, $filename, $selectedMailboxId) {
    $client = new Client([
        'base_uri' => 'https://api.helpscout.net/v2/',
        'headers' => [
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type' => 'application/json',
        ],
    ]);

    $page = 1;

    // Open the file for writing
    $fp = fopen($filename, 'w');
    $headerWritten = false;

    do {
        $response = $client->get('conversations', [
            'query' => [
                'query' => "(createdAt:[{$startDate}T00:00:00Z TO {$endDate}T23:59:59Z])",
                'status' => 'all',
                'page' => $page,
                'embed' => 'threads',
            ],
        ]);
        
        $data = json_decode($response->getBody(), true);
        
        if (isset($data['_embedded']['conversations'])) {
            foreach ($data['_embedded']['conversations'] as $conversation) {
                if ($conversation['mailboxId'] != $selectedMailboxId['id']) {
                    continue; // Skip conversations from other mailboxes
                }

                $ticket = [
                    'id' => $conversation['id'],
                    'mailbox' => $conversation['mailboxId'],
                    'status' => $conversation['status'],
                    'name' => $conversation['primaryCustomer']['first'] . ' ' . $conversation['primaryCustomer']['last'],
                    'email' => $conversation['primaryCustomer']['email'] ?? '',
                    'ticket' => $conversation['_links']['web']['href'] ?? '',
                    'tags' => fetchTags($conversation),
                    'initial_message' => $conversation['_embedded']['threads'][0]['body'] ?? '',
                ];

                if (!$headerWritten) {
                    fputcsv($fp, array_keys($ticket));
                    $headerWritten = true;
                }

                fputcsv($fp, $ticket);
            }
        }

        $page++;
    } while ($data['page']['totalPages'] >= $page);

    fclose($fp);
}

function main() {
    global $argv;
    if (count($argv) !== 2) {
        echo "Usage: php script.php \"Month Year\" or \"Month - Month Year\"\n";
        exit(1);
    }

    [$startDate, $endDate] = parseDateRange($argv[1]);
    $accessToken = getAccessToken();

    // Prompt user to select a mailbox
    $selectedMailbox = selectMailbox($accessToken);

    $filename = __DIR__ . '/export-' . $startDate . '-to-' . $endDate . '-' . str_replace(' ', '-', strtolower($selectedMailbox['name'])) . '.csv';
    fetchAndStreamConversations($startDate, $endDate, $accessToken, $filename, $selectedMailbox);
}

main();
