--TEST--
Create QR
--FILE--
<?php
require(dirname(__FILE__) . '/../vendor/autoload.php');
use PHPQRCode\QRcode;

$qrFile = dirname(__FILE__) . '/qr.png';
unlink($qrFile);
QRcode::png('223175087923687075112234402528973166755123456781508151013321', $qrFile, 'M', 4, 2);

if (file_exists($qrFile)) {
	print("OK: QR successful");
}

?>

--EXPECT--
OK: QR successful