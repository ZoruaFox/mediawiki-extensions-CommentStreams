<?php

use MediaWiki\Title\Title;

class SMWDataItem {
	const TYPE_BLOB = 0; // phpcs:ignore
	const TYPE_WIKIPAGE = 0; // phpcs:ignore

	/**
	 * @var int
	 */
	private $type = 0;

	/**
	 * @var Title
	 */
	private $title = null;

	/**
	 * @return int
	 */
	public function getDIType(): int {
		return $this->type;
	}

	/**
	 * @return string
	 */
	public function getString(): string {
		return '';
	}

	/**
	 * @return Title
	 */
	public function getTitle(): Title {
		return $this->title;
	}
}
