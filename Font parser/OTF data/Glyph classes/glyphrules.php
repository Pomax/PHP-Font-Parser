<?php
	//UTF: ☺

	/**
	 * these rules represent all possible vector instructions that are
	 * used in TrueType and Type 2 outlines: move, line, quadratic
	 * curve, and cubic curve. That's it. Sure, there are loads of 
	 * "shorthand" commands used in both formats, but they still
	 * resolve to these four primitives.
	 */

	/**
	  * Input requirement: all glyph rules use relative coordinates
	  */
	class GlyphRule { 
		function computeBounds(&$x, &$y, &$bounds) {
			$this->computeLocalBounds($x, $y, $bounds);
			$this->pushBounds($x,$y,$bounds); }
		function computeLocalBounds(&$x, &$y, &$bounds) {}
		function pushBounds($x, $y, &$bounds) {
			$x = intval($x);
			$y = intval($y);
			if($bounds["minx"]>$x) { $bounds["minx"] = $x; }
			if($bounds["miny"]>$y) { $bounds["miny"] = $y; }
			if($bounds["maxx"]<$x) { $bounds["maxx"] = $x; }
			if($bounds["maxy"]<$y) { $bounds["maxy"] = $y; }}
		function compactString() { return ""; }
		function toAbsoluteSVG(&$x, &$y) { return ""; }
		function toString() { return "Glyph Rule"; }}

	// SVG 'm' equivalent
	class GlyphMoveToRule extends GlyphRule {
		var $x1; var $y1;
		function __construct($x1, $y1) {
			$this->x1 = $x1;
			$this->y1 = $y1; }
		function computeLocalBounds(&$x, &$y, &$bounds) {
			$x += $this->x1;
			$y += $this->y1; }
		function compactString() { return "m".$this->x1." ".$this->y1; }
		function toAbsoluteSVG(&$x, &$y) { $x += $this->x1; $y += $this->y1; return "M $x $y"; }
		function toString() { return "'Move to' instruction, x/y: ".$this->x1."/".$this->y1; }}

	// shorthand for move(x,0)
	class GlyphHMoveToRule extends GlyphMoveToRule {
		function __construct($x) { parent::__construct($x, 0); }}

	// shorthand for move(0,y)
	class GlyphVMoveToRule extends GlyphMoveToRule {
		function __construct($y) { parent::__construct(0, $y); }}

	// SVG 'l' equivalent
	class GlyphLineToRule extends GlyphRule {
		var $x2; var $y2;
		function __construct($x2, $y2) {
			$this->x2 = $x2;
			$this->y2 = $y2; }
		function computeLocalBounds(&$x, &$y, &$bounds) {
			$x += $this->x2;
			$y += $this->y2; }
		function compactString() { return "l".$this->x2." ".$this->y2; }
		function toAbsoluteSVG(&$x, &$y) { $x +=$this->x2; $y += $this->y2; return "L $x $y"; }
		function toString() { return "'Line to' instruction, x/y: ".$this->x2."/".$this->y2; }}

	// SVG 'h' equivalent, shorthand for line(x,0)
	class GlyphHLineToRule extends GlyphLineToRule {
		function __construct($x2) { parent::__construct($x2,0); }}

	// SVG 'v' equivalent, shorthand for line(0,y)
	class GlyphVLineToRule extends GlyphLineToRule {
		function __construct($y2) { parent::__construct(0, $y2); }}

	// superclass for any-order bezier curve
	class GlyphParametricCurveToRule extends GlyphRule {
		var $x2; var $y2;
		function __construct($x2, $y2) {
			$this->x2 = $x2;
			$this->y2 = $y2; }
		function computeXCurveAt($start_x, $t) { return $t; }
		function computeYCurveAt($start_y, $t) { return $t; }
		function computeFinalPoint(&$x, &$y, $bounds) {}
		function computeLocalBounds(&$x, &$y, &$bounds) {
			// run through the bezier curve
			$steps = 40;
			for($s=1; $s<$steps; $s++) {
				$tx = $this->computeXCurveAt($x, (1/($steps - $s)));
				$ty = $this->computeYCurveAt($y, (1/($steps - $s)));
				$this->pushBounds($tx,$ty,$bounds); }
			// then verify final point
			$this->computeFinalPoint($x, $y, $bounds);
			$this->pushBounds($tx,$ty,$bounds); }
		function compactString() { return ""; }
		function toString() { return "Glyph CurveTo Rule"; }}

	// SVG 'q' equivalent, second order bezier curve (quadratic)
	class GlyphQuadCurveToRule extends GlyphParametricCurveToRule {
		var $cx; var $cy;
		function __construct($cx, $cy, $x2, $y2) {
			parent::__construct($x2, $y2);
			$this->cx = $cx;
			$this->cy = $cy; }
		// compute the curve
		function computeXCurveAt($start_x, $t) { 
			$mt = (1-$t);
			return	$mt * $mt * $start_x + 
					2 * $mt * $t * ($start_x +$this->cx) + 
					$t * $t * ($start_x+$this->cx+$this->x2); }
		function computeYCurveAt($start_y, $t) { 
			$mt = (1-$t);
			return	$mt * $mt * $start_y + 
					2 * $mt * $t * ($start_y +$this->cy) + 
					$t * $t * ($start_y+$this->cy+$this->y2); }
		function computeFinalPoint(&$x, &$y, &$bounds) {
			$x += $this->cx + $this->x2;
			$y += $this->cy + $this->y2; }
		// express this quadratic curve as a cubic curve
		function toCubeCurve() {
			$cx1 = (2*$this->cx) / 3;
			$cy1 = (2*$this->cy) / 3;
			$cx2 = (2*$this->cx + $this->x2) / 3;
			$cy2 = (2*$this->cy + $this->y2) / 3;
			return new GlyphCubeCurveToRule($cx1, $cy1, $cx2, $cy2, $this->x2, $this->y2); }
		// the assumption here is that "relative coordinates" are specified in relation to the previous
		// coordinate pair, but that they must be computed relative to the last seen on-curve point.
		// this means we need to sum over control points.
		function compactString() { return "q".$this->cx." ".$this->cy." ".($this->cx+$this->x2)." ".($this->cy+$this->y2); }
		function toAbsoluteSVG(&$x, &$y) { 
			$x += $this->cx;
			$y += $this->cy;
			$s = "Q $x $y ";
			$x += $this->x2;
			$y += $this->y2;
			return $s . "$x $y"; }
		function toString() { return "'Quadratic Curve to' instruction, over control x/y: ".$this->cx."/".$this->cy.
							" to x/y: ".$this->x2."/".$this->y2; }}

	// SVG 'c' equivalent, third order bezier curve (cubic)
	class GlyphCubeCurveToRule extends GlyphParametricCurveToRule {
		var $cx1; var $cy1; var $cx2; var $cy2;
		function __construct($cx1, $cy1, $cx2, $cy2, $x2, $y2) {
			parent::__construct($x2, $y2);		
			$this->cx1 = $cx1;
			$this->cy1 = $cy1;
			$this->cx2 = $cx2;
			$this->cy2 = $cy2; }
		// compute the curve
		function computeXCurveAt($start_x, $t) { 
			$mt = (1-$t);
			return	$mt * $mt * $mt * $start_x + 
					3 * $mt * $mt * $t * ($start_x +$this->cx1) + 
					3 * $mt * $t * $t * ($start_x +$this->cx1+$this->cx2) + 
					$t * $t * $t * ($start_x+$this->cx1+$this->cx2+$this->x2); }
		function computeYCurveAt($start_y, $t) { 
			$mt = (1-$t);
			return	$mt * $mt * $mt * $start_y + 
					3 * $mt * $mt * $t * ($start_y +$this->cy1) + 
					3 * $mt * $t * $t * ($start_y +$this->cy1+$this->cy2) + 
					$t * $t * $t * ($start_y+$this->cy1+$this->cy2+$this->y2); }
		function computeFinalPoint(&$x, &$y, &$bounds) {
			$x += $this->cx1 + $this->cx2 + $this->x2;
			$y += $this->cy1 + $this->cy2 + $this->y2; }
		function compactString() { return "c".$this->cx1." ".$this->cy1." ".
								($this->cx1+$this->cx2)." ".($this->cy1+$this->cy2)." ".
								($this->cx1+$this->cx2+$this->x2)." ".($this->cy1+$this->cy2+$this->y2); }
		function toAbsoluteSVG(&$x, &$y) { 
			$x += $this->cx1;
			$y += $this->cy1;
			$s = "C $x $y ";
			$x += $this->cx2;
			$y += $this->cy2;
			$s .= "$x $y ";
			$x += $this->x2;
			$y += $this->y2;
			return $s . "$x $y"; }
		function toString() { return "'Cubic Curve to' instruction, over control (1) x/y: ".$this->cx1."/".$this->cy1.
							" and control (2) x/y: ".$this->cx2."/".$this->cy2.
							" to x/y: ".$this->x2."/".$this->y2; }}

	/**
	 * Glyphrules are conveniently bundled into a single object, with two simple end-user functions:
	 *
	 *	compactString	will generate a relative coordinate, compact SVG path string.
	 *	toAbsoluteSVG	will generate an absolute coordinate SVG string, relative to the
	 *				x/y coordinate that is put in. To get the same resulting outline
	 * 				as for compactString, simply use 0/0 as coordinate pair.
	 *
	 */
	abstract class GlyphRules {
		var $instructions;
		var $type;	// either GlyphRules::$TYPE2 or GlyphRules::$TRUETYPE
		static $TYPE2 = "type2";
		static $TRUETYPE = "truetype";
		function __construct($type) {
			$this->instructions = array(); 
			$this->type = $type; }
		function addRule($glyphrule) {
			$this->instructions[] = $glyphrule; }
		function addRules($glyphrules) {
			foreach($glyphrules as $glyphrule) {
				$this->instructions[] = $glyphrule; }}
		function getType() { return $this->type; }
		function merge($other) {
			foreach($other->instructions as $instruction) {
				$this->instructions[] = $instruction; }}
		function computeBounds() {
			$rulecount = 1;
			$x=0;
			$y=0;
			$bounds = array("minx"=>999999, "miny"=>999999, "maxx"=>-999999, "maxy"=>-999999);
			foreach($this->instructions as $rule) { $rule->computeBounds($x, $y, $bounds); }
			return $bounds; }
		function compactString() {
			$s = "";
			foreach($this->instructions as $instruction) { $s .= $instruction->compactString() . ""; }
			return str_replace(" -","-",$s); }
		function toAbsoluteSVG($x, $y) {
			$svg = "";
			foreach($this->instructions as $instruction) { $svg .= $instruction->toAbsoluteSVG($x, $y) . " "; }
			return $svg; }}


	// Type 2 outline data
	class Type2GlyphRules extends GlyphRules {
		function __construct() {
			parent::__construct(GlyphRules::$TYPE2); }}

	// TrueType outline data
	class TrueTypeGlyphRules extends GlyphRules {
		function __construct() {
			parent::__construct(GlyphRules::$TRUETYPE); }}
?>
