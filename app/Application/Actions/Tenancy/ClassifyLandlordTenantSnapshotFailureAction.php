<?php

namespace App\Application\Actions\Tenancy;

use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

class ClassifyLandlordTenantSnapshotFailureAction
{
    /**
     * @return array{
     *     retryable:bool,
     *     category:string,
     *     reason:string
     * }
     */
    public function execute(Throwable $throwable): array
    {
        if ($this->isPersistentFailure($throwable)) {
            return [
                'retryable' => false,
                'category' => 'persistent',
                'reason' => $this->persistentReason($throwable),
            ];
        }

        if ($this->isTransientFailure($throwable)) {
            return [
                'retryable' => true,
                'category' => 'transient',
                'reason' => $this->transientReason($throwable),
            ];
        }

        return [
            'retryable' => true,
            'category' => 'unknown',
            'reason' => 'unclassified_exception',
        ];
    }

    private function isPersistentFailure(Throwable $throwable): bool
    {
        if ($throwable instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            return true;
        }

        if ($throwable instanceof \InvalidArgumentException) {
            return true;
        }

        $message = mb_strtolower($throwable->getMessage());

        $persistentPatterns = [
            'unknown database',
            'base table or view not found',
            'table doesn\'t exist',
            'no such table',
            'access denied',
            'unknown column',
            'column not found',
        ];

        foreach ($persistentPatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function isTransientFailure(Throwable $throwable): bool
    {
        if ($throwable instanceof PDOException) {
            return true;
        }

        if ($throwable instanceof QueryException) {
            return true;
        }

        $message = mb_strtolower($throwable->getMessage());

        $transientPatterns = [
            'connection refused',
            'connection timed out',
            'too many connections',
            'gone away',
            'lost connection',
            'deadlock',
            'lock wait timeout',
            'server has gone away',
            'broken pipe',
        ];

        foreach ($transientPatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function persistentReason(Throwable $throwable): string
    {
        if ($throwable instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            return 'model_not_found';
        }

        if ($throwable instanceof \InvalidArgumentException) {
            return 'invalid_argument';
        }

        return 'persistent_database_error';
    }

    private function transientReason(Throwable $throwable): string
    {
        $message = mb_strtolower($throwable->getMessage());

        if (str_contains($message, 'deadlock') || str_contains($message, 'lock wait timeout')) {
            return 'database_lock_contention';
        }

        if (str_contains($message, 'too many connections')) {
            return 'database_pool_exhausted';
        }

        if (str_contains($message, 'gone away') || str_contains($message, 'lost connection')) {
            return 'database_connection_lost';
        }

        if ($throwable instanceof PDOException || $throwable instanceof QueryException) {
            return 'database_connection_error';
        }

        return 'transient_connection_error';
    }
}
