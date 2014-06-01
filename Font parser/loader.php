<?php
	//UTF: ☺

	function loadFontParser($script_location)
	{
		$BASElocation = $script_location . "Font parser" . DIRECTORY_SEPARATOR;
		$FONTlocation = $script_location . "Fonts" . DIRECTORY_SEPARATOR;

		$OTFlocation = $BASElocation . "OTF data" . DIRECTORY_SEPARATOR;
		$TTFlocation = $OTFlocation . "TTF classes" . DIRECTORY_SEPARATOR;
		$CFFlocation = $OTFlocation . "CFF classes" . DIRECTORY_SEPARATOR;
		$GDlocation = $OTFlocation . "Glyph classes" . DIRECTORY_SEPARATOR;

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
