<?php
date_default_timezone_set('Asia/Tehran'); // تنظیم منطقه زمانی به تهران
$botToken = '7741926727:AAHH-pY_nhzgc2I5fkDe79giH_IhuaCCJTw';
$groupId = -1002446115272; // شناسه عددی گروه (با منفی)
$topicId = 55235; // شناسه تاپیک (عدد مثبت)
$requiredGroup = '@semnanm'; // Required group username with @
$requiredChannel = '@semnanam'; // Required channel username with @

$update = json_decode(file_get_contents('php://input'), true);

// تابع جدید برای لاگ گیری
function logActivity($userId, $action, $details = '')
{
    $logDir = 'logs';
    if (!file_exists($logDir))
        mkdir($logDir);

    $logMessage = sprintf(
        "[%s] UserID: %d - Action: %s - Details: %s\n",
        date('Y-m-d H:i:s'),
        $userId,
        $action,
        $details
    );

    file_put_contents("$logDir/bot.log", $logMessage, FILE_APPEND);
}

function isAdmin($userId)
{

    global $requiredChannel, $botToken;

    // بررسی ادمین بودن در کانال
    $url = "https://api.telegram.org/bot$botToken/getChatMember";
    $data = [
        'chat_id' => $requiredChannel,
        'user_id' => $userId
    ];

    $options = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data)
        ]
    ];

    $response = file_get_contents($url, false, stream_context_create($options));
    $result = json_decode($response, true);
    if ($result && $result['ok']) {
        $status = $result['result']['status'];
        return in_array($status, ['administrator', 'creator']);
    }

    return false;
}

// Check group/channel membership
function isMember($userId, $chatIdentifier)
{
    global $botToken;

    // اگر شناسه کانال با @ شروع میشود، از getChat برای گرفتن ID عددی استفاده کنید
    if (strpos($chatIdentifier, '@') === 0) {
        $url = "https://api.telegram.org/bot$botToken/getChat";
        $data = ['chat_id' => $chatIdentifier];
        $options = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($data)
            ]
        ];
        $response = file_get_contents($url, false, stream_context_create($options));
        $result = json_decode($response, true);

        if (!$result || !$result['ok'])
            return false;
        $chatIdentifier = $result['result']['id'];
    }

    // بررسی عضویت با شناسه عددی
    $url = "https://api.telegram.org/bot$botToken/getChatMember";
    $data = [
        'chat_id' => $chatIdentifier,
        'user_id' => $userId
    ];

    $options = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data)
        ]
    ];

    $response = file_get_contents($url, false, stream_context_create($options));
    $result = json_decode($response, true);

    return ($result && $result['ok'] && in_array($result['result']['status'], ['member', 'administrator', 'creator']));
}

// تابع بررسی امکان ارسال
function canSubmit($userId)
{
    if (isAdmin($userId)) {  // Admins are still exempt from limits
        return true;
    }

    $submissionsFile = 'submissions.json';
    if (!file_exists($submissionsFile)) {
        file_put_contents($submissionsFile, '{}');
    }

    $submissions = json_decode(file_get_contents($submissionsFile), true);
    $today = date('Y-m-d');

    // Check if user has submissions today and count them
    if (isset($submissions[$userId]) && isset($submissions[$userId][$today])) {
        return $submissions[$userId][$today] < 3; // Allow if less than 3 submissions
    }

    return true; // Allow if no submissions today
}

// تابع بروزرسانی سابقه ارسال
function updateSubmission($userId)
{
    $submissionsFile = 'submissions.json';
    $submissions = file_exists($submissionsFile) ?
        json_decode(file_get_contents($submissionsFile), true) : [];

    $today = date('Y-m-d');

    // Initialize or update user's submissions for today
    if (!isset($submissions[$userId])) {
        $submissions[$userId] = [];
    }

    if (!isset($submissions[$userId][$today])) {
        $submissions[$userId][$today] = 1;
    } else {
        $submissions[$userId][$today]++;
    }

    // Clean up old dates (optional, keeps the file from growing too large)
    foreach ($submissions as $uid => $dates) {
        foreach ($dates as $date => $count) {
            if ($date != $today) {
                unset($submissions[$uid][$date]);
            }
        }
    }

    file_put_contents($submissionsFile, json_encode($submissions));
}
function getRemainingRequestsMessage($userId)
{
    $submissionsFile = 'submissions.json';
    $submissions = file_exists($submissionsFile) ?
        json_decode(file_get_contents($submissionsFile), true) : [];

    $today = date('Y-m-d');
    $used = isset($submissions[$userId][$today]) ? $submissions[$userId][$today] : 0;
    $remaining = 3 - $used;

    return "✅ درخواست شما در <b><a href='https://t.me/c/2446115272/55235'>گروه سلف</a></b> ثبت شد!\n" .
        "درخواست‌های باقی‌مانده امروز: $remaining";

}

function deleteMessage($chatId, $messageId)
{
    global $botToken;
    $url = "https://api.telegram.org/bot$botToken/deleteMessage";
    $data = [
        'chat_id' => $chatId,
        'message_id' => $messageId
    ];
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data)
        ]
    ];
    $response = file_get_contents($url, false, stream_context_create($options));
    $result = json_decode($response, true);

    // افزودن لاگ برای خطاها
    if (!$result || !$result['ok']) {
        logActivity($chatId, 'DELETE_MESSAGE_FAILED', json_encode($result));
    }
    file_get_contents($url, false, stream_context_create($options));
}

function sendMessage($chatId, $text, $replyMarkup = null)
{
    global $botToken;
    $url = "https://api.telegram.org/bot$botToken/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    if ($replyMarkup) {
        $data['reply_markup'] = $replyMarkup;
    }
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data)
        ]
    ];
    $response = file_get_contents($url, false, stream_context_create($options));
    $result = json_decode($response, true);
    $newMessageId = $result['result']['message_id'] ?? null;

    // ذخیره شناسه پیام در وضعیت کاربر
    if ($newMessageId) {
        $userState = getUserState($chatId) ?? [];
        $userState['last_message_id'] = $newMessageId;
        saveUserState($chatId, $userState);
    }

    logActivity($chatId, 'SEND_MESSAGE', substr($text, 0, 50));
    return $newMessageId;
}
function handleStart($userId)
{
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => 'خرید 🛒', 'callback_data' => 'action:buy'],
                ['text' => 'فروش 💰', 'callback_data' => 'action:sell']
            ]
        ]
    ];

    if (isAdmin($userId)) {
        sendMessage($userId, "سلام ادمین جان\n\n راهنما: /help \n\n لطفا گزینه خرید یا فروش را انتخاب کنید:", json_encode($keyboard));
    } else {
        sendMessage($userId, "لطفا گزینه خرید یا فروش را انتخاب کنید:", json_encode($keyboard));
    }
    saveUserState($userId, ['state' => 'action', 'data' => []]);
    logActivity($userId, 'START_FLOW'); // لاگ شروع فرایند
}

function handleAction($userId, $action)
{
    // حذف پیام قبلی
    $userState = getUserState($userId);
    if (isset($userState['last_message_id'])) {
        deleteMessage($userId, $userState['last_message_id']);
    }
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => 'مهندسی', 'callback_data' => 'dining:مهندسی'],
                ['text' => 'پردیس', 'callback_data' => 'dining:پردیس'],
                ['text' => 'هنر', 'callback_data' => 'dining:هنر'],
            ],
            [
                ['text' => 'فرهیختگان', 'callback_data' => 'dining:خوابگاه فرهیختگان'],
                ['text' => 'فرزانگان', 'callback_data' => 'dining:خوابگاه فرزانگان'],
                ['text' => 'مهرگان', 'callback_data' => 'dining:خوابگاه مهرگان'],
            ],
            [
                ['text' => 'مهدیشهر', 'callback_data' => 'dining:مهدیشهر'],
                ['text' => 'طاهر', 'callback_data' => 'dining:طاهر'],
                ['text' => 'کوثر', 'callback_data' => 'dining:خوابگاه کوثر'],
            ],
            [
                ['text' => '🔙 بازگشت', 'callback_data' => 'back:action']
            ]
        ]
    ];
    // فقط یک بار پیام ارسال شود
    $newMessageId = sendMessage($userId, "سلف مورد نظر را انتخاب کنید:", json_encode($keyboard, JSON_UNESCAPED_UNICODE));

    // ذخیره شناسه پیام جدید
    $userState['last_message_id'] = $newMessageId;
    $userState['state'] = 'dining';
    $userState['data'] = ['action' => $action];
    saveUserState($userId, $userState); // ذخیره state به صورت کامل

    logActivity($userId, 'ACTION_SELECTED', $action);
}

function handleDining($userId, $dining)
{
    $keyboard = [
        'inline_keyboard' => [
            [
//                normal days:
//                ['text' => 'صبحانه ☀️', 'callback_data' => 'meal:صبحانه'],
//                ['text' => 'ناهار 🌞', 'callback_data' => 'meal:ناهار'],
//                ['text' => 'شام 🌙', 'callback_data' => 'meal:شام']

//                Ramadan:
                ['text' => 'سحری 🌅', 'callback_data' => 'meal:سحری'],
                ['text' => 'افطار 🌙', 'callback_data' => 'meal:افطار']
            ],
            [
                ['text' => '🔙 بازگشت', 'callback_data' => 'back:dining']
            ]
        ]
    ];
    sendMessage($userId, "وعده غذایی را انتخاب کنید:", json_encode($keyboard, JSON_UNESCAPED_UNICODE));

    // ذخیره اطلاعات سلف انتخابی به همراه اطلاعات حالت قبلی
    $currentState = getUserState($userId) ?: ['state' => '', 'data' => []];
    $currentState['state'] = 'meal';
    $currentState['data']['dining'] = $dining;
    saveUserState($userId, $currentState);
    logActivity($userId, 'DINING_SELECTED', $dining); // لاگ انتخاب سلف
}

function handleMeal($userId, $meal)
{
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => 'شنبه', 'callback_data' => 'day:شنبه'],
                ['text' => 'یکشنبه', 'callback_data' => 'day:یکشنبه'],
                ['text' => 'دوشنبه', 'callback_data' => 'day:دوشنبه']
            ],
            [
                ['text' => 'سه‌شنبه', 'callback_data' => 'day:سه‌شنبه'],
                ['text' => 'چهارشنبه', 'callback_data' => 'day:چهارشنبه'],
                ['text' => 'پنج‌شنبه', 'callback_data' => 'day:پنج‌شنبه']
            ],
            [
                ['text' => 'جمعه', 'callback_data' => 'day:جمعه']
            ],
            [
                ['text' => '🔙 بازگشت', 'callback_data' => 'back:meal']
            ]
        ]
    ];
    sendMessage($userId, "لطفاً روز مورد نظر را انتخاب کنید:", json_encode($keyboard, JSON_UNESCAPED_UNICODE));

    // ذخیره اطلاعات وعده غذایی به همراه اطلاعات حالت قبلی
    $currentState = getUserState($userId) ?: ['state' => '', 'data' => []];
    $currentState['state'] = 'day';
    $currentState['data']['meal'] = $meal;
    saveUserState($userId, $currentState);
    logActivity($userId, 'MEAL_SELECTED', $meal); // لاگ انتخاب وعده
}


function postToChannel($data, $userId)
{
    global $groupId, $topicId, $botToken;
    $username = $data['username'] ?? "آی دی کاربر: $userId";

    $message = "📣 <b>درخواست جدید!</b>\n"
        . "نوع درخواست: " . ($data['action'] === 'buy' ? 'خرید' : 'فروش') . "\n"
        . "سلف: <b>{$data['dining']}</b>\n"
        . "وعده: <b>{$data['meal']}</b>\n"
        . "روز: <b>{$data['day']}</b>\n"
        . "در صورت انصراف، درخواست را حذف کنید.";

    // ارسال اولیه پیام با دکمه ارتباط
    $initialKeyboard = [
        'inline_keyboard' => [
            [['text' => 'ارتباط با دانشجو', 'url' => "https://t.me/$username"]]
        ]
    ];

    $messageId = sendMessageToTopic($groupId, $topicId, $message, $initialKeyboard);

    // افزودن دکمه حذف پس از ارسال
    if ($messageId) {
        $deleteKeyboard = [
            'inline_keyboard' => [
                [
                    ['text' => 'ارتباط با دانشجو', 'url' => "https://t.me/$username"],
                    ['text' => 'حذف ❌', 'callback_data' => "delete:$messageId:$userId"]
                ]
            ]
        ];

        $url = "https://api.telegram.org/bot$botToken/editMessageReplyMarkup";
        $data = [
            'chat_id' => $groupId,
            'message_id' => $messageId,
            'reply_markup' => json_encode($deleteKeyboard)
        ];

        $options = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($data)
            ]
        ];
        file_get_contents($url, false, stream_context_create($options));
    }

    logActivity($userId, 'POSTED_TO_CHANNEL', json_encode($data));
}
function sendMessageToTopic($chatId, $threadId, $text, $replyMarkup = null)
{
    global $botToken;

    $url = "https://api.telegram.org/bot$botToken/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'message_thread_id' => $threadId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true
    ];

    if ($replyMarkup) {
        $data['reply_markup'] = json_encode($replyMarkup);
    }

    $options = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data)
        ]
    ];

    $response = file_get_contents($url, false, stream_context_create($options));
    $result = json_decode($response, true);
    return $result['ok'] ? $result['result']['message_id'] : null; // بازگرداندن شناسه پیام
}


function saveUserState($userId, $state)
{
    // اضافه کردن شناسه آخرین پیام به وضعیت
    $state['last_message_id'] = $GLOBALS['last_message_id'] ?? null;
    file_put_contents("states/$userId.json", json_encode($state, JSON_UNESCAPED_UNICODE));
}

function getUserState($userId)
{
    $file = "states/$userId.json";
    return file_exists($file) ? json_decode(file_get_contents($file), true) : null;
}

function deleteUserState($userId)
{
    $file = "states/$userId.json";
    if (file_exists($file))
        unlink($file);
}

// Create states directory if not exists
if (isset($update['callback_query'])) {
    $cq = $update['callback_query'];
    $userId = $cq['from']['id'];
    $messageId = $cq['message']['message_id'];
    $chatId = $cq['message']['chat']['id'];
    $data = explode(':', $cq['data'], 2);
    $action = $data[0];
    $value = $data[1] ?? null;

    logActivity($userId, 'BUTTON_CLICKED', $cq['data']);

    // حذف پیام فعلی به جز برای actions خاص
    if (!in_array($action, ['delete', 'check_subscription'])) {
        deleteMessage($chatId, $messageId); // استفاده از متغیرهای اصلاح شده
    }
    // مدیریت دکمه حذف
    if ($action === 'delete') {
        // اصلاح: استخراج صحیح پارامترها
        $params = explode(':', $value);
        if (count($params) < 2) {
            logActivity($userId, 'INVALID_DELETE_QUERY', $value);
            sendMessage($userId, "⚠️ خطا در پردازش درخواست!");
            return;
        }
        $messageId = $params[0];
        $posterId = $params[1];

        if ($userId == $posterId) {
            // حذف پیام از گروه
            $deleteUrl = "https://api.telegram.org/bot$botToken/deleteMessage";
            $deleteData = [
                'chat_id' => $groupId,
                'message_id' => $messageId
            ];

            $options = [
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => http_build_query($deleteData)
                ]
            ];
            $result = file_get_contents($deleteUrl, false, stream_context_create($options));

            // بررسی نتیجه حذف
            $resultJson = json_decode($result, true);
            if (!$resultJson || !$resultJson['ok']) {
                logActivity($userId, 'DELETE_FAILED', $result);
                sendMessage($userId, "❌ خطا در حذف پیام!\n لطفا به ادمین اطلاع دهید.");
            } else {
                sendMessage($userId, "✅ پیام با موفقیت حذف شد.");
                logActivity($userId, 'MESSAGE_DELETED', "MessageID: $messageId");
            }
        } else {
            sendMessage($userId, "⚠️ شما مجوز حذف این پیام را ندارید!");
            logActivity($userId, 'UNAUTHORIZED_DELETE', "MessageID: $messageId");
        }

        // پاسخ به کال‌بک کوئری
        file_get_contents("https://api.telegram.org/bot$botToken/answerCallbackQuery?callback_query_id=" . $cq['id']);
    } elseif ($action === 'check_subscription') {
        $isMember = isMember($userId, $requiredChannel);

        if ($isMember) {
            handleStart($userId);
        } else {
            $channelLink = "https://t.me/" . substr($requiredChannel, 1);
            $message = "❌ هنوز در کانال عضو نشدید!\n\n"
                . "لطفا در کانال زیر عضو شوید و سپس دکمه «بررسی عضویت» را فشار دهید:\n"
                . "<a href='$channelLink'>$requiredChannel</a>";

            // اضافه کردن دکمه «بررسی عضویت» به پیام
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => 'بررسی عضویت', 'callback_data' => 'check_subscription']]
                ]
            ];

            sendMessage($userId, $message, json_encode($keyboard));
        }
        // تایید دریافت کلیک دکمه
        file_get_contents("https://api.telegram.org/bot$botToken/answerCallbackQuery?callback_query_id=" . $cq['id']);
    } elseif ($action === 'back') {
        $userState = getUserState($userId);
        // حذف پیام فعلی قبل از بازگشت
        deleteMessage($chatId, $messageId);
        switch ($value) {
            case 'action':
                handleStart($userId);
                break;
            case 'dining':
                $actionData = $userState['data']['action'] ?? '';
                handleAction($userId, $actionData);
                break;
            case 'meal':
                $diningData = $userState['data']['dining'] ?? '';
                handleDining($userId, $diningData);
                break;
            default:
                sendMessage($userId, "⚠️ خطا در بازگشت به مرحله قبل!");
                break;
        }
    }
    // Handle other existing callback actions
    elseif ($action === 'action') {
        handleAction($userId, $value);
    } elseif ($action === 'dining') {
        handleDining($userId, $value);
    } elseif ($action === 'meal') {
        handleMeal($userId, $value);
    } elseif ($action === 'day') {
        if (!isAdmin($userId) && !canSubmit($userId)) {
            $submissionsFile = 'submissions.json';
            $submissions = json_decode(file_get_contents($submissionsFile), true);
            $today = date('Y-m-d');
            $used = isset($submissions[$userId][$today]) ? $submissions[$userId][$today] : 0;

            sendMessage(
                $userId,
                "⚠️ شما امروز $used درخواست ارسال کرده‌اید!\n" .
                "هر کاربر مجاز به ارسال حداکثر ۳ درخواست در روز می‌باشد."
            );
            logActivity($userId, 'LIMIT_EXCEEDED');
            deleteUserState($userId);
            return;
        }

        $state = getUserState($userId);
        if ($state) {
            $state['data']['day'] = $value;
            $state['data']['username'] = $cq['from']['username'] ?? null;

            if (empty($state['data']['username'])) {
                sendMessage($userId, "⚠️ برای ثبت درخواست باید یوزرنیم داشته باشید!");
                logActivity($userId, 'MISSING_USERNAME_FINAL');
            } else {
                postToChannel($state['data'], $userId);
                updateSubmission($userId);
                sendMessage($userId, getRemainingRequestsMessage($userId));
                logActivity($userId, 'REQUEST_COMPLETED');
            }
            deleteUserState($userId);
        }
    }

    file_get_contents("https://api.telegram.org/bot$botToken/answerCallbackQuery?callback_query_id=" . $cq['id']);
} elseif (isset($update['message'])) {
    $msg = $update['message'];
    $userId = $msg['from']['id'];
    $text = $msg['text'] ?? '';

    if ($text === '/start') {
        if (empty($msg['from']['username'])) {
            sendMessage($userId, "⚠️ برای استفاده از ربات باید یوزرنیم داشته باشید!\nلطفا از تنظیمات تلگرام خود یک یوزرنیم تنظیم کنید.");
            logActivity($userId, 'MISSING_USERNAME');
        } else {
            // Check channel membership
            $isInChannel = isMember($userId, $requiredChannel);
            if (!$isInChannel) {
                $channelLink = "https://t.me/" . substr($requiredChannel, 1);
                $message = "❗️ برای استفاده از ربات باید در کانال زیر عضو شوید:\n\n"
                    . "ـ کانال: <a href='$channelLink'>$requiredChannel</a>\n\n"
                    . "پس از عضویت، دکمه «بررسی عضویت» را فشار دهید:";
                $keyboard = [
                    'inline_keyboard' => [
                        [['text' => 'بررسی عضویت', 'callback_data' => 'check_subscription']]
                    ]
                ];
                sendMessage($userId, $message, json_encode($keyboard));
                logActivity($userId, 'SUBSCRIPTION_REQUIRED');
            } else {
                handleStart($userId);
            }
        }
    } // Closing brace for '/start' check

    // Handle /help command here, inside the message block
    elseif ($text === '/help') {
        $helpMessage = "📚 راهنمای ربات:\n\n"
            . "• برای شروع فرایند خرید/فروش از دستور /start استفاده کنید\n"
            . "• هر کاربر مجاز به ۳ درخواست در روز است\n"
            . "• برای ارتباط با ادمین: @amposhtiban\n"
            . "• برای اطلاعات بیشتر و اخبار دانشگاه:\n"
            . "  - گروه: @semnanm\n"
            . "  - کانال: @semnanam\n\n"
            . "امیدواریم که این ربات به شما برای خرید و یا فروش وعده های غذایی کمک کنه. 🧡";
        sendMessage($userId, $helpMessage);
        logActivity($userId, 'HELP_COMMAND');
    }
}

