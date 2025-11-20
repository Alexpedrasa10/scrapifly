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

            $response = Http::timeout($this->timeout)
                ->get($this->baseUrl, [
                    'api_key' => $this->apiKey,
                    'url' => $url,
                    'render_js' => 'true',
                    'premium_proxy' => 'true',
                    'country_code' => 'us',
                ]);

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
