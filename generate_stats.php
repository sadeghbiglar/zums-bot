<?php
// ایجاد اتصال به دیتابیس
include('config.php');
$db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASSWORD);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// استخراج انواع پیام‌ها از دیتابیس (مقادیری که در message_type وجود دارند)
$stmt = $db->query("SELECT DISTINCT message_type FROM messages");
$messageTypes = $stmt->fetchAll(PDO::FETCH_COLUMN);

// استخراج شناسه‌های کاربران
$stmt = $db->query("SELECT DISTINCT user_id FROM messages");
$userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

// ساخت آرایه برای ذخیره آمار به تفکیک کاربر و نوع پیام
$stats = [];
foreach ($userIds as $userId) {
    foreach ($messageTypes as $type) {
        $stats[$userId][$type] = 0;  // مقدار اولیه برای هر کاربر و هر نوع پیام صفر است
    }
}

// گرفتن آمار از دیتابیس و شمارش تعداد هر نوع پیام برای هر کاربر
$stmt = $db->query("SELECT user_id, message_type, COUNT(*) as count FROM messages GROUP BY user_id, message_type");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $userId = $row['user_id'];
    $messageType = $row['message_type'];
    $stats[$userId][$messageType] = $row['count'];
}

// ساخت جدول HTML برای نمایش آمار
$html = "<table border='1'>";
$html .= "<tr><th>کاربر</th>";

// اضافه کردن ستون‌های مربوط به انواع پیام‌ها
foreach ($messageTypes as $type) {
    $html .= "<th>$type</th>";
}
$html .= "<th>جمع</th></tr>";

// اضافه کردن ردیف‌ها برای هر کاربر
foreach ($userIds as $userId) {
    $html .= "<tr><td>$userId</td>";
    $total = 0;

    foreach ($messageTypes as $type) {
        $count = $stats[$userId][$type];
        $html .= "<td>$count</td>";
        $total += $count;  // جمع تعداد پیام‌ها برای این کاربر
    }

    $html .= "<td>$total</td></tr>";  // نمایش جمع در آخرین ستون
}

$html .= "</table>";

// ذخیره آمار در فایل HTML
file_put_contents('stats.html', $html);

echo "فایل آمار با موفقیت ایجاد شد!";