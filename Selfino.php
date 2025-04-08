<?php

require_once 'config.php';

date_default_timezone_set('Asia/Tehran'); // تنظیم منطقه زمانی به تهران
$botToken = 'BOT-TOKEN';
$groupId = -1002446115272; // شناسه عددی گروه (با منفی)
$topicId = 55235; // شناسه تاپیک (عدد مثبت)
$requiredGroup = '@semnanm'; // Required group username with @
$requiredChannel = '@semnanam'; // Required channel username with @

$admins = [
    1131070204,  // ادمین 1
    5678901234,   // ادمین 2
    9876543210,   // ادمین 3
    7003857433   // ادمین جدید
];

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

function getAllAdmins() {
    global $mysqli;
    $admins = [];
    $result = $mysqli->query("SELECT user_id, username FROM admins");
    while($row = $result->fetch_assoc()) {
        $admins[$row['user_id']] = $row['username'];
    }
    return $admins;
}

function addAdmin($userId, $targetUserId, $username = null) {
    global $mysqli;

    // بررسی معتبر بودن شناسه کاربری
    if (!is_numeric($targetUserId) || $targetUserId <= 0) {
        logActivity($userId, 'ADMIN_ADD_INVALID_ID', "Invalid ID: $targetUserId");
        return false;
    }

    // بررسی وجود ادمین با این شناسه کاربری
    $checkStmt = $mysqli->prepare("SELECT 1 FROM admins WHERE user_id = ?");
    $checkStmt->bind_param("i", $targetUserId);
    $checkStmt->execute();
    $result = $checkStmt->get_result();

    // اگر ادمین قبلاً وجود داشته باشد، خطا برگردان
    if ($result->num_rows > 0) {
        logActivity($userId, 'ADMIN_ADD_DUPLICATE', "Duplicate ID: $targetUserId");
        return false;
    }

    // اضافه کردن ادمین جدید
    try {
        $stmt = $mysqli->prepare("INSERT INTO admins (user_id, username, added_by) VALUES (?, ?, ?)");
        $stmt->bind_param("isi", $targetUserId, $username, $userId);
        $result = $stmt->execute();
        if (!$result) {
            logActivity($userId, 'ADMIN_ADD_SQL_ERROR', $mysqli->error);
        }
        return $result;
    } catch (Exception $e) {
        logActivity($userId, 'ADMIN_ADD_EXCEPTION', $e->getMessage());
        return false;
    }
}

function removeAdmin($userId) {
    global $mysqli;
    $stmt = $mysqli->prepare("DELETE FROM admins WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    return $stmt->execute();
}

function isAdmin($userId)
{
    global $mysqli;
    $stmt = $mysqli->prepare("SELECT 1 FROM admins WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
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
    if (isAdmin($userId)) {
        return true;
    }

    global $mysqli;

    $today = date('Y-m-d');
    $stmt = $mysqli->prepare("SELECT SUM(count) AS total 
            FROM submissions 
            WHERE user_id = ? AND submission_date = ?");
    $stmt->bind_param("is", $userId, $today);
    $stmt->execute();
    $result = $stmt->get_result();

    $row = $result->fetch_assoc();
    return ($row['total'] ?? 0) < 3;
}

// تابع بروزرسانی سابقه ارسال
function updateSubmission($userId)
{
    global $mysqli;

    $today = date('Y-m-d');
    $stmt = $mysqli->prepare("INSERT INTO submissions (user_id, submission_date, count) 
            VALUES (?, ?, 1) 
            ON DUPLICATE KEY UPDATE 
            count = count + 1");
    $stmt->bind_param("is", $userId, $today);
    $stmt->execute();
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

    try {
        $response = @file_get_contents($url, false, stream_context_create($options));
        $result = json_decode($response, true);

        if (!$result || !$result['ok']) {
            logActivity($chatId, 'DELETE_MESSAGE_FAILED', json_encode($result ?? ['error' => 'Unknown error']));
        }
    } catch(Exception $e) {
        logActivity($chatId, 'DELETE_MESSAGE_EXCEPTION', $e->getMessage());
    }
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
    updateUserStats($userId); // اضافه کردن این خط
    if (isAdmin($userId)) {
        sendMessage($userId, "سلام ادمین جان\n\n راهنما: /help \n\n لطفا گزینه خرید یا فروش را انتخاب کنید:", json_encode($keyboard));
    } else {
        sendMessage($userId, "لطفا گزینه خرید یا فروش را انتخاب کنید:", json_encode($keyboard));
    }
    saveUserState($userId, ['state' => 'action', 'data' => []]);
    logActivity($userId, 'START_FLOW'); // لاگ شروع فرایند
    global $mysqli;
    $stmt = $mysqli->prepare("INSERT IGNORE INTO users (user_id) VALUES (?)");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
}

function handleAction($userId, $action)
{
    // حذف پیام قبلی
    $userState = getUserState($userId) ?? ['state' => '', 'data' => []];
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
//               normal days:
                ['text' => 'صبحانه ☀️', 'callback_data' => 'meal:صبحانه'],
                ['text' => 'ناهار 🌞', 'callback_data' => 'meal:ناهار'],
                ['text' => 'شام 🌙', 'callback_data' => 'meal:شام']
//               Ramadan:
//                ['text' => 'سحری 🌅', 'callback_data' => 'meal:سحری'],
//                ['text' => 'افطار 🌙', 'callback_data' => 'meal:افطار']
            ],
            [
                ['text' => '🔙 بازگشت', 'callback_data' => 'back:dining']
            ]
        ]
    ];
    sendMessage($userId, "وعده غذایی را انتخاب کنید:", json_encode($keyboard, JSON_UNESCAPED_UNICODE));

    // ذخیره اطلاعات سلف انتخابی به همراه اطلاعات حالت قبلی
    $currentState = getUserState($userId) ?? ['state' => '', 'data' => []];
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
    $currentState = getUserState($userId) ?? ['state' => '', 'data' => []];
    $currentState['state'] = 'day';
    $currentState['data']['meal'] = $meal;
    saveUserState($userId, $currentState);
    logActivity($userId, 'MEAL_SELECTED', $meal); // لاگ انتخاب وعده
}


// تابع برای ایجاد یک کلید منحصر به فرد برای هر درخواست
function generateRequestKey($data, $userId) {
    return md5($userId . $data['action'] . $data['dining'] . $data['meal'] . $data['day'] . date('Y-m-d H'));
}

// تابع برای بررسی و ثبت درخواست‌های تکراری
function isRequestDuplicate($key) {
    global $mysqli;

    // پاکسازی رکوردهای قدیمی (بیش از 1 ساعت)
    $oneHourAgo = date('Y-m-d H:i:s', time() - 3600);
    $mysqli->query("DELETE FROM request_cache WHERE created_at < '$oneHourAgo'");

    // بررسی تکراری بودن
    $stmt = $mysqli->prepare("SELECT 1 FROM request_cache WHERE request_key = ?");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        return true;
    }

    // ثبت درخواست جدید
    $stmt = $mysqli->prepare("INSERT INTO request_cache (request_key) VALUES (?)");
    $stmt->bind_param("s", $key);
    $stmt->execute();

    return false;
}

function postToChannel($data, $userId)
{
    global $groupId, $topicId, $botToken;
    $username = $data['username'] ?? "آی دی کاربر: $userId";

    // ایجاد کلید منحصر به فرد برای این درخواست
    $requestKey = generateRequestKey($data, $userId);

    // بررسی تکراری بودن درخواست
    if (isRequestDuplicate($requestKey)) {
        logActivity($userId, 'DUPLICATE_REQUEST_PREVENTED', json_encode($data));
        // اطلاع به کاربر در مورد تکراری بودن درخواست
        sendMessage($userId, "⚠️ شما قبلاً درخواستی با همین مشخصات ثبت کرده‌اید!\n\nلطفاً از ثبت درخواست‌های تکراری خودداری کنید.");
        return false;
    }

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
        $editData = [
            'chat_id' => $groupId,
            'message_id' => $messageId,
            'reply_markup' => json_encode($deleteKeyboard)
        ];

        $options = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($editData)
            ]
        ];
        file_get_contents($url, false, stream_context_create($options));
    }

    logActivity($userId, 'POSTED_TO_CHANNEL', json_encode($data));
    updateRequestStats($data); // اضافه کردن این خط
    return true;
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

// تابع ذخیره وضعیت کاربر
function saveUserState($userId, $state) {
    global $mysqli;

    $stmt = $mysqli->prepare("INSERT INTO user_states (user_id, state_data) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE 
            state_data = VALUES(state_data),
            last_message_id = VALUES(last_message_id),
            updated_at = NOW()");

    $stateJson = json_encode($state);
    $stmt->bind_param("is", $userId, $stateJson);
    $stmt->execute();
}

// تابع دریافت وضعیت کاربر
function getUserState($userId) {
    global $mysqli;

    $stmt = $mysqli->prepare("SELECT state_data FROM user_states WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $state = json_decode($row['state_data'], true);
        return is_array($state) ? $state : ['state' => '', 'data' => []];
    }
    return ['state' => '', 'data' => []]; // مقدار پیش‌فرض
}

function deleteUserState($userId)
{
    global $mysqli;
    $stmt = $mysqli->prepare("DELETE FROM user_states WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
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
        deleteMessage($chatId, $messageId);
    }

    // مدیریت اقدامات مختلف با استفاده از switch
    switch ($action) {
        case 'delete':
            $params = explode(':', $value);
            if (count($params) < 2) {
                logActivity($userId, 'INVALID_DELETE_QUERY', $value);
                sendMessage($userId, "⚠️ خطا در پردازش درخواست!");
            } else {
                $messageId = $params[0];
                $posterId = $params[1];
                if ($userId == $posterId) {
                    $deleteUrl = "https://api.telegram.org/bot$botToken/deleteMessage";
                    $deleteData = ['chat_id' => $groupId, 'message_id' => $messageId];
                    $options = [
                        'http' => [
                            'method' => 'POST',
                            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                            'content' => http_build_query($deleteData)
                        ]
                    ];
                    $result = file_get_contents($deleteUrl, false, stream_context_create($options));
                    $resultJson = json_decode($result, true);
                    if (!$resultJson || !$resultJson['ok']) {
                        logActivity($userId, 'DELETE_FAILED', $result);
                        sendMessage($userId, "❌ خطا در حذف پیام!\n لطفا به ادمین اطلاع دهید.");
                    } else {
                        $stmt = $mysqli->prepare("UPDATE submissions SET deleted = 1 WHERE user_id = ? AND submission_date = ? AND deleted = 0");
                        $today = date('Y-m-d');
                        $stmt->bind_param("is", $posterId, $today);
                        $stmt->execute();
                        // ثبت آمار درخواست حذف شده
                        updateDeletedRequestStats();
                        sendMessage($userId, "✅ پیام با موفقیت حذف شد.");
                        logActivity($userId, 'MESSAGE_DELETED', "MessageID: $messageId");
                    }
                } else {
                    sendMessage($userId, "⚠️ شما مجوز حذف این پیام را ندارید!");
                    logActivity($userId, 'UNAUTHORIZED_DELETE', "MessageID: $messageId");
                }
            }
            break;

        case 'check_subscription':
            $isMember = isMember($userId, $requiredChannel);
            if ($isMember) {
                handleStart($userId);
            } else {
                $channelLink = "https://t.me/" . substr($requiredChannel, 1);
                $message = "❌ هنوز در کانال عضو نشدید!\n\n"
                    . "لطفا در کانال زیر عضو شوید و سپس دکمه «بررسی عضویت» را فشار دهید:\n"
                    . "<a href='$channelLink'>$requiredChannel</a>";
                $keyboard = [
                    'inline_keyboard' => [
                        [['text' => 'بررسی عضویت', 'callback_data' => 'check_subscription']]
                    ]
                ];
                sendMessage($userId, $message, json_encode($keyboard));
            }
            break;

        case 'back':
            $userState = getUserState($userId) ?? ['state' => '', 'data' => []];
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
            break;

        case 'action':
            handleAction($userId, $value);
            break;

        case 'dining':
            handleDining($userId, $value);
            break;

        case 'meal':
            handleMeal($userId, $value);
            break;

        case 'day':
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
            } else {
                $state = getUserState($userId) ?? ['state' => '', 'data' => []];
                if ($state) {
                    $state['data']['day'] = $value;
                    $state['data']['username'] = $cq['from']['username'] ?? null;
                    if (empty($state['data']['username'])) {
                        sendMessage($userId, "⚠️ برای ثبت درخواست باید یوزرنیم داشته باشید!");
                        logActivity($userId, 'MISSING_USERNAME_FINAL');
                    } else {
                        $postSuccess = postToChannel($state['data'], $userId);
                        if ($postSuccess) {
                            if (!isAdmin($userId)) {
                                updateSubmission($userId);
                            }
                            sendMessage($userId, getRemainingRequestsMessage($userId));
                            logActivity($userId, 'REQUEST_COMPLETED');
                        }
                    }
                    deleteUserState($userId);
                }
            }
            break;

        case 'admin':
            if (!isAdmin($userId)) {
                sendMessage($userId, "⛔️ دسترسی غیرمجاز!");
            } else {
                switch ($value ?? '') {
                    case 'refresh':
                        showAdminPanel($userId);
                        break;
                    case 'broadcast':
                        handleBroadcast($userId);
                        break;
                    case 'exit':
                        if ($chatId && $messageId) {
                            deleteMessage($chatId, $messageId);
                        }
                        break;
                    case 'manage':
                        handleAdminManagement($userId);
                        break;
                    case 'add':
                        handleAddAdmin($userId);
                        break;
                    case 'delete':
                        $targetUserId = explode(':', $value)[1];
                        if (removeAdmin($targetUserId)) {
                            sendMessage($userId, "✅ ادمین حذف شد");
                        }
                        break;
                }
            }
            break;

        case 'broadcast':
            if (!isAdmin($userId)) {
                sendMessage($userId, "⛔️ دسترسی غیرمجاز!");
            } else {
                switch ($value) {
                    case 'confirm':
                        $userState = getUserState($userId) ?? ['state' => '', 'data' => []];
                        $messageId = $userState['data']['message_id'] ?? null;
                        if ($messageId) {
                            list($success, $failed) = sendBroadcast($userId, $messageId);
                            sendMessage(
                                $userId,
                                "✅ ارسال همگانی انجام شد!\n\n" .
                                "موفق: $success\n" .
                                "ناموفق: $failed"
                            );
                            deleteUserState($userId);
                        }
                        break;
                    case 'cancel':
                        deleteUserState($userId);
                        sendMessage($userId, "❌ ارسال پیام همگانی لغو شد.");
                        break;
                }
            }
            break;

        default:
            sendMessage($userId, "⚠️ اقدام نامعتبر!");
            break;
    }

    // پاسخ به کال‌بک کوئری
    file_get_contents("https://api.telegram.org/bot$botToken/answerCallbackQuery?callback_query_id=" . $cq['id']);
}
elseif (isset($update['message'])) {
    $msg = $update['message'];
    $userId = $msg['from']['id'];
    $text = $msg['text'] ?? '';

    // اضافه کردن بخش مدیریت پیام همگانی
    $userState = getUserState($userId) ?? [];
    if (isset($userState['state']) && $userState['state'] === 'broadcast_input') {
        if (strtolower($text) === '/cancel') {
            deleteUserState($userId);
            sendMessage($userId, "❌ ارسال پیام همگانی لغو شد");
            return;
        }

        // اعتبارسنجی پیام
        if(strlen($text) > 1000) {
            sendMessage($userId, "⚠️ حداکثر طول پیام ۱۰۰۰ کاراکتر مجاز است!");
            return;
        }

        // ارسال پیام به عنوان پیش‌نمایش
        $previewMessageId = sendMessage($userId, "📤 در حال آماده‌سازی ارسال همگانی...\n\n".$text);

        // ذخیره پیام در وضعیت کاربر
        saveUserState($userId, [
            'state' => 'broadcast_confirm',
            'data' => [
                'message_id' => $previewMessageId,
                'text' => $text
            ]
        ]);

        // ایجاد دکمه‌های تایید/لغو
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ تایید ارسال', 'callback_data' => 'broadcast:confirm'],
                    ['text' => '❌ لغو', 'callback_data' => 'broadcast:cancel']
                ]
            ]
        ];

        editMessageText($userId, $previewMessageId, "پیام پیش‌نمایش:\n\n".$text, json_encode($keyboard));
        return;
    }

    // بقیه پردازش پیام‌ها...
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

    // اضافه کردن دستور ادمین
    elseif ($text === '/admin') {
        if(isAdmin($userId)) {
            showAdminPanel($userId);
        } else {
            sendMessage($userId, "⛔️ دسترسی غیرمجاز!");
        }
    }

    // در بخش پردازش پیام‌ها بعد از بخش مدیریت broadcast_input این کد را اضافه کنید
    elseif($userState['state'] === 'admin_add') {
        if(!is_numeric($text)) {
            sendMessage($userId, "⚠️ آیدی باید عددی باشد!");
            return;
        }

        $targetUserId = (int)$text;

        // بررسی معتبر بودن شناسه کاربری
        if ($targetUserId <= 0) {
            sendMessage($userId, "⚠️ آیدی وارد شده معتبر نیست!");
            logActivity($userId, 'ADMIN_ADD_INVALID_ID', "Invalid ID: $targetUserId");
            return;
        }

        $username = getUsername($targetUserId);

        // اضافه کردن بررسی وجود کاربر
        if(!$username) {
            sendMessage($userId, "❌ کاربر با این آیدی یافت نشد!");
            return;
        }

        if(addAdmin($userId, $targetUserId, $username)) {
            sendMessage($userId, "✅ ادمین با موفقیت اضافه شد!\nآیدی: $targetUserId\nیوزرنیم: @$username");
            logActivity($userId, 'ADMIN_ADDED', "Target: $targetUserId");
        } else {
            sendMessage($userId, "❌ خطا در اضافه کردن ادمین! ممکن است این کاربر قبلاً ادمین باشد.");
            logActivity($userId, 'ADMIN_ADD_FAILED', "Target: $targetUserId");
        }

        // بازگشت به پنل مدیریت
        deleteUserState($userId);
        handleAdminManagement($userId);
    }
}

// تابع نمایش پنل ادمین
function showAdminPanel($userId) {
    $stats = getAdminStats();
    $message = "📊 آمار ربات:\n\n"
        . "👥 کاربران:\n"
        . "• کل: " . number_format($stats['users']['total']) . "\n"
        . "• امروز: " . number_format($stats['users']['daily']) . "\n"
        . "• این هفته: " . number_format($stats['users']['weekly']) . "\n"
        . "• این ماه: " . number_format($stats['users']['monthly']) . "\n\n"
        . "📨 درخواستها:\n"
        . "• کل: " . number_format($stats['requests']['total']) . "\n"
        . "• امروز: " . number_format($stats['requests']['daily']) . "\n"
        . "• این هفته: " . number_format($stats['requests']['weekly']) . "\n"
        . "• این ماه: " . number_format($stats['requests']['monthly']) . "\n"
        . "• حذف شده: " . number_format($stats['requests']['deleted']) . "\n\n"
        . "🏆 محبوب‌ترین‌ها:\n"
        . "• سلف: " . (!empty($stats['requests']['popular']['dining']) ? getMostPopular($stats['requests']['popular']['dining']) : 'هنوز آماری ثبت نشده') . "\n"
        . "• وعده: " . (!empty($stats['requests']['popular']['meal']) ? getMostPopular($stats['requests']['popular']['meal']) : 'هنوز آماری ثبت نشده');

    $keyboard = [
        'inline_keyboard' => [
            [['text' => 'بروزرسانی آمار 🔄', 'callback_data' => 'admin:refresh'], ['text' => 'ارسال پیام همگانی 📢', 'callback_data' => 'admin:broadcast']],
            [['text' => 'مدیریت ادمین‌ها 👤', 'callback_data' => 'admin:manage']],
            [['text' => 'خروج از پنل ❌', 'callback_data' => 'admin:exit']]
        ]
    ];
    sendMessage($userId, $message, json_encode($keyboard));
}

// تابع دریافت آمار
function getAdminStats() {
    global $mysqli;

    $stats = [
        'users' => ['total' => 0, 'daily' => 0, 'weekly' => 0, 'monthly' => 0],
        'requests' => ['total' => 0, 'daily' => 0, 'weekly' => 0, 'monthly' => 0, 'deleted' => 0, 'popular' => ['dining' => [], 'meal' => []]]
    ];

    // دریافت تعداد کل کاربران
    $result = $mysqli->query("SELECT COUNT(*) AS total_users FROM users");
    if ($row = $result->fetch_assoc()) {
        $stats['users']['total'] = $row['total_users'];
    }

    // دریافت آمار کاربران فعال روزانه، هفتگی و ماهانه
    $today = date('Y-m-d');
    $week = date('Y-W');
    $month = date('Y-m');

    // کاربران فعال روزانه
    $result = $mysqli->query("SELECT COUNT(DISTINCT user_id) AS daily_users FROM user_activity WHERE period_type='daily' AND period_value='$today'");
    if ($row = $result->fetch_assoc()) {
        $stats['users']['daily'] = $row['daily_users'];
    }

    // کاربران فعال هفتگی
    $result = $mysqli->query("SELECT COUNT(DISTINCT user_id) AS weekly_users FROM user_activity WHERE period_type='weekly' AND period_value='$week'");
    if ($row = $result->fetch_assoc()) {
        $stats['users']['weekly'] = $row['weekly_users'];
    }

    // کاربران فعال ماهانه
    $result = $mysqli->query("SELECT COUNT(DISTINCT user_id) AS monthly_users FROM user_activity WHERE period_type='monthly' AND period_value='$month'");
    if ($row = $result->fetch_assoc()) {
        $stats['users']['monthly'] = $row['monthly_users'];
    }

    // دریافت آمار درخواست‌ها
    $result = $mysqli->query("SELECT period_type, period_value, value FROM stats WHERE stat_type='request'");
    while ($row = $result->fetch_assoc()) {
        if ($row['period_type'] == 'total') {
            $stats['requests']['total'] += $row['value'];
        } else if ($row['period_type'] == 'daily' && $row['period_value'] == $today) {
            $stats['requests']['daily'] += $row['value'];
        } else if ($row['period_type'] == 'weekly' && $row['period_value'] == $week) {
            $stats['requests']['weekly'] += $row['value'];
        } else if ($row['period_type'] == 'monthly' && $row['period_value'] == $month) {
            $stats['requests']['monthly'] += $row['value'];
        }
    }

    // دریافت درخواست‌های حذف‌شده از جدول submissions
    $result = $mysqli->query("SELECT COUNT(*) AS deleted FROM submissions WHERE deleted = 1");
    if ($row = $result->fetch_assoc()) {
        $stats['requests']['deleted'] = $row['deleted'];
    }

    // دریافت آمار درخواست‌های حذف شده از جدول stats
    $result = $mysqli->query("SELECT value FROM stats WHERE stat_type='deleted_request' AND period_type='total' AND period_value='all'");
    if ($row = $result->fetch_assoc()) {
        $stats['requests']['deleted'] += $row['value'];
    }

    // دریافت محبوب‌ترین سلف و وعده
    $result = $mysqli->query("SELECT item_name, count FROM popular_items WHERE item_type = 'dining' ORDER BY count DESC LIMIT 5");
    while ($row = $result->fetch_assoc()) {
        $stats['requests']['popular']['dining'][$row['item_name']] = $row['count'];
    }
    $result = $mysqli->query("SELECT item_name, count FROM popular_items WHERE item_type = 'meal' ORDER BY count DESC LIMIT 5");
    while ($row = $result->fetch_assoc()) {
        $stats['requests']['popular']['meal'][$row['item_name']] = $row['count'];
    }

    return $stats;
}
// تابع برای ثبت آمار درخواست‌های حذف شده
function updateDeletedRequestStats() {
    global $mysqli;

    $today = date('Y-m-d');
    $week = date('Y-W');
    $month = date('Y-m');

    // بروزرسانی آمار کل درخواست‌های حذف شده
    $stmt = $mysqli->prepare("INSERT INTO stats (stat_type, period_type, period_value, value) 
            VALUES ('deleted_request', 'total', 'all', 1) 
            ON DUPLICATE KEY UPDATE value = value + 1");
    $stmt->execute();

    // بروزرسانی آمار روزانه درخواست‌های حذف شده
    $stmt = $mysqli->prepare("INSERT INTO stats (stat_type, period_type, period_value, value) 
            VALUES ('deleted_request', 'daily', ?, 1) 
            ON DUPLICATE KEY UPDATE value = value + 1");
    $stmt->bind_param("s", $today);
    $stmt->execute();

    // بروزرسانی آمار هفتگی درخواست‌های حذف شده
    $stmt = $mysqli->prepare("INSERT INTO stats (stat_type, period_type, period_value, value) 
            VALUES ('deleted_request', 'weekly', ?, 1) 
            ON DUPLICATE KEY UPDATE value = value + 1");
    $stmt->bind_param("s", $week);
    $stmt->execute();

    // بروزرسانی آمار ماهانه درخواست‌های حذف شده
    $stmt = $mysqli->prepare("INSERT INTO stats (stat_type, period_type, period_value, value) 
            VALUES ('deleted_request', 'monthly', ?, 1) 
            ON DUPLICATE KEY UPDATE value = value + 1");
    $stmt->bind_param("s", $month);
    $stmt->execute();
}

// تابع بروزرسانی آمار کاربران
function updateUserStats($userId) {
    global $mysqli;

    // بررسی وجود کاربر
    $stmt = $mysqli->prepare("SELECT created_at FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    if (!$row) {
        // کاربر جدید است، ثبت در جدول users
        $stmt = $mysqli->prepare("INSERT INTO users (user_id) VALUES (?)");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
    }

    // ثبت فعالیت کاربر در دوره‌های مختلف
    $today = date('Y-m-d');
    $week = date('Y-W');
    $month = date('Y-m');

    // ثبت فعالیت روزانه
    $stmt = $mysqli->prepare("INSERT IGNORE INTO user_activity (user_id, period_type, period_value) VALUES (?, 'daily', ?)");
    $stmt->bind_param("is", $userId, $today);
    $stmt->execute();

    // ثبت فعالیت هفتگی
    $stmt = $mysqli->prepare("INSERT IGNORE INTO user_activity (user_id, period_type, period_value) VALUES (?, 'weekly', ?)");
    $stmt->bind_param("is", $userId, $week);
    $stmt->execute();

    // ثبت فعالیت ماهانه
    $stmt = $mysqli->prepare("INSERT IGNORE INTO user_activity (user_id, period_type, period_value) VALUES (?, 'monthly', ?)");
    $stmt->bind_param("is", $userId, $month);
    $stmt->execute();
}

// تابع جدید برای بروزرسانی آمار درخواستها
function updateRequestStats($data) {
    global $mysqli;

    $today = date('Y-m-d');
    $week = date('Y-W');
    $month = date('Y-m');

    // بروزرسانی آمار کل درخواست‌ها
    $stmt = $mysqli->prepare("INSERT INTO stats (stat_type, period_type, period_value, value) 
            VALUES ('request', 'total', 'all', 1) 
            ON DUPLICATE KEY UPDATE value = value + 1");
    $stmt->execute();

    // بروزرسانی آمار روزانه
    $stmt = $mysqli->prepare("INSERT INTO stats (stat_type, period_type, period_value, value) 
            VALUES ('request', 'daily', ?, 1) 
            ON DUPLICATE KEY UPDATE value = value + 1");
    $stmt->bind_param("s", $today);
    $stmt->execute();

    // بروزرسانی آمار هفتگی
    $stmt = $mysqli->prepare("INSERT INTO stats (stat_type, period_type, period_value, value) 
            VALUES ('request', 'weekly', ?, 1) 
            ON DUPLICATE KEY UPDATE value = value + 1");
    $stmt->bind_param("s", $week);
    $stmt->execute();

    // بروزرسانی آمار ماهانه
    $stmt = $mysqli->prepare("INSERT INTO stats (stat_type, period_type, period_value, value) 
            VALUES ('request', 'monthly', ?, 1) 
            ON DUPLICATE KEY UPDATE value = value + 1");
    $stmt->bind_param("s", $month);
    $stmt->execute();

    // بروزرسانی آمار محبوب‌ترین سلف‌ها و وعده‌ها
    if (isset($data['dining'])) {
        $stmt = $mysqli->prepare("INSERT INTO popular_items (item_type, item_name, count) 
                VALUES ('dining', ?, 1) 
                ON DUPLICATE KEY UPDATE count = count + 1");
        $stmt->bind_param("s", $data['dining']);
        $stmt->execute();
    }

    if (isset($data['meal'])) {
        $stmt = $mysqli->prepare("INSERT INTO popular_items (item_type, item_name, count) 
                VALUES ('meal', ?, 1) 
                ON DUPLICATE KEY UPDATE count = count + 1");
        $stmt->bind_param("s", $data['meal']);
        $stmt->execute();
    }

    // بروزرسانی آمار محبوب‌ترین سلف‌ها و وعده‌ها
    if (isset($data['dining'])) {
        $stmt = $mysqli->prepare("INSERT INTO popular_items (item_type, item_name, count) 
                VALUES ('dining', ?, 1) 
                ON DUPLICATE KEY UPDATE count = count + 1");
        $stmt->bind_param("s", $data['dining']);
        $stmt->execute();
    }

    if (isset($data['meal'])) {
        $stmt = $mysqli->prepare("INSERT INTO popular_items (item_type, item_name, count) 
                VALUES ('meal', ?, 1) 
                ON DUPLICATE KEY UPDATE count = count + 1");
        $stmt->bind_param("s", $data['meal']);
        $stmt->execute();
    }

    // بروزرسانی محبوب‌ترین سلف و وعده
    if (!empty($data['dining'])) {
        $stmt = $mysqli->prepare("INSERT INTO popular_items (item_type, item_name, count) 
                VALUES ('dining', ?, 1) 
                ON DUPLICATE KEY UPDATE count = count + 1");
        $stmt->bind_param("s", $data['dining']);
        $stmt->execute();
    }
    if (!empty($data['meal'])) {
        $stmt = $mysqli->prepare("INSERT INTO popular_items (item_type, item_name, count) 
                VALUES ('meal', ?, 1) 
                ON DUPLICATE KEY UPDATE count = count + 1");
        $stmt->bind_param("s", $data['meal']);
        $stmt->execute();
    }
    // if (empty($data['dining']) || empty($data['meal'])) {
    //     logActivity($userId, 'INVALID_REQUEST_DATA', json_encode($data));
    // }
}

// تابع جدید برای مدیریت پیام همگانی
function handleBroadcast($userId, $messageId = null) {
    if($messageId) {
        $userState = getUserState($userId) ?? ['state' => '', 'data' => []];
        $messageText = $userState['data']['text'] ?? '';

        // حذف forward و استفاده از متن اصلی
        $userState = ['state' => 'broadcast_confirm', 'data' => ['text' => $messageText]];
        saveUserState($userId, $userState);

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ تایید ارسال', 'callback_data' => 'broadcast:confirm'],
                    ['text' => '❌ لغو', 'callback_data' => 'broadcast:cancel']
                ]
            ]
        ];

        sendMessage($userId, "⚠️ آیا از ارسال این پیام به همه کاربران اطمینان دارید؟\nتعداد کاربران: " . getTotalUsersCount(), json_encode($keyboard));
    } else {
        saveUserState($userId, ['state' => 'broadcast_input']);
        sendMessage($userId, "📝 لطفا پیام خود را ارسال کنید:\n• میتوانید از Markdown استفاده کنید\n• حداکثر ۱۰۰۰ کاراکتر\n• برای لغو /cancel ارسال کنید");
    }
}

// تابع کمکی اصلاح شده
function getMostPopular($items) {
    if (empty($items)) return 'بدون آمار';
    arsort($items);
    $topItem = array_key_first($items); // اولین کلید (نام آیتم)
    $count = $items[$topItem];
    if (empty($topItem)) return "آمار ناشناخته ($count)"; // در صورت خالی بودن نام
    return "$topItem ($count)";
}
// اضافه کردن تابع شمارش کاربران
function getTotalUsersCount() {
    $statsFile = 'admin_stats.json';
    $stats = file_exists($statsFile) ? json_decode(file_get_contents($statsFile), true) : [];
    return number_format($stats['users']['total'] ?? 0);
}

function sendBroadcast($userId, $messageId) {
    global $groupId, $botToken;

    $allUsers = getAllUsers();
    $success = 0;
    $failed = [];

    foreach($allUsers as $user) {
        try {
            // بررسی معتبر بودن شناسه کاربر
            if(!is_numeric($user) || $user < 1000) {
                throw new Exception("Invalid user ID");
            }

            // ارسال پیام با استفاده از متد sendMessage به جای forward
            $url = "https://api.telegram.org/bot$botToken/sendMessage";
            $data = [
                'chat_id' => $user,
                'text' => getUserState($userId)['data']['text'] ?? '',
                'parse_mode' => 'HTML'
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

            if(!$result || !$result['ok']) {
                throw new Exception($result['description'] ?? 'Unknown error');
            }

            $success++;
        } catch(Exception $e) {
            $failed[] = [
                'user' => $user,
                'error' => $e->getMessage()
            ];
            logActivity($user, 'BROADCAST_FAILED', $e->getMessage());
        }
    }

    // ذخیره گزارش با جزئیات بیشتر
    $statsFile = 'admin_stats.json';
    $stats = file_exists($statsFile) ? json_decode(file_get_contents($statsFile), true) : [];
    $stats['broadcasts'][] = [
        'date' => date('Y-m-d H:i:s'),
        'admin' => $userId,
        'success' => $success,
        'failed' => count($failed),
        'failed_details' => $failed
    ];

    file_put_contents($statsFile, json_encode($stats, JSON_UNESCAPED_UNICODE));
    return [$success, count($failed)];
}

function getAllUsers() {
    $users = [];
    $statsFile = 'admin_stats.json';
    if(file_exists($statsFile)) {
        $stats = json_decode(file_get_contents($statsFile), true);

        // استخراج صحیح شناسه کاربران از ساختار جدید
        $userEntries = array_merge(
            array_values($stats['users']['daily'] ?? []),
            array_values($stats['users']['weekly'] ?? []),
            array_values($stats['users']['monthly'] ?? [])
        );

        // یکسان سازی و حذف موارد تکراری
        $users = array_unique(array_reduce($userEntries, function($carry, $item) {
            return array_merge($carry, $item);
        }, []));
    }
    return $users;
}

function forwardMessage($chatId, $fromChatId, $messageId) {
    global $botToken;
    $url = "https://api.telegram.org/bot$botToken/forwardMessage";
    $data = [
        'chat_id' => $chatId,
        'from_chat_id' => $fromChatId,
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

    if(!$result || !$result['ok']) {
        throw new Exception($result['description'] ?? 'Unknown error');
    }
}

function editMessageText($chatId, $messageId, $newText, $replyMarkup = null) {
    global $botToken;

    $url = "https://api.telegram.org/bot$botToken/editMessageText";
    $data = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $newText,
        'parse_mode' => 'HTML'
    ];

    if($replyMarkup) {
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
    return json_decode($response, true);
}

function handleAdminManagement($userId) {
    $admins = getAllAdmins();
    $keyboard = [
        'inline_keyboard' => [
            [['text' => '➕ افزودن ادمین', 'callback_data' => 'admin:add']],
            [['text' => '➖ حذف ادمین', 'callback_data' => 'admin:remove']],
            [['text' => '🔙 بازگشت', 'callback_data' => 'admin:back']]
        ]
    ];

    $message = "🔧 مدیریت ادمین‌ها\n\n";
    foreach($admins as $id => $username) {
        $message .= "👤 $username (ID: $id)\n";
    }

    sendMessage($userId, $message, json_encode($keyboard));
}

function handleAddAdmin($userId) {
    // حذف پیام قبلی
    $userState = getUserState($userId);
    if(isset($userState['last_message_id'])) {
        deleteMessage($userId, $userState['last_message_id']);
    }

    // ارسال پیام درخواست آیدی
    $messageId = sendMessage($userId, "لطفا آیدی عددی کاربر را ارسال کنید:");

    // ذخیره وضعیت با اطلاعات جدید
    saveUserState($userId, [
        'state' => 'admin_add',
        'last_message_id' => $messageId,
        'data' => []
    ]);

    logActivity($userId, 'ADMIN_ADD_INITIATED');
}

function handleRemoveAdmin($userId) {
    $admins = getAllAdmins();
    $keyboard = ['inline_keyboard' => []];

    foreach($admins as $id => $username) {
        $keyboard['inline_keyboard'][] = [
            ['text' => "$username (ID: $id)", 'callback_data' => "admin:delete:$id"]
        ];
    }
    $keyboard['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => 'admin:back']];

    sendMessage($userId, "ادمین مورد نظر برای حذف را انتخاب کنید:", json_encode($keyboard));
}

function getUsername($userId) {
    global $botToken;

    // بررسی معتبر بودن شناسه کاربری
    if (!is_numeric($userId) || $userId <= 0) {
        logActivity(0, 'GET_USERNAME_INVALID_ID', "Invalid ID: $userId");
        return null;
    }

    $url = "https://api.telegram.org/bot$botToken/getChat?chat_id=$userId";

    try {
        $response = @file_get_contents($url);

        if(!$response) {
            logActivity(0, 'GET_USERNAME_NO_RESPONSE', "User ID: $userId");
            return null;
        }

        $result = json_decode($response, true);
        if (!isset($result['ok']) || !$result['ok']) {
            logActivity(0, 'GET_USERNAME_API_ERROR', json_encode($result));
            return null;
        }

        return $result['result']['username'] ?? 'user_' . $userId;
    } catch (Exception $e) {
        logActivity(0, 'GET_USERNAME_EXCEPTION', $e->getMessage());
        return null;
    }
}

