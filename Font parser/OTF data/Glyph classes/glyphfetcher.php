<?php
	//UTF: ☺

	/**
	 * This class is responsible for getting outline data from a font. This could have been
	 * placed inside the OTTTFont class, but it's a lot of code for basically doing two things
	 * (find glyph for TTF, and find glyph for CFF). Putting it in its own static class made
	 * things much more readable.
	 *
	 * The only public function is get_glyph, which returns a Glyph object. In the rare case
	 * that the glyph is just a placeholder glyph, having a non-0 index but a trivial outline, this
	 * function will return "false", provided the font knows what the hash value for this font\s
	 * "trivial outline" is, based on what the user indicated during setup
	 */
	class GlyphFetcher
	{
		static $charstringindices = array();
		private static $NOTSET = -9999;

		/**
		 * Before we get the glyph data, split on outline: TTF or CFF font?
		 */
		static function get_glyph($font, $char, $index)
		{
			// require base glyphdata class
			require_once(OTTTFont::$GDlocation . "glyph.php");
			$glyph = "";
			if($font->version=="OTTO") { $glyph = GlyphFetcher::get_CFF_glyph($font, $char, $index); }
			else { $glyph = GlyphFetcher::get_TTF_glyph($font, $char, $index); }
			if($glyph === false) return false;

			// check whether this is a filler glyph
			if($glyph->hash === $font->filler_hash) { return false; }

			// if we have a glyph make sure to set its administrative values
			$glyph->font = $font->fontfilename;
			$glyph->type = ($font->version=="OTTO" ? "CFF" : "TTF");
			$glyph->quadsize = $font->get_quad_size();
			$glyph->index = $index;
			$glyph->glyph = $char;
			return $glyph;
		}

		/**
		 * Get the glyph data from an OTF font with CFF content.
		 * This uses a hell of a lot of tables, in a marvelously interwoven manner.
		 */
		private static function get_CFF_glyph($font, $char, $index)
		{
			$data = false;

			// step zero: does this char exist?
			if($index == GlyphFetcher::$NOTSET) { $index = $font->get_index($char); }

			// how about now?
			if($index===false) {
				// nope, no index. This character is not supported by this font.
				return false; }

			// step one: the earlier call to get_index() may have called get_data()
			// with an actual index, so is that data cached now?
			if(isset($font->glyphcache[$char])) { return $font->glyphcache[$char]; }

			// okay we're still not there, but we have an index. Load up the CFF parser and get to work
			require_once(OTTTFont::$CFFlocation . "CFFBlockClasses.php");

			// navigate to the CFF table
			$fh = $font->open();
			$cff =& $font->tables['CFF '];
			$offset = $cff->offset;
			fseek($fh, $offset);

			// read header
			$version_major = FileRead::read_BYTE($fh);
			$version_minor = FileRead::read_BYTE($fh);
			$hdrSize = FileRead::read_BYTE($fh);
			$offSize = FileRead::read_BYTE($fh);

			// read in the name index
			rewind($fh);
			fseek($fh, $offset + $hdrSize);
			$nameindex = CFFIndex::create_index($fh);
			// echo "Name INDEX:\n" . $nameindex->toString() . "\n---\n";

			// read in the top dict index
			rewind($fh);
			fseek($fh, $nameindex->nexttable);
			$topdictindex = CFFTopDictIndex::create_index($fh);
			// echo "Top DICT INDEX:\n" . $topdictindex->toString() . "\n---\n";

			// read in the string index (FIXME: resolving strings is rather cumbersome, and not working a.t.m.)
			rewind($fh);
			fseek($fh, $topdictindex->nexttable);
			$stringindex =  CFFStringIndex::create_index($fh);
			// echo "String INDEX:\n" . $stringindex->toString() . "\n---\n";

			// read in the global subroutine index. global subroutines can be referenced by any glyph
			rewind($fh);
			fseek($fh, $stringindex->nexttable);
			$globalsubindex =  CFFIndex::create_index($fh);
			// echo "Global Subr INDEX:\n" . $globalsubindex->toString() . "\n---\n";

			// move to the next table
			rewind($fh);
			fseek($fh, $globalsubindex->nexttable);

			// Is there a charset that we might find our character in?
			if($topdictindex->charset!=0)
			{
				// find the fontdict that we need to look at in order to find this character's
				// description. we do this by accessing the "FDarray" offset
				if($topdictindex->is_CIDFont())
				{
					$mark = ftell($fh);

					// is there a cache entry for the "charstringindex" that contains the subroutines used by
					// the fontdict datastructure that this character is found in?
					$charstringindex = "";
					if(isset(GlyphFetcher::$charstringindices[$index])) { $charstringindex = GlyphFetcher::$charstringindices[$index]; }
					// if not yet, build it from the font data and then cache it for future reference.
					else {
						rewind($fh);
						fseek($fh, $cff->offset + $topdictindex->CharStrings);
						$charstringsindex = CFFIndex::create_index($fh);
						$charstringindices[$index] = $charstringindex; }
					// echo "Char Strings INDEX:\n" . $charstringsindex->toString() . "\n---\n";

					rewind($fh);
					fseek($fh, $cff->offset + $topdictindex->FDArray);
					$fontdictindex = CFFFontDictIndex::create_index($fh, $cff->offset, $topdictindex->CharstringType);
					// echo "Font DICT INDEX:\n" . $fontdictindex->toString() . "\n---\n";

					// resolve the FDSelect field, so that we can figure out which fontdict to look in
					// (which will also tell us where we can find subroutines used only in this fontdict)
					rewind($fh);
					fseek($fh, $cff->offset + $topdictindex->FDSelect);
					$fdselectindex = new FDSelectIndex($fh);
					$fd = $fdselectindex->get_FD($index);

					// now that we have the correct fontdict, we can properly parse the type2 charstring for this glyph
					$charstringdata = $charstringsindex->get_data($fh, $index);
					$fontdict = $fontdictindex->get_font_dict($fd);

					// the following line is a debug comment, but a fairly important one. glyphs have a default width,
					// which can be overriden by an instruction at the start of the glyph's outline charstring. In order
					// for this to yield a sensible metric, the nominal width has to have been correctly computable.
					//
					//echo "nominal width: " . $fontdict->privatedict->nominalWidthX . ", default width: " . $fontdict->privatedict->defaultWidthX . "\n";

					// grab the data, and make sure the width metric is corrected.
					$data = new Type2GlyphData($fh, $charstringdata, $fontdict, $globalsubindex);
					if($data->differenceWithNominalWidthX!=0) {
						//echo "width = " . $fontdict->privatedict->nominalWidthX . " + " .$data->differenceWithNominalWidthX . "\n";
						$data->width = $fontdict->privatedict->nominalWidthX + $data->differenceWithNominalWidthX; }
					else { $data->width = $fontdict->privatedict->defaultWidthX; }
				}

				else
				{
					// for non-CID fonts, the top dict acts as font dict.
					$topdictindex->load_private_dict($fh, $offset, $topdictindex->CharstringType);

					// get the charstring data block
					rewind($fh);
					fseek($fh, $cff->offset + $topdictindex->CharStrings);
					$charstringsindex = CFFIndex::create_index($fh);
					//echo "CHARSTRING INDEX:\n" . $charstringsindex->toString() . "\n---\n";
					$charstringdata = $charstringsindex->get_data($fh,$index);

					// and then resolve the Type2 charstring to glyph outline data
					$data = new Type2GlyphData($fh, $charstringdata, $topdictindex, $globalsubindex);
				}
			}
			else { die("ERROR: no charset information could be found in this font.\n"); }

			// construct a new Glyph object, cache it, and then return it
			$ret = new Glyph();
			$ret->hash = $data->glyphdata->hash;
			$ret->glyphrules = $data->glyphdata;
			$ret->width = $data->width;
			$ret->computeBounds();
			$ret->bounds["width"] = $ret->bounds["maxx"] - $ret->bounds["minx"];
			$ret->bounds["height"] = $ret->bounds["maxy"] - $ret->bounds["miny"];

			// FIXME: this might not be the correct way to determine LSB
			$ret->lsb= $ret->bounds["minx"];
			$ret->rsb = $data->width - ($ret->bounds["maxx"] - $ret->bounds["minx"] + $ret->lsb);

			// set the height based on the bounding box.
			// FIXME: this should really be the vertical metric value instead.
			$ret->height = $ret->bounds["maxy"] - $ret->bounds["miny"];
			$font->cache_glyph($char, $ret);
			return $ret;
		}


		/**
		 * Get the glyph data from an OTF font with TTF content.
		 * This uses the "loca" and "glyf" tables. That's all it uses.
		 */
		private static function get_TTF_glyph($font, $char, $index)
		{
			$data = GlyphFetcher::get_TTF_glyph_for_index($font, $char, $index);

			// FIXME: find out how we can get here, code path wise, when data is false!
			if($data===false) { return false; }

			// construct Glyph object and return
			$ret = new Glyph();
			$ret->hash = $data->glyphdata->hash;
			$ret->index = $index;
			$ret->font = $font->fontfilelocation;
			$ret->height = $data->yMax - $data->yMin;
			$ret->bounds["minx"] = $data->xMin;
			$ret->bounds["miny"] = $data->yMin;
			$ret->bounds["maxx"] = $data->xMax;
			$ret->bounds["maxy"] = $data->yMax;
			$ret->bounds["width"] = $data->xMax - $data->xMin;
			$ret->bounds["height"] = $data->yMax - $data->yMin;
			$ret->glyphrules = $data->glyphdata;

			$hmtx =& $font->getOTFTableLoader()->get_hmtx_table($font);
			// if the index is greater than the number of hmetric entries,
			// it has the same hmetrics as the last entry in the table.
			// (see http://www.microsoft.com/typography/otspec/hmtx.htm)
			if($index >= count($hmtx->hMetrics)) { $index = count($hmtx->hMetrics) - 1; }
			$hmetric = $hmtx->hMetrics[$index];

			$ret->lsb= $hmetric["lsb"];
			$ret->width = $hmetric["advanceWidth"];
			// alternative width
			$ret->rsb = $ret->width - ($ret->lsb + $ret->bounds["width"]);

			$font->cache_glyph($char, $ret);
			return $ret;
		}

		// the "matrix" variable represents an x/y offset as 2x3 transformation matrix
		private static function get_TTF_glyph_for_index($font, $char, $index, $matrix = array(0,0, 1,0,0,1))
		{
//			echo "glyph index: $index\n";

			// step zero: does this char exist?
			if($index == GlyphFetcher::$NOTSET) { $index = $font->get_index($char); }
			if($index===false) { return false; }

			// step one: get_index may have called get_data with an actual index, so is the data for it cached now?
			if(isset($font->glyphcache[$char])) { return $font->glyphcache[$char]; }

			// there was no cache yet. Perform the real lookup.
			require_once(OTTTFONT::$TTFlocation . "ttfglyphdata.php");
			$font->log("This is a TTF font. consulting 'index to location' table.");

			$fh = $font->open();
			$head =& $font->getOTFTableLoader()->get_head_table($font);
			$indexToLocFormat = $head->indexToLocFormat;	// tells us whether the 'loca' table uses USHORT or ULONG data fields

			// "Index to Location" table, which will tell us where in the "glyf" table we can find the glyph
			$loca =& $font->tables['loca'];

			// "Glyph Data" table, which should give us the glyph's actual outline data (if there is any)
			$glyf =& $font->tables['glyf'];

			// navigate to position $index
			$glyphpointer = 0;
			$next = 0;
			if($indexToLocFormat==0) {
				// USHORT entries = seek based on 2 byte jumps (since a USHORT is 16 bit)
				$offset = $loca->offset + $index*2;
				rewind($fh);
				fseek($fh,$offset);
				// pointer values are stored as half of what they really are in the short table
				$glyphpointer = 2 * FileRead::read_USHORT($fh);
				$next = 2 * FileRead::read_USHORT($fh); }

			elseif($indexToLocFormat==1) {
				// ULONG entries = seek based on 4 byte jumps (since a ULONG is 32 bit)
				$step = $index*4;
				$offset = $loca->offset + $step;
				rewind($fh);
				fseek($fh,$offset);
				// pointer values are stored normally in the long table
				$glyphpointer = FileRead::read_ULONG($fh);
				$next = FileRead::read_ULONG($fh); }

			// if the two pointer values are the same, the glyph has no outline
			// (there are a number of invisible characters, mostly spacers).
			$empty =false;
			if($glyphpointer == $next) {
				$empty = true;
				$font->log("glyph has no outline data in ".$font->fontfilelocation); }

			$data = new TTFGlyphData();

			// if there is no outline data, we need to fill in the zero-valued metrics
			if($empty)
			{
				$data->xMin = 0;
				$data->yMin = 0;
				$data->xMax = 0;
				$data->yMax = 0;
				$data->height = 0;
				require_once(OTTTFont::$GDlocation . "glyphrules.php");
				$data->glyphdata = new Type2GlyphRules();
			}

			// if there is outline data, move over to the glyph table
			else
			{
				rewind($fh);
				$offset = $glyf->offset + $glyphpointer;
				$fs = filesize($font->fontfilelocation);
				if($offset > $fs) {
					echo "ERROR: tried to move the pointer ".($offset-$fs)." bytes beyond the end of file!\n";
					return false; }
				fseek($fh, $offset);

				// read glyph data (see http://www.microsoft.com/typography/otspec/glyf.htm)
				$data->unitsPerEm = $head->unitsPerEm;

				$numberOfContours = FileRead::read_SHORT($fh);	// If the number of contours is greater than zero, this is a single glyph;
													// if negative, this is a composite glyph.
				$xMin = FileRead::read_SHORT($fh);			// Minimum x for coordinate data.
				$yMin = FileRead::read_SHORT($fh);			// Minimum y for coordinate data.
				$xMax = FileRead::read_SHORT($fh);			// Maximum x for coordinate data.
				$yMax = FileRead::read_SHORT($fh);			// Maximum y for coordinate data.
				$width = $xMax-$xMin;
				$height = $yMax-$yMin;

				$data->numberOfContours = $numberOfContours;
				$data->xMin = $xMin;
				$data->yMin = $yMin;
				$data->xMax = $xMax;
				$data->yMax = $yMax;
				$data->width = $width;
				$data->height = $height;

				$font->log("glyph has $numberOfContours contours (${width}x$height), x/y min/max: ($xMin,$yMin,$xMax,$yMax) in ".$font->fontfilelocation);

				// simple glyph
				if($numberOfContours>=0)
				{
					// first things first: if there is only one contour point, and it's really small, it's probably not actually a glyph
					if($numberOfContours==1 && $width<120 && $height<120) { return false; }

					// read the glyph data
					$endPtsOfContours = array();
					for($i=0; $i<$numberOfContours; $i++) { $endPtsOfContours[] = FileRead::read_USHORT($fh); }
					$data->endPtsOfContours=$endPtsOfContours;

					// it's much easier to also have access to contour startpoints, rather than just endpoints
					$startPtsOfContours = array(0);
					for($i=0; $i<count($endPtsOfContours)-1; $i++) {
						$startPtsOfContours[]=$endPtsOfContours[$i]+1; }
					$data->startPtsOfContours = $startPtsOfContours;

					// get instructions
					$instructionLength = FileRead::read_USHORT($fh);
					$data->instructionLength=$instructionLength;

					$instructions = array();
					for($i=0; $i<$instructionLength; $i++) { $instructions[] = FileRead::read_BYTE($fh); }
					$data->instructions=$instructions;

					// get the coordinate information
					$count = $endPtsOfContours[$numberOfContours-1] + 1;

					// get all the coordinate flags (code based on Apache's batik java code)
					$data->flags = array();
					for ($flag = 0; $flag < $count; $flag++) {
						$data->flags[$flag] = FileRead::read_BYTE($fh);
						if ($data->flag_repeats($flag)) {
							$repeats = FileRead::read_BYTE($fh);
							for ($i = 1; $i <= $repeats; $i++) { $data->flags[$flag + $i] = $data->flags[$flag]; }
							$flag += $repeats; }}

					// x-coordinates (relative, code based on Apache's batik java code)
					$xCoordinates = array();
					for ($i = 0; $i < $count; $i++) {
						$x = 0;
						$xShort = $data->x_is_byte($i);
						$xDual = $data->x_dual_set($i);
						// If x-Short Vector is set, xDual describes the sign of the value, with 1 equalling positive and 0 negative.
						if($xShort) {
							if($xDual) { $x += FileRead::read_BYTE($fh); }
							else { $x -= FileRead::read_BYTE($fh); }}
						// If the x-Short Vector bit is not set and the xDual bit is set, then the current x-coordinate is the same as the previous x-coordinate.
						// If the x-Short Vector bit is not set and the xDual bit is also not set, the current x-coordinate is a signed 16-bit delta vector.
						elseif( !$xDual) { $x += FileRead::read_SHORT($fh); }
						// correct for offset
						$xCoordinates[$i] = $x - $xMin; }

					// y-coordinates (relative, code based on Apache's batik java code)
					$yCoordinates = array();
					for ($i = 0; $i < $count; $i++) {
						$y = 0;
						$yShort = $data->y_is_byte($i);
						$yDual = $data->y_dual_set($i);
						// If y-Short Vector is set, yDual describes the sign of the value, with 1 equalling positive and 0 negative.
						if($yShort) {
							if($yDual) { $y += FileRead::read_BYTE($fh); }
							else { $y -= FileRead::read_BYTE($fh); }}
						// If the y-Short Vector bit is not set and the yDual bit is set, then the current x-coordinate is the same as the previous x-coordinate.
						// If the y-Short Vector bit is not set and the yDual bit is also not set, the current x-coordinate is a signed 16-bit delta vector.
						elseif( !$yDual) { $y += FileRead::read_SHORT($fh); }
						// correct for offset and flip y coordinate
						$yCoordinates[$i] = $y - $yMin; }

					// bind data and form a glyphrules object
					$data->xCoordinates=$xCoordinates;
					$data->yCoordinates=$yCoordinates;
					$data->formGlyphRules($matrix);
				}

				// composite glyph (numcontour == -1)
				else
				{
					$ARG_1_AND_2_ARE_WORDS = 1;			// If this is set, the arguments are words; otherwise, they are bytes.
					$ARGS_ARE_XY_VALUES = 2;			// If this is set, the arguments are xy values; otherwise, they are points.
					$ROUND_XY_TO_GRID = 4;				// For the xy values if the preceding is true.
					$WE_HAVE_A_SCALE = 8;				// This indicates that there is a simple scale for the component. Otherwise, scale = 1.0.
					$MORE_COMPONENTS = 32;				// Indicates at least one more glyph after this one.
					$WE_HAVE_AN_X_AND_Y_SCALE = 64;		// The x direction will use a different scale from the y direction.
					$WE_HAVE_A_TWO_BY_TWO = 128;			// There is a 2 by 2 transformation that will be used to scale the component.
					$WE_HAVE_INSTRUCTIONS = 256;			// Following the last component are instructions for the composite character.
					$USE_MY_METRICS = 512;				// If set, this forces the aw and lsb (and rsb) for the composite to be equal to those from this original glyph.

					$old_xoffset = 0;
					$old_yoffset = 0;
					$old_xdiff = 0;
					$old_ydiff = 0;
					$last = false;
					$current = false;
					$flags = "";
					do
					{
						$flags = FileRead::read_USHORT($fh);
						$glyphIndex = FileRead::read_USHORT($fh);
						$arg1="";
						$arg2="";

						// read in argument 1 and 2
						if(masks($flags, $ARG_1_AND_2_ARE_WORDS)) {
							$arg1 = FileRead::read_SHORT($fh);
							$arg2 = FileRead::read_SHORT($fh); }
						else {
							$arg1 = FileRead::read_BYTE($fh);
							$arg2 = FileRead::read_SBYTE($fh); }

						$xscale = 1;
						$scale01 = 0;
						$scale10 = 0;
						$yscale = 1;

						if(masks($flags, $WE_HAVE_A_SCALE)) {
							$xscale = FileRead::read_F2DOT14($fh);
							$yscale = $xscale; }
						elseif(masks($flags, $WE_HAVE_AN_X_AND_Y_SCALE)) {
							$xscale = FileRead::read_F2DOT14($fh);
							$yscale = FileRead::read_F2DOT14($fh); }
						elseif(masks($flags, $WE_HAVE_A_TWO_BY_TWO)) {
							$xscale = FileRead::read_F2DOT14($fh);
							$scale01 = FileRead::read_F2DOT14($fh);
							$scale10 = FileRead::read_F2DOT14($fh);
							$yscale = FileRead::read_F2DOT14($fh); }

						$xoffset = $arg1;
						$yoffset = $arg2;

						// Merge data: if not masked, the arguments indicate how the glyphs link up:
						// 	- arg1 is the connecting point in the "current" glyph
						// 	- arg2 is the connecting point in the "next" glyph
						// We can use these to derive the x/y offset for the linked glyph
						if(!masks($flags, $ARGS_ARE_XY_VALUES)) {
							// TODO: this has not yet been implemented
							trigger_error("point matching not yet implemented\n"); }

						// push the administrative values through.
						$last = $current;
						$matrix = array($xoffset, $yoffset, $xscale, $scale01, $scale10, $yscale);
						$mark = ftell($fh);
						$current = GlyphFetcher::get_TTF_glyph_for_index($font, $char, $glyphIndex, $matrix);
						$old_xoffset = $xoffset;
						$old_yoffset = $yoffset;
						rewind($fh);
						fseek($fh,$mark);
						$data->merge($current);

					}
					while (masks($flags, $MORE_COMPONENTS));

					// if there are instructions, we read them in but don't do anything with them,
					// because this parser does not process instructions.
					if(masks($flags, $WE_HAVE_INSTRUCTIONS)) {
						$numInstr = FileRead::read_USHORT($fh);
						$bytes = array();
						for($n=0;$n<$numInstr;$n++) { $bytes[] = FileRead::read_BYTE($fh); }}
				}
			}
			fclose($fh);
			return $data;
		}
	}
?>
