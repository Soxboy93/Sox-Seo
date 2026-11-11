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

	/* ---- Second-language fields (posts and terms) ------------------------ */

	function initLang() {
		$$( '.sox-seo-lang' ).forEach( ( section ) => {
			const button = $( '.sox-seo-generate-lang', section );
			if ( ! button ) {
				return;
			}
			const status = $( '.sox-seo-status', section );
			button.addEventListener( 'click', () =>
				withButton( button, status, () =>
					api( '/lang-meta', {
						kind: section.dataset.kind,
						id: parseInt( section.dataset.id, 10 ),
						lang: section.dataset.lang,
					} ).then( ( meta ) => {
						$( '.sox-seo-lang-title', section ).value = meta.title;
						$( '.sox-seo-lang-description', section ).value = meta.description;
						setStatus( status, t.filled );
					} )
				)
			);
		} );
	}

	/* ---- Category introduction ------------------------------------------- */

	function initTermCopy() {
		const row = $( '.sox-seo-term' );
		const button = row && $( '.sox-seo-term-copy', row );
		if ( ! button ) {
			return;
		}
		const status = $( '.sox-seo-term-status', row );
		const results = $( '.sox-seo-term-copy-results', row );
		const termId = parseInt( row.dataset.term, 10 );
		button.addEventListener( 'click', () =>
			withButton( button, status, () =>
				api( '/term-copy', { term_id: termId } ).then( ( response ) => {
					setStatus( status, '' );
					results.textContent = '';
					const area = el( 'textarea' );
					area.rows = 10;
					area.value = response.html;
					const save = el( 'button', 'button button-primary', t.saveText );
					save.type = 'button';
					save.addEventListener( 'click', () =>
						withButton( save, status, () =>
							api( '/term-copy', { term_id: termId, html: area.value } ).then( () => {
								setStatus( status, t.textSaved );
								results.textContent = '';
							} )
						)
					);
					results.append( area, el( 'p' ), save );
				} )
			)
		);
	}

	/* ---- Redirect suggestions for 404s ----------------------------------- */

	function initRedirectSuggest() {
		const button = $( '#sox-seo-suggest-redirects' );
		if ( ! button ) {
			return;
		}
		const status = $( '#sox-seo-suggest-status' );
		const results = $( '#sox-seo-suggest-results' );
		button.addEventListener( 'click', () =>
			withButton( button, status, () =>
				api( '/redirects/suggest' ).then( ( response ) => {
					results.textContent = '';
					if ( ! response.redirects.length ) {
						setStatus( status, t.noRedirects );
						return;
					}
					setStatus( status, '' );
					const list = el( 'ul', 'sox-seo-suggestions' );
					response.redirects.forEach( ( item, index ) => {
						const row = el( 'li' );
						const label = el( 'label' );
						const check = el( 'input' );
						check.type = 'checkbox';
						check.checked = true;
						check.value = String( index );
						label.append( check, ' ', el( 'code', '', item.url ), ' → ', el( 'strong', '', item.target_title ), el( 'span', 'sox-seo-muted', ' — ' + item.reason ) );
						row.append( label );
						list.append( row );
					} );
					const apply = el( 'button', 'button', t.addRedirects );
					apply.type = 'button';
					apply.addEventListener( 'click', () => {
						const chosen = $$( 'input:checked', list ).map( ( input ) => response.redirects[ parseInt( input.value, 10 ) ] );
						withButton( apply, status, () =>
							api( '/redirects/apply', { redirects: chosen } ).then( ( result ) => {
								setStatus( status, t.redirectsAdded.replace( '%d', result.added ) );
								window.location.reload();
							} )
						);
					} );
					results.append( list, apply );
				} )
			)
		);
	}

	/* ---- Speed report ---------------------------------------------------- */

	function initSpeed() {
		const table = $( '.sox-seo-speed' );
		if ( ! table ) {
			return;
		}
		const run = ( url, button, status ) =>
			withButton( button, status, () => {
				setStatus( status, t.testing );
				return api( '/speed', { url } ).then( () => window.location.reload() );
			} );
		$$( '.sox-seo-speed-test', table ).forEach( ( button ) => {
			const row = button.closest( 'tr' );
			button.addEventListener( 'click', () => run( row.dataset.url, button, $( '.sox-seo-status', row ) ) );
		} );
		const custom = $( '#sox-seo-speed-custom' );
		custom.addEventListener( 'click', () => {
			const url = $( '#sox-seo-speed-url' ).value.trim();
			if ( url ) {
				let status = custom.nextElementSibling;
				if ( ! status ) {
					status = el( 'span', 'sox-seo-status' );
					custom.after( status );
				}
				run( url, custom, status );
			}
		} );
	}

	/* ---- Bulk editor: only rows that were changed are sent -------------- */

	function initEditor() {
		const table = $( '.sox-seo-editor' );
		if ( ! table ) {
			return;
		}
		const status = $( '.sox-seo-editor-status' );
		const fields = $$( 'input, textarea', table );
		fields.forEach( ( field ) => {
			field.dataset.original = field.value;
		} );
		const save = ( button ) => {
			const rows = {};
			fields.forEach( ( field ) => {
				if ( field.value === field.dataset.original ) {
					return;
				}
				const id = field.closest( 'tr' ).dataset.id;
				rows[ id ] = rows[ id ] || { id: parseInt( id, 10 ) };
				if ( field.dataset.lang ) {
					rows[ id ].lang = rows[ id ].lang || {};
					rows[ id ].lang[ field.dataset.lang ] = rows[ id ].lang[ field.dataset.lang ] || {};
					rows[ id ].lang[ field.dataset.lang ][ field.dataset.field ] = field.value;
				} else {
					rows[ id ][ field.dataset.field ] = field.value;
				}
			} );
			const list = Object.values( rows );
			if ( ! list.length ) {
				setStatus( status, t.noChanges );
				return;
			}
			withButton( button, status, () =>
				api( '/bulk-edit', { rows: list } ).then( ( result ) => {
					fields.forEach( ( field ) => {
						field.dataset.original = field.value;
					} );
					setStatus( status, t.rowsSaved.replace( '%d', result.saved ) );
				} )
			);
		};
		$$( '.sox-seo-editor-save' ).forEach( ( button ) => button.addEventListener( 'click', () => save( button ) ) );
		window.addEventListener( 'beforeunload', ( event ) => {
			if ( fields.some( ( field ) => field.value !== field.dataset.original ) ) {
				event.preventDefault();
			}
		} );
	}

	function initConfirm() {
		$$( '.sox-seo-confirm' ).forEach( ( link ) =>
			link.addEventListener( 'click', ( event ) => {
				if ( ! window.confirm( t.confirmDelete ) ) {
					event.preventDefault();
				}
			} )
		);
	}

	/* ---- Term editor ----------------------------------------------------- */

	function initTerm() {
		const row = $( '.sox-seo-term' );
		const button = row && $( '.sox-seo-generate-term', row );
		if ( ! button ) {
			return;
		}
		const status = $( '.sox-seo-term-status', row );
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
							lang: card.dataset.lang || '',
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
		initLang();
		initTermCopy();
		initRedirectSuggest();
		initSpeed();
		initEditor();
		initConfirm();
	} );
} )();
