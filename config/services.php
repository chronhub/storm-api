<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

/*
 * Api package wiring.
 *
 * Registers the bridge services with autowiring and autoconfiguration: the ExceptionTranslator
 * rides its #[AsEventListener] through autoconfiguration. The abstract state bases are skipped
 * by the loader itself; the app registers its concrete providers and API Platform autoconfigures
 * them as state providers.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('Storm\\Api\\', dirname(__DIR__).'/')
        ->exclude([
            dirname(__DIR__).'/Compiler/', // build-time passes, registered by the bundle, not services
            dirname(__DIR__).'/Error/ApiProblem.php', // an exception, constructed at the throw site, not a service
            dirname(__DIR__).'/Freshness/WriteReceipt.php', // a value object, harvested from the envelope, not a service
            dirname(__DIR__).'/Metadata/', // typed declaration readers, constructed from operation metadata, not services
            dirname(__DIR__).'/Tests/',
            dirname(__DIR__).'/config/',
        ]);
};
