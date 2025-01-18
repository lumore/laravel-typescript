<?php

namespace Based\TypeScript\Generators;

use Based\TypeScript\Definitions\ColumnTypes;
use Based\TypeScript\Definitions\TypeScriptProperty;
use Based\TypeScript\Definitions\TypeScriptType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\Types;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelInspector;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

class ModelGenerator extends AbstractGenerator
{
    protected Model $model;
    /** @var Collection<Column> */
    protected Collection $columns;

    public function getDefinition(): ?string
    {
        return collect([
            $this->getProperties(),
            $this->getRelations(),
            $this->getManyRelations(),
            $this->getAccessors(),
        ])
            ->filter(fn (string $part) => !empty($part))
            ->join(PHP_EOL . '        ');
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     * @throws \ReflectionException
     */
    protected function boot(): void
    {
        $this->model = $this->reflection->newInstance();

        /** @var ModelInspector $modelInspector */
        $modelInspector = app(ModelInspector::class);

        $inspect = $modelInspector->inspect($this->model::class, $this->model->getConnection()->getName());

        $this->columns = $inspect['attributes']->filter(function (array $attribute) {
            // check if the attribute is not hidden and type is not null because of "mixed_accessor" in "attributes" collection
            return $attribute['hidden'] === false && !is_null($attribute['type']);
        });
    }

    protected function getProperties(): string
    {
        return $this->columns->map(function (array $column) {
            return (string) new TypeScriptProperty(
                name: $column['name'],
                types: $this->getPropertyType($column['type']),
                nullable: $column['nullable']
            );
        })
            ->join(PHP_EOL . '        ');
    }

    protected function getAccessors(): string
    {
        $relationsToSkip =  $this->getRelationMethods()
            ->map(function (ReflectionMethod $method) {
                return Str::snake($method->getName());
            });

        return $this->getMethods()
            ->filter(fn (ReflectionMethod $method) => Str::startsWith($method->getName(), 'get'))
            ->filter(fn (ReflectionMethod $method) => Str::endsWith($method->getName(), 'Attribute'))
            ->mapWithKeys(function (ReflectionMethod $method) {
                $property = (string) Str::of($method->getName())
                    ->between('get', 'Attribute')
                    ->snake();

                return [$property => $method];
            })
            ->reject(function (ReflectionMethod $method, string $property) {
                return $this->columns->contains(fn (array $column) => $column['name'] == $property);
            })
            ->reject(function (ReflectionMethod $method, string $property) use ($relationsToSkip) {
                return $relationsToSkip->contains($property);
            })
            ->map(function (ReflectionMethod $method, string $property) {
                return (string) new TypeScriptProperty(
                    name: $property,
                    types: TypeScriptType::fromMethod($method),
                    optional: true,
                    readonly: true
                );
            })
            ->join(PHP_EOL . '        ');
    }

    protected function getRelations(): string
    {
        return $this->getRelationMethods()
            ->map(function (ReflectionMethod $method) {
                return (string) new TypeScriptProperty(
                    name: Str::snake($method->getName()),
                    types: $this->getRelationType($method),
                    optional: true,
                    nullable: true
                );
            })
            ->join(PHP_EOL . '        ');
    }

    protected function getManyRelations(): string
    {
        return $this->getRelationMethods()
            ->filter(fn (ReflectionMethod $method) => $this->isManyRelation($method))
            ->map(function (ReflectionMethod $method) {
                return (string) new TypeScriptProperty(
                    name: Str::snake($method->getName()) . '_count',
                    types: TypeScriptType::NUMBER,
                    optional: true,
                    nullable: true
                );
            })
            ->join(PHP_EOL . '        ');
    }

    protected function getRelationMethods(): Collection
    {
        return $this->getMethods()
            ->filter(function (ReflectionMethod $method) {
                try {
                    return $method->invoke($this->model) instanceof Relation;
                } catch (Throwable) {
                    return false;
                }
            })
            // [TODO] Resolve trait/parent relations as well (e.g. DatabaseNotification)
            // skip traits for awhile
            ->filter(function (ReflectionMethod $method) {
                return collect($this->reflection->getTraits())
                    ->filter(function (ReflectionClass $trait) use ($method) {
                        return $trait->hasMethod($method->name);
                    })
                    ->isEmpty();
            });
    }

    protected function getMethods(): Collection
    {
        return collect($this->reflection->getMethods(ReflectionMethod::IS_PUBLIC))
            ->reject(fn (ReflectionMethod $method) => $method->isStatic())
            ->reject(fn (ReflectionMethod $method) => $method->getNumberOfParameters());
    }

    protected function getPropertyType(string $type): string|array
    {
        if (!in_array($type, ['integer', 'varchar', 'text', 'datetime', 'numeric'])) {
            dd($type);
        }
        return match ($type) {
            ColumnTypes::ARRAY => [TypeScriptType::array(), TypeScriptType::ANY],
            ColumnTypes::ASCII_STRING => TypeScriptType::STRING,
            ColumnTypes::BIGINT => TypeScriptType::NUMBER,
            ColumnTypes::BINARY => TypeScriptType::STRING,
            ColumnTypes::BLOB => TypeScriptType::STRING,
            ColumnTypes::BOOLEAN => TypeScriptType::BOOLEAN,
            ColumnTypes::DATE_MUTABLE => TypeScriptType::STRING,
            ColumnTypes::DATE_IMMUTABLE => TypeScriptType::STRING,
            ColumnTypes::DATEINTERVAL => TypeScriptType::STRING,
            ColumnTypes::DATETIME_MUTABLE => TypeScriptType::STRING,
            ColumnTypes::DATETIME_IMMUTABLE => TypeScriptType::STRING,
            ColumnTypes::DATETIMETZ_MUTABLE => TypeScriptType::STRING,
            ColumnTypes::DATETIMETZ_IMMUTABLE => TypeScriptType::STRING,
            ColumnTypes::DECIMAL => TypeScriptType::NUMBER,
            ColumnTypes::FLOAT => TypeScriptType::NUMBER,
            ColumnTypes::GUID => TypeScriptType::STRING,
            ColumnTypes::INTEGER => TypeScriptType::NUMBER,
            ColumnTypes::JSON => [TypeScriptType::array(), TypeScriptType::ANY],
            ColumnTypes::OBJECT => TypeScriptType::ANY,
            ColumnTypes::SIMPLE_ARRAY => [TypeScriptType::array(), TypeScriptType::ANY],
            ColumnTypes::SMALLINT => TypeScriptType::NUMBER,
            ColumnTypes::STRING => TypeScriptType::STRING,
            ColumnTypes::TEXT => TypeScriptType::STRING,
            ColumnTypes::TIME_MUTABLE => TypeScriptType::NUMBER,
            ColumnTypes::TIME_IMMUTABLE => TypeScriptType::NUMBER,
            default => TypeScriptType::ANY,
        };
    }

    protected function getRelationType(ReflectionMethod $method): string
    {
        $relationReturn = $method->invoke($this->model);
        $related = str_replace('\\', '.', get_class($relationReturn->getRelated()));

        if ($this->isManyRelation($method)) {
            return TypeScriptType::array($related);
        }

        if ($this->isOneRelattion($method)) {
            return $related;
        }

        return TypeScriptType::ANY;
    }

    protected function isManyRelation(ReflectionMethod $method): bool
    {
        $relationType = get_class($method->invoke($this->model));

        return in_array(
            $relationType,
            [
                HasMany::class,
                BelongsToMany::class,
                HasManyThrough::class,
                MorphMany::class,
                MorphToMany::class,
            ]
        );
    }

    protected function isOneRelattion(ReflectionMethod $method): bool
    {
        $relationType = get_class($method->invoke($this->model));

        return in_array(
            $relationType,
            [
                HasOne::class,
                BelongsTo::class,
                MorphOne::class,
                HasOneThrough::class,
            ]
        );
    }
}
