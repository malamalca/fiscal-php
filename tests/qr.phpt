--TEST--
Create FURS QR with Endroid
--FILE--
<?php
require __DIR__ . '/../vendor/autoload.php';
use Malamalca\FiscalPHP\FiscalQr;
$file = tempnam(sys_get_temp_dir(), 'fiscal-qr-');
try {
    $result = FiscalQr::create('223175087923687075112234402528973166755123456781508151013321', 300);
    $result->saveToFile($file);
    $info = getimagesize($file);
    if ($info[0] === 198 && $info[1] === 198 && $info['mime'] === 'image/png') {
        echo 'OK: QR successful';
    }
} finally {
    unlink($file);
}
?>
--EXPECT--
OK: QR successful
