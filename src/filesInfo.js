/**
 * Deletion date in the Files app: texts for the badge next to the file name and for the
 * sidebar tab. Input is the WebDAV property nc:folder-retention (lib/Dav/DeletionInfoPlugin.php,
 * fields see lib/Service/DeletionInfo.php). Pure – runs under node --test.
 */
import { formatDate, periodLabel, sourceLabel } from './format.js'
import { locale, n, t } from './l10n.js'

/** Days before the deletion date from which the badge reads "in N days" */
export const SOON_DAYS = 7

/**
 * Deletion info of a Files node, null if the folder is not managed or the value is broken.
 *
 * @param {object} node node from @nextcloud/files
 */
export function infoOf(node) {
	const raw = node?.attributes?.['folder-retention']
	if (typeof raw !== 'string' || raw === '') {
		return null
	}
	try {
		const info = JSON.parse(raw)
		return info && typeof info === 'object' ? info : null
	} catch (e) {
		return null
	}
}

/** Rule in the form periodLabel() expects */
function ruleOf(info) {
	return { periodValue: info.period?.value, periodUnit: info.period?.unit }
}

/** "Nov 4" – with the year only when it is not the current one (badge must stay narrow) */
function shortDate(ts, now) {
	const date = new Date(ts * 1000)
	const sameYear = date.getFullYear() === new Date(now * 1000).getFullYear()
	return date.toLocaleDateString(locale(), sameYear ? { day: 'numeric', month: 'short' } : { day: 'numeric', month: 'short', year: 'numeric' })
}

/**
 * Badge next to the file name: only files with a deletion date get one.
 *
 * @param {object|null} info
 * @param {number} now unix time
 * @return {{text: string, title: string}|null}
 */
export function badge(info, now) {
	if (!info || info.type !== 'file' || !info.expiresAt) {
		return null
	}
	const days = Math.ceil((info.expiresAt - now) / 86400)
	let text
	if (info.expiresAt <= now) {
		text = t('folder_retention', 'Due')
	} else if (days <= SOON_DAYS) {
		text = n('folder_retention', 'in %n day', 'in %n days', days)
	} else {
		text = shortDate(info.expiresAt, now)
	}
	const notes = pauseNotes(info)
	const title = [
		t('folder_retention', 'Moved to the trash bin on {date} by the folder rule “{period}”. Forecast – changes when the rule changes.', {
			date: formatDate(info.expiresAt),
			period: periodLabel(ruleOf(info)),
		}),
		...notes,
	].join('\n')
	return { text, title }
}

/** Why nothing is deleted at the moment, despite the date */
export function pauseNotes(info) {
	const notes = []
	if (info.simulation) {
		notes.push(t('folder_retention', 'Simulation mode is on – nothing is deleted at the moment.'))
	}
	if (info.halted) {
		notes.push(t('folder_retention', 'Deletion is paused (deletion limit reached) until an admin resumes it.'))
	}
	if (info.blocked) {
		notes.push(t('folder_retention', 'Deletion is locked in this area after an error.'))
	}
	if (info.noCron) {
		notes.push(t('folder_retention', 'Background jobs do not run via system cron – the app does not delete.'))
	}
	return notes
}

/** Where the rule is set */
export function ruleSource(info) {
	const src = info.source ?? {}
	switch (src.kind) {
	case 'default': return t('folder_retention', 'Default rule')
	case 'personal': return t('folder_retention', 'Default rule for personal folders')
	case 'area': return t('folder_retention', 'Top level of this area')
	case 'own': return t('folder_retention', 'This folder')
	default:
		return src.name
			? t('folder_retention', 'Folder “{name}”', { name: src.name })
			: t('folder_retention', 'A parent folder')
	}
}

function periodText(info) {
	const period = periodLabel(ruleOf(info))
	return info.basis === 'modified'
		? t('folder_retention', '{period} after the last modification', { period })
		: t('folder_retention', '{period} after upload to Nextcloud', { period })
}

/**
 * Content of the sidebar tab.
 *
 * @param {object} info
 * @return {{rows: Array<{label: string, value: string}>, notes: string[]}}
 */
export function details(info) {
	const rows = []
	const notes = []
	const isFolder = info.type === 'folder'

	if (info.skip === 'personal_default') {
		notes.push(isFolder
			? t('folder_retention', 'No deletion rule applies to files in this folder.')
			: t('folder_retention', 'No deletion rule applies to this file.'))
		return { rows, notes }
	}
	if (info.skip === 'never') {
		rows.push({
			label: isFolder ? t('folder_retention', 'Files in this folder') : t('folder_retention', 'Deletion'),
			value: t('folder_retention', 'Never deleted automatically'),
		})
		rows.push({ label: t('folder_retention', 'Rule set on'), value: ruleSource(info) })
		return { rows, notes }
	}

	if (isFolder) {
		rows.push({ label: t('folder_retention', 'Files in this folder'), value: t('folder_retention', 'Deleted {period}', { period: periodText(info) }) })
	} else {
		rows.push({
			label: t('folder_retention', 'Deletion date'),
			value: info.expiresAt ? formatDate(info.expiresAt) : t('folder_retention', 'None – upload time unknown'),
		})
		rows.push({ label: t('folder_retention', 'Rule'), value: periodText(info) })
		if (info.referenceDate) {
			rows.push({
				label: t('folder_retention', 'Counted from'),
				value: `${formatDate(info.referenceDate)} (${sourceLabel(info.referenceSource)})`,
			})
		}
	}
	rows.push({ label: t('folder_retention', 'Rule set on'), value: ruleSource(info) })

	notes.push(...pauseNotes(info))
	notes.push(isFolder
		? t('folder_retention', 'Subfolders can have rules of their own. Deleted files go to the trash bin.')
		: t('folder_retention', 'The date is a forecast: if the rule changes or the file is moved, it changes too. Deleted files go to the trash bin.'))
	return { rows, notes }
}
