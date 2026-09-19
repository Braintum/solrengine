/**
 * Instant search: attaches autocomplete to a theme's own search field.
 *
 * No framework. A dropdown under an input does not justify shipping a runtime,
 * and a plugin that pulled React in for this would rightly be criticised for it.
 *
 * The accessibility work here is not optional decoration. An autocomplete that
 * cannot be driven from the keyboard, or that changes what is on screen without
 * telling a screen reader, is a search box that some visitors cannot use at all.
 * This implements the combobox pattern: the input owns the listbox, one option
 * is active at a time, and the active option is announced through
 * aria-activedescendant rather than by moving focus.
 */

import './instant-search.css';

const SETTINGS = window.solrEngineInstantSearch || {};

/** Milliseconds to wait after the last keystroke before asking the server. */
const DEBOUNCE_MS = 150;

/** Shortest prefix worth a request. Mirrors the server, which enforces it too. */
const MIN_LENGTH = 2;

let counter = 0;

/**
 * Wire one search input.
 *
 * @param {HTMLInputElement} input The search field.
 */
function attach( input ) {
	if ( input.dataset.solrengineAttached ) {
		return;
	}

	input.dataset.solrengineAttached = '1';

	const id = `solrengine-listbox-${ ++counter }`;
	const listbox = document.createElement( 'ul' );

	listbox.id = id;
	listbox.className = 'solrengine-suggestions';
	listbox.setAttribute( 'role', 'listbox' );
	listbox.hidden = true;

	// A live region announcing how many suggestions appeared. Without it, a
	// screen reader user types and hears nothing change.
	const status = document.createElement( 'div' );
	status.className = 'screen-reader-text';
	status.setAttribute( 'aria-live', 'polite' );

	const wrapper = document.createElement( 'div' );
	wrapper.className = 'solrengine-instant-search';

	input.parentNode.insertBefore( wrapper, input );
	wrapper.appendChild( input );
	wrapper.appendChild( listbox );
	wrapper.appendChild( status );

	input.setAttribute( 'role', 'combobox' );
	input.setAttribute( 'aria-expanded', 'false' );
	input.setAttribute( 'aria-controls', id );
	input.setAttribute( 'aria-autocomplete', 'list' );
	// Browsers' own history dropdown would cover ours.
	input.setAttribute( 'autocomplete', 'off' );

	let items = [];
	let active = -1;
	let timer = null;
	let controller = null;

	/** Hide the list and reset state. */
	function close() {
		listbox.hidden = true;
		listbox.replaceChildren();
		input.setAttribute( 'aria-expanded', 'false' );
		input.removeAttribute( 'aria-activedescendant' );
		items = [];
		active = -1;
	}

	/**
	 * Mark one option as active.
	 *
	 * Focus stays in the input throughout. Moving focus into the list would
	 * break typing, which is the entire point of an autocomplete.
	 *
	 * @param {number} index Index to activate, or -1 for none.
	 */
	function setActive( index ) {
		const options = listbox.querySelectorAll( '[role="option"]' );

		options.forEach( ( option, i ) => {
			const isActive = i === index;
			option.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
			option.classList.toggle( 'is-active', isActive );
		} );

		active = index;

		if ( index >= 0 && options[ index ] ) {
			input.setAttribute( 'aria-activedescendant', options[ index ].id );
			options[ index ].scrollIntoView( { block: 'nearest' } );
		} else {
			input.removeAttribute( 'aria-activedescendant' );
		}
	}

	/**
	 * Render a set of suggestions.
	 *
	 * @param {Array} suggestions Suggestions from the server.
	 */
	function render( suggestions ) {
		items = suggestions;
		listbox.replaceChildren();

		if ( ! suggestions.length ) {
			close();
			status.textContent = SETTINGS.i18n?.noResults || '';
			return;
		}

		suggestions.forEach( ( suggestion, index ) => {
			const option = document.createElement( 'li' );

			option.id = `${ id }-option-${ index }`;
			option.className = 'solrengine-suggestion';
			option.setAttribute( 'role', 'option' );
			option.setAttribute( 'aria-selected', 'false' );
			// textContent, not innerHTML: a post title is content, and content
			// never becomes markup here.
			option.textContent = suggestion.text;

			// mousedown rather than click: a click fires after blur, by which
			// time the list has already closed and the target is gone.
			option.addEventListener( 'mousedown', ( event ) => {
				event.preventDefault();
				choose( index );
			} );

			listbox.appendChild( option );
		} );

		listbox.hidden = false;
		input.setAttribute( 'aria-expanded', 'true' );
		setActive( -1 );

		const template = SETTINGS.i18n?.resultsFound || '';
		status.textContent = template.replace( '%d', String( suggestions.length ) );
	}

	/**
	 * Follow a suggestion.
	 *
	 * @param {number} index Index of the suggestion.
	 */
	function choose( index ) {
		const suggestion = items[ index ];

		if ( ! suggestion ) {
			return;
		}

		if ( suggestion.url ) {
			window.location.href = suggestion.url;
			return;
		}

		input.value = suggestion.text;
		close();
		input.form?.submit();
	}

	/** Ask the server for suggestions. */
	async function fetchSuggestions() {
		const value = input.value.trim();

		if ( value.length < MIN_LENGTH ) {
			close();
			return;
		}

		// Cancel the request still in flight. A visitor typing eight characters
		// would otherwise have several racing, and the slowest could land last
		// and overwrite results for a prefix they have already moved past.
		if ( controller ) {
			controller.abort();
		}

		controller = new AbortController();

		const url = new URL( SETTINGS.endpoint, window.location.origin );
		url.searchParams.set( 'q', value );
		url.searchParams.set( 'limit', SETTINGS.limit || 8 );

		try {
			const response = await window.fetch( url, {
				signal: controller.signal,
				credentials: 'same-origin',
				headers: { Accept: 'application/json' },
			} );

			if ( ! response.ok ) {
				close();
				return;
			}

			const data = await response.json();

			// The prefix is echoed back, so a response for text the visitor has
			// since changed is discarded rather than rendered.
			if ( data.prefix && data.prefix.trim() !== value ) {
				return;
			}

			render( data.suggestions || [] );
		} catch ( error ) {
			// An aborted request is the normal case, not a failure. Anything
			// else leaves the visitor with a plain search box, which still
			// works: autocomplete is an enhancement.
			close();
		}
	}

	input.addEventListener( 'input', () => {
		window.clearTimeout( timer );
		timer = window.setTimeout( fetchSuggestions, DEBOUNCE_MS );
	} );

	input.addEventListener( 'keydown', ( event ) => {
		if ( listbox.hidden ) {
			return;
		}

		switch ( event.key ) {
			case 'ArrowDown':
				event.preventDefault();
				setActive( active + 1 >= items.length ? 0 : active + 1 );
				break;

			case 'ArrowUp':
				event.preventDefault();
				setActive( active - 1 < 0 ? items.length - 1 : active - 1 );
				break;

			case 'Enter':
				// Only intercepted when an option is highlighted. Otherwise the
				// form submits normally, so pressing enter always does
				// something predictable.
				if ( active >= 0 ) {
					event.preventDefault();
					choose( active );
				}
				break;

			case 'Escape':
				event.preventDefault();
				close();
				break;

			case 'Tab':
				close();
				break;

			default:
				break;
		}
	} );

	input.addEventListener( 'blur', () => {
		// Delayed, so a click on an option is not cancelled by the list closing
		// underneath the pointer.
		window.setTimeout( close, 150 );
	} );
}

/** Attach to every search field on the page. */
function init() {
	if ( ! SETTINGS.endpoint ) {
		return;
	}

	const selector =
		SETTINGS.selector ||
		'input[type="search"], input[name="s"], .wp-block-search__input';

	document.querySelectorAll( selector ).forEach( attach );
}

if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
