<?php
namespace PHPQRCode;

use Endroid\QrCode\Bacon\MatrixFactory;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode as EndroidQrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use InvalidArgumentException;

/** Compatibility for existing PHPQRCode calls; fiscal printing uses FiscalQr. */
class QRcode
{
    private static function create($text, $level, $size, $margin): EndroidQrCode
    {
        $levels = [0 => ErrorCorrectionLevel::Low, 1 => ErrorCorrectionLevel::Medium, 2 => ErrorCorrectionLevel::Quartile, 3 => ErrorCorrectionLevel::High,
            'L' => ErrorCorrectionLevel::Low, 'M' => ErrorCorrectionLevel::Medium, 'Q' => ErrorCorrectionLevel::Quartile, 'H' => ErrorCorrectionLevel::High];
        if (!isset($levels[$level]) || $size < 1 || $margin < 0) {
            throw new InvalidArgumentException('Invalid QR level, module size or margin.');
        }
        $encoding = new Encoding('UTF-8');
        $draft = new EndroidQrCode(data: (string) $text, encoding: $encoding, errorCorrectionLevel: $levels[$level]);
        $count = (new MatrixFactory())->create($draft)->getBlockCount();
        return new EndroidQrCode(data: (string) $text, encoding: $encoding, errorCorrectionLevel: $levels[$level],
            size: $count * (int) $size, margin: (int) $margin * (int) $size, roundBlockSizeMode: RoundBlockSizeMode::None);
    }

    public static function png($text, $outfile = false, $level = 0, $size = 3, $margin = 4, $saveandprint = false)
    {
        $result = (new PngWriter())->write(self::create($text, $level, $size, $margin));
        if ($outfile !== false) {
            $result->saveToFile($outfile);
        }
        if ($outfile === false || $saveandprint) {
            header('Content-Type: image/png');
            echo $result->getString();
        }
    }

    public static function text($text, $outfile = false, $level = 0, $size = 3, $margin = 4)
    {
        $matrix = (new MatrixFactory())->create(self::create($text, $level, $size, $margin));
        $rows = [];
        for ($y = 0; $y < $matrix->getBlockCount(); $y++) {
            $row = '';
            for ($x = 0; $x < $matrix->getBlockCount(); $x++) {
                $row .= $matrix->getBlockValue($y, $x);
            }
            $rows[] = $row;
        }
        if ($outfile !== false) {
            file_put_contents($outfile, implode("\n", $rows));
        }
        return $rows;
    }
}
