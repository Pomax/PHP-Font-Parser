<?php
	//UTF: ☺

	/**
	 * Vertical Origin table for glyphs coded in a CFF block
	 */
	class VORG
	{
		var $majorVersion;
		var $minorVersion;
		var $defaultVertOriginY;
		var $numVertOriginYMetrics;
		var $vertOriginYMetrics  = array();
		
		function __construct(&$fh)
		{
			$this->majorVersion = FileRead::read_USHORT($fh);
			$this->minorVersion = FileRead::read_USHORT($fh);
			$this->defaultVertOriginY = FileRead::read_SHORT($fh);
			$this->numVertOriginYMetrics = FileRead::read_USHORT($fh);
			// actual data
			for($i=0; $i<$this->numVertOriginYMetrics; $i++) {
				$glyphIndex = FileRead::read_USHORT($fh);
				$vertOriginY = FileRead::read_SHORT($fh);
				$this->vertOriginYMetrics[] = array("glyphIndex"=>$glyphIndex, "vertOriginY"=>$vertOriginY); }
		}
		
		function getVertOriginY($index)
		{
			for($i=0; $i<$this->numVertOriginYMetrics; $i++) {
				// too far? not in table, use default
				$gindex = $this->vertOriginYMetrics[$i]["glyphIndex"];
				if($gindex>$index) { return $this->defaultVertOriginY; }
				// not too far, check if there's an entry in the table
				else if($gindex == $index) { return $this->vertOriginYMetrics[$i]["vertOriginY"]; }}
			// last entry index below search index: use default
			return $this->defaultVertOriginY;
		}
	}
?>
