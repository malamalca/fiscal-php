--TEST--
Send echo soap request.
--FILE--
<?php
require(dirname(__FILE__) . '/../vendor/autoload.php');
require(dirname(__FILE__) . '/../fiscal.php');
use Malamalca\FiscalPHP\FiscalSoap;

$s = new FiscalSoap();
$s->setP12(dirname(__FILE__) . '/10039953-1.p12');
$s->setPassword('Geslo123#');
$s->setCert(dirname(__FILE__) . '/sitest-ca.cer');

if ($ret = $s->sendEcho('Test')) {
    print('ECHO: ' . $ret) . PHP_EOL;
    print('OK: Echo successful');
}
?>

--EXPECTF--
ECHO: Test
OK: Echo successful