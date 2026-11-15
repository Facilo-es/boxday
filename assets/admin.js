/* Adds a planner row, and hands the sample button and copy-link buttons their small jobs. */
( function () {
	document.addEventListener( 'click', function ( e ) {
		var add = e.target.closest( '[data-add-row]' );
		if ( add ) {
			e.preventDefault();
			var list = add.parentNode.querySelector( '[data-rows]' );
			var row = list.lastElementChild.cloneNode( true );
			var index = list.children.length + 100;
			row.querySelectorAll( 'input' ).forEach( function ( input ) {
				input.name = input.name.replace( /\[\d+\](\[\w+\])$/, '[' + index + ']$1' );
				if ( 'checkbox' === input.type ) {
					input.checked = false;
				} else {
					input.value = '';
				}
			} );
			list.appendChild( row );
			row.querySelector( 'input[type=text]' ).focus();
		}
		var place = e.target.closest( '[data-add-place]' );
		if ( place ) {
			var rows = document.querySelector( '.bd-places' );
			var next = rows.lastElementChild.cloneNode( true );
			var at = rows.children.length;
			next.querySelectorAll( 'input, select' ).forEach( function ( input ) {
				input.name = input.name.replace( /sites\[\d+\]/, 'sites[' + at + ']' );
				if ( 'hidden' === input.type ) {
					input.value = '0';
				} else if ( 'text' === input.type || 'number' === input.type ) {
					input.value = '';
				}
			} );
			rows.appendChild( next );
			next.querySelector( 'input[type=text]' ).focus();
		}
		var starter = e.target.closest( '[data-starter]' );
		if ( starter ) {
			var area = document.querySelector( 'textarea[name=agreement_text]' );
			if ( ! area.value.trim() || window.confirm( starter.dataset.confirm ) ) {
				area.value = starter.dataset.starter;
			}
		}
		var copy = e.target.closest( '[data-copy]' );
		if ( copy && navigator.clipboard ) {
			e.preventDefault();
			navigator.clipboard.writeText( copy.dataset.copy );
		}
	} );
}() );
