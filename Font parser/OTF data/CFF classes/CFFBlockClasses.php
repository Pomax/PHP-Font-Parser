<?php
	//UTF: ☺

	require_once(OTTTFONT::$CFFlocation . "adobestrings.php");
	require_once(OTTTFONT::$CFFlocation . "type2glyphdata.php");
	require_once(OTTTFONT::$CFFlocation . "CFFDataParser.php");

// -------------------------------------------------

	/**
	  * index for mapping character CID/GID to Font DICT number in the Font DICT INDEX
	  */
	class FDSelectIndex
	{
		var $format = 0;
		var $Range3 = "-";
		var $sentinel = 0;

		function __construct(&$fh)
		{
			// which format?
			$this->format =  FileRead::read_BYTE($fh);

			// I'll be honest, I don't care about parsing format 0 at the moment
			if($this->format==0) { }
			
			// I do care about parsing format 3, though.
			elseif($this->format==3)
			{
				$nRanges = FileRead::read_SHORT($fh);
				$this->Range3 = array();
				for($i=0; $i<$nRanges; $i++) {
					$Range3 = array('first'=>FileRead::read_SHORT($fh), 'fd' =>FileRead::read_BYTE($fh));
					$this->Range3[] = $Range3; }
				$this->sentinel = FileRead::read_SHORT($fh);
			}
		}
		
		// get the FontDict that goes with this glyph
		function get_FD($glyphindex)
		{
			if($this->format==0) {
				echo "ERROR: FD Format 0, NOT IMPLEMENTED PARSING FOR THIS FORMAT\n";
				return false; }
			elseif($this->format==3) {				
				for($range3=0; $range3<count($this->Range3); $range3++) {
					if($this->Range3[$range3]['first']>$glyphindex) {
						return $this->Range3[$range3-1]['fd']; }}
				// glyph may be in the range {last-sentinel}
				if($glyphindex <= $this->sentinel) { return $this->Range3[$range3-1]['fd']; }
				// if it's not, we have a problem, and a mystery glyph that should not exist!
				else { echo "ERROR IN FETCHING FONT DICT FOR GLYPH $glyphindex (none found!!)\n"; return false; }
			}
			// shouldn't be able to get here
			return false;
		}
	}

// ---------------------------------------------------------------
//   It is unlikely you will want to care about any of the 'struct' classes below
// ---------------------------------------------------------------

	/**
	  * object class for a generic CFF INDEX block
	  */
	class CFFIndex
	{
		// standard index
		var $count = "-";
		var $offSize = "-";
		var $offsets = "-";
		var $datastart = "-";
		var $nexttable = "-";

		// top dict etc.
		var $version = "-";
		var $Notice = "-";
		var $Copyright = "-";
		var $FullName = "-";
		var $FamilyName = "-";
		var $Weight = "-";
		var $isFixedPitch = 0;
		var $ItalicAngle = 0;
		var $UnderlinePosition = -100;
		var $UnderlineThickness = 50;
		var $PaintType = 0;
		var $CharstringType = 2;
		var $FontMatrix = array(0.001, 0, 0, 0.001, 0, 0);
		var $UniqueID = "-";
		var $FontBBox = array(0, 0, 0, 0);
		var $StrokeWidth = 0;
		var $XUID = "-";
		var $charset = 0;
		var $Encoding = 0;
		var $CharStrings = "-";
		var $Private = "-";
		var $SyntheticBase = "-";
		var $PostScript = "-";
		var $BaseFontName = "-";
		var $BaseFontBlend = "-";

		// CID specific top dict values
		var $ROS = array();
		var $CIDFontVersion = 0;
		var $CIDFontRevision = 0;
		var $CIDFontType = 0;
		var $CIDCount = 0;
		var $UIDBase = "-";
		var $FDArray = "-";
		var $FDSelect = "-";
		var $FontName = "-";

		var $data_as_strings = false;
		function data_as_strings() { $this->data_as_strings = true;}

		// grabs a particular data block - preserves the filepointer position (saves, relocates, restores)
		function get_data(&$fh, $offset_index) {
			$mark = ftell($fh);
			rewind($fh);
			fseek($fh, $this->datastart + $this->offsets[$offset_index]);
			$rb = $this->offsets[$offset_index+1] - $this->offsets[$offset_index];
			$data = '';
			while($rb-->0) { $data .= chr(FileRead::read_BYTE($fh)); }
			rewind($fh); fseek($fh, $mark);
			return $data; }

		// static create
		static function create_index(&$fh)
		{
			$index = new CFFIndex();
			$index->setup($fh);
			return $index;
		}
		
		// perform base setup
		function setup(&$fh)
		{
			$this->set_base_values($fh);
		}
		
		// fills an index with standard values, provided the filepointer is at the start of an index
		function set_base_values(&$fh)
		{
			$this->byteposition = ftell($fh);
			//echo "table start = " . $this->byteposition . " (".dechex($this->byteposition).")\n";
			$this->count = FileRead::read_USHORT($fh);
			// are there any elements?
			if($this->count==0)  { $this->nexttable = ftell($fh); }
			else
			{
				//echo "count is " . $this->count . "\n";
				$offSize =  FileRead::read_BYTE($fh);
				//echo "offSize = " . $offSize . "\n";
				$this->offSize = $offSize;
				$this->offsets = array();

				$blocksize = ($this->count+1) * $offSize;
				$block = fread($fh, $blocksize);
				
				// you could optimise this by using some nice code with a switch statement and compact source, and it would run slower.
				// normally, this would not be a big problem, but when it's about the speed at which individual letters are plucked from a font,
				// 10ms per letter adds up. Very quickly. "Things not to do" include:
				//
				//   - imploding the unpack instead of grabbing [1]
				//   - using a single for(...) loop with a switch(offsize) for the offset reading
				//   - split datablock to byte array first, using preg_split, and then reading (series of) bytes instead of substrings
				//
				
				$offsets = array();
				$i = 0;
				if($offSize == 1) { 
					for($i=0; $i<$blocksize; $i ++) {
						$up = unpack('C', substr($block, $i, 1));
						$offsets[] = $up[1]; }}
				elseif($offSize == 2) {
					for($i=0; $i<$blocksize; $i = $i + 2)  {
						$up = unpack('n', substr($block, $i, 2));
						$offsets[] = $up[1]; }}
				elseif($offSize == 3) {
					for($i=0; $i<$blocksize; $i = $i + 3) {
						 $byte = unpack('C', substr($block, $i, 1));
						 $short = unpack('n', substr($block, $i + 1, 2));
						 $offsets[] = (0x010000 * $byte[1]) + $short[1]; }}
				elseif($offSize == 4) {
					for($i=0; $i<$blocksize; $i = $i + 4) {
						$up = unpack('N', substr($block, $i, 4));
						$offsets[] = $up[1]; }}
				else {
					echo "ERROR: offSize is unknown size: $offSize\n";
					exit(-1); }
				$this->offsets = $offsets;

				// the filepointer for the data start is set at 1 byte before the actual data
				//  (see 16 March 2000 version of Adobe CFF spec, pp10, right after Table 7)
				$this->datastart = ftell($fh)-1;
				
				// the next table can be found with a calculation mostly derived from the example on page 50 of the CFF specification
				$offsetcount = count($this->offsets);			
				$offset_and_data = ($this->offSize*$offsetcount)			// = number-of-offsets * size-of-offsets		(after, fp at start of data block)
							+ $this->offsets[$offsetcount-1];		// = last entry in the offsets array			(after, fp at start of next table)	
				$this->nexttable = $this->byteposition					// = start of table					(after, fp at start of table)
							+ 2							// = unsigned short for "count"			(after, fp at start of offset definitions)
							+ $offset_and_data;
				
				//echo "next table starts at " . $this->nexttable . " (".dechex($this->nexttable).")\n";
			}
		}

		// fills the  index with operator/operand data
		function process(&$fh)
		{
			for($datablock =0; $datablock < count($this->offsets)-1; $datablock++)
			{
				$start = $this->datastart + $this->offsets[$datablock];
				$end = $this->datastart + $this->offsets[$datablock+1];
				CFFDataParser::process_block($fh, $start, $end, $this);
			}
		}

		// ye olde tostring
		function toString() {
			// standard values
			$ret = "{CFF Index: count=".$this->count.", offSize=".$this->offSize.", offsets:{";
			$ssess = array();
			for($o=0; $o<count($this->offsets); $o++) { $ssess[] = "[$o]=>[".$this->offsets[$o]."]"; }
			$ret .= implode(",", $ssess) . "}}";
			$ret .= "\n";
			return $ret; }
	}

// ----------------------------------------------------

	class CFFStringIndex extends CFFIndex
	{
		static function create_index(&$fh)
		{
			$index = new CFFStringIndex();
			$index->setup($fh);
			return $index;
		}
	
		function setup(&$fh)
		{
			parent::setup($fh);
		}
	}

// ----------------------------------------------------

	class CFFTopDictIndex extends CFFIndex
	{
		static function create_index(&$fh)
		{
			$index = new CFFTopDictIndex();
			$index->setup($fh);
			return $index;
		}

		public function is_CIDFont() { return $this->ROS!=array(); }
		
		function setup(&$fh)
		{
			parent::setup($fh);
			$this->process($fh);
		}

		var $privatedict = "-";

		function load_private_dict(&$fh, $cff_offset, $charstringtype)
		{
			$private_dict_size = $this->Private['dictsize'];
			$private_dict_offset = $this->Private['offset'];
			$privdict = new CFFPrivateDict();
			$start = $cff_offset + $private_dict_offset;
			$end = $start + $private_dict_size;
			CFFDataParser::process_block($fh, $start, $end, $privdict);
			$privdict->load_subroutines($fh, $start, $charstringtype);
			$this->privatedict = $privdict;
		}
		
		function get_subroutine(&$fh, $subrindex)
		{
      if($this->privatedict!="-") {
        return $this->privatedict->get_subroutine($fh, $subrindex); }
      die("ERROR: attempted to access Top Dict as Font Dict in a font that does not support this.\n");
		}
		
		function toString()
		{
			$ret = parent::toString();
			$ret .= "version: " . $this->version . "\n" . 
			"Notice: " . $this->Notice . "\n" . 
			"Copyright: " . $this->Copyright . "\n" . 
			"FullName: " . $this->FullName . "\n" . 
			"FamilyName: " . $this->FamilyName . "\n" . 
			"Weight: " . $this->Weight . "\n" . 
			"isFixedPitch: " . $this->isFixedPitch . "\n" . 
			"ItalicAngle: " . $this->ItalicAngle . "\n" . 
			"UnderlinePosition: " . $this->UnderlinePosition . "\n" . 
			"UnderlineThickness: " . $this->UnderlineThickness . "\n" . 
			"PaintType: " . $this->PaintType . "\n" . 
			"CharstringType: " . $this->CharstringType . "\n" . 
			"FontMatrix: {" . implode(",",$this->FontMatrix) . "}\n" .
			"UniqueID: " .  $this->UniqueID  . "\n" . 
			"FontBBox: {" . implode(",",$this->FontBBox) . "}\n" .
			"StrokeWidth: " . $this->StrokeWidth . "\n" . 
			"XUID: " . CFFDataParser::get_operator_value($this->XUID) . "\n" .  
			"charset: " . $this->charset . "\n" . 
			"Encoding: " . $this->Encoding . "\n" . 
			"CharStrings: " . $this->CharStrings . "\n" . 
			"Private: " . CFFDataParser::get_operator_value($this->Private) . "\n" .  
			"SyntheticBase: " .  $this->SyntheticBase . "\n" . 
			"PostScript: " . $this->PostScript . "\n" . 
			"BaseFontName: " . $this->BaseFontName . "\n" .  
			"BaseFontBlend: " . $this->BaseFontBlend . "\n" .
			"[CID data]\nROS: {". implode(",", $this->ROS) . "}\n" .
			"CIDFontVersion: " . $this->CIDFontVersion . "\n" . 
			"CIDFontRevision: " . $this->CIDFontRevision . "\n" . 
			"CIDFontType: " . $this->CIDFontType . "\n" . 
			"CIDCount: " . $this->CIDCount . "\n" . 
			"UIDBase: " . $this->UIDBase . "\n" . 
			"FDArray: " . $this->FDArray . "\n" . 
			"FDSelect: " . $this->FDSelect . "\n" . 
			"FontName: " . $this->FontName . "\n";
			return $ret;
		}
	}

// ----------------------------------------------------

	class CFFPrivateDict
	{
		var $BlueValues = "-";
		var $OtherBlues = "-";
		var $FamilyBlues = "-";
		var $FamilyOtherBlues = "-";
		var $BlueScale = 0.039625;
		var $BlueShift = 7;
		var $BlueFuzz = 1;
		var $StdHW  = "-";
		var $StdVW  = "-";
		var $StemSnapH = "-";
		var $StemSnapV = "-";
		var $ForceBold = false;
		var $LanguageGroup = 0;
		var $ExpansionFactor = 0.06;
		var $initialRandomSeed = 0;
		var $Subrs = "-";
		var $defaultWidthX = 0;
		var $nominalWidthX = 0;

		var $Subroutinesbias = 0;
		var $SubroutineIndex = "-";
		
		function load_subroutines(&$fh, $local_offset, $charstringtype)
		{
			if($this->Subrs!='-')
			{
				rewind($fh);
				fseek($fh, $local_offset + $this->Subrs);
			
				$this->SubroutineIndex = CFFIndex::create_index($fh);
				$nSubrs = count($this->SubroutineIndex->offsets)-1;
				if($charstringtype==1) { $this->Subroutinesbias = 0; }
				elseif($nSubrs<1240) { $this->Subroutinesbias = 107; }
				elseif($nSubrs<33900) { $this->Subroutinesbias = 1131; }
				else { $this->Subroutinesbias = 32768; }
				//echo "Subroutine INDEX: ".(count($this->SubroutineIndex->offsets)-1)." entries\n";
			}
		}
		
		function get_subroutine(&$fh, $subrindex)
		{
			$biased = $subrindex + $this->Subroutinesbias;
			//echo "subroutine $subrindex requested, bias = " . $this->Subroutinesbias . " for subroutine index with " . (count($this->SubroutineIndex->offsets)-1) . " subroutines. Calling subroutine $biased\n";
			return $this->SubroutineIndex->get_data($fh, $biased);
		}

		function toString()
		{
			$ret = "BlueValues: ". CFFDataParser::get_operator_value($this->BlueValues) . "\n" . 
			"OtherBlues: ".CFFDataParser::get_operator_value($this->OtherBlues) . "\n" . 
			"FamilyBlues: ".CFFDataParser::get_operator_value($this->FamilyBlues) . "\n" . 
			"FamilyOtherBlues: ". CFFDataParser::get_operator_value($this->FamilyOtherBlues) . "\n" . 
			"BlueScale: ". $this->BlueScale . "\n" . 
			"BlueShift: ". $this->BlueShift . "\n" . 
			"BlueFuzz: ". $this->BlueFuzz . "\n" . 
			"StdHW: ". $this->StdHW . "\n" . 
			"StdVW: ". $this->StdVW . "\n" . 
			"StemSnapH: ".  CFFDataParser::get_operator_value($this->StemSnapH) . "\n" . 
			"StemSnapV: ". CFFDataParser::get_operator_value($this->StemSnapV) . "\n" . 
			"ForceBold: ". $this->ForceBold . "\n" . 
			"LanguageGroup: ". $this->LanguageGroup . "\n" . 
			"ExpansionFactor: ". $this->ExpansionFactor . "\n" . 
			"initialRandomSeed: ". $this->initialRandomSeed . "\n" . 
			"Subrs: ". $this->Subrs . "\n" . 
			"defaultWidthX: ". $this->defaultWidthX . "\n" . 
			"nominalWidthX: ". $this->nominalWidthX . "\n";
			return $ret;
		}
	}

// ----------------------------------------------------

	class CFFDict
	{
		var $FontName = "-";
		var $isFixedPitch = 0;
		var $ItalicAngle = 0;
		var $UnderlineThickness = 50;
		var $PaintType = 0;
		var $CharstringType = 2;
		var $FontMatrix = array(0.001, 0, 0, 0.001, 0, 0);
		var $FontBBox = array(0, 0, 0, 0);
		var $StrokeWidth = 0;
		var $Encoding = 0;
		var $Private = "-";
		
		function toString()
		{
			$ret = "FontName: " . $this->FontName . "\n" .
			"isFixedPitch: " . $this->isFixedPitch . "\n" . 
			"ItalicAngle: " . $this->ItalicAngle . "\n" . 
			"UnderlineThickness: " . $this->UnderlineThickness . "\n" . 
			"PaintType: " . $this->PaintType . "\n" . 
			"CharstringType: " . $this->CharstringType . "\n" . 
			"FontMatrix: {" . implode(",",$this->FontMatrix) . "}\n" .
			"FontBBox: {" . implode(",",$this->FontBBox) . "}\n" .
			"StrokeWidth: " . $this->StrokeWidth . "\n" . 
			"Encoding: " . $this->Encoding . "\n" . 
			"Private: " . CFFDataParser::get_operator_value($this->Private) . "\n" ;
			"Private: " . CFFDataParser::get_operator_value($this->Private) . "\n" ;
			return $ret;
		}
	}

	class CFFFontDict extends CFFDict
	{
		var $privatedict = "-";

		function load_private_dict(&$fh, $cff_offset, $charstringtype)
		{
			$private_dict_size = $this->Private['dictsize'];
			$private_dict_offset = $this->Private['offset'];
			$privdict = new CFFPrivateDict();
			$start = $cff_offset + $private_dict_offset;
			$end = $start + $private_dict_size;
			CFFDataParser::process_block($fh, $start, $end, $privdict);
			$privdict->load_subroutines($fh, $start, $charstringtype);
			$this->privatedict = $privdict;
		}
		
		function get_subroutine(&$fh, $subrindex)
		{
			return $this->privatedict->get_subroutine($fh, $subrindex);
		}
		
		function toString()
		{
			$ret = parent::toString();
			$ret .= "FONT DICT DATA\n" . $this->privatedict->toString() . "\n";
			$ret .= "---\n";
			return $ret;
		}
	}

	class CFFFontDictIndex extends CFFIndex
	{
		var $cff_offset = "-";
		var $fontdicts = array();
		var $CharStringType = 2;
	
		static function create_index(&$fh, $cff_offset, $charstringtype)
		{
			$index = new CFFFontDictIndex();
			$index->cff_offset = $cff_offset;
			$index->setup($fh);
			$index->CharStringType = $charstringtype;
			return $index;
		}
		
		function setup(&$fh)
		{
			parent::setup($fh);
			$this->process($fh);
		}

		function process(&$fh)
		{
			parent::process($fh);

			// create the Font DICT structures
			for($dict =0; $dict<count($this->offsets)-1; $dict++)
			{
				$fontdict = new CFFFontDict();
				$start = $this->datastart + $this->offsets[$dict];
				$end = $this->datastart + $this->offsets[$dict+1];
				CFFDataParser::process_block($fh, $start, $end, $fontdict);
				$fontdict->load_private_dict($fh, $this->cff_offset, $this->CharStringType);
				$this->fontdicts[] = $fontdict;
			}
		}

		function get_font_dict($fdid)
		{
			return $this->fontdicts[$fdid];
		}

		function toString()
		{
			$ret = parent::toString();
			$ret .= "FONT DICT BLOCKS:\n";
			foreach($this->fontdicts as $fontdict) { $ret .= $fontdict->toString(); }
			return $ret;
		}
	}
?>
