<?php

namespace Src;

require_once __DIR__.'/../vendor/autoload.php';

use GraphAware\Neo4j\Client\ClientBuilder;
use Src\ChampionData;
use Src\ChampionWeight;

class PlayerStatistics
{
	private $neo4j;

	public function __construct()
	{
		$this->neo4j = ClientBuilder::create()
		    ->addConnection('default', 'bolt://'.getenv('NEO4J_USER').':'.getenv('NEO4J_PASSWORD').'@localhost:7687') // port is optional
		    ->build();
	}

	public function getMostPlayedChampions(string $playerName)
	{
		$records = $this->neo4j->run('MATCH (p:Player)-[:PLAYED_CHAMPION]->(c:Champion) WHERE p.riot_name = {name} RETURN c.name AS cname, COUNT(c) AS cnt ORDER BY cnt DESC', ['name' => $playerName])->getRecords();

		if (count($records) == false) {
			// Didn't play any champions
			return;
		}

		$champions = [];
		foreach ($records as $record) {
			$champions[] = [$record->value('cname'), $record->value('cnt')];
		}

		return $champions;
	}

	public function getAveragePlayerWeighting(string $playerName)
	{
		$champions = $this->getMostPlayedChampions($playerName);

		$weightsWithTallies = [];
		foreach ($champions as $champion) {
			$name = $champion[0];

			$cd = new ChampionData();
			$championWeight = $cd->getChampionWeightsByName($name);
			$weightsWithTallies[] = [$cd->getChampionWeightsByName($name), $champion[1]];
		}

		return $this->combineWeights($weightsWithTallies);
	}

	private function combineWeights(array $weightsWithTallies)
	{
		$first = true;
		$tally = 0;
		$combinedWeights = null;

		foreach ($weightsWithTallies as $data) {
			$weight = $data[0];
			$count = $data[1];
			$tally += $count;

			foreach ($weight as $categoryName => $categoryData) {
				$keys = get_object_vars($categoryData);

				foreach ($keys as $key => $val) {
					$categoryData->$key = $categoryData->$key * $count;
				}
			}

			if ($first) {
				$first = false;
				$combinedWeights = $weight;
				continue;
			}

			foreach ($weight as $categoryName => $categoryData) {
				$keys = get_object_vars($categoryData);

				foreach ($keys as $key => $val) {
					$combinedWeights->$categoryName->$key = $combinedWeights->$categoryName->$key + $categoryData->$key;
				}
			}
		}

		foreach ($combinedWeights as $categoryName => $categoryData) {
			$keys = get_object_vars($categoryData);

			foreach ($keys as $key => $val) {
				$combinedWeights->$categoryName->$key = round(($combinedWeights->$categoryName->$key / $tally), 2);
			}
		}

		return $combinedWeights;
	}

	public function getPlayerRecommendedClasses(string $playerName)
	{
		$playerWeights = $this->getAveragePlayerWeighting($playerName);

		$recommendedClasses = $this->getRecommendedClasses($playerWeights);

		$result = [];
		foreach ($recommendedClasses as $value) {
			$result[] = $value[0];
		}

		return $result;
	}

	private function getRecommendedClasses(object $playerWeights): array
	{
		$subclassesWithWeight = [];
		$cw = new ChampionWeight();
		$subclasses = $cw->getChampionSubclasses();

		foreach ($subclasses as $className) {
			$subclassesWithWeight[] = [$className, $this->calculateWeightForSubclass($className, $playerWeights)];
		}

		usort($subclassesWithWeight, function ($a, $b) {
			return ($a[1] <= $b[1]);
		});

		return $subclassesWithWeight;
	}

	private function calculateWeightForSubclass(string $className, object $playerWeights): int
	{
		$cw = new ChampionWeight();
		return $cw->calculatePlayerWeightForSubclass($className, $playerWeights);
	}
}

$c = new PlayerStatistics();
// var_dump($c->getChampionBaseDataByName('Akali'));
var_dump($c->getPlayerRecommendedClasses('Endscape'));
