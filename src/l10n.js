/**
 * Übersetzung der Oberfläche. Quelltexte sind Englisch, Übersetzungen kommen aus l10n/<sprache>.js.
 *
 * Dünne Hülle um @nextcloud/l10n: Vue maskiert Text selbst, deshalb hier ohne HTML-Escaping
 * und ohne DOMPurify – sonst stünde „&amp;“ statt „&“ in Ordnernamen. Aufrufe bleiben in der
 * Form t('folder_retention', '…') / n('folder_retention', '…', '…', count), damit das
 * Nextcloud-translationtool sie findet. Läuft ohne DOM (node --test).
 */
import { getCanonicalLocale, translate, translatePlural } from '@nextcloud/l10n'

const RAW = { escape: false, sanitize: false }

/**
 * @param {string} app
 * @param {string} text englischer Quelltext, Platzhalter als {name}
 * @param {Record<string, string|number>} [vars]
 */
export function t(app, text, vars) {
	return translate(app, text, vars, undefined, RAW)
}

/**
 * @param {string} app
 * @param {string} singular
 * @param {string} plural
 * @param {number} count ersetzt %n
 * @param {Record<string, string|number>} [vars]
 */
export function n(app, singular, plural, count, vars) {
	return translatePlural(app, singular, plural, count, vars, RAW)
}

/** BCP-47-Locale des Benutzers (für Datum, Zahlen, Sortierung) */
export function locale() {
	try {
		return getCanonicalLocale()
	} catch (e) {
		return undefined
	}
}
