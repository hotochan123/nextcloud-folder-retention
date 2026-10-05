/** Texts for badges, the plain-language box and the source column. */
import { locale, n, t } from './l10n.js'

/** "1 month", "2 weeks", "Never delete" */
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
 * Retention period sentence per unit and reference as its own plural text – in German the period
 * takes the dative ("2 Monaten", but "2 Wochen"), which cannot be derived from periodLabel().
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
 * Plain-language text of the effective outcome, e.g. "Files are deleted 1 month after upload to Nextcloud."
 *
 * @param {object} eff result of store.effective()
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
 * Text of the source column.
 *
 * @param {object} eff result of store.effective()
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

/** Where the reference date comes from (column in the log and in the preview) */
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
 * Request to lift blocks: only the displayed areas, each with the displayed
 * timestamp – a block set again in the meantime (different timestamp) stays in place.
 *
 * @param {Array<{key: string, at: number}>} entries
 */
export function unblockPayload(entries) {
	return { unblock: entries.map(b => ({ key: b.key, at: b.at })) }
}

/**
 * Status group of a log entry, like LogSummary::category() in the backend: anything unknown counts as an error.
 * A "would delete" with supersededAt no longer applies (rule changed, file no longer due, deleted or gone).
 */
export function categoryOf(status, supersededAt = null) {
	if (status === 'would_delete' && supersededAt) return 'superseded'
	if (status === 'deleted' || status === 'would_delete') return status
	return status.startsWith('skipped') ? 'skipped' : 'error'
}

/** Badge tone per status group */
export const CATEGORY_TONE = { error: 'error', deleted: 'delete', would_delete: 'sim', superseded: 'old', skipped: 'skip' }

export function baseName(path) {
	return path.slice(path.lastIndexOf('/') + 1)
}

/**
 * Only mention the reference source when it deviates from the normal case: upload time (period from upload) and
 * modification time (period from modification) follow from the rule.
 */
export function unusualSource(src) {
	return src !== 'upload' && src !== 'mtime'
}

/** Display the day "2026-10-04" (instance time zone) without conversion */
export function formatDay(date) {
	const [y, m, d] = date.split('-').map(Number)
	return new Date(y, m - 1, d).toLocaleDateString(locale(), { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })
}

export function formatTime(ts) {
	return ts ? new Date(ts * 1000).toLocaleTimeString(locale(), { timeStyle: 'short' }) : '–'
}

/**
 * Counts per status group as badges, only those present.
 *
 * @param {{deleted: number, would_delete: number, superseded?: number, skipped: number, error: number}} counts
 * @return {Array<{key: string, label: string, tone: string}>}
 */
export function countChips(counts) {
	const chips = [
		['error', c => n('folder_retention', '%n error', '%n errors', c)],
		['deleted', c => n('folder_retention', '%n deleted', '%n deleted', c)],
		['would_delete', c => n('folder_retention', '%n would be deleted', '%n would be deleted', c)],
		['skipped', c => n('folder_retention', '%n skipped', '%n skipped', c)],
		['superseded', c => n('folder_retention', '%n superseded', '%n superseded', c)],
	]
	return chips.filter(([key]) => counts[key] > 0).map(([key, label]) => ({ key, tone: CATEGORY_TONE[key], label: label(counts[key]) }))
}
