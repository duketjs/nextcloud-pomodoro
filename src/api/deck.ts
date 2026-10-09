/**
 * SPDX-FileCopyrightText: 2026 Jokersmile
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import type { FocusItem } from './types'

/**
 * Deck's OCS API (boards, stacks, cards). Deck's own web UI uses exactly these
 * endpoints, so this is the sanctioned way in and it reuses the browser session
 * - no credentials of our own are needed.
 */
const API_BASE = '/ocs/v2.php/apps/deck/api/v1.0'

/**
 * Deck's action routes are ordinary app routes rather than OCS ones. The
 * explicit /index.php is deliberate: it works whether or not the install is
 * configured to rewrite pretty URLs.
 */
const APP_BASE = '/index.php/apps/deck'

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
	/** Set once the card has been marked done */
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
 * marked done (which Deck does for cards sitting in the board's done column).
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

/**
 * Mark a card as done.
 *
 * This is Deck's own action endpoint - the same one its user interface calls -
 * so Deck does the work: it stamps the completion date and, when the board has
 * a done column configured, moves the card into it. Using it keeps Deck's
 * permission checks, activity entries and notifications intact.
 *
 * Note that when a board has no done column the card is still marked done but
 * stays where it is; that is Deck's own behaviour, not something we override.
 */
export async function markCardDone(cardId: number): Promise<void> {
	await axios.put(`${APP_BASE}/cards/${cardId}/done`)
}
