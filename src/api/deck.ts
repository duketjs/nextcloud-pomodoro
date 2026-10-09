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

export interface DeckBoard {
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
 * The boards the user can see, excluding archived ones.
 *
 * Returns [] when Deck is not installed/enabled or the user has no boards, so a
 * missing Deck never breaks the page.
 */
export async function fetchDeckBoards(): Promise<DeckBoard[]> {
	try {
		const response = await axios.get(`${API_BASE}/boards`)
		const boards: DeckBoard[] = response.data?.ocs?.data ?? []

		return boards.filter((board) => !board.archived)
	} catch (error) {
		return []
	}
}

/**
 * Outstanding cards of a single board.
 *
 * "Outstanding" means: the card itself is not archived and it has not been
 * marked done (which Deck does for cards sitting in the board's done stack).
 */
export async function fetchDeckBoardItems(boardId: number): Promise<FocusItem[]> {
	let stacks: DeckStack[]
	try {
		const response = await axios.get(`${API_BASE}/stacks/${boardId}`)
		stacks = response.data?.ocs?.data ?? []
	} catch (error) {
		return []
	}

	const items: FocusItem[] = []
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
				// The board is chosen in the picker, so the stack is what
				// distinguishes one row from another.
				list: stack.title,
			})
		}
	}

	return items
}
