<?php

namespace Src;

require_once __DIR__.'/../vendor/autoload.php';

use GraphAware\Neo4j\Client\ClientBuilder;
use Src\ChampionData;

class PlayerStatistics
{
	private $neo4j;

	public function __construct()
	{
		$this->neo4j = ClientBuilder::create()
		    ->addConnection('default', 'http://'.getenv('NEO4J_USER').':'.getenv('NEO4J_PASSWORD').'@localhost:7474') // port is optional
		    ->build();
	}

	public function getMostPlayedChampions(string $playerName)
	{
		// $champions = $this->neo4j->run('MATCH (p:Player)-[:PLAYED_CHAMPION]->(c:Champion) WHERE p.name = {name} RETURN c.name AS cname, COUNT(c) AS cnt', ['name' => $playerName])->getRecords();
		var_dump($this->neo4j->run('MATCH (n) RETURN COUNT(n)'));
		exit(1);

		if (count($champions) == false) {
			// Didn't play any champions
			return;
		}

		foreach ($records as $record) {
			var_dump($record);
			exit(1);
			$gameId = $record->value('gid');
		}
	}
}

$c = new PlayerStatistics();
// var_dump($c->getChampionBaseDataByName('Akali'));
var_dump($c->getMostPlayedChampions('Endscape'));
