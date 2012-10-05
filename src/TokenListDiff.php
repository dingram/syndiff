<?php
namespace SynDiff\Diff;

require_once(__DIR__.'/Token.php');
require_once(dirname(__DIR__).'/lib/pear/Text/Diff.php');

use \SynDiff\Diff\TokenList\Token;
use \Text_Diff;
use \Text_Diff_Op_add;
use \Text_Diff_Op_copy;
use \Text_Diff_Op_delete;
use \Text_Diff_Op_change;

class TokenList
{
	protected $orig_file;
	protected $final_file;

	protected $orig_tokens;
	protected $final_tokens;


	public static function fromFiles($orig_file, $final_file)
	{
		$obj = new static();

		$obj->orig_file = $orig_file;
		$obj->final_file = $final_file;

		$obj->orig_tokens = token_get_all(file_get_contents($orig_file));
		$obj->final_tokens = token_get_all(file_get_contents($final_file));

		return $obj;
	}

	protected static function formatTokens(array $tokens)
	{
		$results = [];
		$current_line = 1;

		foreach ($tokens as $tok) {
			$token = new Token($tok);

			if ($token->getLine()) {
				// the token knows what line we're on
				$current_line = $token->getLine();
			} else {
				// it's a raw token; it has no idea. Let's help.
				$token->setLine($current_line);
			}

			$type = $token->getType();
			$content = $token->getContent();
			if (strpos($content, "\n") !== false) {
				// this is a multi-line token... we need to convert into multiple single-line tokens
				$lines = explode("\n", $content);
				$n = count($lines);

				for ($i = 0; $i < $n; ++$i) {
					// go through each line, and split it further if we can
					if (preg_match('/^(\s*)(\S.*?)(\s*)$/', $lines[$i], $matches)) {
						// it has leading/trailing whitespace; let's deal with that
						if ($matches[1] !== '') {
							$results[] = new Token(array(T_WHITESPACE, $matches[1], $current_line));
						}
						$results[] = new Token(array($type, $matches[2], $current_line));
						if ($matches[3] !== '') {
							$results[] = new Token(array(T_WHITESPACE, $matches[3], $current_line));
						}
					} elseif ($lines[$i] !== '') {
						$results[] = new Token(array($type, $lines[$i], $current_line));
					}

					if ($i != $n - 1) {
						// restore newlines lost in the explode()
						$results[] = new Token(array(T_WHITESPACE, "\n", $current_line));
						++$current_line;
					}
				}

			} else {
				// single-line token
				$results[] = $token;
			}
		}

		return $results;
	}

	/**
	 * Process a collection of diff adds
	 * - if they are all white-space changes we translate them to a copy
	 * - otherwise leave unchanged as an add
	 *
	 * @param   array  $final  Array of input tokens to process
	 * @return  array  Text_Diff_Op objects
	 */
	protected static function postProcessDiffAdd(array $final)
	{
		foreach ($final as $entry) {
			if (!$entry->isWhitespace()) {
				// found a non-whitespace add, can't translate
				return array(new Text_Diff_Op_add($final));
			}
		}

		// all whitespace adds, make it a copy
		return array(new Text_Diff_Op_copy($final));
	}

	/**
	 * Process a collection of diff deletes
	 * - if they are all white-space changes we throw them away
	 * - otherwise leave unchanged as a delete
	 *
	 * @param   array  $orig  Array of input tokens to process
	 * @return  array of Text_Diff_Op objects
	 */
	protected static function postProcessDiffDelete(array $orig)
	{
		foreach ($orig as $entry) {
			if (!$entry->isWhitespace()) {
				// found a non-whitespace delete, can't translate
				return array(new Text_Diff_Op_delete($orig));
			}
		}

		// all whitespace deletes, discard
		return array();
	}

	/**
	 * Process a collection of diff changes
	 * - use 2 accumulators, one for pre-change (orig), one for post-change (final)
	 * - incoming and outgoing changes can be different lengths (eg 2 adds, 3 deletes)
	 * - interleave adds and deletes while we have both
	 * - handle outstanding accumulated changes when we run out of matching incoming and outgoing changes
	 * - handle outstanding trailing changes in either orig or final
	 *
	 * @param   Text_Diff_Op_change  $orig
	 * @return  array  Text_Diff_Op objects
	 */
	protected static function postProcessDiffChange(Text_Diff_Op_change $diff)
	{
		$results = array();

		// determine initial context

		if ($diff->final[0]->isWhitespace() && $diff->orig[0]->isWhitespace()) {
			$context = 'whitespace';
		} else {
			$context = 'nonwhitespace';
		}

		$origAccumulator = array();
		$finalAccumulator = array();

		$iter = max(count($diff->final), count($diff->orig));

		for ($i = 0; $i < $iter; $i++) {
			if (isset($diff->final[$i]) && isset($diff->orig[$i])) {
				$orig = $diff->orig[$i];
				$final = $diff->final[$i];

				if ($final->isWhitespace() && $orig->isWhitespace()) {
					if ($context != 'whitespace') {
						// accumulated non-whitespace, keep the change
						$results[] =  new Text_Diff_Op_change($origAccumulator, $finalAccumulator);

						$origAccumulator = array();
						$finalAccumulator = array();

						$context = 'whitespace';
					}

					$origAccumulator[] = $orig;
					$finalAccumulator[] = $final;
				} else {
					if ($context != 'nonwhitespace') {
						// accumulated whitespace changes, convert to a copy of the final only
						$results[] =  new Text_Diff_Op_copy($finalAccumulator);
						$origAccumulator = array();
						$finalAccumulator = array();

						$context = 'nonwhitespace';
					}

					$origAccumulator[] = $orig;
					$finalAccumulator[] = $final;
				}
			} else {
				// final and orig arrays are not equal length, deal with them outside the loop
				break;
			}
		}

		// doesn't matter which accumulator we check, they are in sync
		if (!empty($origAccumulator)) {
			if ($context == 'whitespace') {
				// accumulated whitespace changes, convert to a copy of the final only
				$results[] =  new Text_Diff_Op_copy($finalAccumulator);
			} else {
				// accumulated non-whitespace, keep the change
				$results[] =  new Text_Diff_Op_change($origAccumulator, $finalAccumulator);
			}
		}

		// process any trailing final or orig entries and convert to simple adds or deletes
		if (isset($diff->final[$i])) {
			$results[] = new Text_Diff_Op_add(array_slice($diff->final, $i));
		} elseif (isset($diff->orig[$i])) {
			$results[] = new Text_Diff_Op_delete(array_slice($diff->orig, $i));
		}

		return $results;
	}

	/**
	 * Process an array of diffs in a white-space and code-style agnostic way
	 * - unserialises the diffed tokens
	 *
	 * @param   array $diffs  Text_Diff_Op objects
	 * @return  array         Text_Diff_Op objects
	 */
	public static function postprocessDiff(array $diffs)
	{
		$results = array();

		foreach ($diffs as $diff) {
			switch (get_class($diff)) {
				case 'Text_Diff_Op_copy':
					$results[] = $diff;
					break;
				case 'Text_Diff_Op_add':
					$results = array_merge($results, self::postProcessDiffAdd($diff->final));
					break;
				case 'Text_Diff_Op_delete':
					$results = array_merge($results, self::postProcessDiffDelete($diff->orig));
					break;
				case 'Text_Diff_Op_change':
					$results = array_merge($results, self::postProcessDiffChange($diff));
					break;
			}
		}

		return $results;
	}

	/**
	 * Generate the difference between the token arrays, and return an array of
	 * Text_Diff_Op_* objects
	 *
	 * @return  array  Text_Diff_Op objects
	 */
	public function getDiff()
	{
		if (!is_array($this->orig_tokens) || !is_array($this->final_tokens)) {
			throw new InvalidArgumentException('You need to set the inputs first');
		}
		$orig_tokens  = self::formatTokens($this->orig_tokens);
		$final_tokens = self::formatTokens($this->final_tokens);

		$differ = new Text_Diff('native', array($orig_tokens, $final_tokens));

		return self::postprocessDiff($differ->getDiff());
	}

}
