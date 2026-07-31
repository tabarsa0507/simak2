<?php
require_once 'config.php';
require_once 'functions.php';

echo "زمان فعلی (timestamp): " . time() . "<br>";
echo "تاریخ میلادی: " . date('Y-m-d H:i:s') . "<br>";
echo "تاریخ شمسی (nowShamsi): " . nowShamsi('Y/m/d H:i:s') . "<br>";