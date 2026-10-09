<?php

declare(strict_types=1);

namespace OCA\Pomodoro\Controller;

use OCA\Pomodoro\Service\SessionService;
use OCA\Pomodoro\Service\TaskConfigService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * @psalm-suppress UnusedClass
 */
class ApiController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private SessionService $sessionService,
		private TaskConfigService $taskConfigService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Everything the UI needs on load: the running session (if any), the timer
	 * settings, and the per-item configuration and cycle counters.
	 *
	 * The items to focus on are read from Deck by the front-end directly.
	 *
	 * @return DataResponse<Http::STATUS_OK, array{session: array<string, mixed>|null, settings: array<string, mixed>, tasks: array<string, mixed>}, array{}>
	 *
	 * 200: State returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/state')]
	public function state(): DataResponse {
		return new DataResponse([
			'session' => $this->sessionService->getSession(),
			'settings' => $this->sessionService->getSettings(),
			'tasks' => $this->taskConfigService->getAll(),
		]);
	}

	/**
	 * Save the user's timer preferences.
	 *
	 * @return DataResponse<Http::STATUS_OK, array{settings: array<string, mixed>}, array{}>
	 *
	 * 200: Settings saved
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/settings')]
	public function updateSettings(
		int $focusMinutes = 25,
		int $breakMinutes = 5,
		bool $autoTransition = false,
	): DataResponse {
		return new DataResponse([
			'settings' => $this->sessionService->updateSettings($focusMinutes, $breakMinutes, $autoTransition),
		]);
	}

	/**
	 * Start a focus session for an item.
	 *
	 * @return DataResponse<Http::STATUS_CREATED, array{session: array<string, mixed>, settings: array<string, mixed>}, array{}>
	 *
	 * 201: Session started
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/session')]
	public function startSession(
		string $taskId,
		string $taskSource = 'deck',
		string $taskTitle = '',
		string $taskList = '',
		int $focusMinutes = 25,
		int $breakMinutes = 5,
	): DataResponse {
		// How many cycles this item takes is a property of the item, not a
		// global setting; it defaults to 1 until the user says otherwise.
		$cyclesPerTask = $this->taskConfigService->get($taskId)['cyclesPerTask'];

		$session = $this->sessionService->start(
			[
				'id' => $taskId,
				'source' => $taskSource,
				'title' => $taskTitle,
				'list' => $taskList,
			],
			$focusMinutes,
			$breakMinutes,
			$cyclesPerTask,
		);

		return new DataResponse([
			'session' => $session,
			'settings' => $this->sessionService->getSettings(),
		], Http::STATUS_CREATED);
	}

	/**
	 * Set how many focus cycles an item is expected to take.
	 *
	 * @return DataResponse<Http::STATUS_OK, array{tasks: array<string, mixed>}, array{}>
	 *
	 * 200: Configuration saved
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/task-config')]
	public function setTaskConfig(string $taskId, int $cyclesPerTask): DataResponse {
		$this->taskConfigService->setCycles($taskId, $cyclesPerTask);

		return new DataResponse([
			'tasks' => $this->taskConfigService->getAll(),
		]);
	}

	/**
	 * Advance the session to the next phase (focus -> break -> ... -> done).
	 *
	 * @param bool $skipped true when the user abandoned the phase rather than
	 *                      letting it run out; a skipped focus phase is counted
	 *                      as skipped for the item and does not move the
	 *                      session towards its cycle target.
	 *
	 * @return DataResponse<Http::STATUS_OK, array{session: array<string, mixed>|null, tasks: array<string, mixed>}, array{}>
	 *
	 * 200: Phase advanced
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/session/phase')]
	public function advanceSession(bool $skipped = false): DataResponse {
		return new DataResponse([
			'session' => $this->sessionService->advance($skipped),
			'tasks' => $this->taskConfigService->getAll(),
		]);
	}

	/**
	 * Pause or resume the current phase.
	 *
	 * @return DataResponse<Http::STATUS_OK, array{session: array<string, mixed>|null}, array{}>
	 *
	 * 200: Pause state updated
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/session/pause')]
	public function pauseSession(bool $paused = true): DataResponse {
		return new DataResponse([
			'session' => $this->sessionService->setPaused($paused),
		]);
	}

	/**
	 * Clear the running session. Cycle counters are history and are kept.
	 *
	 * @return DataResponse<Http::STATUS_OK, array{cleared: bool}, array{}>
	 *
	 * 200: Session cleared
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/session')]
	public function clearSession(): DataResponse {
		$this->sessionService->clear();

		return new DataResponse(['cleared' => true]);
	}
}
