<?php

namespace SD\Specials\BrowseData;

use Html;
use MediaWiki\Widget\DateInputWidget;
use OutputPage;
use PageProps;
use SD\AppliedFilter;
use SD\AppliedFilterValue;
use SD\Filter;
use SD\Parameters\Parameters;
use SD\PossibleFilterValue;
use SD\PossibleFilterValues;
use SD\Repository;
use SD\Utils;
use SpecialPage;
use TemplateParser;
use Title;
use WebRequest;
use WikiPage;

class Printer {

	private Repository $repository;
	private PageProps $pageProps;
	private OutputPage $output;
	private WebRequest $request;
	private Parameters $parameters;
	private DrilldownQuery $query;

	private TemplateParser $templateParser;

	public function __construct(
		Repository $repository, PageProps $pageProps,
		OutputPage $output, WebRequest $request, Parameters $parameters, DrilldownQuery $query
	) {
		$this->repository = $repository;
		$this->pageProps = $pageProps;
		$this->output = $output;
		$this->request = $request;
		$this->parameters = $parameters;
		$this->query = $query;

		$this->templateParser = new TemplateParser( __DIR__ . '/templates');
	}

	public function getPageHeader(): string {
		$categories = Utils::getCategoriesForBrowsing();
		// if there are no categories, escape quickly
		if ( count( $categories ) == 0 ) {
			return "";
		}

		$vm = [
			'introTemplate' => $this->getIntroTemplate(),
			'categories' => $this->showSingleCat() ? null : $this->getCategories( $categories ),
		];

		// if there are no subcategories or filters for this
		// category, escape now that we've (possibly) printed the
		// categories list
		if ( empty( $this->query->nextLevelSubcategories() ) &&
			 empty( $this->query->appliedFilters() ) &&
			 empty( $this->query->remainingFilters() )
		) {
			return $this->processTemplate('Page', $vm);
		}

		if ( count( $this->query->appliedFilters() ) > 0 || $this->query->subcategory() ) {
			$vm['appliedFilters'] = $this->getAppliedFilters();
		}

		$vm['applicableFilters'] = $this->getApplicableFilters();

		return $this->processTemplate('Page', $vm);
	}

	private function getCategories( $categories ): array {
		$toCategoryViewModel = function ( $category ) {
			$category_children = $this->repository->getCategoryChildren( $category, false, 5 );
			return [
				'name' => $category . " (" . count( array_unique( $category_children ) ) . ")",
				'isSelected' => str_replace( '_', ' ', $this->query->category() ) == $category,
				'url' => $this->makeBrowseURL( $category )
			];
		};

		global $sdgShowCategoriesAsTabs;
		return [
			'categoriesAsTabs' => $sdgShowCategoriesAsTabs,
			'categories' => array_map( $toCategoryViewModel, $categories )
		];
	}

	private function getAppliedFilters() : array {
		global $wgScriptPath;
		$sdSkinsPath = $wgScriptPath . '/extensions/SemanticDrilldown/skins';

		$remainingHtml = '';
		$subcategory_text = wfMessage( 'sd_browsedata_subcategory' )->text();

		if ( $this->query->subcategory() ) {
			$remainingHtml .= " > ";
			$remainingHtml .= "$subcategory_text: ";
			$subcat_string = str_replace( '_', ' ', $this->query->subcategory() );
			$remove_filter_url = $this->makeBrowseURL( $this->query->category(), $this->query->appliedFilters() );
			$remainingHtml .= '<span class="drilldown-header-value">' . $subcat_string . '</span>' .
				'<a href="' . $remove_filter_url . '" title="' . wfMessage( 'sd_browsedata_removesubcategoryfilter' )->text() . '"><img src="' . $sdSkinsPath . '/filter-x.png" /></a> ';
		}

		foreach ( $this->query->appliedFilters() as $i => $af ) {
			$remainingHtml .= ( !$this->query->subcategory() && $i == 0 ) ? " > " : "\n					<span class=\"drilldown-header-value\">&</span> ";
			$filter_label = $af->filter->name();
			// add an "x" to remove this filter, if it has more
			// than one value
			if ( count( $this->query->appliedFilters()[$i]->values ) > 1 ) {
				$temp_filters_array = $this->query->appliedFilters();
				array_splice( $temp_filters_array, $i, 1 );
				$remove_filter_url = $this->makeBrowseURL( $this->query->category(), $temp_filters_array, $this->query->subcategory() );
				array_splice( $temp_filters_array, $i, 0 );
				$remainingHtml .= $filter_label . ' <a href="' . $remove_filter_url . '" title="' . wfMessage( 'sd_browsedata_removefilter' )->text() . '"><img src="' . $sdSkinsPath . '/filter-x.png" /></a> : ';
			} else {
				$remainingHtml .= "$filter_label: ";
			}
			foreach ( $af->values as $j => $fv ) {
				if ( $j > 0 ) {
					$remainingHtml .= ' <span class="drilldown-or">' . wfMessage( 'sd_browsedata_or' )->text() . '</span> ';
				}
				$filter_text = Utils::escapeString( $this->getNiceAppliedFilterValue( $af->filter->propertyType(), $fv->text ) );
				$temp_filters_array = $this->query->appliedFilters();
				$removed_values = array_splice( $temp_filters_array[$i]->values, $j, 1 );
				$remove_filter_url = $this->makeBrowseURL( $this->query->category(), $temp_filters_array, $this->query->subcategory() );
				array_splice( $temp_filters_array[$i]->values, $j, 0, $removed_values );
				$remainingHtml .= '				<span class="drilldown-header-value">' . $filter_text . '</span> <a href="' . $remove_filter_url . '" title="' . wfMessage( 'sd_browsedata_removefilter' )->text() . '"><img src="' . $sdSkinsPath . '/filter-x.png" /></a>';
			}

			if ( $af->search_terms != null ) {
				foreach ( $af->search_terms as $j => $search_term ) {
					if ( $j > 0 ) {
						$remainingHtml .= ' <span class="drilldown-or">' . wfMessage( 'sd_browsedata_or' )->text() . '</span> ';
					}
					$filter_text = Utils::escapeString( $this->getNiceAppliedFilterValue( $af->filter->propertyType(), $search_term ) );
					$temp_filters_array = $this->query->appliedFilters();
					$removed_values = array_splice( $temp_filters_array[$i]->search_terms, $j, 1 );
					$remove_filter_url = $this->makeBrowseURL( $this->query->category(), $temp_filters_array, $this->query->subcategory() );
					array_splice( $temp_filters_array[$i]->search_terms, $j, 0, $removed_values );
					$remainingHtml .= "\n\t" . '<span class="drilldown-header-value">~ \'' . $filter_text . '\'</span> <a href="' . $remove_filter_url . '" title="' . wfMessage( 'sd_browsedata_removefilter' )->text() . '"><img src="' . $sdSkinsPath . '/filter-x.png" /> </a>';
				}
			} elseif ( $af->lower_date != null || $af->upper_date != null ) {
				$remainingHtml .= "\n\t<span class=\"drilldown-header-value\">" . $af->lower_date_string . " - " . $af->upper_date_string . "</span>";
			}
		}

		return [
			'category' => str_replace( '_', ' ', $this->query->category() ),
			'categoryUrl' => $this->makeBrowseURL( $this->query->category() ),
			'remainingHtml' => $remainingHtml,
		];
	}

	private function getApplicableFilters() {
		global $sdgFiltersSmallestFontSize, $sdgFiltersLargestFontSize;

		$remainingHtml = '';
		$drilldown_description = wfMessage( 'sd_browsedata_docu' )->text();
		$remainingHtml .= "<p>$drilldown_description</p>\n";
		// display the list of subcategories on one line, and below
		// it every filter, each on its own line; each line will
		// contain the possible values, and, in parentheses, the
		// number of pages that match that value
		$remainingHtml .= "<div class=\"drilldown-filters\">\n";
		$cur_url = $this->makeBrowseURL( $this->query->category(), $this->query->appliedFilters(), $this->query->subcategory() );
		$cur_url .= ( strpos( $cur_url, '?' ) ) ? '&' : '?';
		$this->repository->createTempTable( $this->query->category(), $this->query->subcategory(), $this->query->allSubcategories(), $this->query->appliedFilters() );
		$num_printed_values = 0;
		if ( count( $this->query->nextLevelSubcategories() ) > 0 ) {
			$results_line = "";
			// loop through to create an array of subcategory
			// names and their number of values, then loop through
			// the array to print them - we loop through twice,
			// instead of once, to be able to print a tag-cloud
			// display if necessary
			$subcat_values = [];
			foreach ( $this->query->nextLevelSubcategories() as $i => $subcat ) {
				$further_subcats = $this->repository->getCategoryChildren( $subcat, true, 10 );
				$num_results = $this->repository->getNumResults( $subcat, $further_subcats );
				$subcat_values[$subcat] = $num_results;
			}
			// get necessary values for creating the tag cloud,
			// if appropriate
			if ( $sdgFiltersSmallestFontSize > 0 && $sdgFiltersLargestFontSize > 0 ) {
				$lowest_num_results = min( $subcat_values );
				$highest_num_results = max( $subcat_values );
				if ( $lowest_num_results != $highest_num_results ) {
					$scale_factor = ( $sdgFiltersLargestFontSize - $sdgFiltersSmallestFontSize ) / ( log( $highest_num_results ) - log( $lowest_num_results ) );
				}
			}

			foreach ( $subcat_values as $subcat => $num_results ) {
				if ( $num_results > 0 ) {
					if ( $num_printed_values++ > 0 ) {
						$results_line .= " · ";
					}
					$filter_text = str_replace( '_', ' ', $subcat ) . " ($num_results)";
					$filter_url = $cur_url . '_subcat=' . urlencode( $subcat );
					if ( $sdgFiltersSmallestFontSize > 0 && $sdgFiltersLargestFontSize > 0 ) {
						if ( $lowest_num_results != $highest_num_results ) {
							$font_size = round( ( ( log( $num_results ) - log( $lowest_num_results ) ) * $scale_factor ) + $sdgFiltersSmallestFontSize );
						} else {
							$font_size = ( $sdgFiltersSmallestFontSize + $sdgFiltersLargestFontSize ) / 2;
						}
						$results_line .= '<a href="' . $filter_url . '" title="' . wfMessage( 'sd_browsedata_filterbysubcategory' )->text() . '" style="font-size: ' . $font_size . 'px">' . $filter_text . '</a>';
					} else {
						$results_line .= '<a href="' . $filter_url . '" title="' . wfMessage( 'sd_browsedata_filterbysubcategory' )->text() . '">' . $filter_text . '</a>';
					}
				}
			}
			if ( $results_line != "" ) {
				$subcategory_text = wfMessage( 'sd_browsedata_subcategory' )->text();
				$remainingHtml .= "					<p><strong>$subcategory_text:</strong> $results_line</p>\n";
			}
		}
		foreach ( $this->query->filters() as $f ) {
			foreach ( $this->query->appliedFilters() as $af ) {
				if ( $af->filter->name() == $f->name() ) {
					if ( $f->propertyType() == 'date' || $f->propertyType() == 'number' ) {
						$remainingHtml .= $this->printUnappliedFilterLine( $f );
					} else {
						$remainingHtml .= $this->printAppliedFilterLine( $af );
					}
				}
			}
			foreach ( $this->query->remainingFilters() as $rf ) {
				if ( $rf->name() == $f->name() ) {
					$remainingHtml .= $this->printUnappliedFilterLine( $rf );
				}
			}
		}

		return [
			'remainingHtml' => $remainingHtml
		];
	}

	/**
	 * Used to set URL for additional pages of results.
	 */
	public function linkParameters() {
		$params = [];
		if ( $this->showSingleCat() ) {
			$params['_single'] = null;
		}
		$params['_cat'] = $this->query->category();
		if ( $this->query->subcategory() ) {
			$params['_subcat'] = $this->query->subcategory();
		}

		foreach ( $this->query->appliedFilters() as $i => $af ) {
			if ( count( $af->values ) == 1 ) {
				$key_string = str_replace( ' ', '_', $af->filter->name() );
				$value_string = str_replace( ' ', '_', $af->values[0]->text );
				$params[$key_string] = $value_string;
			} else {
				// HACK - QueryPage's pagination-URL code,
				// which uses wfArrayToCGI(), doesn't support
				// two-dimensional arrays, which is what we
				// need - instead, add the brackets directly
				// to the key string
				foreach ( $af->values as $i => $value ) {
					$key_string = str_replace( ' ', '_', $af->filter->name() . "[$i]" );
					$value_string = str_replace( ' ', '_', $value->text );
					$params[$key_string] = $value_string;
				}
			}

			// Add search terms (if any).
			$search_terms = $af->search_terms ?? [];
			foreach ( $search_terms as $i => $text ) {
				$key_string = '_search_' . str_replace( ' ', '_', $af->filter->name() . "[$i]" );
				$value_string = str_replace( ' ', '_', $text );
				$params[$key_string] = $value_string;
			}
		}
		return $params;
	}

	private function makeBrowseURL( $category, $applied_filters = [], $subcategory = null,
							  $filter_to_remove = null ) {
		$bd = SpecialPage::getTitleFor( 'BrowseData' );
		$url = $bd->getLocalURL() . '/' . $category;
		if ( $this->showSingleCat() ) {
			$url .= ( strpos( $url, '?' ) ) ? '&' : '?';
			$url .= "_single";
		}
		if ( $subcategory ) {
			$url .= ( strpos( $url, '?' ) ) ? '&' : '?';
			$url .= "_subcat=" . $subcategory;
		}
		foreach ( $applied_filters as $i => $af ) {
			if ( $af->filter->name() == $filter_to_remove ) {
				continue;
			}
			if ( count( $af->values ) == 0 ) {
				// do nothing
			} elseif ( count( $af->values ) == 1 ) {
				$url .= ( strpos( $url, '?' ) ) ? '&' : '?';
				$url .= urlencode( str_replace( ' ', '_', $af->filter->name() ) ) . "=" . urlencode( str_replace( ' ', '_', $af->values[0]->text ) );
			} else {
				usort( $af->values, [ AppliedFilterValue::class, "compare" ] );
				foreach ( $af->values as $j => $fv ) {
					$url .= ( strpos( $url, '?' ) ) ? '&' : '?';
					$url .= urlencode( str_replace( ' ', '_', $af->filter->name() ) ) . "[$j]=" . urlencode( str_replace( ' ', '_', $fv->text ) );
				}
			}
			if ( $af->search_terms != null ) {
				foreach ( $af->search_terms as $j => $search_term ) {
					$url .= ( strpos( $url, '?' ) ) ? '&' : '?';
					$url .= '_search_' . urlencode( str_replace( ' ', '_', $af->filter->name() ) . '[' . $j . ']' ) . "=" . urlencode( str_replace( ' ', '_', $search_term ) );
				}
			}
		}
		return $url;
	}

	/**
	 * Create the full display of the filter line, once the text for
	 * the "results" (values) for this filter has been created.
	 */
	private function printFilterLine( $filterName, $isApplied, $isNormalFilter, $resultsLine, $filter ) {
		global $wgScriptPath;
		global $sdgDisableFilterCollapsible;
		$sdSkinsPath = "$wgScriptPath/extensions/SemanticDrilldown/skins";

		if ( isset( $filter->int ) ) {
			$filterName = wfMessage( $filter->int )->text();
		}

		$additionalClasses = '';
		if ( $isApplied ) {
			$additionalClasses .= ' is-applied';
		}
		if ( $isNormalFilter ) {
			$additionalClasses .= ' is-normal-filter';
		}

		if ( $sdgDisableFilterCollapsible ) {
			$text  = '<div class="drilldown-filter' . $additionalClasses . '">';
			$text .= "	<div class='drilldown-filter-label'>  \t\t\t\t\t$filterName</div>";
			$text .= '	<div class="drilldown-filter-values">' . $resultsLine . '</div>';
			$text .= '</div>';

			return $text;
		}

		$text = <<<END
				<div class="drilldown-filter $additionalClasses">
					<div class="drilldown-filter-label">

END;
		if ( $isApplied ) {
			$arrowImage = "$sdSkinsPath/right-arrow.png";
		} else {
			$arrowImage = "$sdSkinsPath/down-arrow.png";
		}
		$text .= <<<END
				<a class="drilldown-values-toggle" style="cursor: default;"><img src="$arrowImage" /></a>

END;
		$text .= "\t\t\t\t\t$filterName:";
		if ( $isApplied ) {
			$add_another_str = wfMessage( 'sd_browsedata_addanothervalue' )->text();
			$text .= " <span class=\"drilldown-filter-notes\">($add_another_str)</span>";
		}
		$displayText = ( $isApplied ) ? 'style="display: none;"' : '';
		$text .= <<<END

					</div>
					<div class="drilldown-filter-values" $displayText>$resultsLine
					</div>
				</div>

END;
		return $text;
	}

	private function getNiceAppliedFilterValue( string $propertyType, string $value ): string {
		if ( $propertyType === 'page' ) {
			$title = Title::newFromText( $value );
			$displayTitle = $this->pageProps->getProperties( $title, 'displaytitle' );
			$value = $displayTitle === [] ? $value : array_values( $displayTitle )[0];
		}

		return $this->getNiceFilterValue( $propertyType, $value );
	}

	/**
	 * Print a "nice" version of the value for a filter, if it's some
	 * special case like 'other', 'none', a boolean, etc.
	 */
	private function getNiceFilterValue( string $propertyType, string $value ): string {
		$value = str_replace( '_', ' ', $value );
		// if it's boolean, display something nicer than "0" or "1"
		if ( $value === ' other' ) {
			return wfMessage( 'sd_browsedata_other' )->text();
		} elseif ( $value === ' none' ) {
			return wfMessage( 'sd_browsedata_none' )->text();
		} elseif ( $propertyType === 'boolean' ) {
			return Utils::booleanToString( $value );
		} elseif ( $propertyType === 'date' && strpos( $value, '//T' ) ) {
			return str_replace( '//T', '', $value );
		} else {
			return $value;
		}
	}

	/**
	 * Print the line showing 'OR' values for a filter that already has
	 * at least one value set
	 */
	private function printAppliedFilterLine( AppliedFilter $af ) {
		$results_line = "";
		foreach ( $this->query->appliedFilters() as $af2 ) {
			if ( $af->filter->name() == $af2->filter->name() ) {
				$current_filter_values = $af2->values;
			}
		}
		if ( $af->filter->allowedValues() != null ) {
			$or_values = $af->filter->allowedValues();
		} else {
			$or_values = $af->getAllOrValues( $this->query->category() );
		}
		if ( $af->search_terms != null ) {
			$curSearchTermNum = count( $af->search_terms );
			$results_line = $this->printComboBoxInput(
				$af->filter->name(), $curSearchTermNum, $or_values );
			return $this->printFilterLine( $af->filter->name(), true, false, $results_line, $af->filter );
			/*
			} elseif ( $af->lower_date != null || $af->upper_date != null ) {
				// With the current interface, this code will never get
				// called; but at some point in the future, there may
				// be a date-range input again.
				$results_line = $this->printDateRangeInput( $af->filter->name(), $af->lower_date, $af->upper_date );
				return $this->printFilterLine( $af->filter->name(), true, false, $results_line );
			*/
		}
		// add 'Other' and 'None', regardless of whether either has
		// any results - add 'Other' only if it's not a date field
		$additional_or_values = [];
		if ( $af->filter->propertyType() != 'date' ) {
			$additional_or_values[] = new PossibleFilterValue( '_other' );
		}
		$additional_or_values[] = new PossibleFilterValue( '_none' );
		$or_values = $or_values->merge( $additional_or_values );

		$i = 0;
		foreach ( $or_values as $or_value ) {
			$value = $or_value->value();
			if ( $i++ > 0 ) {
				$results_line .= " · ";
			}
			$filter_text = Utils::escapeString( $this->getNiceFilterValue( $af->filter->propertyType(), $or_value->displayValue() ) );
			$applied_filters = $this->query->appliedFilters();
			foreach ( $applied_filters as $af2 ) {
				if ( $af->filter->name() == $af2->filter->name() ) {
					$or_fv = AppliedFilterValue::create( $value, $af->filter );
					$af2->values = array_merge( $current_filter_values, [ $or_fv ] );
				}
			}
			// show the list of OR values, only linking
			// the ones that haven't been used yet
			$found_match = false;
			foreach ( $current_filter_values as $fv ) {
				if ( $value == $fv->text ) {
					$found_match = true;
					break;
				}
			}
			if ( $found_match ) {
				$results_line .= "\n				$filter_text";
			} else {
				$filter_url = $this->makeBrowseURL( $this->query->category(), $applied_filters, $this->query->subcategory() );
				$results_line .= "\n						" . '<a href="' . $filter_url . '" title="' . wfMessage( 'sd_browsedata_filterbyvalue' )->text() . '">' . $filter_text . '</a>';
			}
			foreach ( $applied_filters as $af2 ) {
				if ( $af->filter->name() == $af2->filter->name() ) {
					$af2->values = $current_filter_values;
				}
			}
		}
		return $this->printFilterLine( $af->filter->name(), true, true, $results_line, $af->filter );
	}

	private function printUnappliedFilterValues( $cur_url, Filter $f, PossibleFilterValues $possibleValues ) {
		global $sdgFiltersSmallestFontSize, $sdgFiltersLargestFontSize;

		$results_line = "";
		// set font-size values for filter "tag cloud", if the
		// appropriate global variables are set
		if ( $sdgFiltersSmallestFontSize > 0 && $sdgFiltersLargestFontSize > 0 ) {
			[ $lowest_num_results, $highest_num_results ] = $possibleValues->countRange();
			if ( $lowest_num_results != $highest_num_results ) {
				$scale_factor = ( $sdgFiltersLargestFontSize - $sdgFiltersSmallestFontSize ) / ( log( $highest_num_results ) - log( $lowest_num_results ) );
			}
		}
		// now print the values
		$num_printed_values = 0;
		$filterByValueMessage = wfMessage( 'sd_browsedata_filterbyvalue' )->text();
		foreach ( $possibleValues as $value ) {
			$num_results = $value->count();
			if ( $num_printed_values++ > 0 ) {
				$results_line .= "<span class=\"sep\"> · </span>";
			}
			$filter_text = Utils::escapeString( $this->getNiceFilterValue( $f->propertyType(), $value->displayValue() ) );
			$filter_text .= "&nbsp;($num_results)";
			$filter_url = $cur_url . urlencode( str_replace( ' ', '_', $f->name() ) ) . '=' . urlencode( str_replace( ' ', '_', $value->value() ) );
			$styleAttribute = "";
			if ( $sdgFiltersSmallestFontSize > 0 && $sdgFiltersLargestFontSize > 0 ) {
				if ( $lowest_num_results != $highest_num_results ) {
					$font_size = round( ( ( log( $num_results ) - log( $lowest_num_results ) ) * $scale_factor ) + $sdgFiltersSmallestFontSize );
				} else {
					$font_size = ( $sdgFiltersSmallestFontSize + $sdgFiltersLargestFontSize ) / 2;
				}
				$styleAttribute = " style=\"font-size: $font_size px;\"";
			}
			$results_line .=
				"<span class=\"drilldown-filter-value\"><a href=\"$filter_url\" title=\"$filterByValueMessage\"$styleAttribute>$filter_text</a></span>";
		}
		return $results_line;
	}

	/**
	 * Copied from Miga, also written by Yaron Koren
	 * (https://github.com/yaronkoren/miga/blob/master/NumberUtils.js)
	 * - though that one is in Javascript.
	 */
	private function getNearestNiceNumber( $num, $previousNum, $nextNum ) {
		if ( $previousNum == null ) {
			$smallestDifference = $nextNum - $num;
		} elseif ( $nextNum == null ) {
			$smallestDifference = $num - $previousNum;
		} else {
			$smallestDifference = min( $num - $previousNum, $nextNum - $num );
		}

		$base10LogOfDifference = log10( $smallestDifference );
		$significantFigureOfDifference = floor( $base10LogOfDifference );

		$powerOf10InCorrectPlace = pow( 10, $significantFigureOfDifference );
		$significantDigitsOnly = round( $num / $powerOf10InCorrectPlace );
		$niceNumber = $significantDigitsOnly * $powerOf10InCorrectPlace;

		// Special handling if it's the first or last number in the
		// series - we have to make sure that the "nice" equivalent is
		// on the right "side" of the number.

		// That's especially true for the last number -
		// it has to be greater, not just equal to, because of the way
		// number filtering works.
		// ...or does it??
		if ( $previousNum == null && $niceNumber > $num ) {
			$niceNumber -= $powerOf10InCorrectPlace;
		}
		if ( $nextNum == null && $niceNumber < $num ) {
			$niceNumber += $powerOf10InCorrectPlace;
		}

		return $niceNumber;
	}

	/**
	 * Copied from Miga, also written by Yaron Koren
	 * (https://github.com/yaronkoren/miga/blob/master/NumberUtils.js)
	 * - though that one is in Javascript.
	 */
	private function generateIndividualFilterValuesFromNumbers( $uniqueValues ) {
		$propertyValues = [];
		foreach ( $uniqueValues as $uniqueValue => $numInstances ) {
			$curBucket = [
				'lowerNumber' => $uniqueValue,
				'higherNumber' => null,
				'numValues' => $numInstances
			];
			$propertyValues[] = $curBucket;
		}
		return $propertyValues;
	}

	/**
	 * Copied from Miga, also written by Yaron Koren
	 * (https://github.com/yaronkoren/miga/blob/master/NumberUtils.js)
	 * - though that one is in Javascript.
	 */
	private function generateFilterValuesFromNumbers( array $numberArray ) {
		global $sdgNumRangesForNumberFilters;

		$numNumbers = count( $numberArray );

		// First, find the number of unique values - if it's the value
		// of $sdgNumRangesForNumberFilters, or fewer, just display
		// each one as its own bucket.
		$numUniqueValues = 0;
		$uniqueValues = [];
		foreach ( $numberArray as $curNumber ) {
			if ( !array_key_exists( $curNumber, $uniqueValues ) ) {
				$uniqueValues[$curNumber] = 1;
				$numUniqueValues++;
				if ( $numUniqueValues > $sdgNumRangesForNumberFilters ) {
					continue;
				}
			} else {
				// We do this now to save time on the next step,
				// if we're creating individual filter values.
				$uniqueValues[$curNumber]++;
			}
		}

		if ( $numUniqueValues <= $sdgNumRangesForNumberFilters ) {
			return $this->generateIndividualFilterValuesFromNumbers( $uniqueValues );
		}

		$propertyValues = [];
		$separatorValue = $numberArray[0];

		// Make sure there are at least, on average, five numbers per
		// bucket.
		// @HACK - add 3 to the number so that we don't end up with
		// just one bucket ( 7 + 3 / 5 = 2).
		$numBuckets = min( $sdgNumRangesForNumberFilters, floor( ( $numNumbers + 3 ) / 5 ) );
		$bucketSeparators = [];
		$bucketSeparators[] = $numberArray[0];
		for ( $i = 1; $i < $numBuckets; $i++ ) {
			$separatorIndex = floor( $numNumbers * $i / $numBuckets ) - 1;
			$previousSeparatorValue = $separatorValue;
			$separatorValue = $numberArray[$separatorIndex];
			if ( $separatorValue == $previousSeparatorValue ) {
				continue;
			}
			$bucketSeparators[] = $separatorValue;
		}
		$lastValue = ceil( $numberArray[count( $numberArray ) - 1] );
		if ( $lastValue != $separatorValue ) {
			$bucketSeparators[] = $lastValue;
		}

		// Get the closest "nice" (few significant digits) number for
		// each of the bucket separators, with the number of significant digits
		// required based on their proximity to their neighbors.
		// The first and last separators need special handling.
		$bucketSeparators[0] = $this->getNearestNiceNumber( $bucketSeparators[0], null, $bucketSeparators[1] );
		for ( $i = 1; $i < count( $bucketSeparators ) - 1; $i++ ) {
			$bucketSeparators[$i] = $this->getNearestNiceNumber( $bucketSeparators[$i], $bucketSeparators[$i - 1], $bucketSeparators[$i + 1] );
		}
		$bucketSeparators[count( $bucketSeparators ) - 1] = $this->getNearestNiceNumber( $bucketSeparators[count( $bucketSeparators ) - 1], $bucketSeparators[count( $bucketSeparators ) - 2], null );

		$oldSeparatorValue = $bucketSeparators[0];
		for ( $i = 1; $i < count( $bucketSeparators ); $i++ ) {
			$separatorValue = $bucketSeparators[$i];
			$propertyValues[] = [
				'lowerNumber' => $oldSeparatorValue,
				'higherNumber' => $separatorValue,
				'numValues' => 0,
			];
			$oldSeparatorValue = $separatorValue;
		}

		$curSeparator = 0;
		for ( $i = 0; $i < count( $numberArray ); $i++ ) {
			if ( $curSeparator < count( $propertyValues ) - 1 ) {
				$curNumber = $numberArray[$i];
				while ( ( $curSeparator < count( $bucketSeparators ) - 2 ) && ( $curNumber >= $bucketSeparators[$curSeparator + 1] ) ) {
					$curSeparator++;
				}
			}
			$propertyValues[$curSeparator]['numValues']++;
		}

		return $propertyValues;
	}

	private function printNumberRanges( $filter_name, PossibleFilterValues $possibleValues ) {
		// We generate $cur_url here, instead of passing it in, because
		// if there's a previous value for this filter it may be
		// removed.
		$cur_url = $this->makeBrowseURL( $this->query->category(), $this->query->appliedFilters(), $this->query->subcategory(), $filter_name );
		$cur_url .= ( strpos( $cur_url, '?' ) ) ? '&' : '?';

		$numberArray = [];
		foreach ( $possibleValues as $value ) {
			for ( $i = 0; $i < $value->count(); $i++ ) {
				$numberArray[] = $value->value();
			}
		}
		// Put into numerical order.
		sort( $numberArray );

		$text = '';
		$filterValues = $this->generateFilterValuesFromNumbers( $numberArray );
		foreach ( $filterValues as $i => $curBucket ) {
			if ( $i > 0 ) {
				$text .= " &middot; ";
			}
			// number_format() adds in commas for each thousands place.
			$curText = number_format( $curBucket['lowerNumber'] );
			if ( $curBucket['higherNumber'] != null ) {
				$curText .= ' - ' . number_format( $curBucket['higherNumber'] );
			}
			$curText .= ' (' . $curBucket['numValues'] . ') ';
			$filterURL = $cur_url . "$filter_name=" . $curBucket['lowerNumber'];
			if ( $curBucket['higherNumber'] != null ) {
				$filterURL .= '-' . $curBucket['higherNumber'];
			}
			$text .= '<a href="' . $filterURL . '">' . $curText . '</a>';
		}
		return $text;
	}

	private function printComboBoxInput( $filter_name, $instance_num, PossibleFilterValues $possibleValues, $cur_value = null ) {
		$filter_name = str_replace( ' ', '_', $filter_name );
		// URL-decode the filter name - necessary if it contains
		// any non-Latin characters.
		$filter_name = urldecode( $filter_name );

		// Add on the instance number, since it can be one of a string
		// of values.
		$filter_name .= '[' . $instance_num . ']';

		$inputName = "_search_$filter_name";

		$filter_url = $this->makeBrowseURL( $this->query->category(), $this->query->appliedFilters(), $this->query->subcategory() );

		$text = <<< END
<form method="get" action="$filter_url">

END;

		foreach ( $this->request->getValues() as $key => $val ) {
			if ( $key != $inputName ) {
				if ( is_array( $val ) ) {
					foreach ( $val as $i => $realVal ) {
						$keyString = $key . '[' . $i . ']';
						$text .= Html::hidden( $keyString, $realVal ) . "\n";
					}
				} else {
					$text .= Html::hidden( $key, $val ) . "\n";
				}
			}
		}

		$text .= <<< END
	<div class="ui-widget">
		<select class="semanticDrilldownCombobox" name="$cur_value">
			<option value="$inputName"></option>;

END;
		foreach ( $possibleValues as $value ) {
			if ( $value->value() != '_other' && $value->value() != '_none' ) {
				$text .= "\t\t" . Html::element(
					'option',
					[ 'value' => str_replace( '_', ' ', $value->value() ) ],
					$value->displayValue() ) . "\n";
			}
		}

		$text .= <<<END
		</select>
	</div>

END;

		$text .= Html::input(
				null,
				wfMessage( 'sd_browsedata_search' )->text(),
				'submit',
				[ 'style' => 'margin: 4px 0 8px 0;' ]
			) . "\n";
		$text .= "</form>\n";
		return $text;
	}

	private function printDateInput( $input_name, $cur_value = null ) {
		$this->output->enableOOUI();
		$this->output->addModuleStyles( 'mediawiki.widgets.DateInputWidget.styles' );

		$widget = new DateInputWidget( [
			'name' => $input_name,
			'value' => $cur_value
		] );
		return (string)$widget;
	}

	private function printDateRangeInput( $filter_name, $dateRange ) {
		[ $lower_date, $upper_date ] = $dateRange;
		$start_label = wfMessage( 'sd_browsedata_daterangestart' )->text();
		$end_label = wfMessage( 'sd_browsedata_daterangeend' )->text();
		$start_month_input = $this->printDateInput( "_lower_$filter_name", $lower_date );
		$end_month_input = $this->printDateInput( "_upper_$filter_name", $upper_date );
		$text = <<<END
<form method="get">
<p>$start_label $start_month_input
$end_label $end_month_input</p>

END;
		foreach ( $this->request->getValues() as $key => $val ) {
			if ( $key == "_lower_$filter_name" || $key == "_upper_$filter_name" ) {
				// Prevent older value from querystring from overriding the value from inputs.
				continue;
			}

			if ( is_array( $val ) ) {
				foreach ( $val as $realKey => $realVal ) {
					$text .= Html::hidden( $key . '[' . $realKey . ']', $realVal ) . "\n";
				}
			} else {
				$text .= Html::hidden( $key, $val ) . "\n";
			}
		}
		$submitButton = Html::input( null, wfMessage( 'searchresultshead' )->text(), 'submit' );
		$text .= Html::rawElement( 'p', null, $submitButton ) . "\n";
		$text .= "</form>\n";

		return $text;
	}

	/**
	 * Print the line showing 'AND' values for a filter that has not
	 * been applied to the drilldown
	 */
	private function printUnappliedFilterLine( Filter $f ) {
		global $sdgMinValuesForComboBox;
		global $sdgHideFiltersWithoutValues;

		$possibleValues = $this->getPossibleValues( $f );

		$filter_name = urlencode( str_replace( ' ', '_', $f->name() ) );
		$normal_filter = true;
		if ( $possibleValues->count() == 0 ) {
			$results_line = '(' . wfMessage( 'sd_browsedata_novalues' )->text() . ')';
		} elseif ( $f->propertyType() == 'number' ) {
			$results_line = $this->printNumberRanges( $filter_name, $possibleValues );
		} elseif ( $possibleValues->count() >= $sdgMinValuesForComboBox ) {
			$results_line = $this->printComboBoxInput( $filter_name, 0, $possibleValues );
			$normal_filter = false;
		} else {
			$cur_url = $this->makeBrowseURL( $this->query->category(), $this->query->appliedFilters(), $this->query->subcategory(), $f->name() );
			$cur_url .= ( strpos( $cur_url, '?' ) ) ? '&' : '?';
			$results_line = $this->printUnappliedFilterValues( $cur_url, $f, $possibleValues );
		}

		// For dates additionally add two datepicker inputs (Start/End) to select a custom interval.
		if ( $f->propertyType() == 'date' && $possibleValues->count() != 0 ) {
			$results_line .= '<br>' . $this->printDateRangeInput( $filter_name, $possibleValues->dateRange() );
		}

		$text = $this->printFilterLine( $f->name(), false, $normal_filter, $results_line, $f );

		if ( $sdgHideFiltersWithoutValues && $possibleValues->count() === 0 ) {
			$text = '';
		}

		return $text;
	}

	private function getPossibleValues( Filter $f ): PossibleFilterValues {
		$this->repository->createFilterValuesTempTable( $f->propertyType(), $f->escapedProperty() );
		if ( empty( $f->allowedValues() ) ) {
			$possibleFilterValues = $f->propertyType() == 'date'
				? $f->getTimePeriodValues()
				: $f->getAllValues();
		} else {
			$possibleValues = [];
			foreach ( $f->allowedValues() as $value ) {
				$new_filter = AppliedFilter::create( $f, $value );
				$num_results = $this->repository->getNumResults( $this->query->subcategory(), $this->query->allSubcategories(), $new_filter );
				if ( $num_results > 0 ) {
					$possibleValues[] = new PossibleFilterValue( $value, $num_results );
				}
			}
			$possibleFilterValues = new PossibleFilterValues( $possibleValues );
		}
		$this->repository->dropFilterValuesTempTable();

		$additionalPossibleValues = [];
		// Now get values for 'Other' and 'None', as well
		// - don't show 'Other' if filter values were
		// obtained dynamically.
		if ( !empty( $f->allowedValues() ) ) {
			$other_filter = AppliedFilter::create( $f, ' other' );
			$num_results = $this->repository->getNumResults( $this->query->subcategory(), $this->query->allSubcategories(), $other_filter );
			if ( $num_results > 0 ) {
				$additionalPossibleValues[] = new PossibleFilterValue( '_other', $num_results );
			}
		}
		// Show 'None' only if any other results have been found, and
		// if it's not a numeric filter.
		if ( !empty( $f->allowedValues() ) ) {
			$fv = AppliedFilterValue::create( $f->allowedValues()[0] );
			if ( !$fv->is_numeric ) {
				$none_filter = AppliedFilter::create( $f, ' none' );
				$num_results = $this->repository->getNumResults( $this->query->subcategory(), $this->query->allSubcategories(), $none_filter );
				if ( $num_results > 0 ) {
					$additionalPossibleValues[] = new PossibleFilterValue( '_none', $num_results );
				}
			}
		}

		return $possibleFilterValues->merge( $additionalPossibleValues );
	}

	private function showSingleCat() {
		return $this->request->getCheck( '_single' );
	}

	private function getIntroTemplate() {
		$headerPage = $this->parameters->header();
		if ( $headerPage !== null ) {
			$title = Title::newFromText( $headerPage );
			$page = WikiPage::factory( $title );
			if ( $page->exists() ) {
				$content = $page->getContent();
				$pageContent = $content->serialize();
				return $this->output->parseInlineAsInterface( $pageContent );
			}
		}
		return '';
	}

	private function processTemplate( $template, $vm ) {
		$messages = [
			'sd_browsedata_subcategory',
			'sd_browsedata_resetfilters',
			'sd_browsedata_removesubcategoryfilter',
			'sd_browsedata_removefilter',
			'sd_browsedata_or',
			'sd_browsedata_docu',
			'sd_browsedata_filterbysubcategory',
			'sd_browsedata_choosecategory',
			'colon-separator',
			'sd_browsedata_addanothervalue',
			'sd_browsedata_other',
			'sd_browsedata_none',
			'sd_browsedata_filterbyvalue',
			'sd_browsedata_search',
			'sd_browsedata_daterangestart',
			'sd_browsedata_daterangeend',
			'searchresultshead',
			'sd_browsedata_novalues',
		];
		foreach ($messages as $message)
			$msg[ "msg_$message" ] = wfMessage( $message )->text();

		return $this->templateParser->processTemplate( $template, $vm + $msg);
	}

}
