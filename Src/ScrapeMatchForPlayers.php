<?php

require_once __DIR__.'/../vendor/autoload.php';

use GraphAware\Neo4j\Client\ClientBuilder;

$neo4j = ClientBuilder::create()
    ->addConnection('default', 'bolt://'.getenv('NEO4J_USER').':'.getenv('NEO4J_PASSWORD').'@localhost:7687') // port is optional
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

$playerInformation = [];

foreach ($json->participantIdentities as $identity) {
	$playerInformation[$identity->participantId] = [];

	$playerInformation[$identity->participantId]['aid'] = $identity->player->accountId;
	$playerInformation[$identity->participantId]['name'] = $identity->player->summonerName;
}

foreach ($json->participants as $participant) {
	$playerInformation[$participant->participantId]['cid'] = $participant->championId;
	$playerInformation[$participant->participantId]['lane'] = $participant->timeline->lane;
	$playerInformation[$participant->participantId]['role'] = $participant->timeline->role;
	$playerInformation[$participant->participantId]['team'] = $participant->teamId;
	if (property_exists($participant->timeline, 'creepsPerMinDeltas')) {
		$key = '0-10';
		$playerInformation[$participant->participantId]['cs_per_min'] = $participant->timeline->creepsPerMinDeltas->$key;
	} else {
		$playerInformation[$participant->participantId]['cs_per_min'] = 0;
	}
}

$laneInformation = [
	100 => [],
	200 => [],
];

$doubt = false;
$determineBottomMakeup = [
	100 => [],
	200 => [],
];

foreach ($playerInformation as $id => $data) {
	// Player is not in bottom lane, set their lane to the provided information
	if ($data['lane'] !== 'BOTTOM') {
		$laneInformation[$data['team']][$data['lane']] = $data['role'];
		continue;
	}

	// Player's role is set as support in bottom lane, assume they are support
	if ($data['role'] === 'DUO_SUPPORT') {
		$laneInformation[$data['team']]['SUPPORT'] = 'NONE';
		$playerInformation[$id]['lane'] = 'SUPPORT';
		continue;
	}

	if ($data['role'] === 'DUO_CARRY') {
		$laneInformation[$data['team']]['ADC'] = 'NONE';
		$playerInformation[$id]['lane'] = 'ADC';
		continue;
	}

	// Player's lane is bottom, but we don't know if they're support or ADC
	// If we already have a support, they must be the ADC
	if (array_key_exists('SUPPORT', $laneInformation[$data['team']])) {
		$laneInformation[$data['team']]['ADC'] = 'NONE';
		$playerInformation[$id]['lane'] = 'ADC';
		$playerInformation[$id]['role'] = 'DUO_CARRY';
		continue;
	}

	// And the reverse
	if (array_key_exists('ADC', $laneInformation[$data['team']])) {
		$laneInformation[$data['team']]['SUPPORT'] = 'NONE';
		$playerInformation[$id]['lane'] = 'SUPPORT';
		$playerInformation[$id]['role'] = 'DUO_SUPPORT';
		continue;
	}

	// Lane is bottom, but we don't know who is support and who is ADC
	// Determine who is support based on CS/min data
	$determineBottomMakeup[$data['team']][] = $id;
	$doubt = true;
}

if ($doubt) {
	foreach ($determineBottomMakeup as $team => $identities) {
		$highestCs = 0;
		$highestCsIdentity = null;
		$lowestCsIdentity = null;

		foreach ($identities as $identity) {
			if ($playerInformation[$identity]['cs_per_min'] > $highestCs) {
				$highestCs = $playerInformation[$identity]['cs_per_min'];
				$lowestCsIdentity = $highestCsIdentity;
				$highestCsIdentity = $identity;
			}
		}

		$playerInformation[$highestCsIdentity]['lane'] = 'ADC';
		$playerInformation[$highestCsIdentity]['role'] = 'DUO_CARRY';
		if ($lowestCsIdentity) {
			$playerInformation[$lowestCsIdentity]['lane'] = 'SUPPORT';
			$playerInformation[$lowestCsIdentity]['role'] = 'DUO_SUPPORT';
		}
	}
}

foreach ($playerInformation as $id => $data) {
	// If lane is none, chances are the positional data for the match is gone/corrupt. Just skip the match.
	if ($data['lane'] === 'NONE') {
		$neo4j->run('MATCH (m:Match) WHERE m.riot_gameId = {gid} SET m.scraped = true', ['gid' => $gameId]);
		sleep(1);
		exit(0);
	}
}

$season = $json->seasonId;
foreach ($playerInformation as $id => $data) {
	// Do we have this player already?
	$player = $neo4j->run('MATCH (n:Player) WHERE n.riot_accountId = {aid} RETURN n', ['aid' => $data['aid']])->getRecords();
	if (count($player) == false) {
		// Player doesn't exist, create them
		$neo4j->run('CREATE (n:Player { riot_accountId: {aid}, riot_name: {name}, scraped: false }) RETURN n', ['aid' => $data['aid'], 'name' => $data['name']]);
	}

	// Does champion node exist?
	$champion = $neo4j->run('MATCH (c:Champion) WHERE c.riot_id = {cid} RETURN c', ['cid' => $data['cid']])->getRecords();
	if (count($champion) == false) {
		// Champion doesn't exist, create it
		$neo4j->run('CREATE (c:Champion { riot_id: {cid} }) RETURN c', ['cid' => $data['cid']]);
	}

	// Record the player as having played the match, if we haven't already
	$relationship = $neo4j->run('MATCH (n:Player)-[r1:PLAYED_CHAMPION]->(:Champion)-[r2:IN_MATCH]->(m:Match) WHERE n.riot_accountId = {rid} AND m.riot_gameId = {gid} RETURN r1', ['rid' => $data['aid'], 'gid' => $gameId])->getRecords();
	if (count($relationship) == false) {
		// Player isn't marked as match participant, mark them
		$neo4j->run('MATCH (n:Player) WHERE n.riot_accountId = {rid} WITH n MATCH (m:Match) WHERE m.riot_gameId = {gid} WITH n, m MATCH (c:Champion) WHERE c.riot_id = {cid} WITH n, m, c MERGE (n)-[r1:PLAYED_CHAMPION]->(c)-[r2:IN_MATCH]->(m) SET r1.riot_lane = {lane}, r1.riot_role = {role}, r1.riot_season = {season}', ['rid' => $data['aid'], 'gid' => $gameId, 'cid' => $data['cid'], 'lane' => $data['lane'], 'role' => $data['role'], 'season' => $season]);
	}
}

var_dump($laneInformation);

// Mark match scraped
$neo4j->run('MATCH (m:Match) WHERE m.riot_gameId = {gid} SET m.scraped = true', ['gid' => $gameId]);
