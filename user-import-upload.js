( function () {
	'use strict';

	const dropzone = document.getElementById( 'user-import-upload-dropzone' );
	const fileInput = document.getElementById( 'user-import-csv' );
	const continueButton = document.getElementById( 'user-import-continue-upload' );

	if ( ! dropzone || ! fileInput ) {
		return;
	}

	const updateLabel = () => {
		const file = fileInput.files[ 0 ];
		if ( file ) {
			dropzone.querySelector( 'strong' ).textContent = file.name;
			dropzone.classList.add( 'has-file' );
		}
		if ( continueButton ) {
			continueButton.disabled = ! file;
		}
	};

	const acceptFiles = ( files ) => {
		if ( ! files || ! files.length ) {
			return;
		}

		const dataTransfer = new DataTransfer();
		dataTransfer.items.add( files[ 0 ] );
		fileInput.files = dataTransfer.files;
		fileInput.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		updateLabel();
	};

	dropzone.addEventListener( 'click', () => fileInput.click() );
	dropzone.addEventListener( 'keydown', ( event ) => {
		if ( 'Enter' === event.key || ' ' === event.key ) {
			event.preventDefault();
			fileInput.click();
		}
	} );
	dropzone.addEventListener( 'dragover', ( event ) => {
		event.preventDefault();
		dropzone.classList.add( 'is-dragging-over' );
	} );
	dropzone.addEventListener( 'dragleave', () => dropzone.classList.remove( 'is-dragging-over' ) );
	dropzone.addEventListener( 'drop', ( event ) => {
		event.preventDefault();
		dropzone.classList.remove( 'is-dragging-over' );
		acceptFiles( event.dataTransfer.files );
	} );
	fileInput.addEventListener( 'change', updateLabel );
}() );
