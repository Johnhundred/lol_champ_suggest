<?php

require_once __DIR__.'/../vendor/autoload.php';

use GraphAware\Neo4j\Client\ClientBuilder;

$neo4j = ClientBuilder::create()
    ->addConnection('default', 'bolt://'.getenv('NEO4J_USER').':'.getenv('NEO4J_PASSWORD').'@localhost:7687') // port is optional
    ->build();

$client = new GuzzleHttp\Client();

// Get 1 player we haven't yet scraped matches for.
// TODO: Eventually we'll want to convert to a timestamp to allow repeat scrapes.
$player = $neo4j->run('MATCH (n:Player) WHERE EXISTS(n.riot_accountId) AND (n.scraped = false OR NOT EXISTS(n.scraped)) RETURN n.riot_accountId AS rid LIMIT 1');
$records = $player->getRecords(); // Using records rather than record to get an empty array rather than an error if no result.

// 0 type juggles to false.
if (count($records) == false) {
	// If no player was found, stop.
	throw new \Exception("No player found for scraping matches", 1);
	
}

foreach ($records as $record) {
	$accountId = $record->value('rid');
}

// Get player's matchlist (Most recent 100 by default)
try {
	$res = $client->request('GET', 'https://euw1.api.riotgames.com/lol/match/v4/matchlists/by-account/'.$accountId, [
	    'query' => 'api_key='.getenv('RIOT_API_TOKEN'),
	]);
} catch (\Exception $e) {
	$neo4j->run('MATCH (n:Player) WHERE n.riot_accountId = {rid} SET n.scraped = true', ['rid' => $accountId]);
	sleep(1);

	throw new Exception("Error fetching player matches, marking scraped and skipping: ".$accountId, 1);
}

$json = json_decode($res->getBody());

// TODO: Optimize
foreach ($json->matches as $match) {
	$queue = $match->queue;

	// If match is not 5v5 Summoner's Rift (Normal or Ranked), or Clash, skip it
	if (!in_array($queue, [400, 420, 430, 440, 700])) {
		continue;
	}

	$gid = $match->gameId;
	$cid = $match->champion;

	// Does match node exist?
	$match = $neo4j->run('MATCH (m:Match) WHERE m.riot_gameId = {gid} RETURN m', ['gid' => $gid])->getRecords();
	if (count($match) == false) {
		// Match doesn't exist, create it
		$neo4j->run('CREATE (m:Match { riot_gameId: {gid}, scraped: false }) RETURN m', ['gid' => $gid]);
	}

	// Does champion node exist?
	$champion = $neo4j->run('MATCH (c:Champion) WHERE c.riot_id = {cid} RETURN c', ['cid' => $cid])->getRecords();
	if (count($champion) == false) {
		// Champion doesn't exist, create it
		$neo4j->run('CREATE (c:Champion { riot_id: {cid} }) RETURN c', ['cid' => $cid]);
	}

	// Record the player as having played the match, if we haven't already
	$relationship = $neo4j->run('MATCH (n:Player)-[r1:PLAYED_CHAMPION]->(:Champion)-[r2:IN_MATCH]->(m:Match) WHERE n.riot_accountId = {rid} AND m.riot_gameId = {gid} RETURN r1', ['rid' => $accountId, 'gid' => $gid])->getRecords();
	if (count($relationship) == false) {
		// Player isn't marked as match participant, mark them
		$neo4j->run('MATCH (n:Player) WHERE n.riot_accountId = {rid} WITH n MATCH (m:Match) WHERE m.riot_gameId = {gid} WITH n, m MATCH (c:Champion) WHERE c.riot_id = {cid} WITH n, m, c MERGE (n)-[r1:PLAYED_CHAMPION]->(c)-[r2:IN_MATCH]->(m)', ['rid' => $accountId, 'gid' => $gid, 'cid' => $cid]);
	}
}

// Mark player as scraped
$neo4j->run('MATCH (n:Player) WHERE n.riot_accountId = {rid} SET n.scraped = true', ['rid' => $accountId]);
