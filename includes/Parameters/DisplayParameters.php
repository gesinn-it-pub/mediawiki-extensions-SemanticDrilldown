<?php

namespace SD\Parameters;

use Generator;
use IteratorAggregate;

class DisplayParameters implements IteratorAggregate {

	private const SEP = ';';
	private const CAPTION_EQ = 'caption=';
	private const UNPAGED_EQ = 'unpaged=';

	/**
	 * Array of strings of the form "x=y"
	 * @readonly
	 */
	private array $displayParameters = [];

	/**
	 * The caption passed as display parameter "caption=Foo"
	 * @readonly
	 */
	public ?string $caption = null;

	/**
	 * The unpaged flag passed as display parameter "unpaged=true"; this is the only
	 * form which is considered to indicate unpaged display
	 * @readonly
	 */
	public bool $unpaged = false;

	/**
	 * @param string $displayParameters String of the form "x1=y1;...;xn=yn"
	 */
	public function __construct( string $displayParameters ) {
		$displayParameters = array_map( 'trim', explode( self::SEP, $displayParameters ) );

		foreach ( $displayParameters as $dp ) {
			// filter out the caption parameter and store it separately
			if ( strpos( $dp, self::CAPTION_EQ ) === 0 ) {
				$this->caption = substr( $dp, strlen( self::CAPTION_EQ ) );
			} elseif ( strpos( $dp, self::UNPAGED_EQ ) === 0 ) {
				$this->unpaged = substr( $dp, strlen( self::UNPAGED_EQ ) ) === 'true';
			} else {
				$this->displayParameters[] = $dp;
			}
		}
	}

	public function getIterator(): Generator {
		yield from $this->displayParameters;
	}

	public function __toString() {
		$additionalParameters = [];
		if ( $this->caption ) {
			$additionalParameters[] = self::CAPTION_EQ . $this->caption;
		}
		if ( $this->unpaged ) {
			$additionalParameters[] = self::UNPAGED_EQ . 'true';
		}

		$displayParameters = array_merge( $additionalParameters, $this->displayParameters );

		return implode( self::SEP, $displayParameters );
	}

}
