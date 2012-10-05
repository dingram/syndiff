<?php
namespace SynDiff\Diff\TokenList;

define('T_RAW', -1);

class Token
{
	protected $type;
	protected $content;
	protected $line;
	public $debug = false;

	public function __construct($token)
	{
		if (is_array($token)) {
			$this
				->setType($token[0])
				->setContent($token[1])
				->setLine($token[2]);
		} else {
			$this
				->setType(T_RAW)
				->setContent($token);
		}
	}

	public function isWhitespace()
	{
		return $this->type === T_WHITESPACE;
	}

	public function getType()
	{
		return $this->type;
	}

	public function setType($type)
	{
		if ($type == T_DOC_COMMENT) {
			// we don't care about the different sorts of comments
			$type = T_COMMENT;
		}
		$this->type = $type;
		return $this;
	}

	public function getContent()
	{
		return $this->content;
	}

	public function setContent($content)
	{
		$this->content = $content;
		return $this;
	}

	public function getLine()
	{
		return $this->line;
	}

	public function setLine($line)
	{
		$this->line = $line;
		return $this;
	}

	public function __toString()
	{
		return $this->debug ? ('«'.$this->line.'·'.$this->content.'»') : $this->content;
	}
}
