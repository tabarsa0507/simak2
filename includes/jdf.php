<?php
/**
 * تبدیل تاریخ میلادی به شمسی - الگوریتم دقیق و استاندارد
 */
function jdate($format, $timestamp = null) {
    date_default_timezone_set('Asia/Tehran');
    if ($timestamp === null) {
        $timestamp = time();
    }

    $g_y = (int)date('Y', $timestamp);
    $g_m = (int)date('m', $timestamp);
    $g_d = (int)date('d', $timestamp);
    $h = (int)date('H', $timestamp);
    $i = (int)date('i', $timestamp);
    $s = (int)date('s', $timestamp);

    $g_days_in_month = array(31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31);
    $j_days_in_month = array(31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29);

    // محاسبه تعداد روز از ابتدای سال میلادی
    $g_day_no = 365 * ($g_y - 1) + floor(($g_y - 1) / 4) - floor(($g_y - 1) / 100) + floor(($g_y - 1) / 400);
    for ($m = 0; $m < $g_m - 1; $m++) {
        $g_day_no += $g_days_in_month[$m];
    }
    if ($g_m > 2 && (($g_y % 4 == 0 && $g_y % 100 != 0) || $g_y % 400 == 0)) {
        $g_day_no++;
    }
    $g_day_no += $g_d;

    // محاسبه تاریخ شمسی با الگوریتم دقیق (تصحیح شده)
    // عدد 226899 برای 1 فروردین 1 شمسی (21 مارس 622 میلادی) است
    $j_day_no = $g_day_no - 226899;
    $j_y = 1 + floor(($j_day_no * 33 + 3) / 12053);
    $j_day_no -= floor(($j_y - 1) * 12053 / 33);

    for ($m = 0; $m < 12; $m++) {
        if ($j_day_no <= $j_days_in_month[$m]) {
            $j_m = $m + 1;
            $j_d = $j_day_no;
            break;
        }
        $j_day_no -= $j_days_in_month[$m];
    }

    // فرمت خروجی
    $result = '';
    $format_len = strlen($format);
    for ($idx = 0; $idx < $format_len; $idx++) {
        $char = $format[$idx];
        switch ($char) {
            case 'Y': $result .= str_pad($j_y, 4, '0', STR_PAD_LEFT); break;
            case 'y': $result .= substr($j_y, -2); break;
            case 'm': $result .= str_pad($j_m, 2, '0', STR_PAD_LEFT); break;
            case 'd': $result .= str_pad($j_d, 2, '0', STR_PAD_LEFT); break;
            case 'H': $result .= str_pad($h, 2, '0', STR_PAD_LEFT); break;
            case 'i': $result .= str_pad($i, 2, '0', STR_PAD_LEFT); break;
            case 's': $result .= str_pad($s, 2, '0', STR_PAD_LEFT); break;
            default: $result .= $char;
        }
    }
    return $result;
}