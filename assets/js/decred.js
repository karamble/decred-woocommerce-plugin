(function($) {
    $(window).load(function () {
        'use strict';
	setInterval(timingLoad, 10000);
	function timingLoad() {
		$('#payment-status').load(window.location.href + ' #payment-status', function() {
		});
	}

    });
}(jQuery));
