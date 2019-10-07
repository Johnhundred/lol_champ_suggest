<?php

require_once __DIR__.'/../vendor/autoload.php';

$client = new GuzzleHttp\Client();
$res = $client->request('GET', 'https://euw1.api.riotgames.com/lol/summoner/v4/summoners/by-name/Endscape', [
    'query' => 'api_key='.getenv('RIOT_API_TOKEN'),
]);
echo $res->getStatusCode() . PHP_EOL;
echo $res->getHeader('content-type')[0] . PHP_EOL;
echo $res->getBody() . PHP_EOL . PHP_EOL;

$json = json_decode($res->getBody());
echo $json->profileIconId . PHP_EOL;
echo $json->name . PHP_EOL;
echo $json->puuid . PHP_EOL;
echo $json->summonerLevel . PHP_EOL;
echo $json->accountId . PHP_EOL;
echo $json->id . PHP_EOL;
echo $json->revisionDate . PHP_EOL;
echo round($json->revisionDate/1000) . PHP_EOL;
