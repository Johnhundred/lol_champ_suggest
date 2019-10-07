<?php

require_once __DIR__.'/../vendor/autoload.php';

use GraphAware\Neo4j\Client\ClientBuilder;

$name = 'Endscape';

$client = new GuzzleHttp\Client();
$res = $client->request('GET', 'https://euw1.api.riotgames.com/lol/summoner/v4/summoners/by-name/'.$name, [
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
echo round($json->revisionDate/1000) . PHP_EOL . PHP_EOL;

$client = ClientBuilder::create()
    ->addConnection('default', 'http://'.getenv('NEO4J_USER').':'.getenv('NEO4J_PASSWORD').'@localhost:7474') // Example for HTTP connection configuration (port is optional)
    ->build();

$result = $client->run('MATCH (n) RETURN COUNT(n) AS count');
$record = $result->getRecord();
var_dump($record->get('count'));

// Body:
// {
//     "profileIconId": 3898,
//     "name": "Endscape",
//     "puuid": "zmlRhULxucTc3y5-ky2dECqYWIT6IgrUS0VI-NCOiJKEZfCWpASBYWvFqa_fAPqfymEIjoh02DjvLQ",
//     "summonerLevel": 134,
//     "accountId": "0U6diAUh54ojwjYE2pH0OuAb_hgwAn03iHEv25YM5viLFw",
//     "id": "bMWlrHph4IKOUqorwhBJamHLqtouXr_KP_q-Q9X6nVHsvXM",
//     "revisionDate": 1570239241000
// }

// Headers:
// {
//     "X-App-Rate-Limit-Count": "1:1,1:120",
//     "Content-Encoding": "gzip",
//     "X-Method-Rate-Limit-Count": "1:60",
//     "Vary": "Accept-Encoding",
//     "X-App-Rate-Limit": "20:1,100:120",
//     "X-Method-Rate-Limit": "2000:60",
//     "transfer-encoding": "chunked",
//     "Connection": "keep-alive",
//     "Date": "Mon, 7 Oct 2019  13:43:54 GMT",
//     "X-Riot-Edge-Trace-Id": "8352ceb8-02ee-4047-b9a7-a4c4d7bdd33b",
//     "Content-Type": "application/json;charset=utf-8"
// }
