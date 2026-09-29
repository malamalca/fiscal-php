# FiscalPHP

PHP client for Slovenian FURS fiscal verification of invoices and business premises.

## Requirements and dependencies

PHP 8.4 or newer with cURL, DOM and OpenSSL. QR PNG generation also needs GD.
Run `composer install` (or `composer update` to resolve the latest allowed releases).

The library now uses the original `robrichards/xmlseclibs` **4.0.0** (`^4.0`)
from https://github.com/robrichards/xmlseclibs, replacing the `malamalca` fork.
xmlseclibs requires PHP 8.0+, and the current Endroid release raises the project minimum to PHP 8.4.
Composer also installs upstream's phpseclib dependencies.

QR generation uses `endroid/qr-code` 6.1.3 (`^6.1`). The old bundled PHPQRCode implementation has been removed. A small compatibility adapter preserves `PHPQRCode\QRcode::png()` and `text()` for existing callers; those generic methods do not enforce fiscal print requirements. Use `FiscalQr` below for FURS receipts.

## Signing compatibility

Sources:

- [eDavki developer documentation](https://edavki.durs.si/edavkiportal/openportal/commonpages/opdynp/PageB.aspx?category=razvijalci)
- [Fiscal verification technical specifications](https://edavki.durs.si/edavkiportal/openportal/CommonPages/Opdynp/PageD.aspx?category=dpr_teh_spec)
- [FURS specification v3.2, section 5](https://www.datoteke.fu.gov.si/dpr/files/TehnicnaDokumentacijaVer3.2.pdf)

This client implements the fiscal cash-register service, which has a different
SOAP endpoint and message format from the general eDavki document service.
Signatures retain inclusive canonicalization, RSA-SHA256, SHA256 reference
digests, the enveloped-signature transform and the request's existing `Id`.
They contain X509 subject, issuer and decimal serial number. The embedded
certificate is removed after upstream generates KeyInfo, preserving the old
fork's output policy; FURS does not require it in requests.

## Offline checks

Run `composer test`. Tests generate a temporary RSA certificate and PKCS#12
bundle, sign invoice and business-premise fixtures with default and custom IDs,
verify signatures independently with OpenSSL and with upstream xmlseclibs,
check reference digests and certificate metadata, reject tampering and an
incorrect password, and check ZOI and a PKCS#12 bundle without extra certificates.
Temporary credentials are deleted afterward. No requests are sent to FURS.

On Windows, OpenSSL may need `OPENSSL_CONF` set to your installation's
`openssl.cnf` to generate test certificates.

The historical `.phpt` examples use an old PKCS#12 fixture whose encryption is
unsupported by the default OpenSSL 3 providers. They are not part of this
offline test command. Live FURS acceptance was verified on 2026-09-29 using a current external test certificate; see the live-test section below.

## Reliability fixes

- SOAP responses are parsed as XML with namespace checks. Malformed responses,
  SOAP faults and FURS errors are not treated as successful premise registration.
  Echo input is escaped; response text correctly decodes XML entities.
- HTTP failures and empty responses raise exceptions. TLS verification remains
  enabled and TLS 1.2 is the minimum (TLS 1.3 may still be negotiated).
- CA certificates may be DER, PEM, or a PEM bundle; PEM is no longer encoded a
  second time. Temporary TLS files are removed on success and failure.
- ZOI signs directly in memory. Public PEM conversion helpers return temporary
  files owned by the caller, who must delete them after use.
- Signing rejects malformed XML, DTDs, duplicate IDs, ambiguous targets and
  already-signed requests. A missing signing element still returns false.
- The legacy `fiscal.php` entry point can also be loaded after Composer autoload.

`composer test` includes transport simulations for failure handling and cleanup;
these do not establish live FURS acceptance or verify FURS response signatures.
The bundled historical client certificate expired on 2020-08-25. A live run with a valid external certificate and current CA bundle succeeded on 2026-09-29.

## FURS QR generation with Endroid

```php
use Malamalca\FiscalPHP\FiscalQr;

$issuedAt = new DateTimeImmutable('2015-08-15 10:13:32', new DateTimeZone('Europe/Ljubljana'));
$png = FiscalQr::png('a7e5f55e1dbb48b799268e1a6d8618a3', '12345678', $issuedAt, 300);
file_put_contents('fiscal-qr.png', $png);
```

The date must be the invoice's local issue time. For an already assembled,
validated 60-digit record, use `FiscalQr::create($payload, $printerDpi)` and
`saveToFile($path)` on the result.

Chapter 11 of the [FURS v3.2 specification](https://www.datoteke.fu.gov.si/dpr/files/TehnicnaDokumentacijaVer3.2.pdf)
is implemented and checked as follows:

| Requirement | Implementation / verification |
| --- | --- |
| 60 numeric digits | 39-digit decimal ZOI with leading zeroes, 8-digit tax number, 12-digit YYMMDDHHMMSS timestamp, digit sum modulo 10 |
| Exact ZOI conversion | BigInteger conversion, no floating-point rounding; both official examples pass |
| 25 x 25 modules | Numeric encoding, version 2, enforced matrix size |
| Error correction M | Explicit Medium setting; independently read back from PNG format bits |
| At least 4 x 4 printer dots per module | Integer module size calculated for the supplied DPI |
| Symbol at least 12 x 12 mm | Size calculated from DPI, rounded upward |
| White border at least 4 modules and 2 mm | Both limits applied independently |
| No picture or logo | Plain opaque black/white image, every pixel checked against the matrix |
| Readable payload | Independently decoded with ZXing for both FURS examples at 203, 300 and 600 DPI |

At 300 DPI the output is 198 x 198 pixels: a 150-pixel (12.7 mm) symbol
plus a 24-pixel (2.032 mm) border on each side. The complete image is
16.764 x 16.764 mm. PNG contains the requested DPI metadata.

Print at the stated DPI and preserve the entire white border, without smoothing
or downscaling. Place the code below the textual hexadecimal ZOI on the receipt.
Some print software ignores PNG DPI metadata, so explicitly set the physical
image size there. ISO/IEC 15415 print quality, actual dimensions and placement
must be verified on the final receipt; automated image tests cannot certify the
printer, paper, contrast or layout. The existing `tests/qr.php` is a user-provided
UPN payment example and is not a FURS fiscal QR example.

`php tests/qr-endroid.php` checks these software requirements with E_ALL enabled.
The decoder is a development-only dependency. `composer test` runs this together
with all fiscal signing and transport regression checks.

## Live FURS test

On 2026-09-29 the test endpoint accepted Echo, a new test business premise and
a signed invoice for test tax number 10039953. Both signed responses were verified
using the public FURS test signing certificate, with matching request MessageIDs.
The invoice QR was generated and independently decoded.

- Test premise: `FP260929123035CC4D`
- Test invoice: `FP260929123035CC4D-TEST1-1`
- EOR: `99994840-5f3d-4371-845e-fae989652a27`

To repeat, set `FISCAL_TEST_P12` to an external FURS test certificate path and
`FISCAL_TEST_PASSWORD` in the process environment, then run `php tests/live.php --send`.
This command creates a new premise and invoice in the **test** environment on
each run. It is excluded from `composer test`. Reports, request/response XML and
QR images are saved under ignored `tests/live-output/`. No password or client
private key is saved there. The fixture currently expects tax number 10039953.
Public CA and FURS signing certificates are under `tests/certificates/` and must
be refreshed when their issuers rotate them. Live testing requires development
dependencies for the independent QR decoder.
