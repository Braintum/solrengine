/**
 * Keyboard and axe audit of the search popup, in a real browser on a real site.
 *
 * Usage:
 *
 *     SOLRENGINE_A11Y_URL=http://example.test/ npm run test:a11y
 *
 * Optional environment:
 *
 * - SOLRENGINE_A11Y_QUERY   text to search for; needs results in two or more
 *                           types (default "qu")
 * - SOLRENGINE_A11Y_COOKIE  "name=value", for a site that shows logged-out
 *                           visitors a maintenance or coming-soon page
 * - SOLRENGINE_A11Y_CHANNEL browser channel (default "chrome", the installed
 *                           Chrome; "" for Playwright's own Chromium)
 *
 * Playwright and axe-core come with @wordpress/scripts. Exits non-zero when a
 * check fails. Not run by `composer check`: it needs a running site with
 * results to find. See .claude/skills/solrengine-accessibility/SKILL.md.
 *
 * @package SolrEngine
 */

/* eslint-disable no-console */

const fs = require( 'fs' );
const { chromium } = require( 'playwright' );

const AXE = fs.readFileSync( require.resolve( 'axe-core/axe.min.js' ), 'utf8' );
const BASE = process.env.SOLRENGINE_A11Y_URL || '';
const QUERY = process.env.SOLRENGINE_A11Y_QUERY || 'qu';
const COOKIE = process.env.SOLRENGINE_A11Y_COOKIE || '';
const CHANNEL = process.env.SOLRENGINE_A11Y_CHANNEL ?? 'chrome';
const RULES = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice' ];

const results = [];
const check = ( label, ok, detail = '' ) =>
	results.push( `${ ok ? 'PASS' : 'FAIL' }  ${ label }${ detail ? '  — ' + detail : '' }` );

/** Where focus is, described. */
const focused = ( page ) =>
	page.evaluate( () => {
		const e = document.activeElement;

		return {
			input: !! e?.matches( '[data-solrengine-input]' ),
			card: e?.closest( '[data-solrengine-index]' )?.dataset.solrengineIndex ?? null,
			name: e?.getAttribute( 'aria-label' ) || e?.textContent.replace( /\s+/g, ' ' ).trim().slice( 0, 40 ),
		};
	} );

/** axe violations inside the popup. */
const axe = ( page ) =>
	page.evaluate( async ( rules ) => {
		const report = await window.axe.run( document.querySelector( '[data-solrengine-modal]' ), {
			runOnly: rules,
		} );

		return report.violations.map(
			( v ) => `${ v.impact } ${ v.id } (${ v.nodes.length }): ${ v.nodes[ 0 ].target.join( ' ' ) }`
		);
	}, RULES );

/** Wait until the popup has finished a request. */
const settled = ( page ) =>
	page.waitForFunction(
		() => {
			const d = document.querySelector( '[data-solrengine-modal]' );

			return d && d.open && ! d.classList.contains( 'is-loading' );
		},
		null,
		{ timeout: 15000 }
	);

async function main() {
	if ( ! BASE ) {
		console.error( 'Set SOLRENGINE_A11Y_URL to the site to audit.' );
		process.exit( 2 );
	}

	const browser = await chromium.launch( CHANNEL ? { channel: CHANNEL } : {} );
	const context = await browser.newContext( { viewport: { width: 1280, height: 900 } } );

	if ( COOKIE.includes( '=' ) ) {
		const at = COOKIE.indexOf( '=' );

		await context.addCookies( [ { name: COOKIE.slice( 0, at ), value: COOKIE.slice( at + 1 ), url: BASE } ] );
	}

	const page = await context.newPage();
	const errors = [];
	let pagedType = '';

	page.on( 'pageerror', ( e ) => errors.push( String( e ) ) );

	// Give the first type tab a second page whatever the data, so keyboard
	// paging is exercised on any site: page 1 claims more, page 2 repeats it
	// under new ids.
	await page.route( /instant/, async ( route ) => {
		const url = new URL( route.request().url() );
		const response = await route.fetch();
		const body = await response.json();
		const type = url.searchParams.get( 'post_type' ) || '';

		if ( type && type === pagedType && Array.isArray( body.results ) ) {
			if ( Number( url.searchParams.get( 'page' ) || 1 ) === 1 ) {
				body.has_more = true;
				body.total = body.results.length * 2;
			} else {
				url.searchParams.delete( 'page' );

				const first = await ( await route.fetch( { url: url.href } ) ).json();

				body.results = first.results.map( ( r ) => ( { ...r, id: r.id + 1e6, title: 'Page 2: ' + r.title } ) );
				body.page = 2;
				body.has_more = false;
				body.total = first.results.length * 2;
			}
		}

		await route.fulfill( { response, json: body } );
	} );

	await page.goto( BASE, { waitUntil: 'networkidle' } );

	const field = page
		.locator( 'input[name="s"]:not([data-solrengine-input]):visible, input[type="search"]:not([data-solrengine-input]):visible' )
		.first();

	if ( ! ( await field.count() ) ) {
		console.error( 'No visible search field on that page.' );
		process.exit( 2 );
	}

	// Opening: focus alone must not, typing must.
	await field.focus();
	check( 'focus alone does not open the popup', ! ( await page.evaluate( () => document.querySelector( '[data-solrengine-modal]' ).open ) ) );
	await page.keyboard.type( QUERY );
	await settled( page );
	await page.waitForSelector( '[data-solrengine-index]' );

	const opened = await focused( page );

	check( 'typing in the theme field opens the popup with focus in its field', opened.input );

	// Markup.
	const cards = await page.evaluate( () =>
		[ ...document.querySelectorAll( '[data-solrengine-index]:not([hidden])' ) ].map( ( card ) => {
			const link = card.querySelector( 'a[href]' );

			return {
				role: card.getAttribute( 'role' ),
				label: link?.getAttribute( 'aria-label' ),
				tabindex: link?.getAttribute( 'tabindex' ),
				described: ( link?.getAttribute( 'aria-describedby' ) || '' )
					.split( ' ' )
					.filter( Boolean )
					.map( ( id ) => document.getElementById( id )?.textContent.trim() )
					.join( ' / ' ),
			};
		} )
	);

	check( 'every card has a link named by its title', cards.length > 0 && cards.every( ( c ) => c.label ) );
	check( 'card links are in the tab order', cards.every( ( c ) => null === c.tabindex ) );
	check( 'cards are plain list items, not listbox options', cards.every( ( c ) => 'option' !== c.role ) );
	check( 'card links are described', cards.every( ( c ) => c.described ), cards[ 0 ]?.described );

	const panel = await page.evaluate( () => {
		const r = document.querySelector( '[data-solrengine-results]' );

		return {
			role: r.getAttribute( 'role' ),
			by: document.getElementById( r.getAttribute( 'aria-labelledby' ) )?.getAttribute( 'role' ),
		};
	} );

	check( 'results are the tabs\' tabpanel', 'tabpanel' === panel.role && 'tab' === panel.by );

	// Tab order.
	const order = [];

	await page.locator( '[data-solrengine-input]' ).focus();

	for ( let i = 0; i < 10; i++ ) {
		await page.keyboard.press( 'Tab' );

		const f = await focused( page );

		order.push( null !== f.card ? `card ${ f.card }` : f.name );
	}

	check( 'Tab reaches the cards', order.some( ( o ) => o.startsWith( 'card' ) ), order.join( ' → ' ) );

	// Arrow keys.
	await page.locator( '[data-solrengine-input]' ).focus();
	await page.keyboard.press( 'ArrowDown' );

	const first = await focused( page );

	await page.keyboard.press( 'ArrowDown' );

	const second = await focused( page );

	await page.keyboard.press( 'ArrowUp' );
	await page.keyboard.press( 'ArrowUp' );

	const back = await focused( page );

	check( '↓ from the field focuses the first card, ↓ the next', '0' === first.card && '1' === second.card );
	check( '↑ from the first card returns to the field', back.input );

	await page.keyboard.press( 'ArrowDown' );
	await page.keyboard.press( 'End' );

	const end = await focused( page );

	await page.keyboard.press( 'Home' );

	const home = await focused( page );

	check( 'End and Home reach the last and first card', '0' === home.card && null !== end.card && '0' !== end.card );

	const before = await page.locator( '[data-solrengine-input]' ).inputValue();

	await page.keyboard.type( 'x' );

	const typed = await focused( page );
	const after = await page.locator( '[data-solrengine-input]' ).inputValue();

	check( 'typing on a card continues in the field', typed.input && after === before + 'x' );
	await page.keyboard.press( 'Backspace' );
	await settled( page );

	await page.addScriptTag( { content: AXE } );

	const allViolations = await axe( page );

	check( 'axe: no violations on All', 0 === allViolations.length, allViolations.join( ' | ' ) );

	// A type tab: paging by keyboard.
	pagedType = await page.evaluate( () => document.querySelector( '[data-solrengine-tab]:not([data-solrengine-tab=""])' )?.dataset.solrengineTab || '' );

	if ( pagedType ) {
		await page.locator( `[data-solrengine-tab="${ pagedType }"]` ).focus();
		await page.keyboard.press( 'Enter' );
		await settled( page );

		const count = await page.locator( '[data-solrengine-index]' ).count();

		await page.locator( '[data-solrengine-input]' ).focus();
		await page.keyboard.press( 'ArrowDown' );
		await page.keyboard.press( 'End' );
		await page.keyboard.press( 'ArrowDown' );
		await page.waitForFunction( ( n ) => document.querySelectorAll( '[data-solrengine-index]' ).length > n, count, { timeout: 15000 } );

		const paged = await focused( page );

		check( '↓ past the last card loads the next page and focuses its first card', String( count ) === paged.card, paged.name );

		const tabViolations = await axe( page );

		check( 'axe: no violations on a type tab', 0 === tabViolations.length, tabViolations.join( ' | ' ) );
	}

	// Closing.
	await page.keyboard.press( 'Escape' );

	const closed = await page.evaluate( () => ( {
		open: document.querySelector( '[data-solrengine-modal]' ).open,
		inPopup: !! document.activeElement?.closest( '[data-solrengine-modal]' ),
		body: document.activeElement === document.body,
	} ) );

	check( 'Escape closes and returns focus to the page', ! closed.open && ! closed.inPopup && ! closed.body );
	check( 'no script errors', 0 === errors.length, errors.join( ' | ' ) );

	await browser.close();

	console.log( results.join( '\n' ) );
	process.exit( results.some( ( line ) => line.startsWith( 'FAIL' ) ) ? 1 : 0 );
}

main().catch( ( error ) => {
	console.error( error );
	process.exit( 2 );
} );
