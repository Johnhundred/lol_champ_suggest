<?php

require_once __DIR__.'/../vendor/autoload.php';

use GraphAware\Neo4j\Client\ClientBuilder;

$neo4j = ClientBuilder::create()
    ->addConnection('default', 'http://'.getenv('NEO4J_USER').':'.getenv('NEO4J_PASSWORD').'@localhost:7474') // port is optional
    ->build();

$name = 'Endscape';
$client = new GuzzleHttp\Client();
$res = $client->request('GET', 'https://euw1.api.riotgames.com/lol/summoner/v4/summoners/by-name/'.$name, [
    'query' => 'api_key='.getenv('RIOT_API_TOKEN'),
]);

$json = json_decode($res->getBody());
// echo round($json->revisionDate/1000) . PHP_EOL . PHP_EOL;

// Do we already have this player?
$player = $neo4j->run('MATCH (n:Player) WHERE n.riot_accountId = {id} RETURN n', ['id' => $json->accountId]);
$record = $player->getRecords();

// If no player was found. 0 type juggles to false.
if (count($record) == false) {
	$query = 'Create (n:Player) SET n += {attributes}';
} else {
	$query = 'MATCH (n:Player) WHERE n.riot_accountId = {id} SET n += {attributes}';
}

// Set player attributes, including updated_at to keep track of when we last looked at this player
$neo4j->run($query,
[
	'attributes' => [
		'riot_profileIconId' => $json->profileIconId, 
		'riot_name' => $json->name, 
		'riot_id' => $json->id, 
		'riot_accountId' => $json->accountId, 
		'riot_puuid' => $json->puuid, 
		'riot_summonerLevel' => $json->summonerLevel, 
		'riot_revisionDate' => $json->revisionDate,
		'updated_at' => time(),
	],
	'id' => $json->accountId
]);

// Get player's matchlist (Most recent 100 by default)
$res = $client->request('GET', 'https://euw1.api.riotgames.com/lol/match/v4/matchlists/by-account/'.$json->accountId, [
    'query' => 'api_key='.getenv('RIOT_API_TOKEN'),
]);

$json = json_decode($res->getBody());
// var_dump(array_keys(get_object_vars($json)));
// var_dump(array_keys($json->matches));
// var_dump(array_keys(get_object_vars($json->matches[0])));
// echo $json->matches[0]->gameId .PHP_EOL;

// Get match info about player's most recent match
$res = $client->request('GET', 'https://euw1.api.riotgames.com/lol/match/v4/matches/'.$json->matches[0]->gameId, [
    'query' => 'api_key='.getenv('RIOT_API_TOKEN'),
]);

$json = json_decode($res->getBody());
// var_dump(array_keys(get_object_vars($json)));
// var_dump($json->participants);
// var_dump($json->participantIdentities);

foreach ($json->participantIdentities as $key => $value) {
	var_dump($value->player->accountId);
}

$result = $neo4j->run('MATCH (n) RETURN COUNT(n) AS count');
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
