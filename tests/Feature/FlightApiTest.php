<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use App\Services\BrightDataService;
use App\Services\ScrapingBeeService;
use App\Services\FlightService;
use App\Services\KayakParserService;

class FlightApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * Test health endpoint returns correct structure
     */
    public function test_health_endpoint_returns_correct_structure(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'timestamp',
                'cache' => [
                    'last_scrape_age_seconds',
                    'ttl_seconds',
                ],
            ])
            ->assertJson([
                'status' => 'ok',
            ]);
    }

    /**
     * Test flights endpoint validation
     */
    public function test_flights_endpoint_requires_all_parameters(): void
    {
        $response = $this->getJson('/api/flights');

        $response->assertStatus(422)
            ->assertJsonStructure([
                'error',
                'messages',
            ]);
    }

    /**
     * Test flights endpoint validates date format
     */
    public function test_flights_endpoint_validates_date_format(): void
    {
        $response = $this->getJson('/api/flights?' . http_build_query([
            'origin' => 'AGP',
            'destination' => 'MAD',
            'departure_date' => '12-01-2026', // Wrong format
            'return_date' => '2026-01-15',
        ]));

        $response->assertStatus(422);
    }

    /**
     * Test flights endpoint validates airport codes
     */
    public function test_flights_endpoint_validates_airport_codes(): void
    {
        $response = $this->getJson('/api/flights?' . http_build_query([
            'origin' => 'INVALID', // Too long
            'destination' => 'MAD',
            'departure_date' => '2026-01-12',
            'return_date' => '2026-01-15',
        ]));

        $response->assertStatus(422);
    }

    /**
     * Test flights endpoint validates return date is after departure
     */
    public function test_flights_endpoint_validates_return_date_after_departure(): void
    {
        $response = $this->getJson('/api/flights?' . http_build_query([
            'origin' => 'AGP',
            'destination' => 'MAD',
            'departure_date' => '2026-01-15',
            'return_date' => '2026-01-12', // Before departure
        ]));

        $response->assertStatus(422);
    }

    /**
     * Test cache behavior - second call returns cached data
     */
    public function test_cache_returns_cached_data_on_second_call(): void
    {
        // Mock the scraping service (ScrapingBee is the default provider)
        $this->mock(ScrapingBeeService::class, function ($mock) {
            $mock->shouldReceive('fetch')
                ->once() // Should only be called once
                ->andReturn($this->getSampleKayakHtml());
        });

        $params = [
            'origin' => 'AGP',
            'destination' => 'MAD',
            'departure_date' => '2026-01-12',
            'return_date' => '2026-01-15',
        ];

        // First call - should hit the scraper
        $response1 = $this->getJson('/api/flights?' . http_build_query($params));
        $response1->assertStatus(200);

        // Second call - should return cached data (scraper should not be called again)
        $response2 = $this->getJson('/api/flights?' . http_build_query($params));
        $response2->assertStatus(200);

        // Both responses should have the same structure
        $response1->assertJsonStructure(['flights']);
        $response2->assertJsonStructure(['flights']);
    }

    /**
     * Test flights response has correct structure
     */
    public function test_flights_response_has_correct_structure(): void
    {
        $this->mock(ScrapingBeeService::class, function ($mock) {
            $mock->shouldReceive('fetch')
                ->once()
                ->andReturn($this->getSampleKayakHtml());
        });

        $response = $this->getJson('/api/flights?' . http_build_query([
            'origin' => 'AGP',
            'destination' => 'MAD',
            'departure_date' => '2026-01-12',
            'return_date' => '2026-01-15',
        ]));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'flights',
            ]);

        // If flights are returned, check their structure
        $data = $response->json();
        if (!empty($data['flights'])) {
            $response->assertJsonStructure([
                'flights' => [
                    '*' => [
                        'price',
                        'currency',
                        'origin' => ['code', 'city'],
                        'destination' => ['code', 'city'],
                        'departure',
                        'arrival',
                        'durationInMinutes',
                        'stopCount',
                        'flightNumber',
                        'marketingCarrier' => ['code', 'name'],
                        'operatingCarrier' => ['code', 'name'],
                    ],
                ],
            ]);
        }
    }

    /**
     * Get sample Kayak HTML for testing
     */
    private function getSampleKayakHtml(): string
    {
        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head><title>Kayak Flights</title></head>
        <body>
            <div class="resultWrapper">
                <span class="price">$71</span>
                <span class="time">05:00</span>
                <span class="time">06:15</span>
                <div class="duration">1h 15m</div>
                <span class="stops">nonstop</span>
                <div class="carrier"><span>Air Europa</span></div>
            </div>
            <div class="resultWrapper">
                <span class="price">$85</span>
                <span class="time">10:30</span>
                <span class="time">11:45</span>
                <div class="duration">1h 15m</div>
                <span class="stops">nonstop</span>
                <div class="carrier"><span>Iberia</span></div>
            </div>
        </body>
        </html>
        HTML;
    }
}
