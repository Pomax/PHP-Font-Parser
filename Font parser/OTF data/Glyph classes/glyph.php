<?php
	//UTF: ☺

	/**
	 * Unified glyph representation.
	 */
	class Glyph
	{
		var $glyph;	    // the actual glyph
		var $hash;      // the glyph's outline hash (used for validation purposes)
		var $font;	    // font the glyph was plucked from
		var $type;	    // CFF or TTF?
		var $index;	    // glyph's index in that font
		var $quadsize;  // the size of the em quad

		var $bounds = array("minx"=>0,"miny"=>0,"maxx"=>0,"maxy"=>0, "width"=>0, "height"=>0);

		var $lsb;		  // left side bearing
		var $rsb;		  // right side bearing
		var $width;		// lsb + bounding box width + rsb (= lsb + font-indicated width)
		var $height;	// simple height

		var $glyphrules = false;	// vector outline data

		/**
		 * Get the path instructions for this glyph's outline
		 */
		function getGlyphRules() { return $this->glyphrules; }

		/**
		 * runs through the glyphrules, building up the bounding box
		 */
		function computeBounds() { $this->bounds = $this->glyphrules->computeBounds(); }

		/**
		 * converts this glyph object to a JSON representation
		 */
		function toJSON() {
			$nl = "\n";
			$tab = "\t";
			$json = "{" . $nl;
			$json .= $tab . 'glyph: "'. $this->glyph.'",' . $nl;
			$json .= $tab . 'font: "'. $this->font .'",' . $nl;
			$json .= $tab . 'type: "'. $this->type.'",' . $nl;
			$json .= $tab . 'index: ' . $this->index . ',' . $nl;
			$json .= $tab . 'quadsize: "'. $this->quadsize.'",' . $nl;
			$json .= $tab . 'lsb: '.$this->lsb.',' . $nl;
			$json .= $tab . 'rsb: '.$this->rsb.',' . $nl;
			$json .= $tab . 'width: '.$this->width.',' . $nl;
			$json .= $tab . 'height: '.$this->height.',' . $nl;
			$json .= $tab . 'minx: '. $this->bounds["minx"].',' . $nl;
			$json .= $tab . 'maxx: '. $this->bounds["maxx"].',' . $nl;
			$json .= $tab . 'miny: '. $this->bounds["miny"].',' . $nl;
			$json .= $tab . 'maxy: '. $this->bounds["maxy"].',' . $nl;
			$json .= $tab . 'outline: "' . $this->glyphrules->compactString() . '"' . $nl;
			$json .=  "}";
			return $json;
		}
	}
?>
