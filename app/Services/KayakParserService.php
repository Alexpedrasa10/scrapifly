<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class KayakParserService
{
    /**
     * Parse Kayak HTML response and extract flight data
     *
     * @param string $html The HTML content from Kayak
     * @param string $origin Origin airport code
     * @param string $destination Destination airport code
     * @return array Normalized flight data
     */
    public function parse(string $html, string $origin, string $destination): array
    {
        $flights = [];

        // Try to find flight data in JSON embedded in the page
        $jsonFlights = $this->extractJsonData($html);

        if (!empty($jsonFlights)) {
            $flights = $this->normalizeJsonFlights($jsonFlights, $origin, $destination);
        } else {
            // Fallback to HTML parsing
            $flights = $this->parseHtmlFlights($html, $origin, $destination);
        }

        Log::info('KayakParser: Parsed flights', ['count' => count($flights)]);

        return $flights;
    }

    /**
     * Extract flight data from embedded JSON in the page
     */
    private function extractJsonData(string $html): array
    {
        $flights = [];

        // Kayak often embeds flight data in script tags as JSON
        // Look for various patterns where flight data might be stored

        // Pattern 1: Look for __NEXT_DATA__ (Next.js apps)
        if (preg_match('/<script[^>]*id="__NEXT_DATA__"[^>]*>(.+?)<\/script>/s', $html, $matches)) {
            $jsonData = json_decode($matches[1], true);
            if ($jsonData && isset($jsonData['props']['pageProps']['flightResults'])) {
                return $jsonData['props']['pageProps']['flightResults'];
            }
        }

        // Pattern 2: Look for window.__PRELOADED_STATE__
        if (preg_match('/window\.__PRELOADED_STATE__\s*=\s*({.+?});/s', $html, $matches)) {
            $jsonData = json_decode($matches[1], true);
            if ($jsonData && isset($jsonData['flightResults'])) {
                return $jsonData['flightResults'];
            }
        }

        // Pattern 3: Look for data-flights attribute or similar
        if (preg_match('/data-flights=["\'](.+?)["\']/s', $html, $matches)) {
            $decoded = html_entity_decode($matches[1]);
            $jsonData = json_decode($decoded, true);
            if ($jsonData) {
                return $jsonData;
            }
        }

        return $flights;
    }

    /**
     * Normalize flight data from JSON format
     */
    private function normalizeJsonFlights(array $jsonFlights, string $origin, string $destination): array
    {
        $normalized = [];

        foreach ($jsonFlights as $flight) {
            $normalized[] = $this->normalizeFlightData($flight, $origin, $destination);
        }

        return $normalized;
    }

    /**
     * Parse flights from HTML when JSON is not available
     */
    private function parseHtmlFlights(string $html, string $origin, string $destination): array
    {
        $flights = [];

        // Create DOM document
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);

        // Kayak flight result selectors - these may need adjustment based on current Kayak HTML structure
        $flightNodes = $xpath->query("//div[contains(@class, 'resultWrapper') or contains(@class, 'nrc6') or contains(@class, 'flight-result')]");

        if ($flightNodes->length === 0) {
            // Try alternative selectors
            $flightNodes = $xpath->query("//div[contains(@class, 'resultInner') or contains(@class, 'inner-grid')]");
        }

        foreach ($flightNodes as $index => $node) {
            $flight = $this->parseFlightNode($xpath, $node, $origin, $destination);
            if ($flight) {
                $flights[] = $flight;
            }
        }

        // If no flights found via DOM, try regex patterns as last resort
        if (empty($flights)) {
            $flights = $this->parseWithRegex($html, $origin, $destination);
        }

        return $flights;
    }

    /**
     * Parse a single flight node from DOM
     */
    private function parseFlightNode(\DOMXPath $xpath, \DOMNode $node, string $origin, string $destination): ?array
    {
        try {
            // Extract price
            $priceNode = $xpath->query(".//span[contains(@class, 'price') or contains(@class, 'price-text')]", $node)->item(0);
            $priceText = $priceNode ? trim($priceNode->textContent) : '';
            $price = $this->extractPrice($priceText);

            // Extract times
            $timeNodes = $xpath->query(".//span[contains(@class, 'time') or contains(@class, 'depart-time') or contains(@class, 'arrival-time')]", $node);
            $departureTime = $timeNodes->item(0) ? trim($timeNodes->item(0)->textContent) : '';
            $arrivalTime = $timeNodes->item(1) ? trim($timeNodes->item(1)->textContent) : '';

            // Extract duration
            $durationNode = $xpath->query(".//div[contains(@class, 'duration') or contains(@class, 'segment-duration')]", $node)->item(0);
            $durationText = $durationNode ? trim($durationNode->textContent) : '';
            $durationMinutes = $this->parseDuration($durationText);

            // Extract stops
            $stopsNode = $xpath->query(".//span[contains(@class, 'stops') or contains(@class, 'stop-count')]", $node)->item(0);
            $stopsText = $stopsNode ? trim($stopsNode->textContent) : '0';
            $stopCount = $this->parseStops($stopsText);

            // Extract airline
            $airlineNode = $xpath->query(".//div[contains(@class, 'carrier') or contains(@class, 'airline')]//span", $node)->item(0);
            $airlineName = $airlineNode ? trim($airlineNode->textContent) : 'Unknown';

            if ($price === null) {
                return null;
            }

            return [
                'price' => $price,
                'currency' => 'USD',
                'origin' => [
                    'code' => $origin,
                    'city' => $this->getCityName($origin),
                ],
                'destination' => [
                    'code' => $destination,
                    'city' => $this->getCityName($destination),
                ],
                'departure' => $departureTime,
                'arrival' => $arrivalTime,
                'durationInMinutes' => $durationMinutes,
                'stopCount' => $stopCount,
                'flightNumber' => null,
                'marketingCarrier' => [
                    'code' => $this->getAirlineCode($airlineName),
                    'name' => $airlineName,
                ],
                'operatingCarrier' => [
                    'code' => $this->getAirlineCode($airlineName),
                    'name' => $airlineName,
                ],
            ];
        } catch (\Exception $e) {
            Log::warning('KayakParser: Failed to parse flight node', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Parse flights using regex patterns as fallback
     */
    private function parseWithRegex(string $html, string $origin, string $destination): array
    {
        $flights = [];

        // Look for price patterns
        preg_match_all('/\$(\d+)/', $html, $priceMatches);

        // Look for time patterns (e.g., "6:30 am", "14:45")
        preg_match_all('/(\d{1,2}:\d{2})\s*(am|pm)?/i', $html, $timeMatches);

        // Look for duration patterns (e.g., "1h 15m", "2h 30min")
        preg_match_all('/(\d+)h\s*(\d+)?m/i', $html, $durationMatches);

        // If we found some data, create basic flight entries
        $numFlights = min(count($priceMatches[1]), 10); // Limit to 10 flights

        for ($i = 0; $i < $numFlights; $i++) {
            $price = isset($priceMatches[1][$i]) ? (int)$priceMatches[1][$i] : 0;

            if ($price > 0 && $price < 10000) { // Sanity check for price
                $flights[] = [
                    'price' => $price,
                    'currency' => 'USD',
                    'origin' => [
                        'code' => $origin,
                        'city' => $this->getCityName($origin),
                    ],
                    'destination' => [
                        'code' => $destination,
                        'city' => $this->getCityName($destination),
                    ],
                    'departure' => null,
                    'arrival' => null,
                    'durationInMinutes' => null,
                    'stopCount' => 0,
                    'flightNumber' => null,
                    'marketingCarrier' => [
                        'code' => null,
                        'name' => null,
                    ],
                    'operatingCarrier' => [
                        'code' => null,
                        'name' => null,
                    ],
                ];
            }
        }

        return $flights;
    }

    /**
     * Normalize a single flight from JSON data
     */
    private function normalizeFlightData(array $flight, string $origin, string $destination): array
    {
        return [
            'price' => $flight['price'] ?? $flight['displayPrice'] ?? null,
            'currency' => $flight['currency'] ?? 'USD',
            'origin' => [
                'code' => $flight['origin']['code'] ?? $origin,
                'city' => $flight['origin']['city'] ?? $this->getCityName($origin),
            ],
            'destination' => [
                'code' => $flight['destination']['code'] ?? $destination,
                'city' => $flight['destination']['city'] ?? $this->getCityName($destination),
            ],
            'departure' => $flight['departure'] ?? $flight['departureTime'] ?? null,
            'arrival' => $flight['arrival'] ?? $flight['arrivalTime'] ?? null,
            'durationInMinutes' => $flight['duration'] ?? $flight['durationInMinutes'] ?? null,
            'stopCount' => $flight['stops'] ?? $flight['stopCount'] ?? 0,
            'flightNumber' => $flight['flightNumber'] ?? null,
            'marketingCarrier' => [
                'code' => $flight['marketingCarrier']['code'] ?? $flight['airline']['code'] ?? null,
                'name' => $flight['marketingCarrier']['name'] ?? $flight['airline']['name'] ?? null,
            ],
            'operatingCarrier' => [
                'code' => $flight['operatingCarrier']['code'] ?? $flight['airline']['code'] ?? null,
                'name' => $flight['operatingCarrier']['name'] ?? $flight['airline']['name'] ?? null,
            ],
        ];
    }

    /**
     * Extract numeric price from price string
     */
    private function extractPrice(string $priceText): ?int
    {
        preg_match('/[\d,]+/', $priceText, $matches);
        if (!empty($matches[0])) {
            return (int) str_replace(',', '', $matches[0]);
        }
        return null;
    }

    /**
     * Parse duration text to minutes
     */
    private function parseDuration(string $durationText): ?int
    {
        if (preg_match('/(\d+)h\s*(\d+)?m?/i', $durationText, $matches)) {
            $hours = (int) $matches[1];
            $minutes = isset($matches[2]) ? (int) $matches[2] : 0;
            return ($hours * 60) + $minutes;
        }
        return null;
    }

    /**
     * Parse stops text to number
     */
    private function parseStops(string $stopsText): int
    {
        if (stripos($stopsText, 'nonstop') !== false || stripos($stopsText, 'direct') !== false) {
            return 0;
        }
        preg_match('/(\d+)/', $stopsText, $matches);
        return isset($matches[1]) ? (int) $matches[1] : 0;
    }

    /**
     * Get city name from airport code
     */
    private function getCityName(string $code): string
    {
        $cities = [
            'AGP' => 'Málaga',
            'MAD' => 'Madrid',
            'BCN' => 'Barcelona',
            'PMI' => 'Palma de Mallorca',
            'ALC' => 'Alicante',
            'SVQ' => 'Sevilla',
            'VLC' => 'Valencia',
            'BIO' => 'Bilbao',
        ];

        return $cities[$code] ?? $code;
    }

    /**
     * Get airline code from airline name
     */
    private function getAirlineCode(string $name): ?string
    {
        $airlines = [
            'Air Europa' => 'UX',
            'Iberia' => 'IB',
            'Vueling' => 'VY',
            'Ryanair' => 'FR',
            'EasyJet' => 'U2',
            'Air Nostrum' => 'YW',
        ];

        foreach ($airlines as $airlineName => $code) {
            if (stripos($name, $airlineName) !== false) {
                return $code;
            }
        }

        return null;
    }
}
