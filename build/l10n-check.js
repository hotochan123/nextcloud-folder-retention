/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * Compares the translatable strings in the source code with l10n/<lang>.json.
 *
 * The app's source language is English; German comes from l10n/de.json. If a
 * string is missing there, the German UI suddenly shows English — hard to
 * notice while clicking through, but shown here at once. Also reported:
 * orphans (translations no source string uses any more), plurals that are not
 * a two-element array, and translations whose placeholders ({name}, %s, %1$s,
 * %n, %d) differ from the source.
 *
 * Scanned: lib/ and templates/ (PHP: ->t('…'), ->n('…', '…'), any variable
 * name for the IL10N object) and src/ (JS/Vue: t('folder_retention', '…'),
 * n('folder_retention', '…', '…')). A call whose text is not a literal cannot
 * be checked; it is listed as a warning.
 *
 *   npm run l10n:check          # exit 1 if anything is missing or wrong
 *   L10N_LANGS=de npm run l10n:check   # only these languages (default: all)
 */
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const APP_ID = 'folder_retention'
const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..')
const l10nDir = path.join(root, 'l10n')

/** All files below dir with a matching extension (missing dir = none). */
function walk(dir, exts, out = []) {
	if (!fs.existsSync(dir)) return out
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		const full = path.join(dir, entry.name)
		if (entry.isDirectory()) {
			if (entry.name !== 'node_modules') walk(full, exts, out)
		} else if (exts.some((e) => entry.name.endsWith(e)) && !/\.test\.[cm]?js$/.test(entry.name)) {
			out.push(full)
		}
	}
	return out
}

// A string literal in single or double quotes, or a backtick literal without
// ${…}. Group 1 = quote, group 2 = body.
const LIT = String.raw`(['"\x60])((?:(?!\1)[^\\]|\\[\s\S])*)\1`
const SP = String.raw`\s*`

// Unescaping by language: PHP single quotes know only \' and \\; PHP double
// quotes and JS know the usual escapes.
function unescapePhpSingle(s) {
	return s.replace(/\\(['\\])/g, '$1')
}
function unescapeFull(s) {
	return s.replace(/\\(u\{[0-9a-fA-F]+\}|u[0-9a-fA-F]{4}|x[0-9a-fA-F]{2}|[\s\S])/g, (m, c) => {
		if (c[0] === 'u' && c.length > 1) return String.fromCodePoint(parseInt(c.replace(/[u{}]/g, ''), 16))
		if (c[0] === 'x' && c.length === 3) return String.fromCharCode(parseInt(c.slice(1), 16))
		return { n: '\n', t: '\t', r: '\r', 0: '\0', '\n': '' }[c] ?? c
	})
}
const unescapeFor = (lang, quote) => (lang === 'php' && quote === '\'' ? unescapePhpSingle : unescapeFull)

const RULES = {
	php: {
		singular: new RegExp(String.raw`->t\(${SP}${LIT}`, 'g'),
		plural: new RegExp(String.raw`->n\(${SP}${LIT}${SP},${SP}${LIT}`, 'g'),
		any: /->[tn]\((?=\s*[^\s'"])/g,
	},
	js: {
		singular: new RegExp(String.raw`\bt\(${SP}['"]${APP_ID}['"]${SP},${SP}${LIT}`, 'g'),
		plural: new RegExp(String.raw`\bn\(${SP}['"]${APP_ID}['"]${SP},${SP}${LIT}${SP},${SP}${LIT}`, 'g'),
		any: new RegExp(String.raw`\b[tn]\(${SP}['"]${APP_ID}['"]${SP},(?=\s*[^\s'"\x60])`, 'g'),
	},
}

const found = new Map() // key -> Set of "file:line"
const dynamic = []
const lineOf = (src, index) => src.slice(0, index).split('\n').length
const note = (key, where) => {
	if (!found.has(key)) found.set(key, new Set())
	found.get(key).add(where)
}

const sources = [
	...walk(path.join(root, 'lib'), ['.php']).map((f) => [f, 'php']),
	...walk(path.join(root, 'templates'), ['.php']).map((f) => [f, 'php']),
	...walk(path.join(root, 'src'), ['.js', '.mjs', '.vue', '.ts']).map((f) => [f, 'js']),
]
for (const [file, lang] of sources) {
	// Block comments (docblocks) may show example calls; blank them out but
	// keep their line breaks so reported line numbers stay right.
	const src = fs.readFileSync(file, 'utf8')
		.replace(/\/\*[\s\S]*?\*\//g, (c) => c.replace(/[^\n]/g, ' '))
	const rel = path.relative(root, file)
	const rules = RULES[lang]
	let m
	rules.plural.lastIndex = 0
	while ((m = rules.plural.exec(src)) !== null) {
		const s = unescapeFor(lang, m[1])(m[2])
		const p = unescapeFor(lang, m[3])(m[4])
		note('_' + s + '_::_' + p + '_', `${rel}:${lineOf(src, m.index)}`)
	}
	rules.singular.lastIndex = 0
	while ((m = rules.singular.exec(src)) !== null) {
		if (m[1] === '`' && m[2].includes('${')) {
			dynamic.push(`${rel}:${lineOf(src, m.index)}`)
			continue
		}
		note(unescapeFor(lang, m[1])(m[2]), `${rel}:${lineOf(src, m.index)}`)
	}
	rules.any.lastIndex = 0
	while ((m = rules.any.exec(src)) !== null) {
		// ->t($var), ->t(self::X), t('folder_retention', text): not checkable.
		dynamic.push(`${rel}:${lineOf(src, m.index)}`)
	}
}

// Placeholders that must survive translation unchanged.
function placeholders(s) {
	const list = [...s.matchAll(/\{[A-Za-z0-9_]+\}|%(?:\d+\$)?[sdn]/g)].map((m) => m[0])
	return list.sort().join(' ')
}

/** What build/l10n-build.js generates from l10n/<lang>.json. */
function expectedJs(lang) {
	const data = JSON.parse(fs.readFileSync(path.join(l10nDir, lang + '.json'), 'utf8'))
	const body = JSON.stringify(data.translations, null, 4).replace(/\n/g, '\n    ')
	return 'OC.L10N.register(\n    "' + APP_ID + '",\n    ' + body + ',\n"' + data.pluralForm + '");\n'
}

let bad = 0
const wanted = process.env.L10N_LANGS ? process.env.L10N_LANGS.split(',') : null
const langFiles = fs.existsSync(l10nDir)
	? fs.readdirSync(l10nDir).filter((f) => /^[A-Za-z_]+\.json$/.test(f))
		.filter((f) => !wanted || wanted.includes(f.replace(/\.json$/, '')))
	: []

if (found.size === 0) {
	console.log('No translatable strings found in lib/, templates/ or src/ — has the call pattern changed?')
	process.exit(1)
}
if (langFiles.length === 0) {
	console.log(`${found.size} source strings, but no l10n/<lang>.json — run npm run l10n first`)
	process.exit(1)
}

for (const file of langFiles) {
	const lang = file.replace(/\.json$/, '')
	let translations
	try {
		translations = JSON.parse(fs.readFileSync(path.join(l10nDir, file), 'utf8')).translations
	} catch (e) {
		console.log(`${lang}: cannot read l10n/${file}: ${e.message}`)
		bad++
		continue
	}
	if (!translations || typeof translations !== 'object') {
		console.log(`${lang}: l10n/${file} has no "translations" object`)
		bad++
		continue
	}
	const jsFile = path.join(l10nDir, lang + '.js')
	if (!fs.existsSync(jsFile) || fs.readFileSync(jsFile, 'utf8') !== expectedJs(lang)) {
		console.log(`  STALE     l10n/${lang}.js is missing or does not match ${file} — run npm run l10n`)
		bad++
	}
	const missing = [...found.keys()].filter((s) => !(s in translations))
	const orphan = Object.keys(translations).filter((s) => !found.has(s))
	const wrong = []
	for (const [key, value] of Object.entries(translations)) {
		if (!found.has(key)) continue
		const plural = key.startsWith('_') && key.includes('_::_')
		if (plural) {
			if (!Array.isArray(value) || value.length !== 2 || value.some((v) => typeof v !== 'string' || v === '')) {
				wrong.push([key, 'plural needs an array of two non-empty strings'])
				continue
			}
			const [s, p] = key.slice(1, -1).split('_::_')
			if (placeholders(value[0]) !== placeholders(s) || placeholders(value[1]) !== placeholders(p)) {
				wrong.push([key, 'placeholders differ from the source'])
			}
		} else if (typeof value !== 'string' || value === '') {
			wrong.push([key, 'translation must be a non-empty string'])
		} else if (placeholders(value) !== placeholders(key)) {
			wrong.push([key, `placeholders differ: source "${placeholders(key)}", translation "${placeholders(value)}"`])
		}
	}
	console.log(`${lang}: ${found.size} source strings, ${missing.length} missing, ${orphan.length} orphaned, ${wrong.length} malformed`)
	for (const s of missing) {
		console.log(`  MISSING   ${JSON.stringify(s)}  (${[...found.get(s)].join(', ')})`)
	}
	for (const s of orphan) {
		console.log(`  ORPHAN    ${JSON.stringify(s)}`)
	}
	for (const [s, why] of wrong) {
		console.log(`  MALFORMED ${JSON.stringify(s)}: ${why}`)
	}
	bad += missing.length + orphan.length + wrong.length
}

for (const where of dynamic) {
	console.log(`  Warning: translation call without a literal text, not checked (${where})`)
}
process.exit(bad === 0 ? 0 : 1)
