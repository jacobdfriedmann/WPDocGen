var test;

var wpdocgen = window.wpdocgen = {

	init: function () {
		jQuery(".entry-title").hide();
	},

	initializeTOC: function () {
		var data = arguments[0];
		var content = '<div id="wpdocgen-material"><ul id="wpdocgen-toc" style="display:none;"> \
	    	<li id="wpdocgen-toc-css"> \
				<h3>CSS</h3> \
	    		<ul id="wpdocgen-toc-css-list" style="display:none;"></ul> \
	    	</li> \
	    	<li id="wpdocgen-toc-php"> \
				<h3>PHP</h3> \
	    		<ul id="wpdocgen-toc-php-list" style="display:none;"></ul> \
	    	</li> \
	    	<li id="wpdocgen-toc-js"> \
				<h3>JavaScript</h3> \
	    		<ul id="wpdocgen-toc-js-list" style="display:none;"></ul> \
	    	</li> \
	    </ul></div>';
	    jQuery("#wpdocgen-content").append(content);
		for (var file in data) {
			var list = "<li><a href='" + wpdocgen_base_url + "&file="+file+"'><h3>" + data[file].name + "</a></h3><ul style='display:none;'>";
			for (var section in data[file]) {
				if (data[file][section] != null && data[file][section].hasOwnProperty("name")) {
					list = list + "<li><h4>" + data[file][section].name + "</h4></li>";
				}
			}
			var type = data[file].type;
			jQuery("#wpdocgen-toc-" + type + "-list").append(list);
		}
		jQuery("#wpdocgen-toc").css("display", "block");
	},

	getTOC: function () {
		jQuery.ajax({
			url: ajaxurl,
			type: "POST",
			data: "action=wpdocgen_get_toc",
			success: function (data) {
				wpdocgen.initializeTOC(data);
				jQuery("#wpdocgen-toc h3").click(function () {
					jQuery(this).siblings("ul").slideToggle();
				});
			}
		});
	},

	getPage: function () {
		jQuery.ajax({
			url: ajaxurl,
			type: "POST",
			data: "action=wpdocgen_get_file&file="+wpdocgen_file,
			success: function (data) {
				jQuery("#wpdocgen-page-description").text(data.description);
			}
		});
	}

}

jQuery(function(){
	wpdocgen.init();
});