<?php

/**
 * 
 * The following class borrows most of the toMo() function from https://github.com/josscrowcroft/php.mo/blob/master/php-mo.php
 * The read() function was entirely rewritten because the original didn't seem to work well with our data. All the rest is new.
 * Original license of php.mo is reproduced here after the dashes.
 *
 * ---------------------------------------------------------------------------------------------------------------------------
 * 
 * php.mo 0.1 by Joss Crowcroft (http://www.josscrowcroft.com)
 * 
 * Converts gettext translation '.po' files to binary '.mo' files in PHP.
 * 
 * Usage: 
 * <?php require('php-mo.php'); phpmo_convert( 'input.po', [ 'output.mo' ] ); ?>
 * 
 * NB:
 * - If no $output_file specified, output filename is same as $input_file (but .mo)
 * - Returns true/false for success/failure
 * - No warranty, but if it breaks, please let me know
 * 
 * More info:
 * https://github.com/josscrowcroft/php.mo
 * 
 * Based on php-msgfmt by Matthias Bauer (Copyright © 2007), a command-line PHP tool
 * for converting .po files to .mo.
 * (http://wordpress-soc-2007.googlecode.com/svn/trunk/moeffju/php-msgfmt/msgfmt.php)
 * 
 * License: GPL v3 http://www.opensource.org/licenses/gpl-3.0.html
 */

class PoFileCore
{
	// States for the .po file parser
	const ParserInWhiteSpace = 0;
	const ParserInEntry      = 1;
	const ParserInString   	 = 2;

	// Holds the .po file entries
	private $entries = array();

	public function __construct()
	{
	}

	public static function escape($string)
	{
		return '"'.preg_replace('/\\\*\'/', '\'', preg_replace('/\\\*"/', '\"', $string)).'"';
	}

	public static function unbreaklines($string)
	{
		return str_replace("\n", '\n', $string);
	}

	private static function format($string)
	{
		return static::escape(static::unbreaklines($string));
	}

	public function addMessage($message, $translation, $context = "")
	{
		if($message == "")return;
		$msgid = $message;

		if($context != "")
		{
			$msgid = $context . "\x04" . $msgid;
		}

		$this->entries[$msgid] = array('msgid' => $msgid, 'msgctxt' => $context, 'msgstr' => $translation);
	}

	public function add($msgid, $msgid_plural='', $msgctxt='', $msgstr='')
	{
		if ($msgid === '')
			return;
		$cmsgstr = ($msgctxt !== '') ? $msgctxt."\x04" .$msgid : $msgid;
		
		$entry = array(
			'msgid' => $msgid,
			'msgstr' => $msgstr
		);

		if ($msgctxt !== '')
			$entry['msgctxt'] = $msgctxt;

		if ($msgid_plural !== '')
			$entry['msgid_plural'] = $msgid_plural;

		if ($msgctxt !== '')
			$entry['msgctxt'] = $msgctxt;

		if (is_array($msgstr))
			foreach ($msgstr as $plurality => $str)
				$entry["msgstr[$plurality]"] = $str;

		$this->entries[$cmsgstr] = $entry;
	}

	public function addMessages($messages)
	{
		
		foreach($messages as $key => $value)
		{
			// Message with translation
			if(is_string($key) && is_string($value))
			{
				$this->addMessage($key, $value);
			}
			// Message stored in the key
			else if(is_string($key))
			{
				$this->addMessage($key, "");
			}
			// Message stored in the value
			else if(is_string($value))
			{
				$this->addMessage($value, "");
			}
			// Don't know what to do with this
			else
			{
				throw new Exception("Entry '$message':'$value' does not seem to describe a translation, either/both the key or/and value of the array must be a string.");
			}
		}
	}

	// Transforms "string" into string (removes the quotes) or throws an exception
	public static function unquote($str)
	{
		if($str[0] == '"' && $str[strlen($str)-1] == '"')
		{
			return substr($str, 1, strlen($str)-2);
		}
		else
		{
			throw new Exception("String cannot be unquoted: it is not quoted!");
		}
	}

	function read($filename)
	{
		if(!file_exists($filename))
		{
			throw new Exception("File $filename not found!");
		}

		$hash = array();

		$lines = array_map('trim', explode("\n", file_get_contents($filename)));
		// Add empty line at the end if not present so that the parser correctly writes
		// the last entry!
		if(end($lines) != "")$lines[] = "";

		// Initial state of the parser
		$state = self::ParserInWhiteSpace;

		$current_keyword     = null;
		$current_entry_data  = array('comments' => array());
		$current_string = "";

		$line_number    = 0;

		foreach($lines as $line)
		{
			if(preg_match('/^#~/', $line))
			{
				// We don't handle those kind of deleted lines yet, haven't seen them in the spec
				$line_number += 1;
				continue;
			}

			// Check if we just ended a multiline string
			if($state == self::ParserInString && ($line == "" || $line[0] != '"'))
			{
				$current_entry_data[$current_keyword] = $current_string;
			}

			// Record the current entry
			if($line == "")
			{
				if($state != self::ParserInWhiteSpace) // do nothing if this is not the first whitespace line we encounter
				{
					$state = self::ParserInWhiteSpace;

					if(isset($current_entry_data['msgid']))
					{
						$msgid = $current_entry_data['msgid'];
						if(isset($current_entry_data['msgctxt']))
						{
							$msgid = $current_entry_data['msgctxt'] . "\x04" . $msgid;
						}

						if(isset($hash[$msgid]))
						{
							throw new Exception("Duplicate entry found for msgid '{$current_entry_data['msgid']}' (at line: $line_number)");
						}
						else
						{
							$hash[$msgid] = $current_entry_data;

							// Get a fresh start
							$current_keyword    = null;
							$current_entry_data = array('comments' => array());
							$current_string 	= "";
						}
					}
					else
					{
						//die("<pre>".print_r(end($hash),1)."</pre>");
						throw new Exception("Entry does not have a msgid! (at line: $line_number)");
					}
				}
			}
			// If the line starts with a double quote (") then it can only be a multiline string
			else if($line[0] == '"')
			{
				if($state == self::ParserInString)
				{
					$current_string .= static::unquote($line);
				}
				else
				{
					throw new Exception("Lines beginning with \" can only happen in ParserInString state! (at line: $line_number)");
				}
			}
			// Else there must be a 'keyword' (msgid, #., ...) followed by the data
			else
			{
				$data    = "";
				$splat 	 = preg_split('/\s+/', $line, 2);
				$current_keyword = $splat[0];
				if(count($splat) == 2)
				{
					$data = $splat[1];
				}

				// This is the only thing that we're sure is written in one line
				if($current_keyword == 'msgctxt')
				{
					$state = self::ParserInEntry;
					$current_entry_data[$current_keyword] = $data;
				}
				else if($current_keyword[0] == '#')
				{
					$state = self::ParserInEntry;
					// Comments may appear multiple times
					if(!isset($current_entry_data['comments'][$current_keyword]))
					{
						$current_entry_data['comments'][$current_keyword] = array();
					}
					$current_entry_data['comments'][$current_keyword][] = $data;
				}
				// Data is a (potentially) multiline string, so we'll add it to $current_entry_data
				// on the next whitespace line or keyworded line
				else
				{
					$state = self::ParserInString;
					$current_string = static::unquote($data);
				}
			}

			$line_number += 1;
		}

		$this->entries = $hash;
	}

	public function toMo($filename="")
	{
		$hash = $this->entries;

		// sort by msgid
		ksort($hash, SORT_STRING);
		// our mo file data
		$mo = '';
		// header data
		$offsets = array ();
		$ids = '';
		$strings = '';

		foreach ($hash as $entry)
		{
			$id = $entry['msgid'];
			if(isset ($entry['msgid_plural']))
				$id .= "\x00" . $entry['msgid_plural'];
			// build msgstr array (in case there are plural forms)
			$msgstr = array();
			foreach($entry as $key => $value)
			{
				$m = array();
				if(preg_match('/^msgstr(?:\[(\d+)\])?$/', $key, $m))
				{
					$n = isset($m[1]) ? (int)$m[1] : -1;
					$msgstr[$n] = $value;
				}
			}
			ksort($msgstr);
			// plural msgstrs are NUL-separated
			$str = implode("\x00", $msgstr);
			// keep track of offsets
			$offsets[] = array(
				strlen($ids), strlen($id), strlen($strings), strlen($str)
			);
			// plural msgids are not stored (?)
			$ids 	 .= $id . "\x00";
			$strings .= $str . "\x00";
		}

		// keys start after the header (7 words) + index tables ($#hash * 4 words)
		$key_start = 7 * 4 + sizeof($hash) * 4 * 4;
		// values start right after the keys
		$value_start = $key_start +strlen($ids);
		// first all key offsets, then all value offsets
		$key_offsets = array ();
		$value_offsets = array ();
		// calculate
		foreach($offsets as $v)
		{
			list ($o1, $l1, $o2, $l2) = $v;
			$key_offsets[] = $l1;
			$key_offsets[] = $o1 + $key_start;
			$value_offsets[] = $l2;
			$value_offsets[] = $o2 + $value_start;
		}
		$offsets = array_merge($key_offsets, $value_offsets);

		// write header
		$mo .= pack('Iiiiiii', 	0x950412de					, // magic number
								0 							, // version
								sizeof($hash)				, // number of entries in the catalog
								7 * 4 						, // key index offset
								7 * 4 + sizeof($hash) * 8 	, // value index offset,
								0 							, // hashtable size (unused, thus 0)
								$key_start 					  // hashtable offset
		);
		// offsets
		foreach($offsets as $offset)
		{
			$mo .= pack('i', $offset);
		}
		// ids
		$mo .= $ids;
		// strings
		$mo .= $strings;

		if($filename == "")
		{
			return $mo;
		}
		else
		{
			file_put_contents($filename, $mo);
			return true;
		}
	}

	public function entryToString($entry)
	{
		$string = "\n";

		if(isset($entry['comments']))
		{
			foreach($entry['comments'] as $symbol => $comments)
			{
				foreach($comments as $comment)
				{
					$string .="\n$symbol $comment";
				}
			}
		}

		foreach($entry as $key => $value)
		{
			// Comments are already taken care of at this point
			if($key == 'comments')continue;
			// Only write empty values if they are msgstr's
			if($value == '' && !preg_match('/^msgstr/', $key))continue;
			if($key == 'msgctxt')
			{
				$string .= "\n$key $value";
			}
			else
			{
				$string .= "\n$key " . static::format($value);
			}
		}

		return $string;
	}

	public function __toString()
	{
		$data = "";
		
		if(!isset($this->entries[""]))
		{
			// Write po file header
			$data =   "msgid \"\"\nmsgstr \"\"\n"
					. "\"Content-Type: text/plain; charset=UTF-8\\n\"\n"
					. "\"MIME-Version: 1.0\\n\"\n"
					. "\"Content-Transfer-Encoding: 8bit\\n\"";
		}
		else
		{
			$data = $this->entryToString($this->entries[""]);
		}

		foreach($this->entries as $msgid => $entry)
		{
			if($msgid == "")continue; // Header is already written
			$data .= $this->entryToString($entry);
		}

		return $data;
	}

	public function sendToBrowser($name="unnamed_file")
	{
		header('Content-Description: File Transfer');
	    header('Content-Type: text/plain');
	    header('Content-Disposition: attachment; filename='.$name);
	    header('Content-Transfer-Encoding: binary');
	    header('Expires: 0');
	    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
	    header('Pragma: public');
	    header('Content-Length: ' . strlen($this->data));
	    ob_clean();
	    flush();
	    echo (string)$this;
	    exit;
	}

	public function write($path)
	{
		file_put_contents($path, (string)$this);
	}

	public function getEntries()
	{
		return $this->entries;
	}

	public function updateFrom(PoFileCore $other)
	{
		$entries = $other->getEntries();
		foreach($this->entries as $msgid => $entry)
		{
			if(($entry['msgstr'] == '') && isset($entries[$msgid]) && $entries[$msgid]['msgstr'] != '')
			{
				$this->entries[$msgid] = $entries[$msgid];
			}
		}
	}

}