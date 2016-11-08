--TEST--
Sign Invoice Application
--FILE--
<?php
require(dirname(__FILE__) . '/../vendor/autoload.php');
require(dirname(__FILE__) . '/../fiscal.php');
use Malamalca\FiscalPHP\FiscalSign;

$s = new FiscalSign();
$s->setP12(dirname(__FILE__) . '/10039953-1.p12');
$s->setPassword('Geslo123#');

if ($signed = $s->sign(file_get_contents('invoice.xml'), 'fu:InvoiceRequest')) {
    //file_put_contents('invoice_signed.xml', $signed);
    if ($signed == file_get_contents('invoice_signed.xml')) {
        print('OK: Sign successful');
    } else {
        print('ERROR: Signature invalid');
    }
} else {
    print('ERROR: Signing failed');
}
?>

--EXPECT--
OK: Sign successful