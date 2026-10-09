/**
 * SPDX-FileCopyrightText: 2026 Jokersmile
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { fetchDeckItems } from './deck'
import { fetchTaskItems } from './tasks'
import type { FocusItem } from './types'

/**
 * Collect outstanding items from all supported sources.
 *
 * The two sources are fetched independently and a failure in one never hides
 * the other; that is why each adapter swallows its own errors and returns [].
 */
export async function fetchFocusItems(): Promise<FocusItem[]> {
	const [tasks, deck] = await Promise.all([
		fetchTaskItems(),
		fetchDeckItems(),
	])

	return [...tasks, ...deck].sort(byDueThenTitle)
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
