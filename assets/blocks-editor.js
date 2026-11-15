/* Shows a plain placeholder for BoxDay's blocks in the editor. The real page renders on the server. */
( function ( wp ) {
	[ [ 'boxday/signup', 'BoxDay sign-up' ], [ 'boxday/member', 'BoxDay member page' ], [ 'boxday/this-week', "BoxDay this week's box" ] ].forEach( function ( block ) {
		wp.blocks.registerBlockType( block[ 0 ], {
			edit: function () {
				return wp.element.createElement( 'p', { style: { padding: '16px', background: '#f6f7f7', border: '1px solid #dcdcde' } }, block[ 1 ] );
			},
			save: function () {
				return null;
			}
		} );
	} );
}( window.wp ) );
