// SPDX-FileCopyrightText: 2026 SCIM Server contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Browser test of the admin settings page (tokens and settings), against the same instance as run.sh.
// Saves a screenshot of every step to SCREENSHOT_DIR (uploaded by CI as an artifact).
// Usage: NC_URL=http://127.0.0.1:8089 [ADMIN_PASS=admin] [SCREENSHOT_DIR=...] node browser.mjs
// SKIP_SCIM_CALL=1: do not call SCIM with the new token (it needs https or allow_http, which the instance may lack).
// The instance must be disposable: it creates and revokes a token and changes the app settings.
import { mkdirSync } from 'node:fs'
import { chromium } from 'playwright'

const nc = process.env.NC_URL ?? 'http://127.0.0.1:8089'
const dir = process.env.SCREENSHOT_DIR ?? '/tmp/scim-screenshots'
mkdirSync(dir, { recursive: true })

const browser = await chromium.launch(process.env.CHROMIUM ? { executablePath: process.env.CHROMIUM } : {})
let failures = 0
let shots = 0
const check = (ok, what, detail = '') => {
	console.log(`${ok ? '  ok  ' : '  FAIL'} ${what}${ok || !detail ? '' : '\n       ' + detail}`)
	failures += ok ? 0 : 1
}
/** Full-page screenshot, or just one element when a locator is given. */
async function shot(page, name, locator = null) {
	const path = `${dir}/${String(++shots).padStart(2, '0')}-${name}.png`
	await page.waitForLoadState('networkidle').catch(() => {})
	await (locator ?? page).screenshot(locator ? { path } : { path, fullPage: true })
	console.log(`       screenshot ${path}`)
}

/** App Store screenshot: stable file name, cropped to a region (clip) of the page. Saved in SCREENSHOT_DIR/store/. */
async function storeShot(page, name, clip) {
	mkdirSync(`${dir}/store`, { recursive: true })
	await page.waitForLoadState('networkidle').catch(() => {})
	await page.screenshot({ path: `${dir}/store/${name}.png`, clip })
	console.log(`       store screenshot ${dir}/store/${name}.png`)
}

const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: Number(process.env.VIEWPORT_HEIGHT ?? 2200) }, timezoneId: 'Europe/Amsterdam', locale: 'en-GB' })
const page = await context.newPage()
page.on('pageerror', (e) => check(false, 'no JavaScript errors', e.message))
await page.goto(`${nc}/index.php/login`)
await page.fill('input[name="user"]', 'admin')
await page.fill('input[name="password"]', process.env.ADMIN_PASS ?? 'admin')
await Promise.all([page.waitForURL((u) => !u.pathname.endsWith('/login')), page.click('button[type="submit"]')])

console.log('Administration settings: SCIM Server')
await page.goto(`${nc}/index.php/settings/admin/security`)
const section = page.locator('#scim-server')
await section.waitFor()
check((await section.locator('code').first().innerText()).endsWith('/index.php/apps/scim_server/v2') || (await section.locator('code').first().innerText()).endsWith('/apps/scim_server/v2'), 'the SCIM base URL is shown')
await shot(page, 'admin-settings')

console.log('Token: create, use, revoke')
const before = await page.locator('#scim-tokens li').count()
await page.fill('#scim-label', 'browser-test')
await page.click('#scim-create')
await page.locator('#scim-new-token').waitFor({ state: 'visible', timeout: 10000 })
const token = (await page.locator('#scim-new-token code').innerText()).trim()
check(token.length >= 32, 'a new token is shown once')
check(await page.locator('#scim-tokens li').count() === before + 1, 'and listed')
await shot(page, 'admin-new-token')
const callScim = process.env.SKIP_SCIM_CALL !== '1'
const scim = (t) => fetch(`${nc}/index.php/apps/scim_server/v2/ServiceProviderConfig`, { headers: { Authorization: `Bearer ${t}` } })
if (callScim) check((await scim(token)).status === 200, 'the new token authenticates against SCIM')
check(await page.locator('#scim-tokens li', { hasText: 'browser-test' }).locator('.scim-revoke').count() === 1, 'the new token has a Revoke button right away')
await page.reload()
const row = page.locator('#scim-tokens li', { hasText: 'browser-test' })
await row.waitFor()
check(true, 'the token is still listed after a reload')
check(!(await page.content()).includes(token), 'and the plain token is not in the page any more')
await row.locator('.scim-revoke').click()
await page.locator('#scim-tokens li', { hasText: 'browser-test' }).waitFor({ state: 'detached', timeout: 10000 })
if (callScim) check((await scim(token)).status === 401, 'a revoked token is refused')

console.log('Settings: save and keep')
await page.selectOption('#scim-mode', 'disable_purge')
await page.fill('#scim-days', '14')
await page.fill('#scim-group-regex', '^nc-')
await page.fill('#scim-group-prefix', 'SAML_')
await page.fill('#scim-adopt-regex', '^adopt-')
await page.click('#scim-save')
await page.locator('#scim-status', { hasText: '✓' }).waitFor({ timeout: 10000 })
check(true, 'settings save')
await shot(page, 'admin-settings-saved')
await page.reload()
check(await page.inputValue('#scim-mode') === 'disable_purge' && await page.inputValue('#scim-days') === '14', 'delete mode and days are kept')
check(await page.inputValue('#scim-group-regex') === '^nc-' && await page.inputValue('#scim-group-prefix') === 'SAML_' && await page.inputValue('#scim-adopt-regex') === '^adopt-', 'group filter, prefix and adopt list are kept')

// The screenshots of the App Store listing (and the repository's screenshots/ folder): the SCIM section with example
// settings, in two parts. Nothing secret is on the page: the plain token only exists right after it is created.
// Pages differ in length (All-in-One has more above the section): make the viewport tall enough for the whole section,
// so nothing scrolls and the crop regions are inside the image.
await section.scrollIntoViewIfNeeded()
let sec = await section.boundingBox()
if (sec) {
	const needed = Math.ceil(sec.y + sec.height + 64)
	if (needed > page.viewportSize().height) {
		await page.setViewportSize({ width: 1440, height: needed })
		await page.waitForTimeout(500)
		sec = await section.boundingBox()
	}
}
const groups = await page.locator('#scim-server h3', { hasText: 'Groups' }).boundingBox()
if (sec && groups) {
	const pad = 16
	const x = Math.max(0, sec.x - pad)
	const width = sec.width + 2 * pad
	await storeShot(page, '1-connection-and-deprovisioning', { x, y: Math.max(0, sec.y - pad), width, height: groups.y - sec.y + pad })
	await storeShot(page, '2-groups', { x, y: groups.y - pad, width, height: sec.y + sec.height - groups.y + 2 * pad })
	check(true, 'store screenshots saved')
} else {
	check(false, 'store screenshots saved', 'the SCIM section or the Groups heading was not found')
}

console.log('Settings: an invalid regular expression is refused')
await page.fill('#scim-group-regex', '(unclosed')
await page.click('#scim-save')
await page.locator('#scim-status', { hasText: /regular expression/i }).waitFor({ timeout: 10000 })
check(true, 'the error is shown')
await shot(page, 'admin-settings-invalid-regex')
await page.reload()
check(await page.inputValue('#scim-group-regex') === '^nc-', 'and nothing was saved')

console.log('Settings: back to the defaults')
await page.selectOption('#scim-mode', 'disable_purge')
await page.fill('#scim-days', '30')
await page.fill('#scim-group-regex', '')
await page.fill('#scim-group-prefix', '')
await page.fill('#scim-adopt-regex', '')
await page.click('#scim-save')
await page.locator('#scim-status', { hasText: '✓' }).waitFor({ timeout: 10000 })
check(true, 'defaults saved')

await browser.close()
console.log(failures === 0 ? '\nall passed' : `\n${failures} failed`)
process.exit(failures === 0 ? 0 : 1)
