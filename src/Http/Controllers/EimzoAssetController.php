<?php

namespace AsadbekRahimov\EimzoIntegration\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EimzoAssetController extends Controller
{
    public function __invoke(string $path): BinaryFileResponse
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '' || strpos($path, '..') !== false) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $base = realpath(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'js');
        if ($base === false) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $file = realpath($base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path));
        if (
            $file === false
            || ! is_file($file)
            || strpos($file, $base . DIRECTORY_SEPARATOR) !== 0
            || pathinfo($file, PATHINFO_EXTENSION) !== 'js'
        ) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return response()->file($file, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=' . (int) config('eimzo.assets.cache_seconds', 3600),
        ]);
    }
}
