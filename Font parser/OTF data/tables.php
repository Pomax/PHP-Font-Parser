<?php
	//UTF: ☺

	/**
	 * Standard opentype table description, used in font header
	 * to indicate which tables are implemented in the font.
	 */
	class FontTable {
		var $tag;
		var $checkSum;
		var $offset;
		var $length;
		function __construct($tag, $checksum, $offset, $length) {
			$this->tag=$tag;
			$this->checkSum=$checksum;
			$this->offset=$offset;
			$this->length=$length; }
		function toString() {
			return "{tag: ".$this->tag.
							 ", checkSum: ".$this->checkSum.
							 ", offset: ".$this->offset.
							 ", length: ".$this->length."}"; }}

// ----------------------------------------------------

	/**
	 * This ensures all tables have a sensible toString function
	 */
	class PrintableTable
	{
		// the toString function simple writes out the
		// class name, and all properties with their value.
		function toString() {
			$vars = get_object_vars($this);
			$keys = array_keys($vars);
			$str = get_class($this) . " => {";
			for($i=0, $end=count($keys); $i<$end; $i++) {
				$key = $keys[$i];
				$val = $vars[$key];
				$str .= "$key: $val";
				if($i<$end-1) { $str .= ", "; }}
			$str .= "}\n";
			return $str;
		}
	}

	/**
	 * "struct" class for the "post" table.
	 * NOTE: this is a partial implementation, mostly because the data in this table
	 * is used only by postscript printers. It has some useful information, but it's not
	 * worth fully parsing when the device it's for is not a printer.
	 */
	class OTTTFontPOST extends PrintableTable
	{
		var $Version;
		var $italicAngle;
		var $underlinePosition;
		var $underlineThickness;
		var $isFixedPitch;
	}

	/**
	 * "struct" class for the "OS/2" table
	 */
	class OTTTFontOS2 extends PrintableTable
	{
		var $version;
		var $xAvgCharWidth;
		var $usWeightClass;
		var $usWidthClass;
		var $fsType;
		var $ySubscriptXSize;
		var $ySubscriptYSize;
		var $ySubscriptXOffset;
		var $ySubscriptYOffset;
		var $ySuperscriptXSize;
		var $ySuperscriptYSize;
		var $ySuperscriptXOffset;
		var $ySuperscriptYOffset;
		var $yStrikeoutSize;
		var $yStrikeoutPosition;
		var $sFamilyClass;
		var $panose; // byte[10]
		var $ulUnicodeRange1;
		var $ulUnicodeRange2;
		var $ulUnicodeRange3;
		var $ulUnicodeRange4;
		var $achVendID; // char[4]
		var $fsSelection;
		var $usFirstCharIndex;
		var $usLastCharIndex;
		var $sTypoAscender;
		var $sTypoDescender;
		var $sTypoLineGap;
		var $usWinAscent;
		var $usWinDescent;
		var $ulCodePageRange1;
		var $ulCodePageRange2;
		var $sxHeight;
		var $sCapHeight;
		var $usDefaultChar;
		var $usBreakChar;
		var $usMaxContext;
	}

	/**
	 * "struct" class for the "name" table.
	 */
	class OTTTFontNAME extends PrintableTable
	{
		var $format;
		var $count;
		var $stringOffset;
		var $nameRecord;
		var $langTagCount;
		var $langTagRecord;
	}

	/**
	 * "struct" class for the "maxp" table.
	 */
	class OTTTFontMAXP extends PrintableTable
	{
		var $Table_version_number;
		var $numGlyphs;
		var $maxPoints;
		var $maxContour;
		var $maxCompositePoints;
		var $maxCompositeContours;
		var $maxZones;
		var $maxTwilightPoints;
		var $maxStorage;
		var $maxFunctionDefs;
		var $maxInstructionDefs;
		var $maxStackElements;
		var $maxSizeOfInstructions;
		var $maxComponentElements;
		var $maxComponentDepth;
	}
	
	/**
	 * "struct" class for the "hmtx" table.
	 */
	class OTTTFontHMTX extends PrintableTable
	{
		var $hMetrics;
		var $leftSideBearing;
	}

	/**
	 * "struct" class for the "hhea" table.
	 */
	class OTTTFontHHEA extends PrintableTable
	{
		var $Table_version_number;
		var $Ascender;
		var $Descender;
		var $LineGap;
		var $advanceWidthMax;
		var $minLeftSideBearing;
		var $minRightSideBearing;
		var $xMaxExtent;
		var $caretSlopeRise;
		var $caretSlopeRun;
		var $caretOffset;
		var $reserved1;
		var $reserved2;
		var $reserved3;
		var $reserved4;
		var $metricDataFormat;
		var $numberOfHMetrics;
	}

	/**
	 * "struct" class for the "head" table.
	 */
	class OTTTFontHEAD extends PrintableTable
	{
		var $Table_version_number;
		var $fontRevision;
		var $checkSumAdjustment ;
		var $magicNumber;
		var $flags;
		var $unitsPerEm;
		var $created;
		var $modified;
		var $xMin;
		var $yMin;
		var $xMax;
		var $yMax;
		var $macStyle;
		var $lowestRecPPEM;	
		var $fontDirectionHint;
		var $indexToLocFormat;
		var $glyphDataFormat;
	}

// --------------------------------

	/**
	 * Serves up (and loads if not loaded yet) the universal OTF tables into memory. Namely:
	 *
	 *	head - Font header
	 *	hhea - Horizontal header
	 *	hmtx - Horizontal metrics
	 *	maxp - Maximum profile
	 *	name	- Naming table
	 *	OS/2 - OS/2 and Windows specific metrics (but actually very useful in general)
	 *	post - PostScript information for the font
	 *
	 * 	You will note that cmap is not loaded here. Instead, it is referenced when needed,
	 *	because we don't need the entire CMAP
	 */
	class OTFTableLoader
	{
		var $head_table = '';
		var $hhea_table = '';
		var $hmtx_table = '';
		var $maxp_table = '';
		var $name_table = '';
		var $os2_table = '';
		var $post_table = '';
		private $font;

		// bind a new OTF table loader to a specific font.
		function __construct($font) { 
			$this->font = $font; 
			$this->load_tables($font); 
		}

		/**
		 * loads all the OpenType tables in one go
		 */
		private function load_tables($font)
		{
			$this->get_head_table($font);
			$this->get_hhea_table($font);
			$this->get_hmtx_table($font);
			// get_hmtx_table() implicitly calls get_maxp_table() for us, so we don't need to call it a second time.
			$this->get_name_table($font);
			$this->get_os2_table($font);
			$this->get_post_table($font);
		}

		/**
		 * Parses header table (head) and sticks it in the indicated font
		 */
		function get_head_table($font)
		{
			if($this->head_table!='') { return $this->head_table; }
			$head =& $font->tables['head'];
			$fh = $font->open();
			fseek($fh, $head->offset);
			// see http://www.microsoft.com/typography/otspec/head.htm
			$head = new OTTTFontHEAD();
			$head->Table_version_number = FileRead::read_Fixed($fh);
			$head->fontRevision = FileRead::read_Fixed($fh);
			$head->checkSumAdjustment	= FileRead::read_ULONG($fh);
			$head->magicNumber = FileRead::read_ULONG($fh);
			$head->flags = FileRead::read_USHORT($fh);
			$head->unitsPerEm = FileRead::read_USHORT($fh);
			$head->created = FileRead::read_LONGDATETIME($fh);
			$head->modified = FileRead::read_LONGDATETIME($fh);
			$head->xMin = FileRead::read_SHORT($fh);
			$head->yMin = FileRead::read_SHORT($fh);
			$head->xMax = FileRead::read_SHORT($fh);
			$head->yMax = FileRead::read_SHORT($fh);
			$head->macStyle = FileRead::read_USHORT($fh);
			$head->lowestRecPPEM	 = FileRead::read_USHORT($fh);
			$head->fontDirectionHint = FileRead::read_SHORT($fh);
			$head->indexToLocFormat = FileRead::read_SHORT($fh);
			$head->glyphDataFormat = FileRead::read_SHORT($fh);
			$this->head_table = $head;
			fclose($fh);
			return $this->get_head_table($font);
		}


		/**
		 * Parses horizontal header table (hhea) and sticks it in the indicated font
		 */
		function get_hhea_table($font)
		{
			if($this->hhea_table!='') { return $this->hhea_table; }
			$hhea =& $font->tables['hhea'];
			$fh = $font->open();
			fseek($fh, $hhea->offset);
			// see http://www.microsoft.com/typography/otspec/hhea.htm
			$hhea = new OTTTFontHHEA();
			$hhea->Table_version_number = FileRead::read_Fixed($fh);
			$hhea->Ascender = FileRead::read_FWORD($fh);
			$hhea->Descender = FileRead::read_FWORD($fh);
			$hhea->LineGap = FileRead::read_FWORD($fh);
			$hhea->advanceWidthMax = FileRead::read_UFWORD($fh);
			$hhea->minLeftSideBearing = FileRead::read_FWORD($fh);
			$hhea->minRightSideBearing = FileRead::read_FWORD($fh);
			$hhea->xMaxExtent = FileRead::read_FWORD($fh);
			$hhea->caretSlopeRise = FileRead::read_SHORT($fh);
			$hhea->caretSlopeRun = FileRead::read_SHORT($fh);
			$hhea->caretOffset = FileRead::read_SHORT($fh);
			$hhea->reserved1 = FileRead::read_SHORT($fh);
			$hhea->reserved2 = FileRead::read_SHORT($fh);
			$hhea->reserved3 = FileRead::read_SHORT($fh);
			$hhea->reserved4 = FileRead::read_SHORT($fh);
			$hhea->metricDataFormat = FileRead::read_SHORT($fh);
			$hhea->numberOfHMetrics = FileRead::read_USHORT($fh);
			$this->hhea_table = $hhea;
			fclose($fh);
			return $this->get_hhea_table($font);
		}

		/**
		 * Parses horizontal metrics table (hmtx) and sticks it in the indicated font
		 */
		function get_hmtx_table($font)
		{
			if($this->hmtx_table!='') { return $this->hmtx_table; }
			$hmtx =& $font->tables['hmtx'];
			$fh = $font->open();
			fseek($fh, $hmtx->offset);
			// see http://www.microsoft.com/typography/otspec/hmtx.htm
			$hmtx = new OTTTFontHMTX();
			$longHorMetrics = array();
			$numberOfHMetrics = $this->get_hhea_table($font)->numberOfHMetrics;
			for($i=0; $i<$numberOfHMetrics; $i++) {
				$advanceWidth = FileRead::read_USHORT($fh);
				$lsb = FileRead::read_SHORT($fh);
				$longHorMetric = array("advanceWidth"=>$advanceWidth, "lsb" => $lsb);
				$longHorMetrics[] = $longHorMetric; }
			$hmtx->hMetrics =& $longHorMetrics;
			$leftSideBearing = array();
			$entries = $this->get_maxp_table($font)->numGlyphs - $numberOfHMetrics;
			for($i=0; $i<$entries; $i++) { $leftSideBearing[] = FileRead::read_SHORT($fh); }
			$hmtx->leftSideBearing =& $leftSideBearing;
			$this->hmtx_table = $hmtx;
			fclose($fh);
			return $this->get_hmtx_table($font);
		}

		/**
		 * Parse maximum profile table (maxp)
		 */
		function get_maxp_table($font)
		{
			if($this->maxp_table!='') { return $this->maxp_table; }
			$maxp =& $font->tables['maxp'];
			$fh = $font->open();
			fseek($fh, $maxp->offset);
			// see http://www.microsoft.com/typography/otspec/maxp.htm
			$maxp = new OTTTFontMAXP();
			$maxp->Table_version_number = FileRead::read_Fixed($fh);
			$maxp->numGlyphs = FileRead::read_USHORT($fh);
			// if the table version is 0.5, there won't be more data in this table
			if($maxp->Table_version_number==0x00005000) {}
			// if the table is version 1.0, there's lots more data to gather
			elseif($maxp->Table_version_number==0x00010000) {
				$maxp->maxPoints = FileRead::read_USHORT($fh);
				$maxp->maxContour = FileRead::read_USHORT($fh);
				$maxp->maxCompositePoints = FileRead::read_USHORT($fh);
				$maxp->maxCompositeContours = FileRead::read_USHORT($fh);
				$maxp->maxZones = FileRead::read_USHORT($fh);
				$maxp->maxTwilightPoints = FileRead::read_USHORT($fh);
				$maxp->maxStorage = FileRead::read_USHORT($fh);
				$maxp->maxFunctionDefs = FileRead::read_USHORT($fh);
				$maxp->maxInstructionDefs = FileRead::read_USHORT($fh);
				$maxp->maxStackElements = FileRead::read_USHORT($fh);
				$maxp->maxSizeOfInstructions = FileRead::read_USHORT($fh);
				$maxp->maxComponentElements = FileRead::read_USHORT($fh);
				$maxp->maxComponentDepth = FileRead::read_USHORT($fh); }
			$this->maxp_table = $maxp;
			fclose($fh);
			return $this->get_maxp_table($font);
		}
		
		/**
		 * Parse name table (name)
		 */
		function get_name_table($font)
		{
			if($this->name_table!='') { return $this->name_table; }
			$name =& $font->tables['name'];
			$fh = $font->open();
			fseek($fh, $name->offset);
			// see http://www.microsoft.com/typography/otspec/name.htm
			$name = new OTTTFontNAME();
			$name->format = FileRead::read_USHORT($fh);
			$name->count = FileRead::read_USHORT($fh);
			$name->stringOffset = FileRead::read_USHORT($fh);
			$name->nameRecord[] = array();
			for($i=0; $i<$name->count; $i++) {
				$nameRecord = array("platformID"=>FileRead::read_USHORT($fh), 
								"encodingID"=>FileRead::read_USHORT($fh), 
								"languageID"=>FileRead::read_USHORT($fh), 
								"nameID"=>FileRead::read_USHORT($fh), 
								"length"=>FileRead::read_USHORT($fh), 
								"offset"=>FileRead::read_USHORT($fh));
				$name->nameRecord[] = $nameRecord; }
			// format 1 has additional language tags
			if($name->format == 1) {
				$name->langTagCount = FileRead::read_USHORT($fh);
				$name->LangTagRecord = array();
				for($i=0; $i<$name->langTagCount; $i++) {
					$name->LangTagRecord[] = array("length"=>FileRead::read_USHORT($fh),
											"offset"=>FileRead::read_USHORT($fh)); }}
			$this->name_table = $name;
			fclose($fh);
			return $this->get_name_table($font);
		}

		/**
		 * Parse OS/2 and Windows table (OS/2)
		 */
		function get_os2_table($font)
		{
			if($this->os2_table!='') { return $this->os2_table; }
			$os2 =& $font->tables['OS/2'];
			$fh = $font->open();
			fseek($fh, $os2->offset);
			// see http://www.microsoft.com/typography/otspec/os2.htm
			$os2 = new OTTTFontOS2();
			$os2->version = FileRead::read_USHORT($fh);
			$os2->xAvgCharWidth = FileRead::read_SHORT($fh);
			$os2->usWeightClass = FileRead::read_USHORT($fh);
			$os2->usWidthClass = FileRead::read_USHORT($fh);
			$os2->fsType = FileRead::read_USHORT($fh);
			$os2->ySubscriptXSize = FileRead::read_SHORT($fh);
			$os2->ySubscriptYSize = FileRead::read_SHORT($fh);
			$os2->ySubscriptXOffset = FileRead::read_SHORT($fh);
			$os2->ySubscriptYOffset = FileRead::read_SHORT($fh);
			$os2->ySuperscriptXSize = FileRead::read_SHORT($fh);
			$os2->ySuperscriptYSize = FileRead::read_SHORT($fh);
			$os2->ySuperscriptXOffset = FileRead::read_SHORT($fh);
			$os2->ySuperscriptYOffset = FileRead::read_SHORT($fh);
			$os2->yStrikeoutSize = FileRead::read_SHORT($fh);
			$os2->yStrikeoutPosition = FileRead::read_SHORT($fh);
			$os2->sFamilyClass = FileRead::read_SHORT($fh);
			$os2->panose = array();
			for($i=0; $i<10; $i++) { $os2->panose[] = FileRead::read_BYTE($fh); }
			$os2->ulUnicodeRange1 = FileRead::read_ULONG($fh);
			$os2->ulUnicodeRange2 = FileRead::read_ULONG($fh);
			$os2->ulUnicodeRange3 = FileRead::read_ULONG($fh);
			$os2->ulUnicodeRange4 = FileRead::read_ULONG($fh);
			$os2->achVendID = array();
			// technically char, but the datatype's the same
			for($i=0; $i<4; $i++) { $os2->achVendID[] = FileRead::read_BYTE($fh); }
			$os2->fsSelection = FileRead::read_USHORT($fh);
			$os2->usFirstCharIndex = FileRead::read_USHORT($fh);
			$os2->usLastCharIndex = FileRead::read_USHORT($fh);
			$os2->sTypoAscender = FileRead::read_SHORT($fh);
			$os2->sTypoDescender = FileRead::read_SHORT($fh);
			$os2->sTypoLineGap = FileRead::read_SHORT($fh);
			$os2->usWinAscent = FileRead::read_USHORT($fh);
			$os2->usWinDescent = FileRead::read_USHORT($fh);
			$os2->ulCodePageRange1 = FileRead::read_ULONG($fh);
			$os2->ulCodePageRange2 = FileRead::read_ULONG($fh);
			$os2->sxHeight = FileRead::read_SHORT($fh);
			$os2->sCapHeight = FileRead::read_SHORT($fh);
			$os2->usDefaultChar = FileRead::read_USHORT($fh);
			$os2->usBreakChar = FileRead::read_USHORT($fh);
			$os2->usMaxContext = FileRead::read_USHORT($fh);
			$this->os2_table = $os2;
			fclose($fh);
			return $this->get_os2_table($font);
		}

		/**
		 * Parse PostScript table (post)
		 */
		function get_post_table($font)
		{
			if($this->post_table!='') { return $this->post_table; }
			$post =& $font->tables['post'];
			$fh = $font->open();
			fseek($fh, $post->offset);
			// see http://www.microsoft.com/typography/otspec/post.htm
			$post = new OTTTFontPOST();
			$post->Version = FileRead::read_Fixed($fh);
			$post->italicAngle = FileRead::read_Fixed($fh);
			$post->underlinePosition = FileRead::read_FWORD($fh);
			$post->underlineThickness = FileRead::read_FWORD($fh);
			$post->isFixedPitch = FileRead::read_ULONG($fh);
			$this->post_table = $post;
			fclose($fh);
			return $this->get_post_table($font);
		}
	}

// ------------------------------------------

	// -----------------------
	//        CMAP subtables
	// -----------------------

	/**
	 * Standard opentype/truetype CMAP subtable
	 */
	class FontSubTable {
		var $platformID;
		var $encodingID;
		var $offset;
		public static $encodings = array('Symbol','Unicode BMP (UCS-2)','ShiftJIS','PRC','Big5','Wansung','Johab','Reserved','Reserved','Reserved','Unicode UCS-4');
		function __construct($platform, $encoding, $offset) {
			$this->platformID = $platform;
			$this->encodingID = $encoding;
			$this->offset = $offset; }}

	/**
	 * "struct" class for the cmap format 4 table data.
	 * Typically used for UCS-2
	 */
	class CMAPFormat4
	{
		var $format;
		var $length;
		var $language;
		var $segCountX2;
		var $segCount;		// half of segCountX2
		var $searchRange;
		var $entrySelector;
		var $rangeShift;
		var $endCount;
		var $reservedPad;
		var $startCount;
		var $idDelta;
		var $idRangeOffset;
		var $filepointers;	// special
		var $glyphIdArray;
		var $glyphIdArrayPointer;
		
		/**
		 * create a cmap format 4 struct based on the data at the filepointer
		 */
		static function createCMAPFormat4(&$fh)
		{
			$cmapformat4 = new CMAPFormat4();
			$cmapformat4->length = FileRead::read_USHORT($fh); 		//	This is the length in bytes of the subtable.
			$cmapformat4->language = FileRead::read_USHORT($fh);	 	//	kind of an illegal field, sometimes (see documentation)
			$cmapformat4->segCountX2 = FileRead::read_USHORT($fh); 	//	twice the value of segCount
			$cmapformat4->segCount=$cmapformat4->segCountX2/2;
			$cmapformat4->searchRange = FileRead::read_USHORT($fh); 	//	2 x (2**floor(log2(segCount)))
			$cmapformat4->entrySelector = FileRead::read_USHORT($fh); //	log2(searchRange/2)
			$cmapformat4->rangeShift = FileRead::read_USHORT($fh); 	//	segCountX2 - searchRange
			$segCount = $cmapformat4->segCount;

			$endCount=array();						//	character code for the last character in the segment. the last segment's code is 0xFFFF.
			for($i=0;$i<$segCount;$i++) {
				$endCount[] = FileRead::read_USHORT($fh); }
			$cmapformat4->endCount = $endCount;
			
			$cmapformat4->reservedPad = FileRead::read_USHORT($fh); 	//	Set to 0.

			$startCount=array();					//	character code for the first character in the segment. the last segment's code is 0xFFFF.
			for($i=0;$i<$segCount;$i++) {
				$startCount[] = FileRead::read_USHORT($fh); }
			$cmapformat4->startCount = $startCount;

			
			$idDelta=array();						//	Delta (=mapping offset) for all character codes in a specific segment.
			for($i=0;$i<$segCount;$i++) {
				$idDelta[] = FileRead::read_SHORT($fh); }
			$cmapformat4->idDelta = $idDelta;

			$filepointers=array();
			$idRangeOffset=array();				//	array index offsets for the glyphIdArray (or 0 if glyphidarray isn't needed to find a mapping)

			for($i=0;$i<$segCount;$i++) {
				$filepointers[] = ftell($fh);
				$idRangeOffset[] = FileRead::read_USHORT($fh); }
			$cmapformat4->idRangeOffset = $idRangeOffset;
			$cmapformat4->filepointers = $filepointers;
			
			return $cmapformat4;
		}
		
		function toString() {
			return "{format ".$this->format.
						" cmap table. length: ".$this->length.
						", segCount: ".$this->segCount.
						"}"; }
	}

	/**
	 * "struct" class for the cmap format 12 table data
	 * Typically used for UCS-4
	 */
	class CMAPFormat12
	{
		var $length;
		var $language;
		var $nGroups;
		var $groups;
		
		/**
		 * create a cmap format 4 struct based on the data at the filepointer
		 */
		static function createCMAPFormat12(&$fh)
		{
			$cmapformat12 = new CMAPFormat12();
			FileRead::read_USHORT($fh);						// reserved for... who knows
			$cmapformat12->length = FileRead::read_ULONG($fh);
			$cmapformat12->language = FileRead::read_ULONG($fh);
			$cmapformat12->nGroups = FileRead::read_ULONG($fh);
			
			$groups = array();
			for($n=0; $n<$cmapformat12->nGroups; $n++) {
				$startCharCode = FileRead::read_ULONG($fh);
				$endCharCode = FileRead::read_ULONG($fh);
				$startGlyphID = FileRead::read_ULONG($fh);
				$groups[] = array('startCharCode' => $startCharCode,
							'endCharCode' => $endCharCode,
							'startGlyphID' => $startGlyphID); }
			$cmapformat12->groups = $groups;

			return $cmapformat12;
		}
		
		function toString() {
			return "{format ".$this->format.
						" cmap table. length: ".$this->length.
						", segCount: ".$this->segCount.
						"}"; }
	}
?>
