<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Exceptions\ScrapingException;

class ScrapingBeeService
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;

    public function __construct()
    {
        $this->apiKey = config('scraping.scrapingbee.api_key');
        $this->baseUrl = config('scraping.scrapingbee.base_url');
        $this->timeout = config('scraping.scrapingbee.timeout');

        if (empty($this->apiKey)) {
            throw new \RuntimeException('ScrapingBee API key is not configured');
        }
    }

    /**
     * Fetch HTML content from a URL using ScrapingBee
     *
     * @param string $url The URL to scrape
     * @return string The HTML content
     * @throws ScrapingException
     */
    public function fetch(string $url): string
    {
        try {
            Log::info('ScrapingBee: Fetching URL', ['url' => $url]);

            // Advanced parameters for JavaScript-heavy sites like Kayak
            // IMPORTANT: ScrapingBee requires lowercase 'true'/'false' strings for boolean params
            $params = [
                'api_key' => $this->apiKey,
                'url' => $url,
                // Enable JavaScript rendering - CRITICAL for Kayak
                'render_js' => 'true',
                // Use premium residential proxies to avoid detection
                'premium_proxy' => 'true',
                // Set country to US for consistent results
                'country_code' => 'us',
                // Wait 25 seconds for Kayak's JavaScript to fully load flights
                'wait' => '25000',
                // Keep resources enabled - Kayak needs CSS/JS loaded
                'block_resources' => 'false',
                // Disable custom google to get standard results
                'custom_google' => 'false',
            ];

            Log::info('ScrapingBee: Request parameters', [
                'wait' => $params['wait'],
                'render_js' => $params['render_js'],
                'premium_proxy' => $params['premium_proxy'],
            ]);

            $response = Http::timeout($this->timeout)
                ->get($this->baseUrl, $params);

            if ($response->failed()) {
                $statusCode = $response->status();
                $errorBody = $response->body();

                Log::error('ScrapingBee: Request failed', [
                    'status' => $statusCode,
                    'body' => substr($errorBody, 0, 500),
                ]);

                throw new ScrapingException(
                    "ScrapingBee request failed with status {$statusCode}",
                    $statusCode
                );
            }

            $html = $response->body();

            Log::info('ScrapingBee: Successfully fetched content', [
                'content_length' => strlen($html),
                'contains_nrc6' => strpos($html, 'nrc6') !== false,
                'contains_flight_data' => strpos($html, 'flight') !== false,
            ]);

            return $html;

        } catch (ScrapingException $e) {
            throw $e;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('ScrapingBee: Connection timeout', ['error' => $e->getMessage()]);
            throw new ScrapingException('ScrapingBee connection timeout: ' . $e->getMessage(), 504);
        } catch (\Exception $e) {
            Log::error('ScrapingBee: Unexpected error', ['error' => $e->getMessage()]);
            throw new ScrapingException('ScrapingBee error: ' . $e->getMessage(), 500);
        }
    }
}
