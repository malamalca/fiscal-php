<?php
require __DIR__ . '/../vendor/autoload.php';

use Malamalca\FiscalPHP\FiscalSign;
use Malamalca\FiscalPHP\FiscalUtils;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

function check($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// Fail on warnings/deprecations as well as incorrect results.
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Fresh credentials keep offline tests independent of expired FURS fixtures
// and legacy PKCS#12 encryption disabled by OpenSSL 3.
$config = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
$key = openssl_pkey_new($config);
check($key !== false, 'Generate private key');
$csr = openssl_csr_new(['countryName' => 'SI', 'organizationName' => 'FiscalPHP test', 'commonName' => 'Offline signing test'], $key, $config);
$cert = openssl_csr_sign($csr, null, $key, 1, $config, 123456789);
check($cert !== false, 'Generate certificate');
check(openssl_x509_export($cert, $certPem), 'Export certificate');
$p12 = tempnam(sys_get_temp_dir(), 'fiscal-test-');
try {
    check(openssl_pkcs12_export_to_file($cert, $p12, $key, 'test-password'), 'Export PKCS#12');
    $signer = (new FiscalSign())->setP12($p12)->setPassword('test-password');
    foreach (['invoice' => 'InvoiceRequest', 'premise' => 'BusinessPremiseRequest'] as $fixture => $element) {
        foreach (['data', 'custom-request-id'] as $id) {
            $input = str_replace('Id="data"', 'Id="' . $id . '"', file_get_contents(__DIR__ . '/' . $fixture . '.xml'));
            $signed = $signer->sign($input, 'fu:' . $element);
            check(is_string($signed), 'Sign ' . $fixture);
            $doc = new DOMDocument();
            check($doc->loadXML($signed), 'Parse signed document');
            $xpath = new DOMXPath($doc);
            $xpath->registerNamespace('ds', XMLSecurityDSig::XMLDSIGNS);
            $xpath->registerNamespace('fu', 'http://www.fu.gov.si/');
            check($xpath->evaluate('count(//fu:' . $element . '/ds:Signature)') === 1.0, 'Enveloped signature');
            check($xpath->evaluate('string(//ds:Reference/@URI)') === '#' . $id, 'Preserve request Id');
            check($xpath->evaluate('string(//ds:CanonicalizationMethod/@Algorithm)') === XMLSecurityDSig::C14N, 'Canonicalization');
            check($xpath->evaluate('string(//ds:SignatureMethod/@Algorithm)') === XMLSecurityKey::RSA_SHA256, 'RSA SHA-256');
            check($xpath->evaluate('string(//ds:DigestMethod/@Algorithm)') === XMLSecurityDSig::SHA256, 'SHA-256 digest');
            check($xpath->evaluate('string(//ds:Transform/@Algorithm)') === XMLSecurityDSig::XMLDSIGNS . 'enveloped-signature', 'Enveloped transform');
            check($xpath->evaluate('count(//ds:X509Certificate)') === 0.0, 'Omit embedded certificate');
            foreach (['X509SubjectName', 'X509IssuerName'] as $field) {
                check($xpath->evaluate('string(//ds:' . $field . ')') !== '', 'Required ' . $field);
            }
            check($xpath->evaluate('string(//ds:X509SerialNumber)') === '123456789', 'Decimal certificate serial');

            // Verify independently with OpenSSL, including the reference digest.
            $info = $xpath->query('//ds:SignedInfo')->item(0);
            $signature = base64_decode($xpath->evaluate('string(//ds:SignatureValue)'), true);
            check(openssl_verify($info->C14N(), $signature, $certPem, OPENSSL_ALGO_SHA256) === 1, 'OpenSSL signature verification');
            $digest = $xpath->evaluate('string(//ds:DigestValue)');
            $request = $xpath->query('//fu:' . $element)->item(0);
            $signatureNode = $xpath->query('./ds:Signature', $request)->item(0);
            $request->removeChild($signatureNode);
            check(base64_encode(hash('sha256', $request->C14N(), true)) === $digest, 'Reference digest');

            $publicKey = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'public']);
            $publicKey->loadKey($certPem, false, true);
            $doc->loadXML($signed);
            $verified = (new XMLSecurityDSig())->verifyDocument($publicKey, $doc);
            check(!empty($verified), 'Upstream verification');
            $doc->loadXML($signed);
            $doc->getElementsByTagNameNS('http://www.fu.gov.si/', 'MessageID')->item(0)->nodeValue = 'tampered';
            $rejected = false;
            try {
                $rejected = !(new XMLSecurityDSig())->verifyDocument($publicKey, $doc);
            } catch (Exception $e) {
                $rejected = true;
            }
            check($rejected, 'Reject tampered request');
            echo "PASS: $fixture ($id), metadata, OpenSSL and upstream verification, tamper detection\n";
        }
    }
    check(openssl_sign('test', $signature, $key, OPENSSL_ALGO_SHA256), 'Reference ZOI signature');
    check($signer->zoi('test') === md5($signature), 'ZOI unchanged');
    echo "PASS: ZOI\n";
    $pem = FiscalUtils::p12ToPem($p12, 'test-password');
    check(is_string($pem), 'PKCS#12 without extra certificates');
    unlink($pem);
    echo "PASS: PKCS#12 without certificate chain\n";
    $failed = false;
    try {
        $signer->setPassword('wrong')->sign($input, 'fu:BusinessPremiseRequest');
    } catch (Exception $e) {
        $failed = true;
    }
    check($failed, 'Reject invalid PKCS#12 password');
    echo "PASS: invalid password rejected\n";
    require __DIR__ . '/regressions.php';
} finally {
    unlink($p12);
}
