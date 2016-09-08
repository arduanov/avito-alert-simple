<?php
$hours = date('H');
if (!($hours >= 8 && $hours <= 24)) {
    exit;
}

sleep(rand(20,100));

include __DIR__ . '/vendor/autoload.php';

$root = __DIR__;
$slack_config = include $root . '/config/slack.php';

$goutte = new Goutte\Client();
$slack = new Maknz\Slack\Client($slack_config['endpoint'], $slack_config);
$avito = new App\AvitoService($root, $goutte, $slack);
$avito->start();