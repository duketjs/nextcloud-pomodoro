/**
 * SPDX-FileCopyrightText: 2026 Jokersmile
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import type { FocusItem } from './types'

/**
 * Deck's OCS API. Deck's own web UI uses exactly these endpoints, so this is
 * the sanctioned way in and it reuses the browser session - no credentials of
 * our own are needed.
 */
const API_BASE = '/ocs/v2.php/apps/deck/api/v1.0'

interface DeckBoard {
	id: number
	title: string
	archived?: boolean
}

interface DeckCard {
	id: number
	title: string
	description?: string
	duedate?: string | null
	/** Set once the card has been moved to the board's done stack */
	done?: string | null
	archived?: boolean
	stackId: number
	order?: number
}

interface DeckStack {
	id: number
	title: string
	cards?: DeckCard[]
}

/**
 * Fetch outstanding Deck cards from every non-archived board.
 *
 * "Outstanding" means: the card itself is not archived and it has not been
 * marked done. Returns [] when Deck is not installed/enabled or has no boards,
 * so a missing Deck never breaks the focus list.
 */
export async function fetchDeckItems(): Promise<FocusItem[]> {
	let boards: DeckBoard[]
	try {
		const response = await axios.get(`${API_BASE}/boards`)
		boards = response.data?.ocs?.data ?? []
	} catch (error) {
		// Deck missing/disabled, or the user has no access to any board.
		return []
	}

	const items: FocusItem[] = []
	for (const board of boards) {
		if (board.archived) {
			continue
		}

		let stacks: DeckStack[]
		try {
			const response = await axios.get(`${API_BASE}/stacks/${board.id}`)
			stacks = response.data?.ocs?.data ?? []
		} catch (error) {
			continue
		}

		for (const stack of stacks) {
			for (const card of stack.cards ?? []) {
				if (card.archived || card.done) {
					continue
				}

				items.push({
					id: `deck:${card.id}`,
					source: 'deck',
					title: card.title,
					due: card.duedate ?? null,
					priority: null,
					status: 'NEEDS-ACTION',
					list: `${board.title} / ${stack.title}`,
				})
			}
		}
	}

	return items
}
