<?php
namespace Malamalca\FiscalPHP;

use \Exception;
use \DomDocument;
use \DomXPath;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

/**
 * FiscalSign.php
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
 
class FiscalSign
{
    /** @var string */
    private $p12 = '';
    
    /** @var string */
    private $password = '';
    
    /** @var string */
    private $idPropertyName = 'Id';
    
    /**
    * @param string $prefix
    */
    public function __construct($options = array())
    {
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
    * @param string $signingNode Node to sign eg fu:InvoiceRequest
    */
    public function sign($xml, $signingNode)
    {
        $ret = false;
        
        $doc = FiscalUtils::parseXml($xml);
         
        $xpath = new DOMXPath($doc);
        if (!preg_match('/^(?:[A-Za-z_][A-Za-z0-9_.-]*:)?[A-Za-z_][A-Za-z0-9_.-]*$/D', $signingNode)) {
            throw new Exception('Invalid signing element name.');
        }
        $xpath->registerNamespace('fu', 'http://www.fu.gov.si/');
        if (strpos($signingNode, ':') !== false) {
            $prefix = explode(':', $signingNode, 2)[0];
            if ($prefix !== 'fu' && $doc->documentElement->lookupNamespaceURI($prefix) === null) {
                throw new Exception('Unknown signing namespace prefix.');
            }
        }
        $nodes = $xpath->query('//' . $signingNode);
        if ($nodes === false || $nodes->length > 1) {
            throw new Exception('Signing element must be unambiguous.');
        }
        if ($nodeset = $nodes->item(0)) {
            $id = $nodeset->getAttribute($this->idPropertyName);
            if ($id !== '') {
                foreach ($xpath->query('//*[@Id]') as $element) {
                    if (!$element->isSameNode($nodeset) && $element->getAttribute('Id') === $id) {
                        throw new Exception('Duplicate signing Id.');
                    }
                }
            }
            if ($nodeset->getElementsByTagNameNS(XMLSecurityDSig::XMLDSIGNS, 'Signature')->length > 0) {
                throw new Exception('Request is already signed.');
            }
            $objXMLSecDSig = new XMLSecurityDSig('');
            $objXMLSecDSig->setCanonicalMethod(XMLSecurityDSig::C14N);  
            $objXMLSecDSig->addReference($nodeset, 
                XMLSecurityDSig::SHA256,
                ['http://www.w3.org/2000/09/xmldsig#enveloped-signature'], 
                ['id_name' => $this->idPropertyName, 'overwrite' => false]
            );
             
            if (($raw = FiscalUtils::readP12($this->p12, $this->password)) === false) {
                throw new Exception('Cannot read PKCS#12 signing certificate.');
            }
             
            $objKey = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
            $objKey->loadKey($raw['pkey']);
             
            $objXMLSecDSig->sign($objKey, $nodeset);
            $objXMLSecDSig->add509Cert($raw['cert'], true, false, 
                ['issuerSerial' => true, 'subjectName' => true]
            );
         
            // FURS identifies the certificate by subject, issuer and decimal serial.
            // Upstream xmlseclibs always embeds it; omit it as the former fork did.
            $signatureXPath = new DOMXPath($doc);
            $signatureXPath->registerNamespace('ds', XMLSecurityDSig::XMLDSIGNS);
            foreach ($signatureXPath->query('./ds:KeyInfo/ds:X509Data/ds:X509Certificate', $objXMLSecDSig->sigNode) as $certificate) {
                $certificate->parentNode->removeChild($certificate);
            }

            $ret = $doc->saveXML();
        }
        
        return $ret;
    }
    
    /**
    * @param string $data Zoi string to be signed
    * @param string $p12 Path to clients p12 store
    */
    public function zoi($data)
    {
        $ret = false;
        
        if (($raw = FiscalUtils::readP12($this->p12, $this->password)) !== false) {
            $key = openssl_pkey_get_private($raw['pkey']);
            if ($key !== false && openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256)) {
                $ret = md5($signature);
            }
        }
        return $ret;
    }
    

}