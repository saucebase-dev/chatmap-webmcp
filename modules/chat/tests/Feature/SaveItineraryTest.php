<?php

namespace Modules\Chat\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;
use Modules\Chat\Ai\Tools\SaveItinerary;
use Modules\Chat\Models\OnboardingState;
use Tests\TestCase;

class SaveItineraryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // ShowOnMap caches hits for a day, so without this a place geocoded by
        // one test would answer for the same place in the next one.
        Cache::flush();
    }

    /**
     * Geocode by name, so each stop in a test can land somewhere different.
     *
     * Keyed on the part before the first comma, because every stop is written
     * "name, city, country" and so contains the plan's location too -- a
     * substring match would answer "Porto" for all of them and quietly put
     * every stop in the same place. Places not listed come back empty, which
     * is how a stop nobody can find is expressed.
     *
     * @param  array<string, array{float, float}>  $places
     */
    protected function fakeGeocoder(array $places): void
    {
        Http::fake(function ($request) use ($places) {
            $query = (string) $request['q'];
            $name = trim(explode(',', $query)[0]);

            if (! isset($places[$name])) {
                return Http::response([]);
            }

            [$latitude, $longitude] = $places[$name];

            return Http::response([[
                'lat' => (string) $latitude,
                'lon' => (string) $longitude,
                'display_name' => $query,
                // Nominatim orders this south, north, west, east. Porto is
                // roughly a tenth of a degree across.
                'boundingbox' => ['41.1000', '41.2000', '-8.7000', '-8.5000'],
            ]]);
        });
    }

    protected function state(?array $plan = null): OnboardingState
    {
        return OnboardingState::create([
            'conversation_id' => 'conversation-1',
            'phase' => 'mapping',
            'plan' => $plan ?? [
                'goal' => 'A rainy Sunday',
                'location' => 'Porto, Portugal',
                'details' => ['Companions' => 'two kids'],
            ],
        ]);
    }

    public function test_it_returns_a_map_view_of_the_stops_in_order(): void
    {
        $this->fakeGeocoder([
            'Porto' => [41.15, -8.61],
            'Casa do Infante' => [41.1407, -8.6141],
            'Livraria Lello' => [41.1468, -8.6148],
        ]);

        $result = json_decode((string) (new SaveItinerary($this->state()))->handle(new Request([
            'stops' => [
                ['time' => '10:30', 'title' => 'Museum', 'place' => 'Casa do Infante, Porto, Portugal'],
                ['title' => 'Bookshop', 'place' => 'Livraria Lello, Porto, Portugal', 'note' => 'Indoors'],
            ],
        ])), true);

        $this->assertSame('Porto, Portugal', $result['label']);
        $this->assertSame([
            ['time' => '10:30', 'title' => 'Museum', 'place' => 'Casa do Infante, Porto, Portugal', 'lat' => 41.1407, 'lon' => -8.6141],
            ['title' => 'Bookshop', 'place' => 'Livraria Lello, Porto, Portugal', 'note' => 'Indoors', 'lat' => 41.1468, 'lon' => -8.6148],
        ], $result['stops']);

        // The box has to frame every stop, or the camera cuts one off.
        [$west, $south, $east, $north] = array_map(floatval(...), $result['bbox']);
        $this->assertLessThan(-8.6148, $west);
        $this->assertLessThan(41.1407, $south);
        $this->assertGreaterThan(-8.6141, $east);
        $this->assertGreaterThan(41.1468, $north);
    }

    public function test_it_keeps_the_rest_of_the_plan_when_it_saves_the_stops(): void
    {
        $this->fakeGeocoder([
            'Porto' => [41.15, -8.61],
            'Casa do Infante' => [41.1407, -8.6141],
        ]);

        $state = $this->state();

        (new SaveItinerary($state))->handle(new Request([
            'stops' => [['title' => 'Museum', 'place' => 'Casa do Infante, Porto, Portugal']],
        ]));

        $plan = $state->fresh()->plan;

        $this->assertSame('A rainy Sunday', $plan['goal']);
        $this->assertSame('Porto, Portugal', $plan['location']);
        $this->assertSame(['Companions' => 'two kids'], $plan['details']);
        $this->assertCount(1, $plan['stops']);
    }

    public function test_it_replaces_the_previous_itinerary_rather_than_appending(): void
    {
        $this->fakeGeocoder([
            'Porto' => [41.15, -8.61],
            'Casa do Infante' => [41.1407, -8.6141],
            'Livraria Lello' => [41.1468, -8.6148],
        ]);

        $state = $this->state();
        $tool = new SaveItinerary($state);

        $tool->handle(new Request(['stops' => [['title' => 'Museum', 'place' => 'Casa do Infante, Porto, Portugal']]]));
        $tool->handle(new Request(['stops' => [['title' => 'Bookshop', 'place' => 'Livraria Lello, Porto, Portugal']]]));

        $stops = $state->fresh()->plan['stops'];

        $this->assertCount(1, $stops);
        $this->assertSame('Bookshop', $stops[0]['title']);
    }

    public function test_it_drops_a_stop_the_geocoder_cannot_find(): void
    {
        $this->fakeGeocoder([
            'Porto' => [41.15, -8.61],
            'Casa do Infante' => [41.1407, -8.6141],
        ]);

        $result = json_decode((string) (new SaveItinerary($this->state()))->handle(new Request([
            'stops' => [
                ['title' => 'Museum', 'place' => 'Casa do Infante, Porto, Portugal'],
                ['title' => 'Nowhere', 'place' => 'Qzxwv Imaginary Place'],
            ],
        ])), true);

        $this->assertCount(1, $result['stops']);
        $this->assertSame(['Qzxwv Imaginary Place (not found)'], $result['unplaced']);
    }

    public function test_it_drops_a_stop_the_geocoder_placed_in_another_region(): void
    {
        // A restaurant name matching somewhere of the same name 30km east is
        // the real failure this guards: one outlier stretches the box over the
        // whole region and the map zooms out to nothing.
        $this->fakeGeocoder([
            'Porto' => [41.15, -8.61],
            'Casa do Infante' => [41.1407, -8.6141],
            'Senhor Ze' => [41.1108, -8.2638],
        ]);

        $result = json_decode((string) (new SaveItinerary($this->state()))->handle(new Request([
            'stops' => [
                ['title' => 'Museum', 'place' => 'Casa do Infante, Porto, Portugal'],
                ['title' => 'Lunch', 'place' => 'Senhor Ze, Porto, Portugal'],
            ],
        ])), true);

        $this->assertCount(1, $result['stops']);
        $this->assertSame('Museum', $result['stops'][0]['title']);
        $this->assertSame(['Senhor Ze, Porto, Portugal (found, but too far from Porto, Portugal)'], $result['unplaced']);
    }

    public function test_it_keeps_a_day_that_has_plainly_moved_somewhere_else(): void
    {
        // A whole day elsewhere is a decision, not a geocoding accident, so the
        // area check stands down rather than refusing every stop and leaving
        // the visitor unable to plan a day trip at all.
        $this->fakeGeocoder([
            'Porto' => [41.15, -8.61],
            'Guimaraes Castle' => [41.4487, -8.2909],
            'Paco dos Duques' => [41.4479, -8.2925],
        ]);

        $result = json_decode((string) (new SaveItinerary($this->state()))->handle(new Request([
            'stops' => [
                ['title' => 'Castle', 'place' => 'Guimaraes Castle, Portugal'],
                ['title' => 'Palace', 'place' => 'Paco dos Duques, Guimaraes, Portugal'],
            ],
        ])), true);

        $this->assertCount(2, $result['stops']);
        $this->assertArrayNotHasKey('unplaced', $result);
    }

    public function test_it_drops_a_stop_on_a_point_another_stop_already_uses(): void
    {
        // Unnamed places give the model nothing to tell one from another, so it
        // answers with the same string twice. Stacked pins draw as one.
        $this->fakeGeocoder([
            'Porto' => [41.15, -8.61],
            'Livraria Lello' => [41.1468, -8.6148],
            'Casa do Infante' => [41.1407, -8.6141],
        ]);

        $result = json_decode((string) (new SaveItinerary($this->state()))->handle(new Request([
            'stops' => [
                ['title' => 'Bookshop', 'place' => 'Livraria Lello, Porto, Portugal'],
                ['title' => 'Another lookout', 'place' => 'Livraria Lello, Porto, Portugal'],
                ['title' => 'Museum', 'place' => 'Casa do Infante, Porto, Portugal'],
            ],
        ])), true);

        $this->assertSame(
            ['Bookshop', 'Museum'],
            array_column($result['stops'], 'title'),
        );
        $this->assertStringContainsString('the same point as "Bookshop"', $result['unplaced'][0]);
    }

    public function test_it_keeps_a_stop_just_outside_the_location_itself(): void
    {
        // The margin exists so a plan for Porto can still include the edge of
        // town; only a stop in another region is refused.
        $this->fakeGeocoder([
            'Porto' => [41.15, -8.61],
            'Matosinhos' => [41.2100, -8.6900],
        ]);

        $result = json_decode((string) (new SaveItinerary($this->state()))->handle(new Request([
            'stops' => [['title' => 'Seafood', 'place' => 'Matosinhos, Portugal']],
        ])), true);

        $this->assertCount(1, $result['stops']);
        $this->assertArrayNotHasKey('unplaced', $result);
    }

    public function test_it_falls_back_to_the_marker_coordinates_for_a_nameless_place(): void
    {
        // Unnamed viewpoints have no string a geocoder can find, so without
        // this the model can only offer the name of the area and a day of
        // lookouts collapses onto one point in the middle of it.
        $this->fakeGeocoder(['Porto' => [41.15, -8.61]]);

        $result = json_decode((string) (new SaveItinerary($this->state()))->handle(new Request([
            'stops' => [
                ['title' => 'First lookout', 'place' => 'Viewpoint 1', 'lat' => 41.1461, 'lon' => -8.6112],
                ['title' => 'Second lookout', 'place' => 'Viewpoint 2', 'lat' => 41.1479, 'lon' => -8.6135],
            ],
        ])), true);

        $this->assertSame(
            [[41.1461, -8.6112], [41.1479, -8.6135]],
            array_map(fn (array $stop): array => [$stop['lat'], $stop['lon']], $result['stops']),
        );
    }

    public function test_it_still_refuses_coordinates_that_are_nowhere_near_the_plan(): void
    {
        // The fallback is a copy of a find_places result, not a licence to
        // invent: the area check is what keeps it honest.
        $this->fakeGeocoder(['Porto' => [41.15, -8.61]]);

        $result = json_decode((string) (new SaveItinerary($this->state()))->handle(new Request([
            'stops' => [
                ['title' => 'Real', 'place' => 'Porto, Portugal'],
                ['title' => 'Invented', 'place' => 'Viewpoint 9', 'lat' => 0.5, 'lon' => 0.5],
            ],
        ])), true);

        $this->assertCount(1, $result['stops']);
        $this->assertStringContainsString('too far from Porto, Portugal', $result['unplaced'][0]);
    }

    public function test_it_refuses_a_day_scattered_across_the_world(): void
    {
        // "Viewpoint 1" is not unfindable, it is worse: the geocoder matches
        // somewhere of that name in Brazil. Three stops on three continents
        // are bad coordinates, not a day trip, and must not pass as one.
        $this->fakeGeocoder(['Porto' => [41.15, -8.61]]);

        $result = (string) (new SaveItinerary($this->state()))->handle(new Request([
            'stops' => [
                ['title' => 'One', 'place' => 'Viewpoint 1', 'lat' => -20.1015, 'lon' => -43.5005],
                ['title' => 'Two', 'place' => 'Viewpoint 4', 'lat' => 50.9155, 'lon' => -115.1483],
                ['title' => 'Three', 'place' => 'Viewpoint 8', 'lat' => 43.7283, 'lon' => -73.4986],
            ],
        ]));

        $this->assertStringContainsString('No stop could be placed', $result);
    }

    public function test_it_checks_nothing_when_the_plan_names_no_location(): void
    {
        $this->fakeGeocoder(['Senhor Ze' => [41.1108, -8.2638]]);

        $state = $this->state(['goal' => 'Lunch somewhere', 'location' => '', 'details' => []]);

        $result = json_decode((string) (new SaveItinerary($state))->handle(new Request([
            'stops' => [['title' => 'Lunch', 'place' => 'Senhor Ze']],
        ])), true);

        $this->assertCount(1, $result['stops']);
        $this->assertSame('Your itinerary', $result['label']);
    }

    public function test_it_leaves_the_itinerary_alone_when_no_stop_can_be_placed(): void
    {
        $this->fakeGeocoder(['Porto, Portugal' => [41.15, -8.61]]);

        $state = $this->state();

        $result = (string) (new SaveItinerary($state))->handle(new Request([
            'stops' => [['title' => 'Nowhere', 'place' => 'Qzxwv Imaginary Place']],
        ]));

        $this->assertStringContainsString('No stop could be placed', $result);
        $this->assertStringContainsString('Qzxwv Imaginary Place (not found)', $result);
        $this->assertArrayNotHasKey('stops', $state->fresh()->plan);
    }
}
