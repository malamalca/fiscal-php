--TEST--
Test ZOI signing
--FILE--
<?php
require(dirname(__FILE__) . '/../vendor/autoload.php');
require(dirname(__FILE__) . '/../fiscal.php');
use Malamalca\FiscalPHP\FiscalSign;

$s = new FiscalSign();
$s->setP12(dirname(__FILE__) . '/10039953-1.p12');
$s->setPassword('Geslo123#');

if ($zoi = $s->zoi('test')) {
    print($zoi . PHP_EOL);
    print('OK: ZOI successful');
} else {
    print('ERROR: ZOI failed');
}
?>

--EXPECT--
ca12ad3dd9d1cb19a29cf08d09a045c1
OK: ZOI successful