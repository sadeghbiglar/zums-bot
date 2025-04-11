<?php
// ایجاد اتصال به دیتابیس
include('config.php');
$db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASSWORD);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// استخراج انواع پیام‌ها از دیتابیس (مقادیری که در message_type وجود دارند)
$stmt = $db->query("SELECT DISTINCT message_type FROM messages");
$messageTypes = $stmt->fetchAll(PDO::FETCH_COLUMN);

// استخراج شناسه‌های کاربران و نام آنها
$stmt = $db->query("SELECT DISTINCT user_id, first_name FROM messages");
$userData = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ساخت آرایه برای ذخیره آمار به تفکیک کاربر و نوع پیام
$stats = [];
foreach ($userData as $user) {
    $userId = $user['user_id'];
    $userName = $user['first_name'];
    foreach ($messageTypes as $type) {
        $stats[$userId][$type] = 0;  // مقدار اولیه برای هر کاربر و هر نوع پیام صفر است
    }
    $stats[$userId]['name'] = $userName; // ذخیره نام کاربر
}

// گرفتن آمار از دیتابیس و شمارش تعداد هر نوع پیام برای هر کاربر
$stmt = $db->query("SELECT user_id, message_type, COUNT(*) as count FROM messages GROUP BY user_id, message_type");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $userId = $row['user_id'];
    $messageType = $row['message_type'];
    $stats[$userId][$messageType] = $row['count'];
}

// ساخت جدول HTML برای نمایش آمار
$html = "
<style>
    table {
        width: 100%;
        border-collapse: collapse;
        margin: 20px 0;
        font-family: Arial, sans-serif;
    }
    th, td {
        padding: 10px;
        text-align: center;
        border: 1px solid #ddd;
    }
    th {
        background-color: #f2f2f2;
        color: #333;
    }
    tr:nth-child(even) {
        background-color: #f9f9f9;
    }
    tr:hover {
        background-color: #e2e2e2;
    }
    td {
        font-size: 14px;
    }
</style>
<table>
    <tr>
        <th>کاربر</th>
        <th>شناسه کاربر (ID)</th>";

// اضافه کردن ستون‌های مربوط به انواع پیام‌ها
foreach ($messageTypes as $type) {
    $html .= "<th>$type</th>";
}
$html .= "<th>جمع</th></tr>";

// جمع کل برای همه کاربران
$totalCounts = array_fill_keys($messageTypes, 0);  // مقدار اولیه برای هر نوع پیام صفر است
$totalAllUsers = 0;  // جمع کل برای همه کاربران

// اضافه کردن ردیف‌ها برای هر کاربر
foreach ($stats as $userId => $data) {
    $userName = $data['name'];  // نام کاربر از آرایه استخراج می‌شود
    unset($data['name']);  // حذف نام کاربر از آرایه برای اینکه در جدول تکرار نشود

    $html .= "<tr><td>$userName</td><td>$userId</td>";
    $userTotal = 0;

    foreach ($messageTypes as $type) {
        $count = isset($data[$type]) ? $data[$type] : 0;
        $html .= "<td>$count</td>";
        $userTotal += $count;
        $totalCounts[$type] += $count;  // جمع کل هر نوع پیام
    }

    $html .= "<td>$userTotal</td></tr>";  // نمایش جمع برای این کاربر
    $totalAllUsers += $userTotal;  // جمع کل برای همه کاربران
}

// ردیف جمع کل در آخر جدول
$html .= "<tr><td colspan='2'>جمع کل</td>";
foreach ($messageTypes as $type) {
    $html .= "<td>{$totalCounts[$type]}</td>";
}
$html .= "<td>$totalAllUsers</td></tr>";

$html .= "</table>";

// ذخیره آمار در فایل HTML
file_put_contents('stats.html', $html);

echo "فایل آمار با موفقیت ایجاد شد!";