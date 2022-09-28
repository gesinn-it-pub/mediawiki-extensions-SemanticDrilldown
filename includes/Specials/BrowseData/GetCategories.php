<?php

namespace SD\Specials\BrowseData;

use SD\Repository;

class GetCategories {

	private Repository $repository;
	private UrlService $urlService;
	private DrilldownQuery $query;

	public function __construct(
		Repository $repository, UrlService $urlService, DrilldownQuery $query
	) {
		$this->repository = $repository;
		$this->urlService = $urlService;
		$this->query = $query;
	}

	public function __invoke( $categories ): ?array {
		if ( $this->urlService->showSingleCat() ) {
			return null;
		}

		$toCategoryViewModel = function ( $category ) {
			$category_children = $this->repository->getCategoryChildren( $category, false, 5 );
			return [
				'name' => $category . " (" . count( array_unique( $category_children ) ) . ")",
				'isSelected' => str_replace( '_', ' ', $this->query->category() ) == $category,
				'url' => $this->urlService->getUrl( $category )
			];
		};

		global $sdgShowCategoriesAsTabs;
		return [
			'categoriesAsTabs' => $sdgShowCategoriesAsTabs,
			'categories' => array_map( $toCategoryViewModel, $categories )
		];
	}

}
