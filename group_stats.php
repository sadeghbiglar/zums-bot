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
$startDate = $_POST['start_date'] ?? '';
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
    $safeType = preg_replace("/[^a-zA-Z0-9_-]/", "", $type);
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>آمار پیام‌های گروه</title>
    <link href="src/css/tailwind.css" rel="stylesheet">
</head>

<body class="bg-blue-50 min-h-screen flex items-center justify-center p-8">
    <div class="w-full max-w-7xl bg-white rounded-3xl shadow-xl p-10">
        <h2 class="text-5xl font-bold text-blue-900 mb-12 text-center">آمار پیام‌های گروه</h2>

        <!-- دیباگ: نمایش تعداد کاربران -->
        <p class="text-blue-600 text-base mb-8 font-semibold">تعداد کاربران یافت‌شده: <?php echo $debugUsersCount; ?>
        </p>

        <!-- فرم ورودی -->
        <form method="POST" class="mb-12 space-y-8">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <div>
                    <label class="block text-gray-800 text-sm font-bold mb-3">از تاریخ (شمسی، فرمت: 1404-01-01):</label>
                    <input type="text" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>"
                        placeholder="1404-01-01"
                        class="w-full px-5 py-4 border border-gray-300 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-300 shadow-sm hover:shadow-md">
                </div>
                <div>
                    <label class="block text-gray-800 text-sm font-bold mb-3">تا تاریخ (شمسی، فرمت: 1404-01-01):</label>
                    <input type="text" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>"
                        placeholder="1404-01-01"
                        class="w-full px-5 py-4 border border-gray-300 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-300 shadow-sm hover:shadow-md">
                </div>
                <div>
                    <label class="block text-gray-800 text-sm font-bold mb-3">کاربر:</label>
                    <select name="user_id"
                        class="w-full px-5 py-4 border border-gray-300 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-300 shadow-sm hover:shadow-md">
                        <option value="">همه کاربران</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo htmlspecialchars($user['user_id']); ?>"
                                <?php echo ($userId == $user['user_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button type="submit"
                class="w-full md:w-auto px-10 py-4 bg-blue-600 text-white rounded-2xl hover:bg-blue-700 transition duration-300 font-bold shadow-md hover:shadow-lg transform hover:-translate-y-1">نمایش
                آمار</button>
        </form>

        <!-- نمایش خطا -->
        <?php if (isset($error)): ?>
            <p class="text-red-600 text-base mb-8 font-semibold bg-red-50 p-4 rounded-xl">
                <?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>

        <!-- جدول نتایج -->
        <?php if (!empty($results)): ?>
            <div class="overflow-x-auto rounded-xl shadow-md">
                <table class="w-full table-auto border-collapse bg-white">
                    <thead>
                        <tr class="bg-blue-100">
                            <th class="border border-blue-100 px-8 py-5 text-blue-900 font-bold text-base">شناسه کاربر</th>
                            <th class="border border-blue-100 px-8 py-5 text-blue-900 font-bold text-base">نام</th>
                            <th class="border border-blue-100 px-8 py-5 text-blue-900 font-bold text-base">نام خانوادگی</th>
                            <?php foreach ($messageTypes as $type): ?>
                                <th class="border border-blue-100 px-8 py-5 text-blue-900 font-bold text-base">
                                    <?php echo htmlspecialchars($type); ?>
                                </th>
                            <?php endforeach; ?>
                            <th class="border border-blue-100 px-8 py-5 text-blue-900 font-bold text-base">جمع کل</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $row): ?>
                            <tr class="hover:bg-blue-50 transition duration-200">
                                <td class="border border-blue-100 px-8 py-5 text-gray-800">
                                    <?php echo htmlspecialchars($row['user_id']); ?></td>
                                <td class="border border-blue-100 px-8 py-5 text-gray-800">
                                    <?php echo htmlspecialchars($row['first_name']); ?></td>
                                <td class="border border-blue-100 px-8 py-5 text-gray-800">
                                    <?php echo htmlspecialchars($row['last_name']); ?></td>
                                <?php
                                $rowTotal = 0;
                                foreach ($messageTypes as $type):
                                    $safeType = preg_replace("/[^a-zA-Z0-9_-]/", "", $type);
                                ?>
                                    <td class="border border-blue-100 px-8 py-5 text-gray-800"><?php echo $row[$safeType]; ?></td>
                                    <?php $rowTotal += $row[$safeType]; ?>
                                <?php endforeach; ?>
                                <td class="border border-blue-100 px-8 py-5 text-gray-800 font-bold"><?php echo $rowTotal; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="bg-blue-100 font-bold">
                            <td class="border border-blue-100 px-8 py-5 text-blue-900" colspan="3">جمع کل</td>
                            <?php foreach ($messageTypes as $type):
                                $safeType = preg_replace("/[^a-zA-Z0-9_-]/", "", $type);
                            ?>
                                <td class="border border-blue-100 px-8 py-5 text-blue-900">
                                    <?php echo $columnTotals[$safeType]; ?></td>
                            <?php endforeach; ?>
                            <td class="border border-blue-100 px-8 py-5 text-blue-900">
                                <?php echo array_sum($columnTotals); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-gray-600 text-xl mt-8 text-center bg-gray-100 p-6 rounded-xl">هیچ اطلاعاتی برای نمایش وجود ندارد.
            </p>
        <?php endif; ?>
    </div>
</body>

</html>