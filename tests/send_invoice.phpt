--TEST--
Send invoice.
--FILE--
<?php
require(dirname(__FILE__) . '/../vendor/autoload.php');
require(dirname(__FILE__) . '/../fiscal.php');
use Malamalca\FiscalPHP\FiscalSoap;

$s = new FiscalSoap();
$s->setP12(dirname(__FILE__) . '/10039953-1.p12');
$s->setPassword('Geslo123#');
$s->setCert(dirname(__FILE__) . '/sitest-ca.cer');

if ($ret = $s->sendInvoice(file_get_contents('invoice_signed.xml'))) {
    print('EOR: ' . $ret) . PHP_EOL;
    print('OK: Invoice successful');
} else {
    print_r($ret) . PHP_EOL;
    print('Error: Send Failed');
}
?>

--EXPECTF--
EOR: %s
OK: Invoice successful