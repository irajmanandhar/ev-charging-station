<?php
$root = getenv('BACKEND_PATH') ?: '/var/www';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{Charger, Station, User};
$op = User::first();
$station = Station::create(['operator_id' => $op->id, 'name' => 'Race Two', 'latitude' => 27.71, 'longitude' => 85.31, 'is_24_hours' => true]);
$ids = [];
for ($i = 1; $i <= 8; $i++) {
    $ids[] = Charger::create(['station_id' => $station->id, 'label' => "C$i", 'connector_type' => 'CCS2', 'power_kw' => 60, 'price_per_kwh' => 20])->id;
}
$u = User::create(['name' => 'Solo', 'email' => 'solo'.uniqid().'@race.test', 'password' => 'password', 'email_verified_at' => now()]);
echo json_encode(['chargers' => $ids, 'token' => $u->createToken('r')->plainTextToken]);
