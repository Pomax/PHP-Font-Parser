<?php
	//UTF: ☺
	
	/**
	 * File read operations for getting specific data types from an OpenType font file.
	 * Bytes are ordered Big Endian, so we're hampered in a few places where
	 * we need signed values. Since php's unpack() can't do explicit litte/big unpacking
	 * for signed values, we need to read them as unsigned, then transform to signed.
	 */
	class FileRead
	{
		static function read_SOMETHING(&$filehandle, $what,$bytes) {
			$res = unpack($what,fread($filehandle,$bytes));
			return implode('',$res); }

		// unsigned 16 bit integer
		static function read_USHORT(&$filehandle) { return FileRead::read_SOMETHING($filehandle,'n',2); }

		// unsigned 24 bit integer
		static function read_UTRIPLET(&$filehandle) { return (0x010000 * FileRead::read_BYTE($filehandle) + FileRead::read_USHORT($filehandle)); }

		// unsigned 32 bit integer - !!! NOTE THAT THIS DOES NOT EXIST IN PHP !!!
		//
		// php treats all 32 bit integers as signed 31 bit integers. While this should
		// not make a difference under the hood, it means that any printing of
		// values greater than 2^31-1 will be wrong (and show up as a negative value)
		// It also means any filepointer moving should be done in two stages:
		// "is this a negative value? if so, move 2^31, then move -val".
		static function read_ULONG(&$filehandle) { return FileRead::read_SOMETHING($filehandle,'N',4); }

		// wrapper for a USHORT value that represents a number measured in FUnits
		static function read_UFWORD(&$filehandle) {
			return FileRead::read_USHORT($filehandle); }

		// wrapper for a SHORT value that represents a number measured in FUnits
		static function read_FWORD(&$filehandle) {
			return FileRead::read_SHORT($filehandle); }

		// unsigned 8 bit integer
		static function read_BYTE(&$filehandle) { return FileRead::read_SOMETHING($filehandle,'C',1); }

		// signed 8 bit integer
		static function read_SBYTE(&$filehandle) { return FileRead::read_SOMETHING($filehandle,'c',1); }

		// signed 16 bit integer
		static function read_SHORT(&$filehandle) {
			$num = FileRead::read_USHORT($filehandle);
			$limit = pow(2,15);
			if($num>=$limit) { $num = -(2*$limit - $num); } // 2's complement
			return $num; }

		// signed 32 bit integer
		static function read_LONG(&$filehandle) {
			$num = FileRead::read_ULong($filehandle);
			$limit = pow(2,31);
			if($num>=$limit) { $num = -(2*$limit - $num); } // 2's complement
			return $num; }

		// signed 64 bit integer
		static function read_DWORD(&$filehandle) {
			$num =fread($filehandle,8);
			$limit = pow(2,63);
			if($num>=$limit) { $num = -(2*$limit - $num); } // 2's complement
			return $num; }

		// wrapper for DWORD value
		static function read_LONGDATETIME(&$filehandle) {
			return FileRead::read_DWORD($filehandle); }

		// this is kind of an odd data type... the bit layout is 32 bits,
		// which are interpreted as a pair of two unsigned shorts.
		static function read_Fixed(&$filehandle) { return fread($filehandle,4); }
		
		// a 16 bit number, representing a [2.14] decimal fraction
		static function read_F2DOT14(&$filehandle)
		{
			$val = FileRead::read_SHORT($filehandle);
			$twos = ($val  << 2) >> 2;
			// 2's complement means the value right now represents -(pow(2, 14) - $nominator), so convert back:
			$nominator = -(-$twos - 16384);
			// IEEE 14 bit fraction means the divisor is 2^14
			$frac = $nominator / 16384;
			$dec = ($val << 1) >> 1;
			$sign = ($dec !=$val ? -1 : 1);
			$dec = $sign * ($dec  >> 14);
			return ($dec + $frac);
		}
	}
?>
