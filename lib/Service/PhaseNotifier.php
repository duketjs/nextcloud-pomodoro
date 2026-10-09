<?php

declare(strict_types=1);

namespace OCA\Pomodoro\Service;

use OCA\Pomodoro\AppInfo\Application;
use OCA\Pomodoro\Notification\Notifier;
use OCP\Notification\IManager as NotificationManager;

/**
 * Raises the Nextcloud notification for a phase that has run out.
 *
 * This is the server-side twin of the browser pop-up: it exists so the user is
 * told even when the app is not open. Which of the two fires is settled by the
 * front-end marking the phase as announced once it has shown its own pop-up -
 * see SessionService::markAnnounced().
 */
class PhaseNotifier {
	public function __construct(
		private NotificationManager $notificationManager,
	) {
	}

	/**
	 * @param array<string, mixed> $session the session whose phase just ended
	 */
	public function announce(string $uid, array $session): void {
		$phase = (string)($session['phase'] ?? '');
		$task = (string)($session['task']['title'] ?? '');
		$completed = (int)($session['cyclesCompleted'] ?? 0);
		$target = (int)($session['cyclesPerTask'] ?? 1);

		// Mirrors the front-end: the focus phase that meets the cycle target is
		// the one that finishes the session.
		if ($phase === SessionService::PHASE_BREAK) {
			$subject = Notifier::SUBJECT_REST_OVER;
			$parameters = ['task' => $task];
		} elseif ($phase === SessionService::PHASE_FOCUS && $completed + 1 >= $target) {
			$subject = Notifier::SUBJECT_ALL_CYCLES_DONE;
			$parameters = ['task' => $task];
		} else {
			$subject = Notifier::SUBJECT_FOCUS_FINISHED;
			$parameters = ['minutes' => (int)($session['breakMinutes'] ?? 0)];
		}

		$notification = $this->notificationManager->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($uid)
			->setDateTime(new \DateTime())
			->setObject('session', (string)($session['task']['id'] ?? '0'))
			->setSubject($subject, $parameters);

		$this->notificationManager->notify($notification);
	}
}
