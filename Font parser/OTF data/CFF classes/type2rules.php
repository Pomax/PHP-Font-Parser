<?php
	//UTF: ☺

	require_once(OTTTFont::$GDlocation . "glyphrules.php");

	/**
	 * This collection of classes mirror the operator/operand sets that can be found in a Type 2 charstring.
	 * Unlike TTF, where you simply walk through the coordinates, Type 2 uses a slightly more elaborate
	 * vector description, so it pays off to keep things structured. A lot of the Type 2 instructions are actually
	 * clever shorthand instructions for line/curve segments for which some of the coordinates are 0. This
	 * means that ultimately all these instructions boil down to only three operations: moveto, line and curve.
	 */
	class Type2Rule
	{
		var $type;
		function __construct($type) { $this->type = $type; }
		function getType() { return $this->type; }
		function toString() { return $this->type . " type 2 rule"; }
		function toGlyphRules() { return array(); }
	}

// ----------------

	// HSTEM [1]: y dy {dya dyb}*
	class HSTEM_optional extends Type2Rule
	{
		var $dya;
		var $dyb;
		function __construct($dya, $dyb) {
			parent::__construct('hstem optional');
			$this->dya = $dya;
			$this->dyb = $dyb; }
		function toString() { return " ".$this->dya . " ". $this->dyb; }
	}
	class HSTEM extends Type2Rule
	{
		var $y;
		var $dy;
		var $optional = array();
		function __construct($y, $dy, $optional) {
			parent::__construct('hstem');
			$this->y = $y;
			$this->dy = $dy;
			foreach($optional as $option) { $this->optional[] = new HSTEM_optional($option['dya'], $option['dyb']); }}
		function toString() {
			$ret = "hstem " . $this->y ." " . $this->dy;
			foreach($this->optional as $option) { $ret .= $option->toString(); }
			return $ret; }
	}


	// VSTEM [3]: x dx {dxa dxb}*
	class VSTEM_optional extends Type2Rule
	{
		var $dxa;
		var $dxb;
		function __construct($dxa, $dxb) {
			parent::__construct('vstem optional');
			$this->dxa = $dxa;
			$this->dxb = $dxb; }
		function toString() { return " " . $this->dxa . " " . $this->dxb; }
	}
	class VSTEM extends Type2Rule
	{
		var $x;
		var $dx;
		var $optional = array();
		function __construct($x, $dx, $optional) {
			parent::__construct('vstem optional');
			$this->x = $x;
			$this->dx = $dx;
			foreach($optional as $option) { $this->optional[] = new HSTEM_optional($option['dxa'], $option['dxb']); }}
		function toString() {
			$ret = "vstem " . $this->x . " " . $this->dx;
			foreach($this->optional as $option) { $ret .= $option->toString(); }
			return $ret; }
	}


	// VMOVETO [4]: dy
	class VMOVETO extends Type2Rule
	{
		var $dy;
		function __construct($dy) {
			parent::__construct('vmoveto');
			$this->dy = $dy; }
		function toString() { return "vmoveto " . $this->dy; }
		function toGlyphRules() { return array(new GlyphVMoveToRule($this->dy)); }
	}


	// RLINETO [5]: {dxa dya}+
	class RLINETO_rule extends Type2Rule
	{
		var $dxa;
		var $dya;
		function __construct($dxa, $dya) {
			parent::__construct('rlineto rule');
			$this->dxa = $dxa;
			$this->dya = $dya; }
		function toString() { return " " . $this->dxa . " " . $this->dya; }
		function toGlyphRules() { return array(new GlyphLineToRule($this->dxa, $this->dya)); }		
	}
	class RLINETO extends Type2Rule
	{
		var $data = array();
		function __construct($data) {
			parent::__construct('rlineto');
			foreach($data as $rule) {
				$this->data[] = new RLINETO_rule($rule['dxa'], $rule['dya']);}}
		function toString() {
			$ret = "rlineto";
			foreach($this->data as $rule) { $ret .= $rule->toString(); }
			return $ret; }
		function toGlyphRules() {
			$ret = array();
			foreach($this->data as $rule) {
				$ret = array_merge($ret, $rule->toGlyphRules()); }
			return $ret; }
	}


	// HLINETO [6]: dx1 {dya dxb}* (odd number of args), {dxa dyb}+ (even number of args)
	class HLINETO_odd_optional extends Type2Rule
	{
		var $dya;
		var $dxb;
		function __construct($dya, $dxb) {
			parent::__construct('hlineto optional (odd)');
			$this->dya = $dya;
			$this->dxb = $dxb; }
		function toString() { return " " . $this->dya . " " . $this->dxb; }
		function toGlyphRules() {
			return array(new GlyphVLineToRule($this->dya),
					new GlyphHLineToRule($this->dxb)); }
	}
	class HLINETO_odd extends Type2Rule
	{
		var $dx1;
		var $optional = array();
		function __construct($dx1, $optional){
			parent::__construct('hlineto (odd)');
			$this->dx1 = $dx1;
			foreach($optional as $option) {
				$this->optional[] = new HLINETO_odd_optional($option['dya'], $option['dxb']); }}
		function toString() {
			$ret = "hlineto " . $this->dx1;
			foreach($this->optional as $option) { $ret .= $option->toString(); }
			return $ret; }
		function toGlyphRules() { 
			$ret = array(new GlyphHLineToRule($this->dx1));
			foreach($this->optional as $option) { $ret = array_merge($ret, $option->toGlyphRules()); }
			return $ret; }
	}

	class HLINETO_even_rule extends Type2Rule
	{
		var $dxa;
		var $dyb;
		function __construct($dxa, $dyb) {
			parent::__construct('hlineto (even) rule');
			$this->dxa = $dxa;
			$this->dyb = $dyb; }
		function toString() { return " " . $this->dxa . " " . $this->dyb; }
		function toGlyphRules() {
			return array(new GlyphHLineToRule($this->dxa),
					new GlyphVLineToRule($this->dyb)); }
	}
	class HLINETO_even extends Type2Rule
	{
		var $data = array();
		function __construct($data) {
			parent::__construct('hlineto (even)');
			foreach($data as $rule) {
				$this->data[] = new HLINETO_even_rule($rule['dxa'], $rule['dyb']);}}
		function toString() {
			$ret = "hlineto";
			foreach($this->data as $rule) { $ret .= $rule->toString(); }
			return $ret; }
		function toGlyphRules() {
			$ret = array();
			foreach($this->data as $rule) { $ret = array_merge($ret, $rule->toGlyphRules()); }
			return $ret; }
	}


	// VLINETO [7]: dy1 {dxa dyb}* (odd number of args), {dya dxb}+ (even number of args)
	class VLINETO_odd_optional extends Type2Rule
	{
		var $dxa;
		var $dyb;
		function __construct($dxa, $dyb) {
			parent::__construct('vlineto (odd) optional');
			$this->dxa = $dxa;
			$this->dyb = $dyb; }
		function toString() { return " " . $this->dxa . " " . $this->dyb; }
		function toGlyphRules() {
			return array(new GlyphHLineToRule($this->dxa),
					new GlyphVLineToRule($this->dyb)); }
	}
	class VLINETO_odd extends Type2Rule
	{
		var $dy1;
		var $optional = array();
		function __construct($dy1, $optional){
			parent::__construct('vlineto (odd)');
			$this->dy1 = $dy1;
			foreach($optional as $option) {
				$this->optional[] = new VLINETO_odd_optional($option['dxa'], $option['dyb']); }}
		function toString() {
			$ret = "vlineto " . $this->dy1;
			foreach($this->optional as $option) { $ret .= $option->toString(); }
			return $ret; }
		function toGlyphRules() {
			$ret = array(new GlyphVLineToRule($this->dy1));
			foreach($this->optional as $option) { $ret = array_merge($ret, $option->toGlyphRules()); }
			return $ret; }
	}
	class VLINETO_even_rule extends Type2Rule
	{
		var $dya;
		var $dxb;
		function __construct($dya, $dxb) {
			parent::__construct('vlineto (even) rule');
			$this->dya = $dya;
			$this->dxb = $dxb; }
		function toString() { return " " . $this->dya . " " . $this->dxb; }
		function toGlyphRules() {
			return array(new GlyphVLineToRule($this->dya),
					new GlyphHLineToRule($this->dxb)); }
	}
	class VLINETO_even extends Type2Rule
	{
		var $data = array();
		function __construct($data) {
			parent::__construct('vlineto (even)');
			foreach($data as $rule) {
				$this->data[] = new VLINETO_even_rule($rule['dya'], $rule['dxb']);}}
		function toString() {
			$ret = "vlineto";
			foreach($this->data as $rule) { $ret .= $rule->toString(); }
			return $ret; }
		function toGlyphRules() {
			$ret = array();
			foreach($this->data as $rule) { $ret = array_merge($ret, $rule->toGlyphRules()); }
			return $ret; }
	}


	class CURVETO_rule extends Type2Rule
	{
		var $dxa;
		var $dya;
		var $dxb;
		var $dyb;
		var $dxc;
		var $dyc;
		function __construct($dxa, $dya, $dxb, $dyb, $dxc, $dyc) {
			parent::__construct('curveto rule');
			$this->dxa = $dxa;
			$this->dya = $dya;
			$this->dxb = $dxb;
			$this->dyb = $dyb;
			$this->dxc = $dxc;
			$this->dyc = $dyc; }
		function setdxc($dxc) { $this->dxc = $dxc; }
		function setdyc($dyc) { $this->dyc = $dyc; }
		function toString() { return " " . $this->dxa . " " . $this->dya . " " . $this->dxb . " " . $this->dyb . " " . $this->dxc . " " . $this->dyc; }
		function toGlyphRules() { return array(new GlyphCubeCurveToRule($this->dxa, $this->dya, $this->dxb, $this->dyb, $this->dxc, $this->dyc)); }
	}

	// RRCURVETO [8]: {dxa dya dxb dyb dxc dyc}+
	class RRCURVETO extends Type2Rule
	{
		var $data = array();
		function __construct($data) {
			parent::__construct('rrcurveto');
			foreach($data as $rule) {
				$this->data[] = new CURVETO_rule($rule['dxa'], $rule['dya'],$rule['dxb'], $rule['dyb'],$rule['dxc'], $rule['dyc']);}}
		function toString() {
			$ret = "rrcurveto";
			foreach($this->data as $rule) { $ret .= $rule->toString(); }
			return $ret; }
		function toGlyphRules() {
			$ret = array();
			foreach($this->data as $rule) { $ret = array_merge($ret, $rule->toGlyphRules()); }
			return $ret; }
	}


	// ENDCHAR [14]
	class ENDCHAR extends Type2Rule
	{
		function __construct() { parent::__construct('end of character'); }
		function toString() { return "end of character"; }
	}


	// HSTEMHM [18]: y dy {dya dyb}*
	class HSTEMHM_optional extends Type2Rule
	{
		var $dya;
		var $dyb;
		function __construct($dya, $dyb) {
			parent::__construct('hstemhm optional');
			$this->dya = $dya;
			$this->dyb = $dyb; }
		function toString() { return " ".$this->dya . " ". $this->dyb; }
	}
	class HSTEMHM extends Type2Rule
	{
		var $y;
		var $dy;
		var $optional = array();
		function __construct($y, $dy, $optional) {
			parent::__construct('hstemhm');
			$this->y = $y;
			$this->dy = $dy;
			foreach($optional as $option) { $this->optional[] = new HSTEMHM_optional($option['dya'], $option['dyb']); }}
		function toString() {
			$ret = "hstemhm " . $this->y ." " . $this->dy;
			foreach($this->optional as $option) { $ret .= $option->toString(); }
			return $ret; }
	}


	// HINTMASK [19]: n byte mask (n equal to number of stems defined at the start of the charstring)
	class HINTMASK extends Type2Rule
	{
		var $mask;
		function __construct($mask) {
			parent::__construct('hintmask');
			$this->mask = $mask; }
		function toString() { 
			$mask = '';
			foreach($this->mask as $byte) { $mask .= decbin($byte); }
			return "hintmask $mask"; }
	}


	// CNTRMASK [20]: n byte mask (n equal to number of stems defined at the start of the charstring)
	class CNTRMASK extends Type2Rule
	{
		var $mask;
		function __construct($mask) {
			parent::__construct('cntrmask');
			$this->mask = $mask; }
		function toString() { 
			$mask = '';
			foreach($this->mask as $byte) { $mask .= decbin($byte); }
			return "cntrmask $mask"; }
	}


	// RMOVETO [21]: x y
	class RMOVETO extends Type2Rule
	{
		var $dx;
		var $dy;
		function __construct($dx, $dy) {
			parent::__construct('rmoveto');
			$this->dx = $dx;
			$this->dy = $dy; }
		function toString() { return "rmoveto " . $this->dx . " ". $this->dy; }
		function toGlyphRules() { return array(new GlyphMoveToRule($this->dx, $this->dy)); }
	}


	// HMOVETO [22]: x
	class HMOVETO extends Type2Rule
	{
		var $dx;
		function __construct($dx) {
			parent::__construct('hmoveto');
			$this->dx = $dx; }
		function toString() { return "hmoveto ".$this->dx; }
		function toGlyphRules() { return array(new GlyphHMoveToRule($this->dx)); }
	}


	// VSTEMHM [23]: x dx {dxa dxb}*
	class VSTEMHM_optional extends Type2Rule
	{
		var $dxa;
		var $dxb;
		function __construct($dxa, $dxb) {
			parent::__construct('vstemhm optional');
			$this->dxa = $dxa;
			$this->dxb = $dxb; }
		function toString() { return " ".$this->dxa . " ". $this->dxb; }
	}
	class VSTEMHM extends Type2Rule
	{
		var $x;
		var $dx;
		var $optional = array();
		function __construct($x, $dx, $optional) {
			parent::__construct('vstemhm');
			$this->x = $x;
			$this->dx = $dx;
			foreach($optional as $option) { $this->optional[] = new VSTEMHM_optional($option['dxa'], $option['dxb']); }}
		function toString() {
			$ret = "vstemhm " . $this->x ." " . $this->dx;
			foreach($this->optional as $option) { $ret .= $option->toString(); }
			return $ret; }
	}


	// RCURVELINE [24]:  {dxa dya dxb dyb dxc dyc}+ dxd dyd
	class RCURVELINE extends Type2Rule
	{
		var $curves;
		var $line;
		function __construct($data, $dxd, $dyd) {
			parent::__construct('rcurveline');
			$this->curves = new RRCURVETO($data);
			$this->line = new RLINETO_rule($dxd, $dyd); }
		function toString() { return "rcurveline: " . $this->curves->toString() . " and " . $this->line->toString(); }
		function toGlyphRules() {
			$ret = $this->curves->toGlyphRules();
			$ret = array_merge($ret, $this->line->toGlyphRules());
			return $ret; }
	}


	// RLINECURVE [25]: {dxa dya}+ dxb dyb dxc dyc dxd dyd
	class RLINECURVE extends Type2Rule
	{
		var $lines;
		var $curve;
		function __construct($data, $dxb, $dyb, $dxc, $dyc, $dxd, $dyd) {
			parent::__construct('rlinecurve');
			$this->lines = new RLINETO($data);
			$this->curve = new CURVETO_rule($dxb, $dyb, $dxc, $dyc, $dxd, $dyd); }
		function toString() { return "rlinecurve: " . $lines->toString() . " and " . $curve->toString(); }
		function toGlyphRules() {
			$ret = $this->lines->toGlyphRules();
			$ret = array_merge($ret, $this->curve->toGlyphRules());
			return $ret; }
	}


	// VVCURVETO [26]: dx1? {dya dxb dyb dyc}+
	class VVCURVETO_rule extends CURVETO_rule { function __construct($dya, $dxb, $dyb, $dyc) { parent::__construct(0, $dya, $dxb, $dyb, 0, $dyc); }}
	class VVCURVETO extends Type2Rule
	{
		var $dx1;
		var $data = array();
		function __construct($dx1, $data) {
			parent::__construct('vvcurveto');
			$this->dx1 = $dx1;
			foreach($data as $rule) {
				$this->data[] = new VVCURVETO_rule($rule['dya'], $rule['dxb'], $rule['dyb'], $rule['dyc']);}
			if($dx1!='no' && count($this->data)>0) { $this->data[0]->dxa = $dx1; }}				
		function toString() {
			$ret = "vvcurveto";
			if($this->dx1!='no') { $ret .= " " .$this->dx1; }
			foreach($this->data as $rule) { $ret .= $rule->toString(); }
			return $ret; }
		function toGlyphRules() {
			$ret = array();
			foreach($this->data as $rule) { $ret = array_merge($ret, $rule->toGlyphRules()); }
			return $ret; }
	}


	// HHCURVETO [27]: dy1? {dxa dxb dyb dxc}+
	class HHCURVETO_rule extends CURVETO_rule { function __construct($dxa, $dxb, $dyb, $dxc) { parent::__construct($dxa, 0, $dxb, $dyb, $dxc, 0); }}
	class HHCURVETO extends Type2Rule
	{
		var $dy1;
		var $data = array();
		function __construct($dy1, $data) {
			parent::__construct('hhcurveto');
			$this->dy1 = $dy1;
			foreach($data as $rule) {
				$this->data[] = new HHCURVETO_rule($rule['dxa'], $rule['dxb'], $rule['dyb'], $rule['dxc']); }
			if($dy1!='no' && count($this->data)>0) { $this->data[0]->dya = $dy1; }}
		function toString() {
			$ret = "hhcurveto";
			if($this->dy1!='no') { $ret .= " " .$this->dy1; }
			foreach($this->data as $rule) { $ret .= $rule->toString(); }
			return $ret; }
		function toGlyphRules() {
			$ret = array();
			foreach($this->data as $rule) { $ret = array_merge($ret, $rule->toGlyphRules()); }
			return $ret; }
	}


	class VHCURVETO_rule extends Type2Rule
	{
		var $vcurve;
		var $hcurve;
		function __construct($dya, $dxb, $dyb, $dxc, $dxd, $dxe, $dye, $dyf) {
			parent::__construct('hvcurveto (long) optional');
			$this->vcurve = new CURVETO_rule(0, $dya, $dxb, $dyb, $dxc, 0);
			$this->hcurve = new CURVETO_rule($dxd, 0, $dxe, $dye, 0, $dyf); }
		function setdxf($dxf) { $this->hcurve->setdxc($dxf); }
		function toString() { return " ".$this->hcurve->toString() . " and " . $this->vcurve->toString(); }
		function toGlyphRules() {
			$ret = array();
			$ret = array_merge($ret, $this->vcurve->toGlyphRules());
			$ret = array_merge($ret, $this->hcurve->toGlyphRules());
			return $ret; }
	}

	class HVCURVETO_rule extends Type2Rule
	{
		var $hcurve;
		var $vcurve;
		function __construct($dxa, $dxb, $dyb, $dyc, $dyd, $dxe, $dye, $dxf) {
			parent::__construct('hvcurveto (short) rule');
			$this->hcurve = new CURVETO_rule($dxa, 0, $dxb, $dyb, 0, $dyc);
			$this->vcurve = new CURVETO_rule(0, $dyd, $dxe, $dye, $dxf, 0); }
		function setdyf($dyf) { $this->vcurve->setdyc($dyf); }
		function toString() { return " ". $this->hcurve->toString() . " ".$this->vcurve->toString(); }
		function toGlyphRules() {
			$ret = array();
			$ret = array_merge($ret, $this->hcurve->toGlyphRules());
			$ret = array_merge($ret, $this->vcurve->toGlyphRules());
			return $ret; }
	}


	// VHCURVETO [30]:	dy1 dx2 dy2 dx3 {dxa dxb dyb dyc dyd dxe dye dxf}* dyf?
	//				or {dya dxb dyb dxc dxd dxe dye dyf}+ dxf?
	class VHCURVETO_long extends Type2Rule
	{
		var $vcurve;
		var $optional= array();
		var $dyf;
		function __construct($dy1, $dx2, $dy2, $dx3, $optional, $dyf) {
			parent::__construct('vhcurveto (long)');
			$this->vcurve = new CURVETO_rule(0, $dy1, $dx2, $dy2, $dx3, 0);
			foreach($optional as $option) {
				$this->optional[] = new HVCURVETO_rule($option['dxa'], $option['dxb'], $option['dyb'], $option['dyc'], $option['dyd'], $option['dxe'], $option['dye'], $option['dxf']); }
			$this->dyf = $dyf;
			if($dyf!='no') {
				if(count($this->optional)>0) { $this->optional[count($this->optional)-1]->setdyf($dyf); }
				else { $this->vcurve->setdyc($dyf); }}}
		function toString() {
			$ret = "vhcurveto (long) " . $this->vcurve->toString();
			foreach($this->optional as $option) { $ret .= " " . $option->toString(); }
			if($this->dyf!='no') { $ret .= " " . $this->dyf; }
			return $ret; }
		function toGlyphRules() {
			$ret = array();
			$ret = array_merge($ret, $this->vcurve->toGlyphRules());
			foreach($this->optional as $rule) { $ret = array_merge($ret, $rule->toGlyphRules()); }
			return $ret; }
	}
	//		{dya dxb dyb dxc dxd dxe dye dyf}+ dxf?
	class VHCURVETO_short extends Type2Rule
	{
		var $data = array();
		var $dxf = 'no';
		function __construct($data, $dxf) {
			parent::__construct('vhcurveto (short)');
			foreach($data as $rule) {
				$this->data[] = new VHCURVETO_rule($rule['dya'], $rule['dxb'], $rule['dyb'], $rule['dxc'], $rule['dxd'], $rule['dxe'], $rule['dye'], $rule['dyf']);}
			$this->dxf = $dxf;
			if($dxf!='no' && count($this->data)>0) { $this->data[count($this->data)-1]->setdxf($dxf); }}
		function toString() {
			$ret = "vhcurveto (short)";
			foreach($this->data as $rule) { $ret .= $rule->toString(); }
			if($this->dxf!='no') { $ret .= " with dxf " . $this->dxf; }
			return $ret; }
		function toGlyphRules() {
			$ret = array();
			foreach($this->data as $rule) { $ret = array_merge($ret, $rule->toGlyphRules()); }
			return $ret; }
	}


	// HVCURVETO [31]:	dx1 dx2 dy2 dy3 {dya dxb dyb dxc dxd dxe dye dyf}* dxf?
	//				or {dxa dxb dyb dyc dyd dxe dye dxf}+ dyf?
	class HVCURVETO_long extends Type2Rule
	{
		var $hcurve;
		var $optional = array();
		var $dxf;
		function __construct($dx1, $dx2, $dy2, $dy3, $optional, $dxf) {
			parent::__construct('hvcurveto (long)');
			$this->hcurve = new CURVETO_rule($dx1, 0, $dx2, $dy2, 0, $dy3);
			foreach($optional as $option) {
				$opt = new VHCURVETO_rule($option['dya'], $option['dxb'], $option['dyb'], $option['dxc'], $option['dxd'], $option['dxe'], $option['dye'], $option['dyf']);
				$this->optional[] = $opt; }
			$this->dxf = $dxf;
			if($dxf!='no') {
				if(count($this->optional)>0) { $this->optional[count($this->optional)-1]->setdxf($dxf); }
				else { $this->hcurve->setdxc($dxf); }}}
		function toString() {
			$ret = "hvcurveto (long) " . $this->hcurve->toString(). " ";
			foreach($this->optional as $option) { $ret .= $option->toString(); }
			if($this->dxf!='no') { $ret .= " " . $this->dxf; }
			return $ret; }
		function toGlyphRules() {
			$ret = array();
			$ret = array_merge($ret, $this->hcurve->toGlyphRules());
			foreach($this->optional as $rule) { $ret = array_merge($ret, $rule->toGlyphRules()); }
			return $ret; }
	}
	//		{dxa dxb dyb dyc dyd dxe dye dxf}+ dyf?
	class HVCURVETO_short extends Type2Rule
	{
		var $data = array();
		var $dyf;
		function __construct($data, $dyf) {
			parent::__construct('hvcurveto (short)');
			foreach($data as $rule) {
				$this->data[] = new HVCURVETO_rule($rule['dxa'], $rule['dxb'], $rule['dyb'], $rule['dyc'], $rule['dyd'], $rule['dxe'], $rule['dye'], $rule['dxf']);}
			$this->dyf = $dyf;
			if($dyf!='no' && count($this->data)>0) { $this->data[count($this->data)-1]->setdyf($dyf); }}
		function toString() {
			$ret = "hvcurveto (short)";
			foreach($this->data as $rule) { $ret .= " " . $rule->toString(); }
			if($this->dyf!='no') { $ret .= " " . $this->dyf; }
			return $ret; }
		function toGlyphRules() {
			$ret = array();
			foreach($this->data as $rule) { $ret = array_merge($ret, $rule->toGlyphRules()); }
			return $ret; }
	}

// ==========================================================================================
//  While an interesting idea, flex hints kind of border on the whole "if you need them, do a better design job"
// ==========================================================================================

	// HLFEX [12 34]: dx1 dx2 dy2 dx3 dx4 dx5 dx6
	class HFLEX extends Type2Rule
	{
		var $dx1;	var $dx2;	var $dy2;	var $dx3;	var $dx4;	var $dx5;	var $dx6;
		function __construct($dx1, $dx2, $dy2, $dx3, $dx4, $dx5, $dx6) {
			parent::__construct("hflex");
			$this->dx1 = $dx1;        $this->dx2 = $dx2;        $this->dx3 = $dx3;
		        $this->dx4 = $dx4;        $this->dx5 = $dx5;        $this->dx6 = $dx6;
		        $this->dy2 = $dy2; }
		function toString() { return " ". $this->dx1. " ".$this->dx2 . " ". $this->dy2 . " ". $this->dx3 . " ". $this->dx4 . " ".$this->dx5 . " ". $this->dx6; }
	}


	// FLEX [12 35]: dx1 dy1 dx2 dy2 dx3 dy3 dx4 dy4 dx5 dy5 dx6 dy6 fd
	class FLEX extends Type2Rule
	{
		var $dx1;	var $dy1;	var $dx2;	var $dy2;	var $dx3;	var $dy3;
		var $dx4;	var $dy4;	var $dx5;	var $dy5;	var $dx6;	var $dy6;
		var $fd;
		function __construct($dx1, $dy1, $dx2, $dy2, $dx3, $dy3, $dx4, $dy4, $dx5, $dy5, $dx6, $dy6, $fd) {
			parent::__construct("flex");
			$this->dx1 = $dx1;	$this->dy1 = $dy1;
		        $this->dx2 = $dx2;        $this->dy2 = $dy2;
		        $this->dx3 = $dx3;        $this->dy3 = $dy3;
		        $this->dx4 = $dx4;        $this->dy4 = $dy4;
		        $this->dx5 = $dx5;        $this->dy5 = $dy5;
		        $this->dx6 = $dx6;        $this->dy6 = $dy6;
		        $this->fd = $fd; }
		function toString() {
			return $this->dx1 . " " . $this->dy1 . " " . $this->dx2 . " " . $this->dy2 . " " . $this->dx3 . " " . $this->dy3 . " " . 
					$this->dx4 . " " . $this->dy4 . " " . $this->dx5 . " " . $this->dy5 . " " . $this->dx6 . " " . $this->dy6 . " " . $this->fd; }
	}

	// HFLEX1 [12 36]: dx1 dy1 dx2 dy2 dx3 dx4 dx5 dy5 dx6
	class HFLEX1 extends Type2Rule
	{
		var $dx1;	var $dy1;
		var $dx2;	var $dy2;
		var $dx3;	var $dx4;
		var $dx5;	var $dy5;
		var $dx6;
		function __construct($dx1, $dy1, $dx2, $dy2, $dx3, $dx4, $dx5, $dy5, $dx6) {
			parent::__construct("hflex1");
			$this->dx1 = $dx1;	$this->dy1 = $dy1;
		        $this->dx2 = $dx2;        $this->dy2 = $dy2;
		        $this->dx3 = $dx3;        $this->dx4 = $dx4;
		        $this->dx5 = $dx5;        $this->dy5 = $dy5;
		        $this->dx6 = $dx6; }
		function toString() {
			return " ". $this->dx1. " ". $this->dy1." ".$this->dx2 . " ". $this->dy2 . " ". $this->dx3 . " ".
					$this->dx4 . " ".$this->dx5 . " " . $this->dy5 . " ". $this->dx6; }
	}


	// FLEX1 [12 37]: dx1 dy1 dx2 dy2 dx3 dy3 dx4 dy4 dx5 dy5 d6
	class FLEX1 extends Type2Rule
	{
		var $dx1;	var $dy1;
		var $dx2;	var $dy2;
		var $dx3;	var $dy3;
		var $dx4;	var $dy4;
		var $dx5;	var $dy5;
		var $d6;
		function __construct($dx1, $dy1, $dx2, $dy2, $dx3, $dy3, $dx4, $dy4, $dx5, $dy5, $d6) {
			parent::__construct("flex1");
			$this->dx1 = $dx1;	$this->dy1 = $dy1;
		        $this->dx2 = $dx2;        $this->dy2 = $dy2;
		        $this->dx3 = $dx3;        $this->dy3 = $dy3;
		        $this->dx4 = $dx4;        $this->dy4 = $dy4;
		        $this->dx5 = $dx5;        $this->dy5 = $dy5;
		        $this->d6 = $d6; }
		function toString() {
			return $this->dx1 . " " . $this->dy1 . " " . $this->dx2 . " " . $this->dy2 . " " . $this->dx3 . " " . $this->dy3 . " " . 
				$this->dx4 . " " . $this->dy4 . " " . $this->dx5 . " " . $this->dy5 . " " . $this->d6; }
	}
?>
