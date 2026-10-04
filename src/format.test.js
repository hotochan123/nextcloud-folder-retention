// Texte der Oberfläche: basis=created heißt ab 0.8.0 „seit Ablage in Nextcloud“.
// Quelltexte sind Englisch; die deutschen Erwartungen laufen über l10n/.js-de.json –
// so prüft der Test zugleich, dass die Übersetzung den bisherigen Wortlaut trifft.
import { after, before, describe, test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { register, unregister } from '@nextcloud/l10n'
import { effectText, periodLabel, sourceLabel, sourceText, unblockPayload } from './format.js'

const de = JSON.parse(readFileSync(new URL('../l10n/.js-de.json', import.meta.url), 'utf8')).translations

describe('Deutsch (l10n/.js-de.json)', () => {
	before(() => register('folder_retention', de))
	after(() => unregister('folder_retention'))

	test('Frist ab Ablage in Nextcloud', () => {
		const eff = { rule: { periodUnit: 'month', periodValue: 1, basis: 'created' } }
		assert.equal(effectText(eff), 'Dateien werden 1 Monat nach Ablage in Nextcloud gelöscht.')
	})

	test('Frist ab letzter Änderung', () => {
		const eff = { rule: { periodUnit: 'week', periodValue: 2, basis: 'modified' } }
		assert.equal(effectText(eff), 'Dateien werden 2 Wochen nach der letzten Änderung gelöscht.')
	})

	test('Dativ im Klartext, Nominativ auf der Plakette', () => {
		const rule = { periodUnit: 'month', periodValue: 3, basis: 'created', notify: true }
		assert.equal(effectText({ rule }), 'Dateien werden 3 Monaten nach Ablage in Nextcloud gelöscht. Der Besitzer wird einen Tag vorher benachrichtigt.')
		assert.equal(effectText({ rule: { ...rule, periodUnit: 'day', periodValue: 10, notify: false } }), 'Dateien werden 10 Tagen nach Ablage in Nextcloud gelöscht.')
		assert.equal(periodLabel(rule), '3 Monate')
		assert.equal(periodLabel({ periodUnit: 'day', periodValue: 1 }), '1 Tag')
		assert.equal(periodLabel({ periodUnit: 'never' }), 'Nie löschen')
	})

	test('Nie löschen', () => {
		assert.equal(effectText({ rule: { periodUnit: 'never', periodValue: null } }), 'Dateien werden nie automatisch gelöscht.')
	})

	test('Quellen des Bezugsdatums', () => {
		assert.equal(sourceLabel('upload'), 'Ablage in Nextcloud')
		assert.equal(sourceLabel('seen'), 'zuerst gesehen')
		assert.equal(sourceLabel('restored'), 'wiederhergestellt')
		assert.equal(sourceLabel('mtime'), 'Änderung')
		assert.equal(sourceLabel('unbekannt'), 'unbekannt')
	})

	test('Quellen-Spalte', () => {
		const rule = { scope: 'inherit' }
		assert.equal(sourceText({ isOwn: true, rule }, true), 'Eigene Regel · vererbt')
		assert.equal(sourceText({ isOwn: false, isDefault: true, isPersonal: true }), 'geerbt von Persönliche Ordner')
		// Ordnernamen mit Sonderzeichen nicht maskieren – Vue escaped selbst
		assert.equal(sourceText({ isOwn: false, isDefault: false, sourceId: 7 }, false, () => 'A & B <x>'), 'geerbt von A & B <x>')
	})
})

describe('Englisch (Quelltexte, keine Übersetzung geladen)', () => {
	test('Klartext und Plakette', () => {
		assert.equal(effectText({ rule: { periodUnit: 'month', periodValue: 1, basis: 'created' } }), 'Files are deleted 1 month after upload to Nextcloud.')
		assert.equal(effectText({ rule: { periodUnit: 'week', periodValue: 2, basis: 'modified', notify: true } }),
			'Files are deleted 2 weeks after their last modification. The owner is notified one day in advance.')
		assert.equal(periodLabel({ periodUnit: 'day', periodValue: 5 }), '5 days')
		assert.equal(sourceLabel('upload'), 'upload to Nextcloud')
	})
})

test('Sperre aufheben schickt nur die angezeigten Bereiche mit Zeitpunkt', () => {
	const shown = [{ key: '5:files', label: 'Persönlich · bob', reason: 'x', at: 100 }]
	assert.deepEqual(unblockPayload(shown), { unblock: [{ key: '5:files', at: 100 }] })
	assert.deepEqual(unblockPayload([]), { unblock: [] })
})
