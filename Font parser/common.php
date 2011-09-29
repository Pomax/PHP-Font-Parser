<?php
	//UTF: ☺

	// standard in Python, not so standard in PHP
	function uniord($c) {
		$h = ord($c{0});
		if ($h <= 0x7F) { return $h; }
		else if ($h < 0xC2) { return false; }
		else if ($h <= 0xDF) { return ($h & 0x1F) << 6 | (ord($c{1}) & 0x3F); }
		else if ($h <= 0xEF) { return ($h & 0x0F) << 12 | (ord($c{1}) & 0x3F) << 6 | (ord($c{2}) & 0x3F); }
		else if ($h <= 0xF4) { return ($h & 0x0F) << 18 | (ord($c{1}) & 0x3F) << 12 | (ord($c{2}) & 0x3F) << 6 | (ord($c{3}) & 0x3F); }
		return false; }

	// standard bit masking
	function masks($val, $mask) {
		$res = $val & $mask;
		return ($res == $mask); 
	}

?>