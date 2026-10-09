/**
 * SPDX-FileCopyrightText: 2026 Jokersmile
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { fetchDeckBoardItems } from './deck'
import type { FocusItem } from './types'

/**
 * Collect the outstanding items of a single board.
 *
 * Deck is currently the only source; the adapter swallows its own errors and
 * returns [] so a missing or disabled Deck never breaks the page, and adding
 * another source later is just another call here.
 */
export async function fetchFocusItems(boardId: number): Promise<FocusItem[]> {
	const items = await fetchDeckBoardItems(boardId)

	return items.sort(byDueThenTitle)
}

/**
 * Sort with the most urgent first: dated items before undated ones, then by
 * due date, then by title so the order is stable.
 */
function byDueThenTitle(a: FocusItem, b: FocusItem): number {
	if (a.due && !b.due) {
		return -1
	}
	if (!a.due && b.due) {
		return 1
	}
	if (a.due && b.due && a.due !== b.due) {
		return a.due < b.due ? -1 : 1
	}

	return a.title.localeCompare(b.title)
}
