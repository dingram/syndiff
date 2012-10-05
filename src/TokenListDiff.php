<?php
namespace SynDiff\Diff;

require_once(__DIR__.'/Token.php');

use \SynDiff\Diff\TokenList\Token;

class TokenList
{
	protected $old_file;
	protected $new_file;

	protected $old_tokens;
	protected $new_tokens;


	public static function fromFiles($oldfile, $newfile)
	{
		$obj = new static();

		$obj->old_file = $oldfile;
		$obj->new_file = $newfile;

		$obj->old_tokens = token_get_all(file_get_contents($oldfile));
		$obj->new_tokens = token_get_all(file_get_contents($newfile));

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

	public function dump()
	{
		$toks = static::formatTokens($this->old_tokens);
		foreach ($toks as $t) { $t->debug = true; print $t; }

		$toks = static::formatTokens($this->new_tokens);
		foreach ($toks as $t) { $t->debug = true; print $t; }
	}

}
