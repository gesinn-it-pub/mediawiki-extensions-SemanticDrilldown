<?php

namespace SD\Specials\BrowseData;

use Html;
use OutputPage;
use SD\Parameters\Parameters;
use SD\Sql\SqlProvider;
use SD\Utils;
use SMWOutputs;
use Title;
use WikiPage;

class QueryPage extends \QueryPage {
	private Printer $printer;
	private UrlService $urlService;
	private Parameters $parameters;
	private DrilldownQuery $query;

	private ProcessTemplate $processTemplate;

	public function __construct(
		$newPrinter, $newUrlService, $context, $parameters, $query, $offset, $limit
	) {
		parent::__construct( 'BrowseData' );

		$this->setContext( $context );
		$this->printer = $newPrinter( $this->getOutput(), $this->getRequest(), $parameters, $query );
		$this->urlService = $newUrlService( $this->getRequest(), $query );

		$this->parameters = $parameters;
		$this->query = $query;
		$this->offset = $offset;
		$this->limit = $limit;

		$this->processTemplate = new ProcessTemplate;
	}

	public function getName() {
		return "BrowseData";
	}

	public function isExpensive() {
		return false;
	}

	public function isSyndicated() {
		return false;
	}

	protected function getPageHeader(): string {
		$categories = Utils::getCategoriesForBrowsing();
		if ( empty( $categories ) ) {
			return "";
		}

		return ( $this->processTemplate ) ( 'QueryPageHeader', [
			'introTemplate' => $this->getIntroTemplate(),
			'categories' => $this->urlService->showSingleCat() ? null
				: $this->printer->getCategories( $categories ),
			'appliedFilters' => $this->printer->getAppliedFilters(),
			'applicableFilters' => $this->printer->getApplicableFilters(),
			'unpagedResults' => $this->printer->getUnpagedResults()
		] );
	}

	protected function getSQL(): string {
		// From the overridden method:
		// "For back-compat, subclasses may return a raw SQL query here, as a string.
		// This is strongly deprecated; getQueryInfo() should be overridden instead."
		return SqlProvider::getSQL(
			$this->query->category(), $this->query->subcategory(),
			$this->query->allSubcategories(), $this->query->appliedFilters() );
	}

	protected function getOrderFields() {
		return [ 'sortkey' ];
	}

	protected function sortDescending() {
		return false;
	}

	protected function formatResult( $skin, $result ) {
		$title = Title::makeTitle( $result->namespace, $result->value );
		return $this->getLinkRenderer()->makeLink( $title, $title->getText() );
	}

	protected function outputResults( $out, $skin, $dbr, $res, $num, $offset ) {
		$out->addHTML( ( $this->processTemplate )( 'QueryPageOutput', [
			'drilldownResults' => $this->getDrilldownResults( $out, $res, $num ),
			'footer' => $this->getFooter( $out ),
		] ) );

		// U-uh...!
		// close drilldown-results
		$out->addHTML( Html::closeElement( 'div' ) );

		// close the Bootstrap Panel wrapper opened in getPageHeader();
		$out->addHTML( '</div></div>' );

		SMWOutputs::commitToOutputPage( $out );
	}

	protected function openList( $offset ) {
		return "";
	}

	protected function closeList() {
		return '<br style="clear: both" />';
	}

	protected function linkParameters() {
		return $this->urlService->getLinkParameters();
	}

	private function getDrilldownResults( OutputPage $out, $res, $num ): array {
		$drilldownResults = [];
		$semanticResultPrinter = new SemanticResultPrinter( $res, $num );
		$displayParametersList = $this->parameters->displayParametersList();
		foreach ( $displayParametersList as $displayParameters ) {
			$text = $semanticResultPrinter->getText( iterator_to_array( $displayParameters ) );
			$drilldownResults[] = [
				'heading' => $displayParameters->caption,
				'body' => $out->parseAsInterface( $text ),
			];
			// Do we additionally need to add MetaData to $out here?
		}

		return $drilldownResults;
	}

	private function getIntroTemplate() {
		$headerPage = $this->parameters->header();
		if ( $headerPage !== null ) {
			$title = Title::newFromText( $headerPage );
			$page = WikiPage::factory( $title );
			if ( $page->exists() ) {
				$content = $page->getContent();
				$pageContent = $content->serialize();
				return $this->getOutput()->parseInlineAsInterface( $pageContent );
			}
		}
		return '';
	}

	private function getFooter( OutputPage $out ): ?string {
		$footer = null;
		$footerPage = $this->parameters->footer();
		if ( $footerPage !== null ) {
			$title = Title::newFromText( $footerPage );
			$page = WikiPage::factory( $title );

			if ( $page->exists() ) {
				$content = $page->getContent();
				$pageContent = $content->serialize();
				$footer = $out->parseAsInterface( $pageContent );
				// Do we additionally need to add MetaData to $out here?
			}
		}

		return $footer;
	}

}
