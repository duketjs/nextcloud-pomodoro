<?php

declare(strict_types=1);

namespace OCA\Pomodoro\Controller;

use OCA\Pomodoro\Service\SessionService;
use OCA\Pomodoro\Service\TaskService;
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
		private TaskService $taskService,
		private SessionService $sessionService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Outstanding tasks imported from the Tasks app (CalDAV VTODO objects).
	 *
	 * @return DataResponse<Http::STATUS_OK, array{tasks: list<array<string, mixed>>}, array{}>
	 *
	 * 200: Tasks returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/tasks')]
	public function tasks(): DataResponse {
		return new DataResponse([
			'tasks' => $this->taskService->getOpenTasks(),
		]);
	}

	/**
	 * The running focus session, if any, plus the user's saved timer settings.
	 *
	 * @return DataResponse<Http::STATUS_OK, array{session: array<string, mixed>|null, settings: array<string, mixed>}, array{}>
	 *
	 * 200: Session returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/session')]
	public function getSession(): DataResponse {
		return new DataResponse([
			'session' => $this->sessionService->getSession(),
			'settings' => $this->sessionService->getSettings(),
		]);
	}

	/**
	 * Start a focus session for a task.
	 *
	 * @return DataResponse<Http::STATUS_CREATED, array{session: array<string, mixed>, settings: array<string, mixed>}, array{}>
	 *
	 * 201: Session started
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/session')]
	public function startSession(
		string $taskId,
		string $taskSource = 'tasks',
		string $taskTitle = '',
		string $taskList = '',
		int $focusMinutes = 25,
		int $breakMinutes = 5,
		int $cyclesPerTask = 4,
	): DataResponse {
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
	 * Advance the session to the next phase (focus -> break -> ... -> done).
	 *
	 * @return DataResponse<Http::STATUS_OK, array{session: array<string, mixed>|null}, array{}>
	 *
	 * 200: Phase advanced
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/session/phase')]
	public function advanceSession(): DataResponse {
		return new DataResponse([
			'session' => $this->sessionService->advance(),
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
	 * Clear the running session.
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
