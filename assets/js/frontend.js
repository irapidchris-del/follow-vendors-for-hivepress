/**
 * Follow Vendors for HivePress: the follow, save and alert items in Action Bar for HivePress.
 *
 * The bar renders each of this plugin's items as a plain anchor whose href is a bare fragment
 * (#hpfv-follow-12, #hpfv-favorite-40, #hpfv-alert-<md5>). This script finds those anchors,
 * marks the ones that are "on", and turns a click into the same POST the on-page control makes,
 * flipping the icon and label without a reload. No HTML string is ever written into the page:
 * labels go through textContent and icons are rebuilt with createElementNS from a
 * "viewBox|path" pair.
 *
 * Runs after Action Bar's own script (a declared dependency) and after the bar markup, which
 * the bar prints on wp_footer before the footer scripts.
 */
( function () {
	'use strict';

	var SVG_NS = 'http://www.w3.org/2000/svg';

	function getData() {
		var data = window.hpfvData;

		return data && data.items && 'object' === typeof data.items ? data.items : null;
	}

	/**
	 * Builds the <svg> for a "viewBox|path" pair the way the bar's own library draws it.
	 *
	 * @param {string} pair The pair.
	 * @return {Element|null} The svg element, or null when the pair is not usable.
	 */
	function buildSvg( pair ) {
		if ( 'string' !== typeof pair ) {
			return null;
		}

		var parts = pair.split( '|' );

		if ( 2 !== parts.length || ! /^[0-9 .]+$/.test( parts[ 0 ] ) || ! /^[A-Za-z0-9 .,\-]+$/.test( parts[ 1 ] ) ) {
			return null;
		}

		var svg = document.createElementNS( SVG_NS, 'svg' ),
			path = document.createElementNS( SVG_NS, 'path' );

		svg.setAttribute( 'xmlns', SVG_NS );
		svg.setAttribute( 'viewBox', parts[ 0 ] );
		svg.setAttribute( 'aria-hidden', 'true' );
		svg.setAttribute( 'focusable', 'false' );
		svg.setAttribute( 'fill', 'currentColor' );

		path.setAttribute( 'd', parts[ 1 ] );
		path.setAttribute( 'vector-effect', 'non-scaling-stroke' );

		svg.appendChild( path );

		return svg;
	}

	/**
	 * Copies the size and stroke attributes the library put on the bar's own svg, so the rebuilt
	 * glyph keeps the bar's icon size and weight.
	 *
	 * @param {Element} from The svg being replaced.
	 * @param {Element} to The new svg.
	 */
	function copyPresentation( from, to ) {
		[ 'width', 'height', 'class', 'style', 'stroke', 'stroke-width' ].forEach( function ( name ) {
			var value = from.getAttribute( name );

			if ( null !== value ) {
				to.setAttribute( name, value );
			}
		} );

		var fromPath = from.querySelector( 'path' ),
			toPath = to.querySelector( 'path' );

		if ( fromPath && toPath ) {
			[ 'stroke', 'stroke-width', 'vector-effect', 'fill' ].forEach( function ( name ) {
				var value = fromPath.getAttribute( name );

				if ( null !== value ) {
					toPath.setAttribute( name, value );
				}
			} );
		}
	}

	/**
	 * Draws one state's icon into a bar item.
	 *
	 * With the icon library present the bar holds `<i class="fafh-icon"><svg>`; the svg is
	 * replaced. Without it the bar holds `<i class="fas fa-user-plus">`; the class string is
	 * swapped, which is what core's own toggle script does.
	 *
	 * @param {Element} item The bar anchor.
	 * @param {Object} config The item's config.
	 * @param {number} state 0 for off, 1 for on.
	 */
	function drawIcon( item, config, state ) {
		var holder = item.querySelector( '.hp-action-bar__icon i' );

		if ( ! holder ) {
			return;
		}

		var existing = holder.querySelector( 'svg' );

		if ( existing ) {
			var svg = buildSvg( config.paths && config.paths[ state ] );

			if ( svg ) {
				copyPresentation( existing, svg );
				holder.replaceChild( svg, existing );
			}

			return;
		}

		if ( config.icons && config.icons[ state ] ) {
			holder.className = config.icons[ state ];
		}
	}

	/**
	 * Applies a state to a bar item: class, aria-pressed, label and icon.
	 *
	 * @param {Element} item The bar anchor.
	 * @param {Object} config The item's config.
	 * @param {boolean} active Whether the item is on.
	 */
	function applyState( item, config, active ) {
		var state = active ? 1 : 0,
			label = item.querySelector( '.hp-action-bar__label' );

		item.classList.toggle( 'hp-action-bar__item--active', active );
		item.setAttribute( 'aria-pressed', active ? 'true' : 'false' );

		if ( config.labels && config.labels[ state ] ) {
			if ( label ) {
				label.textContent = config.labels[ state ];
			}

			item.setAttribute( 'aria-label', config.labels[ state ] );
		}

		drawIcon( item, config, state );

		item.setAttribute( 'data-hpfv-state', active ? 'active' : '' );
	}

	function isActive( item ) {
		return 'active' === item.getAttribute( 'data-hpfv-state' );
	}

	/**
	 * The on-page core toggle that POSTs to the same address, if there is one.
	 *
	 * @param {string} url REST URL.
	 * @return {Element|null}
	 */
	function findToggle( url ) {
		var toggles = document.querySelectorAll( '[data-component="toggle"][data-url]' ),
			i;

		for ( i = 0; i < toggles.length; i++ ) {
			if ( toggles[ i ].getAttribute( 'data-url' ) === url ) {
				return toggles[ i ];
			}
		}

		return null;
	}

	/**
	 * Flips a core toggle's visuals the way core's own click handler does, without its POST.
	 *
	 * Mirrors hivepress/assets/js/frontend.js:7-49: the icon class is swapped by splitting on
	 * " fa-", the caption (or the title in icon view) is swapped with data-caption, and
	 * data-state flips.
	 *
	 * @param {Element} toggle The core toggle anchor.
	 */
	function mirrorToggle( toggle ) {
		var caption = toggle.getAttribute( 'data-caption' ) || '',
			iconClass = toggle.getAttribute( 'data-icon' ) || '',
			icon = toggle.querySelector( 'i' ),
			label = toggle.querySelector( 'span' );

		if ( icon ) {
			var current = ( icon.getAttribute( 'class' ) || '' ).split( ' fa-' )[ 1 ] || '';

			toggle.setAttribute( 'data-icon', current );

			icon.setAttribute( 'class', ( icon.getAttribute( 'class' ) || '' ).replace( / fa-[a-z0-9-]+/g, '' ) + ' fa-' + iconClass );
		}

		if ( label ) {
			toggle.setAttribute( 'data-caption', label.textContent );
			label.textContent = caption;
		} else {
			toggle.setAttribute( 'data-caption', toggle.getAttribute( 'title' ) || '' );
			toggle.setAttribute( 'title', caption );
		}

		toggle.setAttribute( 'data-state', 'active' === toggle.getAttribute( 'data-state' ) ? '' : 'active' );
	}

	/**
	 * Opens HivePress's sign-in pop-up, when the page has one.
	 */
	function openLogin() {
		if ( document.getElementById( 'user_login_modal' ) && window.jQuery && window.jQuery.fancybox ) {
			window.jQuery.fancybox.close();
			window.jQuery.fancybox.open( {
				src: '#user_login_modal',
				touch: false,
			} );
		}
	}

	function nonce() {
		return window.hivepressCoreData && window.hivepressCoreData.apiNonce ? window.hivepressCoreData.apiNonce : '';
	}

	/**
	 * Wires one bar item.
	 *
	 * @param {Element} item The bar anchor.
	 * @param {Object} config The item's config.
	 */
	function bindItem( item, config ) {
		var busy = false;

		applyState( item, config, !! config.active );

		item.addEventListener( 'click', function ( event ) {
			event.preventDefault();

			if ( busy || ! window.fetch ) {
				return;
			}

			busy = true;

			var wasActive = isActive( item ),
				nowActive = ! wasActive,
				toggle = findToggle( config.url );

			// Optimistic, like core's own toggle: flip first, put it back if the server says no.
			applyState( item, config, nowActive );

			if ( toggle ) {
				mirrorToggle( toggle );
			}

			window.fetch( config.url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'X-WP-Nonce': nonce(),
				},
			} ).then( function ( response ) {
				busy = false;

				if ( ! response.ok ) {
					applyState( item, config, wasActive );

					if ( toggle ) {
						mirrorToggle( toggle );
					}

					// A stale nonce on a cached page is the realistic cause.
					if ( 401 === response.status ) {
						openLogin();
					}

					return null;
				}

				// 204 has no body (a deleted search alert); anything else may carry the final state.
				if ( 204 === response.status ) {
					return null;
				}

				return response.json().catch( function () {
					return null;
				} );
			} ).then( function ( body ) {
				if ( body && body.data && 'boolean' === typeof body.data.following && body.data.following !== isActive( item ) ) {
					applyState( item, config, body.data.following );

					if ( toggle ) {
						mirrorToggle( toggle );
					}
				}
			} ).catch( function () {
				busy = false;

				applyState( item, config, wasActive );

				if ( toggle ) {
					mirrorToggle( toggle );
				}
			} );
		} );
	}

	/**
	 * Keeps the bar in step when the on-page control is clicked instead.
	 *
	 * Core's handler is bound on the toggle itself, so by the time this document-level listener
	 * runs core has already flipped data-state and sent its POST; the bar item only has to follow.
	 *
	 * @param {Object} bound Handle => { item, config } for every wired item.
	 */
	function followToggles( bound ) {
		document.addEventListener( 'click', function ( event ) {
			var target = event.target && event.target.closest ? event.target.closest( '[data-component="toggle"][data-url]' ) : null;

			if ( ! target ) {
				return;
			}

			var url = target.getAttribute( 'data-url' ),
				handle;

			for ( handle in bound ) {
				if ( Object.prototype.hasOwnProperty.call( bound, handle ) && bound[ handle ].config.url === url ) {
					( function ( entry ) {
						window.setTimeout( function () {
							applyState( entry.item, entry.config, 'active' === target.getAttribute( 'data-state' ) );
						}, 0 );
					}( bound[ handle ] ) );
				}
			}
		} );
	}

	function init() {
		var data = getData(),
			bar = document.querySelector( '.hp-action-bar' ),
			bound = {},
			handle;

		if ( ! data || ! bar ) {
			return;
		}

		for ( handle in data ) {
			if ( ! Object.prototype.hasOwnProperty.call( data, handle ) || ! /^[a-z0-9-]+$/.test( handle ) ) {
				continue;
			}

			var item = bar.querySelector( 'a.hp-action-bar__item[href="#' + handle + '"]' );

			if ( ! item || ! data[ handle ] || 'string' !== typeof data[ handle ].url ) {
				continue;
			}

			bindItem( item, data[ handle ] );

			bound[ handle ] = { item: item, config: data[ handle ] };
		}

		followToggles( bound );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
