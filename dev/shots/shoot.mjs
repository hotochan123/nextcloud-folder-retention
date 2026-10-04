#!/usr/bin/env node
/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Takes the App Store screenshots. Called by run.sh, which provides the throwaway
 * instance and its demo content:
 *
 *   FRET_HOST=http://172.17.0.5 FRET_USER=admin FRET_PASS=… node shoot.mjs <out-dir>
 *
 * Drives the host's Firefox through geckodriver (WebDriver over HTTP, no npm
 * dependency – the same approach as Pulse's dev/design-shots).
 */

import { spawn } from 'node:child_process'
import { mkdirSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'

const HOST = process.env.FRET_HOST
const USER = process.env.FRET_USER || 'admin'
const PASS = process.env.FRET_PASS
const GECKODRIVER = process.env.GECKODRIVER
const PORT = Number(process.env.FRET_SHOTS_PORT || 4460)
const BASE = `http://127.0.0.1:${PORT}`
const OUT = process.argv[2]
// CSS pixels of the viewport; captured at double density (2880 × 1800 PNG)
const VIEW = [1440, 900]
const DPR = 2

if (!HOST || !PASS || !GECKODRIVER || !OUT) {
	console.error('FRET_HOST, FRET_PASS, GECKODRIVER and an output directory are required (run.sh sets them)')
	process.exit(1)
}
mkdirSync(OUT, { recursive: true })

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

async function call(method, path, body) {
	const res = await fetch(BASE + path, {
		method,
		headers: { 'Content-Type': 'application/json' },
		body: body === undefined ? undefined : JSON.stringify(body),
	})
	const json = await res.json()
	if (json.value && json.value.error) {
		throw new Error(`${json.value.error}: ${String(json.value.message).split('\n')[0]}`)
	}
	return json.value
}

async function newSession() {
	const value = await call('POST', '/session', {
		capabilities: {
			alwaysMatch: {
				browserName: 'firefox',
				'moz:firefoxOptions': {
					args: ['-headless'],
					prefs: {
						'intl.accept_languages': 'en-US, en',
						'ui.systemUsesDarkTheme': 0,
						'layout.css.devPixelsPerPx': String(DPR),
						'browser.startup.homepage_override.mstone': 'ignore',
					},
				},
			},
		},
	})
	const id = value.sessionId
	const s = {
		close: () => call('DELETE', `/session/${id}`).catch(() => {}),
		go: (url) => call('POST', `/session/${id}/url`, { url }),
		rect: (width, height) => call('POST', `/session/${id}/window/rect`, { x: 0, y: 0, width, height }),
		script: (script, args = []) => call('POST', `/session/${id}/execute/sync`, { script, args }),
		png: () => call('GET', `/session/${id}/screenshot`),
		find: async (sel) => Object.values(await call('POST', `/session/${id}/element`, { using: 'css selector', value: sel }))[0],
		click: async (sel) => call('POST', `/session/${id}/element/${await s.find(sel)}/click`, {}),
		type: async (sel, text) => call('POST', `/session/${id}/element/${await s.find(sel)}/value`, { text }),
		mouseAway: () => call('POST', `/session/${id}/actions`, {
			actions: [{
				type: 'pointer', id: 'mouse', parameters: { pointerType: 'mouse' },
				actions: [{ type: 'pointerMove', duration: 0, x: 4, y: 4, origin: 'viewport' }, { type: 'pointerMove', duration: 0, x: 1, y: 1, origin: 'viewport' }],
			}],
		}),
	}
	return s
}

async function waitFor(s, sel, timeout = 20000) {
	const until = Date.now() + timeout
	while (Date.now() < until) {
		const ok = await s.script('const e = document.querySelector(arguments[0]); return !!e && e.getClientRects().length > 0', [sel])
		if (ok) { return }
		await sleep(250)
	}
	throw new Error(`timeout waiting for ${sel}`)
}

/** Wait until a script returns something truthy. */
async function until(s, script, args = [], timeout = 20000) {
	const end = Date.now() + timeout
	while (Date.now() < end) {
		if (await s.script(script, args)) { return }
		await sleep(250)
	}
	throw new Error(`timeout: ${script.slice(0, 80)}`)
}

/** Click the first element matching sel whose text contains text. */
async function clickText(s, sel, text) {
	const ok = await s.script(`
		const el = Array.from(document.querySelectorAll(arguments[0])).find((e) => e.textContent.trim().includes(arguments[1]))
		if (!el) { return false }
		el.scrollIntoView({ block: 'center' })
		el.click()
		return true
	`, [sel, text])
	if (!ok) { throw new Error(`nothing to click: ${sel} "${text}"`) }
}

/**
 * Put an element at the top of its scroll container, offset px below the top edge. The
 * settings page scrolls inside #app-content, not the window.
 */
async function scrollToEl(s, sel, offset = 24) {
	await s.script(`
		const el = typeof arguments[0] === "string" && arguments[0].startsWith("h2=")
			? Array.from(document.querySelectorAll("h2")).find((e) => e.textContent.trim() === arguments[0].slice(3))
			: document.querySelector(arguments[0])
		let box = el.parentElement
		while (box && !(box.scrollHeight > box.clientHeight + 2 && /auto|scroll/.test(getComputedStyle(box).overflowY))) { box = box.parentElement }
		box = box || document.scrollingElement
		const top = el.getBoundingClientRect().top - (box === document.scrollingElement ? 0 : box.getBoundingClientRect().top)
		box.scrollTop += top - arguments[1]
	`, [sel, offset])
	await sleep(300)
}

/** Collapse the settings navigation: more room for the tree and the tables. */
async function collapseNav(s) {
	await s.script(`
		const nav = document.querySelector("#app-navigation-vue, .app-navigation")
		if (nav && !nav.classList.contains("app-navigation--close")) {
			document.querySelector(".app-navigation-toggle, button[aria-controls=\\"app-navigation-vue\\"]")?.click()
		}
	`)
	await sleep(600)
	// The floating toggle would sit on top of the first heading; the store picture does not need it.
	await s.script("const b = document.querySelector('.app-navigation-toggle-wrapper, .app-navigation-toggle'); if (b) { b.style.visibility = 'hidden' }")
}

/** Viewport to VIEW: the outer window is larger than its content by the browser chrome. */
async function fitViewport(s) {
	await s.rect(VIEW[0], VIEW[1])
	const [w, h] = await s.script('return [window.innerWidth, window.innerHeight]')
	await s.rect(VIEW[0] + (VIEW[0] - w), VIEW[1] + (VIEW[1] - h))
}

let shotNo = 0
async function shot(s, name, settle = 800) {
	await s.mouseAway()
	await sleep(settle)
	const file = `${String(++shotNo).padStart(2, '0')}-${name}.png`
	writeFileSync(join(OUT, file), Buffer.from(await s.png(), 'base64'))
	console.log(`  · ${file}`)
}

/** Untranslated or broken text is easier to spot as a list than in the picture. */
async function report(s, label) {
	const issues = await s.script(`
		const out = []
		const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT)
		while (walker.nextNode()) {
			const t = walker.currentNode.textContent.trim()
			if (/[äöüß„]|\\b(und|nicht|Ordner|Datei|Löschen|Frist)\\b/.test(t)) { out.push(t.slice(0, 80)) }
		}
		return out.slice(0, 10)
	`)
	if (issues.length) { console.log(`  ! ${label}: German-looking text: ${JSON.stringify(issues)}`) }
}

async function login(s) {
	await s.go(`${HOST}/login`)
	// NC 34 wants Firefox >= 145, the host may have an ESR: set the flag that the
	// "Continue with this unsupported browser" button sets (harness only).
	await s.script("window.localStorage.setItem('nextcloud_vol_Y29yZQ==_unsupported-browser-ignore', 'true')")
	await s.go(`${HOST}/login`)
	await waitFor(s, 'input[name="user"]')
	await s.type('input[name="user"]', USER)
	await s.type('input[name="password"]', PASS)
	await s.click('button[type="submit"]')
	await waitFor(s, '#header, .app-menu', 30000)
	await sleep(1500)
}

const driver = spawn(GECKODRIVER, ['--port', String(PORT), '--log', 'error'], {
	// browser clock in UTC like the instance (default_timezone), so the page agrees with itself
	stdio: ['ignore', 'ignore', 'inherit'], env: { ...process.env, TZ: 'UTC' },
})
process.on('exit', () => driver.kill())
for (let i = 0; ; i++) {
	try { await call('GET', '/status'); break } catch { /* not up yet */ }
	if (i > 40) { throw new Error('geckodriver did not come up') }
	await sleep(250)
}

const s = await newSession()
let failed = false
try {
	await fitViewport(s)
	await login(s)

	// 01 – folder tree with rules, a selected folder in the detail panel
	await s.go(`${HOST}/settings/admin/folder_retention`)
	await waitFor(s, '.fr-tree__list .fr-name')
	await clickText(s, '.fr-tree__buttons button', 'Expand all')
	await until(s, "return Array.from(document.querySelectorAll('.fr-name__text')).some((e) => e.textContent.trim() === 'Applications')")
	await sleep(500)
	await clickText(s, '.fr-name', 'Applications')
	await until(s, "return document.querySelector('#fr-detail-title')?.textContent.trim() === 'Applications'")
	// Start of the app section at the top: the page header of the settings area stays, the
	// app's own intro and banner are part of the picture.
	await collapseNav(s)
	await scrollToEl(s, ".fr-main", 0)
	await report(s, 'tree')
	await shot(s, 'folder-tree', 1200)

	// 02 – preview of the affected files
	await clickText(s, '.fr-actions button', 'Show affected files')
	await waitFor(s, '#fr-preview-days')
	await s.script(`
		const sel = document.querySelector('#fr-preview-days')
		sel.value = '30'
		sel.dispatchEvent(new Event('change'))
	`)
	await until(s, "return document.querySelectorAll('.fr-table tbody tr').length > 0")
	await report(s, 'preview')
	await shot(s, 'preview', 1000)
	await s.script("document.querySelector('.modal-container button.modal-container__close, [aria-label=\"Close\"]')?.click()")
	await until(s, "return !document.querySelector('#fr-preview-days')")

	// 03 – the log: newest night open, its folders with their files
	await scrollToEl(s, "#fr-log-title", 16)
	await until(s, "return document.querySelectorAll('.fr-folder > summary').length > 0")
	await s.script("document.querySelectorAll('.fr-day[open] .fr-folder:not([open]) > summary').forEach((e) => e.click())")
	await until(s, "return document.querySelectorAll('.fr-log__table tbody tr').length > 0")
	await report(s, 'log')
	await shot(s, 'log', 800)

	// 04 – settings (simulation mode, tags)
	await scrollToEl(s, "h2=Settings", 0)
	await report(s, 'settings')
	await shot(s, 'settings', 800)

	// 05 – Files app: the informative "Retention: …" tags
	await s.go(`${HOST}/apps/files/files?dir=/HR/Applications`)
	await waitFor(s, '.files-list__row', 30000)
	await until(s, "return document.querySelectorAll('.files-list__system-tag, .files-list__system-tags, [class*=system-tag]').length > 0", [], 15000)
		.catch(() => console.log('  ! files: no system tags rendered'))
	await report(s, 'files')
	await shot(s, 'files-tags', 1500)
} catch (e) {
	failed = true
	console.error('shooting failed:', e.message)
	try { writeFileSync(join(OUT, 'failure.png'), Buffer.from(await s.png(), 'base64')) } catch { /* ignore */ }
} finally {
	await s.close()
	driver.kill()
}
process.exit(failed ? 1 : 0)
