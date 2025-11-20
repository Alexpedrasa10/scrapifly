<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Exceptions\ScrapingException;

class FlightService
{
    private $scraper;
    private KayakParserService $parser;
    private int $cacheTtl;
    private string $cachePrefix;

    public function __construct(KayakParserService $parser)
    {
        $this->parser = $parser;
        $this->cacheTtl = config('scraping.cache.ttl', 3600);
        $this->cachePrefix = config('scraping.cache.prefix', 'flights_');

        // Select scraper based on configuration
        $provider = config('scraping.provider', 'scrapingbee');

        if ($provider === 'scrapingbee') {
            $this->scraper = app(ScrapingBeeService::class);
        } else {
            $this->scraper = app(BrightDataService::class);
        }
    }

    /**
     * Get flights for the given parameters
     *
     * @param string $origin Origin airport code
     * @param string $destination Destination airport code
     * @param string $departureDate Departure date (YYYY-MM-DD)
     * @param string $returnDate Return date (YYYY-MM-DD)
     * @return array
     * @throws ScrapingException
     */
    public function getFlights(string $origin, string $destination, string $departureDate, string $returnDate): array
    {
        $cacheKey = $this->generateCacheKey($origin, $destination, $departureDate, $returnDate);

        // Check if we have cached data
        $cachedData = Cache::get($cacheKey);

        if ($cachedData !== null) {
            Log::info('FlightService: Returning cached data', ['cache_key' => $cacheKey]);
            return $cachedData;
        }

        // No cache, need to scrape
        Log::info('FlightService: Cache miss, scraping new data', ['cache_key' => $cacheKey]);

        try {
            $flights = $this->scrapeFlights($origin, $destination, $departureDate, $returnDate);

            // Cache the results
            Cache::put($cacheKey, $flights, $this->cacheTtl);

            // Also store metadata for the health endpoint
            $this->updateScrapeMetadata($cacheKey);

            return $flights;

        } catch (ScrapingException $e) {
            // If scraping fails, check if we have stale cache to return
            $staleData = Cache::get($cacheKey . '_stale');

            if ($staleData !== null) {
                Log::warning('FlightService: Scraping failed, returning stale cache', [
                    'error' => $e->getMessage(),
                ]);
                return $staleData;
            }

            throw $e;
        }
    }

    /**
     * Scrape flights from Kayak
     */
    private function scrapeFlights(string $origin, string $destination, string $departureDate, string $returnDate): array
    {
        $url = $this->buildKayakUrl($origin, $destination, $departureDate, $returnDate);

        $html = $this->scraper->fetch($url);

        $flights = $this->parser->parse($html, $origin, $destination);

        // Store as stale backup before caching
        $cacheKey = $this->generateCacheKey($origin, $destination, $departureDate, $returnDate);
        Cache::put($cacheKey . '_stale', $flights, $this->cacheTtl * 2);

        return $flights;
    }

    /**
     * Build Kayak search URL
     */
    private function buildKayakUrl(string $origin, string $destination, string $departureDate, string $returnDate): string
    {
        $baseUrl = config('scraping.kayak.base_url');
        $params = config('scraping.kayak.default_params');

        $url = sprintf(
            '%s/%s-%s/%s/%s',
            $baseUrl,
            $origin,
            $destination,
            $departureDate,
            $returnDate
        );

        $queryString = http_build_query($params);

        return $url . '?' . $queryString;
    }

    /**
     * Generate cache key for flight search
     */
    private function generateCacheKey(string $origin, string $destination, string $departureDate, string $returnDate): string
    {
        return $this->cachePrefix . md5("{$origin}_{$destination}_{$departureDate}_{$returnDate}");
    }

    /**
     * Update scrape metadata for health endpoint
     */
    private function updateScrapeMetadata(string $cacheKey): void
    {
        Cache::put('last_scrape_timestamp', now()->timestamp, $this->cacheTtl * 24);
        Cache::put('last_scrape_cache_key', $cacheKey, $this->cacheTtl * 24);
    }

    /**
     * Get the timestamp of the last scrape
     */
    public function getLastScrapeTimestamp(): ?int
    {
        return Cache::get('last_scrape_timestamp');
    }

    /**
     * Get cache TTL in seconds
     */
    public function getCacheTtl(): int
    {
        return $this->cacheTtl;
    }
}
