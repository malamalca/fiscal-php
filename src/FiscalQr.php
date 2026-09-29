<?php
namespace Malamalca\FiscalPHP;

use DateTimeImmutable;
use DateTimeInterface;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\Result\PngResult;
use InvalidArgumentException;
use phpseclib3\Math\BigInteger;
use RuntimeException;

/** FURS technical specification v3.2, chapter 11. */
class FiscalQr
{
    public static function payload(string $zoi, string $taxNumber, DateTimeInterface $issuedAt): string
    {
        if (!preg_match('/^[a-fA-F0-9]{32}$/D', $zoi) || !preg_match('/^[0-9]{8}$/D', $taxNumber)) {
            throw new InvalidArgumentException('Expected a 32-digit hexadecimal ZOI and an 8-digit tax number.');
        }
        $decimal = str_pad((new BigInteger($zoi, 16))->toString(), 39, '0', STR_PAD_LEFT);
        $body = $decimal . $taxNumber . $issuedAt->format('ymdHis');
        return $body . (array_sum(str_split($body)) % 10);
    }

    public static function create(string $payload, int $dpi = 300): PngResult
    {
        self::validatePayload($payload);
        if ($dpi < 72 || $dpi > 2400) {
            throw new InvalidArgumentException('Printer resolution must be between 72 and 2400 DPI.');
        }
        $module = max(4, (int) ceil(12 * $dpi / (25 * 25.4)));
        $margin = max(4 * $module, (int) ceil(2 * $dpi / 25.4));
        $qr = new QrCode(
            data: $payload,
            encoding: new Encoding('ISO-8859-1'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 25 * $module,
            margin: $margin,
            roundBlockSizeMode: RoundBlockSizeMode::None,
        );
        $result = (new PngWriter())->write($qr, options: [PngWriter::WRITER_OPTION_NUMBER_OF_COLORS => null]);
        if (!$result instanceof PngResult || $result->getMatrix()->getBlockCount() !== 25) {
            throw new RuntimeException('The fiscal QR symbol must have exactly 25 by 25 modules.');
        }
        imageresolution($result->getImage(), $dpi, $dpi);
        return $result;
    }

    public static function png(string $zoi, string $taxNumber, DateTimeInterface $issuedAt, int $dpi = 300): string
    {
        return self::create(self::payload($zoi, $taxNumber, $issuedAt), $dpi)->getString();
    }

    private static function validatePayload(string $payload): void
    {
        if (!preg_match('/^[0-9]{60}$/D', $payload)) {
            throw new InvalidArgumentException('Fiscal QR payload must contain exactly 60 digits.');
        }
        if ((string) (array_sum(str_split(substr($payload, 0, 59))) % 10) !== $payload[59]) {
            throw new InvalidArgumentException('Invalid fiscal QR checksum.');
        }
        if ((new BigInteger(substr($payload, 0, 39)))->compare(new BigInteger(str_repeat('f', 32), 16)) > 0) {
            throw new InvalidArgumentException('ZOI exceeds 128 bits.');
        }
        $date = substr($payload, 47, 12);
        $parsed = DateTimeImmutable::createFromFormat('!ymdHis', $date);
        if ($parsed === false || $parsed->format('ymdHis') !== $date) {
            throw new InvalidArgumentException('Invalid invoice date/time.');
        }
    }
}
