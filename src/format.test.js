// UI texts: as of 0.8.0, basis=created means "since upload to Nextcloud".
// Source strings are English; the German expectations go through l10n/.js-de.json –
// so the test also checks that the translation matches the previous wording.
import { after, before, describe, test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { register, unregister } from '@nextcloud/l10n'
import { baseName, categoryOf, countChips, effectText, formatDay, groupUpcoming, localDay, periodLabel, sourceLabel, sourceText, unblockPayload, unusualSource } from './format.js'

const de = JSON.parse(readFileSync(new URL('../l10n/.js-de.json', import.meta.url), 'utf8')).translations

describe('German (l10n/.js-de.json)', () => {
	before(() => register('folder_retention', de))
	after(() => unregister('folder_retention'))

	test('Retention period from upload to Nextcloud', () => {
		const eff = { rule: { periodUnit: 'month', periodValue: 1, basis: 'created' } }
		assert.equal(effectText(eff), 'Dateien werden 1 Monat nach Ablage in Nextcloud gelöscht.')
	})

	test('Retention period from last modification', () => {
		const eff = { rule: { periodUnit: 'week', periodValue: 2, basis: 'modified' } }
		assert.equal(effectText(eff), 'Dateien werden 2 Wochen nach der letzten Änderung gelöscht.')
	})

	test('Dative in the plain-language text, nominative on the badge', () => {
		const rule = { periodUnit: 'month', periodValue: 3, basis: 'created', notify: true }
		assert.equal(effectText({ rule }), 'Dateien werden 3 Monaten nach Ablage in Nextcloud gelöscht. Der Besitzer wird einen Tag vorher benachrichtigt.')
		assert.equal(effectText({ rule: { ...rule, periodUnit: 'day', periodValue: 10, notify: false } }), 'Dateien werden 10 Tagen nach Ablage in Nextcloud gelöscht.')
		assert.equal(periodLabel(rule), '3 Monate')
		assert.equal(periodLabel({ periodUnit: 'day', periodValue: 1 }), '1 Tag')
		assert.equal(periodLabel({ periodUnit: 'never' }), 'Nie löschen')
	})

	test('Never delete', () => {
		assert.equal(effectText({ rule: { periodUnit: 'never', periodValue: null } }), 'Dateien werden nie automatisch gelöscht.')
	})

	test('Sources of the reference date', () => {
		assert.equal(sourceLabel('upload'), 'Ablage in Nextcloud')
		assert.equal(sourceLabel('seen'), 'zuerst gesehen')
		assert.equal(sourceLabel('restored'), 'wiederhergestellt')
		assert.equal(sourceLabel('mtime'), 'Änderung')
		assert.equal(sourceLabel('unbekannt'), 'unbekannt')
	})

	test('Source column', () => {
		const rule = { scope: 'inherit' }
		assert.equal(sourceText({ isOwn: true, rule }, true), 'Eigene Regel · vererbt')
		assert.equal(sourceText({ isOwn: false, isDefault: true, isPersonal: true }), 'geerbt von Persönliche Ordner')
		// Do not escape folder names with special characters – Vue escapes itself
		assert.equal(sourceText({ isOwn: false, isDefault: false, sourceId: 7 }, false, () => 'A & B <x>'), 'geerbt von A & B <x>')
	})

	test('Log: counts per status group, errors first', () => {
		const chips = countChips({ deleted: 0, would_delete: 2, skipped: 1, error: 1 })
		assert.deepEqual(chips.map(c => c.label), ['1 Fehler', '2 würden gelöscht', '1 übersprungen'])
		assert.deepEqual(chips.map(c => c.tone), ['error', 'sim', 'skip'])
		assert.equal(countChips({ deleted: 1, would_delete: 1, skipped: 0, error: 0 })[1].label, '1 würde gelöscht')
		const old = countChips({ deleted: 0, would_delete: 0, superseded: 3, skipped: 0, error: 0 })
		assert.deepEqual(old.map(c => [c.label, c.tone]), [['3 überholt', 'old']])
	})
})

describe('English (source strings, no translation loaded)', () => {
	test('Plain-language text and badge', () => {
		assert.equal(effectText({ rule: { periodUnit: 'month', periodValue: 1, basis: 'created' } }), 'Files are deleted 1 month after upload to Nextcloud.')
		assert.equal(effectText({ rule: { periodUnit: 'week', periodValue: 2, basis: 'modified', notify: true } }),
			'Files are deleted 2 weeks after their last modification. The owner is notified one day in advance.')
		assert.equal(periodLabel({ periodUnit: 'day', periodValue: 5 }), '5 days')
		assert.equal(sourceLabel('upload'), 'upload to Nextcloud')
	})
})

test('Lifting a block sends only the displayed areas with timestamp', () => {
	const shown = [{ key: '5:files', label: 'Persönlich · bob', reason: 'x', at: 100 }]
	assert.deepEqual(unblockPayload(shown), { unblock: [{ key: '5:files', at: 100 }] })
	assert.deepEqual(unblockPayload([]), { unblock: [] })
})

test('Log: file name and status group like LogSummary::category()', () => {
	assert.equal(categoryOf('would_delete'), 'would_delete')
	assert.equal(categoryOf('would_delete', null), 'would_delete')
	assert.equal(categoryOf('would_delete', 1759650000), 'superseded')
	// only a simulated hit can be superseded
	assert.equal(categoryOf('deleted', 1759650000), 'deleted')
	assert.equal(categoryOf('skipped_locked'), 'skipped')
	assert.equal(categoryOf('deleted_final'), 'error')
	assert.equal(categoryOf('something_new'), 'error')
	assert.equal(baseName('Team/Drafts/a.txt'), 'a.txt')
	assert.equal(baseName('a.txt'), 'a.txt')
})

test('Log: reference source only when deviating from the normal case', () => {
	assert.equal(unusualSource('upload'), false)
	assert.equal(unusualSource('mtime'), false)
	for (const src of ['seen', 'restored', 'created']) {
		assert.equal(unusualSource(src), true, src)
	}
})

test('Log: day without time zone shift', () => {
	// "2026-10-04" is a day in the instance time zone; new Date('2026-10-04') would be UTC midnight
	assert.match(formatDay('2026-10-04'), /4/)
	assert.doesNotMatch(formatDay('2026-10-04'), /3/)
})

test('upcoming deletions: already due first, then by day; folders by area and parent folder', () => {
	const at = (day, hour) => Math.floor(new Date(`${day}T${hour}:00:00`).getTime() / 1000)
	const item = (path, root, day, overdue = false, size = 10) => ({ path, root, size, expiresAt: at(day, '10'), overdue })
	const groups = groupUpcoming([
		item('Team/Folder 10/a.pdf', 'r1', '2026-10-09'),
		item('Team/Folder 2/b.pdf', 'r1', '2026-10-09', false, 5),
		item('Archive/c.pdf', 'r2', '2026-10-07'),
		item('Archive/d.pdf', 'r3', '2026-10-07'),
		item('old.txt', 'r1', '2026-10-01', true),
		item('Team/Folder 2/e.pdf', 'r1', '2026-10-09', false, 7),
	])
	assert.deepEqual(groups.map(g => [g.key, g.total, g.size]), [['due', 1, 10], ['2026-10-07', 2, 20], ['2026-10-09', 3, 22]])
	assert.deepEqual(groups[0].folders.map(f => f.folder), [''])
	assert.deepEqual(groups[1].folders.map(f => [f.folder, f.root]), [['Archive', 'r2'], ['Archive', 'r3']], 'same name, different area')
	assert.deepEqual(groups[2].folders.map(f => [f.folder, f.total, f.size]), [['Team/Folder 2', 2, 12], ['Team/Folder 10', 1, 10]], 'natural order')
	assert.equal(localDay(at('2026-10-09', '23')), '2026-10-09')
	assert.deepEqual(groupUpcoming([]), [])
})
