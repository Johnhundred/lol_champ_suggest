<?php

require_once __DIR__.'/../vendor/autoload.php';

use GraphAware\Neo4j\Client\ClientBuilder;

$neo4j = ClientBuilder::create()
    ->addConnection('default', 'http://'.getenv('NEO4J_USER').':'.getenv('NEO4J_PASSWORD').'@localhost:7474') // port is optional
    ->build();

$client = new GuzzleHttp\Client();

// Get match we haven't yet scraped for players
// TODO: Eventually we'll want to convert to a timestamp to allow repeat scrapes.
$match = $neo4j->run('MATCH (n:Match) WHERE EXISTS(n.riot_gameId) AND (n.scraped = false OR NOT EXISTS(n.scraped)) RETURN n.riot_gameId AS gid LIMIT 1');
$records = $match->getRecords(); // Using records rather than record to get an empty array rather than an error if no result.

// 0 type juggles to false.
if (count($records) == false) {
	// If no match was found, stop.
	throw new \Exception("No match found for scraping matches", 1);	
}

foreach ($records as $record) {
	$gameId = $record->value('gid');
}

// Get match information - for now, we're just interested in players
try {
	$res = $client->request('GET', 'https://euw1.api.riotgames.com/lol/match/v4/matches/'.$gameId, [
	    'query' => 'api_key='.getenv('RIOT_API_TOKEN'),
	]);
} catch (\Exception $e) {
	$neo4j->run('MATCH (m:Match) WHERE m.riot_gameId = {gid} SET m.scraped = true', ['gid' => $gameId]);
	sleep(1);

	throw new Exception("Error fetching match, marking scraped and skipping: ".$gameId, 1);
}

$json = json_decode($res->getBody());

foreach ($json->participantIdentities as $key => $value) {
	$aid = $value->player->accountId;
	$name = $value->player->summonerName;
	$participantId = $value->participantId;

	foreach ($json->participants as $participant) {
		if ($participant->participantId == $participantId) {
			$cid = $participant->championId;

			break;
		}
	}

	// Do we have this player already?
	$player = $neo4j->run('MATCH (n:Player) WHERE n.riot_accountId = {aid} RETURN n', ['aid' => $aid])->getRecords();
	if (count($player) == false) {
		// Player doesn't exist, create them
		$neo4j->run('CREATE (n:Player { riot_accountId: {aid}, riot_name: {name}, scraped: false }) RETURN n', ['aid' => $aid, 'name' => $name]);
	}

	// Does champion node exist?
	$champion = $neo4j->run('MATCH (c:Champion) WHERE c.riot_id = {cid} RETURN c', ['cid' => $cid])->getRecords();
	if (count($champion) == false) {
		// Champion doesn't exist, create it
		$neo4j->run('CREATE (c:Champion { riot_id: {cid} }) RETURN c', ['cid' => $cid]);
	}

	// Record the player as having played the match, if we haven't already
	$relationship = $neo4j->run('MATCH (n:Player)-[r1:PLAYED_CHAMPION]->(:Champion)-[r2:IN_MATCH]->(m:Match) WHERE n.riot_accountId = {rid} AND m.riot_gameId = {gid} RETURN r1', ['rid' => $aid, 'gid' => $gameId])->getRecords();
	if (count($relationship) == false) {
		// Player isn't marked as match participant, mark them
		$neo4j->run('MATCH (n:Player) WHERE n.riot_accountId = {rid} WITH n MATCH (m:Match) WHERE m.riot_gameId = {gid} WITH n, m MATCH (c:Champion) WHERE c.riot_id = {cid} WITH n, m, c MERGE (n)-[r1:PLAYED_CHAMPION]->(c)-[r2:IN_MATCH]->(m)', ['rid' => $aid, 'gid' => $gameId, 'cid' => $cid]);
	}
}

// Mark match scraped
$neo4j->run('MATCH (m:Match) WHERE m.riot_gameId = {gid} SET m.scraped = true', ['gid' => $gameId]);
