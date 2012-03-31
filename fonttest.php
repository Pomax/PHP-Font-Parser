<?php
	//UTF: ☺
	require_once("fontparse.php");

	/**
	 * This is a demonstrator script that you can use
	 * to fetch glyph outlines from a font, with the option
	 * to use fallback fonts if you want to test different
	 * characters that aren't found in a single font.
	 *
	 * For this test script to work, place a font in the
	 * ./Fonts directory, and replace 'myfont.ttf' with the
	 * filename of the font you copied over.
	 */
	$fonts = array("myfont.ttf");
	
	/**
	 * Now, we try to get a glyph's metrics and outline information
	 * from our preferred font. If it's not in there, and the array
	 * has more than one font name, we keep looking in the other
	 * fonts until it's found, or we run out of fonts.
	 *
	 * For this test, we'll print the header information for the
	 * loaded font, and try to find the letter "g".
	 */
	$letter = "g";
	$json = false;
	while($json === false && count($fonts)>0) {
		$font = new OTTTFont(array_pop($fonts));
		echo "font header data:\n" . $font->toString() . "\n";
		$data = $font->get_glyph($letter);
		if($data!==false) {
			$json = $data->toJSON(); }}

	if($json===false) { die("the letter '$letter' could not be found!"); }
	echo "glyph information for '$letter':\n" . $json;
?>
