<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class Handler extends ExceptionHandler
{
    protected $dontReport = [
        ValidationException::class,
    ];

    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register()
    {
        $this->reportable(function (Throwable $e) {
            // custom reporting if needed
        });
    }

    protected function prepareJsonResponse($request, Throwable $e)
    {
        $status = 500;
        $errorCategory = 'SYSTEM_ERROR';

        if ($e instanceof ValidationException) {
            $status = 422;
            $errorCategory = 'VALIDATION_ERROR';
        } elseif ($e instanceof QueryException) {
            $status = 500;
            $errorCategory = 'DATABASE_ERROR';
        } elseif ($e instanceof HttpException) {
            $status = $e->getStatusCode();
            if ($status == 504 || $status == 408) {
                $errorCategory = 'TIMEOUT_ERROR';
            }
        }

        $response = parent::prepareJsonResponse($request, $e);

        // Attach error category in response body
        $responseData = $response->getData(true);
        $responseData['error_category'] = $errorCategory;
        $response->setData($responseData);

        return $response;
    }

    public function shouldReturnJson($request, Throwable $e)
    {
        return $request->expectsJson() || $request->is('api/*');
    }
}
