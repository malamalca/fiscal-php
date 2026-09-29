<?php
namespace Malamalca\FiscalPHP;
use Exception;

/**
 * FiscalUtils.php
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
 
class FiscalUtils
{
    /** Read PKCS#12 credentials without writing private keys to disk. */
    public static function readP12($p12, $password = null)
    {
        if (!is_file($p12) || !is_readable($p12)) {
            return false;
        }
        $contents = file_get_contents($p12);
        if ($contents === false || !openssl_pkcs12_read($contents, $certInfo, $password ?? '')) {
            return false;
        }
        return $certInfo;
    }

    /** The caller owns the returned temporary file and must delete it. */
    public static function p12ToPem($p12, $password = null)
    {
        $certInfo = self::readP12($p12, $password);
        if ($certInfo === false) {
            return false;
        }
        return self::writeTemporaryPem($certInfo['pkey'] . $certInfo['cert'] . implode('', $certInfo['extracerts'] ?? []));
    }

    /** Accept either a DER certificate or a PEM certificate/CA bundle. */
    public static function cerToPem($cer)
    {
        if (!is_file($cer) || !is_readable($cer)) {
            return false;
        }
        $contents = file_get_contents($cer);
        if ($contents === false || $contents === '') {
            return false;
        }
        if (strpos($contents, '-----BEGIN CERTIFICATE-----') === false) {
            $contents = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($contents), 64, "\n") . "-----END CERTIFICATE-----\n";
        }
        if (!preg_match_all('/-----BEGIN CERTIFICATE-----\s*([A-Za-z0-9+\/=\s]+)-----END CERTIFICATE-----/', $contents, $matches)) {
            return false;
        }
        foreach ($matches[0] as $certificate) {
            if (openssl_x509_read($certificate) === false) {
                return false;
            }
        }
        return self::writeTemporaryPem($contents);
    }

    private static function writeTemporaryPem($contents)
    {
        $file = tempnam(sys_get_temp_dir(), 'fiscal-');
        if ($file === false) {
            return false;
        }
        $written = false;
        try {
            $written = file_put_contents($file, $contents) === strlen($contents);
            return $written ? $file : false;
        } finally {
            if (!$written) {
                unlink($file);
            }
        }
    }

    /** Parse XML without external resources or document type declarations. */
    public static function parseXml($xml)
    {
        if (!is_string($xml) || trim($xml) === '') {
            throw new Exception('Empty XML document.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new \DOMDocument();
            if (!$doc->loadXML($xml, LIBXML_NONET) || $doc->doctype !== null) {
                throw new Exception('Invalid XML document or unsupported DOCTYPE.');
            }
            return $doc;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
