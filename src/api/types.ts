/**
 * SPDX-FileCopyrightText: 2026 Jokersmile
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * An item that can be focused on, normalised away from the shape of whatever
 * produced it. Deck is the only source at the moment.
 */
export interface FocusItem {
	/** Globally unique, e.g. `deck:12` */
	id: string
	source: 'deck'
	title: string
	/** ISO 8601 date string, or null when the item has no due date */
	due: string | null
	/** 1 (highest) .. 9 (lowest); null when unset */
	priority: number | null
	/** Source-specific status string */
	status: string | null
	/** Where it came from, human readable: "Board / Stack" */
	list: string
}
