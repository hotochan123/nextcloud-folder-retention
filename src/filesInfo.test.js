// Deletion date in the Files app: badge and sidebar texts from the WebDAV property.
import { after, before, describe, test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { register, unregister } from '@nextcloud/l10n'
import { badge, details, infoOf, ruleSource } from './filesInfo.js'

const de = JSON.parse(readFileSync(new URL('../l10n/.js-de.json', import.meta.url), 'utf8')).translations
const DAY = 86400
const NOW = 1_800_000_000

function file(extra = {}) {
	return {
		type: 'file',
		expiresAt: NOW + 30 * DAY,
		skip: null,
		period: { value: 1, unit: 'month' },
		basis: 'created',
		referenceDate: NOW - DAY,
		referenceSource: 'upload',
		source: { kind: 'folder', name: 'Scans' },
		blocked: false,
		simulation: false,
		halted: false,
		noCron: false,
		...extra,
	}
}

describe('infoOf', () => {
	test('reads the JSON attribute', () => {
		assert.equal(infoOf({ attributes: { 'folder-retention': JSON.stringify(file()) } }).type, 'file')
	})
	test('missing, empty or broken value = not managed', () => {
		assert.equal(infoOf({ attributes: {} }), null)
		assert.equal(infoOf({ attributes: { 'folder-retention': '' } }), null)
		assert.equal(infoOf({ attributes: { 'folder-retention': '{broken' } }), null)
		assert.equal(infoOf(null), null)
	})
})

describe('badge (English source)', () => {
	test('only files with a deletion date', () => {
		assert.equal(badge(null, NOW), null)
		assert.equal(badge(file({ expiresAt: null, skip: 'never' }), NOW), null)
		assert.equal(badge(file({ type: 'folder', expiresAt: null }), NOW), null)
	})
	test('overdue, soon, later', () => {
		assert.equal(badge(file({ expiresAt: NOW }), NOW).text, 'Due')
		assert.equal(badge(file({ expiresAt: NOW + 1 }), NOW).text, 'in 1 day')
		assert.equal(badge(file({ expiresAt: NOW + 7 * DAY }), NOW).text, 'in 7 days')
		const later = badge(file({ expiresAt: NOW + 8 * DAY }), NOW).text
		assert.doesNotMatch(later, /\d{4}/, 'same year: no year in the badge')
		assert.match(badge(file({ expiresAt: NOW + 400 * DAY }), NOW).text, /\d{4}/)
	})
	test('tooltip names the rule and why nothing is deleted', () => {
		const title = badge(file({ simulation: true, halted: true }), NOW).title
		assert.match(title, /folder rule “1 month”/)
		assert.match(title, /Simulation mode is on/)
		assert.match(title, /deletion limit reached/)
	})
})

describe('sidebar (English source)', () => {
	test('file: date, rule, reference, source, forecast hint', () => {
		const { rows, notes } = details(file())
		assert.deepEqual(rows.map((r) => r.label), ['Deletion date', 'Rule', 'Counted from', 'Rule set on'])
		assert.equal(rows[1].value, '1 month after upload to Nextcloud')
		assert.match(rows[2].value, /\(upload to Nextcloud\)$/)
		assert.equal(rows[3].value, 'Folder “Scans”')
		assert.match(notes.at(-1), /forecast/)
	})
	test('file without reference date', () => {
		const { rows } = details(file({ expiresAt: null, referenceDate: null, skip: 'no_date' }))
		assert.equal(rows[0].value, 'None – upload time unknown')
		assert.equal(rows.find((r) => r.label === 'Counted from'), undefined)
	})
	test('folder: rule for the files inside, by modification', () => {
		const { rows } = details(file({ type: 'folder', expiresAt: null, basis: 'modified', period: { value: 2, unit: 'week' }, source: { kind: 'own', name: null } }))
		assert.equal(rows[0].value, 'Deleted 2 weeks after the last modification')
		assert.equal(rows[1].value, 'This folder')
	})
	test('never and no rule', () => {
		assert.equal(details(file({ skip: 'never', expiresAt: null, period: { value: null, unit: 'never' } })).rows[0].value, 'Never deleted automatically')
		const none = details(file({ skip: 'personal_default', expiresAt: null }))
		assert.deepEqual(none.rows, [])
		assert.equal(none.notes[0], 'No deletion rule applies to this file.')
	})
	test('source names: via a share the owner’s folder stays anonymous', () => {
		assert.equal(ruleSource(file({ source: { kind: 'folder', name: null } })), 'A parent folder')
		assert.equal(ruleSource(file({ source: { kind: 'default', name: null } })), 'Default rule')
		assert.equal(ruleSource(file({ source: { kind: 'area', name: null } })), 'Top level of this area')
	})
})

describe('German (l10n/.js-de.json)', () => {
	before(() => register('folder_retention', de))
	after(() => unregister('folder_retention'))

	test('badge', () => {
		assert.equal(badge(file({ expiresAt: NOW }), NOW).text, 'Fällig')
		assert.equal(badge(file({ expiresAt: NOW + 3 * DAY }), NOW).text, 'in 3 Tagen')
		assert.equal(badge(file({ expiresAt: NOW + DAY }), NOW).text, 'in 1 Tag')
	})
	test('sidebar', () => {
		const { rows } = details(file())
		assert.equal(rows[1].value, '1 Monat nach Ablage in Nextcloud')
		assert.equal(rows[3].value, 'Ordner „Scans“')
	})
})
