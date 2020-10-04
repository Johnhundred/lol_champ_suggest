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
    	->addConnection('default', 'http://'.getenv('NEO4J_USER').':'.getenv('NEO4J_PASSWORD').'@localhost:7474')
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

	public function getPlayerRecommendedChampions(string $playerName, ?int $listLength = 10)
	{
		$playerWeights = $this->getAveragePlayerWeighting($playerName);
		$recommendedClass = $this->getPlayerRecommendedClasses($playerName)[0];

		$cd = new ChampionData();
		$championNames = $cd->getChampionsWithSubclass($recommendedClass);

		$championsByNameWithWeights = [];
		foreach ($championNames as $name) {
			$championsByNameWithWeights[$name] = $cd->getChampionWeightsByName($name);
		}

		$championsByNameWithScore = [];
		foreach ($championsByNameWithWeights as $name => $weights) {
			$championsByNameWithScore[$name] = $this->getChampionWeightDifferenceFromPlayerWeights($playerWeights, $weights);
		}

		asort($championsByNameWithScore);
		return array_slice($championsByNameWithScore, 0, $listLength);
	}

	private function getChampionWeightDifferenceFromPlayerWeights($playerWeights, $championWeights)
	{
		$score = 0;
		foreach ($playerWeights as $category => $obj) {
			$score += abs($obj->Priority - $championWeights->$category->Priority);
			if (property_exists($obj, 'DPS') && property_exists($obj, 'Burst')) {
				$score += abs($obj->DPS - $championWeights->$category->DPS);
				$score += abs($obj->Burst - $championWeights->$category->Burst);
			}
			if (property_exists($obj, 'Mitigation') && property_exists($obj, 'Sustain')) {
				$score += abs($obj->Mitigation - $championWeights->$category->Mitigation);
				$score += abs($obj->Sustain - $championWeights->$category->Sustain);
			}
			if (property_exists($obj, 'Soft') && property_exists($obj, 'Hard')) {
				$score += abs($obj->Soft - $championWeights->$category->Soft);
				$score += abs($obj->Hard - $championWeights->$category->Hard);
			}
			if (property_exists($obj, 'Engage') && property_exists($obj, 'Reposition')) {
				$score += abs($obj->Engage - $championWeights->$category->Engage);
				$score += abs($obj->Reposition - $championWeights->$category->Reposition);
			}
			if (property_exists($obj, 'Defensive') && property_exists($obj, 'Offensive')) {
				$score += abs($obj->Defensive - $championWeights->$category->Defensive);
				$score += abs($obj->Offensive - $championWeights->$category->Offensive);
			}
		}
		return $score;
	}

	public function getPlayerRecommendedChampionsFromChampionNames(array $championNames, ?int $listLength = 10)
	{
		$result = [];
		$cd = new ChampionData();
		foreach ($championNames as $name) {
			$championWeights = $cd->getChampionWeightsByName($name);
			$recommendedClass = $this->getRecommendedClassesFromWeights($championWeights)[0];
			$championNames = $cd->getChampionsWithSubclass($recommendedClass);

			$championsByNameWithWeights = [];
			foreach ($championNames as $name) {
				$championsByNameWithWeights[$name] = $cd->getChampionWeightsByName($name);
			}

			$championsByNameWithScore = [];
			foreach ($championsByNameWithWeights as $name => $weights) {
				$championsByNameWithScore[$name] = $this->getChampionWeightDifferenceFromPlayerWeights($championWeights, $weights);
			}

			asort($championsByNameWithScore);
			if (!array_key_exists($recommendedClass, $result)) {
				$result[$recommendedClass] = array_slice($championsByNameWithScore, 0, $listLength);
				continue;
			}
			$result[$recommendedClass] = array_merge($result[$recommendedClass], array_slice($championsByNameWithScore, 0, $listLength));
		}
		return $result;
	}

	public function getRecommendedClassesFromWeights(object $weights)
	{
		$recommendedClasses = $this->getRecommendedClasses($weights);

		$result = [];
		foreach ($recommendedClasses as $value) {
			$result[] = $value[0];
		}

		return $result;
	}
}

$c = new PlayerStatistics();
// var_dump($c->getChampionBaseDataByName('Akali'));
// var_dump($c->getPlayerRecommendedClasses('Razorleaf'));
// var_dump($c->getAveragePlayerWeighting('Razorleaf'));
// var_dump($c->getPlayerRecommendedChampions('Razorleaf'));
var_dump($c->getPlayerRecommendedChampionsFromChampionNames(['Urgot', 'Illaoi', 'Sett']));
