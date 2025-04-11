<?php
// تنظیم منطقه زمانی
date_default_timezone_set('Asia/Tehran');

// شامل کردن فایل پیکربندی
include('config.php');

// اتصال به دیتابیس
try {
    $db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASSWORD);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("خطا در اتصال به دیتابیس: " . $e->getMessage());
}

// تابع تبدیل تاریخ میلادی به شمسی
function gregorianToJalali($g_y, $g_m, $g_d)
{
    $g_days_in_month = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    $j_days_in_month = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];

    $gy = $g_y - 1600;
    $gm = $g_m - 1;
    $gd = $g_d - 1;

    $g_day_no = 365 * $gy + floor(($gy + 3) / 4) - floor(($gy + 99) / 100) + floor(($gy + 399) / 400);
    for ($i = 0; $i < $gm; ++$i) {
        $g_day_no += $g_days_in_month[$i];
    }
    if ($gm > 1 && (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0))) {
        $g_day_no++;
    }
    $g_day_no += $gd;

    $j_day_no = $g_day_no - 79;
    $j_np = floor($j_day_no / 12053);
    $j_day_no %= 12053;

    $jy = 979 + 33 * $j_np + 4 * floor($j_day_no / 1461);
    $j_day_no %= 1461;

    if ($j_day_no >= 366) {
        $jy += floor(($j_day_no - 1) / 365);
        $j_day_no = ($j_day_no - 1) % 365;
    }

    for ($i = 0; $i < 11 && $j_day_no >= $j_days_in_month[$i]; ++$i) {
        $j_day_no -= $j_days_in_month[$i];
    }
    $jm = $i + 1;
    $jd = $j_day_no + 1;

    return [$jy, $jm, $jd];
}

// تابع تبدیل تاریخ شمسی به میلادی
function jalaliToGregorian($j_y, $j_m, $j_d)
{
    $j_days_in_month = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];

    $jy = $j_y - 979;
    $jm = $j_m - 1;
    $jd = $j_d - 1;

    $j_day_no = 365 * $jy + floor($jy / 33) * 8 + floor(($jy % 33 + 3) / 4);
    for ($i = 0; $i < $jm; ++$i) {
        $j_day_no += $j_days_in_month[$i];
    }
    $j_day_no += $jd;

    $g_day_no = $j_day_no + 79;

    $gy = 1600 + 400 * floor($g_day_no / 146097);
    $g_day_no = $g_day_no % 146097;

    $leap = true;
    if ($g_day_no >= 36525) {
        $g_day_no--;
        $gy += 100 * floor($g_day_no / 36524);
        $g_day_no = $g_day_no % 36524;

        if ($g_day_no >= 365) {
            $g_day_no++;
        } else {
            $leap = false;
        }
    }

    $gy += 4 * floor($g_day_no / 1461);
    $g_day_no %= 1461;

    if ($g_day_no >= 366) {
        $leap = false;
        $g_day_no--;
        $gy += floor($g_day_no / 365);
        $g_day_no = $g_day_no % 365;
    }

    $g_days_in_month = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    for ($gm = 0; $g_day_no >= ($g_days_in_month[$gm] + ($gm == 1 && $leap)); ++$gm) {
        $g_day_no -= $g_days_in_month[$gm] + ($gm == 1 && $leap);
    }
    $gm++;
    $gd = $g_day_no + 1;

    return [$gy, $gm, $gd];
}

// دریافت کاربران یکتا با آخرین نام (فقط کاربران با message_type غیرخالی)
$stmt = $db->query("
    SELECT user_id, MAX(first_name) as first_name, MAX(last_name) as last_name
    FROM messages
    WHERE message_type IS NOT NULL AND message_type != ''
    GROUP BY user_id
    ORDER BY first_name, last_name
");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// دیباگ: بررسی تعداد کاربران
$debugUsersCount = count($users);

// دریافت انواع پیام‌های یکتا
$stmt = $db->query("SELECT DISTINCT message_type FROM messages WHERE message_type IS NOT NULL AND message_type != '' ORDER BY message_type");
$messageTypes = $stmt->fetchAll(PDO::FETCH_COLUMN);

// دریافت پارامترهای ورودی
$startDate = $_POST['start_date'] ?? ''; // انتظار فرمت شمسی: 1404-01-01
$endDate = $_POST['end_date'] ?? '';
$userId = $_POST['user_id'] ?? '';

// آماده‌سازی کوئری
$conditions = [];
$params = [];

// تبدیل تاریخ شمسی به میلادی
if ($startDate && $endDate) {
    if (preg_match("/^\d{4}-\d{2}-\d{2}$/", $startDate) && preg_match("/^\d{4}-\d{2}-\d{2}$/", $endDate)) {
        list($jy, $jm, $jd) = explode('-', $startDate);
        list($gy, $gm, $gd) = jalaliToGregorian($jy, $jm, $jd);
        $startGregorian = sprintf("%04d-%02d-%02d", $gy, $gm, $gd);

        list($jy, $jm, $jd) = explode('-', $endDate);
        list($gy, $gm, $gd) = jalaliToGregorian($jy, $jm, $jd);
        $endGregorian = sprintf("%04d-%02d-%02d", $gy, $gm, $gd);

        $conditions[] = "message_date BETWEEN :start_date AND :end_date";
        $params[':start_date'] = $startGregorian . ' 00:00:00';
        $params[':end_date'] = $endGregorian . ' 23:59:59';
    } else {
        $error = "فرمت تاریخ نامعتبر است. از فرمت YYYY-MM-DD (مثل 1404-01-01) استفاده کنید.";
    }
}

if ($userId) {
    $conditions[] = "user_id = :user_id";
    $params[':user_id'] = $userId;
}

// ساخت کوئری پویا برای Pivot Table
$sql = "SELECT 
    m.user_id,
    MAX(m.first_name) as first_name,
    MAX(m.last_name) as last_name";
foreach ($messageTypes as $type) {
    $safeType = preg_replace("/[^a-zA-Z0-9_-]/", "", $type); // جلوگیری از تزریق
    $sql .= ", SUM(CASE WHEN message_type = '$type' THEN 1 ELSE 0 END) as `$safeType`";
}
$sql .= " FROM messages m";
if (!empty($conditions)) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}
$sql .= " GROUP BY m.user_id";

// اجرای کوئری
$results = [];
try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "خطا در اجرای کوئری: " . $e->getMessage();
}

// محاسبه جمع ستون‌ها
$columnTotals = [];
foreach ($messageTypes as $type) {
    $safeType = preg_replace("/[^a-zA-Z0-9_-]/", "", $type);
    $columnTotals[$safeType] = 0;
    foreach ($results as $row) {
        $columnTotals[$safeType] += $row[$safeType];
    }
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <title>آمار پیام‌های گروه</title>
    <style>
    body {
        font-family: Arial, sans-serif;
        margin: 20px;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
    }

    th,
    td {
        border: 1px solid #ddd;
        padding: 8px;
        text-align: center;
    }

    th {
        background-color: #f2f2f2;
    }

    .form-container {
        margin-bottom: 20px;
    }

    .error {
        color: red;
    }

    .debug {
        color: blue;
        margin-bottom: 10px;
    }

    select,
    input[type="text"],
    input[type="submit"] {
        padding: 5px;
        margin: 5px;
    }

    .total-row,
    .total-column {
        font-weight: bold;
        background-color: #e8e8e8;
    }
    </style>
</head>

<body>
    <h2>آمار پیام‌های گروه</h2>

    <!-- دیباگ: نمایش تعداد کاربران -->
    <p class="debug">تعداد کاربران یافت‌شده: <?php echo $debugUsersCount; ?></p>

    <!-- فرم ورودی -->
    <div class="form-container">
        <form method="POST">
            <label>از تاریخ (شمسی، فرمت: 1404-01-01):</label>
            <input type="text" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>"
                placeholder="1404-01-01">

            <label>تا تاریخ (شمسی، فرمت: 1404-01-01):</label>
            <input type="text" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>"
                placeholder="1404-01-01">

            <label>کاربر:</label>
            <select name="user_id">
                <option value="">همه کاربران</option>
                <?php foreach ($users as $user): ?>
                <option value="<?php echo htmlspecialchars($user['user_id']); ?>"
                    <?php echo ($userId == $user['user_id']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                </option>
                <?php endforeach; ?>
            </select>

            <input type="submit" value="نمایش آمار">
        </form>
    </div>

    <!-- نمایش خطا -->
    <?php if (isset($error)): ?>
    <p class="error"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>

    <!-- جدول نتایج -->
    <?php if (!empty($results)): ?>
    <table>
        <tr>
            <th>شناسه کاربر</th>
            <th>نام</th>
            <th>نام خانوادگی</th>
            <?php foreach ($messageTypes as $type): ?>
            <th><?php echo htmlspecialchars($type); ?></th>
            <?php endforeach; ?>
            <th>جمع کل</th>
        </tr>
        <?php foreach ($results as $row): ?>
        <tr>
            <td><?php echo htmlspecialchars($row['user_id']); ?></td>
            <td><?php echo htmlspecialchars($row['first_name']); ?></td>
            <td><?php echo htmlspecialchars($row['last_name']); ?></td>
            <?php
                    $rowTotal = 0;
                    foreach ($messageTypes as $type):
                        $safeType = preg_replace("/[^a-zA-Z0-9_-]/", "", $type);
                    ?>
            <td><?php echo $row[$safeType]; ?></td>
            <?php $rowTotal += $row[$safeType]; ?>
            <?php endforeach; ?>
            <td class="total-column"><?php echo $rowTotal; ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="total-row">
            <td colspan="3">جمع کل</td>
            <?php foreach ($messageTypes as $type):
                    $safeType = preg_replace("/[^a-zA-Z0-9_-]/", "", $type);
                ?>
            <td><?php echo $columnTotals[$safeType]; ?></td>
            <?php endforeach; ?>
            <td class="total-column"><?php echo array_sum($columnTotals); ?></td>
        </tr>
    </table>
    <?php else: ?>
    <p>هیچ اطلاعاتی برای نمایش وجود ندارد.</p>
    <?php endif; ?>
</body>

</html>