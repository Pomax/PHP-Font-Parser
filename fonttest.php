<?php
	//UTF: ☺
	require_once("fontparse.php");
	
	
	/**
	 * This is a demonstrator script that you can use
	 * to fetch glyph outlines from a font, with the option
	 * to use fallback fonts if you want to test different
	 * characters that aren't found in a single font.
	 */

	$fonts = array("reduced.ttf");
	
	// try to get the glyph's metrics and outline information
	// from our preferred font. If it's not in there, keep looking
	// in the other fonts until it's found, or we run out of fonts.
	$json = false;
	while($json === false && count($fonts)>0) {
		$font = new OTTTFont(array_pop($fonts));
		echo $font->toString(); }
?>
