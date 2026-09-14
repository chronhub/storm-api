<?php

declare(strict_types=1);

namespace Storm\Api\Tests\Compiler;

use ApiPlatform\Metadata\Resource\Factory\AttributesResourceMetadataCollectionFactory;
use ApiPlatform\Metadata\Resource\Factory\InputOutputResourceMetadataCollectionFactory;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Api\Compiler\GuardInputPropertySecurityPass;
use Storm\Api\Tests\Fixture\InputSecurity\SecuredInput;
use Storm\Api\Tests\Fixture\InputSecurityGenerated\GeneratedInputResource;
use Storm\Api\Tests\Fixture\InputSecurityInherited\InheritedInputResource;
use Storm\Api\Tests\Fixture\InputSecurityOverrides\OverriddenInputResource;
use Storm\Api\Tests\Fixture\InputSecurityOverrides\SafeInput;
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
        // the build policy requires authorization decisions outside custom input properties
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
        // than none
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

    #[Test]
    public function resource_level_input_is_guarded(): void
    {
        $metadata = new InputOutputResourceMetadataCollectionFactory(new AttributesResourceMetadataCollectionFactory)->create(InheritedInputResource::class);
        self::assertNotNull($metadata[0]);
        $operations = $metadata[0]->getOperations();
        self::assertNotNull($operations);
        self::assertCount(1, $operations);
        foreach ($operations as $operation) {
            self::assertSame(SecuredInput::class, $operation->getInput()['class']);
        }

        $this->expectException(LogicException::class);
        new GuardInputPropertySecurityPass()->process($this->container(__DIR__.'/../Fixture/InputSecurityInherited'));
    }

    #[Test]
    public function generated_operations_inherit_the_guarded_input(): void
    {
        $metadata = new InputOutputResourceMetadataCollectionFactory(new AttributesResourceMetadataCollectionFactory)->create(GeneratedInputResource::class);
        self::assertNotNull($metadata[0]);
        $operations = $metadata[0]->getOperations();
        self::assertNotNull($operations);
        self::assertGreaterThan(0, count($operations));
        foreach ($operations as $operation) {
            self::assertSame(SecuredInput::class, $operation->getInput()['class']);
        }

        $this->expectException(LogicException::class);
        new GuardInputPropertySecurityPass()->process($this->container(__DIR__.'/../Fixture/InputSecurityGenerated'));
    }

    #[Test]
    public function explicit_input_overrides_are_respected(): void
    {
        $metadata = new InputOutputResourceMetadataCollectionFactory(new AttributesResourceMetadataCollectionFactory)->create(OverriddenInputResource::class);
        self::assertNotNull($metadata[0]);
        $operations = $metadata[0]->getOperations();
        self::assertNotNull($operations);
        $inputs = [];
        foreach ($operations as $operation) {
            $uri = $operation->getUriTemplate();
            self::assertNotNull($uri);
            $inputs[$uri] = $operation->getInput()['class'];
        }
        self::assertSame([
            '/disabled-input' => null,
            '/safe-input' => SafeInput::class,
            '/self-input' => OverriddenInputResource::class,
        ], $inputs);

        new GuardInputPropertySecurityPass()->process($this->container(__DIR__.'/../Fixture/InputSecurityOverrides'));
    }

    #[Test]
    public function the_diagnostic_names_the_policy_and_the_offending_declaration(): void
    {
        try {
            new GuardInputPropertySecurityPass()->process($this->container(__DIR__.'/../Fixture/InputSecurity'));
            self::fail('The secured custom input must be refused at build time.');
        } catch (LogicException $exception) {
            self::assertStringContainsString(SecuredInput::class, $exception->getMessage());
            self::assertStringContainsString('::_api_/secured-writes_post"', $exception->getMessage());
            self::assertStringContainsString('$adminNote', $exception->getMessage());
            self::assertStringContainsString('Storm rejects property-level security on custom input DTOs', $exception->getMessage());
            self::assertStringContainsString('processor', $exception->getMessage());
            self::assertStringNotContainsString('NEVER evaluated', $exception->getMessage());
        }
    }

    private function container(string $fixtures): ContainerBuilder
    {
        $container = new ContainerBuilder;
        $container->setParameter('api_platform.resource_class_directories', [$fixtures]);

        return $container;
    }
}
