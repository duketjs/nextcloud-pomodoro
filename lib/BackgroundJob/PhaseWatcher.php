<?php

declare(strict_types=1);

namespace OCA\Pomodoro\BackgroundJob;

use OCA\Pomodoro\Service\PhaseNotifier;
use OCA\Pomodoro\Service\SessionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Keeps sessions moving while nobody is looking at the app.
 *
 * The front-end drives the timer when it is open; when it is not, this notices
 * that a phase has run out, tells the user through the notification system, and
 * - if they asked for auto-transition - starts the next phase, exactly as the
 * page would have done.
 *
 * When a notification appears is bounded by how often the job runner wakes up,
 * not by the interval below: with the usual five-minute cron a phase that ends
 * is announced within about five minutes. Running cron more often tightens it.
 */
class PhaseWatcher extends TimedJob {
	private const INTERVAL_SECONDS = 60;

	public function __construct(
		ITimeFactory $time,
		private SessionService $sessionService,
		private PhaseNotifier $phaseNotifier,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);

		$this->setInterval(self::INTERVAL_SECONDS);
	}

	protected function run($argument): void {
		foreach ($this->sessionService->activeUserIds() as $uid) {
			try {
				$this->checkUser($uid);
			} catch (\Throwable $e) {
				// One broken session must not stop the others being checked.
				$this->logger->error('Pomodoro: could not check the phase for ' . $uid, ['exception' => $e]);
			}
		}
	}

	private function checkUser(string $uid): void {
		$session = $this->sessionService->getSession($uid);
		if ($session === null) {
			return;
		}
		if (($session['phase'] ?? SessionService::PHASE_DONE) === SessionService::PHASE_DONE) {
			return;
		}
		if (!($session['running'] ?? false)) {
			// Paused sessions do not move on by themselves.
			return;
		}

		$endsAt = $session['phaseEndsAt'] ?? null;
		if (!is_string($endsAt) || strtotime($endsAt) > $time = time()) {
			return;
		}

		// Tell the user once per phase, unless the front-end already did.
		if (($session['announcedPhase'] ?? null) !== ($session['phaseStartedAt'] ?? null)) {
			$this->phaseNotifier->announce($uid, $session);
			$this->sessionService->markAnnounced($uid);
		}

		// With auto-transition on, do what the open page would have done.
		if ($this->sessionService->getSettings($uid)['autoTransition']) {
			$this->sessionService->advance(false, $uid);
		}
	}
}
