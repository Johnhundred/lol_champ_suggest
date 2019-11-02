<?php

namespace Src;

require_once __DIR__.'/../vendor/autoload.php';

use GraphAware\Neo4j\Client\ClientBuilder;
use Src\ChampionWeight;

class ChampionData
{
	private $typeData;
	private $typeDataById;

	function __construct(string $typePath)
	{
		$this->typeData = json_decode(file_get_contents($typePath));
		$this->typeDataById = new \stdClass();
		foreach ($this->typeData as $name => $data) {
			$id = $data->id;
			$this->typeDataById->$id = $data;
			$this->typeDataById->$id->name = $name;
		}
	}

	public function getChampionWeightsByName(string $championName)
	{
		$data = $this->typeData->$championName;
		$subclasses = $data->subclasses;
		$subclassRatios = $data->subclass_ratios;
		$weight = new ChampionWeight();

		return $weight->getWeights($subclasses, $subclassRatios);
	}

	public function getChampionWeightsById(int $championId)
	{
		$data = $this->typeDataById->$championId;
		$subclasses = $data->subclasses;
		$subclassRatios = $data->subclass_ratios;
		$weight = new ChampionWeight();

		return $weight->getWeights($subclasses, $subclassRatios);
	}

	public function getChampionBaseDataByName(string $championName)
	{
		return $this->typeData->$championName;
	}

	public function getChampionBaseDataById(int $championId)
	{
		return $this->typeDataById->$championId;
	}
}

$c = new ChampionData(__DIR__.'/../Static/champion_types.json');
// var_dump($c->getChampionBaseDataByName('Akali'));
var_dump($c->getChampionWeightsById(517));
