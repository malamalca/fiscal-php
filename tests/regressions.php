<?php
// Isolate transport outcomes while exercising the real request cleanup paths.
namespace Malamalca\FiscalPHP {
    function curl_init() { return new \stdClass(); }
    function curl_setopt_array($handle, $options) {
        $GLOBALS['transportOptions'] = $options;
        foreach ([CURLOPT_SSLCERT, CURLOPT_CAINFO] as $option) {
            \check(is_file($options[$option]), 'TLS file exists during request');
        }
        return true;
    }
    function curl_exec($handle) { return $GLOBALS['transportResponse']; }
    function curl_getinfo($handle, $option) { return $GLOBALS['transportStatus']; }
    function curl_error($handle) { return 'Simulated transport failure'; }
}
namespace {
    use Malamalca\FiscalPHP\FiscalSoap;
    use Malamalca\FiscalPHP\FiscalUtils;

    class SoapStub extends FiscalSoap {
        public $response;
        public $request;
        protected function doRequest($action, $xml) {
            $this->request = $xml;
            return $this->response;
        }
    }
    function envelope($body) {
        return '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" xmlns:f="http://www.fu.gov.si/"><s:Body>' . $body . '</s:Body></s:Envelope>';
    }
    function mustThrow($callback, $message) {
        $thrown = false;
        try { $callback(); } catch (\Exception $e) { $thrown = true; }
        check($thrown, $message);
    }

    $soap = new SoapStub();
    foreach (['not xml', '<html/>', envelope('<s:Fault/>'), envelope('<f:InvoiceResponse><f:Error code="x"/></f:InvoiceResponse>'), envelope('<f:InvoiceResponse><f:Error /></f:InvoiceResponse>')] as $invalid) {
        check($soap->hasError($invalid), 'Reject malformed/error response');
        $soap->response = $invalid;
        check($soap->sendPremise('<request/>') !== true, 'Do not falsely accept premise');
    }
    $soap->response = envelope('<f:BusinessPremiseResponse><f:Header/></f:BusinessPremiseResponse>');
    check($soap->sendPremise('<request/>') === true, 'Accept premise response');
    $soap->response = envelope('<f:InvoiceResponse><f:UniqueInvoiceID attr="x">abc&amp;def</f:UniqueInvoiceID></f:InvoiceResponse>');
    check($soap->sendInvoice('<request/>') === 'abc&def', 'Parse namespaces, attributes and entities');
    $soap->response = envelope('<f:EchoResponse>0</f:EchoResponse>');
    check($soap->sendEcho('A&B <test>') === '0', 'Preserve zero response');
    $echo = FiscalUtils::parseXml($soap->request);
    check($echo->getElementsByTagNameNS('http://www.fu.gov.si/', 'EchoRequest')->item(0)->textContent === 'A&B <test>', 'Escape Echo XML');
    echo "PASS: SOAP parsing, errors, faults and Echo escaping\n";

    $signer->setPassword('test-password');
    mustThrow(function () use ($signer) { $signer->sign('<bad', 'fu:InvoiceRequest'); }, 'Reject malformed XML');
    mustThrow(function () use ($signer) { $signer->sign('<!DOCTYPE x [<!ENTITY x "test">]><x/>', 'x'); }, 'Reject DOCTYPE');
    mustThrow(function () use ($signer) { $signer->sign('<x><r Id="same"/><s Id="same"/></x>', 'r'); }, 'Reject duplicate Id');
    mustThrow(function () use ($signer) { $signer->sign('<x><r/><r/></x>', 'r'); }, 'Reject ambiguous target');
    mustThrow(function () use ($signer) { $signer->sign('<x/>', 'x | //*'); }, 'Reject XPath as element name');
    check($signer->sign('<x/>', 'missing') === false, 'Missing target remains false');
    $once = $signer->sign(file_get_contents(__DIR__ . '/invoice.xml'), 'fu:InvoiceRequest');
    mustThrow(function () use ($signer, $once) { $signer->sign($once, 'fu:InvoiceRequest'); }, 'Reject double signing');
    echo "PASS: signing input validation\n";

    $caInput = tempnam(sys_get_temp_dir(), 'fiscal-ca-test-');
    try {
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $certPem));
        foreach ([$certPem, $certPem . $certPem, $der] as $encoding) {
            file_put_contents($caInput, $encoding);
            $converted = FiscalUtils::cerToPem($caInput);
            check(is_string($converted), 'Convert PEM, bundle and DER');
            try {
                check(openssl_x509_fingerprint(file_get_contents($converted)) === openssl_x509_fingerprint($certPem), 'Preserve certificate identity');
                if ($encoding === $certPem . $certPem) {
                    check(substr_count(file_get_contents($converted), 'BEGIN CERTIFICATE') === 2, 'Preserve entire CA bundle');
                }
            } finally { unlink($converted); }
        }
        file_put_contents($caInput, $certPem);
        $realSoap = (new FiscalSoap())->setP12($p12)->setPassword('test-password')->setCert($caInput);
        foreach ([[false, 0], ['', 200], ['<html>error</html>', 500], ['<response/>', 200]] as [$response, $status]) {
            $GLOBALS['transportResponse'] = $response;
            $GLOBALS['transportStatus'] = $status;
            if ($response === false || $response === '' || $status === 500) {
                mustThrow(function () use ($realSoap) { $realSoap->sendInvoiceRaw('<test/>'); }, 'Reject transport/HTTP/empty response');
            } else {
                check($realSoap->sendInvoiceRaw('<test/>') === $response, 'Raw response preserved');
            }
            foreach ([CURLOPT_SSLCERT, CURLOPT_CAINFO] as $option) {
                check(!file_exists($GLOBALS['transportOptions'][$option]), 'Temporary TLS file cleaned up');
            }
            check($GLOBALS['transportOptions'][CURLOPT_SSL_VERIFYPEER] === true, 'TLS validation remains enabled');
        }
        $before = glob(sys_get_temp_dir() . '/fiscal-*');
        $realSoap->setCert($caInput . '.missing');
        mustThrow(function () use ($realSoap) { $realSoap->sendInvoiceRaw('<test/>'); }, 'Reject missing CA');
        check(glob(sys_get_temp_dir() . '/fiscal-*') === $before, 'Private key cleaned up when CA fails');
        check(FiscalUtils::readP12($p12 . '.missing') === false, 'Missing PKCS#12 returns false');
        echo "PASS: certificate formats, HTTP failures and temporary file cleanup\n";
    } finally { unlink($caInput); }
    require __DIR__ . '/../fiscal.php';
    echo "PASS: legacy entry point after Composer autoload\n";
}
