<?php
	//UTF: ☺

	function loadFontParser($script_location)
	{
		$BASElocation = $script_location . "Font parser/";
		$FONTlocation = $script_location . "Fonts/";

		$OTFlocation = $BASElocation . "OTF data/";
		$TTFlocation = $OTFlocation . "TTF classes/";
		$CFFlocation = $OTFlocation . "CFF classes/";
		$GDlocation = $OTFlocation . "Glyph classes/";

		// byte sequence reading
		require_once($BASElocation . "fileread.php");

		// non-standard but "common" top level functions... 
		// although, that said: it's only one function
		require_once($BASElocation . "common.php");
		
		// font classes
		require_once($OTFlocation. "otttfont.php");

		// bind the various locations for future use
		OTTTFONT::$FONTlocation = $FONTlocation;
		OTTTFONT::$OTFlocation = $OTFlocation;
		OTTTFONT::$TTFlocation = $TTFlocation;
		OTTTFONT::$CFFlocation = $CFFlocation;
		OTTTFONT::$GDlocation = $GDlocation;
	}
?>
