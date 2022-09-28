<?php

namespace SD\Specials\BrowseData;

use OutputPage;

class GetDrilldownResults {

	private $displayParametersList;

	public function __construct( $displayParametersList ) {
		$this->displayParametersList = $displayParametersList;
	}

	public function __invoke( OutputPage $out, $res, $num ): array {
		$drilldownResults = [];
		$semanticResultPrinter = new SemanticResultPrinter( $res, $num );
		foreach ( $this->displayParametersList as $displayParameters ) {
			$text = $semanticResultPrinter->getText( iterator_to_array( $displayParameters ) );
			$drilldownResults[] = [
				'heading' => $displayParameters->caption,
				'body' => $out->parseAsInterface( $text ),
			];
			// Do we additionally need to add MetaData to $out here?
		}

		return $drilldownResults;
	}

}
