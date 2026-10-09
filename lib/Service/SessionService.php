<?php

declare(strict_types=1);

namespace OCA\Pomodoro\Service;

use OCP\IConfig;
use OCP\IUserSession;

/**
 * Stores the running Pomodoro session and the user's timer preferences.
 *
 * The server is the source of truth for timing: a phase stores the wall-clock
 * instant it ends, not a countdown, so closing the tab or navigating away and
 * coming back resumes with the correct remaining time. The front-end only ever
 * renders `phaseEndsAt - now`.
 *
 * Storage is a JSON blob in the user's app config; there is exactly one active
 * session per user, which is all a focus timer needs.
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
		'cyclesPerTask' => 4,
	];

	public function __construct(
		private IConfig $config,
		private IUserSession $userSession,
	) {
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function getSession(): ?array {
		$uid = $this->uid();
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
	 * @return array{focusMinutes: int, breakMinutes: int, cyclesPerTask: int}
	 */
	public function getSettings(): array {
		$uid = $this->uid();
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
			'cyclesPerTask' => $this->clamp((int)($decoded['cyclesPerTask'] ?? 0), 1, 24, self::DEFAULT_SETTINGS['cyclesPerTask']),
		];
	}

	/**
	 * Start (or replace) the session for the given task.
	 *
	 * @param array{id: string, source: string, title: string, list: string} $task
	 * @return array<string, mixed>
	 */
	public function start(array $task, int $focusMinutes, int $breakMinutes, int $cyclesPerTask): array {
		$settings = [
			'focusMinutes' => $this->clamp($focusMinutes, 1, 180, self::DEFAULT_SETTINGS['focusMinutes']),
			'breakMinutes' => $this->clamp($breakMinutes, 1, 60, self::DEFAULT_SETTINGS['breakMinutes']),
			'cyclesPerTask' => $this->clamp($cyclesPerTask, 1, 24, self::DEFAULT_SETTINGS['cyclesPerTask']),
		];
		$this->saveSettings($settings);

		$now = $this->now();
		$session = [
			'task' => $task,
			'focusMinutes' => $settings['focusMinutes'],
			'breakMinutes' => $settings['breakMinutes'],
			'cyclesPerTask' => $settings['cyclesPerTask'],
			'cyclesCompleted' => 0,
			'phase' => self::PHASE_FOCUS,
			'phaseStartedAt' => $now,
			'phaseEndsAt' => $this->addMinutes($now, $settings['focusMinutes']),
			'running' => true,
			'pausedRemainingMs' => null,
		];

		$this->save($session);

		return $session;
	}

	/**
	 * Move to the next phase, decided server-side.
	 *
	 * focus -> break (or done once the target cycle count is reached)
	 * break -> focus
	 *
	 * @return array<string, mixed>|null
	 */
	public function advance(): ?array {
		$session = $this->getSession();
		if ($session === null) {
			return null;
		}

		$now = $this->now();
		$phase = $session['phase'] ?? self::PHASE_FOCUS;

		if ($phase === self::PHASE_FOCUS) {
			$completed = (int)($session['cyclesCompleted'] ?? 0) + 1;
			$session['cyclesCompleted'] = $completed;

			if ($completed >= (int)($session['cyclesPerTask'] ?? 1)) {
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

		$this->save($session);

		return $session;
	}

	/**
	 * Pause or resume the current phase, keeping the remaining time exact.
	 *
	 * @return array<string, mixed>|null
	 */
	public function setPaused(bool $paused): ?array {
		$session = $this->getSession();
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

		$this->save($session);

		return $session;
	}

	public function clear(): void {
		$uid = $this->uid();
		if ($uid === null) {
			return;
		}

		$this->config->deleteUserValue($uid, self::APP_ID, self::KEY_SESSION);
	}

	/**
	 * @param array<string, mixed> $session
	 */
	private function save(array $session): void {
		$uid = $this->uid();
		if ($uid === null) {
			return;
		}

		$this->config->setUserValue($uid, self::APP_ID, self::KEY_SESSION, json_encode($session, JSON_THROW_ON_ERROR));
	}

	/**
	 * @param array{focusMinutes: int, breakMinutes: int, cyclesPerTask: int} $settings
	 */
	private function saveSettings(array $settings): void {
		$uid = $this->uid();
		if ($uid === null) {
			return;
		}

		$this->config->setUserValue($uid, self::APP_ID, self::KEY_SETTINGS, json_encode($settings, JSON_THROW_ON_ERROR));
	}

	private function uid(): ?string {
		return $this->userSession->getUser()?->getUID();
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
