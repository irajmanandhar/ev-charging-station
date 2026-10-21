<?php
// Creates one station + charger and 24 verified drivers with API tokens; prints JSON for the race script.
$root = getenv('BACKEND_PATH') ?: '/var/www';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\{Charger, Station, User};
use Illuminate\Support\Facades\Hash;

$op = User::create(['name' => 'Op', 'email' => 'op'.uniqid().'@race.test', 'password' => 'password', 'email_verified_at' => now()]);
$op->role = App\Enums\UserRole::Operator;
$op->save();

$station = Station::create([
    'operator_id' => $op->id, 'name' => 'Race Station', 'latitude' => 27.7, 'longitude' => 85.3,
    'is_24_hours' => true,
]);
$charger = Charger::create(['station_id' => $station->id, 'label' => 'R1', 'connector_type' => 'CCS2', 'power_kw' => 60, 'price_per_kwh' => 20]);

$tokens = [];
for ($i = 0; $i < 24; $i++) {
    $u = User::create(['name' => "Racer $i", 'email' => "racer$i".uniqid().'@race.test', 'password' => 'password', 'email_verified_at' => now()]);
    $tokens[] = $u->createToken('race')->plainTextToken;
}

echo json_encode(['charger' => $charger->id, 'tokens' => $tokens]);
