<?php
// Explicit opt-in: php tests/live.php --send (test endpoint only).
require __DIR__ . '/../vendor/autoload.php';
use Malamalca\FiscalPHP\FiscalSign;
use Malamalca\FiscalPHP\FiscalSoap;
use Malamalca\FiscalPHP\FiscalUtils;
use Malamalca\FiscalPHP\FiscalQr;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

if (!in_array('--send', $argv, true)) { throw new RuntimeException('Use --send to submit to the FURS test environment.'); }
$p12 = getenv('FISCAL_TEST_P12');
$password = getenv('FISCAL_TEST_PASSWORD');
if (!$p12 || !$password) { throw new RuntimeException('Set FISCAL_TEST_P12 and FISCAL_TEST_PASSWORD.'); }
$raw = FiscalUtils::readP12($p12, $password);
if ($raw === false) { throw new RuntimeException('Cannot read test certificate.'); }
$certificate = openssl_x509_parse($raw['cert']);
if (!in_array('DavPotRacTEST', (array) ($certificate['subject']['OU'] ?? []), true)) { throw new RuntimeException('A FURS test certificate is required.'); }
if (time() < $certificate['validFrom_time_t'] || time() > $certificate['validTo_time_t']) { throw new RuntimeException('Test certificate is not currently valid.'); }
if (!in_array('10039953', (array) $certificate['subject']['OU'], true)) { throw new RuntimeException('This fixture expects tax number 10039953.'); }
$directory = __DIR__ . '/live-output/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
mkdir($directory, 0700, true);
$report = ['endpoint' => 'https://blagajne-test.fu.gov.si:9002/v1/cash_registers', 'certificateValidUntil' => gmdate('c', $certificate['validTo_time_t']), 'stages' => []];
function uuid(): string {
    $b = random_bytes(16); $b[6] = chr((ord($b[6]) & 15) | 64); $b[8] = chr((ord($b[8]) & 63) | 128);
    $h = bin2hex($b); return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
}
function setValue(DOMDocument $doc, string $name, string $value): void {
    foreach ($doc->getElementsByTagNameNS('http://www.fu.gov.si/', $name) as $node) { $node->nodeValue = $value; }
}
function verifyXml(string $xml, string $cert): void {
    $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'public']);
    $key->loadKey($cert, false, true);
    (new XMLSecurityDSig())->verifyDocument($key, FiscalUtils::parseXml($xml));
}
function verifyResponse(string $xml, string $requestId, FiscalSoap $soap, string $serverCert): DOMDocument {
    verifyXml($xml, $serverCert);
    $doc = FiscalUtils::parseXml($xml);
    if ($soap->hasError($xml)) {
        $errors = $doc->getElementsByTagNameNS('http://www.fu.gov.si/', 'Error');
        throw new RuntimeException('FURS error: ' . ($errors->item(0)?->textContent ?? 'Invalid response'));
    }
    if ($doc->getElementsByTagNameNS('http://www.fu.gov.si/', 'MessageID')->item(0)?->textContent !== $requestId) { throw new RuntimeException('Response MessageID mismatch'); }
    return $doc;
}
$ca = tempnam(sys_get_temp_dir(), 'fiscal-live-ca-');
try {
    $bundle = '';
    foreach (['si-trust-root.crt', 'sigov-ca2.crt'] as $name) {
        $pem = FiscalUtils::cerToPem(__DIR__ . '/certificates/' . $name);
        if ($pem === false) { throw new RuntimeException('Invalid CA certificate'); }
        try { $bundle .= file_get_contents($pem) . "\n"; } finally { unlink($pem); }
    }
    file_put_contents($ca, $bundle);
    $serverFile = FiscalUtils::cerToPem(__DIR__ . '/certificates/DavPotRacTEST.cer');
    if ($serverFile === false) { throw new RuntimeException('Invalid FURS signing certificate'); }
    try { $serverCert = file_get_contents($serverFile); } finally { unlink($serverFile); }
    $soap = (new FiscalSoap())->setP12($p12)->setPassword($password)->setCert($ca);
    $signer = (new FiscalSign())->setP12($p12)->setPassword($password);
    $echo = 'FiscalPHP live test ' . uuid();
    if ($soap->sendEcho($echo) !== $echo) { throw new RuntimeException('Echo mismatch'); }
    $report['stages'][] = 'Echo and verified TLS: PASS'; echo end($report['stages']), "\n";

    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Ljubljana'));
    $premise = 'FP' . $now->format('ymdHis') . strtoupper(bin2hex(random_bytes(2)));
    $report['premise'] = $premise;
    $doc = FiscalUtils::parseXml(file_get_contents(__DIR__ . '/premise.xml'));
    $messageId = uuid();
    setValue($doc, 'MessageID', $messageId); setValue($doc, 'DateTime', $now->format('Y-m-d\TH:i:s'));
    setValue($doc, 'BusinessPremiseID', $premise); setValue($doc, 'ValidityDate', $now->format('Y-m-d'));
    setValue($doc, 'SpecialNotes', 'FiscalPHP integration test; fictional test premises');
    $signed = $signer->sign($doc->saveXML(), 'fu:BusinessPremiseRequest');
    verifyXml($signed, $raw['cert']); file_put_contents($directory . '/premise-request.xml', $signed);
    $response = $soap->sendPremiseRaw($signed); file_put_contents($directory . '/premise-response.xml', $response);
    verifyResponse($response, $messageId, $soap, $serverCert);
    $report['stages'][] = 'Premise registration and FURS response signature: PASS'; echo end($report['stages']), "\n";

    $doc = FiscalUtils::parseXml(file_get_contents(__DIR__ . '/invoice.xml'));
    $messageId = uuid();
    setValue($doc, 'MessageID', $messageId); setValue($doc, 'DateTime', $now->format('Y-m-d\TH:i:s'));
    setValue($doc, 'IssueDateTime', $now->format('Y-m-d\TH:i:s')); setValue($doc, 'BusinessPremiseID', $premise);
    setValue($doc, 'ElectronicDeviceID', 'TEST1'); setValue($doc, 'InvoiceNumber', '1'); setValue($doc, 'PaymentAmount', '66.71');
    $zoi = $signer->zoi('10039953' . $now->format('d.m.Y H:i:s') . '1' . $premise . 'TEST1' . '66.71');
    if ($zoi === false) { throw new RuntimeException('ZOI failed'); }
    setValue($doc, 'ProtectedID', $zoi);
    $signed = $signer->sign($doc->saveXML(), 'fu:InvoiceRequest');
    verifyXml($signed, $raw['cert']); file_put_contents($directory . '/invoice-request.xml', $signed);
    $response = $soap->sendInvoiceRaw($signed); file_put_contents($directory . '/invoice-response.xml', $response);
    $responseDoc = verifyResponse($response, $messageId, $soap, $serverCert);
    $eor = $responseDoc->getElementsByTagNameNS('http://www.fu.gov.si/', 'UniqueInvoiceID')->item(0)?->textContent;
    if (!$eor) { throw new RuntimeException('Missing EOR'); }
    $report['eor'] = $eor; $report['zoi'] = $zoi;
    $report['stages'][] = 'Invoice accepted and FURS response signature: PASS'; echo end($report['stages']), "\nEOR: ", $eor, "\n";
    $png = FiscalQr::png($zoi, '10039953', $now);
    file_put_contents($directory . '/invoice-qr.png', $png);
    if ((new Zxing\QrReader($png, Zxing\QrReader::SOURCE_TYPE_BLOB, false))->text() !== FiscalQr::payload($zoi, '10039953', $now)) { throw new RuntimeException('QR decode mismatch'); }
    $report['stages'][] = 'Invoice QR generated and independently decoded: PASS'; echo end($report['stages']), "\n";
    $report['success'] = true;
} catch (Throwable $e) {
    $report['success'] = false; $report['error'] = $e->getMessage(); echo 'FAIL: ', $e->getMessage(), "\n";
} finally {
    unlink($ca);
    file_put_contents($directory . '/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    echo 'Report: ', $directory, "/report.json\n";
}
exit($report['success'] ? 0 : 1);
