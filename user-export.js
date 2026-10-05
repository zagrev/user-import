( function () {
	'use strict';

	const available = document.getElementById( 'user-export-available' );
	const selected = document.getElementById( 'user-export-selected' );
	const sourceInputs = document.querySelectorAll( '[name="user_export_source"]' );
	const groupInput = document.getElementById( 'user-export-group' );
	const queryInput = document.getElementById( 'user-export-query' );

	const updateSource = () => {
		const source = document.querySelector( '[name="user_export_source"]:checked' );
		if ( source && groupInput && queryInput ) {
			groupInput.disabled = source.value !== 'group';
			queryInput.disabled = source.value !== 'query';
			queryInput.required = source.value === 'query';
		}
	};
	sourceInputs.forEach( ( input ) => input.addEventListener( 'change', updateSource ) );
	updateSource();

	if ( ! available || ! selected ) {
		return;
	}

	let dragged = null;
	const updateOrder = () => {
		Array.from( available.children ).sort( ( left, right ) =>
			left.querySelector( 'label' ).textContent.trim().localeCompare(
				right.querySelector( 'label' ).textContent.trim(),
				undefined,
				{ numeric: true, sensitivity: 'base' }
			)
		).forEach( ( item ) => available.appendChild( item ) );
		Array.from( selected.children ).forEach( ( item, index, items ) => {
			item.querySelector( '.user-export-up' ).disabled = index === 0;
			item.querySelector( '.user-export-down' ).disabled = index === items.length - 1;
		} );
	};

	[ available, selected ].forEach( ( list ) => {
		list.addEventListener( 'change', ( event ) => {
			if ( ! event.target.matches( 'input[type="checkbox"]' ) ) {
				return;
			}
			const checkbox = event.target;
			const item = checkbox.closest( '.user-export-field' );
			( checkbox.checked ? selected : available ).appendChild( item );
			updateOrder();
			checkbox.focus();
		} );
		list.addEventListener( 'click', ( event ) => {
			const button = event.target.closest( 'button' );
			if ( ! button || list !== selected ) {
				return;
			}
			const item = button.closest( '.user-export-field' );
			if ( button.classList.contains( 'user-export-up' ) && item.previousElementSibling ) {
				selected.insertBefore( item, item.previousElementSibling );
			} else if ( button.classList.contains( 'user-export-down' ) && item.nextElementSibling ) {
				selected.insertBefore( item.nextElementSibling, item );
			}
			updateOrder();
			button.focus();
		} );
		list.addEventListener( 'dragstart', ( event ) => {
			dragged = event.target.closest( '.user-export-field' );
			if ( dragged ) {
				event.dataTransfer.setData( 'text/plain', dragged.dataset.field );
				event.dataTransfer.effectAllowed = 'move';
			}
		} );
		list.addEventListener( 'dragover', ( event ) => {
			if ( dragged ) {
				event.preventDefault();
				list.classList.add( 'is-dragging-over' );
			}
		} );
		list.addEventListener( 'dragleave', ( event ) => {
			if ( ! list.contains( event.relatedTarget ) ) {
				list.classList.remove( 'is-dragging-over' );
			}
		} );
		list.addEventListener( 'drop', ( event ) => {
			if ( ! dragged ) {
				return;
			}
			event.preventDefault();
			const target = event.target.closest( '.user-export-field' );
			if ( target && target !== dragged ) {
				const bounds = target.getBoundingClientRect();
				list.insertBefore( dragged, event.clientY < bounds.top + bounds.height / 2 ? target : target.nextElementSibling );
			} else if ( ! target ) {
				list.appendChild( dragged );
			}
			dragged.querySelector( 'input' ).checked = list === selected;
			list.classList.remove( 'is-dragging-over' );
			updateOrder();
		} );
		list.addEventListener( 'dragend', () => {
			dragged = null;
			available.classList.remove( 'is-dragging-over' );
			selected.classList.remove( 'is-dragging-over' );
		} );
	} );
	updateOrder();
}() );