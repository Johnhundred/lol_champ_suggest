<?php

require_once __DIR__.'/../vendor/autoload.php';

use GraphAware\Neo4j\Client\ClientBuilder;

$neo4j = ClientBuilder::create()
    ->addConnection('default', 'http://'.getenv('NEO4J_USER').':'.getenv('NEO4J_PASSWORD').'@localhost:7474')
    ->build();

$client = new GuzzleHttp\Client();

// Get latest data dragon LoL version
try {
	$res = $client->request('GET', 'https://ddragon.leagueoflegends.com/api/versions.json');
} catch (\Exception $e) {
	throw new Exception("Could not get LoL versions list, exiting", 1);
}

$json = json_decode($res->getBody());
$latestVersion = $json[0];

// Get latest version's champion data
try {
	$res = $client->request('GET', 'http://ddragon.leagueoflegends.com/cdn/'.$latestVersion.'/data/en_US/champion.json');
} catch (\Exception $e) {
	throw new Exception("Could not get LoL versions list, exiting", 1);
}

$json = json_decode($res->getBody());

// Delete existing Riot champion tags, to prevent duplicate relationships
$neo4j->run('MATCH (t:RiotChampionTag) DETACH DELETE t');

foreach ($json->data as $name => $champion) {
	$cid = (int) $champion->key;
	$partype = $champion->partype;
	$tags = $champion->tags;

	// Does champion node exist?
	$champion = $neo4j->run('MATCH (c:Champion) WHERE c.riot_id = {cid} RETURN c', ['cid' => $cid])->getRecords();
	if (count($champion) == false) {
		// Match doesn't exist, create it
		$neo4j->run('CREATE (c:Champion { name: {name}, riot_id: {cid}, riot_name: {name}, riot_partype: {partype}, version: {version} }) RETURN c', ['cid' => $cid, 'name' => $name, 'partype' => $partype, 'version' => $latestVersion]);
	}

	foreach ($tags as $tag) {
		// Does tag node exist?
		$champion = $neo4j->run('MATCH (c:RiotChampionTag) WHERE c.riot_name = {tag} RETURN c', ['tag' => $tag])->getRecords();
		if (count($champion) == false) {
			// Match doesn't exist, create it
			$neo4j->run('CREATE (c:RiotChampionTag { riot_name: {tag} }) RETURN c', ['tag' => $tag]);
		}

		// Associate champion with tag
		$neo4j->run('MATCH (t:RiotChampionTag) WHERE t.riot_name = {tag} WITH t MATCH (c:Champion) WHERE c.riot_id = {cid} WITH t, c MERGE (c)-[:HAS_RIOT_TAG]->(t) ', ['tag' => $tag, 'cid' => $cid])->getRecords();
	}
}
