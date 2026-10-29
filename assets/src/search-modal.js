/**
 * Search popup: opens from the theme's own search field and button, and shows
 * results with content-type tabs as the visitor types.
 *
 * The All tab is an overview, one section of cards per content type with a
 * "See all" link into that type's tab. A type tab is one grid that loads its
 * next page as the visitor scrolls to the end of it, through a real "Load more"
 * button that is pressed for them when it comes into view — so a keyboard or
 * screen-reader user, who does not scroll to trigger anything, can still reach
 * every result.
 *
 * No framework, for the reason instant-search.js gives. The one dependency is
 * WordPress's own i18n package, which core already ships, because "1 result" and
 * "2 results" are different sentences in most languages and only a real plural
 * function can say both.
 *
 * Markup comes from the page, not from this file. The dialog and every result
 * card are PHP templates a theme can override, printed once into the page; this
 * script finds their parts by `data-solrengine-*` attribute and fills copies.
 * Text is only ever inserted as text.
 *
 * Accessibility, in short: a native modal <dialog> (focus is contained and
 * Escape closes); a plain search field; results as lists of ordinary links,
 * reached with Tab like any other link and also with the arrow keys, which move
 * real focus rather than a virtual one; each link named by its result's title
 * and described by its type and price; tabs that follow the tabs pattern with
 * the results as their panel; and a live region announcing each result count.
 *
 * Not a combobox. A combobox's listbox is for choosing a value, and its options
 * cannot contain links — a card is a link to a page, so that pattern would
 * either nest interactive elements (which screen readers announce badly) or
 * give up the link and with it opening a result in a new tab.
 * The popup opens on click, typing or Enter — never on focus alone, so tabbing
 * through a page header does not throw a dialog at a keyboard user (WCAG
 * 3.2.1).
 */

import { __, _n, sprintf } from '@wordpress/i18n';
import './search-modal.css';

const SETTINGS = window.solrEngineSearchModal || {};

/** Milliseconds to wait after the last keystroke before asking the server. */
const DEBOUNCE_MS = 150;

/** Responses kept in memory, per page view. */
const CACHE_LIMIT = 50;

/** How far ahead of the end of a tab's grid the next page is fetched, in px. */
const PREFETCH_PX = 600;

/** Keeps a currency symbol on the same line as its amount. */
const NBSP = '\u00a0';

/**
 * Whether an element matches a selector a site supplied.
 *
 * A malformed selector from a filter must not take the page's search down with
 * it, so a syntax error reads as "no match".
 *
 * @param {Element} element  Element to test.
 * @param {string}  selector Selector.
 * @return {Element|null} The closest match, or null.
 */
function closest( element, selector ) {
	if ( ! selector ) {
		return null;
	}

	try {
		return element.closest( selector );
	} catch ( error ) {
		return null;
	}
}

/**
 * An address that is safe to navigate to or load.
 *
 * Only http and https. Indexed URLs come from WordPress, but this is the last
 * point before one becomes an href, so a javascript: URL is refused here
 * rather than trusted.
 *
 * @param {*} value Candidate URL.
 * @return {string} The URL, or ''.
 */
function safeUrl( value ) {
	if ( typeof value !== 'string' || '' === value ) {
		return '';
	}

	try {
		const url = new URL( value, window.location.href );

		return 'http:' === url.protocol || 'https:' === url.protocol
			? url.href
			: '';
	} catch ( error ) {
		return '';
	}
}

/**
 * Escape text for use inside a regular expression.
 *
 * @param {string} text Text.
 * @return {string} Escaped text.
 */
function escapeRegExp( text ) {
	return text.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
}

/**
 * Wire the popup.
 *
 * @param {HTMLDialogElement} dialog The dialog printed by search-modal.php.
 */
function attach( dialog ) {
	const part = ( name ) =>
		dialog.querySelector( `[data-solrengine-${ name }]` );

	const input = part( 'input' );
	const results = part( 'results' );

	if ( ! input || ! results ) {
		return;
	}

	const form = part( 'form' );
	const tabInput = part( 'tab-input' );
	const clear = part( 'clear' );
	const closeButton = part( 'close' );
	const tabList = part( 'tabs' );
	const summaryRow = part( 'summary-row' );
	const summary = part( 'summary' );
	const message = part( 'message' );
	const status = part( 'status' );
	const viewAll = part( 'view-all' );
	const more = part( 'more' );
	const moreLabel = more?.querySelector( '.solrengine-modal__more-label' );
	const tabTemplate = dialog.querySelector(
		'template[data-solrengine-template="tab"]',
	);
	const sectionTemplate = dialog.querySelector(
		'template[data-solrengine-template="section"]',
	);

	const cards = new Map();

	document
		.querySelectorAll( 'template[data-solrengine-template="result"]' )
		.forEach( ( template ) => {
			cards.set( template.dataset.solrenginePostType || '', template );
		} );

	const types = new Map(
		( SETTINGS.postTypes || [] ).map( ( type ) => [ type.key, type ] ),
	);
	const minimum = SETTINGS.minimum || 2;
	const locale = SETTINGS.locale || undefined;
	const cache = new Map();

	let numberFormat;

	try {
		numberFormat = new window.Intl.NumberFormat( locale );
	} catch ( error ) {
		numberFormat = new window.Intl.NumberFormat();
	}

	let text = '';
	let tab = '';
	let items = [];
	let timer = null;
	let controller = null;
	let opener = null;
	let last = null;

	// Paging state of a type tab.
	let page = 1;
	let hasMore = false;
	let grid = null;
	let moreController = null;
	let moreInView = false;

	const baseId = results.id || 'solrengine-modal-results';

	results.id = baseId;

	input.setAttribute( 'aria-controls', baseId );

	/**
	 * Fill a template's slots.
	 *
	 * @param {Element}  root  Copy of a template's element.
	 * @param {Function} value Returns the value for a field name.
	 * @param {Function} write Writes a field into an element.
	 */
	function fill( root, value, write ) {
		const within = ( selector ) => [
			...( root.matches( selector ) ? [ root ] : [] ),
			...root.querySelectorAll( selector ),
		];

		within( '[data-solrengine-if]' ).forEach( ( element ) => {
			const found = value( element.dataset.solrengineIf );

			if (
				element !== root &&
				( undefined === found || null === found || '' === found )
			) {
				element.remove();
			}
		} );

		within( '[data-solrengine-field]' ).forEach( ( element ) =>
			write( element, element.dataset.solrengineField ),
		);

		within( '[data-solrengine-href]' ).forEach( ( element ) => {
			const url = safeUrl( value( element.dataset.solrengineHref ) );

			if ( url ) {
				element.setAttribute( 'href', url );
			} else {
				element.removeAttribute( 'href' );
			}
		} );

		within( '[data-solrengine-src]' ).forEach( ( element ) => {
			const url = safeUrl( value( element.dataset.solrengineSrc ) );

			if ( url ) {
				element.setAttribute( 'src', url );
			} else {
				element.remove();
			}
		} );
	}

	/**
	 * The title as text, with the searched words marked.
	 *
	 * @param {string} title Title.
	 * @return {Node[]} Nodes to insert.
	 */
	function highlight( title ) {
		const words = [
			...new Set(
				text
					.toLowerCase()
					.split( /\s+/ )
					.filter( ( word ) => word.length >= minimum ),
			),
		].sort( ( a, b ) => b.length - a.length );

		if ( ! words.length ) {
			return [ document.createTextNode( title ) ];
		}

		const pattern = new RegExp(
			`(${ words.map( escapeRegExp ).join( '|' ) })`,
			'giu',
		);

		// split() with a capturing group puts each match at an odd index.
		return title.split( pattern ).map( ( piece, index ) => {
			if ( index % 2 === 0 ) {
				return document.createTextNode( piece );
			}

			const mark = document.createElement( 'mark' );
			mark.className = 'solrengine-mark';
			mark.textContent = piece;

			return mark;
		} );
	}

	/**
	 * An amount in the store's currency format.
	 *
	 * @param {number} amount Amount.
	 * @return {string} Formatted amount.
	 */
	function money( amount ) {
		const format = SETTINGS.price;
		const decimals = Number.isInteger( format.decimals )
			? format.decimals
			: 2;
		const [ whole, fraction ] = Math.abs( amount )
			.toFixed( decimals )
			.split( '.' );
		const grouped = whole.replace(
			/\B(?=(\d{3})+(?!\d))/g,
			format.thousand,
		);
		const number = fraction ? grouped + format.decimal + fraction : grouped;

		switch ( format.position ) {
			case 'right':
				return number + format.symbol;
			case 'left_space':
				return format.symbol + NBSP + number;
			case 'right_space':
				return number + NBSP + format.symbol;
			default:
				return format.symbol + number;
		}
	}

	/**
	 * A product's price, as WooCommerce marks one up.
	 *
	 * @param {Object} price Price block from the response.
	 * @return {Node} Price element.
	 */
	function priceNode( price ) {
		const wrapper = document.createElement( 'span' );
		wrapper.className = 'solrengine-price';

		// Indexed prices are base prices. Where the store shows prices with
		// tax, scale by the ratio the index recorded for this product.
		const factor =
			'incl' === SETTINGS.price.taxDisplay &&
			price.current_incl_tax &&
			price.current > 0
				? price.current_incl_tax / price.current
				: 1;

		const add = ( tag, className, content, hidden ) => {
			const element = document.createElement( tag );

			if ( className ) {
				element.className = className;
			}

			if ( hidden ) {
				element.setAttribute( 'aria-hidden', 'true' );
			}

			element.textContent = content;
			wrapper.appendChild( element );
		};

		if ( undefined !== price.min && undefined !== price.max ) {
			add(
				'span',
				'solrengine-price__current',
				`${ money( price.min * factor ) } – ${ money(
					price.max * factor,
				) }`,
			);

			return wrapper;
		}

		const current = money( price.current * factor );

		if ( undefined === price.regular ) {
			add( 'span', 'solrengine-price__current', current );

			return wrapper;
		}

		const regular = money( price.regular * factor );

		add( 'span', 'solrengine-price__current', current, true );
		add( 'del', 'solrengine-price__regular', regular, true );
		add(
			'span',
			'screen-reader-text',
			/* translators: 1: original price, 2: current price. */
			sprintf(
				__(
					'Original price was: %1$s. Current price is: %2$s.',
					'solrengine',
				),
				regular,
				current,
			),
		);

		return wrapper;
	}

	/**
	 * A date in the site's language.
	 *
	 * @param {string} value ISO date.
	 * @return {string} Formatted date, or ''.
	 */
	function date( value ) {
		if ( ! value ) {
			return '';
		}

		try {
			return new window.Intl.DateTimeFormat( locale, {
				dateStyle: 'medium',
			} ).format( new Date( value ) );
		} catch ( error ) {
			return '';
		}
	}

	/**
	 * A result's value for a template field.
	 *
	 * @param {Object} result Result.
	 * @param {string} field  Field name.
	 * @return {*} Value.
	 */
	function resultValue( result, field ) {
		if ( 'type' === field ) {
			return types.get( result.post_type )?.singular || result.post_type;
		}

		if ( 'price' === field ) {
			return result.price && SETTINGS.price ? result.price : null;
		}

		return result[ field ];
	}

	/**
	 * Build one result card.
	 *
	 * @param {Object} result Result.
	 * @param {number} index  Position in `items`.
	 * @return {Element|null} The card, or null when there is no template.
	 */
	function card( result, index ) {
		const template = cards.get( result.post_type ) || cards.get( '' );
		const source = template?.content.firstElementChild;

		if ( ! source ) {
			return null;
		}

		const element = source.cloneNode( true );

		element.id = `${ baseId }-${ index }`;
		element.dataset.solrengineIndex = String( index );

		// A copy of the template from before 0.11.0 shipped: the card is a
		// list item now, and its link is reached like any other.
		if ( 'option' === element.getAttribute( 'role' ) ) {
			element.removeAttribute( 'role' );
		}

		element.removeAttribute( 'aria-selected' );

		fill(
			element,
			( field ) => resultValue( result, field ),
			( target, field ) => {
				const value = resultValue( result, field );

				if ( 'title' === field ) {
					target.replaceChildren(
						...highlight( String( value || '' ) ),
					);
				} else if ( 'price' === field ) {
					target.replaceChildren(
						...( value ? [ priceNode( value ) ] : [] ),
					);
				} else if ( 'date' === field ) {
					target.textContent = date( value );
				} else {
					target.textContent =
						typeof value === 'string' || typeof value === 'number'
							? String( value )
							: '';
				}
			},
		);

		nameLink( element, result );

		element.addEventListener( 'click', ( event ) => {
			// A click on the link itself navigates natively, so a middle
			// click or a modified click still opens a new tab. Only the
			// space around it needs help.
			if ( ! event.target.closest( 'a[href]' ) ) {
				follow( element );
			}
		} );

		return element;
	}

	/**
	 * Name a card's link by its title, and describe it by its type and price.
	 *
	 * Without this a link's name is all of its text run together — "Product
	 * Hoodie 42,00 € Original price was…" — which is the first thing a screen
	 * reader says and the last thing anyone wants to hear first.
	 *
	 * @param {Element} element Card.
	 * @param {Object}  result  Result.
	 */
	function nameLink( element, result ) {
		const link = element.matches( '[data-solrengine-href]' )
			? element
			: element.querySelector( '[data-solrengine-href]' );

		if ( ! link ) {
			return;
		}

		if ( '-1' === link.getAttribute( 'tabindex' ) ) {
			link.removeAttribute( 'tabindex' );
		}

		const title = String( result.title || '' ).trim();

		if ( title ) {
			link.setAttribute( 'aria-label', title );
		}

		const described = [ 'type', 'price', 'date', 'author' ]
			.map( ( field ) =>
				element.querySelector( `[data-solrengine-field="${ field }"]` ),
			)
			.filter( ( slot ) => slot && '' !== slot.textContent.trim() )
			.map( ( slot ) => {
				slot.id ||= `${ element.id }-${ slot.dataset.solrengineField }`;

				return slot.id;
			} );

		if ( described.length ) {
			link.setAttribute( 'aria-describedby', described.join( ' ' ) );
		}
	}

	/**
	 * Build one section: a heading row and a list of its cards.
	 *
	 * @param {string}  type   Post type.
	 * @param {number}  total  Matches of that type.
	 * @param {boolean} header Whether to keep the heading row (the All tab).
	 * @return {{element: Element, list: Element}} The section and its list.
	 */
	function section( type, total, header ) {
		const source = sectionTemplate?.content.firstElementChild;
		const element = source
			? source.cloneNode( true )
			: document.createElement( 'section' );
		let list = element.querySelector( '[data-solrengine-section-results]' );

		if ( ! list ) {
			list = document.createElement( 'ul' );
			element.appendChild( list );
		}

		const label = types.get( type )?.plural || type;
		const id = `${ baseId }-section-${ type || 'all' }`;
		const head = element.querySelector( '[data-solrengine-section-header]' );

		list.id = `${ id }-list`;
		list.dataset.solrengineSectionResults = '';
		// Lists styled without bullets lose their list semantics in Safari;
		// the role puts them back, so "list, 4 items" is still announced.
		list.setAttribute( 'role', 'list' );
		element.dataset.solrengineSection = type;
		element.dataset.solrengineTotal = String( total );

		if ( header && head ) {
			const title = head.querySelector( 'h1, h2, h3, h4, h5, h6' ) || head;

			title.id = `${ id }-title`;
			list.setAttribute( 'aria-labelledby', title.id );
		} else {
			head?.remove();
			list.setAttribute( 'aria-label', label );
		}

		fill(
			element,
			() => total,
			( target, field ) => {
				if ( list.contains( target ) ) {
					return;
				}

				if ( 'count' === field ) {
					target.textContent = numberFormat.format( total );
				} else if ( 'see_all' === field ) {
					target.textContent = sprintf(
						/* translators: %s: number of results of one content type. */
						__( 'See all %s', 'solrengine' ),
						numberFormat.format( total ),
					);
					// Visible text first, so a voice user saying what they see
					// still matches the name (WCAG 2.5.3).
					target
						.closest( '[data-solrengine-see-all]' )
						?.setAttribute(
							'aria-label',
							sprintf(
								/* translators: 1: number of results, 2: content type, plural. */
								__( 'See all %1$s: %2$s', 'solrengine' ),
								numberFormat.format( total ),
								label,
							),
						);
				} else if ( 'label' === field ) {
					target.textContent = label;
				}
			},
		);

		element
			.querySelector( '[data-solrengine-see-all]' )
			?.addEventListener( 'click', () => {
				selectTab( type, false );
				input.focus();
			} );

		return { element, list };
	}

	/**
	 * Add results to a section's list.
	 *
	 * @param {Element}  list    The section's list.
	 * @param {Object[]} entries Results.
	 */
	function append( list, entries ) {
		const added = [];

		entries.forEach( ( result ) => {
			const element = card( result, items.length );

			items.push( result );

			if ( element ) {
				list.appendChild( element );
				added.push( [ element, result ] );
			}
		} );

		// After the cards are in the document, so a listener can measure or
		// move them.
		added.forEach( ( [ element, result ] ) => {
			element.dispatchEvent(
				new window.CustomEvent( 'solrengine:result', {
					bubbles: true,
					detail: { result, element },
				} ),
			);
		} );
	}

	/**
	 * Trim each All-tab section to one row at the current column count.
	 *
	 * The server sends a row's worth for the widest layout; a narrower one
	 * hides the cards that would start a second row, and its "See all" link
	 * then also stands for them. Two columns keep two rows: a single card
	 * beside a heading is too little to judge a type by.
	 */
	function fitSections() {
		if ( tab ) {
			return;
		}

		results.querySelectorAll( '[data-solrengine-section]' ).forEach( ( group ) => {
			const list = group.querySelector( '[data-solrengine-section-results]' );
			const cardsIn = [ ...list.querySelectorAll( '[data-solrengine-index]' ) ];
			const columns = window
				.getComputedStyle( list )
				.gridTemplateColumns.split( ' ' )
				.filter( Boolean ).length;
			const visible = columns >= 3 ? columns : Math.max( 1, columns ) * 2;
			const seeAll = group.querySelector( '[data-solrengine-see-all]' );

			cardsIn.forEach( ( element, i ) => {
				element.hidden = i >= visible;
			} );

			if ( seeAll ) {
				seeAll.hidden =
					Number( group.dataset.solrengineTotal ) <=
					Math.min( visible, cardsIn.length );
			}
		} );
	}

	/**
	 * Show or hide the "Load more" button for a type tab.
	 *
	 * @param {string} state '', 'loading' or 'failed'.
	 */
	function syncMore( state = '' ) {
		if ( ! more ) {
			return;
		}

		more.hidden = ! hasMore || ! tab;
		// aria-disabled rather than disabled: disabling the button a keyboard
		// user is on throws their focus to the top of the page.
		more.setAttribute(
			'aria-disabled',
			'loading' === state ? 'true' : 'false',
		);
		more.classList.toggle( 'is-loading', 'loading' === state );

		if ( moreLabel ) {
			moreLabel.textContent =
				'failed' === state
					? __( 'Could not load more. Try again', 'solrengine' )
					: __( 'Load more results', 'solrengine' );
		}
	}

	/**
	 * Build one tab.
	 *
	 * @param {Object} entry Tab: key, label and count.
	 * @return {Element} The tab.
	 */
	function tabButton( entry ) {
		const source = tabTemplate?.content.firstElementChild;
		const button = source
			? source.cloneNode( true )
			: document.createElement( 'button' );
		const selected = entry.key === tab;

		if ( ! source ) {
			button.type = 'button';
			button.className = 'solrengine-tab';
			button.textContent = `${ entry.label } ${ entry.count }`;
		}

		button.id = `${ baseId }-tab-${ entry.key || 'all' }`;
		button.setAttribute( 'role', 'tab' );
		button.setAttribute( 'aria-selected', selected ? 'true' : 'false' );
		button.setAttribute( 'aria-controls', results.id );
		button.tabIndex = selected ? 0 : -1;
		button.classList.toggle( 'is-selected', selected );
		button.dataset.solrengineTab = entry.key;

		fill(
			button,
			( field ) => entry[ field ],
			( target, field ) => {
				target.textContent =
					'count' === field
						? numberFormat.format( entry.count )
						: entry.label;
			},
		);

		return button;
	}

	/**
	 * Render the tab row.
	 *
	 * Every type with a match, in the site's order, and the selected one even
	 * at zero so a visitor is never left on a tab that has disappeared.
	 *
	 * @param {Object} data Response.
	 */
	function renderTabs( data ) {
		const counts = data.counts || {};

		if ( ! data.all && ! tab ) {
			tabList.hidden = true;
			tabList.replaceChildren();
			results.removeAttribute( 'role' );
			results.removeAttribute( 'aria-labelledby' );

			return;
		}

		const entries = [
			{ key: '', label: __( 'All', 'solrengine' ), count: data.all || 0 },
		];

		( SETTINGS.postTypes || [] ).forEach( ( type ) => {
			const count = counts[ type.key ] || 0;

			if ( count > 0 || type.key === tab ) {
				entries.push( { key: type.key, label: type.plural, count } );
			}
		} );

		tabList.replaceChildren( ...entries.map( tabButton ) );
		tabList.hidden = false;

		// The results are what the tabs switch between.
		results.setAttribute( 'role', 'tabpanel' );
		results.setAttribute( 'aria-labelledby', `${ baseId }-tab-${ tab || 'all' }` );
	}

	/**
	 * Render a response.
	 *
	 * @param {Object} data Response.
	 */
	function render( data ) {
		last = data;
		items = [];
		grid = null;

		if ( moreController ) {
			moreController.abort();
			moreController = null;
		}

		renderTabs( data );

		const groups = [];

		if ( tab ) {
			const { element, list } = section( tab, data.total || 0, false );

			append( list, Array.isArray( data.results ) ? data.results : [] );
			grid = list;
			groups.push( element );
		} else {
			( Array.isArray( data.sections ) ? data.sections : [] ).forEach(
				( entry ) => {
					const { element, list } = section(
						entry.post_type,
						entry.total || 0,
						true,
					);

					append( list, entry.results || [] );
					groups.push( element );
				},
			);
		}

		results.replaceChildren( ...( items.length ? groups : [] ) );
		scroller().scrollTop = 0;

		page = data.page || 1;
		hasMore = !! data.has_more && !! tab;
		syncMore();
		fitSections();

		dialog.classList.toggle( 'has-results', items.length > 0 );

		if ( ! items.length ) {
			summaryRow.hidden = true;
			message.textContent = sprintf(
				/* translators: %s: the text searched for. */
				__(
					'No results for “%s”. Check the spelling or try fewer words.',
					'solrengine',
				),
				data.query,
			);
			status.textContent = message.textContent;

			return;
		}

		summary.textContent = sprintf(
			/* translators: 1: number of results, 2: the text searched for. */
			_n(
				'%1$s result for “%2$s”',
				'%1$s results for “%2$s”',
				data.total,
				'solrengine',
			),
			numberFormat.format( data.total ),
			data.query,
		);
		summaryRow.hidden = false;
		message.textContent = '';

		if ( 'standard' !== data.strategy ) {
			message.textContent = __(
				'No exact matches, so these are the closest.',
				'solrengine',
			);
		}

		status.textContent = summary.textContent;
	}

	/**
	 * Show the empty state before anything has been typed.
	 */
	function idle() {
		last = null;
		items = [];
		grid = null;
		hasMore = false;
		results.replaceChildren();
		results.removeAttribute( 'role' );
		results.removeAttribute( 'aria-labelledby' );
		syncMore();
		dialog.classList.remove( 'has-results' );
		tabList.hidden = true;
		summaryRow.hidden = true;
		message.textContent = __(
			'Start typing to see results.',
			'solrengine',
		);
		status.textContent = '';
		busy( false );
	}

	/**
	 * Show that a request failed.
	 *
	 * @param {number} code HTTP status, or 0 for a network failure.
	 */
	function failed( code ) {
		idle();

		message.textContent = __(
			'Instant results are unavailable right now. Press Enter to search the site instead.',
			'solrengine',
		);

		if ( 429 === code ) {
			message.textContent = __(
				'That was a lot of searches at once. Please wait a moment and try again.',
				'solrengine',
			);
		}

		status.textContent = message.textContent;
	}

	/**
	 * Mark the popup as waiting.
	 *
	 * Previous results stay in place while the next arrive, dimmed, rather
	 * than the panel emptying and refilling on every pause in typing.
	 *
	 * @param {boolean} state Whether a request is in flight.
	 */
	function busy( state ) {
		dialog.classList.toggle( 'is-loading', state );
		results.setAttribute( 'aria-busy', state ? 'true' : 'false' );
	}

	/**
	 * Point the "View all results" link and the form at the current search.
	 */
	function syncLinks() {
		if ( tabInput ) {
			tabInput.value = tab;
			tabInput.disabled = '' === tab;
		}

		if ( clear ) {
			clear.hidden = '' === input.value;
		}

		if ( ! viewAll ) {
			return;
		}

		const url = new URL( SETTINGS.searchUrl || '/', window.location.href );
		url.searchParams.set( 's', text );

		if ( tab ) {
			url.searchParams.set( 'post_type', tab );
		}

		viewAll.href = url.href;
		viewAll.hidden = '' === text;
	}

	/**
	 * Ask one endpoint.
	 *
	 * @param {string}      url    Endpoint.
	 * @param {string}      value  Text.
	 * @param {string}      type   Tab.
	 * @param {number}      number Page.
	 * @param {AbortSignal} signal Cancellation.
	 * @return {Promise<{response: Response, body: *}>} Response and parsed body.
	 */
	async function get( url, value, type, number, signal ) {
		const target = new URL( url, window.location.href );

		target.searchParams.set( 'q', value );

		if ( type ) {
			target.searchParams.set( 'post_type', type );
		}

		if ( number > 1 ) {
			target.searchParams.set( 'page', String( number ) );
		}

		const response = await window.fetch( target, {
			signal,
			credentials: 'same-origin',
			headers: { Accept: 'application/json' },
		} );

		let body = null;

		try {
			body = await response.json();
		} catch ( error ) {
			body = null;
		}

		return { response, body };
	}

	/**
	 * Whether a reply came from SolrEngine.
	 *
	 * @param {Response} response Response.
	 * @param {*}        body     Parsed body.
	 * @return {boolean} Whether it did.
	 */
	function ours( response, body ) {
		if ( ! body || typeof body !== 'object' ) {
			return false;
		}

		return response.ok
			? Array.isArray( body.results )
			: typeof body.code === 'string';
	}

	/**
	 * The cache key for a request.
	 *
	 * @param {string} value  Text.
	 * @param {string} type   Tab.
	 * @param {number} number Page.
	 * @return {string} Key.
	 */
	function cacheKey( value, type, number ) {
		return `${ type }\n${ number }\n${ value }`;
	}

	/**
	 * Fetch one page.
	 *
	 * @param {string}      value  Text.
	 * @param {string}      type   Tab.
	 * @param {number}      number Page.
	 * @param {AbortSignal} signal Cancellation.
	 * @return {Promise<{ok: boolean, status: number, body: *}>} Outcome.
	 */
	async function fetchPage( value, type, number, signal ) {
		const key = cacheKey( value, type, number );

		if ( cache.has( key ) ) {
			return { ok: true, status: 200, body: cache.get( key ) };
		}

		const { response, body } = await get(
			SETTINGS.endpoint,
			value,
			type,
			number,
			signal,
		);

		if ( ! response.ok || ! ours( response, body ) ) {
			return { ok: false, status: response.status, body: null };
		}

		cache.set( key, body );

		if ( cache.size > CACHE_LIMIT ) {
			cache.delete( cache.keys().next().value );
		}

		return { ok: true, status: response.status, body };
	}

	/**
	 * Fetch the first page for some text and tab.
	 *
	 * @param {string} value Text.
	 * @param {string} type  Tab.
	 */
	async function request( value, type ) {
		if ( controller ) {
			controller.abort();
		}

		const current = new AbortController();
		controller = current;
		busy( true );

		try {
			const { ok, status: code, body } = await fetchPage(
				value,
				type,
				1,
				current.signal,
			);

			if ( ! ok ) {
				failed( code );

				return;
			}

			// A reply for text or a tab the visitor has since moved on from is
			// kept for later but not shown.
			if ( value === text && type === tab ) {
				render( body );
			}
		} catch ( error ) {
			// An aborted request is the normal case: the visitor kept typing.
			if ( 'AbortError' !== error.name ) {
				failed( 0 );
			}
		} finally {
			if ( controller === current ) {
				controller = null;
				busy( false );
			}
		}
	}

	/**
	 * Search for whatever is in the field now.
	 */
	function search() {
		window.clearTimeout( timer );
		text = input.value.trim().replace( /\s+/g, ' ' );
		syncLinks();

		if ( text.length < minimum ) {
			if ( controller ) {
				controller.abort();
			}

			idle();

			return;
		}

		const key = cacheKey( text, tab, 1 );

		if ( cache.has( key ) ) {
			if ( controller ) {
				controller.abort();
			}

			render( cache.get( key ) );

			return;
		}

		request( text, tab );
	}

	/**
	 * Load the next page of a type tab into its grid.
	 *
	 * Focus moves to the first new card when the visitor asked for the page —
	 * ↓ past the last card — or was on the button when it arrived. Loaded by
	 * scrolling, focus stays where it was.
	 *
	 * @param {boolean} focusNew Whether to move focus to the first new card.
	 */
	async function loadMore( focusNew = false ) {
		if ( ! hasMore || ! tab || ! grid || moreController || controller ) {
			return;
		}

		const current = new AbortController();
		const value = text;
		const type = tab;
		const target = grid;

		moreController = current;
		syncMore( 'loading' );

		let state = '';

		try {
			const { ok, body } = await fetchPage(
				value,
				type,
				page + 1,
				current.signal,
			);

			if ( value !== text || type !== tab || target !== grid ) {
				return;
			}

			if ( ! ok ) {
				// Left for the visitor to retry. Not retried automatically: a
				// 429 retried on every scroll is how a rate limit stays hit.
				state = 'failed';

				return;
			}

			const first = items.length;
			const follows =
				true === focusNew ||
				( more && more.contains( document.activeElement ) );

			append( target, Array.isArray( body.results ) ? body.results : [] );

			if ( follows ) {
				cardLink(
					target.querySelector( `[data-solrengine-index="${ first }"]` ),
				)?.focus();
			}

			page = body.page || page + 1;
			hasMore = !! body.has_more;
			status.textContent = sprintf(
				/* translators: 1: number of results shown, 2: number of results. */
				__( 'Showing %1$s of %2$s results.', 'solrengine' ),
				numberFormat.format( items.length ),
				numberFormat.format( body.total || items.length ),
			);
		} catch ( error ) {
			if ( 'AbortError' !== error.name ) {
				state = 'failed';
			}
		} finally {
			if ( moreController === current ) {
				moreController = null;
				syncMore( state );

				// A short page, or a tall screen, can leave the button in view
				// after a load. The observer reports only changes, so ask
				// again here.
				if ( ! state && moreInView ) {
					window.requestAnimationFrame( () => loadMore( false ) );
				}
			}
		}
	}

	/**
	 * The element a card is focused by: its link.
	 *
	 * @param {Element|null} element Card.
	 * @return {HTMLElement|null} The link, or null.
	 */
	function cardLink( element ) {
		if ( ! element ) {
			return null;
		}

		return element.matches( 'a[href]' )
			? element
			: element.querySelector( 'a[href]' );
	}

	/**
	 * The links of the cards a visitor can see, in order.
	 *
	 * @return {HTMLElement[]} Links.
	 */
	function cardLinks() {
		return [
			...results.querySelectorAll( '[data-solrengine-index]:not([hidden])' ),
		]
			.map( cardLink )
			.filter( Boolean );
	}

	/**
	 * The element whose scrolling reveals more results.
	 *
	 * @return {Element} The nearest scrolling ancestor of the results.
	 */
	function scroller() {
		for (
			let element = results.parentElement;
			element && element !== dialog;
			element = element.parentElement
		) {
			const overflow = window.getComputedStyle( element ).overflowY;

			if ( 'auto' === overflow || 'scroll' === overflow ) {
				return element;
			}
		}

		return dialog;
	}

	/**
	 * Go to a card's result.
	 *
	 * @param {Element|undefined} element Card.
	 */
	function follow( element ) {
		const url = safeUrl(
			items[ Number( element?.dataset.solrengineIndex ) ]?.url,
		);

		if ( url ) {
			window.location.assign( url );
		}
	}

	/**
	 * Select a tab.
	 *
	 * @param {string}  key   Post type, or '' for all.
	 * @param {boolean} focus Whether to move focus to it.
	 */
	function selectTab( key, focus ) {
		if ( key !== tab ) {
			tab = key;

			if ( last ) {
				renderTabs( last );
			}

			search();
		}

		if ( focus ) {
			tabList
				.querySelector(
					`[data-solrengine-tab="${ CSS.escape( key ) }"]`,
				)
				?.focus();
		}
	}

	/**
	 * Open the popup.
	 *
	 * @param {string}  value Text to start with.
	 * @param {Element} from  Element to return focus to on close.
	 */
	function open( value, from ) {
		opener = from || document.activeElement;
		input.value = value || '';

		if ( ! dialog.open ) {
			dialog.showModal();
			document.documentElement.classList.add(
				'solrengine-modal-is-open',
			);
		}

		input.focus();

		const end = input.value.length;

		try {
			input.setSelectionRange( end, end );
		} catch ( error ) {
			// Not every input type supports a selection; search does.
		}

		search();
	}

	/** Close the popup. */
	function close() {
		dialog.close();
	}

	dialog.addEventListener( 'close', () => {
		document.documentElement.classList.remove( 'solrengine-modal-is-open' );
		window.clearTimeout( timer );

		if ( controller ) {
			controller.abort();
		}

		// Back to where the visitor was. Focus does not reopen the popup, so
		// this cannot loop.
		if (
			opener &&
			opener.isConnected &&
			typeof opener.focus === 'function'
		) {
			opener.focus( { preventScroll: true } );
		}

		opener = null;
	} );

	// A click outside the panel lands on the dialog itself.
	dialog.addEventListener( 'click', ( event ) => {
		if ( event.target === dialog ) {
			close();
		}
	} );

	closeButton?.addEventListener( 'click', close );

	clear?.addEventListener( 'click', () => {
		input.value = '';
		search();
		input.focus();
	} );

	input.addEventListener( 'input', () => {
		if ( clear ) {
			clear.hidden = '' === input.value;
		}

		window.clearTimeout( timer );
		timer = window.setTimeout( search, DEBOUNCE_MS );
	} );

	input.addEventListener( 'keydown', ( event ) => {
		if ( 'ArrowDown' === event.key && ! event.altKey ) {
			const first = cardLinks()[ 0 ];

			if ( first ) {
				event.preventDefault();
				first.focus();
			}
		} else if ( 'Escape' === event.key ) {
			// Handled here because a search field with text in it takes Escape
			// to mean "clear me", and the dialog never hears it. A visitor
			// pressing Escape in a search popup means "close".
			event.preventDefault();
			close();
		}
		// Enter submits the form to the site's own results page, which is
		// what Enter in a search field has always done.
	} );

	// Arrow keys between cards. Tab reaches them too; this is the quicker
	// way through a grid of them, and the way back up to the field.
	results.addEventListener( 'keydown', ( event ) => {
		const links = cardLinks();
		const index = links.indexOf( event.target );

		if ( index < 0 || event.ctrlKey || event.metaKey || event.altKey ) {
			return;
		}

		const key = event.key;
		const next = { ArrowDown: 1, ArrowRight: 1, ArrowUp: -1, ArrowLeft: -1 };

		if ( key in next ) {
			event.preventDefault();

			const target = index + next[ key ];

			if ( target < 0 ) {
				input.focus();
			} else if ( target < links.length ) {
				links[ target ].focus();
			} else if ( hasMore ) {
				loadMore( true );
			}
		} else if ( 'Home' === key || 'End' === key ) {
			event.preventDefault();
			links[ 'Home' === key ? 0 : links.length - 1 ].focus();
		} else if ( 1 === key.length || 'Backspace' === key ) {
			// Typing on a card goes on typing in the field: focus moves before
			// the key's default action, so the character lands there.
			const end = input.value.length;

			input.focus();

			try {
				input.setSelectionRange( end, end );
			} catch ( error ) {
				// Not every input type supports a selection; search does.
			}
		}
	} );

	more?.addEventListener( 'click', () => {
		if ( 'true' !== more.getAttribute( 'aria-disabled' ) ) {
			loadMore();
		}
	} );

	if ( more && 'IntersectionObserver' in window ) {
		new window.IntersectionObserver(
			( entries ) => {
				moreInView = entries.some( ( entry ) => entry.isIntersecting );

				if ( moreInView ) {
					loadMore( false );
				}
			},
			{ root: scroller(), rootMargin: `0px 0px ${ PREFETCH_PX }px 0px` },
		).observe( more );
	}

	// The All tab's row length follows the panel's width.
	if ( 'ResizeObserver' in window ) {
		let columns = '';

		new window.ResizeObserver( () => {
			const now = window.getComputedStyle(
				results.querySelector( '[data-solrengine-section-results]' ) ||
					results,
			).gridTemplateColumns;

			if ( now !== columns ) {
				columns = now;
				fitSections();
			}
		} ).observe( results );
	}

	form?.addEventListener( 'submit', ( event ) => {
		window.clearTimeout( timer );
		text = input.value.trim();
		syncLinks();

		if ( '' === text ) {
			event.preventDefault();
		}
	} );

	tabList.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( '[data-solrengine-tab]' );

		if ( button ) {
			selectTab( button.dataset.solrengineTab, false );
		}
	} );

	tabList.addEventListener( 'keydown', ( event ) => {
		const buttons = [
			...tabList.querySelectorAll( '[data-solrengine-tab]' ),
		];
		const index = buttons.indexOf(
			event.target.closest( '[data-solrengine-tab]' ),
		);

		if ( index < 0 ) {
			return;
		}

		const moves = {
			ArrowRight: index + 1,
			ArrowLeft: index - 1,
			Home: 0,
			End: buttons.length - 1,
		};

		if ( ! ( event.key in moves ) ) {
			return;
		}

		event.preventDefault();

		const next =
			buttons[ ( moves[ event.key ] + buttons.length ) % buttons.length ];

		selectTab( next.dataset.solrengineTab, true );
	} );

	/**
	 * Whether an element is one of the theme's search fields.
	 *
	 * @param {*} element Element.
	 * @return {boolean} Whether it is.
	 */
	function isField( element ) {
		return (
			element instanceof window.HTMLInputElement &&
			! dialog.contains( element ) &&
			null !== closest( element, SETTINGS.fields )
		);
	}

	/**
	 * The theme search field in a form, if there is one.
	 *
	 * @param {HTMLFormElement|null} owner Form.
	 * @return {HTMLInputElement|undefined} The field.
	 */
	function fieldIn( owner ) {
		return owner ? [ ...owner.elements ].find( isField ) : undefined;
	}

	// Listeners on the document, in the capture phase, so a search form a
	// theme adds after load — an off-canvas menu, a header that is rendered
	// late — opens the popup too, and so the popup sees the event before a
	// theme script that might stop it.
	document.addEventListener(
		'click',
		( event ) => {
			const target =
				event.target instanceof window.Element ? event.target : null;

			if ( ! target || dialog.contains( target ) ) {
				return;
			}

			const trigger = closest( target, SETTINGS.triggers );

			if ( trigger ) {
				event.preventDefault();
				open( '', trigger );

				return;
			}

			if ( isField( target ) ) {
				event.preventDefault();
				open( target.value, target );

				return;
			}

			const button = target.closest(
				'button, input[type="submit"], input[type="image"]',
			);
			const field = button ? fieldIn( button.form ) : undefined;

			if ( field && 'button' !== button.getAttribute( 'type' ) ) {
				event.preventDefault();
				open( field.value, button );
			}
		},
		true,
	);

	// Typing, or pasting, into the theme's field. The character is already in
	// the field when this fires, so it carries over.
	document.addEventListener(
		'input',
		( event ) => {
			if ( isField( event.target ) && ! dialog.open ) {
				open( event.target.value, event.target );
			}
		},
		true,
	);

	document.addEventListener(
		'keydown',
		( event ) => {
			if (
				isField( event.target ) &&
				( 'Enter' === event.key || 'ArrowDown' === event.key ) &&
				! event.isComposing
			) {
				event.preventDefault();
				open( event.target.value, event.target );
			}
		},
		true,
	);

	// Anything else that submits a theme search form.
	document.addEventListener(
		'submit',
		( event ) => {
			const field = fieldIn(
				event.target instanceof window.HTMLFormElement
					? event.target
					: null,
			);

			if ( field ) {
				event.preventDefault();
				open( field.value, field );
			}
		},
		true,
	);
}

/** Find the dialog and wire it. */
function init() {
	const dialog = document.querySelector( '[data-solrengine-modal]' );

	// Without <dialog> support the theme's form is left exactly as it was,
	// which still searches.
	if (
		! dialog ||
		! SETTINGS.endpoint ||
		typeof dialog.showModal !== 'function'
	) {
		return;
	}

	attach( dialog );
}

if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
