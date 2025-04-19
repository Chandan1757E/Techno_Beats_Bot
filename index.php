<?php
// =============================================
// ERROR REPORTING AND LOGGING CONFIGURATION
// =============================================

// Enable error logging to file
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');
error_reporting(E_ALL);

// =============================================
// ENVIRONMENT CONFIGURATION
// =============================================

// Get bot token from environment variable
$BOT_TOKEN = getenv('BOT_TOKEN');
if (!$BOT_TOKEN) {
    error_log('BOT_TOKEN environment variable not set');
    http_response_code(500);
    header('Content-Type: application/json');
    die(json_encode(['status' => 'error', 'message' => 'Server configuration error']));
}

// =============================================
// DATA STORAGE INITIALIZATION
// =============================================

// Define data files
$USERS_FILE = __DIR__ . '/users.json';
$LOG_FILE = __DIR__ . '/log.txt';

// Initialize users.json if it doesn't exist
if (!file_exists($USERS_FILE)) {
    file_put_contents($USERS_FILE, json_encode(['users' => [], 'statistics' => []]));
    chmod($USERS_FILE, 0664);
}

// Initialize log file if it doesn't exist
if (!file_exists($LOG_FILE)) {
    file_put_contents($LOG_FILE, "=== Telegram Bot Log ===\n");
    chmod($LOG_FILE, 0664);
}

// =============================================
// REQUEST HANDLING
// =============================================

// Get the raw input from Telegram
$input = file_get_contents('php://input');
if (empty($input)) {
    error_log('Empty input received');
    http_response_code(400);
    header('Content-Type: application/json');
    die(json_encode(['status' => 'error', 'message' => 'Empty request']));
}

// Decode the JSON update
$update = json_decode($input, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log('Invalid JSON received: ' . json_last_error_msg());
    http_response_code(400);
    header('Content-Type: application/json');
    die(json_encode(['status' => 'error', 'message' => 'Invalid JSON']));
}

// =============================================
// UPDATE PROCESSING
// =============================================

// Load existing users data
$data = json_decode(file_get_contents($USERS_FILE), true);
if ($data === null) {
    $data = ['users' => [], 'statistics' => []];
}

// Handle channel posts
if (isset($update["channel_post"])) {
    $channel_id = $update["channel_post"]["chat"]["id"] ?? 'unknown';
    $message = "Channel post received from: $channel_id";
    file_put_contents($LOG_FILE, date('[Y-m-d H:i:s] ') . $message . "\n", FILE_APPEND);
}

// Handle chat join requests
if (isset($update["chat_join_request"])) {
    $request = $update["chat_join_request"];
    $chat_id = $request["chat"]["id"] ?? 'unknown';
    $user_id = $request["from"]["id"] ?? 'unknown';
    $first_name = $request["from"]["first_name"] ?? 'Unknown';
    $last_name = $request["from"]["last_name"] ?? '';
    $username = $request["from"]["username"] ?? '';

    // Update user statistics
    if (!isset($data['users'][$user_id])) {
        $data['users'][$user_id] = [
            'first_name' => $first_name,
            'last_name' => $last_name,
            'username' => $username,
            'join_requests' => 0,
            'first_seen' => date('Y-m-d H:i:s'),
            'last_seen' => date('Y-m-d H:i:s')
        ];
    }

    $data['users'][$user_id]['join_requests']++;
    $data['users'][$user_id]['last_seen'] = date('Y-m-d H:i:s');

    // Update global statistics
    if (!isset($data['statistics']['total_requests'])) {
        $data['statistics']['total_requests'] = 0;
    }
    $data['statistics']['total_requests']++;
    $data['statistics']['last_request'] = date('Y-m-d H:i:s');

    // Save the updated data
    file_put_contents($USERS_FILE, json_encode($data, JSON_PRETTY_PRINT));

    // Prepare approval request
    $approve_url = "https://api.telegram.org/bot{$BOT_TOKEN}/approveChatJoinRequest";
    $post_data = [
        'chat_id' => $chat_id,
        'user_id' => $user_id
    ];

    // Send approval request to Telegram
    $options = [
        'http' => [
            'header' => "Content-Type: application/json\r\n",
            'method' => 'POST',
            'content' => json_encode($post_data),
            'timeout' => 5 // 5 second timeout
        ]
    ];

    $context = stream_context_create($options);
    $result = @file_get_contents($approve_url, false, $context);

    // Log the result
    $log_message = sprintf(
        "[%s] Approved join request - User: %s %s (@%s, ID: %s) for Chat: %s. Result: %s\n",
        date('Y-m-d H:i:s'),
        $first_name,
        $last_name,
        $username,
        $user_id,
        $chat_id,
        $result ?: 'No response'
    );
    file_put_contents($LOG_FILE, $log_message, FILE_APPEND);
}

// =============================================
// HEALTH CHECK ENDPOINT
// =============================================

// Handle health check requests
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $_SERVER['REQUEST_URI'] === '/health') {
    header('Content-Type: application/json');
    die(json_encode([
        'status' => 'ok',
        'timestamp' => date('Y-m-d H:i:s'),
        'statistics' => $data['statistics'] ?? []
    ]));
}

// =============================================
// RESPONSE
// =============================================

// Always respond with 200 OK to Telegram
http_response_code(200);
header('Content-Type: text/plain');
echo "OK";

// =============================================
// HELPER FUNCTIONS
// =============================================

/**
 * Logs a message to both error log and application log
 */
function log_message($message, $is_error = false) {
    global $LOG_FILE;
    $formatted = date('[Y-m-d H:i:s] ') . $message . "\n";
    file_put_contents($LOG_FILE, $formatted, FILE_APPEND);
    if ($is_error) {
        error_log($message);
    }
}