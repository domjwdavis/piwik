<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik_Plugins
 * @package Piwik_SEO
 */

/**
 * @see plugins/Referers/functions.php
 */
require_once PIWIK_INCLUDE_PATH . '/plugins/Referers/functions.php';

/**
 * The SEO API lets you access a list of SEO metrics for the specified URL: Google Pagerank, Goolge/Bing indexed pages
 * Alexa Rank, age of the Domain name and count of DMOZ entries.
 * 
 * @package Piwik_SEO
 */
class Piwik_SEO_API 
{
	static private $instance = null;
	/**
	 * @return Piwik_SEO_API
	 */
	static public function getInstance()
	{
		if (self::$instance == null)
		{
			self::$instance = new self;
		}
		return self::$instance;
	}
	
	/**
	 * TODO
	 */
	public function getSEOStats( $idSite, $period, $date )
	{
		// for non-day periods, we look up stats for the last day in the period
		if ($period != 'day')
		{
			if ($period == 'range') // TODO: this code is repeated throughout piwik...
			{
				$oPeriod = new Piwik_Period_Range($period, $date);
			}
			else
			{
				$oPeriod = Piwik_Period::factory($period, Piwik_Date::factory($date));
			}
			
			$period = 'day';
			$date = $oPeriod->getDateEnd()->toString();
		}
		
		$archive = Piwik_Archive::build($idSite, $period, $date);
		$archive->setRequestedReport('SEO_Metrics');
		$archive->prepareArchive();
		$archive->setIdArchive(Piwik_ArchiveProcessing::TIME_OF_DAY_INDEPENDENT);
		
		$todayStr = Piwik_Date::factory('now', Piwik_Site::getTimezoneFor($idSite))->toString();
		
		$doneMetricName = Piwik_SEO::getMetricArchiveName(Piwik_SEO::DONE_ARCHIVE_NAME, $idSite, $todayStr);
		
		// if archive not created and date is today, do the HTTP requests
		$isArchivingDone = $archive->getNumeric($doneMetricName, $checkIfVisits = false);
		if ($isArchivingDone == 0
			&& $date == $todayStr)
		{
			$stats = Piwik_SEO::archiveSEOStatsFor($idSite);
			
			$result = new Piwik_DataTable();
			if ($stats === false) // sanity check, should never occur
			{
				Piwik::log("sanity check failed: SEO archiving was called but archiving for today is done");
			}
			else
			{
				$result->addRowsFromArrayWithIndexLabel($stats);
			}
		}
		else
		{
			$seoMetrics = array(
				Piwik_SEO::GOOGLE_PAGE_RANK_METRIC_NAME,
				Piwik_SEO::GOOGLE_INDEXED_PAGE_COUNT,
				Piwik_SEO::ALEXA_RANK_METRIC_NAME,
				Piwik_SEO::DMOZ_METRIC_NAME,
				Piwik_SEO::BING_INDEXED_PAGE_COUNT,
				Piwik_SEO::BACKLINK_COUNT,
				Piwik_SEO::REFERRER_DOMAINS_COUNT
			);
			foreach ($seoMetrics as &$name)
			{
				$name = Piwik_SEO::getMetricArchiveName($name, $idSite, $date);
			}
			
			// TODO: if I remove $formatResult, everything is returned as 0. that makes no bloody sense.
			$table = $archive->getDataTableFromNumeric($seoMetrics, $formatResult = false);
		
			$result = new Piwik_DataTable();
			foreach ($table->getFirstRow()->getColumns() as $metricName => $value)
			{
				if ($value === false)
				{
					$value = 0;
				}
			
				$columns = array('label' => $metricName, 'value' => $value);
				$row = new Piwik_DataTable_Row(array(Piwik_DataTable_Row::COLUMNS => $columns));
				
				$result->addRow($row);
			}
		}
		
		// TODO: what happens if when archiving, majestic limit is reached? need to retry next cron.
		
		// add row for site birth
		$siteBirth = $this->getSiteBirthTime($idSite);
		$row = new Piwik_DataTable_Row(array(
			Piwik_DataTable_Row::COLUMNS => array('label' => 'site_birth',
												  'value' => $siteBirth)
		));
		$result->addRow($row);
		
		// clean labels
		$cleanSEOMetricArchiveName = array('Piwik_SEO_API', 'cleanSEOMetricArchiveName');
		$result->filter('ColumnCallbackReplace', array('label', $cleanSEOMetricArchiveName));
		
		// set metadata for individual rows
		$googleLogo = Piwik_getSearchEngineLogoFromUrl('http://google.com');
		$bingLogo = Piwik_getSearchEngineLogoFromUrl('http://bing.com');
		$alexaLogo = Piwik_getSearchEngineLogoFromUrl('http://alexa.com');
		$dmozLogo = Piwik_getSearchEngineLogoFromUrl('http://dmoz.org');
		$linkToMajestic = Piwik_SEO_MajesticClient::getLinkForUrl(Piwik_Site::getMainUrlFor($idSite));
		
		$majesticMetadata = array(
			'logo' => 'plugins/SEO/images/majesticseo.png',
			'url' => $linkToMajestic,
			'url_tooltip' => Piwik_Translate('SEO_ViewBacklinksOnMajesticSEO')
		);
		
		$metadataToAdd = array(
			Piwik_SEO::GOOGLE_PAGE_RANK_METRIC_NAME => array('logo' => $googleLogo, 'id' => 'pagerank'),
			Piwik_SEO::GOOGLE_INDEXED_PAGE_COUNT => array('logo' => $googleLogo, 'id' => 'google-index'),
			Piwik_SEO::BING_INDEXED_PAGE_COUNT => array('logo' => $bingLogo, 'id' => 'bing-index'),
			Piwik_SEO::ALEXA_RANK_METRIC_NAME => array('logo' => $alexaLogo, 'id' => 'alexa'),
			Piwik_SEO::DMOZ_METRIC_NAME => array('logo' => $dmozLogo, 'id' => 'dmoz'),
			'site_birth' => array('logo' => 'plugins/SEO/images/whois.png', 'id' => 'domain-age'),
			Piwik_SEO::BACKLINK_COUNT => array_merge($majesticMetadata, array('id' => 'external-backlinks')),
			Piwik_SEO::REFERRER_DOMAINS_COUNT => array_merge($majesticMetadata, array('id' => 'referrer-domainsb')),
		);
		
		foreach ($metadataToAdd as $label => $metadata)
		{
			$row = $result->getRowFromLabel($label);
			if ($row)
			{
				foreach ($metadata as $name => $value)
				{
					$row->setMetadata($name, $value);
				}
			}
		}
		
		// turn site_birth into age (TODO: do in controller?)
		$row = $result->getRowFromLabel('site_birth');
		if ($row)
		{
			$prettyAge = Piwik::getPrettyTimeFromSeconds(time() - $row->getColumn('value'));
			
			$row->setColumn('label', 'site_age');
			$row->setColumn('value', $prettyAge);
		}
		
		// translate labels
		$seoMetricTranslations = array(
			Piwik_SEO::GOOGLE_PAGE_RANK_METRIC_NAME => 'Google PageRank',
			Piwik_SEO::GOOGLE_INDEXED_PAGE_COUNT => 'SEO_Google_IndexedPages',
			Piwik_SEO::ALEXA_RANK_METRIC_NAME => 'SEO_AlexaRank',
			Piwik_SEO::DMOZ_METRIC_NAME => 'SEO_Dmoz',
			Piwik_SEO::BING_INDEXED_PAGE_COUNT => 'SEO_Bing_IndexedPages',
			Piwik_SEO::BACKLINK_COUNT => 'SEO_ExternalBacklinks',
			Piwik_SEO::REFERRER_DOMAINS_COUNT => 'SEO_ReferrerDomains',
			'site_age' => 'SEO_DomainAge'
		);
		$translateSeoMetricName = array('Piwik_SEO_API', 'translateSeoMetricName');
		$result->filter('ColumnCallbackReplace', array('label', $translateSeoMetricName, array($seoMetricTranslations)));
		
		return $result;
	}
	
	/**
	 * TODO (move + docs)
	 */
	public static function cleanSEOMetricArchiveName( $label )
	{
		$parts = explode('-', $label);
		return reset($parts);
	}
	
	/**
	 * TODO (move + docs)
	 */
	public static function translateSeoMetricName( $metricName, $translations )
	{
		if (isset($translations[$metricName]))
		{
			return Piwik_Translate($translations[$metricName]);
		}
		return $metricName;
	}
	
	/**
	 * TODO
	 */
	public function getSiteBirthTime( $idSite )
	{
		$siteBirthOption = Piwik_SEO::getSiteBirthOptionName($idSite);
		$siteBirthTime = Piwik_GetOption($siteBirthOption);
		
		if ($siteBirthTime === false)
		{
			$rank = new Piwik_SEO_RankChecker(Piwik_Site::getMainUrlFor($idSite));
			$siteAge = $rank->getAge($prettyFormatAge = false);
			$siteBirthTime = time() - $siteAge;
			Piwik_SetOption($siteBirthOption, $siteBirthTime);
		}
		
		return $siteBirthTime;
	}
	
	/** TODO: deprecate
	 * Returns SEO statistics for a URL.
	 *
	 * @param string $url URL to request SEO stats for
	 * @return Piwik_DataTable
	 */
	public function getRank( $url )
	{
		Piwik::checkUserHasSomeViewAccess();
		$rank = new Piwik_SEO_RankChecker($url);
		
		$linkToMajestic = Piwik_SEO_MajesticClient::getLinkForUrl($url);
		
		$data = array(
			'Google PageRank' 	=> array(
				'rank' => $rank->getPageRank(),
				'logo' => Piwik_getSearchEngineLogoFromUrl('http://google.com'),
				'id' => 'pagerank'
			),
            Piwik_Translate('SEO_Google_IndexedPages') => array(
                'rank' => $rank->getIndexedPagesGoogle(),
                'logo' => Piwik_getSearchEngineLogoFromUrl('http://google.com'),
                'id' => 'google-index',
            ),
            Piwik_Translate('SEO_Bing_IndexedPages') => array(
                'rank' => $rank->getIndexedPagesBing(),
                'logo' => Piwik_getSearchEngineLogoFromUrl('http://bing.com'),
                'id' => 'bing-index',
			),
			Piwik_Translate('SEO_AlexaRank') => array(
				'rank' => $rank->getAlexaRank(),
				'logo' => Piwik_getSearchEngineLogoFromUrl('http://alexa.com'),
				'id' => 'alexa',
			),
			Piwik_Translate('SEO_DomainAge') => array(
				'rank' => $rank->getAge(),
				'logo' => 'plugins/SEO/images/whois.png',
				'id'   => 'domain-age',
			),
			Piwik_Translate('SEO_ExternalBacklinks') => array(
				'rank' => $rank->getExternalBacklinkCount(),
				'logo' => 'plugins/SEO/images/majesticseo.png',
				'logo_link' => $linkToMajestic,
				'logo_tooltip' => Piwik_Translate('SEO_ViewBacklinksOnMajesticSEO'),
				'id'   => 'external-backlinks',
			),
			Piwik_Translate('SEO_ReferrerDomains') => array(
				'rank' => $rank->getReferrerDomainCount(),
				'logo' => 'plugins/SEO/images/majesticseo.png',
				'logo_link' => $linkToMajestic,
				'logo_tooltip' => Piwik_Translate('SEO_ViewBacklinksOnMajesticSEO'),
				'id'   => 'referrer-domains',
			),
		);

		// Add DMOZ only if > 0 entries found
		$dmozRank = array(
			'rank' => $rank->getDmoz(),
			'logo' => Piwik_getSearchEngineLogoFromUrl('http://dmoz.org'),
			'id'   => 'dmoz',
		);
		if($dmozRank['rank'] > 0)
		{
			$data[Piwik_Translate('SEO_Dmoz')] = $dmozRank;
		}

		$dataTable = new Piwik_DataTable();
		$dataTable->addRowsFromArrayWithIndexLabel($data);
		return $dataTable;
	}
}
