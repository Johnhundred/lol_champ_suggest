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
}
