var test;

var wpdocgen = window.wpdocgen = {

	init: function () {
		jQuery(".entry-title").hide();
		if (wpdocgen_toc) {
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
		}
		else {
			jQuery.ajax({
				url: ajaxurl,
				type: "POST",
				data: "action=wpdocgen_get_file&file="+wpdocgen_file,
				success: function (data) {
					jQuery("#wpdocgen-page-name").text(data.name);
					jQuery("#wpdocgen-page-description").text(data.description);
				}
			});
		}
	},

	initializeTOC: function () {
		var data = arguments[0];
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
	}

}

jQuery(function(){
	wpdocgen.init();
});