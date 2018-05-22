<?php

echo __DIR__ . DIRECTORY_SEPARATOR . '../../vendor/autoload.php' . "\n\n";

require_once __DIR__ . DIRECTORY_SEPARATOR . '../../vendor/autoload.php';

$transport = new Gelf\Transport\TcpTransport("127.0.0.1", 12201);

$publisher = new Gelf\Publisher();
$publisher->addTransport($transport);

$logger = new Gelf\Logger($publisher);

$logger->debug("A debug message.");
$logger->alert("An alert message", ['structure' => ['data' => [0, 1]]]);

try {
    throw new Exception("Test exception");
} catch (Exception $e) {
    $logger->emergency("Exception example", array('exception' => $e));
}

$message = new Gelf\Message();
$message->setShortMessage("Structured message")
    ->setLevel(\Psr\Log\LogLevel::ALERT)
    ->setFullMessage("There was a foo in bar")
    ->setFacility("example-facility")
    ->setAdditional('foo', 'bar')
    ->setAdditional('bar', 'baz')
;
$publisher->publish($message);

$logger->warning("A warning message.", ['structure' => ['with' => ['several' => 'nested', 'levels']]]);
$logger->info(file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . "bacon.txt"));
