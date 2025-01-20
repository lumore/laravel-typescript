<?php

namespace Lumore\TypeScript;

use Lumore\TypeScript\Commands\TypeScriptGenerateCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class TypeScriptServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-typescript')
            ->hasConfigFile('typescript')
            ->hasCommand(TypeScriptGenerateCommand::class);
    }
}
