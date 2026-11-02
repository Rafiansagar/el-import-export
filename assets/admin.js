( function () {
	var table = document.querySelector( '.eie-table' );
	if ( ! table ) {
		return;
	}

	var rows = Array.prototype.slice.call( table.querySelectorAll( 'tbody tr[data-type]' ) );
	var empty = table.querySelector( '.eie-empty' );
	var typeFilter = document.getElementById( 'eie-type-filter' );
	var search = document.getElementById( 'eie-search' );
	var elementorOnly = document.getElementById( 'eie-elementor-only' );
	var checkAll = document.getElementById( 'eie-check-all' );
	var button = document.getElementById( 'eie-export-btn' );
	var count = document.getElementById( 'eie-count' );

	function visibleRows() {
		return rows.filter( function ( row ) {
			return ! row.hidden;
		} );
	}

	function updateCount() {
		var checked = rows.filter( function ( row ) {
			return row.querySelector( 'input' ).checked;
		} ).length;
		button.disabled = 0 === checked;
		count.textContent = checked ? checked + ' selected' : '';
	}

	function applyFilters() {
		var type = typeFilter.value;
		var term = search.value.trim().toLowerCase();

		rows.forEach( function ( row ) {
			var show = ( ! type || row.dataset.type === type ) &&
				( ! elementorOnly.checked || '1' === row.dataset.elementor ) &&
				( ! term || -1 !== row.querySelector( '.eie-title' ).textContent.toLowerCase().indexOf( term ) );
			row.hidden = ! show;
		} );

		empty.hidden = visibleRows().length > 0;
		checkAll.checked = false;
	}

	typeFilter.addEventListener( 'change', applyFilters );
	search.addEventListener( 'input', applyFilters );
	elementorOnly.addEventListener( 'change', applyFilters );

	checkAll.addEventListener( 'change', function () {
		visibleRows().forEach( function ( row ) {
			row.querySelector( 'input' ).checked = checkAll.checked;
		} );
		updateCount();
	} );

	table.addEventListener( 'click', function ( event ) {
		var row = event.target.closest( 'tbody tr[data-type]' );
		if ( row && 'INPUT' !== event.target.tagName ) {
			var box = row.querySelector( 'input' );
			box.checked = ! box.checked;
		}
		updateCount();
	} );

	// Hidden rows are not exported.
	button.form.addEventListener( 'submit', function () {
		rows.forEach( function ( row ) {
			if ( row.hidden ) {
				row.querySelector( 'input' ).checked = false;
			}
		} );
	} );

	applyFilters();
	updateCount();
} )();
