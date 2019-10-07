<?php

require_once __DIR__.'/../vendor/autoload.php';

echo 'wat' . PHP_EOL;
echo getenv('PAPERTRAIL_API_TOKEN') . PHP_EOL;

$client = new GuzzleHttp\Client();
$res = $client->request('GET', 'https://euw1.api.riotgames.com/lol/summoner/v4/summoners/by-name/Endscape', [
    'query' => 'api_key='.getenv('RIOT_API_TOKEN'),
]);
echo $res->getStatusCode() . PHP_EOL;
echo $res->getHeader('content-type')[0] . PHP_EOL;
echo $res->getBody() . PHP_EOL;
