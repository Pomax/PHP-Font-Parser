<?php
	//UTF: ☺

	/*
		This is a class representation for parsing, not creating. That means tables are not fully parsed
		until they are necessary, and the CMAP and glyph tables are never stored in memory, but
		are only referenced by following the pointer instructions that are stored in the tables
		relevant to glyph retrieval.

		This has a benefit that the memory footprint is small, but the disadvantage that processing
		is not as fast as when the font would be properly turned into an object representation and
		then flown through at the speed of RAM.

		Future versions might be rewritten to memory-map a font, so that pointer based reading
		is faster than it is now. However, this may also make PHP run into the memory limit when
		particularly large CJK fonts are loaded. The Hanazono font, for instance, is not an unusually
		large font and takes up 23 MB. This is almost double the size of the standard 12MB that
		a typical PHP installation is set up to use.

		Currently, only the "Unicode BMP (UCS-2)" and "Unicode UCS-4" are searched for character
		implentations. If the script claims "not supported", that really means "not supported by this
		parser, but not necessarily not supported by this font".

		@author: Michiel "Pomax" Kamermans
		@contact: pomax の nihongoresources 。com
		@version: 1.0
		@date: 2011.06.24
	*/

// ----------------------------------------------------

	/**
	 * Object class for standard OpenType/TrueType fonts
	 * loaded from font file (rather than memory)
	 */
	class OTTTFont
	{
		// debug
		var $debug = false;
		function setDebug($debug) { $this->debug=$debug; }
		function log($string) {
			if($this->debug===true) {
				$fh=fopen('debug.log','a');
				fwrite($fh, $string."\n");
				fclose($fh); }}

		// script base locations, in a place where everyone can find them
		public static $OTFlocation;
		public static $TTFlocation;
		public static $CFFlocation;
		public static $GDlocation;
		public static $FONTlocation;

		// crucial data
		var $fontfilelocation;
		var $fontfilename;
		var $version;
		var $number_of_tables;
		var $search_range;
		var $entry_selector;
		var $range_shift;
		var $tables = array();

		// should this font cache glyphs?
		var $cachechars = true;

		// glyph index caching
		var $indexcache = array();

    // glyph data caching
		var $glyphcache = array();


		// if the font has a filler glyph, in addition to mapping nonexistent glyphs to index 0,
		// this is the hash code for that glyph, used in the get_glyph functions. I do not
		// know what posesses foundries to use a filler glyph, but goddamnit do I hate them.
		// The whole idea is to give glyph 0 some kind of "not found" outline, and use that
		// exclusively. Production fonts should NOT come with placeholder glyphs.
		var $filler_hash = "";
		function set_filler_hash($hash) { $this->filler_hash=$hash; }

		// table loader
		var $OTFTableLoader = "";
		function getOTFTableLoader() {
			if($this->OTFTableLoader=="") {
				$this->OTFTableLoader = new OTFTableLoader($this); }
			return $this->OTFTableLoader; }

		/**
		 * The constructor loads part of the file into memory. It does not do any
		 * validation, so it assumes an OTF file and if it isn't, you may get
		 * very unexpected results.
		 */
		function __construct($filename, $cache=true)
		{
			require_once(OTTTFONT::$OTFlocation . "tables.php");

      $this->cachechars = $cache;
			$this->fontfilename=$filename;
			$this->fontfilelocation=OTTTFONT::$FONTlocation . $filename;
			$fh = $this->open();
			$this->version = fread($fh,4);

			// TrueType collection?
			if($this->version == "ttcf") { trigger_error("Tried to load a TrueType Collection as if it was a single font.\n", E_USER_ERROR); }

			// read in the sfnt header
			$this->number_of_tables = FileRead::read_USHORT($fh);
			$this->search_range = FileRead::read_USHORT($fh);
			$this->entry_selector = FileRead::read_USHORT($fh);
			$this->range_shift = FileRead::read_USHORT($fh);

			// read in the table declarations
			for($t=0; $t<$this->number_of_tables; $t++) {
				$tag = fread($fh,4);
				$checkSum = FileRead::read_ULONG($fh);
				$offset = FileRead::read_ULONG($fh);
				$length = FileRead::read_ULONG($fh);
				$this->tables[$tag] = new FontTable($tag,$checkSum,$offset,$length); }

			fclose($fh);
		}

		// cache a character for this font
		function cache_glyph($char, $data) {
		  if($this->cachechars) {
		    $this->glyphcache[$char] = $data; }}

		// TTF fonts have - at least up until April 2010 - a bytecode version number corresponding to
		// [0x00 0x01 0x00 0x00]. To my understanding, this is not byte-order-sensitive.
		function is_TTF() { return ($this->version== chr(0).chr(1).chr(0).chr(0)); }

		// CFF fonts have the ascii string "OTTO" as version "number"
		function is_CFF() { return ($this->version=="OTTO"); }

		/**
		 * This is not a full representation of the font, such as one output by TTX,
		 * but a short string representation of the OTF master header
		 */
		function toString() {
			$tables = "";
			foreach($this->tables as $table) { $tables .= $table->toString()."\n"; }
			return "[HEADER]\n\n".
				"{version: ".$this->version.
				", number of tables: ".$this->number_of_tables.
				", search range: ".$this->search_range.
				", entry selector: ".$this->entry_selector.
				", range shift: ".$this->range_shift.
				"}\n\n".
				"[TABLES]\n\n".
				$tables; }

// ------------------- actual functions ------------------

		// set up a filepointer for this font
		function open() {
			if(!file_exists($this->fontfilelocation)) {
				echo "Font [".$this->fontfilelocation."] does not exist. Exiting";
				exit(-1); }
			return fopen($this->fontfilelocation, 'r'); }

		/**
		 * check if this character is supported by seeing if has a
		 * glyph index, and if it does, whether it's not 0 (=notdef)
		 */
		function supports($character)
		{
			// get index. This will also implicitly cache the glyph data,
			// so subsequent get_glyph calls will retrieved the cached
			// data rather than searching the font again.
			$index = $this->get_index($character);
			return ($index !== false && $index>0);
		}

		/**
		 * Get the glyph data for a particular character
		 */
		function get_glyph($char)
		{
			require_once(OTTTFont::$GDlocation . "glyphfetcher.php");
			// get index. This will also implicitly cache the glyph data,
			// so subsequent get_glyph calls will retrieved the cached
			// data rather than searching the font again.
			$index = $this->get_index($char);
			if($index===false) return false;
			return GlyphFetcher::get_glyph($this, $char, $index);
		}

		/**
		 * Get the glyph data in JSON form for a particular character
		 */
		function get_glyph_JSON($char)
		{
			$glyphdata = $this->get_glyph($char);
			if($glyphdata===false) { return false; }
			return $glyphdata->toJSON();
		}

		/**
		 * Get the em quad size for this font
		 */
		function get_quad_size()
		{
      $head =& $this->getOTFTableLoader()->get_head_table($this);
      return $head->unitsPerEm;
		}

		/**
		 * consult the character map ("cmap" table) to see if the character's
		 * code point maps to glyph '0' (=unknown character) or something not 0
		 * (meaning it's supported by the font). This check is currently done ONLY
		 * for the 'Unicode BMP (UCS-2)' and 'Unicode UCS-4' subtables. Generally,
		 * this should be good enough, but you might have an exotic font for which
		 * this script will result in a "not supported" verdict, even though technically
		 * the character you are looking for is in there.
		 */
		function get_index($character)
		{
			// previously cached?
			if(isset($this->indexcache[$character])) { return $this->indexcache[$character]; }

			// if not, consult the character map
			$fh = $this->open();
			$cmap = $this->tables['cmap'];
			fseek($fh,$cmap->offset); // forward the pointer to the cmap table
			$version = FileRead::read_USHORT($fh);
			$numTables = FileRead::read_USHORT($fh);

			// get the list of available subtables
			$subtables = array();
			for($n=0; $n<$numTables; $n++) {
				$platformID = FileRead::read_USHORT($fh);
				$encodingID = FileRead::read_USHORT($fh);
				$offset = FileRead::read_ULONG($fh);
				$subtables[FontSubTable::$encodings[$encodingID]] = new FontSubTable($platformID, $encodingID, $offset); }

			// $c is used here mostly for cosmetic reasons, because it tells us the hex code for
			// the character in question, making it easy to look up in, for instance, BabelMap.
			$c = uniord($character);

			// check the 'Unicode BMP (UCS-2)' subtable.
			if(isset($subtables['Unicode BMP (UCS-2)'])) {
				$this->log("Font contains the 'Unicode BMP (UCS-2)' subtable. Searching for $character (hex: ".strtoupper(dechex($c)).", dec: $c)");

				$subtable =& $subtables['Unicode BMP (UCS-2)'];
				rewind($fh);
				fseek($fh, $cmap->offset + $subtable->offset);
				$format = FileRead::read_USHORT($fh);

				// and for now, we only look at the subtable if it uses format 4
				if($format == 4) {
					$val = $this->contains_format4($character, $fh);
					if($val !== false) {
						fclose($fh);
						// cache result
						$this->indexcache[$character] = $val;
						return $val; }}}

			// also check the 'Unicode UCS-4' subtable, if the previous subtable didn't yield a result
			if(isset($subtables['Unicode UCS-4'])) {
				$this->log("Font contains the 'Unicode UCS-4' subtable. Searching for $character (hex: ".strtoupper(dechex($c)).", dec: $c)");

				$subtable =& $subtables['Unicode UCS-4'];
				rewind($fh);
				fseek($fh, $cmap->offset + $subtable->offset);
				$format = FileRead::read_USHORT($fh);

				// a UCS-4 subtable pretty much has to be format 12. It is similar to format 4, but simpler
				if($format == 12) {
					$val = $this->contains_format12($character, $fh);
					if($val !== false) {
						fclose($fh);
						// cache result
						$this->indexcache[$character] = $val;
						return $val; }}}

			// when we get here, our algorithm will not have found the character in the font.
			// This does NOT mean the character isn't in the font, just that it's not in the
			// UCS-2 and UCS-4 subtables.

			// Before returning, we cache the fact that this character could not be found,
			// so that subsequent checks for this character (during the run of the script)
			// will immediately fail.
			$this->indexcache[$character] = $val;
			return false;
		}

		/**
		 * Format 4 is the "segment mapping to delta value" table format. It's relatively
		 * straight-forward, except when the glyph map needs to be consulted, in which case
		 * it pulls off a crazy pointer arithmetic trick.
		 */
		private function contains_format4($character, &$fh)
		{
			$cmapformat4 = CMAPFormat4::createCMAPFormat4($fh);

			// from this point until the start of the next table, every USHORT is an entry in the GlyphIdArray
			$mark = ftell($fh);

			// characters are stored based on their unicode number.
			$c = uniord($character);

			// start looking for the segment of the table that our character should be in, which we will call "i".
			$i=0;
			while($i<$cmapformat4->segCount && $cmapformat4->endCount[$i]<$c) { $i++; }

			// if the character was not found in any segment...
			if($i>=$cmapformat4->segCount) {
				$this->log("No segment found containing this glyph.");
				return false; }

			$this->log("Glyph $c should be in segment ".($i+1)." (segment range is ".
						"[".$cmapformat4->startCount[$i]."-".$cmapformat4->endCount[$i]."], delta ".
						$cmapformat4->idDelta[$i].", offset ".$cmapformat4->idRangeOffset[$i]."...");

			// if the following conditional succeeds, our character has a mapping stored implicitly for this
			// segment. However, if that mapping is 0, the character itself is not supported (0 mapping to
			// the NOTDEF glyph.
			if($cmapformat4->startCount[$i]<=$c)
			{
				$this->log("Valid segment range, checking whether we need to consult the glyphIdArray...");
				$found = false;
				$index = 0;
				if($cmapformat4->idRangeOffset[$i]!=0)
				{
					$this->log("We do. Computing array index and consulting glyphIdArray...");

					/*
						This is where things get a bit mad...

						If the idRangeOffset value for the segment is not 0, the mapping of character codes relies on glyphIdArray.
						The character code offset from startCode is added to the idRangeOffset value. This sum is used as an
						offset from the current location within idRangeOffset itself to index out the correct glyphIdArray value.
						This obscure indexing trick works because glyphIdArray immediately follows idRangeOffset in the font file.

						The C expression that yields the glyph index, according to Microsoft, is:

							*(idRangeOffset[i]/2 + (c - startCount[i]) + &idRangeOffset[i])

						Try as I might, I couldn't make that do the right thing. Luckily, an alternative function is provided by
						https://developer.apple.com/fonts/TTRefMan/RM06/Chap6cmap.html:

							glyphindex = idRangeOffset[i] + 2 * (c - startCode[i]) + (Ptr) &idRangeOffset[i]

						This function seems to work quite well. I don't quite understand why, but I'm not going to question something
						that works.
					*/

					$pointer = $cmapformat4->idRangeOffset[$i] + 2 * ($c - $cmapformat4->startCount[$i]) + $cmapformat4->filepointers[$i];
					
					// zero index?
					if($pointer==0) {
						$this->log("Glyph index was 0, which means it maps to NOTDEF.");
						return false; }

					// if not 0, find the mapped index value.
					$this->log("Glyph index was $pointer, checking what the corresponding mapping is.");
					rewind($fh);
					fseek($fh, $pointer);
					$this->log("Set pointer to ".ftell($fh). " (0x".strtoupper(dechex(ftell($fh))).")");

					// now... again, if this value is zero, the glyph is not actually supported by the font.
					$mapping = FileRead::read_USHORT($fh);
					$this->log("Glyph maps to $mapping.");
					$found = ($mapping!=0);
					$this->log("This font " . ($found ? "supports": "does not support") . " this glyph.");

					// and then the final bit of crazy: if the mapping was not zero, the character's
					// actual index in the glyph data table is [mapping + delta (modulo 2^16)].
					$index = ($cmapformat4->idDelta[$i] + $mapping + 65536) % 65536;
				}

				// Of course, there's also the possibility that idRangeffset is 0. If that's the case, things
				// are a tremendous amount easier, because we can compute the mapping directly.
				else
				{
					$this->log("We don't. Computing direct mapping...");
					// again, the glyph data table index for this glyph is computed modulo 2^16
					$index = ($cmapformat4->idDelta[$i] + $c + 65536) % 65536;
					$this->log("Glyph maps to $index.");
					$found = ($index!=0);
					$this->log("This font " . ($found ? "supports": "does not support") . " this glyph.");
				}

				// if everything checks out, we signal success by returning the glyph's index
				return $index;
			}
			// and one final possibility for definitely not supporting a glyph:
			$this->log("First segment start is already higher than our character's value. That means this font does not support this glyph.");
			return false;
		}

		/*
			Format 12 is a bit like format 4, but rather than providing mappings for UCS-2 characters,
			it provides mappings for characters that don't fit in UCS-2, but do fit in UCS-4. Thankfully
			the table layout doesn't rely on an idRangeOffset, so no crazy math in this table lookup.
		*/
		private function contains_format12($character, &$fh)
		{
			// also note that in a format 12 subtable, every USHORT is an entry in the GlyphIdArray
			$cmapformat12 = CMAPFormat12::createCMAPFormat12($fh);
			$mark = ftell($fh);

			// lookup is based on the unicode number again.
			$c = uniord($character);

			// start looking for the segment our character should be in, which we'll call "i" again.
			for($i=0; $i<$cmapformat12->nGroups; $i++)
			{
				$startCharCode =& $cmapformat12->groups[$i]["startCharCode"];
				$endCharCode =& $cmapformat12->groups[$i]["endCharCode"];

				// did we find a segment containing our character?
				if($startCharCode<=$c && $c<=$endCharCode) {
					$startGlyphID = $cmapformat12->groups[$i]["startGlyphID"];
					$diff = $c - $startCharCode;
					return ($startGlyphID + $diff); }

				// if we didn't, then we should stop looking if the next segments are
				// guaranteed not to contain it.
				elseif($startCharCode>$c) { break; }
			}
			return false;
		}
	}
?>
