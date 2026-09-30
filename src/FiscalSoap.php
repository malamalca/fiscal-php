<?php
namespace Malamalca\FiscalPHP;
use \Exception;

/**
 * FiscalSoap.php
 *
 * Copyright (c) 2015-2016, Miha Nahtigal <miha@malamalca.com>.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 *
 *   * Redistributions of source code must retain the above copyright
 *     notice, this list of conditions and the following disclaimer.
 *
 *   * Redistributions in binary form must reproduce the above copyright
 *     notice, this list of conditions and the following disclaimer in
 *     the documentation and/or other materials provided with the
 *     distribution.
 *
 *   * Neither the name of Miha Nahtigal nor the names of his
 *     contributors may be used to endorse or promote products derived
 *     from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
 * FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN
 * ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @author    Miha Nahtigal <miha@malamalca.com>
 * @copyright 2015-2016 Miha Nahtigal <miha@malamalca.com>
 * @license   http://www.gnu.org/licenses/lgpl.html  GNU Lesser General Public License
 */
 
class FiscalSoap
{
    /** @var string */
    private $ECHO_TEMPLATE = '';
    
    /** @var string */
    private $cert = '';
    
    /** @var string */
    private $p12 = '';
    
    /** @var string */
    private $password = '';
    
    /** @var string */
    private $url = 'https://blagajne-test.fu.gov.si:9002/v1/cash_registers';
    
    /** @var int Total request timeout in milliseconds */
    private $timeoutMs = 3000;
    
    /**
    * @param string $prefix
    */
    public function __construct($options = array())
    {
        $this->ECHO_TEMPLATE = '<?xml version="1.0" encoding="utf-8"?' . '>' .
            '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" ' .
            'xmlns:fu="http://www.fu.gov.si/" xmlns:xd="http://www.w3.org/2000/09/xmldsig#">' .
            '<soapenv:Body>' .
            '<fu:EchoRequest>%s</fu:EchoRequest>' .
            '</soapenv:Body>' .
            '</soapenv:Envelope>';
    }
    
    /**
    * @param string $url Soap service url
    */
    public function setUrl($url)
    {
        $this->url = $url;
        return $this;
    }
    
    /**
    * @param int $milliseconds Total request timeout (the connection timeout stays 3 s).
    */
    public function setTimeout($milliseconds)
    {
        $this->timeoutMs = max(1, (int) $milliseconds);
        return $this;
    }
    
    /**
    * @param string $fileName Server's public key.
    */
    public function setCert($fileName)
    {
        $this->cert = $fileName;
        return $this;
    }
    
    /**
    * @param string $fileName Clients key in .p12|.pfx store.
    */
    public function setP12($fileName)
    {
        $this->p12 = $fileName;
        return $this;
    }
    
    /**
    * @param string $password Client's private key password.
    */
    public function setPassword($password)
    {
        $this->password = $password;
        return $this;
    }
    
    /**
    * @param string $message Echo message
    */
    public function sendEcho($message)
    {
        if ($response = $this->doRequest('echo', sprintf($this->ECHO_TEMPLATE, htmlspecialchars($message, ENT_XML1 | ENT_QUOTES, 'UTF-8')))) {
            if ($this->hasError($response) === false) {
                return $this->elementValue($response, 'EchoResponse');
            }
        }
    }
    
    /**
    * @param string $xml Signed premise xml
    */
    public function sendPremiseRaw($xml)
    {
        return $this->doRequest('invoices/register', $xml);
    }
    
    /**
    * @param string $xml Signed premise xml
    */
    public function sendPremise($xml)
    {
        if ($response = $this->sendPremiseRaw($xml)) {
            return $this->hasError($response) === false && $this->elementValue($response, 'BusinessPremiseResponse') !== false;
        }
    }
    
    /**
    * @param string $xml Signed invoice xml
    */
    public function sendInvoiceRaw($xml)
    {
        return $this->doRequest('invoices', $xml);
    }
    
    /**
    * @param string $xml Signed invoice xml
    */
    public function sendInvoice($xml)
    {
        if ($response = $this->sendInvoiceRaw($xml)) {
            if ($this->hasError($response) === false) {
                return $this->elementValue($response, 'UniqueInvoiceID');
            }
        }
    }
    
    /**
    * @param string $xml Signed invoice xml
    */
    public function hasError($xml)
    {
        try {
            $doc = FiscalUtils::parseXml($xml);
        } catch (Exception $e) {
            return true;
        }
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('soap', 'http://schemas.xmlsoap.org/soap/envelope/');
        $xpath->registerNamespace('fu', 'http://www.fu.gov.si/');
        $body = $xpath->query('/soap:Envelope/soap:Body')->item(0);
        return $body === null
            || $xpath->query('.//fu:Error | .//soap:Fault', $body)->length > 0
            || $xpath->query('./fu:EchoResponse | ./fu:InvoiceResponse | ./fu:BusinessPremiseResponse', $body)->length !== 1;
    }
    /**
    * @param string $xml Response XML
    * @param string $elementName XML Element Name
    */
    private function elementValue($xml, $elementName)
    {
        $doc = FiscalUtils::parseXml($xml);
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('soap', 'http://schemas.xmlsoap.org/soap/envelope/');
        $xpath->registerNamespace('fu', 'http://www.fu.gov.si/');
        $paths = [
            'EchoResponse' => '/soap:Envelope/soap:Body/fu:EchoResponse',
            'BusinessPremiseResponse' => '/soap:Envelope/soap:Body/fu:BusinessPremiseResponse',
            'UniqueInvoiceID' => '/soap:Envelope/soap:Body/fu:InvoiceResponse/fu:UniqueInvoiceID',
        ];
        $nodes = $xpath->query($paths[$elementName]);
        return $nodes->length === 1 ? $nodes->item(0)->textContent : false;
    }
    /**
    * @param string $action Curl action.
    * @param string $xml XML body.
    */
    protected function doRequest($action, $xml)
    {
        $privateKey = false;
        $ca = false;
        try {
            $privateKey = FiscalUtils::p12ToPem($this->p12, $this->password);
            if ($privateKey === false) {
                throw new Exception('ERROR: Cannot parse P12');
            }
            $ca = FiscalUtils::cerToPem($this->cert);
            if ($ca === false) {
                throw new Exception('ERROR: Cannot parse CA Info');
            }
            $conn = curl_init();
            curl_setopt_array($conn, [
                CURLOPT_URL => $this->url,
                CURLOPT_CONNECTTIMEOUT_MS => 3000,
                CURLOPT_TIMEOUT_MS => $this->timeoutMs,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: text/xml; charset=utf-8',
                    'Cache-Control: no-cache',
                    'Pragma: no-cache',
                    'SOAPAction: /' . $action,
                ],
                CURLOPT_POSTFIELDS => $xml,
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSLCERT => $privateKey,
                CURLOPT_SSLCERTPASSWD => $this->password,
                CURLOPT_CAINFO => $ca,
            ]);
            $response = curl_exec($conn);
            if ($response === false) {
                throw new Exception('CODECURL: ' . curl_error($conn));
            }
            $status = curl_getinfo($conn, CURLINFO_HTTP_CODE);
            if ($status < 200 || $status >= 300) {
                throw new Exception('HTTP error: ' . $status);
            }
            if ($response === '') {
                throw new Exception('Empty response from fiscal service.');
            }
            return $response;
        } finally {
            // Release the cURL handle before removing files, including on Windows.
            unset($conn);
            if ($privateKey !== false) {
                unlink($privateKey);
            }
            if ($ca !== false) {
                unlink($ca);
            }
        }
    }
}
