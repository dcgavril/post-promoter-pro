(function ($) {
	$('.share-time-selector').timepicker({ 'step': 15 });

	$('#_ppp_post_override').click( function() {
		$('.post-override-matrix').toggle();
	});
})(jQuery);