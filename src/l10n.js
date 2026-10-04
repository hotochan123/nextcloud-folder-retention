/**
 * UI translation. Source strings are English, translations come from l10n/<language>.js.
 *
 * Thin wrapper around @nextcloud/l10n: Vue escapes text itself, so no HTML escaping here
 * and no DOMPurify – otherwise folder names would show "&amp;" instead of "&". Calls keep the
 * form t('folder_retention', '…') / n('folder_retention', '…', '…', count) so that the
 * Nextcloud translationtool finds them. Runs without a DOM (node --test).
 */
import { getCanonicalLocale, translate, translatePlural } from '@nextcloud/l10n'

const RAW = { escape: false, sanitize: false }

/**
 * @param {string} app
 * @param {string} text English source string, placeholders as {name}
 * @param {Record<string, string|number>} [vars]
 */
export function t(app, text, vars) {
	return translate(app, text, vars, undefined, RAW)
}

/**
 * @param {string} app
 * @param {string} singular
 * @param {string} plural
 * @param {number} count replaces %n
 * @param {Record<string, string|number>} [vars]
 */
export function n(app, singular, plural, count, vars) {
	return translatePlural(app, singular, plural, count, vars, RAW)
}

/** User's BCP 47 locale (for dates, numbers, sorting) */
export function locale() {
	try {
		return getCanonicalLocale()
	} catch (e) {
		return undefined
	}
}
