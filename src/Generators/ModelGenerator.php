<?php

namespace Lumore\TypeScript\Generators;

use Lumore\TypeScript\Definitions\ColumnTypes;
use Lumore\TypeScript\Definitions\TypeScriptProperty;
use Lumore\TypeScript\Definitions\TypeScriptType;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelInspector;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ReflectionMethod;

class ModelGenerator extends AbstractGenerator
{
    protected Model $model;
    protected Collection $columns;
    protected Collection $relations;
    protected Collection $accessors;

    public function getDefinition(): ?string
    {
        return collect([
            $this->getProperties(),
            $this->getRelations(),
            $this->getManyRelations(),
            $this->getAccessors(),
        ])
            ->filter(fn(string $part) => !empty($part))
            ->join(PHP_EOL . '        ');
    }

    /**
     * @throws \ReflectionException
     * @throws BindingResolutionException
     */
    protected function boot(): void
    {
        $this->model = $this->reflection->newInstance();

        /** @var ModelInspector $modelInspector */
        $modelInspector = app(ModelInspector::class);

        $inspect = $modelInspector->inspect($this->model::class, $this->model->getConnection()->getName());

        $this->columns = collect($inspect['attributes'])
            ->filter(fn(array $attribute) => $attribute['hidden'] === false);

        $this->accessors = $this->columns
            ->filter(fn(array $attribute) => $attribute['cast'] === 'accessor');

        $this->relations = collect($inspect['relations']);
    }

    protected function getProperties(): string
    {
        return $this->columns
            ->filter(fn(array $column) => $column['type'] !== null)
            ->map(function (array $column) {
                return (string)new TypeScriptProperty(
                    name: $column['name'],
                    types: $this->getPropertyType($column['type']),
                    nullable: $column['nullable']
                );
            })
            ->join(PHP_EOL . '        ');
    }

    protected function getAccessors(): string
    {
        return $this->getMethods()
            ->filter(function (ReflectionMethod $method) {
                $formattedName = Str::remove(['get_', '_attribute'], Str::snake($method->getName()));

                return $this->accessors->contains(fn (array $accessor) => $accessor['name'] === $formattedName);
            })
            ->mapWithKeys(function (ReflectionMethod $method) {
                $property = (string)Str::of($method->getName())
                    ->between('get', 'Attribute')
                    ->snake();

                return [$property => $method];
            })
            ->map(function (ReflectionMethod $method, string $property) {
                return (string)new TypeScriptProperty(
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
        return $this->relations
            ->map(function (array $relation) {
                return (string)new TypeScriptProperty(
                    name: Str::snake($relation['name']),
                    types: $this->getRelationType($relation),
                    optional: true,
                    nullable: true
                );
            })
            ->join(PHP_EOL . '        ');
    }

    protected function getManyRelations(): string
    {
        return $this->relations
            ->filter(fn(array $relation) => $this->isManyRelation($relation['type']))
            ->map(function (array $relation) {
                return (string)new TypeScriptProperty(
                    name: Str::snake($relation['name']) . '_count',
                    types: TypeScriptType::NUMBER,
                    optional: true,
                    nullable: true
                );
            })
            ->join(PHP_EOL . '        ');
    }

    protected function getMethods(): Collection
    {
        return collect($this->reflection->getMethods(ReflectionMethod::IS_PUBLIC))
            ->reject(fn(ReflectionMethod $method) => $method->isStatic());
    }

    protected function getPropertyType(string $type): string|array
    {
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
            ColumnTypes::NUMERIC => TypeScriptType::NUMBER,
            ColumnTypes::DECIMAL => TypeScriptType::NUMBER,
            ColumnTypes::FLOAT => TypeScriptType::NUMBER,
            ColumnTypes::GUID => TypeScriptType::STRING,
            ColumnTypes::INTEGER => TypeScriptType::NUMBER,
            ColumnTypes::JSON => [TypeScriptType::array(), TypeScriptType::ANY],
            ColumnTypes::OBJECT => TypeScriptType::ANY,
            ColumnTypes::SIMPLE_ARRAY => [TypeScriptType::array(), TypeScriptType::ANY],
            ColumnTypes::SMALLINT => TypeScriptType::NUMBER,
            ColumnTypes::VARCHAR => TypeScriptType::STRING,
            ColumnTypes::TEXT => TypeScriptType::STRING,
            ColumnTypes::TIME_MUTABLE => TypeScriptType::NUMBER,
            ColumnTypes::TIME_IMMUTABLE => TypeScriptType::NUMBER,
            default => TypeScriptType::ANY,
        };
    }

    protected function getRelationType(array $relation): string
    {
        $related = str_replace('\\', '.', $relation['related']);

        if ($this->isManyRelation($relation['type'])) {
            return TypeScriptType::array($related);
        }

        if ($this->isOneRelation($relation['type'])) {
            return $related;
        }

        return TypeScriptType::ANY;
    }

    protected function isManyRelation(string $relationType): bool
    {
        return in_array(
            $relationType,
            [
                'HasMany',
                'BelongsToMany',
                'HasManyThrough',
                'MorphMany',
                'MorphToMany',
                'MorphedByMany',
            ]
        );
    }

    protected function isOneRelation(string $relationType): bool
    {
        return in_array(
            $relationType,
            [
                'HasOne',
                'BelongsTo',
                'MorphOne',
                'MorphTo',
                'HasOneThrough',
            ]
        );
    }
}
