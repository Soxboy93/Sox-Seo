/* global wp, soxSeo */
( function () {
	'use strict';

	const api = ( path, data ) =>
		wp.apiFetch( { path: '/sox-seo/v1' + path, method: 'POST', data: data || {} } );
	const t = soxSeo.i18n;
	const $ = ( selector, root ) => ( root || document ).querySelector( selector );
	const $$ = ( selector, root ) => Array.from( ( root || document ).querySelectorAll( selector ) );

	function setStatus( el, text, isError ) {
		if ( ! el ) {
			return;
		}
		el.textContent = text || '';
		el.classList.toggle( 'sox-seo-error', !! isError );
	}

	function errorText( error ) {
		return ( error && error.message ) || String( error );
	}

	/* Run an async action behind a button, showing progress and errors. */
	function withButton( button, status, action ) {
		button.disabled = true;
		setStatus( status, t.working );
		return action()
			.catch( ( error ) => setStatus( status, errorText( error ), true ) )
			.finally( () => {
				button.disabled = false;
			} );
	}

	/* ---- Character counters and search preview -------------------------- */

	function updateCounters() {
		$$( '.sox-seo-counter' ).forEach( ( counter ) => {
			const field = document.getElementById( counter.dataset.for );
			if ( ! field ) {
				return;
			}
			const length = Array.from( field.value ).length;
			const max = parseInt( counter.dataset.max, 10 );
			counter.textContent = length + ' / ' + max + ' ' + t.chars;
			counter.classList.toggle( 'sox-seo-over', length > max );
		} );

		const title = $( '.sox-seo-preview-title' );
		if ( title ) {
			const seoTitle = $( '#sox-seo-field-title' );
			const description = $( '#sox-seo-field-description' );
			title.textContent = ( seoTitle && seoTitle.value ) || title.dataset.fallback || '';
			$( '.sox-seo-preview-desc' ).textContent = description ? description.value : '';
		}
	}

	function fillFields( meta ) {
		[ 'focus_keyword', 'title', 'description' ].forEach( ( name ) => {
			const field = document.getElementById( 'sox-seo-field-' + name );
			if ( field && meta[ name ] ) {
				field.value = meta[ name ];
			}
		} );
		updateCounters();
	}

	/* ---- Post editor ----------------------------------------------------- */

	function initMetaBox() {
		const box = $( '.sox-seo-box' );
		if ( ! box ) {
			return;
		}
		const postId = parseInt( box.dataset.post, 10 );
		const status = $( '.sox-seo-actions .sox-seo-status', box );

		box.addEventListener( 'input', updateCounters );
		updateCounters();

		const generate = $( '.sox-seo-generate', box );
		if ( generate ) {
			generate.addEventListener( 'click', () =>
				withButton( generate, status, () =>
					api( '/meta', { post_id: postId, apply: ! soxSeo.native } ).then( ( meta ) => {
						if ( soxSeo.native ) {
							fillFields( meta );
							setStatus( status, t.filled );
						} else {
							setStatus( status, t.saved );
						}
					} )
				)
			);
		}

		initContentTools( box, postId, status );

		const links = $( '.sox-seo-links', box );
		const results = $( '.sox-seo-link-results', box );
		if ( links ) {
			links.addEventListener( 'click', () =>
				withButton( links, status, () =>
					api( '/links', { post_id: postId } ).then( ( response ) => {
						setStatus( status, '' );
						renderLinks( results, response, postId, status );
					} )
				)
			);
		}
	}

	function renderLinks( container, response, postId, status ) {
		container.textContent = '';
		if ( ! response.links.length ) {
			setStatus( status, t.noLinks );
			return;
		}
		const list = document.createElement( 'ul' );
		response.links.forEach( ( link, index ) => {
			const item = document.createElement( 'li' );
			const label = document.createElement( 'label' );
			const checkbox = document.createElement( 'input' );
			checkbox.type = 'checkbox';
			checkbox.checked = true;
			checkbox.value = String( index );
			const anchor = document.createElement( 'strong' );
			anchor.textContent = '“' + link.anchor_text + '”';
			const target = document.createElement( 'a' );
			target.href = link.url;
			target.target = '_blank';
			target.rel = 'noopener';
			target.textContent = link.title;
			const reason = document.createElement( 'span' );
			reason.className = 'sox-seo-muted';
			reason.textContent = ' — ' + link.reason;
			label.append( checkbox, ' ', anchor, ' → ', target, reason );
			item.append( label );
			list.append( item );
		} );
		container.append( list );

		const apply = document.createElement( 'button' );
		apply.type = 'button';
		apply.className = 'button';
		apply.textContent = t.apply;
		apply.addEventListener( 'click', () => {
			const chosen = $$( 'input:checked', list ).map( ( input ) => response.links[ parseInt( input.value, 10 ) ] );
			if ( ! chosen.length ) {
				return;
			}
			withButton( apply, status, () =>
				api( '/links/apply', { post_id: postId, links: chosen } ).then( ( result ) => {
					setStatus( status, t.linksAdded.replace( '%d', result.inserted ) );
					container.textContent = '';
				} )
			);
		} );
		container.append( apply );
	}

	/* ---- Content audit, FAQ schema, product descriptions ---------------- */

	function el( tag, className, text ) {
		const node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text ) {
			node.textContent = text;
		}
		return node;
	}

	function renderAudit( container, audit ) {
		container.textContent = '';
		if ( ! audit || ! audit.recommendations ) {
			return;
		}
		container.append( el( 'p', '', audit.summary ) );
		const list = el( 'ol', 'sox-seo-audit-list' );
		audit.recommendations.forEach( ( item ) => {
			const row = el( 'li' );
			row.append(
				el( 'span', 'sox-seo-priority sox-seo-priority-' + item.priority, t.priority[ item.priority ] || item.priority ),
				' ',
				el( 'strong', '', item.issue ),
				el( 'div', '', item.fix )
			);
			list.append( row );
		} );
		container.append( list );
	}

	function initContentTools( box, postId, status ) {
		const auditButton = $( '.sox-seo-audit', box );
		const auditResults = $( '.sox-seo-audit-results', box );
		if ( auditResults && auditResults.dataset.audit ) {
			try {
				renderAudit( auditResults, JSON.parse( auditResults.dataset.audit ) );
			} catch ( error ) {
				// A damaged saved audit is simply not shown.
			}
		}
		if ( auditButton ) {
			auditButton.addEventListener( 'click', () =>
				withButton( auditButton, status, () =>
					api( '/audit', { post_id: postId } ).then( ( audit ) => {
						setStatus( status, '' );
						renderAudit( auditResults, audit );
					} )
				)
			);
		}

		const faqButton = $( '.sox-seo-faq', box );
		const faqClear = $( '.sox-seo-faq-clear', box );
		if ( faqButton ) {
			faqButton.addEventListener( 'click', () =>
				withButton( faqButton, status, () =>
					api( '/faq', { post_id: postId } ).then( ( response ) => {
						const count = response.faqs.length;
						setStatus( status, count ? t.faqSaved.replace( '%d', count ) : t.noFaq );
						faqClear.hidden = ! count;
					} )
				)
			);
			faqClear.addEventListener( 'click', () =>
				withButton( faqClear, status, () =>
					api( '/faq', { post_id: postId, clear: true } ).then( () => {
						setStatus( status, t.faqCleared );
						faqClear.hidden = true;
					} )
				)
			);
		}

		const copyButton = $( '.sox-seo-copy', box );
		const copyResults = $( '.sox-seo-copy-results', box );
		if ( copyButton ) {
			copyButton.addEventListener( 'click', () =>
				withButton( copyButton, status, () =>
					api( '/product-copy', { post_id: postId } ).then( ( copy ) => {
						setStatus( status, '' );
						renderCopy( copyResults, copy, postId, status );
					} )
				)
			);
		}
	}

	/* Preview of generated product copy. The full description is shown as
	 * text in an editable box; nothing is saved until the owner confirms. */
	function renderCopy( container, copy, postId, status ) {
		container.textContent = '';
		const fields = [
			[ 'short_description', t.shortDesc, 3 ],
			[ 'description', t.fullDesc, 10 ],
		].map( ( [ key, label, rows ] ) => {
			const wrap = el( 'p' );
			const check = el( 'input' );
			check.type = 'checkbox';
			check.checked = true;
			const heading = el( 'label' );
			heading.append( check, ' ', el( 'strong', '', label ) );
			const area = el( 'textarea', 'widefat' );
			area.rows = rows;
			area.value = copy[ key ];
			wrap.append( heading, area );
			container.append( wrap );
			return { key, check, area };
		} );

		const save = el( 'button', 'button button-primary', t.saveCopy );
		save.type = 'button';
		save.addEventListener( 'click', () => {
			const data = { post_id: postId, short_description: '', description: '' };
			fields.forEach( ( field ) => {
				if ( field.check.checked ) {
					data[ field.key ] = field.area.value;
				}
			} );
			withButton( save, status, () =>
				api( '/product-copy/apply', data ).then( () => {
					setStatus( status, t.copySaved );
					container.textContent = '';
				} )
			);
		} );
		container.append( save );
	}

	/* ---- Link audit ------------------------------------------------------ */

	function initScan() {
		const button = $( '#sox-seo-scan' );
		if ( ! button ) {
			return;
		}
		const status = $( '#sox-seo-scan-status' );
		button.addEventListener( 'click', async () => {
			button.disabled = true;
			let restart = true;
			try {
				for ( ;; ) {
					const result = await api( '/link-audit', { restart } );
					restart = false;
					setStatus( status, t.scanning.replace( '%1$d', result.scanned ).replace( '%2$d', result.total ) );
					if ( result.done ) {
						break;
					}
				}
				setStatus( status, t.scanDone );
				window.location.reload();
			} catch ( error ) {
				setStatus( status, errorText( error ), true );
				button.disabled = false;
			}
		} );
	}

	/* ---- Term editor ----------------------------------------------------- */

	function initTerm() {
		const row = $( '.sox-seo-term' );
		const button = row && $( '.sox-seo-generate-term', row );
		if ( ! button ) {
			return;
		}
		const status = $( '.sox-seo-status', row );
		button.addEventListener( 'click', () =>
			withButton( button, status, () =>
				api( '/term-meta', { term_id: parseInt( row.dataset.term, 10 ), apply: ! soxSeo.native } ).then( ( meta ) => {
					if ( soxSeo.native ) {
						fillFields( meta );
						setStatus( status, t.filled );
					} else {
						setStatus( status, t.saved );
					}
				} )
			)
		);
	}

	/* ---- Settings: connection test --------------------------------------- */

	function initTest() {
		const button = $( '#sox-seo-test' );
		if ( ! button ) {
			return;
		}
		const status = $( '#sox-seo-test-status' );
		button.addEventListener( 'click', () =>
			withButton( button, status, () => api( '/test' ).then( () => setStatus( status, t.connected ) ) )
		);
	}

	/* ---- Bulk optimizer --------------------------------------------------
	 * One item per request, in a loop driven from this tab. Items that fail
	 * are skipped for the rest of the run so one bad page cannot stall it.
	 */

	function initBulk() {
		$$( '.sox-seo-card' ).forEach( ( card ) => {
			const button = $( '.sox-seo-bulk-start', card );
			const status = $( '.sox-seo-status', card );
			const log = $( '.sox-seo-log', card );
			const type = $( '.sox-seo-bulk-type', card );
			let running = false;

			function finish( message, isError ) {
				running = false;
				button.textContent = t.start;
				setStatus( status, message, isError );
			}

			async function run() {
				const exclude = [];
				while ( running ) {
					let result;
					try {
						result = await api( '/bulk', {
							task: card.dataset.task,
							post_type: type ? type.value : '',
							exclude,
						} );
					} catch ( error ) {
						finish( errorText( error ), true );
						return;
					}
					if ( result.done ) {
						finish( t.done );
						return;
					}
					if ( result.error ) {
						exclude.push( result.id );
					}
					const item = document.createElement( 'li' );
					const link = document.createElement( 'a' );
					link.href = result.edit_link || '#';
					link.target = '_blank';
					link.rel = 'noopener';
					link.textContent = result.title || '#' + result.id;
					const text = document.createElement( 'span' );
					text.textContent = ' — ' + ( result.error || result.output );
					if ( result.error ) {
						text.className = 'sox-seo-error';
					}
					item.append( link, text );
					log.prepend( item );
					setStatus( status, t.remaining.replace( '%d', Math.max( 0, result.remaining - exclude.length ) ) );
				}
				finish( t.stopped );
			}

			button.addEventListener( 'click', () => {
				if ( running ) {
					running = false;
					return;
				}
				running = true;
				button.textContent = t.stop;
				setStatus( status, t.working );
				run();
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', () => {
		initMetaBox();
		initTerm();
		initTest();
		initBulk();
		initScan();
	} );
} )();
