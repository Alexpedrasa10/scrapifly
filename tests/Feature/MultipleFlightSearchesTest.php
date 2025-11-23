<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Cache;

class MultipleFlightSearchesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Clear cache before tests to ensure fresh scraping
        Cache::flush();
    }

    /**
     * Test multiple flight searches with different routes and dates
     * This test ensures the API always returns data for various searches
     */
    public function test_multiple_flight_searches_return_data(): void
    {
        // Define 5 different search scenarios
        $searches = [
            [
                'name' => 'Malaga to Madrid',
                'origin' => 'AGP',
                'destination' => 'MAD',
                'departure_date' => '2026-01-12',
                'return_date' => '2026-01-15',
            ],
            [
                'name' => 'Barcelona to Madrid',
                'origin' => 'BCN',
                'destination' => 'MAD',
                'departure_date' => '2026-02-10',
                'return_date' => '2026-02-17',
            ],
            [
                'name' => 'Madrid to Barcelona',
                'origin' => 'MAD',
                'destination' => 'BCN',
                'departure_date' => '2026-03-05',
                'return_date' => '2026-03-12',
            ],
            [
                'name' => 'Seville to Madrid',
                'origin' => 'SVQ',
                'destination' => 'MAD',
                'departure_date' => '2026-04-20',
                'return_date' => '2026-04-25',
            ],
            [
                'name' => 'Valencia to Barcelona',
                'origin' => 'VLC',
                'destination' => 'BCN',
                'departure_date' => '2026-05-15',
                'return_date' => '2026-05-22',
            ],
        ];

        $results = [];

        foreach ($searches as $search) {
            echo "\n\n🔍 Testing: {$search['name']} ({$search['origin']} → {$search['destination']})\n";
            echo "   Dates: {$search['departure_date']} to {$search['return_date']}\n";

            $startTime = microtime(true);

            $response = $this->getJson('/api/flights?' . http_build_query([
                'origin' => $search['origin'],
                'destination' => $search['destination'],
                'departure_date' => $search['departure_date'],
                'return_date' => $search['return_date'],
            ]));

            $endTime = microtime(true);
            $duration = round(($endTime - $startTime) * 1000);

            // Assert response is successful
            $response->assertStatus(200);

            // Assert response has flights array
            $response->assertJsonStructure(['flights']);

            $data = $response->json();
            $flightCount = count($data['flights']);

            // CRITICAL: Assert that flights array is NOT empty
            $this->assertNotEmpty(
                $data['flights'],
                "Search '{$search['name']}' must return flights but returned empty array"
            );

            // Assert we have at least 1 flight
            $this->assertGreaterThanOrEqual(
                1,
                $flightCount,
                "Search '{$search['name']}' must return at least 1 flight"
            );

            // Validate each flight has correct structure
            foreach ($data['flights'] as $index => $flight) {
                $this->assertArrayHasKey('price', $flight, "Flight #{$index} missing price");
                $this->assertArrayHasKey('currency', $flight, "Flight #{$index} missing currency");
                $this->assertArrayHasKey('origin', $flight, "Flight #{$index} missing origin");
                $this->assertArrayHasKey('destination', $flight, "Flight #{$index} missing destination");
                $this->assertArrayHasKey('departure', $flight, "Flight #{$index} missing departure");
                $this->assertArrayHasKey('arrival', $flight, "Flight #{$index} missing arrival");
                $this->assertArrayHasKey('durationInMinutes', $flight, "Flight #{$index} missing duration");
                $this->assertArrayHasKey('stopCount', $flight, "Flight #{$index} missing stopCount");
                $this->assertArrayHasKey('marketingCarrier', $flight, "Flight #{$index} missing carrier");

                // Validate origin/destination structure
                $this->assertArrayHasKey('code', $flight['origin']);
                $this->assertArrayHasKey('city', $flight['origin']);
                $this->assertArrayHasKey('code', $flight['destination']);
                $this->assertArrayHasKey('city', $flight['destination']);

                // Validate carrier structure
                $this->assertArrayHasKey('code', $flight['marketingCarrier']);
                $this->assertArrayHasKey('name', $flight['marketingCarrier']);

                // Validate values are reasonable
                $this->assertIsInt($flight['price'], "Price must be integer");
                $this->assertGreaterThan(0, $flight['price'], "Price must be positive");
                $this->assertEquals($search['origin'], $flight['origin']['code']);
                $this->assertEquals($search['destination'], $flight['destination']['code']);
            }

            // Get price range
            $prices = array_column($data['flights'], 'price');
            $minPrice = min($prices);
            $maxPrice = max($prices);

            $results[] = [
                'name' => $search['name'],
                'route' => "{$search['origin']} → {$search['destination']}",
                'flights_found' => $flightCount,
                'duration_ms' => $duration,
                'price_range' => "\${$minPrice} - \${$maxPrice}",
            ];

            echo "   ✅ Success: Found {$flightCount} flights in {$duration}ms\n";
            echo "   💰 Price range: \${$minPrice} - \${$maxPrice}\n";

            // Small delay between requests to be respectful to the API
            if ($search !== end($searches)) {
                echo "   ⏳ Waiting 2 seconds before next request...\n";
                sleep(2);
            }
        }

        // Print summary
        echo "\n\n" . str_repeat("=", 70) . "\n";
        echo "📊 TEST SUMMARY\n";
        echo str_repeat("=", 70) . "\n\n";

        foreach ($results as $result) {
            echo sprintf(
                "%-30s | %2d flights | %6dms | %s\n",
                $result['name'],
                $result['flights_found'],
                $result['duration_ms'],
                $result['price_range']
            );
        }

        echo "\n" . str_repeat("=", 70) . "\n";
        echo "✅ ALL TESTS PASSED - All searches returned flight data!\n";
        echo str_repeat("=", 70) . "\n\n";

        // Final assertion: all searches must have returned data
        $this->assertCount(5, $results, "Must have completed 5 searches");
        foreach ($results as $result) {
            $this->assertGreaterThan(0, $result['flights_found']);
        }
    }

    /**
     * Test that flight data persists in cache
     */
    public function test_flight_data_persists_in_cache(): void
    {
        $params = [
            'origin' => 'AGP',
            'destination' => 'MAD',
            'departure_date' => '2026-01-12',
            'return_date' => '2026-01-15',
        ];

        echo "\n🔍 Testing cache persistence...\n";

        // First request (scrapes)
        $startTime1 = microtime(true);
        $response1 = $this->getJson('/api/flights?' . http_build_query($params));
        $duration1 = round((microtime(true) - $startTime1) * 1000);

        $response1->assertStatus(200);
        $data1 = $response1->json();

        echo "   First request (scraping): {$duration1}ms\n";
        echo "   Flights found: " . count($data1['flights']) . "\n";

        // Second request (from cache, should be much faster)
        $startTime2 = microtime(true);
        $response2 = $this->getJson('/api/flights?' . http_build_query($params));
        $duration2 = round((microtime(true) - $startTime2) * 1000);

        $response2->assertStatus(200);
        $data2 = $response2->json();

        echo "   Second request (cached): {$duration2}ms\n";
        echo "   Flights found: " . count($data2['flights']) . "\n";

        // Both should return data
        $this->assertNotEmpty($data1['flights'], "First request must return flights");
        $this->assertNotEmpty($data2['flights'], "Cached request must return flights");

        // Both should return the same number of flights
        $this->assertEquals(
            count($data1['flights']),
            count($data2['flights']),
            "Cached data should match original data"
        );

        // Cached request should be significantly faster
        $this->assertLessThan(
            $duration1,
            $duration2,
            "Cached request should be faster than scraping"
        );

        echo "   ✅ Cache working correctly (speedup: " . round($duration1 / $duration2, 1) . "x)\n";
    }
}
