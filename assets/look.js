/* Setup's look step: reads the site's buttons from a same-origin page in a hidden frame, then paints the preview. */
( function () {
	var box = document.querySelector( '.bd-look' );
	if ( ! box ) {
		return;
	}
	var form = box.closest( 'form' );
	var prev = box.querySelector( '.bd-prev' );
	function paint() {
		prev.style.setProperty( '--a', form.accent.value );
		prev.style.setProperty( '--r', form.radius.value + 'px' );
		prev.style.setProperty( '--tt', form.caps.checked ? 'uppercase' : 'none' );
		box.querySelector( '.bd-range output' ).textContent = form.radius.value + ' px';
	}
	function hex( rgb ) {
		var m = /rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?/.exec( rgb || '' );
		if ( ! m || ( m[4] !== undefined && parseFloat( m[4] ) < 0.5 ) ) {
			return '';
		}
		return '#' + [ m[1], m[2], m[3] ].map( function ( n ) { return ( '0' + parseInt( n, 10 ).toString( 16 ) ).slice( -2 ); } ).join( '' );
	}
	form.addEventListener( 'input', paint );
	paint();
	var url = box.dataset.lookRead;
	if ( ! url ) {
		return;
	}
	var frame = document.createElement( 'iframe' );
	frame.setAttribute( 'aria-hidden', 'true' );
	frame.tabIndex = -1;
	frame.style.cssText = 'position:absolute;width:1200px;height:800px;left:-9999px;top:0;border:0';
	frame.src = url;
	frame.onload = function () {
		try {
			var doc = frame.contentDocument;
			var btn = doc.querySelector( '.single_add_to_cart_button, .wp-element-button, .wp-block-button__link, button[type=submit], .button, button' );
			if ( btn ) {
				var cs = frame.contentWindow.getComputedStyle( btn );
				var color = hex( cs.backgroundColor );
				if ( color && '#ffffff' !== color && '#000000' !== color ) {
					form.accent.value = color;
				}
				form.radius.value = Math.min( 30, parseInt( cs.borderTopLeftRadius, 10 ) || 0 );
				form.caps.checked = 'uppercase' === cs.textTransform;
				paint();
			}
		} catch ( e ) {}
		frame.remove();
	};
	document.body.appendChild( frame );
}() );
