<?php

namespace Src;

require_once __DIR__.'/../vendor/autoload.php';

use GraphAware\Neo4j\Client\ClientBuilder;

class ChampionWeight
{
	private $weightData;
	private $weightDataBySubclass;

	function __construct(?string $weightPath = null)
	{
		if (!$weightPath) {
			$weightPath = __DIR__.'/../Static/champion_type_weights.json';
		}

		$this->weightData = json_decode(file_get_contents($weightPath));
		$this->weightDataBySubclass = new \stdClass();

		foreach ($this->weightData as $className => $subclasses) {
			foreach ($subclasses as $subclassName => $subclassData) {
				$this->weightDataBySubclass->$subclassName = $subclassData;
			}
		}
	}

	public function getWeights(array $subclasses, array $subclassRatios)
	{
		// If we only have one subclass, the ratio must be 1, so we can just return the data
		if (count($subclasses) < 2) {
			$accessor = $subclasses[0];
			return $this->weightDataBySubclass->$accessor;
		}

		$calculatedWeights = [];

		foreach ($subclasses as $index => $name) {
			$weightCategories = $this->weightDataBySubclass->$name;

			$ratio = $subclassRatios[$index];

			foreach ($weightCategories as $categoryName => $categoryData) {
				$keys = get_object_vars($categoryData);

				foreach ($keys as $key => $val) {
					$categoryData->$key = $categoryData->$key * $ratio;
				}
			}

			$calculatedWeights[] = $weightCategories;
		}

		$combinedWeights = null;
		$first = true;
		foreach ($calculatedWeights as $weights) {
			if ($first) {
				$combinedWeights = $weights;
				$first = false;
				continue;
			}

			foreach ($weights as $categoryName => $categoryData) {
				$keys = get_object_vars($categoryData);

				foreach ($keys as $key => $val) {
					$combinedWeights->$categoryName->$key = $combinedWeights->$categoryName->$key + $categoryData->$key;
				}
			}
		}

		return $combinedWeights;
	}

	public function getChampionSubclasses(): array
	{
		$classes = [];

		foreach ($this->weightDataBySubclass as $className => $subclasses) {
			$classes[] = $className;
		}

		return $classes;
	}

	public function calculatePlayerWeightForSubclass(string $className, object $playerWeights): int
	{
		$subclassWeights = $this->getWeights([$className], [1]);
		$keys = get_object_vars($subclassWeights);

		$subclassWeight = 0;
		foreach ($keys as $key => $val) {
			$player = $playerWeights->$key;
			$subclass = $subclassWeights->$key;

			$attrWeight = 3 * (5 - abs($player->Priority - $subclass->Priority));
			$part = 5;
			foreach (get_object_vars($player) as $keyName => $value) {
				if ($keyName === 'Priority') {
					continue;
				}

				$part -= 0.5 * abs($player->$keyName - $subclass->$keyName);
			}

			$attrWeight = ($attrWeight + $part) * $player->Priority;
			$subclassWeight += $attrWeight;
		}

		return $subclassWeight;
	}
}
