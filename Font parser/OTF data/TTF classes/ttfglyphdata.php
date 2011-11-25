<?php
	//UTF: ☺

	require_once(OTTTFont::$GDlocation . "glyphrules.php");

	/**
	 * A container for the data associated with a TrueType glyph
	 */
	class TTFGlyphData extends Glyph
	{
		var $unitsPerEm;
		var $numberOfContours;
		var $xMin;
		var $yMin;
		var $xMax;
		var $yMax;
		var $width;
		var $height;
		var $startPtsOfContours;
		var $endPtsOfContours;
		var $instructionLength;
		var $instructions;
		var $flags;
		var $xCoordinates;
		var $yCoordinates;

		var $glyphdata = false;

		// mask flags, one bit each
		var $MASK_ON_CURVE;
		var $MASK_X_BYTE_OR_SHORT;
		var $MASK_Y_BYTE_OR_SHORT;
		var $MASK_REPEAT;
		var $MASK_X_DUAL;
		var $MASK_Y_DUAL;

		function __construct() {
			$this->MASK_ON_CURVE = 0x01;
			$this->MASK_X_BYTE_OR_SHORT = 0x02;
			$this->MASK_Y_BYTE_OR_SHORT = 0x04;
			$this->MASK_REPEAT = 0x08;
			$this->MASK_X_DUAL = 0x10;
			$this->MASK_Y_DUAL = 0x20; }

		function on_curve($index) { return $this->masks($index, $this->MASK_ON_CURVE); }
		function x_is_byte($index) { return $this->masks($index, $this->MASK_X_BYTE_OR_SHORT); }
		function y_is_byte($index) { return $this->masks($index, $this->MASK_Y_BYTE_OR_SHORT); }
		function flag_repeats($index) { return $this->masks($index, $this->MASK_REPEAT); }
		function x_dual_set($index) { return $this->masks($index, $this->MASK_X_DUAL); }
		function y_dual_set($index) { return $this->masks($index, $this->MASK_Y_DUAL); }

		private function masks($index, $mask) { return ($this->flags[$index] & $mask) == $mask; }

		/**
		 * merge another TTFGlyphData object into this one. If this one was not initialised, verbatim copy instead
		 */
		function merge($other)
		{
			if(is_array($this->flags))
			{
				$this->flags = array_merge($this->flags, $other->flags);
				$points = count($this->xCoordinates);
				$this->xCoordinates = array_merge($this->xCoordinates, $other->xCoordinates);
				$this->yCoordinates = array_merge($this->yCoordinates, $other->yCoordinates);
				$this->startPtsOfContours = array_merge($this->startPtsOfContours, $other->startPtsOfContours);
				$this->endPtsOfContours = array_merge($this->endPtsOfContours, $other->endPtsOfContours);
				// the starts and ends of contours are, of course, now shifted by the number of points in the original contour
				for($i=$points; $i<count($this->startPtsOfContours); $i++) { $this->startPtsOfContours[$i] += $points; }
				for($i=$points; $i<count($this->endPtsOfContours); $i++) { $this->endPtsOfContours[$i] += $points; }
				$this->glyphdata->merge($other->glyphdata);
			}
			else
			{
				$this->flags = $other->flags;
				$this->xCoordinates = $other->xCoordinates;
				$this->yCoordinates = $other->yCoordinates;
				$this->startPtsOfContours = $other->startPtsOfContours;
				$this->endPtsOfContours = $other->endPtsOfContours;
				$this->glyphdata = $other->glyphdata;
			}
		}

		// matrix = offset x/y, 2x2 transform
		function formGlyphRules($matrix = array(0,0, 1,0,0,1))
		{
			$this->glyphdata = new TrueTypeGlyphRules();
			$bezier = new TTFBezierPoints();
			$coordinates = count($this->xCoordinates);

			// these variables track the absolute coordinates
			$dx=0;
			$dy=0;

			// these two variables are used to resolve path closing
			$path_start_x = 0;
			$path_start_y = 0;

			$xoffset = $matrix[0];
			$yoffset = $matrix[1];

			$firstx = 0;
			$firsty = 0;

			for($i=0; $i<$coordinates;  $i++)
			{
				$oncurve = $this->on_curve($i);

				$x = $this->xMin + $this->xCoordinates[$i];
				$y = $this->yMin + $this->yCoordinates[$i];

				// apply matrix transform
				$x = $x * $matrix[2] + $y * $matrix[3];
				$y = $x * $matrix[4] + $y * $matrix[5];

				if($i==0) {
					$x += $xoffset;
					$y += $yoffset;
					$firstx = $x;
					$firsty = $y; }

				// does this coordinate mark the start of a contour?
				if(in_array($i, $this->startPtsOfContours)) {
					$path_start_x = $dx + $x;
					$path_start_y = $dy + $y;
					$this->glyphdata->addRule(new GlyphMoveToRule($x, $y)); }

				// process coordinate: not on-curve = bezier control point
				else if(!$oncurve) { $bezier->add_control($x, $y); }

				// process coordinate: on-curve point, last in a bezier sequence
				else if($bezier->has_points()) {
					$bezier->set_to($x, $y);
					$this->glyphdata->addRules($bezier->toGlyphRules());
					$bezier = new TTFBezierPoints(); }

				// process coordinate: on-curve point, line segment
				else {	$this->glyphdata->addRule(new GlyphLineToRule($x,$y)); }

				// real coordinate: update dx/dy
				$dx += $x;
				$dy += $y;

				// if we're at the end of a contour, generate the final contour segment(s).
				// if there's bezier information, the last point on the bezier curve is actually
				// off-curve, and we need to add the very first point as "last point".
				if(in_array($i, $this->endPtsOfContours)) {
					$xdiff = $path_start_x - $dx;
					$ydiff = $path_start_y - $dy;
					if($bezier->has_points()) {
						$bezier->set_to($xdiff, $ydiff);
						$bezier->set_implied_last();
						$this->glyphdata->addRules($bezier->toGlyphRules());
						$bezier = new TTFBezierPoints(); }}
			}

			// move back to 0/0, in case of compound glyphs
			$this->glyphdata->addRule(new GlyphMoveToRule(-$dx, -$dy));
		}

		function toString()
		{
			return "{" . $this->unitsPerEm . "," .
					$this->numberOfContours . "," .
					$this->xMin . "," .
					$this->yMin . "," .
					$this->xMax . "," .
					$this->yMax . "," .
					$this->width . "," .
					$this->height . "," .
					"[" . implode(",",$this->startPtsOfContours) . "]," .
					"[" . implode(",",$this->endPtsOfContours) . "]," .
					$this->instructionLength . "," .
					"[" . implode(",",$this->instructions) . "]," .
					$this->flags . "," .
					"[" . implode(",",$this->xCoordinates) . "]," .
					"[" . implode(",",$this->yCoordinates) . "]}";
		}
	}

	/**
	 * In order to help build the outline, we need a poly-bezier tracking class. Coordinates
	 * in TrueType may indicate successive bezier control points, with on-curve points
	 * implicitly lying in between these control points. Once an on-curve point is seen again,
	 * the full bezier expression can be evaluated.
	 */
	class TTFBezierPoints
	{
		var $control_x  = array();
		var $control_y = array();
		var $has_points = false;
		var $implied_last = false;

		function add_control($x, $y) { $this->control_x[]=$x  ; $this->control_y[]=$y  ;  $this->has_points = true ; }
		function set_to($x, $y) { $this->add_control($x, $y); }

		function has_points() { return $this->has_points; }
		function get_points() {
			$points = array();
			for($i=0; $i<count($this->control_x); $i ++) { $points[] = $this->control_x[$i] . ",". $this->control_y[$i]; }
			return $points; }

		// when a bezier curve has an implied last coordinate, the corresponding GlyphRule that is build
		// needs to know that in terms of coordinate placement, a MoveTo that counteracts the last
		// coordinate is required. If this is not done, the relative coordinates will be off.
		function set_implied_last() { $this->implied_last = true; }

		// TrueType uses quadratic curves. The last point in the control_... variables is a
		// genuine coordinate, any points in between indicate successive control points.
		// If there is more than one control point, there are on-curve coordinates between
		// successive control points.
		function toGlyphRules()
		{
			$count = count($this->control_x);
			$rules = array();

			// only one control point (simple quadratic bezier)
			if(count($this->control_x)==2) {
				$rules[] = new GlyphQuadCurveToRule($this->control_x[0], $this->control_y[0], $this->control_x[1], $this->control_y[1]); }

			// more than one control point - start inferring real coordinates
			else {
				// run through the sequence up to the last control point (last-1) + genuine coordinate (last)
				for($i=0; $i<$count-2; $i++) {
					// the inferred point lies halfway between control (i) and control (i+1). Since we're using
					// relative coordinates, that means half the distance indicated by control (i+1)
					$inferred_x = intval($this->control_x[$i+1]/2);
					$inferred_y = intval($this->control_y[$i+1]/2);
					$rules[] = new GlyphQuadCurveToRule($this->control_x[$i], $this->control_y[$i], $inferred_x, $inferred_y);
					// and of course, the distance from the inferred point to the next control is now half (i+1) too
					$this->control_x[$i+1] = $inferred_x;
					$this->control_y[$i+1] = $inferred_y;
				}
				// and then add the final control (last-1) to on-curve coordinate (last) bezier curve
				$rules[] = new GlyphQuadCurveToRule($this->control_x[$count-2], $this->control_y[$count-2], $this->control_x[$count-1], $this->control_y[$count-1]);
			}

			// make sure to counteract the relative coordinate shift that was caused by closing a contour with
			// an open bezier curve (last bezier coordinate being the first contour coordinate).
			if($this->implied_last) { $rules[] = new GlyphMoveToRule(-$this->control_x[$count-1], -$this->control_y[$count-1]); }

			return $rules;
		}
	}
?>
