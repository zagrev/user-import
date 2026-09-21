( function () {
	'use strict';

	const fileInput = document.getElementById( 'user-import-csv' );
	const mappingInput = document.getElementById( 'user-import-mapping' );
	const columnsContainer = document.getElementById( 'user-import-columns' );
	const mappingBuilder = document.getElementById( 'user-import-mapping-builder' );
	const fieldsContainer = document.getElementById( 'user-import-fields' );
	const savedMappingSelect = document.getElementById( 'user-import-saved-mapping' );

	if ( ! fileInput || ! mappingInput || ! columnsContainer || ! mappingBuilder || ! fieldsContainer ) {
		return;
	}

	const parseCsvHeader = ( text ) => {
		const headers = [];
		let value = '';
		let quoted = false;

		for ( let index = 0; index < text.length; index++ ) {
			const character = text[ index ];
			const nextCharacter = text[ index + 1 ];

			if ( '"' === character && quoted && '"' === nextCharacter ) {
				value += '"';
				index++;
				continue;
			}
			if ( '"' === character ) {
				quoted = ! quoted;
				continue;
			}
			if ( ',' === character && ! quoted ) {
				headers.push( value.trim() );
				value = '';
				continue;
			}
			if ( ( '\n' === character || '\r' === character ) && ! quoted ) {
				if ( '\r' === character && '\n' === nextCharacter ) {
					index++;
				}
				break;
			}
			value += character;
		}

		headers.push( value.trim() );
		return headers;
	};

	const updateMapping = () => {
		const mappings = [];
		columnsContainer.querySelectorAll( '[data-column-index]' ).forEach( ( column ) => {
			const field = column.querySelector( '[data-assigned-field]' );
			if ( field ) {
				const source = column.dataset.columnHeader || column.dataset.columnIndex;
				mappings.push( `${ source }=${ field.dataset.assignedField }` );
			}
		} );
		mappingInput.value = mappings.join( '\n' );
		mappingBuilder.querySelectorAll( '[data-user-field]' ).forEach( ( field ) => {
			field.classList.toggle( 'is-mapped', Array.from( columnsContainer.querySelectorAll( '[data-assigned-field]' ) ).some( ( assigned ) => assigned.dataset.assignedField === field.dataset.userField ) );
		} );
	};

	const removeAssignment = ( fieldName ) => {
		columnsContainer.querySelectorAll( '[data-assigned-field]' ).forEach( ( assigned ) => {
			if ( assigned.dataset.assignedField === fieldName ) {
				assigned.remove();
			}
		} );
		updateMapping();
	};

	const assignField = ( column, field ) => {
		removeAssignment( field.dataset.userField );
		const existing = column.querySelector( '[data-assigned-field]' );
		if ( existing ) {
			existing.remove();
		}

		const assigned = document.createElement( 'span' );
		assigned.className = 'user-import-assigned-field';
		assigned.dataset.assignedField = field.dataset.userField;
		assigned.draggable = true;
		assigned.textContent = field.textContent.replace( ' *', '' );
		assigned.title = 'Click to remove';
		assigned.addEventListener( 'dragstart', ( event ) => {
			event.dataTransfer.setData( 'text/plain', field.dataset.userField );
			event.dataTransfer.setData( 'application/x-user-import-assignment', 'true' );
			event.dataTransfer.effectAllowed = 'move';
		} );
		assigned.addEventListener( 'click', () => {
			assigned.remove();
			updateMapping();
		} );
		column.appendChild( assigned );
		updateMapping();
	};

	const applyMapping = () => {
		const mapping = mappingInput.value;
		columnsContainer.querySelectorAll( '[data-assigned-field]' ).forEach( ( assigned ) => assigned.remove() );
		updateMapping();

		mapping.split( /\r?\n/ ).forEach( ( line ) => {
			const trimmedLine = line.trim();
			if ( ! trimmedLine.includes( '=' ) ) {
				return;
			}

			const separator = trimmedLine.indexOf( '=' );
			const source = trimmedLine.slice( 0, separator ).trim();
			const target = trimmedLine.slice( separator + 1 ).trim();
			const column = Array.from( columnsContainer.querySelectorAll( '[data-column-index]' ) ).find( ( item ) => {
				return /^\d+$/.test( source )
					? item.dataset.columnIndex === source
					: item.dataset.columnHeader.toLowerCase() === source.toLowerCase();
			} );
			const field = Array.from( fieldsContainer.querySelectorAll( '[data-user-field]' ) ).find( ( item ) => item.dataset.userField === target );

			if ( column && field ) {
				assignField( column, field );
			}
		} );
	};

	const renderColumns = ( headers ) => {
		columnsContainer.innerHTML = '';
		headers.forEach( ( header, index ) => {
			const column = document.createElement( 'div' );
			column.className = 'user-import-column';
			column.dataset.columnIndex = index;
			column.dataset.columnHeader = header;
			const label = document.createElement( 'strong' );
			label.textContent = `${ index }: ${ header || '(blank header)' }`;
			const dropZone = document.createElement( 'span' );
			dropZone.className = 'user-import-drop-zone';
			dropZone.textContent = 'Drop a field here';
			column.append( label, dropZone );
			column.addEventListener( 'dragover', ( event ) => {
				event.preventDefault();
				column.classList.add( 'is-dragging-over' );
			} );
			column.addEventListener( 'dragleave', () => column.classList.remove( 'is-dragging-over' ) );
			column.addEventListener( 'drop', ( event ) => {
				event.preventDefault();
				column.classList.remove( 'is-dragging-over' );
				const fieldName = event.dataTransfer.getData( 'text/plain' );
				const field = Array.from( mappingBuilder.querySelectorAll( '[data-user-field]' ) ).find( ( item ) => item.dataset.userField === fieldName );
				if ( field ) {
					assignField( column, field );
				}
			} );
			columnsContainer.appendChild( column );
		} );
		applyMapping();
	};

	mappingBuilder.querySelectorAll( '[data-user-field]' ).forEach( ( field ) => {
		field.addEventListener( 'dragstart', ( event ) => {
			event.dataTransfer.setData( 'text/plain', field.dataset.userField );
			event.dataTransfer.setData( 'application/x-user-import-field', 'true' );
			event.dataTransfer.effectAllowed = 'copy';
		} );
	} );

	fieldsContainer.addEventListener( 'dragover', ( event ) => {
		if ( event.dataTransfer.types.includes( 'application/x-user-import-assignment' ) ) {
			event.preventDefault();
			fieldsContainer.classList.add( 'is-dragging-over' );
		}
	} );
	fieldsContainer.addEventListener( 'dragleave', () => fieldsContainer.classList.remove( 'is-dragging-over' ) );
	fieldsContainer.addEventListener( 'drop', ( event ) => {
		if ( ! event.dataTransfer.types.includes( 'application/x-user-import-assignment' ) ) {
			return;
		}
		event.preventDefault();
		fieldsContainer.classList.remove( 'is-dragging-over' );
		removeAssignment( event.dataTransfer.getData( 'text/plain' ) );
	} );

	fileInput.addEventListener( 'change', () => {
		const file = fileInput.files[ 0 ];
		if ( ! file ) {
			return;
		}
		const reader = new FileReader();
		reader.addEventListener( 'load', () => renderColumns( parseCsvHeader( String( reader.result ).split( /\r?\n/, 1 )[ 0 ] ) ) );
		reader.readAsText( file );
	} );

	mappingInput.addEventListener( 'input', () => {
		if ( mappingInput.value.trim() ) {
			mappingBuilder.classList.add( 'has-manual-mapping' );
		} else {
			mappingBuilder.classList.remove( 'has-manual-mapping' );
		}
	} );

	if ( savedMappingSelect ) {
		savedMappingSelect.addEventListener( 'change', () => {
			const selected = savedMappingSelect.options[ savedMappingSelect.selectedIndex ];
			mappingInput.value = selected ? selected.dataset.mapping || '' : '';
			mappingInput.dispatchEvent( new Event( 'input' ) );
			if ( columnsContainer.querySelector( '[data-column-index]' ) ) {
				applyMapping();
			}
		} );
	}
}() );
