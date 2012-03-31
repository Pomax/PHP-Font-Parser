<?php
	//UTF: ☺

	require_once(OTTTFont::$CFFlocation . "type2rules.php");

	/**
	 * This class describes a glyph with outline data described using a
	 * Type 2 charstring. Type 2 charstrings allow two things: outline data,
	 * using a great number of shorthand instructions, and PostScript
	 * programs. Mostly because I didn't want to get bogged down with
	 * debugging PostScript parsing, I made the decision to simply not
	 * support the PostScript sections of a Type 2 charstring. If you run
	 * into any fonts for which this is essential, though, it can always be
	 * implemented.
	 */
	class Type2GlyphData
	{
		var $debug = false;

		var $xUitsPerEm = 1000;	// indicates how to interpret the coordinates relative to the glyph's quad
		var $yUnitsPerEm = 1000;	// indicates how to interpret the coordinates relative to the glyph's quad

		var $width = 1000;
		var $height = 1000;

		var $differenceWithNominalWidthX = 0;	// indicates this charater's width difference with respect ot the font's nominalWidthX value

		var $pointer = 0;
		var $charstring = "";
		var $stack = array();			// max size = 48
		var $transient_stack = array();	// max size = 32

		var $hstems = 0;				// number of horizontal stems
		var $vstems = 0;				// number of horizontal stems

		var $fontdict;				// used for getting to the local subroutines
		var $GlobalSubroutines;		// used for getting to the global subroutines
		var $GlobalSubroutineBias = '-';	// the bias for the global subroutine table (see CFF docs, section 16, for explanation)

		var $glyphdata = array();		// very important - holds the final data. In practice, $glyphdata = GlyphRules object

		function __construct(&$fh, $charstring, &$fontdict, &$global_subroutines)
		{
			$this->charstring = $charstring;
			$this->fontdict = $fontdict;
			$this->GlobalSubroutines = $global_subroutines;
			$this->parse_type2_charstring($fh);
			// now turn $glyphdata from type Type2Rule[] into GlyphRule[]
			$glyphrules = new Type2GlyphRules();
			foreach($this->glyphdata as $t2rule) {
        foreach($t2rule->toGlyphRules() as $rule) {
          $glyphrules->addRule($rule); }}
			$this->glyphdata = $glyphrules;
		}

		// vars used for the parse run.
		var $hinting_mode = "HINTING";
		var $outline_mode = "OUTLINE";
		var $parse_mode = "HINTING";

// --------------------------------------

		/*

		   run through the charstring byte for byte, and decode based on the explanation in Adobe's technical note #5177 ("The Type 2 Charstring Format")

			value		meaning											range							bytes in the full code

			0 – 11	operators 											0 to 11						1
			12		escape: next byte interpreted as additional operators				additional 0 to 255 for operator codes	2
			13 – 18	operators											13 to 18						1
			19, 20	operators (hintmask and cntrmask)	operators					19, 20						2 or more
			21 – 27	operators 											21 to 27						1
			28		next 2 bytes interpreted as a 16-bit two’s complement number		–32768 to +32767				3
			29 – 31	operators											29 to 31						1
			32 – 246	number - result = v–139								–107 to +107					1
			247 – 250	number - with next byte, w, result = (v–247)*256+w+108			+108 to +1131					2
			251 – 254	number - with next byte, w, result = –[(v–251)*256]–w–108.		–108 to –1131					2
			255		number - next 4 bytes interpreted as a 32-bit two’s-complement
					(16-bit signed integer with 16 bits of fraction.)												5

		 */

		function try_numeral($byte)
		{
			// signed short
			if($byte==28)
			{
				$b0 = $this->read_charstring_byte();
				$b1 = $this->read_charstring_byte();
				$num = 0x0100 * $b0  + $b1;
				$this->enqueue($num);
//				echo "short: $num\n";
				return true;
			}
			// single byte number
			elseif(32<=$byte && $byte<=246)
			{
				$num = $byte - 139;
				$this->enqueue($num);
//				echo "1 byte integer: $num\n";
				return true;
			}
			// double byte integer (positive)
			elseif(247<=$byte && $byte<=250)
			{
				$b2 = $this->read_charstring_byte();
				$num = ($byte - 247) * 256 + $b2 + 108;
				$this->enqueue($num);
//				echo "2 byte integer (pos): $num\n";
				return true;
			}
			// double byte integer (negative)
			elseif(251<=$byte && $byte<=254)
			{
				$b2 = $this->read_charstring_byte();
				$num = -(($byte - 251)*256) - ($b2 + 108);
				$this->enqueue($num);
//				echo "2 byte integer (neg): $num\n";
				return true;
			}
			// four byte float (short . ushort)
			elseif($byte==255)
			{
        $pattern2c = 0x01000000 * $this->read_charstring_byte() +
                      0x010000 * $this->read_charstring_byte() +
                        0x0100 * $this->read_charstring_byte() +
                                $this->read_charstring_byte();
        $pattern = (~$pattern2c + 1) & 0xFFFFFFFF;
        $ushort = $pattern & 0xFFFF;
        $short = $pattern >> 16;
        if($short >= 32768) { $short -= 65536; }
        $num = - ($short + round($ushort / 65536,3));
        $this->enqueue($num);
//        echo "4 byte float: $num\n";
        return true;
			}
			return false;
		}

		/**
		 * A charstring has the form
		 *
		 *	w? {hs* vs* cm* hm* mt subpath}? {mt subpath}* endchar
		 *
		 * This means that there may be a difference-with-nominal-width indicator,
		 * but this cannot be ascertained until the first moveto command is found,
		 * signaling the start of the actual path.
		 *
		 */
		function parse_type2_charstring(&$fh)
		{
			$this->transient_stack = array();
			if($this->debug) echo "starting charstring parse - position " . $this->pointer . ", charstring " . $this->charstring_as_bytes($this->charstring) . "\n";
			while($this->pointer < strlen($this->charstring))
			{
				$byte = $this->read_charstring_byte();
				if(!$this->try_numeral($byte))
				{
					if($this->debug) echo "(opcode [$byte]) ";
					switch($byte)
					{
						// HSTEM: y dy {dya dyb}*
						case(1) : {
							if($this->debug) echo "hstem operator - stack: {" . (implode(',',$this->stack)) . "}\n";
							// how many arguments should we read, given that we should only read pairs?
							$argnum = count($this->stack) - count($this->stack)%2;
							$args = $this->get_operands($argnum);
							$y = $args[0];
							$dy = $args[1];
							$optional = array();
							for($a=2; $a<count($args);$a+=2) {
								$dya = $args[$a];
								$dyb = $args[$a+1];
								$optional[] = array('dya'=>$dya, 'dyb'=>$dyb); }
							// do something with coordinate list
							if($this->debug) echo "hstem ".$this->array_collapse($optional)."\n\n";
							$this->add_rule(new HSTEM($y, $dy, $optional));
							$this->hstems = 1 + count($optional);
							break; }

						// VSTEM: x dx {dxa dxb}*
						case(3) : {
							if($this->debug) echo "vstem operator - stack: {" . (implode(',',$this->stack)) . "}\n";
							// how many arguments should we read, given that we should only read pairs?
							$argnum = count($this->stack) - count($this->stack)%2;
							$args = $this->get_operands($argnum);
							$x = $args[0];
							$dx = $args[1];
							$optional = array();
							for($a=2; $a<count($args);$a+=2) {
								$dxa = $args[$a];
								$dxb = $args[$a+1];
								$optional[] = array('dxa'=>$dxa, 'dxb'=>$dxb); }
							// do something with coordinate list
							if($this->debug) echo "vstem ".$this->array_collapse($optional)."\n\n";
							$this->add_rule(new VSTEM($x, $dx, $optional));
							$this->vstems = 1 + count($optional);
							break; }

						// VMOVETO: dy
						case(4) : {
							// if we are still in hinting mode, we can now tell whether or not there's a "width" operand left on the stack
							if($this->parse_mode==$this->hinting_mode) { $this->check_for_width(1); }
							if($this->debug) echo "vmoveto operator - stack: {" . (implode(',',$this->stack)) . "}\n";
							$dy = $this->get_head();
							if($this->debug) echo "vmoveto ($dy)\n\n";
							$this->add_rule(new VMOVETO($dy));
							break; }

						// RLINETO: {dxa dya}+
						case(5) : {
							if($this->debug) echo "rlineto operator - stack: {" . (implode(',',$this->stack)) . "}\n";
							$data = array();
							$dxa = $this->get_head();
							$dya = $this->get_head();
							$data[] = array("dxa"=>$dxa, "dya"=>$dya);
							while(count($this->stack)>=2) {
								$dxa = $this->get_head();
								$dya = $this->get_head();
								$data[] = array("dxa"=>$dxa, "dya"=>$dya); }
							// do something with coordinate list
							if($this->debug) echo "rlineto ".$this->array_collapse($data)."\n\n";
							$this->add_rule(new RLINETO($data));
							break; }

						// HLINETO: dx1 {dya dxb}* (odd number of args), {dxa dyb}+ (even number of args)
						case(6) : {
							if($this->debug) echo "hlineto operator - stack: {" . (implode(',',$this->stack)) . "}\n";
							if(count($this->stack)%2==1) {
								$optional = array();
								$dx1 = $this->get_head();
								while(count($this->stack)>=2) {
									$dya = $this->get_head();
									$dxb = $this->get_head();
									$optional[] = array('dya'=>$dya, 'dxb'=>$dxb); }
								if($this->debug) echo "hlineto (dx1=$dx1". (count($optional)>0 ? ', ' . $this->array_collapse($optional) : '') . "))\n\n";
								$this->add_rule(new HLINETO_odd($dx1, $optional));
							} else {
								$data = array();
								while(count($this->stack)>=2) {
									$dxa = $this->get_head();
									$dyb = $this->get_head();
									$data [] = array('dxa'=>$dxa, 'dyb'=>$dyb); }
								if($this->debug) echo "hlineto ".$this->array_collapse($data )."\n\n";
								$this->add_rule(new HLINETO_even($data));
							} break; }

						// VLINETO: dy1 {dxa dyb}* (odd number of args), {dya dxb}+ (even number of args)
						case(7) : {
							if($this->debug) echo "vlineto operator - stack: {" . (implode(',',$this->stack)) . "}\n";
							if(count($this->stack)%2==1) {
								$optional = array();
								$dy1 = $this->get_head();
								while(count($this->stack)>=2) {
									$dxa = $this->get_head();
									$dyb = $this->get_head();
									$optional[] = array('dxa'=>$dxa, 'dyb'=>$dyb); }
								if($this->debug) echo "vlineto (dy1=$dy1". (count($optional)>0 ? ', ' . $this->array_collapse($optional) : '') . ")\n\n";
								$this->add_rule(new VLINETO_odd($dy1, $optional));
							} else {
								$data = array();
								while(count($this->stack)>=2) {
									$dya = $this->get_head();
									$dxb = $this->get_head();
									$data[] = array('dya'=>$dya, 'dxb'=>$dxb); }
								if($this->debug) echo "vlineto ".$this->array_collapse($data)."\n\n";
								$this->add_rule(new VLINETO_even($data));
							} break; }

						// RRCURVETO: {dxa dya dxb dyb dxc dyc}+
						case(8) : {
							if($this->debug) echo "rrcurveto operator - stack: {" . (implode(',',$this->stack)) . "}\n";
							$data = array();
							while(count($this->stack)>=6) {
								$dxa = $this->get_head();
								$dya = $this->get_head();
								$dxb = $this->get_head();
								$dyb = $this->get_head();
								$dxc = $this->get_head();
								$dyc = $this->get_head();
								$data[] = array('dxa'=>$dxa, 'dya'=>$dya, 'dxb'=>$dxb, 'dyb'=>$dyb, 'dxc'=>$dxc, 'dyc'=>$dyc); }
							// do something with coordinate list
							if($this->debug) echo "rrcurveto (".$this->array_collapse($data).")\n\n";
							$this->add_rule(new RRCURVETO($data));
							break; }

						// call local subroutine
						case(10) : {
//							if($this->debug) echo "calling local subroutine - stack: {" . (implode(',',$this->stack)) . "}\n";
							$index = $this->get_tail();
							$subr_data = $this->fontdict->get_subroutine($fh, $index);
							$this->insert_subroutine_charstring($subr_data);
							$bias = $this->fontdict->privatedict->Subroutinesbias;
//							if($this->debug) echo "local subroutine ".($index+$bias)." called, charstring (".$this->charstring_as_bytes($subr_data).") inserted for further processing\n\n";
							break; }

						// return from subroutine
						case(11) : {
//							if($this->debug) echo "return from subroutine\n\n";
							break; }

						// two byte opcode
						case(12) : {
							$opcode = $this->read_charstring_byte();
							if($this->debug) echo "(opcode [$byte]) ";
							switch($opcode)
							{
								// AND (bitwise)
								case(3) : {
									$val = (($this->get_head() & $this->get_head()) != 0);
									if($val) { $this->enqueue(1); } else { $this->enqueue(0); }
									if($this->debug) echo "AND\n";
									break; }

								// OR (bitwise)
								case(4) : {
									$val = (($this->get_head() | $this->get_head()) != 0);
									if($val) { $this->enqueue(1); } else { $this->enqueue(0); }
									if($this->debug) echo "OR\n";
									break; }

								// NOT (bitwise)
								case(5) : {
									$val = ($this->get_head() == 0);
									if($val) { $this->enqueue(1); } else { $this->enqueue(0); }
									if($this->debug) echo "NOT\n";
									break; }

								// absolute value
								case(9) : {
									$val = abs($this->get_head());
									$this->enqueue($val);
									if($this->debug) echo "abs\n";
									break; }

								// add
								case(10) : {
									$val = $this->get_head() + $this->get_head();
									$this->enqueue($val);
									if($this->debug) echo "add\n";
									break; }

								// subtract
								case(11) : {
									$val = $this->get_head() - $this->get_head();
									$this->enqueue($val);
									if($this->debug) echo "sub\n";
									break; }

								// divide
								case(12) : {
									$val = $this->get_head() / $this->get_head();
									$this->enqueue($val);
									if($this->debug) echo "div\n";
									break; }

								// negate
								case(14) : {
									$val = -$this->get_head();
									$this->enqueue($val);
									if($this->debug) echo "neg\n";
									break; }

								// EQ (bitwise)
								case(15) : {
									$val = ($this->get_head() == $this->get_head());
									if($val) { $this->enqueue(1); } else { $this->enqueue(0); }
									if($this->debug) echo "EQ\n";
									break; }

								// drop (why? O_o)
								case(18) : {
									$this->get_head();
									if($this->debug) echo "drop\n";
									break; }

								// set(index) on transient stack
								case(20) : {
									$val = $this->get_head();
									$i = $this->get_head();
									$this->transient_stack[$i] = $val;
									if($this->debug) echo "put\n";
									break; }

								// get(i) from transient stack
								case(21) : {
									$i = $this->get_head();
									$this->enqueue($this->transient_stack[$i]);
									if($this->debug) echo "get\n";
									break; }

								// ifelse (bitwise)
								case(22) : {
									echo "ifelse\n";
									echo "NOT IMPLEMENTED\n";
									break; }

								// random
								case(23) : {
									$rand=0;
									while($rand==0) { $rand = rand(0,1); }	// Type2 only allows [0,1)
									$this->enqueue($rand);
									if($this->debug) echo "random\n";
									break; }

								// multiply
								case(24) : {
									$val = $this->get_head() * $this->get_head();
									$this->enqueue($val);
									if($this->debug) echo "mul\n";
									break; }

								// square root
								case(26) : {
									$val = sqrt($this->get_head());
									$this->enqueue($val);
									if($this->debug) echo "sqrt\n";
									break; }

								// duplicate
								case(27) : {
									$val = $this->get_head();
									$this->enqueue($val);
									$this->enqueue($val);
									if($this->debug) echo "dup\n";
									break; }

								// swap ('exch')
								case(28) : {
									$val1 = $this->get_head();
									$val2 = $this->get_head();
									$this->enqueue($val2);
									$this->enqueue($val1);
									if($this->debug) echo "exch\n";
									break; }

								// index
								case(29) : {
									echo "index\n";
									echo "NOT IMPLEMENTED\n";
									break; }

								// roll
								case(30) : {
									echo "roll\n";
									echo "NOT IMPLEMENTED\n";
									break; }

								// hflex: dx1 dx2 dy2 dx3 dx4 dx5 dx6
								case(34) : {
									if($this->debug) echo "hflex operator - stack: {" . (implode(',',$this->stack)) . "}\n";
									$dx1 = $this->get_head();
									$dx2 = $this->get_head();
									$dy2 = $this->get_head();
									$dx3 = $this->get_head();
									$dx4 = $this->get_head();
									$dx5 = $this->get_head();
									$dx6 = $this->get_head();
									if($this->debug) echo "hflex ($dx1 $dx2 $dy2 $dx3 $dx4 $dx5 $dx6)\n\n";
									$this->add_rule(new HFLEX($dx1, $dx2, $dy2, $dx3, $dx4, $dx5, $dx6));
									break; }

								// flex
								case(35) : {
									if($this->debug) echo "flex operator - stack: {" . (implode(',',$this->stack)) . "}\n";
									$dx1 = $this->get_head();
									$dy1 = $this->get_head();
									$dx2 = $this->get_head();
									$dy2 = $this->get_head();
									$dx3 = $this->get_head();
									$dy3 = $this->get_head();
									$dx4 = $this->get_head();
									$dy4 = $this->get_head();
									$dx5 = $this->get_head();
									$dy5 = $this->get_head();
									$dx6 = $this->get_head();
									$dy6 = $this->get_head();
									$fd = $this->get_head();
									if($this->debug) echo "flex ($dx1 $dy1 $dx2 $dy2 $dx3 $dy3 $dx4 $dy4 $dx5 $dy5 $dx6 $dy6 $fd)\n\n";
									$this->add_rule(new FLEX($dx1, $dy1, $dx2, $dy2, $dx3, $dy3, $dx4, $dy4, $dx5, $dy5, $dx6, $dy6, $fd));
									break; }

								// hflex1
								case(36) : {
									if($this->debug) echo "hflex1 operator - stack: {" . (implode(',',$this->stack)) . "}\n";
									$dx1 = $this->get_head();
									$dy1 = $this->get_head();
									$dx2 = $this->get_head();
									$dy2 = $this->get_head();
									$dx3 = $this->get_head();
									$dx4 = $this->get_head();
									$dx5 = $this->get_head();
									$dy5 = $this->get_head();
									$dx6 = $this->get_head();
									if($this->debug) echo "hflex1 ($dx1 $dy1 $dx2 $dy2 $dx3 $dx4 $dx5 $dy5 $dx6)\n\n";
									$this->add_rule(new HFLEX1($dx1, $dy1, $dx2, $dy2, $dx3, $dx4, $dx5, $dy5, $dx6));
									break; }

								// flex1
								case(37) : {
									if($this->debug) echo "flex1 operator - stack: {" . (implode(',',$this->stack)) . "}\n";
									$dx1 = $this->get_head();
									$dy1 = $this->get_head();
									$dx2 = $this->get_head();
									$dy2 = $this->get_head();
									$dx3 = $this->get_head();
									$dy3 = $this->get_head();
									$dx4 = $this->get_head();
									$dy4 = $this->get_head();
									$dx5 = $this->get_head();
									$dy5 = $this->get_head();
									$d6 = $this->get_head();
									if($this->debug) echo "flex1 ($dx1 $dy1 $dx2 $dy2 $dx3 $dy3 $dx4 $dy4 $dx5 $dy5 $d6)\n\n";
									$this->add_rule(new FLEX1($dx1, $dy1, $dx2, $dy2, $dx3, $dy3, $dx4, $dy4, $dx5, $dy5, $d6));
									break; }
							}
						}

						// endchar - note that this may have four bytes worth of argument
						case(14) : {
							if($this->debug) echo "endchar (stack: ".implode(',',$this->stack).")\n";
							$this->add_rule(new ENDCHAR());
							break; }

						// HSTEMHM: y dy {dya dyb}*
						case(18) : {
							if($this->debug) echo "hstemhm operator - stack: {" . (implode(',',$this->stack)) . "}\n";
							// how many arguments should we read, given that we should only read pairs?
							$argnum = count($this->stack) - count($this->stack)%2;
							$args = $this->get_operands($argnum);
							$y = $args[0];
							$dy = $args[1];
							$optional = array();
							for($a=2; $a<count($args);$a+=2) {
								$dya = $args[$a];
								$dyb = $args[$a+1];
								$optional[] = array('dya'=>$dya, 'dyb'=>$dyb); }
							if($this->debug) echo "hstemhm (y=$y, dy=$dy, ".$this->array_collapse($optional).")\n\n";
							$this->add_rule(new HSTEMHM($y, $dy, $optional));
							$this->hstems = 1 + count($optional);
							break; }

						// HINTMASK: 8 bit hint
						case(19) : {
							if($this->debug) echo "hintmask operator - stack: {" . (implode(',',$this->stack)) . "}\n";
							// if we have more than one values left on the stack at this point, they're vstem(hm) values
							$lasttype = $this->get_last_rule()->getType();
							if(count($this->stack)>1 && ($lasttype=='hstem' || $lasttype=='hstemhm')) {
								// how many arguments should we read, given that we should only read pairs?
								$argnum = count($this->stack) - count($this->stack)%2;
								$args = $this->get_operands($argnum);
								$x = $args[0];
								$dx = $args[1];
								$optional = array();
								for($a=2; $a<count($args);$a+=2) {
									$dxa = $args[$a];
									$dxb = $args[$a+1];
									$optional[] = array('dxa'=>$dxa, 'dxb'=>$dxb); }
								if($this->debug) echo "implicit vstem (x=$x, dx=$dx, ".$this->array_collapse($optional).")\n";
								if($lasttype=='hstemhm') { $this->add_rule(new VSTEMHM($x, $dx, $optional)); }
								elseif($lasttype=='hstem') { $this->add_rule(new VSTEM($x, $dx, $optional)); }
								$this->vstems = 1 + count($optional); }
							// get mask bytes
							$mask = array();
							$bytesneeded = 1 + intval(($this->hstems + $this->vstems - 1)/8);
							while($bytesneeded-->0) { $mask[] = $this->read_charstring_byte(); }
							if($this->debug) echo "hintmask (".decbin($mask).")\n\n";
							$this->add_rule(new HINTMASK($mask));
							break; }

						// CTRMASK
						case(20) : {
							if($this->debug) echo "countermask operator - stack: {" . (implode(',',$this->stack)) . "}\n";
							// if we have values on the stack they're for vstemhm
							$lasttype = $this->get_last_rule()->getType();
							if(count($this->stack)>1 && ($lasttype=='hstem' || $lasttype=='hstemhm')) {
								// how many arguments should we read, given that we should only read pairs?
								$argnum = count($this->stack) - count($this->stack)%2;
								$args = $this->get_operands($argnum);
								$x = $args[0];
								$dx = $args[1];
								$optional = array();
								for($a=2; $a<count($args);$a+=2) {
									$dxa = $args[$a];
									$dxb = $args[$a+1];
									$optional[] = array('dxa'=>$dxa, 'dxb'=>$dxb); }
								if($this->debug) echo "implicit vstem (x=$x, dx=$dx, ".$this->array_collapse($optional).")\n";
								if($lasttype=='hstemhm') { $this->add_rule(new VSTEMHM($x, $dx, $optional)); }
								elseif($lasttype=='hstem') { $this->add_rule(new VSTEM($x, $dx, $optional)); }
								$this->vstems = 1 + count($optional); }
							// get mask bytes
							$mask = array();
							$bytesneeded = 1 + intval(($this->hstems + $this->vstems - 1)/8);
							while($bytesneeded-->0) { $mask[] = $this->read_charstring_byte(); }
							if($this->debug) echo "cntrmask ".decbin($mask).")\n\n";
							$this->add_rule(new CNTRMASK($mask));
							break; }

						// RMOVETO: x y
						case(21) : {
							// if we are still in hinting mode, we can now tell whether or not there's a "width" operand left on the stack
							if($this->parse_mode==$this->hinting_mode) { $this->check_for_width(2); }
							if($this->debug) echo "rmoveto operator - stack: {" . (implode(',',$this->stack)) . "}\n";
							$dx = $this->get_head();
							$dy = $this->get_head();
							if($this->debug) echo "rmoveto ($dx $dy)\n\n";
							$this->add_rule(new RMOVETO($dx, $dy));
							break; }

						// HMOVETO: dx1
						case(22) : {
							// if we are still in hinting mode, we can now tell whether or not there's a "width" operand left on the stack
							if($this->parse_mode==$this->hinting_mode) { $this->check_for_width(1); }
							if($this->debug) echo "hmoveto operator - stack: {" . (implode(',',$this->stack)) . "}\n";
							$dx = $this->get_head();
							if($this->debug) echo "hmoveto ($dx)\n\n";
							$this->add_rule(new HMOVETO($dx));
							break; }

						// VSTEMHM: x dx {dxa dxb}*
						case(23) : {
							if($this->debug) echo "vstemhm operator - stack: {" . (implode(',',$this->stack)) . "}\n";
							// how many arguments should we read, given that we should only read pairs?
							$argnum = count($this->stack) - count($this->stack)%2;
							$args = $this->get_operands($argnum);
							$x = $args[0];
							$dx = $args[1];
							$optional = array();
							for($a=2; $a<count($args);$a+=2) {
								$dxa = $args[$a];
								$dxb = $args[$a+1];
								$optional[] = array('dxa'=>$dxa, 'dxb'=>$dxb); }
							if($this->debug) echo "vstemhm (x=$x, dx=$dx, ".$this->array_collapse($optional).")\n\n";
							$this->add_rule(new VSTEMHM($x, $dx, $optional));
							$this->vstems = 1 + count($optional);
							break; }

						// RCURVELINE:  {dxa dya dxb dyb dxc dyc}+ dxd dyd
						case(24) : {
							if($this->debug) echo "rcurveline operator - stack: {" . (implode(',',$this->stack)) . "}\n";
							$data = array();
							while(count($this->stack)>=6) {
								$dxa = $this->get_head();
								$dya = $this->get_head();
								$dxb = $this->get_head();
								$dyb = $this->get_head();
								$dxc = $this->get_head();
								$dyc = $this->get_head();
								$data[] = array('dxa'=>$dxa, 'dya'=>$dya, 'dxb'=>$dxb, 'dyb'=>$dyb, 'dxc'=>$dxc, 'dyc'=>$dyc); }
							$dxd = $this->get_head();
							$dyd = $this->get_head();
							if($this->debug) echo "rcurveline (".$this->array_collapse($data).", dxd=$dxd, dyd=$dyd)\n\n";
							$this->add_rule(new RCURVELINE($data, $dxd, $dyd));
							break; }

						// RLINECURVE: {dxa dya}+ dxb dyb dxc dyc dxd dyd
						case(25) : {
							if($this->debug) echo "rlinecurve operator - stack: {" . (implode(',',$this->stack)) . "}\n";
							$data = array();
							while(count($this->stack)>=7) {
								$dxa = $this->get_head();
								$dya = $this->get_head();
								$data[] = array('dxa'=>$dxa, 'dya'=>$dya); }
							$dxb = $this->get_head();
							$dyb = $this->get_head();
							$dxc = $this->get_head();
							$dyc = $this->get_head();
							$dxd = $this->get_head();
							$dyd = $this->get_head();
							if($this->debug) echo "rlinecurve (".$this->array_collapse($data).", dxb=$dxb, dyb=$dyb, dxc=$dxc, dyc=$dyc, dxd=$dxd, dyd=$dyd)\n\n";
							$this->add_rule(new RLINECURVE($data, $dxb, $dyb, $dxc, $dyc, $dxd, $dyd));
							break; }

						// VVCURVETO: dx1? {dya dxb dyb dyc}+
						case(26) : {
							if($this->debug) echo "vvcurveto operator - stack: {" . (implode(',',$this->stack)) . "}\n";
							$data = array();
							$dx1 = 'no';
							if(count($this->stack)>=5) { $dx1 = $this->get_head(); }
							while(count($this->stack)>=4) {
								$dya = $this->get_head();
								$dxb = $this->get_head();
								$dyb = $this->get_head();
								$dyc = $this->get_head();
								$data[] = array('dya'=>$dya, 'dxb'=>$dxb, 'dyb'=>$dyb, 'dyc'=>$dyc); }
							if($this->debug) echo "vvcurveto (". ($dx1!='no'? "dx1=$dx1, ": '') . $this->array_collapse($data).")\n\n";
							$this->add_rule(new VVCURVETO($dx1, $data));
							break; }

						// HHCURVETO: dy1? {dxa dxb dyb dxc}+
						case(27) : {
							if($this->debug) echo "hhcurveto operator - stack: {" . (implode(',',$this->stack)) . "}\n";
							$data = array();
							$dy1 = 'no';
							if(count($this->stack)>=5) { $dy1 = $this->get_head(); }
							while(count($this->stack)>=4) {
								$dxa = $this->get_head();
								$dxb = $this->get_head();
								$dyb = $this->get_head();
								$dxc = $this->get_head();
								$data[] = array('dxa'=>$dxa, 'dxb'=>$dxb, 'dyb'=>$dyb, 'dxc'=>$dxc); }
							if($this->debug) echo "hhcurveto (". ($dy1!='no'? "dy1=$dy1, ": '') . $this->array_collapse($data).")\n\n";
							$this->add_rule(new HHCURVETO($dy1, $data));
							break; }

						// call global subroutine
						case(29) : {
//							if($this->debug) echo "call global subrouting - stack: {" . (implode(',',$this->stack)) . "}\n";
							$index = $this->get_tail();
							if($this->GlobalSubroutineBias=='-') {
								$nSubrs = count($this->GlobalSubroutines->offsets)-1;
								if($this->GlobalSubroutines->CharstringType==1) { $this->GlobalSubroutineBias = 0; }
								elseif($nSubrs<1240) { $this->GlobalSubroutineBias = 107; }
								elseif($nSubrs<33900) { $this->GlobalSubroutineBias = 1131; }
								else { $this->GlobalSubroutineBias = 32768; }}
							$index += $this->GlobalSubroutineBias;
							$subr_data = $this->GlobalSubroutines->get_data($fh, $index);
//							if($this->debug) echo "global subroutine $index called, charstring (".$this->charstring_as_bytes($subr_data).") inserted for further processing\n\n";
							$this->insert_subroutine_charstring($subr_data);
							break; }

						// VHCURVETO: dy1 dx2 dy2 dx3 {dxa dxb dyb dyc dyd dxe dye dxf}* dyf? (...WTF?!)
						//			or {dya dxb dyb dxc dxd dxe dye dyf}+ dxf?
						case(30) : {
							if($this->debug) echo "vhcurveto operator - stack: {" . (implode(',',$this->stack)) . "}\n";
							if((count($this->stack)-5) % 8 == 0 || (count($this->stack)-4)%8==0)
							{
								$optional = array();
								$dy1 = $this->get_head();
								$dx2 = $this->get_head();
								$dy2 = $this->get_head();
								$dx3 = $this->get_head();
								while(count($this->stack)>=8) {
									$dxa = $this->get_head();
									$dxb = $this->get_head();
									$dyb = $this->get_head();
									$dyc = $this->get_head();
									$dyd = $this->get_head();
									$dxe = $this->get_head();
									$dye = $this->get_head();
									$dxf = $this->get_head();
									$optional[] = array('dxa'=>$dxa, 'dxb'=>$dxb, 'dyb'=>$dyb, 'dyc'=>$dyc, 'dyd'=>$dyd, 'dxe'=>$dxe, 'dye'=>$dye, 'dxf'=>$dxf); }
								$dyf='no';
								if(count($this->stack)>=1) { $dyf = $this->get_head(); }
								if($this->debug) echo "vhcurveto (dy1=$dy1, dx2=$dx2, dy2=$dy2, dx3=$dx3, ".$this->array_collapse($optional) . ($dyf!='no'? ", dyf=$dyf" : '').")\n\n";
								$this->add_rule(new VHCURVETO_long($dy1, $dx2, $dy2, $dx3, $optional, $dyf));
							}
							else
							{
								$data = array();
								while(count($this->stack)>=8) {
									$dya = $this->get_head();
									$dxb = $this->get_head();
									$dyb = $this->get_head();
									$dxc = $this->get_head();
									$dxd = $this->get_head();
									$dxe = $this->get_head();
									$dye = $this->get_head();
									$dyf = $this->get_head();
									$data[] = array('dya'=>$dya, 'dxb'=>$dxb, 'dyb'=>$dyb, 'dxc'=>$dxc, 'dxd'=>$dxd, 'dxe'=>$dxe, 'dye'=>$dye, 'dyf'=>$dyf); }
								$dxf='no';
								if(count($this->stack)>=1) { $dxf = $this->get_head(); }
								if($this->debug) echo "vhcurveto (".$this->array_collapse($data ) . ($dxf!='no'? ", dxf=$dxf" : '').")\n\n";
								$this->add_rule(new VHCURVETO_short($data, $dxf));
							}
							break; }

						// HVCURVETO: dx1 dx2 dy2 dy3 {dya dxb dyb dxc dxd dxe dye dyf}* dxf?
						//			or {dxa dxb dyb dyc dyd dxe dye dxf}+ dyf?
						case(31) : {
							if($this->debug) echo "hvcurveto operator - stack: {" . (implode(',',$this->stack)) . "}\n";
							if((count($this->stack)-5) % 8 == 0 || (count($this->stack)-4) % 8 == 0)
							{
								$optional = array();
								$dx1 = $this->get_head();
								$dx2 = $this->get_head();
								$dy2 = $this->get_head();
								$dy3 = $this->get_head();
								while(count($this->stack)>=8) {
									$dya = $this->get_head();
									$dxb = $this->get_head();
									$dyb = $this->get_head();
									$dxc = $this->get_head();
									$dxd = $this->get_head();
									$dxe = $this->get_head();
									$dye = $this->get_head();
									$dyf = $this->get_head();
									$optional[] = array('dya'=>$dya, 'dxb'=>$dxb, 'dyb'=>$dyb, 'dxc'=>$dxc, 'dxd'=>$dxd, 'dxe'=>$dxe, 'dye'=>$dye, 'dyf'=>$dyf); }
								$dxf='no';
								if(count($this->stack)>=1) { $dxf = $this->get_head(); }
								if($this->debug) echo "hvcurveto (dx1=$dx1, dx2=$dx2, dy2=$dy2, dy3=$dy3, ".$this->array_collapse($optional) . ($dxf!='no'? ", dxf=$dxf" : '').")\n\n";
								$this->add_rule(new HVCURVETO_long($dx1, $dx2, $dy2, $dy3, $optional, $dxf));
							}
							else
							{
								$data = array();
								while(count($this->stack)>=8) {
									$dxa = $this->get_head();
									$dxb = $this->get_head();
									$dyb = $this->get_head();
									$dyc = $this->get_head();
									$dyd = $this->get_head();
									$dxe = $this->get_head();
									$dye = $this->get_head();
									$dxf = $this->get_head();
									$data[] = array('dxa'=>$dxa, 'dxb'=>$dxb, 'dyb'=>$dyb, 'dyc'=>$dyc, 'dyd'=>$dyd, 'dxe'=>$dxe, 'dye'=>$dye, 'dxf'=>$dxf); }
								$dyf='no';
								if(count($this->stack)>=1) { $dyf = $this->get_head(); }
								if($this->debug) echo "hvcurveto (".$this->array_collapse($data) . ($dyf!='no'? ", dyf=$dyf" : '').")\n\n";
								$this->add_rule(new HVCURVETO_short($data, $dyf));
							}
							break; }
					}
				}
			}
			if($this->debug)
			{
				echo "\nflattened charstring:\n";
				for($i=0; $i<strlen($this->charstring);$i++) { echo ord(substr($this->charstring,$i,1)) . " "; }
				echo "\n";
			}
		}

		// if there is 1 number left on the stack, this is the character's real "width",
		// encoded as the difference from nominalWidthX (type2.pdf, pp16)
		private function check_for_width($argcount) {
			$this->parse_mode = $this->outline_mode;
			if($this->differenceWithNominalWidthX==0 && count($this->stack)==($argcount+1)) {
				$diff =$this->get_head();
//				if($this->debug) echo "difference with nominal width (X) found: $diff\n";
				$this->differenceWithNominalWidthX = $diff; }}

		// get the head of the stack
		private function get_head() {
//			if($this->debug) echo "[*] get head - stack: {" . (implode(',',$this->stack)) . "}\n";
			if(count($this->stack)==0) { echo "\nTRYING TO GET FROM AN EMPTY STACK!\n\n"; }
			return array_shift($this->stack); }

		// get a number of operands, counted from the end of the stack.
		private function get_operands($number) {
			if(count($this->stack)==0) { echo "\nTRYING TO GET FROM AN EMPTY STACK!\n\n"; }
			$args = array();
			while($number-->0) { $args[$number] = array_pop($this->stack); }
			return $args; }

		// get the tail of the stack
		private function get_tail() {
//			if($this->debug) echo "[*] get tail - stack: {" . (implode(',',$this->stack)) . "}\n";
			if(count($this->stack)==0) { echo "\nTRYING TO GET FROM AN EMPTY STACK!\n\n"; }
			return array_pop($this->stack); }

		// add data to the stack
		private function enqueue($val) {
//			if($this->debug) echo "[*] enqueue $val - stack: {" . (implode(',',$this->stack)) . "}\n";
			// only queue if the stack is below max stack size (c.f. 5177)
			if(count($this->stack)<48) { $this->stack[] = $val; }}

		// add data to the transient stack
		private function transient_enqueue($val) {
//			if($this->debug) echo "[*] transient enqueue $val - stack: {" . (implode(',',$this->transient_stack)) . "}\n";
			// only queue if the transient stack is below max stack size (c.f. 5177)
			if(count($this->transient_stack)<32) { $this->transient_stack[] = $val; }}

		// add a rule to the glyph's building ruleset
		private function add_rule($rule) { $this->glyphdata[] = $rule; }

		// gets the last rule that was added to the glyph's data representation
		private function get_last_rule() { return $this->glyphdata[count($this->glyphdata)-1]; }

		// reads a byte and moves the pointer up by one
		private function read_charstring_byte() {
			$byte = ord(substr($this->charstring, $this->pointer++, 1));
//			if($this->debug) echo "reading byte at charstring pos ".$this->pointer.": $byte\n";
			return $byte; }

		// inserts a subroutine's charstring into this glyph's charstring
		private function insert_subroutine_charstring($data) {
			$this->charstring = substr($this->charstring, 0, $this->pointer) .
							$data .
							substr($this->charstring, $this->pointer); }

		// turns a charstring into a human-readable series of byte values
		private function charstring_as_bytes($string) {
			$ret = array();
			for($i=0; $i<strlen($string); $i++) { $ret[] = ord(substr($string,$i,1)); }
			return "{" . implode(",",$ret) . "}"; }

		// annoyingly missing-from-php function. collapses a possibly
		// multi-dimensional associative array to a flat string.
		private function array_collapse($array) {
			$s = "(";
			$len = count($array);
			$keys = array_keys($array);
			$values = array_values($array);
			for($e=0; $e<$len; $e++) {
				$key = $keys[$e];
				$value = $values[$e];
				$s .= "['".$key ."'] => " . (is_array($value) ? $this->array_collapse($value) : $value);
				if($e<$len-1) $s .= ','; }
			return $s . ")"; }
	}
?>
