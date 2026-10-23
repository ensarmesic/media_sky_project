<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        //
    }

    /**
     * Greške se vraćaju kao JSON za api/* i zahtjeve koji očekuju JSON.
     */
    protected function shouldReturnJson($request, Throwable $e): bool
    {
        /** @var Request $request */
        return $request->is('api/*') || $request->expectsJson();
    }
}
