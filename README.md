## This is a fork of `lepikhinb/laravel-typescript`

Warning: this package only supports Laravel 11+

___

# Laravel TypeScript

[![Latest Version on Packagist](https://img.shields.io/packagist/v/lumore/laravel-typescript.svg?style=flat-square)](https://packagist.org/packages/lumore/laravel-typescript)
[![GitHub Tests Action Status](https://img.shields.io/github/workflow/status/lumore/laravel-typescript/run-tests?label=tests)](https://github.com/lumore/laravel-typescript/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/lumore/laravel-typescript.svg?style=flat-square)](https://packagist.org/packages/lumore/laravel-typescript)

The package lets you generate TypeScript interfaces from your Laravel models.

## Introduction
Say you have a model which has several properties (database columns) and multiple relations.
```php
class Product extends Model
{
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function features(): HasMany
    {
        return $this->hasMany(Feature::class);
    }
}
```

Laravel TypeScript will generate the following TypeScript interface:

```typescript
declare namespace App.Models {
    export interface Product {
        id: number;
        category_id: number;
        name: string;
        price: number;
        created_at: string | null;
        updated_at: string | null;
        category?: App.Models.Category | null;
        features?: Array<App.Models.Feature> | null;
    }
    ...
}
```

**Laravel TypeScript** supports:
- [x] Database columns
- [x] Model relations
- [x] Model accessors
- [ ] Casted attributes

## Installation

**Laravel 11 and PHP 8 are required.**
You can install the package via composer:

```bash
composer require lumore/laravel-typescript
```

You can publish the config file with:
```bash
php artisan vendor:publish --provider="Lumore\TypeScript\TypeScriptServiceProvider" --tag="typescript-config"
```

This is the contents of the published config file:

```php
return [
    'generators' => [
        Model::class => ModelGenerator::class,
    ],

    'output' => resource_path('js/models.d.ts'),

    // load namespaces from composer's `dev-autoload`
    'autoloadDev' => false,
];

```

## Usage

Generate TypeScript interfaces.
```bash
php artisan typescript:generate
```

Example usage with Vue 3:
```typescript
import { defineComponent, PropType } from "vue";

export default defineComponent({
    props: {
        product: {
            type: Object as PropType<App.Models.Product>,
            required: true,
        },
    },
}
```

## Testing

```bash
composer test
```

## Credits

- [Adil Rakhimbayev](https://github.com/lumore)
- [Boris Lepikhin](https://github.com/lepikhinb)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
