<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * Pins the public route contract (method, URL, name, action and the set of middleware that apply) while the
 * routing and authorization code is refactored. A failure means a route was added, removed or changed; if that
 * was intended, regenerate the snapshot with UPDATE_ROUTE_SNAPSHOT=1 and review the diff.
 */
final class RouteContractTest extends TestCase
{
    private const SNAPSHOT = __DIR__.'/fixtures/routes.snapshot.json';

    public function test_route_contract_matches_the_snapshot(): void
    {
        $current = $this->contract();

        if (getenv('UPDATE_ROUTE_SNAPSHOT') === '1') {
            file_put_contents(self::SNAPSHOT, json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        }

        $this->assertFileExists(self::SNAPSHOT, 'Run once with UPDATE_ROUTE_SNAPSHOT=1 to create the snapshot.');
        $expected = json_decode((string) file_get_contents(self::SNAPSHOT), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($expected, $current);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contract(): array
    {
        $router = app('router');
        $rows = [];

        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            $uri = $route->uri();
            if (str_starts_with($uri, '_boost') || str_starts_with($uri, 'storage/')) {
                continue;
            }

            $middleware = array_map(
                static fn (mixed $item): string => is_string($item) ? $item : 'closure',
                $router->gatherRouteMiddleware($route),
            );
            $middleware = array_values(array_unique($middleware));
            sort($middleware);

            $methods = array_values(array_diff($route->methods(), ['HEAD']));
            sort($methods);

            $rows[] = [
                'methods' => $methods,
                'uri' => $uri,
                'name' => $route->getName(),
                'action' => is_string($route->getActionName()) ? $route->getActionName() : 'Closure',
                'defaults' => $route->defaults,
                'middleware' => $middleware,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$a['uri'], implode('|', $a['methods'])] <=> [$b['uri'], implode('|', $b['methods'])]);

        return $rows;
    }
}
