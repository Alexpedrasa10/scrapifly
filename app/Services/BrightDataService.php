<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Exceptions\ScrapingException;

class BrightDataService
{
    private string $proxyHost;
    private int $proxyPort;
    private string $proxyUser;
    private string $proxyPass;
    private int $timeout;

    public function __construct()
    {
        $this->proxyHost = config('scraping.brightdata.proxy_host');
        $this->proxyPort = config('scraping.brightdata.proxy_port');
        $this->proxyUser = config('scraping.brightdata.proxy_user');
        $this->proxyPass = config('scraping.brightdata.proxy_pass');
        $this->timeout = config('scraping.brightdata.timeout');

        if (empty($this->proxyUser) || empty($this->proxyPass)) {
            throw new \RuntimeException('Bright Data proxy credentials are not configured');
        }
    }

    /**
     * Fetch HTML content from a URL using Bright Data proxy
     *
     * @param string $url The URL to scrape
     * @return string The HTML content
     * @throws ScrapingException
     */
    public function fetch(string $url): string
    {
        try {
            Log::info('BrightData: Fetching URL', ['url' => $url]);

            // Generate a consistent session ID for Kayak
            // This helps maintain cookies and avoid repeated CAPTCHAs
            $sessionId = md5($url);

            // Build proxy string with session support and residential IPs
            // Format: username-session-{id}-country-us:password@host:port
            $proxyUser = sprintf(
                '%s-session-%s-country-us',
                $this->proxyUser,
                $sessionId
            );

            Log::info('BrightData: Using session', ['session_id' => $sessionId]);

            $response = Http::timeout($this->timeout)
                ->withOptions([
                    'proxy' => sprintf(
                        'http://%s:%s@%s:%d',
                        urlencode($proxyUser),
                        urlencode($this->proxyPass),
                        $this->proxyHost,
                        $this->proxyPort
                    ),
                    'verify' => false,
                    // Allow redirects
                    'allow_redirects' => true,
                ])
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.5',
                    'Accept-Encoding' => 'gzip, deflate, br',
                    'Connection' => 'keep-alive',
                    'Upgrade-Insecure-Requests' => '1',
                    'Sec-Fetch-Dest' => 'document',
                    'Sec-Fetch-Mode' => 'navigate',
                    'Sec-Fetch-Site' => 'none',
                ])
                ->get($url);

            if ($response->failed()) {
                $statusCode = $response->status();
                $errorBody = $response->body();

                Log::error('BrightData: Request failed', [
                    'status' => $statusCode,
                    'body' => substr($errorBody, 0, 500),
                ]);

                throw new ScrapingException(
                    "Proxy request failed with status {$statusCode}",
                    $statusCode
                );
            }

            $html = $response->body();

            Log::info('BrightData: Successfully fetched content', [
                'content_length' => strlen($html),
                'contains_nrc6' => strpos($html, 'nrc6') !== false,
                'contains_flight_data' => strpos($html, 'flight') !== false,
            ]);

            return $html;

        } catch (ScrapingException $e) {
            throw $e;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('BrightData: Connection timeout', ['error' => $e->getMessage()]);
            throw new ScrapingException('Proxy connection timeout: ' . $e->getMessage(), 504);
        } catch (\Exception $e) {
            Log::error('BrightData: Unexpected error', ['error' => $e->getMessage()]);
            throw new ScrapingException('Proxy error: ' . $e->getMessage(), 500);
        }
    }
}
