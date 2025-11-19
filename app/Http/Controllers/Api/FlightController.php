<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FlightService;
use App\Exceptions\ScrapingException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FlightController extends Controller
{
    private FlightService $flightService;

    public function __construct(FlightService $flightService)
    {
        $this->flightService = $flightService;
    }

    /**
     * Get flights from Kayak
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'origin' => 'required|string|size:3|alpha',
            'destination' => 'required|string|size:3|alpha',
            'departure_date' => 'required|date_format:Y-m-d',
            'return_date' => 'required|date_format:Y-m-d|after_or_equal:departure_date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors(),
            ], 422);
        }

        $origin = strtoupper($request->input('origin'));
        $destination = strtoupper($request->input('destination'));
        $departureDate = $request->input('departure_date');
        $returnDate = $request->input('return_date');

        try {
            $flights = $this->flightService->getFlights(
                $origin,
                $destination,
                $departureDate,
                $returnDate
            );

            return response()->json([
                'flights' => $flights,
            ]);

        } catch (ScrapingException $e) {
            $statusCode = $e->getStatusCode();

            // Map to appropriate HTTP status
            if ($statusCode >= 400 && $statusCode < 500) {
                $httpStatus = 502; // Bad Gateway - upstream error
            } else {
                $httpStatus = $statusCode >= 500 ? 502 : 500;
            }

            return response()->json([
                'error' => 'Failed to fetch flight data',
                'message' => $e->getMessage(),
            ], $httpStatus);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Internal server error',
                'message' => config('app.debug') ? $e->getMessage() : 'An unexpected error occurred',
            ], 500);
        }
    }

    /**
     * Health check endpoint
     *
     * @return JsonResponse
     */
    public function health(): JsonResponse
    {
        $lastScrapeTimestamp = $this->flightService->getLastScrapeTimestamp();
        $cacheAgeSeconds = null;

        if ($lastScrapeTimestamp) {
            $cacheAgeSeconds = now()->timestamp - $lastScrapeTimestamp;
        }

        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toIso8601String(),
            'cache' => [
                'last_scrape_age_seconds' => $cacheAgeSeconds,
                'ttl_seconds' => $this->flightService->getCacheTtl(),
            ],
        ]);
    }
}
