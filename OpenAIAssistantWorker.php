<?php

namespace App;

use Illuminate\Support\Facades\Storage;

class OpenAIAssistantWorker extends AIWorker
{

	const CREATE_THREAD_ACTION_ID = 'createThread';

	private \OpenAI\Client $client;
	private string $assistantId;
	private bool $addedCreateThreadAction = false;

	public function __construct(string $apiKey, string $assistantId)
	{
		$this->client = \OpenAI::client($apiKey);
		$this->assistantId = $assistantId;

		parent::__construct();
	}

	protected function createThread()
	{
		$id = 'createThread';
		$callable = function (AIWorkerResult $result, $next)
		{
			return $this->client->threads()->create([]);
		};
		$this->addAction($id, $callable);
	}

	public function uploadFile(string $id, string $filePath)
	{
		$callable = function (AIWorkerResult $result, $next) use ($filePath)
		{
			if (Storage::exists($filePath))
			{
				$response = $this->client->files()->upload([
					'purpose' => 'assistants',
					'file' => fopen(Storage::path($filePath), 'r'),
				]);

				return $response;
			}
			else
			{
				return 'Passed file does not exist.';
			}
		};

		$this->addAction($id, $callable);

		return $this;
	}

	public function withPreviousFileAttached(string $id, string $prompt)
	{
		$callable = function (AIWorkerResult $result, $next) use ($prompt)
		{
			$fileId = null;
			$fileUploadResponse = $result->getLastActionResult();
			if (is_object($fileUploadResponse) && $fileUploadResponse->object == 'file')
			{
				$fileId = $fileUploadResponse->id;
			}
			return $this->messageCallback($result, $next, $prompt, $fileId);
		};
		$this->addAction($id, $callable);

		return $this;
	}

	public function message(string $id, string $prompt)
	{
		$callable = function (AIWorkerResult $result, $next) use ($prompt)
		{
			return $this->messageCallback($result, $next, $prompt);
		};
		$this->addAction($id, $callable);

		return $this;
	}

	protected function addAction(string $id, Callable $callable)
	{
		if (! $this->addedCreateThreadAction)
		{
			$this->addedCreateThreadAction = true;
			$this->createThread();
		}

		parent::addAction($id, $callable);
	}

	private function waitForRunToComplete(string $runId, string $threadId)
	{
		$isCompleted = false;
		$waitInterval = 2;
		$maxTries = 10;
		$try = 1;

		while (! $isCompleted && $try <= $maxTries)
		{
 			$response = $this->client->threads()->runs()->retrieve(
				threadId: $threadId,
				runId: $runId,
			);

			$isCompleted = ! is_null($response->completedAt);
			$try++;
			sleep($waitInterval);
		}
	}

	private function getThreadLastMessage(string $threadId): ?string
	{
		$message = null;
		$response = $this->client->threads()->messages()->list($threadId, ['limit' => 1]);

		if ($response->object == 'list')
		{
			$message = (current($response->data))->content[0]->text->value;
		}

		return $message;
	}

	private function messageCallback(AIWorkerResult $result, $next, string $prompt, ?string $fileId = null)
	{
		$createThreadResult = $result->getActionResult(self::CREATE_THREAD_ACTION_ID);
		if ($createThreadResult)
		{
			$threadId = $createThreadResult->id;
			$parameters = [
				'role' => 'user',
				'content' => $prompt,
			];

			if ($fileId)
			{
				$parameters['attachments'] = [['file_id' => $fileId, 'tools' => [['type' => 'file_search']]]];
			}

			$response = $this->client->threads()->messages()->create($threadId, $parameters);

			if ($response->id)
			{
				$runResponse = $this->client->threads()->runs()->create(
					threadId: $threadId,
					parameters: ['assistant_id' => $this->assistantId],
				);

				$this->waitForRunToComplete($runResponse->id, $threadId);
				return $this->getThreadLastMessage($threadId);
			}
		}
		return '';
	}

}
