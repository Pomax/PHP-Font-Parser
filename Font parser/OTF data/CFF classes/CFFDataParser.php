<?php
	//UTF: ☺
	
	/*
	 * The only thing this class does is look at a bytestring, and fill index/dict structures.
	 * It's not very glamorous, but the CFF specification wasn't designed with glamour in
	 * mind, it would seem. Just with "how do we get the most data described in the
	 * fewest number of bytes"...
	 */
	class CFFDataParser
	{

		// fills the  index with operator/operand data
		static function process_block(&$fh, $start, $end, &$datastructure)
		{
			rewind($fh);
			fseek($fh, $start);
			$buffer = array();
			while(ftell($fh)<$end) {
				if(CFFDataParser::is_operator($fh)) {
					CFFDataParser::process_operator($fh, $datastructure, $buffer);
					$buffer = array(); }
				else { $buffer[] = CFFDataParser::read_NUMBER($fh); }}
		}
	
		// checks whether the next number is an operator or operand
		static function is_operator(&$fh)
		{
			$b0 = FileRead::read_BYTE($fh);
			fseek($fh, -1, SEEK_CUR);
			if(0<=$b0 && $b0<=21) { return true; }
			return false;
		}
		
		// process an operator + content
		static function process_operator(&$fh, &$index, &$buffer)
		{
			$byte1 = FileRead::read_BYTE($fh);
			//echo "processing operator $byte1 (buffer: ".implode(",",$buffer).")\n";
			switch($byte1)
			{
				case(0) : { $index->version = $buffer[0]; return; }
				case(1) : { $index->Notice = $buffer[0]; return; }
				case(2) : { $index->FullName = $buffer[0]; return; }
				case(3) : { $index->FamilyName = $buffer[0]; return; }
				case(4) : { $index->Weight = $buffer[0]; return; }
				case(5) : { $index->FontBBox = $buffer; return; }
				case(6) : { $index->BlueValues = CFFDataParser::read_DELTAS($buffer); return; }
				case(7) : { $index->OtherBlues = CFFDataParser::read_DELTAS($buffer); return; }
				case(8) : { $index->FamilyBlues = $buffer[0]; return; }
				case(9) : { $index->FamilyOtherBlues = $buffer[0]; return; }
				case(10) : { $index->StdHW = $buffer[0]; return; }
				case(11) : { $index->StdVW = $buffer[0]; return; }
				case(12) :
				{
					$byte2 = FileRead::read_BYTE($fh);
					// echo "  compound - processing $byte2\n";
					switch($byte2)
					{
						case(0) : { $index->Copyright = $buffer[0]; return; }
						case(1) : { $index->isFixedPitch = $buffer[0]; return; }
						case(2) : { $index->ItalicAngle = $buffer[0]; return; }
						case(3) : { $index->UnderlinePosition =$buffer[0]; return; }
						case(4) : { $index->UnderlineThickness = $buffer[0]; return; }
						case(5) : { $index->PaintType =$buffer[0]; return; }
						case(6) : { $index->CharstringType = $buffer[0]; return; }
						case(7) : { $index->FontMatrix = $buffer; return; }
						case(8) : { $index->StrokeWidth = $buffer[0]; return; }
						case(9) : { $index->BlueScale = $buffer[0]; return; }
						case(10) : { $index->BlueShift = $buffer[0]; return; }
						case(11) : { $index->BlueFuzz = $buffer[0]; return; }
						case(12) : { $index->StemSnapH = $buffer[0]; return; }
						case(13) : { $index->StemSnapV =$buffer[0]; return; }						
						case(14) : { $index->ForceBold = ($buffer[0]==0 ? false : true); return; }
						case(17) : { $index->LanguageGroup = $buffer; return; }
						case(18) : { $index->ExpansionFactor = $buffer[0]; return; }
						case(19) : { $index->InitialRandomSeed = $buffer[0]; return; }
						case(20) : { $index->SyntheticBase = $buffer[0]; return; }
						case(21) : { $index->PostScript = $buffer[0]; return; }
						case(22) : { $index->BaseFontName = $buffer[0]; return; }
						case(23) : { $index->BaseFontBlend = $buffer[0]; return; }

						// CID specific
						case(30) : { $index->ROS = $buffer; return; }
						case(31) : { $index->CIDFontVersion = $buffer[0]; return; }
						case(32) : { $index->CIDFontRevision = $buffer[0]; return; }
						case(33) : { $index->CIDFontType = $buffer[0]; return; }
						case(34) : { $index->CIDCount = $buffer[0]; return; }
						case(35) : { $index->UIDBase = $buffer[0]; return; }
						case(36) : { $index->FDArray = $buffer[0]; return; }
						case(37) : { $index->FDSelect = $buffer[0]; return; }
						case(38) : { $index->FontName = $buffer[0]; return; }
					}
				}
				case(13) : { $index->UniqueID = $buffer[0]; return; }
				case(14) : { $index->XUID = $buffer; return; }
				case(15) : { $index->charset = $buffer[0]; return; }
				case(16) : { $index->Encoding = $buffer[0]; return; }
				case(17) : { $index->CharStrings = $buffer[0]; return; }
				case(18) : { $index->Private = array('dictsize'=>$buffer[0], 'offset'=>$buffer[1]); return; }
				case(19) : { $index->Subrs = $buffer[0]; return; }
				case(20) : { $index->defaultWidthX = $buffer[0]; return; }
				case(21) : { $index->nominalWidthX = $buffer[0]; return; }
				default : { echo "ERROR: did not process byte!\n"; }
			}
		}
		
		static function read_DELTAS(&$buffer) {
			$array = array();
			$array[] = array_shift($buffer);
			while(count($buffer)>0) { $array[] =  array_shift($buffer) + $array[count($array)-1]; }
			return $array;
		}
		
		// a 2 byte identifier
		static function read_SID(&$fh) { return FileRead::read_USHORT($fh); }
		
		// n element number array
		static function read_ARRAY(&$fh, $n) {
			$ret = array();
			while($n>0) { $ret[] = CFFDataParser::read_NUMBER($fh); $n--; }
			return $ret; }
		
		// completely mad integer representation - see page 8-9 of the CFF documentation
		static function read_NUMBER(&$fh)
		{
			$b0 = FileRead::read_BYTE($fh);
			// single byte integers
			if(32<=$b0  && $b0<=246) {
				return ($b0-139); }
			// two byte integers
			$b1 = FileRead::read_BYTE($fh);
			if(247<=$b0 && $b0<=250) {
				return (256*($b0-247) + $b1 + 108); }
			if(251<=$b0 && $b0<=259) {
				return (-256*($b0-251) - ($b1 + 108));}
			// three byte integers
			$b2 = FileRead::read_BYTE($fh);
			if($b0==28) {
				$v = ($b1<<8 | $b2);
				if($v>pow(2,15)) $v -= pow(2,16); 
				return $v; }
			// five byte integers
			$b3 = FileRead::read_BYTE($fh);
			$b4 = FileRead::read_BYTE($fh);
			if($b0==29) {
				return $b1<<24|$b2<<16|$b3<<8|$b4; }
			// real numeral, rather than integer numeral
			elseif($b0==30)
			{
				$stop_nibble = 0xf;
				$n1 = -1;
				$n2 = -1;
				$numstring = '';
				while($n1 != $stop_nibble && $n2 != $stop_nibble){
					$nibbles = FileRead::read_BYTE($fh);
					//echo dechex($nibbles) . ": {";
					$n1 = $nibbles>>4;;
					//echo "$n1,";
					$n2 = (($nibbles&0xF)<<4)>>4;
					//echo "$n2} ";
					// check both nibbles for what they actually mean
					$nibbles = array($n1, $n2);
					foreach($nibbles as $nibble) {
						if(0<=$nibble && $nibble<=9) { $numstring .= $nibble; }
						else {
							switch($nibble) {
								case(0xa) : { $numstring .= "."; break; }
								case(0xb) : { $numstring .= "E"; break; }
								case(0xc) : { $numstring .= "E-"; break; }
								// case(0xd) : { reserved }
								case(0xe) : { $numstring .= "-"; break; }
								case(0xf) : { break; }}}}}
				$real = floatval($numstring);
				//echo "\n returning number conversion of $numstring ($real)\n";
				return $real;
			}
			// fallback.
			echo "error in read_NUMBER, byte pattern ($b0) didn't make sense.\n";
			return 0;
		}

		// one byte integer number, either 1 or 0
		static function read_BOOLEAN(&$fh) { return (CFFDataParser::read_NUMBER($fh)==1); }

		static function get_operator_value($operator)
		{
			return (is_array($operator) ? '{' . implode(",", $operator) . '}' : $operator);
		}
	}
?>
