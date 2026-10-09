/**
 * SPDX-FileCopyrightText: 2026 Jokersmile
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * A task-like item normalised across the apps we import from.
 *
 * Deck cards and Tasks (CalDAV VTODO) objects have very different shapes; the
 * UI only ever sees this.
 */
export interface FocusItem {
	/** Globally unique across sources, e.g. `deck:12` or `tasks:<uid>` */
	id: string
	source: 'deck' | 'tasks'
	title: string
	/** ISO 8601 date string, or null when the item has no due date */
	due: string | null
	/** 1 (highest) .. 9 (lowest); null when unset */
	priority: number | null
	/** Source-specific status string, e.g. NEEDS-ACTION */
	status: string | null
	/** Where it came from, human readable: "Board / Stack" or the calendar name */
	list: string
}
