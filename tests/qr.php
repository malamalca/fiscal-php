<?php
require(dirname(__FILE__) . '/../vendor/autoload.php');
use PHPQRCode\QRcode;

$qrFile = dirname(__FILE__) . '/qr.png';
unlink($qrFile);

$qrString = 'UPNQR\n\n\n\n\nJanez Novak\nDunajska ulica 1\n1000 Ljubljana\n00000008105\n\n\nRENT\nPlacilo najemnine za marec 2017\n01.04.2017\nSI56020170014356205\nSI121234567890120\nRentaCar d.o.o.\nPohorska ulica 22\n2000 Maribor\n201\n';
//$qrString = strstr($qrString, '\n', chr(10));
QRcode::png($qrString, $qrFile, 'M', 4, 2);

if (file_exists($qrFile)) {
	print("OK: QR successful");
}
