<?php

namespace App\Http\Controllers;

use App\Models\Router;
use App\Services\Mikrotik\ConnectedDeviceService;
use Exception;
use Inertia\Inertia;
use Inertia\Response;

class ConnectedDeviceController extends Controller
{
    public function index(ConnectedDeviceService $devices): Response
    {
        $routers = Router::latest('id')->get();

        $connected = [];
        $routerErrors = [];

        foreach ($routers as $router) {
            try {
                foreach ($devices->snapshot($router) as $device) {
                    $connected[] = $device;
                }
            } catch (Exception $exception) {
                $routerErrors[] = [
                    'router_name' => $router->name,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return Inertia::render('ConnectedDevices/Index', [
            'devices' => $connected,
            'routers' => $routers->map(fn (Router $router) => $router->only(['id', 'name'])),
            'routerErrors' => $routerErrors,
            'fetchedAt' => now()->toIso8601String(),
        ]);
    }
}
