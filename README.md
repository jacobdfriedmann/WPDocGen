WPDocGen
========

Description
-----------

WPDocGen is a WordPress plugin that generates documentation for your current WordPress theme and then displays it within the theme itself. It also comes with a RESTful API (via WordPress's native admin-ajax.php file) for retrieving the data and displaying it how you wish.

*Note: This is an Alpha release, therefore there are likely still some kinks to work out. Feel free to open a GitHub issue.*

Usage
-----

### Basic Usage

Place the shortcode `[wpdocgen]` onto the page where you wish the documentation to display. 

On the first load of this page, all of the files in the current theme directory will be parsed and documentation information will be stored in the WordPress database. For this reason, the first load will be noticeably slower than subsequent viewings.

When the WPDocGen is visited, the documentation Table of Contents will be displayed. Clicking links within the Table of Contents navigates to subsections of the documentation by appending arguments to the base URL.

### RESTful Usage

You can also retrieve information about the theme through a RESTful API call. This is done via the normal WordPress POST to /wp-admin/admin-ajax.php. The way the plugin is currently configured uses a combination of POST and GET arguments.

#### POST

*action* (required) Must be equal to "wpdocgen". This is how WordPress identifies the correct PHP function to call.

#### GET

*format* (optional) If not included defaults to HTML. Can also be set to "json" to recieve a JSON response.

*file* (optional) The file ID. If file or section is not included returns Table of Contents information.

*section* (optional) The section ID.

### API Examples

Here are some examples of retrieving documentation information through the RESTful API. All examples use jQuery.post method for simplicity.

- Get the Table of Contents as JSON.

`jQuery.post("http://mywordpressurl.com/wp-admin/admin-ajax.php?format=json", "action=wpdocgen")`

- Get the file with an ID of 3 as HTML.

`jQuery.post("http://mywordpressurl.com/wp-admin/admin-ajax.php?file=3", "action=wpdocgen")`

- Get the section with an ID of 5 as JSON.

`jQuery.post("http://mywordpressurl.com/wp-admin/admin-ajax.php?section=5&format=json", "action=wpdocgen")`


Documentation Style Guide
-------------------------

In the Alpha release, documentation must follow a relatively strict style. It was designed to read documentation in the style of that found in the TwentyThirteen theme by the WordPress team. For now, use that theme as guide for writing inline documentation. As the plugin gets closer to a "1.0" release, there will be more flexibility in documentation style as well as a complete style guide here.

Planned Improvements
--------------------

- Support for multiple theme documentations at once.
- More flexible documentation style.
- Complete style guide and more thorough documentation.
- Support for plugin documentation.

