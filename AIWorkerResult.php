<?php

namespace App;

class AIWorkerResult
{

	private array $actionsResult;

	public function setActionResult(string $id, mixed $result)
	{
		$this->actionsResult[$id] = $result;
	}

	public function getActionResult(string $id): mixed
	{
		return $this->actionsResult[$id] ?? null;
	}

	public function getLastActionResult(): mixed
	{
		return end($this->actionsResult);
	}

}
