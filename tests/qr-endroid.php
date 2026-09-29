<?php
require __DIR__ . '/../vendor/autoload.php';
use Malamalca\FiscalPHP\FiscalQr;
use Zxing\QrReader;
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
function qrCheck($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
$date = new DateTimeImmutable('2015-08-15 10:13:32', new DateTimeZone('Europe/Ljubljana'));
$examples = [
    'a7e5f55e1dbb48b799268e1a6d8618a3' => '223175087923687075112234402528973166755123456781508151013321',
    '3024e56bf1ddd2e7eeb5715c6859a913' => '063994519708649896901260100447252359443123456781508151013320',
];
foreach ($examples as $zoi => $expected) {
    qrCheck(FiscalQr::payload($zoi, '12345678', $date) === $expected, 'FURS example payload, padding and checksum');
    foreach ([203, 300, 600] as $dpi) {
        $result = FiscalQr::create($expected, $dpi);
        $matrix = $result->getMatrix();
        qrCheck($matrix->getBlockCount() === 25, 'Version 2: 25 x 25 modules');
        $module = (int) $matrix->getBlockSize();
        $margin = $matrix->getMarginLeft();
        qrCheck($module >= 4 && $module === (int) $matrix->getBlockSize(), 'At least 4 dots per module');
        qrCheck($matrix->getInnerSize() * 25.4 / $dpi >= 12, 'At least 12 mm symbol');
        qrCheck($margin >= 4 * $module && $margin * 25.4 / $dpi >= 2, '4 modules and 2 mm quiet zone');
        $png = $result->getString();
        $image = imagecreatefromstring($png);
        qrCheck(imageresolution($image) === [$dpi, $dpi], 'PNG printer resolution');
        qrCheck(imagesx($image) === $matrix->getOuterSize() && imagesy($image) === $matrix->getOuterSize(), 'Square PNG dimensions');
        for ($y = 0; $y < imagesy($image); $y++) {
            for ($x = 0; $x < imagesx($image); $x++) {
                $inside = $x >= $margin && $y >= $margin && $x < $margin + 25 * $module && $y < $margin + 25 * $module;
                $black = $inside && $matrix->getBlockValue(intdiv($y - $margin, $module), intdiv($x - $margin, $module)) === 1;
                $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                qrCheck($color['red'] === ($black ? 0 : 255) && $color['green'] === $color['red'] && $color['blue'] === $color['red'] && $color['alpha'] === 0, 'Exact opaque modules, white quiet zone, no logo');
            }
        }
        $reader = new QrReader($png, QrReader::SOURCE_TYPE_BLOB, false);
        qrCheck($reader->text() === $expected, 'Independent PNG decoding');
        // Read format bits from the rendered image independently of Endroid.
        $bits = new \Zxing\Common\BitMatrix(25);
        for ($y = 0; $y < 25; $y++) {
            for ($x = 0; $x < 25; $x++) {
                $color = imagecolorsforindex($image, imagecolorat($image, $margin + $x * $module, $margin + $y * $module));
                if ($color['red'] === 0) { $bits->set($x, $y); }
            }
        }
        $format = (new \Zxing\Qrcode\Decoder\BitMatrixParser($bits))->readFormatInformation();
        qrCheck($format->getErrorCorrectionLevel()->getBits() === 0, 'Independent correction level M');
        echo "PASS: FURS example at $dpi DPI, payload, geometry, PNG pixels, decoding and M level\n";
    }
}
foreach (['', str_repeat('1', 60), substr($expected, 0, 59), substr($expected, 0, 59) . '9'] as $invalid) {
    $rejected = false;
    try { FiscalQr::create($invalid); } catch (InvalidArgumentException $e) { $rejected = true; }
    qrCheck($rejected, 'Reject invalid data');
}
$zero = FiscalQr::payload(str_repeat('0', 32), '12345678', $date);
qrCheck(substr($zero, 0, 39) === str_repeat('0', 39), 'Zero padding');
$max = FiscalQr::payload(str_repeat('f', 32), '12345678', $date);
qrCheck(substr($max, 0, 39) === '340282366920938463463374607431768211455', 'Exact 128-bit conversion');
$file = tempnam(sys_get_temp_dir(), 'qr-endroid-test-');
try {
    PHPQRCode\QRcode::png('Compatibility test', $file, 'M', 4, 4);
    qrCheck((new QrReader($file))->text() === 'Compatibility test', 'Legacy PNG API uses Endroid');
} finally { unlink($file); }
echo "PASS: invalid payloads, 128-bit limits and legacy PNG API\n";
