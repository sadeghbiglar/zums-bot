<?php
date_default_timezone_set('Asia/Tehran');

// شامل کردن فایل پیکربندی
include('config.php');

// استفاده از مقادیر تعریف شده در config.php
$token = BOT_TOKEN;

// اتصال به دیتابیس
$db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASSWORD);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function getMessageType($message)
{
    $basicTypes = [
        'text',
        'photo',
        'video',
        'voice',
        'audio',
        'sticker',
        'contact',
        'location',
        'venue',
        'poll',
        'dice',
        'animation',
        'video_note'
    ];

    foreach ($basicTypes as $type) {
        if (isset($message[$type])) {
            return $type;
        }
    }

    if (isset($message['document'])) {
        $fileName = $message['document']['file_name'] ?? '';
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        return "document-$ext";
    }

    return 'unknown';
}

$stmt = $db->query("SELECT MAX(update_id) FROM messages");
$lastUpdateId = $stmt->fetchColumn();
$lastUpdateId = $lastUpdateId ? $lastUpdateId + 1 : 0;

while (true) {
    $url = "https://tapi.bale.ai/bot$token/getUpdates?offset=$lastUpdateId";
    $response = file_get_contents($url);
    $updates = json_decode($response, true);

    if ($updates['ok'] && count($updates['result']) > 0) {
        foreach ($updates['result'] as $update) {
            $lastUpdateId = $update['update_id'] + 1;

            if (!isset($update['message'])) continue;

            $message = $update['message'];
            $updateId = $update['update_id'];
            $userId = $message['from']['id'];
            $chatId = $message['chat']['id'];
            $firstName = $message['from']['first_name'] ?? '';
            $lastName = $message['from']['last_name'] ?? '';
            $messageDate = date('Y-m-d H:i:s', $message['date']);
            $caption = $message['caption'] ?? null;
            $text = $message['text'] ?? null;

            $messageType = getMessageType($message);
            $isCaptionValid = $caption && mb_strlen($caption) > 3 && str_ends_with($caption, '@webda_zums_asl');

            if ($text === '/stats') {
                $stmt = $db->prepare("
                    SELECT first_name, last_name, message_type, COUNT(*) as count 
                    FROM messages 
                    WHERE chat_id = :chat_id 
                    GROUP BY user_id, message_type, first_name, last_name
                ");
                $stmt->execute([':chat_id' => $chatId]);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $stats = [];
                foreach ($results as $row) {
                    $userKey = trim($row['first_name'] . ' ' . $row['last_name']);
                    $stats[$userKey][$row['message_type']] = $row['count'];
                }

                $responseText = "آمار پیام‌ها:\n";
                foreach ($stats as $user => $types) {
                    $responseText .= "کاربر $user:\n";
                    foreach ($types as $type => $count) {
                        $responseText .= "  $type: $count\n";
                    }
                }

                $sendUrl = "https://tapi.bale.ai/bot$token/sendMessage";
                $data = ['chat_id' => 6136667699, 'text' => $responseText];
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $sendUrl);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_exec($ch);
                curl_close($ch);
            } else {
                $finalType = $messageType;

                // برای پیام‌های متنی
                if ($messageType === 'text') {
                    $text = trim($text);
                    if (mb_strlen($text) > 3 && str_ends_with($text, '@webda_zums_asl')) {
                        $stmt = $db->prepare("INSERT INTO messages 
                            (update_id, user_id, chat_id, message_type, first_name, last_name, message_date, message_text) 
                            VALUES (:update_id, :user_id, :chat_id, :type, :first_name, :last_name, :message_date, :message_text)");
                        $stmt->execute([
                            ':update_id' => $updateId,
                            ':user_id' => $userId,
                            ':chat_id' => $chatId,
                            ':type' => $finalType,
                            ':first_name' => $firstName,
                            ':last_name' => $lastName,
                            ':message_date' => $messageDate,
                            ':message_text' => $text
                        ]);
                    }
                    continue;
                }

                // برای document با پسوند خاص
                if (str_starts_with($messageType, 'document-')) {
                    if ($isCaptionValid) {
                        $finalType = $messageType . '-caption';
                        $stmt = $db->prepare("INSERT INTO messages 
                            (update_id, user_id, chat_id, message_type, first_name, last_name, message_date, message_text) 
                            VALUES (:update_id, :user_id, :chat_id, :type, :first_name, :last_name, :message_date, :message_text)");
                        $stmt->execute([
                            ':update_id' => $updateId,
                            ':user_id' => $userId,
                            ':chat_id' => $chatId,
                            ':type' => $finalType,
                            ':first_name' => $firstName,
                            ':last_name' => $lastName,
                            ':message_date' => $messageDate,
                            ':message_text' => $caption
                        ]);
                    } else {
                        $stmt = $db->prepare("INSERT INTO messages 
                            (update_id, user_id, chat_id, message_type, first_name, last_name, message_date) 
                            VALUES (:update_id, :user_id, :chat_id, :type, :first_name, :last_name, :message_date)");
                        $stmt->execute([
                            ':update_id' => $updateId,
                            ':user_id' => $userId,
                            ':chat_id' => $chatId,
                            ':type' => $finalType,
                            ':first_name' => $firstName,
                            ':last_name' => $lastName,
                            ':message_date' => $messageDate
                        ]);
                    }
                    continue;
                }

                // برای بقیه انواع فایل مثل photo, video, audio, voice, animation
                if (in_array($messageType, ['photo', 'video', 'audio', 'voice', 'animation'])) {
                    if ($isCaptionValid) {
                        $finalType = $messageType . '-caption';
                        $stmt = $db->prepare("INSERT INTO messages 
                            (update_id, user_id, chat_id, message_type, first_name, last_name, message_date, message_text) 
                            VALUES (:update_id, :user_id, :chat_id, :type, :first_name, :last_name, :message_date, :message_text)");
                        $stmt->execute([
                            ':update_id' => $updateId,
                            ':user_id' => $userId,
                            ':chat_id' => $chatId,
                            ':type' => $finalType,
                            ':first_name' => $firstName,
                            ':last_name' => $lastName,
                            ':message_date' => $messageDate,
                            ':message_text' => $caption
                        ]);
                    } else {
                        $stmt = $db->prepare("INSERT INTO messages 
                            (update_id, user_id, chat_id, message_type, first_name, last_name, message_date) 
                            VALUES (:update_id, :user_id, :chat_id, :type, :first_name, :last_name, :message_date)");
                        $stmt->execute([
                            ':update_id' => $updateId,
                            ':user_id' => $userId,
                            ':chat_id' => $chatId,
                            ':type' => $finalType,
                            ':first_name' => $firstName,
                            ':last_name' => $lastName,
                            ':message_date' => $messageDate
                        ]);
                    }
                    continue;
                }
            }
        }
    }

    sleep(1);
}
