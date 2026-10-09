/**
 * SPDX-FileCopyrightText: 2026 Jokersmile
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Desktop pop-ups for the end of a focus or rest period.
 *
 * These are the browser's own notifications, which come with two constraints
 * worth knowing:
 *
 *  - they only work in a secure context, so the app must be reached over HTTPS
 *    (or localhost). Over plain HTTP the browser refuses permission outright,
 *    which is the case on a dev instance served as http://<host>.local;
 *  - they are raised by the page, so the tab has to be open. Reaching someone
 *    whose browser is closed would need the timing to live on the server.
 */

export function notificationsSupported(): boolean {
	return typeof window !== 'undefined'
		&& 'Notification' in window
		&& window.isSecureContext
}

/**
 * Ask for permission to show pop-ups.
 *
 * Browsers require this to come from a user gesture, so it is called when a
 * focus session is started rather than on load.
 */
export async function ensureNotificationPermission(): Promise<boolean> {
	if (!notificationsSupported()) {
		return false
	}
	if (Notification.permission === 'granted') {
		return true
	}
	if (Notification.permission === 'denied') {
		return false
	}

	try {
		return (await Notification.requestPermission()) === 'granted'
	} catch (error) {
		return false
	}
}

/**
 * Show a pop-up. Silently does nothing when pop-ups are unavailable or have not
 * been allowed, so a missing permission never breaks the timer.
 */
export function notify(title: string, body: string): void {
	if (!notificationsSupported() || Notification.permission !== 'granted') {
		return
	}

	try {
		new Notification(title, { body })
	} catch (error) {
		// A failed pop-up must never take the timer down.
	}
}
