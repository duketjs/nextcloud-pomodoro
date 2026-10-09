<?php

declare(strict_types=1);

namespace OCA\Pomodoro\Service;

use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserSession;

/**
 * Stores the running Pomodoro session and the user's timer preferences.
 *
 * The server is the source of truth for timing: a phase stores the wall-clock
 * instant it ends, not a countdown, so closing the tab or navigating away and
 * coming back resumes with the correct remaining time. The front-end only ever
 * renders `phaseEndsAt - now`.
 *
 * The cycle target is per item (see TaskConfigService), not global. How many
 * focus cycles have been finished or abandoned per item is also kept there.
 *
 * Storage is a JSON blob in the user's app config; there is exactly one active
 * session per user, which is all a focus timer needs.
 *
 * Every method takes an optional user id. Left out, it means "the logged-in
 * user", which is what the API uses; the background job passes one explicitly
 * because it runs with nobody logged in.
 */
class SessionService {
	private const APP_ID = 'pomodoro';
	private const KEY_SESSION = 'session';
	private const KEY_SETTINGS = 'settings';

	public const PHASE_FOCUS = 'focus';
	public const PHASE_BREAK = 'break';
	public const PHASE_DONE = 'done';

	private const DEFAULT_SETTINGS = [
		'focusMinutes' => 25,
		'breakMinutes' => 5,
		// Manual by default: when a phase ends the user starts the next one.
		'autoTransition' => false,
	];

	public function __construct(
		private IConfig $config,
		private IUserSession $userSession,
		private TaskConfigService $taskConfigService,
		private IDBConnection $db,
	) {
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function getSession(?string $uid = null): ?array {
		$uid = $this->resolveUid($uid);
		if ($uid === null) {
			return null;
		}

		$raw = $this->config->getUserValue($uid, self::APP_ID, self::KEY_SESSION, '');
		if ($raw === '') {
			return null;
		}

		$decoded = json_decode($raw, true);

		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * @return array{focusMinutes: int, breakMinutes: int, autoTransition: bool}
	 */
	public function getSettings(?string $uid = null): array {
		$uid = $this->resolveUid($uid);
		if ($uid === null) {
			return self::DEFAULT_SETTINGS;
		}

		$raw = $this->config->getUserValue($uid, self::APP_ID, self::KEY_SETTINGS, '');
		$decoded = $raw === '' ? null : json_decode($raw, true);
		if (!is_array($decoded)) {
			return self::DEFAULT_SETTINGS;
		}

		return [
			'focusMinutes' => $this->clamp((int)($decoded['focusMinutes'] ?? 0), 1, 180, self::DEFAULT_SETTINGS['focusMinutes']),
			'breakMinutes' => $this->clamp((int)($decoded['breakMinutes'] ?? 0), 1, 60, self::DEFAULT_SETTINGS['breakMinutes']),
			'autoTransition' => (bool)($decoded['autoTransition'] ?? self::DEFAULT_SETTINGS['autoTransition']),
		];
	}

	/**
	 * Persist the user's timer preferences.
	 *
	 * @return array{focusMinutes: int, breakMinutes: int, autoTransition: bool}
	 */
	public function updateSettings(int $focusMinutes, int $breakMinutes, bool $autoTransition, ?string $uid = null): array {
		$settings = [
			'focusMinutes' => $this->clamp($focusMinutes, 1, 180, self::DEFAULT_SETTINGS['focusMinutes']),
			'breakMinutes' => $this->clamp($breakMinutes, 1, 60, self::DEFAULT_SETTINGS['breakMinutes']),
			'autoTransition' => $autoTransition,
		];
		$this->saveSettings($settings, $uid);

		return $settings;
	}

	/**
	 * Start (or replace) the session for the given task.
	 *
	 * @param array{id: string, source: string, title: string, list: string} $task
	 * @return array<string, mixed>
	 */
	public function start(array $task, int $focusMinutes, int $breakMinutes, int $cyclesPerTask, ?string $uid = null): array {
		// autoTransition is not part of starting a session; carry the stored
		// preference over rather than resetting it.
		$settings = [
			'focusMinutes' => $this->clamp($focusMinutes, 1, 180, self::DEFAULT_SETTINGS['focusMinutes']),
			'breakMinutes' => $this->clamp($breakMinutes, 1, 60, self::DEFAULT_SETTINGS['breakMinutes']),
			'autoTransition' => $this->getSettings($uid)['autoTransition'],
		];
		$this->saveSettings($settings, $uid);

		$now = $this->now();
		$session = [
			'task' => $task,
			'focusMinutes' => $settings['focusMinutes'],
			'breakMinutes' => $settings['breakMinutes'],
			'cyclesPerTask' => $this->taskConfigService->normaliseCycles($cyclesPerTask),
			'cyclesCompleted' => 0,
			'phase' => self::PHASE_FOCUS,
			'phaseStartedAt' => $now,
			'phaseEndsAt' => $this->addMinutes($now, $settings['focusMinutes']),
			'running' => true,
			'pausedRemainingMs' => null,
			'announcedPhase' => null,
		];

		$this->save($session, $uid);

		return $session;
	}

	/**
	 * Move to the next phase, decided server-side.
	 *
	 * focus -> break (or done once the target cycle count is reached)
	 * break -> focus
	 *
	 * A focus phase that ends because its time ran out counts as a completed
	 * cycle for the item and moves the session towards its target. A focus
	 * phase the user abandons with Skip is recorded as skipped and does NOT
	 * count towards the target - abandoning a cycle is not finishing it.
	 * Skipping a break is not a cycle, so it changes no counters.
	 *
	 * Whether this happens by itself or when the user asks is driven by the
	 * autoTransition preference: the front-end calls this when the user presses
	 * the button, and the background job calls it when nobody is watching.
	 *
	 * @return array<string, mixed>|null
	 */
	public function advance(bool $skipped = false, ?string $uid = null): ?array {
		$session = $this->getSession($uid);
		if ($session === null) {
			return null;
		}

		$now = $this->now();
		$phase = $session['phase'] ?? self::PHASE_FOCUS;
		$taskId = (string)($session['task']['id'] ?? '');

		// The cycle target belongs to the item, so honour the *current* setting
		// rather than whatever it was when the session started - the user may
		// raise or lower it mid-session.
		$target = $this->taskConfigService->get($taskId, $uid)['cyclesPerTask'];
		$session['cyclesPerTask'] = $target;

		if ($phase === self::PHASE_FOCUS) {
			$completed = (int)($session['cyclesCompleted'] ?? 0);

			if ($skipped) {
				$this->taskConfigService->recordSkipped($taskId, $uid);
			} else {
				$completed++;
				$session['cyclesCompleted'] = $completed;
				$this->taskConfigService->recordCompleted($taskId, $uid);
			}

			if (!$skipped && $completed >= $target) {
				$session['phase'] = self::PHASE_DONE;
				$session['phaseStartedAt'] = $now;
				$session['phaseEndsAt'] = null;
				$session['running'] = false;
				$session['pausedRemainingMs'] = null;
			} else {
				$session['phase'] = self::PHASE_BREAK;
				$session['phaseStartedAt'] = $now;
				$session['phaseEndsAt'] = $this->addMinutes($now, (int)$session['breakMinutes']);
				$session['running'] = true;
				$session['pausedRemainingMs'] = null;
			}
		} elseif ($phase === self::PHASE_BREAK) {
			$session['phase'] = self::PHASE_FOCUS;
			$session['phaseStartedAt'] = $now;
			$session['phaseEndsAt'] = $this->addMinutes($now, (int)$session['focusMinutes']);
			$session['running'] = true;
			$session['pausedRemainingMs'] = null;
		}

		// The new phase has not been announced yet.
		$session['announcedPhase'] = null;

		$this->save($session, $uid);

		return $session;
	}

	/**
	 * Pause or resume the current phase, keeping the remaining time exact.
	 *
	 * @return array<string, mixed>|null
	 */
	public function setPaused(bool $paused, ?string $uid = null): ?array {
		$session = $this->getSession($uid);
		if ($session === null) {
			return null;
		}

		if ($session['phase'] === self::PHASE_DONE) {
			return $session;
		}

		$now = $this->now();

		if ($paused && ($session['running'] ?? false)) {
			$remainingMs = max(0, $this->toTimestamp((string)$session['phaseEndsAt']) - $this->toTimestamp($now)) * 1000;
			$session['running'] = false;
			$session['pausedRemainingMs'] = $remainingMs;
		} elseif (!$paused && !($session['running'] ?? false)) {
			$remainingMs = (int)($session['pausedRemainingMs'] ?? 0);
			$session['running'] = true;
			$session['pausedRemainingMs'] = null;
			$session['phaseEndsAt'] = gmdate('Y-m-d\TH:i:s\Z', $this->toTimestamp($now) + (int)round($remainingMs / 1000));
		}

		$this->save($session, $uid);

		return $session;
	}

	public function clear(?string $uid = null): void {
		$uid = $this->resolveUid($uid);
		if ($uid === null) {
			return;
		}

		$this->config->deleteUserValue($uid, self::APP_ID, self::KEY_SESSION);
	}

	/**
	 * Record that the current phase has already been announced, so the
	 * background job does not raise a second notification for it. The
	 * front-end calls this once it has shown its own pop-up.
	 */
	public function markAnnounced(?string $uid = null): void {
		$uid = $this->resolveUid($uid);
		if ($uid === null) {
			return;
		}

		$session = $this->getSession($uid);
		if ($session === null) {
			return;
		}

		$session['announcedPhase'] = $session['phaseStartedAt'] ?? null;
		$this->save($session, $uid);
	}

	/**
	 * Everyone who currently has a session stored.
	 *
	 * The background job has no logged-in user to work from, so it asks for the
	 * list and then reads each session by user id. This reads the preferences
	 * table directly for want of an API that enumerates user config values.
	 *
	 * @return list<string>
	 */
	public function activeUserIds(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('userid')
			->from('preferences')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter(self::APP_ID)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter(self::KEY_SESSION)));

		$ids = [];
		$result = $qb->executeQuery();
		while (($row = $result->fetch()) !== false) {
			$ids[] = (string)$row['userid'];
		}
		$result->closeCursor();

		return $ids;
	}

	/**
	 * @param array<string, mixed> $session
	 */
	private function save(array $session, ?string $uid = null): void {
		$uid = $this->resolveUid($uid);
		if ($uid === null) {
			return;
		}

		$this->config->setUserValue($uid, self::APP_ID, self::KEY_SESSION, json_encode($session, JSON_THROW_ON_ERROR));
	}

	/**
	 * @param array{focusMinutes: int, breakMinutes: int, autoTransition: bool} $settings
	 */
	private function saveSettings(array $settings, ?string $uid = null): void {
		$uid = $this->resolveUid($uid);
		if ($uid === null) {
			return;
		}

		$this->config->setUserValue($uid, self::APP_ID, self::KEY_SETTINGS, json_encode($settings, JSON_THROW_ON_ERROR));
	}

	private function resolveUid(?string $uid): ?string {
		return $uid ?? $this->userSession->getUser()?->getUID();
	}

	private function now(): string {
		return gmdate('Y-m-d\TH:i:s\Z');
	}

	private function addMinutes(string $iso, int $minutes): string {
		return gmdate('Y-m-d\TH:i:s\Z', $this->toTimestamp($iso) + ($minutes * 60));
	}

	private function toTimestamp(string $iso): int {
		$ts = strtotime($iso);

		return $ts === false ? time() : $ts;
	}

	private function clamp(int $value, int $min, int $max, int $fallback): int {
		if ($value < $min || $value > $max) {
			return $value <= 0 ? $fallback : max($min, min($max, $value));
		}

		return $value;
	}
}
