<?php

declare(strict_types=1);

namespace Storm\Api\Tests\Compiler;

use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Api\Compiler\GuardInputPropertySecurityPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class GuardInputPropertySecurityPassTest extends TestCase
{
    #[Test]
    public function a_container_without_api_platform_is_a_no_op(): void
    {
        $this->expectNotToPerformAssertions();

        new GuardInputPropertySecurityPass()->process(new ContainerBuilder);
    }

    #[Test]
    public function api_platform_present_but_discovering_no_directory_is_a_no_op(): void
    {
        // a distinct state from the absent parameter above: the bundle IS installed, and the app
        // declares no attribute-discovered resource directory, so the gate has no field to walk
        $this->expectNotToPerformAssertions();

        $container = new ContainerBuilder;
        $container->setParameter('api_platform.resource_class_directories', []);

        new GuardInputPropertySecurityPass()->process($container);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_secured_property_on_a_custom_input_dto_fails_the_build(): void
    {
        // the documented trap turned witness: API Platform never evaluates property security
        // during input denormalization, so the expression guards nothing while reading as a guard
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/adminNote/');

        new GuardInputPropertySecurityPass()->process($this->container(__DIR__.'/../Fixture/InputSecurity'));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_directory_declared_as_a_bare_string_is_still_walked(): void
    {
        // a scalar where a list was expected is a plausible hand-written parameter, and the gate
        // must not read it as an empty field: a build guard that silently disables itself is worse
        // than none, since the trap it refuses leaves no trace at runtime either
        $container = new ContainerBuilder;
        $container->setParameter('api_platform.resource_class_directories', __DIR__.'/../Fixture/InputSecurity');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/adminNote/');

        new GuardInputPropertySecurityPass()->process($container);
    }

    #[Test]
    public function a_secured_property_on_the_resource_as_its_own_input_is_tolerated(): void
    {
        // on the resource itself the expression is evaluated on the read side, legitimate shaping
        $this->expectNotToPerformAssertions();

        new GuardInputPropertySecurityPass()->process($this->container(__DIR__.'/../Fixture/InputSecuritySelf'));
    }

    private function container(string $fixtures): ContainerBuilder
    {
        $container = new ContainerBuilder;
        $container->setParameter('api_platform.resource_class_directories', [$fixtures]);

        return $container;
    }
}
