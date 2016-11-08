--TEST--
Send business premise application.
--FILE--
<?php
require(dirname(__FILE__) . '/../vendor/autoload.php');
require(dirname(__FILE__) . '/../fiscal.php');
use Malamalca\FiscalPHP\FiscalSoap;

$s = new FiscalSoap();
$s->setP12(dirname(__FILE__) . '/10039953-1.p12');
$s->setPassword('Geslo123#');
$s->setCert(dirname(__FILE__) . '/sitest-ca.cer');

if ($ret = $s->sendPremise(file_get_contents('premise_signed.xml'))) {
    //print_r($ret);
    print('OK: Premise successful');
}
?>

--EXPECT--
OK: Premise successful