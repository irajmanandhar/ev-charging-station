<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/** Keeps openapi.yaml honest: every API route is documented, and nothing documented is gone. */
class OpenApiTest extends TestCase
{
    private function documented(): array
    {
        $spec = Yaml::parseFile(base_path('openapi.yaml'));
        $found = [];
        foreach ($spec['paths'] as $path => $item) {
            foreach (array_keys($item) as $method) {
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $found[] = strtoupper($method).' '.$path;
                }
            }
        }
        sort($found);

        return $found;
    }

    private function actual(): array
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                $routes[] = $method.' /'.substr($route->uri(), 4);
            }
        }
        sort($routes);

        return $routes;
    }

    public function test_spec_and_routes_match(): void
    {
        $this->assertSame($this->actual(), $this->documented());
    }

    public function test_every_reference_resolves(): void
    {
        $raw = file_get_contents(base_path('openapi.yaml'));
        preg_match_all('/\$ref: "#\/([^"]+)"/', $raw, $m);
        $spec = Yaml::parse($raw);

        foreach (array_unique($m[1]) as $pointer) {
            $node = $spec;
            foreach (explode('/', $pointer) as $part) {
                $this->assertIsArray($node, $pointer);
                $this->assertArrayHasKey($part, $node, "Unresolved \$ref: #/{$pointer}");
                $node = $node[$part];
            }
        }
    }
}
