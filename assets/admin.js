/**
 * Extend AI — Enterprise admin app.
 *
 * Plain JS (no JSX build step) using @wordpress/element + @wordpress/components.
 * Loaded by Admin\Settings_Page on the AI Enterprise tools page.
 */
( function ( wp ) {
	const { createElement: h, useState, useEffect, Fragment } = wp.element;
	const {
		Button, Card, CardBody, CardHeader, Notice, SelectControl,
		Spinner, TextareaControl, __experimentalText: Text,
	} = wp.components;
	const apiFetch = wp.apiFetch;

	const REST_ROOT = '/extend-ai/v1';
	const MODES = [
		{ label: 'Prepend to default', value: 'prepend' },
		{ label: 'Append to default',  value: 'append'  },
		{ label: 'Replace default',    value: 'replace' },
	];

	function App() {
		const [ view, setView ] = useState( { name: 'list' } );

		if ( view.name === 'edit' ) {
			return h( EditView, {
				abilityId: view.abilityId,
				onBack:    () => setView( { name: 'list' } ),
			} );
		}
		return h( ListView, {
			onEdit: ( abilityId ) => setView( { name: 'edit', abilityId } ),
		} );
	}

	function ListView( { onEdit } ) {
		const [ rows, setRows ]       = useState( null );
		const [ error, setError ]     = useState( '' );

		useEffect( () => {
			apiFetch( { path: REST_ROOT + '/prompts' } )
				.then( setRows )
				.catch( ( e ) => setError( e.message || 'Failed to load.' ) );
		}, [] );

		if ( error ) {
			return h( Notice, { status: 'error', isDismissible: false }, error );
		}
		if ( ! rows ) {
			return h( Spinner );
		}

		return h( 'div', {},
			h( 'p', {}, 'Each AI ability registered on this site is listed below. Click an ability to override its system prompt.' ),
			h( 'table', { className: 'wp-list-table widefat striped' },
				h( 'thead', {}, h( 'tr', {},
					h( 'th', {}, 'Ability' ),
					h( 'th', {}, 'Override' ),
					h( 'th', {}, 'Mode' ),
					h( 'th', {}, '' )
				) ),
				h( 'tbody', {},
					rows.map( ( r ) => h( 'tr', { key: r.ability_id },
						h( 'td', {},
							h( 'strong', {}, r.label || r.ability_id ),
							h( 'br' ),
							h( 'code', { style: { fontSize: '11px' } }, r.ability_id )
						),
						h( 'td', {}, r.override
							? h( Text, { variant: 'muted' }, truncate( r.override.template, 80 ) )
							: h( Text, { variant: 'muted' }, '—' )
						),
						h( 'td', {}, r.override ? r.override.mode : '' ),
						h( 'td', {}, h( Button, {
							variant: 'secondary',
							onClick: () => onEdit( r.ability_id ),
						}, r.override ? 'Edit' : 'Override' ) )
					) )
				)
			)
		);
	}

	function EditView( { abilityId, onBack } ) {
		const [ prompt, setPrompt ]   = useState( null );
		const [ history, setHistory ] = useState( [] );
		const [ template, setTemplate ] = useState( '' );
		const [ mode, setMode ]       = useState( 'prepend' );
		const [ saving, setSaving ]   = useState( false );
		const [ notice, setNotice ]   = useState( null );

		useEffect( () => {
			Promise.all( [
				apiFetch( { path: REST_ROOT + '/prompts/' + abilityId } ),
				apiFetch( { path: REST_ROOT + '/prompts/' + abilityId + '/history' } )
					.then( ( r ) => r.history )
					.catch( () => [] ),
			] ).then( ( [ p, hist ] ) => {
				setPrompt( p );
				setHistory( hist );
				if ( p.override ) {
					setTemplate( p.override.template );
					setMode( p.override.mode );
				}
			} ).catch( ( e ) => setNotice( { status: 'error', text: e.message } ) );
		}, [ abilityId ] );

		if ( ! prompt ) {
			return h( Spinner );
		}

		const save = () => {
			setSaving( true );
			apiFetch( {
				path:   REST_ROOT + '/prompts/' + abilityId,
				method: 'PUT',
				data:   { mode, template },
			} ).then( ( updated ) => {
				setPrompt( updated );
				setNotice( { status: 'success', text: 'Saved.' } );
				return apiFetch( { path: REST_ROOT + '/prompts/' + abilityId + '/history' } );
			} ).then( ( r ) => setHistory( r.history ) )
				.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) )
				.finally( () => setSaving( false ) );
		};

		const revert = () => {
			if ( ! window.confirm( 'Revert to the WP AI default for this ability?' ) ) {
				return;
			}
			setSaving( true );
			apiFetch( {
				path:   REST_ROOT + '/prompts/' + abilityId,
				method: 'DELETE',
			} ).then( () => {
				setTemplate( '' );
				setMode( 'prepend' );
				setNotice( { status: 'success', text: 'Reverted to default.' } );
				return apiFetch( { path: REST_ROOT + '/prompts/' + abilityId } );
			} ).then( setPrompt )
				.then( () => apiFetch( { path: REST_ROOT + '/prompts/' + abilityId + '/history' } ) )
				.then( ( r ) => setHistory( r.history ) )
				.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) )
				.finally( () => setSaving( false ) );
		};

		return h( Fragment, {},
			h( 'p', {},
				h( Button, { variant: 'link', onClick: onBack }, '← Back to all abilities' )
			),
			h( 'h2', {}, prompt.label || abilityId ),
			h( 'p', {}, h( 'code', {}, abilityId ) ),

			notice && h( Notice, {
				status:        notice.status,
				isDismissible: true,
				onRemove:      () => setNotice( null ),
			}, notice.text ),

			h( Card, {},
				h( CardHeader, {}, h( 'strong', {}, 'WP AI default prompt' ) ),
				h( CardBody, {},
					prompt.default_available
						? ( prompt.default
							? h( 'pre', { style: preStyle }, prompt.default )
							: h( 'em', {}, 'The default is an empty string.' ) )
						: h( Notice, { status: 'info', isDismissible: false },
							'This ability builds its prompt at runtime from per-call context (post body, user input, etc.), so the default is not previewable here. Use a placeholder like {post_title} in your override to reference that runtime data.'
						)
				)
			),

			h( 'br' ),

			h( Card, {},
				h( CardHeader, {}, h( 'strong', {}, 'Override' ) ),
				h( CardBody, {},
					h( SelectControl, {
						label:    'Mode',
						value:    mode,
						options:  MODES,
						onChange: setMode,
						help:     'Prepend/Append combines with the WP AI default. Replace uses only your template.',
					} ),
					h( TextareaControl, {
						label:    'Template',
						value:    template,
						onChange: setTemplate,
						rows:     12,
						help:     'Supports {variable} placeholders. Available: {ability}, {user_login}, {user_role}, {site_name}, {site_url}, {current_date}, {post_title}, {post_type}, {post_status} (when applicable), plus any scalar passed by the ability.',
					} ),
					h( 'div', { style: { display: 'flex', gap: '8px' } },
						h( Button, {
							variant:  'primary',
							onClick:  save,
							disabled: saving || ! template.trim(),
						}, saving ? 'Saving…' : 'Save override' ),
						prompt.override && h( Button, {
							variant:   'secondary',
							isDestructive: true,
							onClick:   revert,
							disabled:  saving,
						}, 'Revert to default' )
					)
				)
			),

			h( 'br' ),

			h( Card, {},
				h( CardHeader, {}, h( 'strong', {}, 'History' ) ),
				h( CardBody, {},
					history.length === 0
						? h( 'em', {}, 'No edits yet.' )
						: h( 'table', { className: 'wp-list-table widefat striped' },
							h( 'thead', {}, h( 'tr', {},
								h( 'th', {}, 'When' ),
								h( 'th', {}, 'Action' ),
								h( 'th', {}, 'Mode' ),
								h( 'th', {}, 'By' ),
								h( 'th', {}, 'Template' )
							) ),
							h( 'tbody', {},
								history.map( ( row, i ) => h( 'tr', { key: i },
									h( 'td', {}, row.updated_at ),
									h( 'td', {}, row.action ),
									h( 'td', {}, row.mode ),
									h( 'td', {}, '#' + row.updated_by ),
									h( 'td', {}, h( Text, { variant: 'muted' }, truncate( row.template, 80 ) ) )
								) )
							)
						)
				)
			)
		);
	}

	const preStyle = {
		whiteSpace: 'pre-wrap',
		background: '#f6f7f7',
		padding:    '12px',
		borderRadius: '4px',
		fontSize:   '12px',
		margin:     0,
	};

	function truncate( s, n ) {
		s = String( s || '' );
		return s.length > n ? s.slice( 0, n ) + '…' : s;
	}

	wp.domReady( () => {
		const root = document.getElementById( 'extend-ai-enterprise-prompts-root' );
		if ( ! root ) {
			return;
		}
		// React 18 createRoot when available, else legacy render.
		if ( wp.element.createRoot ) {
			wp.element.createRoot( root ).render( h( App ) );
		} else {
			wp.element.render( h( App ), root );
		}
	} );
} )( window.wp );
