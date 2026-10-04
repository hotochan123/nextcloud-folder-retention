/** Texte für Plaketten, Klartext-Kasten und Quellen-Spalte. */
import { locale, n, t } from './l10n.js'

/** „1 Monat“, „2 Wochen“, „Nie löschen“ */
export function periodLabel(rule) {
	if (!rule || rule.periodUnit === 'never') {
		return t('folder_retention', 'Never delete')
	}
	const v = Number(rule.periodValue)
	switch (rule.periodUnit) {
	case 'day':
		return n('folder_retention', '%n day', '%n days', v)
	case 'week':
		return n('folder_retention', '%n week', '%n weeks', v)
	case 'month':
		return n('folder_retention', '%n month', '%n months', v)
	default:
		return `${rule.periodValue} ?`
	}
}

export function isNever(rule) {
	return !rule || rule.periodUnit === 'never'
}

/**
 * Frist-Satz je Einheit und Bezug als eigener Pluraltext – im Deutschen steht die Frist
 * im Dativ („2 Monaten“, aber „2 Wochen“), das lässt sich nicht aus periodLabel() ableiten.
 */
function deletionSentence(rule) {
	const v = Number(rule.periodValue)
	const modified = rule.basis === 'modified'
	switch (rule.periodUnit) {
	case 'day':
		return modified
			? n('folder_retention', 'Files are deleted %n day after their last modification.', 'Files are deleted %n days after their last modification.', v)
			: n('folder_retention', 'Files are deleted %n day after upload to Nextcloud.', 'Files are deleted %n days after upload to Nextcloud.', v)
	case 'week':
		return modified
			? n('folder_retention', 'Files are deleted %n week after their last modification.', 'Files are deleted %n weeks after their last modification.', v)
			: n('folder_retention', 'Files are deleted %n week after upload to Nextcloud.', 'Files are deleted %n weeks after upload to Nextcloud.', v)
	case 'month':
		return modified
			? n('folder_retention', 'Files are deleted %n month after their last modification.', 'Files are deleted %n months after their last modification.', v)
			: n('folder_retention', 'Files are deleted %n month after upload to Nextcloud.', 'Files are deleted %n months after upload to Nextcloud.', v)
	default:
		return modified
			? t('folder_retention', 'Files are deleted {period} after their last modification.', { period: periodLabel(rule) })
			: t('folder_retention', 'Files are deleted {period} after upload to Nextcloud.', { period: periodLabel(rule) })
	}
}

/**
 * Klartext der effektiven Wirkung, z. B. „Dateien werden 1 Monat nach Ablage in Nextcloud gelöscht.“
 *
 * @param {object} eff Ergebnis von store.effective()
 */
export function effectText(eff) {
	const rule = eff.rule
	if (isNever(rule)) {
		return t('folder_retention', 'Files are never deleted automatically.')
	}
	let text = deletionSentence(rule)
	if (rule.notify) {
		text += ' ' + t('folder_retention', 'The owner is notified one day in advance.')
	}
	return text
}

/**
 * Text der Quellen-Spalte.
 *
 * @param {object} eff Ergebnis von store.effective()
 * @param {boolean} hasChildren
 * @param {(id: number) => string} nameOf
 */
export function sourceText(eff, hasChildren, nameOf) {
	if (eff.isOwn) {
		if (eff.rule.scope === 'here') {
			return t('folder_retention', 'Own rule · this level only')
		}
		return hasChildren ? t('folder_retention', 'Own rule · passed on') : t('folder_retention', 'Own rule')
	}
	if (eff.isDefault) {
		return eff.isPersonal
			? t('folder_retention', 'inherited from Personal folders')
			: t('folder_retention', 'inherited from Default rule')
	}
	return t('folder_retention', 'inherited from {name}', { name: nameOf(eff.sourceId) })
}

/** Woher das Bezugsdatum stammt (Spalte im Protokoll und in der Vorschau) */
export function sourceLabel(src) {
	switch (src) {
	case 'created': return t('folder_retention', 'creation according to client')
	case 'upload': return t('folder_retention', 'upload to Nextcloud')
	case 'seen': return t('folder_retention', 'first seen')
	case 'restored': return t('folder_retention', 'restored')
	case 'mtime': return t('folder_retention', 'modification')
	default: return src
	}
}

export function formatDate(ts) {
	if (!ts) {
		return '–'
	}
	return new Date(ts * 1000).toLocaleString(locale(), { dateStyle: 'medium', timeStyle: 'short' })
}

export function formatSize(bytes) {
	if (bytes < 1024) {
		return `${bytes} B`
	}
	const units = ['KB', 'MB', 'GB', 'TB']
	let v = bytes / 1024
	let i = 0
	while (v >= 1024 && i < units.length - 1) {
		v /= 1024
		i++
	}
	return `${v.toLocaleString(locale(), { maximumFractionDigits: 1 })} ${units[i]}`
}

/**
 * Anfrage zum Aufheben von Sperren: nur die angezeigten Bereiche, je mit dem angezeigten
 * Zeitpunkt – eine inzwischen neu gesetzte Sperre (anderer Zeitpunkt) bleibt bestehen.
 *
 * @param {Array<{key: string, at: number}>} entries
 */
export function unblockPayload(entries) {
	return { unblock: entries.map(b => ({ key: b.key, at: b.at })) }
}

/** Statusgruppe eines Protokolleintrags, wie LogSummary::category() im Backend: Unbekanntes zählt als Fehler */
export function categoryOf(status) {
	if (status === 'deleted' || status === 'would_delete') return status
	return status.startsWith('skipped') ? 'skipped' : 'error'
}

/** Farbton der Plaketten je Statusgruppe */
export const CATEGORY_TONE = { error: 'error', deleted: 'delete', would_delete: 'sim', skipped: 'skip' }

export function baseName(path) {
	return path.slice(path.lastIndexOf('/') + 1)
}

/**
 * Bezugsquelle nur nennen, wenn sie vom Normalfall abweicht: Upload-Zeit (Frist ab Ablage) und
 * Änderungszeit (Frist ab Änderung) ergeben sich aus der Regel.
 */
export function unusualSource(src) {
	return src !== 'upload' && src !== 'mtime'
}

/** Tag „2026-10-04“ (Zeitzone der Instanz) ohne Umrechnung anzeigen */
export function formatDay(date) {
	const [y, m, d] = date.split('-').map(Number)
	return new Date(y, m - 1, d).toLocaleDateString(locale(), { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })
}

export function formatTime(ts) {
	return ts ? new Date(ts * 1000).toLocaleTimeString(locale(), { timeStyle: 'short' }) : '–'
}

/**
 * Zahlen je Statusgruppe als Plaketten, nur die vorhandenen.
 *
 * @param {{deleted: number, would_delete: number, skipped: number, error: number}} counts
 * @return {Array<{key: string, label: string, tone: string}>}
 */
export function countChips(counts) {
	const chips = [
		['error', c => n('folder_retention', '%n error', '%n errors', c)],
		['deleted', c => n('folder_retention', '%n deleted', '%n deleted', c)],
		['would_delete', c => n('folder_retention', '%n would be deleted', '%n would be deleted', c)],
		['skipped', c => n('folder_retention', '%n skipped', '%n skipped', c)],
	]
	return chips.filter(([key]) => counts[key] > 0).map(([key, label]) => ({ key, tone: CATEGORY_TONE[key], label: label(counts[key]) }))
}
