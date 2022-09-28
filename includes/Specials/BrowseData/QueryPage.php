<?php

namespace SD\Specials\BrowseData;

use Closure;
use PageProps;
use RequestContext;
use SD\Parameters\Parameters;
use SD\Repository;
use SD\Sql\SqlProvider;
use SD\Utils;
use SMWOutputs;
use Title;

class QueryPage extends \QueryPage {
	private UrlService $urlService;
	private GetPageContent $getPageContent;
	private GetCategories $getCategories;
	private GetAppliedFilters $getAppliedFilters;
	private GetApplicableFilters $getApplicableFilters;
	private GetUnpagedResults $getUnpagedResults;
	private GetDrilldownResults $getDrilldownResults;

	private ProcessTemplate $processTemplate;

	private DrilldownQuery $query;
	private ?string $headerPage;
	private ?string $footerPage;

	public function __construct(
		Repository $repository, PageProps $pageProps, Closure $newUrlService,
		Closure $getPageFromTitleText, RequestContext $context, Parameters $parameters,
		DrilldownQuery $query, int $offset, int $limit
	) {
		parent::__construct( 'BrowseData' );
		$this->setContext( $context );

		$request = $context->getRequest();
		$output = $this->getOutput();

		$urlService = $newUrlService( $request, $query );
		$this->getPageContent = new GetPageContent( $getPageFromTitleText, $output );
		$this->getCategories = new GetCategories( $repository, $urlService, $query );
		$this->getAppliedFilters = new GetAppliedFilters( $pageProps, $urlService, $query );
		$this->getApplicableFilters = new GetApplicableFilters( $repository, $urlService, $output, $request, $query );
		$this->getUnpagedResults = new GetUnpagedResults();
		$this->getDrilldownResults = new GetDrilldownResults( $parameters->displayParametersList() );

		$this->urlService = $urlService;
		$this->query = $query;
		$this->headerPage = $parameters->header();
		$this->footerPage = $parameters->footer();
		$this->offset = $offset;
		$this->limit = $limit;

		$this->processTemplate = new ProcessTemplate;
	}

	public function getName() {
		return 'BrowseData';
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
			return '';
		}

		return ( $this->processTemplate ) ( 'QueryPageHeader', [
			'header' => ( $this->getPageContent )( $this->headerPage ),
			'categories' => ( $this->getCategories )( $categories ),
			'appliedFilters' => ( $this->getAppliedFilters )(),
			'applicableFilters' => ( $this->getApplicableFilters )(),
			'unpagedResults' => ( $this->getUnpagedResults )(),
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
			'drilldownResults' => ( $this->getDrilldownResults )( $out, $res, $num ),
			'footer' => ( $this->getPageContent )( $this->footerPage ),
		] ) );

		SMWOutputs::commitToOutputPage( $out );
	}

	protected function openList( $offset ) {
		return "";
	}

	protected function closeList() {
		return '<br style="clear: both" />';
	}

	protected function linkParameters() {
		return $this->urlService->getLinkParameters( $this->getRequest(), $this->query );
	}

}
