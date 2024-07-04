<?php

namespace App;

use Illuminate\Pipeline\Pipeline;

abstract class AIWorker
{

	private Pipeline $actions;

	public function __construct()
	{
		$this->actions = new Pipeline();
	}

	abstract public function uploadFile(string $id, string $filePath);
	abstract public function withPreviousFileAttached(string $id, string $prompt);
	abstract public function message(string $id, string $prompt);

	public function handleResult(string $id, Callable $callable)
	{
		$this->addAction($id, $callable);

		return $this;
	}

	protected function addAction(string $id, Callable $callable)
	{
		$this->actions->pipe(function (AIWorkerResult $result, $nextAction) use ($id, $callable)
		{
			$actionResult = $callable($result, $nextAction);
			if ($actionResult)
			{
				$result->setActionResult($id, $actionResult);
			}

			return $nextAction($result);
		});
	}

	public function execute(?AIWorkerResult $workerResult = null): AIWorkerResult
	{
		if (! $workerResult)
		{
			$workerResult = new AIWorkerResult();
		}

		return $this->actions->send($workerResult)->thenReturn();
	}

	public function clearAction()
	{
		$this->actions = new Pipeline();
	}

}
