<?php

namespace Database\Seeders;

use App\Enums\BookingStatus;
use App\Enums\ChargerStatus;
use App\Enums\SessionStatus;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Charger;
use App\Models\Station;
use App\Models\User;
use App\Services\PricingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Demo data: fictional stations around the Kathmandu valley (plus Pokhara and Dhulikhel),
 * 60 days of charging history, and some live activity "right now".
 *
 * Demo logins (password: "password"):
 *   admin@evcharge.test, operator@evcharge.test, operator2@evcharge.test, driver@evcharge.test
 */
class DatabaseSeeder extends Seeder
{
    private const HISTORY_DAYS = 60;

    public function run(PricingService $pricing): void
    {
        mt_srand(2026);
        $password = Hash::make('password');

        $make = fn (string $name, string $email, UserRole $role) => User::factory()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => $role,
        ]);

        $make('Platform Admin', 'admin@evcharge.test', UserRole::Admin);
        $opA = $make('Himal EV Networks', 'operator@evcharge.test', UserRole::Operator);
        $opB = $make('Valley Charge Co.', 'operator2@evcharge.test', UserRole::Operator);
        $driver = $make('Demo Driver', 'driver@evcharge.test', UserRole::Driver);

        $drivers = collect([$driver])->merge(
            User::factory(24)->create(['password' => $password])
        );

        $stations = $this->stations($opA, $opB);
        $chargers = $stations->flatMap(fn (Station $s) => $s->chargers);

        $this->history($chargers, $drivers, $pricing);
        $this->liveActivity($chargers, $drivers, $driver, $pricing);
    }

    private function stations(User $opA, User $opB)
    {
        $specs = [
            // name, area, city, lat, lng, operator, 24h?, opens, closes, amenities, chargers
            ['Thamel Plaza Charging Hub', 'Amrit Marg, Thamel', 'Kathmandu', 27.7154, 85.3123, $opA, false, '07:00', '22:00', ['Parking', 'Cafe', 'Wi-Fi'], [['CCS2', 60, 22], ['CCS2', 60, 22], ['Type2', 22, 16]]],
            ['Baneshwor Fast Charge', 'New Baneshwor Chowk', 'Kathmandu', 27.6915, 85.3420, $opA, true, null, null, ['Parking', 'Restroom', '24/7 security'], [['CCS2', 120, 25], ['CCS2', 120, 25], ['CHAdeMO', 50, 22], ['Type2', 22, 16]]],
            ['Jawalakhel Park & Charge', 'Jawalakhel', 'Lalitpur', 27.6730, 85.3130, $opA, false, '06:00', '21:00', ['Parking', 'Restroom'], [['CCS2', 60, 21], ['GBT', 60, 20]]],
            ['Boudha Stupa EV Point', 'Boudha Road', 'Kathmandu', 27.7215, 85.3620, $opA, false, '06:00', '22:00', ['Cafe', 'Restroom'], [['CCS2', 30, 18], ['Type2', 22, 15]]],
            ['Maharajgunj Ring Road Charging', 'Ring Road, Maharajgunj', 'Kathmandu', 27.7395, 85.3307, $opA, true, null, null, ['Parking', 'Convenience store'], [['CCS2', 150, 26], ['CCS2', 150, 26], ['GBT', 120, 24]]],
            ['Pokhara Lakeside Charge Stop', 'Lakeside Road', 'Pokhara', 28.2096, 83.9585, $opA, false, '07:00', '21:00', ['Parking', 'Cafe', 'Lake view'], [['CCS2', 60, 20], ['Type2', 22, 15]]],
            ['Kalanki Highway Gateway', 'Kalanki Chowk', 'Kathmandu', 27.6936, 85.2810, $opB, true, null, null, ['Parking', 'Restroom', 'Food court'], [['CCS2', 120, 24], ['GBT', 60, 20], ['CHAdeMO', 50, 21]]],
            ['Koteshwor Transit Charging', 'Koteshwor', 'Kathmandu', 27.6780, 85.3490, $opB, false, '06:00', '23:00', ['Parking'], [['CCS2', 60, 19], ['GBT', 60, 19]]],
            ['Bhaktapur Durbar Charge Point', 'Suryabinayak Road', 'Bhaktapur', 27.6710, 85.4298, $opB, false, '07:00', '20:00', ['Parking', 'Restroom'], [['CCS2', 30, 17], ['Type2', 11, 14]]],
            ['Kirtipur Campus Chargers', 'Naya Bazaar', 'Kirtipur', 27.6788, 85.2775, $opB, false, '07:00', '19:00', ['Parking'], [['Type2', 22, 14], ['Type2', 22, 14]]],
            ['Budhanilkantha Hillside Charge', 'Budhanilkantha', 'Kathmandu', 27.7650, 85.3650, $opB, false, '08:00', '20:00', ['Parking', 'Cafe'], [['CCS2', 60, 21]]],
            ['Dhulikhel Highway Rest Stop', 'Araniko Highway', 'Dhulikhel', 27.6200, 85.5560, $opB, true, null, null, ['Parking', 'Restroom', 'Food court'], [['CCS2', 120, 24], ['GBT', 120, 23]]],
        ];

        return collect($specs)->map(function ($s) {
            [$name, $address, $city, $lat, $lng, $op, $is24, $opens, $closes, $amenities, $chargers] = $s;

            $station = Station::create([
                'operator_id' => $op->id,
                'name' => $name,
                'description' => "{$name} offers ".count($chargers).' charging '.(count($chargers) > 1 ? 'bays' : 'bay')." in {$city}. Book a slot ahead or charge on arrival when a bay is free.",
                'address' => $address,
                'city' => $city,
                'latitude' => $lat,
                'longitude' => $lng,
                'timezone' => 'Asia/Kathmandu',
                'is_24_hours' => $is24,
                'opens_at' => $opens,
                'closes_at' => $closes,
                'amenities' => $amenities,
                'status' => 'active',
            ]);

            foreach ($chargers as $i => [$type, $kw, $price]) {
                $station->chargers()->create([
                    'label' => chr(65 + intdiv($i, 2)).(($i % 2) + 1), // A1, A2, B1, ...
                    'connector_type' => $type,
                    'power_kw' => $kw,
                    'price_per_kwh' => $price,
                    'status' => ChargerStatus::Available,
                ]);
            }

            return $station->load('chargers');
        });
    }

    /** Completed sessions (and some no-shows/cancellations) over the last HISTORY_DAYS days. */
    private function history($chargers, $drivers, PricingService $pricing): void
    {
        $tz = 'Asia/Kathmandu';
        $bookings = [];
        $sessions = [];
        $now = now();
        $bookingId = 0;

        foreach ($chargers as $charger) {
            $station = $charger->station;
            // Busier, faster chargers see more traffic; weekends a bit more.
            $base = $charger->power_kw >= 100 ? 6 : ($charger->power_kw >= 50 ? 4 : 2);
            $growth = 0.6; // demand grows over the period

            for ($d = self::HISTORY_DAYS; $d >= 1; $d--) {
                $day = now($tz)->subDays($d)->startOfDay();
                $openH = $station->is_24_hours ? 6 : (int) substr($station->opens_at, 0, 2);
                $closeH = $station->is_24_hours ? 23 : (int) substr($station->closes_at, 0, 2);

                $trend = 1 + $growth * (self::HISTORY_DAYS - $d) / self::HISTORY_DAYS;
                $weekend = $day->isSaturday() ? 1.3 : 1.0;
                $count = max(0, (int) round(($base + mt_rand(-1, 2)) * $trend * $weekend * 0.8));

                $cursor = $day->copy()->setTime($openH, 0)->addMinutes(15 * mt_rand(0, 6));
                for ($n = 0; $n < $count; $n++) {
                    $duration = [30, 45, 60, 60, 90, 120][mt_rand(0, 5)];
                    $start = $cursor->copy();
                    $end = $start->copy()->addMinutes($duration);
                    if ($end->gt($day->copy()->setTime($closeH, 0))) {
                        break;
                    }

                    $user = $drivers[mt_rand(0, $drivers->count() - 1)];
                    $estimate = $pricing->quote($charger, $duration);
                    $roll = mt_rand(1, 100);
                    $status = $roll <= 6 ? BookingStatus::NoShow : ($roll <= 12 ? BookingStatus::Cancelled : BookingStatus::Completed);

                    $bookingId++;
                    $actualMinutes = $duration - 15 * mt_rand(0, max(0, intdiv($duration, 15) - 2));
                    $actualEnd = $start->copy()->addMinutes($actualMinutes);

                    $bookings[] = [
                        'id' => $bookingId,
                        'reference' => 'EV-'.strtoupper(Str::random(8)),
                        'user_id' => $user->id,
                        'charger_id' => $charger->id,
                        'starts_at' => $start->copy()->utc(),
                        'ends_at' => ($status === BookingStatus::Completed ? $actualEnd : $end)->copy()->utc(),
                        'status' => $status->value,
                        'vehicle' => null,
                        'estimated_kwh' => $estimate['kwh'],
                        'estimated_cost' => $estimate['cost'],
                        'cancelled_at' => $status === BookingStatus::Cancelled ? $start->copy()->subHours(3)->utc() : null,
                        'created_at' => $start->copy()->subHours(mt_rand(1, 48))->utc(),
                        'updated_at' => $now,
                    ];

                    if ($status === BookingStatus::Completed) {
                        $kwh = $pricing->energyFor($charger, $actualMinutes * (mt_rand(80, 100) / 100));
                        $sessions[] = [
                            'user_id' => $user->id,
                            'charger_id' => $charger->id,
                            'booking_id' => $bookingId,
                            'started_at' => $start->copy()->addMinutes(mt_rand(0, 4))->utc(),
                            'ended_at' => $actualEnd->copy()->utc(),
                            'energy_kwh' => $kwh,
                            'cost' => $pricing->costFor($charger, $kwh),
                            'status' => SessionStatus::Completed->value,
                            'created_at' => $start->copy()->utc(),
                            'updated_at' => $now,
                        ];
                    }

                    // Gap until the next booking on this charger.
                    $cursor = $end->copy()->addMinutes(15 * mt_rand(0, 8));
                }
            }
        }

        foreach (array_chunk($bookings, 500) as $chunk) {
            DB::table('bookings')->insert($chunk);
        }
        foreach (array_chunk($sessions, 500) as $chunk) {
            DB::table('charging_sessions')->insert($chunk);
        }
    }

    /** A few chargers busy right now, some reserved soon, and upcoming bookings for the demo driver. */
    private function liveActivity($chargers, $drivers, User $demoDriver, PricingService $pricing): void
    {
        $now = now()->startOfMinute();
        $slot = fn (Carbon $t) => $t->copy()->setMinute(intdiv($t->minute, 15) * 15 + 15)->setSecond(0); // next quarter hour

        $open = $chargers->filter(fn (Charger $c) => $c->station->isOpenBetween($now, $now->copy()->addHours(2)))->values();

        // Each live or upcoming booking goes to a different driver, so no driver gets overlapping
        // bookings (which the API itself would refuse). Index 0 is the demo driver, handled below.
        $pool = $drivers->slice(1)->shuffle()->values();
        $nextDriver = function () use (&$pool, $drivers) {
            if ($pool->isEmpty()) {
                $pool = $drivers->slice(1)->shuffle()->values();
            }

            return $pool->shift();
        };

        // Charging right now on every third open charger.
        foreach ($open->filter(fn ($c, $i) => $i % 3 === 0) as $charger) {
            $user = $nextDriver();
            $start = $now->copy()->subMinutes(mt_rand(5, 40));
            $booking = $charger->bookings()->create([
                'user_id' => $user->id,
                'starts_at' => $start,
                'ends_at' => $start->copy()->addMinutes(90),
                'status' => BookingStatus::Active,
                'estimated_kwh' => $pricing->energyFor($charger, 90),
                'estimated_cost' => $pricing->costFor($charger, $pricing->energyFor($charger, 90)),
            ]);
            $charger->chargingSessions()->create([
                'user_id' => $user->id,
                'booking_id' => $booking->id,
                'started_at' => $start,
                'status' => SessionStatus::Active,
            ]);
        }

        // Reserved: bookings starting within the next 15 minutes.
        foreach ($open->filter(fn ($c, $i) => $i % 3 === 1 && $i % 2 === 0) as $charger) {
            $start = $slot($now);
            $charger->bookings()->create([
                'user_id' => $nextDriver()->id,
                'starts_at' => $start,
                'ends_at' => $start->copy()->addMinutes(60),
                'status' => BookingStatus::Confirmed,
                'estimated_kwh' => $pricing->energyFor($charger, 60),
                'estimated_cost' => $pricing->costFor($charger, $pricing->energyFor($charger, 60)),
            ]);
        }

        // Upcoming bookings spread over the next few days.
        foreach ($chargers->random(min(10, $chargers->count())) as $i => $charger) {
            $day = now('Asia/Kathmandu')->addDays(1 + $i % 4)->startOfDay();
            $hour = $charger->station->is_24_hours ? mt_rand(8, 20) : mt_rand(
                (int) substr($charger->station->opens_at, 0, 2) + 1,
                (int) substr($charger->station->closes_at, 0, 2) - 2
            );
            $start = $day->setTime($hour, [0, 15, 30, 45][mt_rand(0, 3)])->utc();
            $minutes = [30, 60, 90][mt_rand(0, 2)];
            if (Booking::query()->overlapping($charger->id, $start, $start->copy()->addMinutes($minutes))->exists()
                || ! $charger->station->isOpenBetween($start, $start->copy()->addMinutes($minutes))) {
                continue; // the API would refuse it too
            }

            $charger->bookings()->create([
                'user_id' => $nextDriver()->id,
                'starts_at' => $start,
                'ends_at' => $start->copy()->addMinutes($minutes),
                'status' => BookingStatus::Confirmed,
                'estimated_kwh' => $pricing->energyFor($charger, $minutes),
                'estimated_cost' => $pricing->costFor($charger, $pricing->energyFor($charger, $minutes)),
            ]);
        }

        // One upcoming booking for the demo driver, tomorrow 10:00 at Baneshwor.
        $baneshwor = $chargers->first(fn (Charger $c) => str_starts_with($c->station->name, 'Baneshwor') && $c->label === 'B2');
        $start = now('Asia/Kathmandu')->addDay()->setTime(10, 0)->utc();
        Booking::query()->overlapping($baneshwor->id, $start, $start->copy()->addHour())->delete();
        $baneshwor->bookings()->create([
            'user_id' => $demoDriver->id,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addMinutes(60),
            'status' => BookingStatus::Confirmed,
            'vehicle' => 'BYD Atto 3',
            'estimated_kwh' => $pricing->energyFor($baneshwor, 60),
            'estimated_cost' => $pricing->costFor($baneshwor, $pricing->energyFor($baneshwor, 60)),
        ]);

        // One charger under maintenance, so the "offline" state shows up.
        $chargers->first(fn (Charger $c) => str_starts_with($c->station->name, 'Koteshwor'))
            ?->update(['status' => ChargerStatus::Maintenance]);
    }
}
