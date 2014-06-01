<?php
	//UTF: ☺

	// determine where we're actually running. You can change this variable 
	// to hard-coded-ly reflect your production code's location.
	$script_location = dirname(__FILE__) . DIRECTORY_SEPARATOR;

	// uncomment the following line if you need error reporting and warnings explicitly turned on
//	require_once($script_location . "errors.php");

	// grab the loader code, which will set up all the locations
	require_once($script_location . join(DIRECTORY_SEPARATOR, array("Font parser", "loader.php")));

	// finally, set everything up so that we can start loading fonts.
	loadFontParser($script_location);
?>
