<?php
// Security headers
header("Strict-Transport-Security: max-age=31536000");
header("Content-Security-Policy: default-src 'self'");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");

$botToken = '6990761692:AAFoy2zj2Q-jnt_SD9LIimjSXBh7jXyrW3M';
$apiUrl = "https://api.telegram.org/bot{$botToken}/";

// Load users from JSON file
$usersFile = __DIR__ . '/users.json';
$users = json_decode(file_get_contents($usersFile), true) ?: [];
$adminId = 1614927658;

$content = file_get_contents("php://input");
$update = json_decode($content, true);

function botRequest($method, $params) {
    global $apiUrl;
    $url = $apiUrl . $method . '?' . http_build_query($params);
    return file_get_contents($url);
}

// Handle chat join requests
if (isset($update['chat_join_request'])) {
    $chatId = $update['chat_join_request']['chat']['id'];
    $userId = $update['chat_join_request']['from']['id'];

    // Approve the request
    botRequest('approveChatJoinRequest', [
        'chat_id' => $chatId,
        'user_id' => $userId
    ]);

    // Add user to list if not exists
    if (!in_array($userId, $users)) {
        $users[] = $userId;
        file_put_contents($usersFile, json_encode($users));
    }

    // Send welcome message
    $welcomeText = "🎉 Thank you for joining our family and keep loving us like this.";
    botRequest('sendMessage', [
        'chat_id' => $userId,
        'text' => $welcomeText,
        'parse_mode' => 'Markdown'
    ]);
}

// Handle members leaving
if (isset($update['message']['left_chat_member'])) {
    $userId = $update['message']['left_chat_member']['id'];

    $goodbyeText = "😔 Aap Hamari family ko chod kar jaa rahe ho kyu? Kripya feedback dein @Chandan1757E ko, Aur dobara join karen: @https://t.me/+oAdRN8O2O3gyNGJl";
    botRequest('sendMessage', [
        'chat_id' => $userId,
        'text' => $goodbyeText
    ]);
}

// Handle incoming messages
if (isset($update['message']['text'])) {
    $text = $update['message']['text'];
    $chatId = $update['message']['chat']['id'];
    $fromId = $update['message']['from']['id'];

    // Admin broadcast command
    if (strpos($text, '/broadcast') === 0 && $fromId == $adminId) {
        $msg = trim(str_replace('/broadcast', '', $text));
        
        if (!empty($msg)) {
            $successCount = 0;
            foreach ($users as $user) {
                $response = botRequest('sendMessage', [
                    'chat_id' => $user,
                    'text' => "📢 Broadcast:\n" . $msg
                ]);
                if ($response !== false) $successCount++;
            }
            
            botRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => "✅ Message sent to $successCount users."
            ]);
        } else {
            botRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => "❗ Please provide a message to broadcast.\nUsage: /broadcast your message"
            ]);
        }
        exit;
    }

    // Regular commands
    switch ($text) {
        case '/start':
            $reply = "Namaste! Ye bot aapki madad ke liye hai. Type /help for options.";
            break;
            
        case '/help':
            $reply = "Aap ye commands use kar sakte hain:\n"
                   . "/start - Shuru karen\n"
                   . "/help - Madad lein\n"
                   . "/info - Bot ki jankari\n"
                   . "/broadcast - Admin only";
            break;
            
        case '/info':
            $reply = "Ye ek Telegram bot hai jo automatic join approvals aur messages bhejta hai jisko @Chandan1757E ne banaya hai.";
            botRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => $reply,
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => 'Join Channel', 'url' => 'https://t.me/+oAdRN8O2O3gyNGJl']]
                    ]
                ])
            ]);
            exit;
            
        default:
            $reply = "Mujhe ye command samajh nahi aaya. Type /help for options.";
    }

    botRequest('sendMessage', [
        'chat_id' => $chatId,
        'text' => $reply
    ]);
}

// Return 200 OK
http_response_code(200);
?>