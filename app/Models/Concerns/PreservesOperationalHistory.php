<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use LogicException;

trait PreservesOperationalHistory
{
    protected static function bootPreservesOperationalHistory(): void
    {
        static::updating(function (Model $model): void {
            $dirty = array_keys($model->getDirty());
            if (array_diff($dirty, ['valid_until']) || $model->getRawOriginal('valid_until') !== null || ! $model->valid_until || $model->valid_until->lessThanOrEqualTo($model->valid_from)) {
                throw new LogicException('Las versiones históricas son inmutables; únicamente se permite finalizar una vigencia abierta.');
            }
        });
        static::deleting(function (): never {
            throw new LogicException('No se permite eliminar el historial operativo.');
        });
    }
}
