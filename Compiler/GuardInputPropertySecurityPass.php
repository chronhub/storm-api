<?php

declare(strict_types=1);

namespace Storm\Api\Compiler;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\Resource\Factory\AttributesResourceMetadataCollectionFactory;
use ApiPlatform\Metadata\Resource\Factory\AttributesResourceNameCollectionFactory;
use ApiPlatform\Metadata\Resource\Factory\InputOutputResourceMetadataCollectionFactory;
use LogicException;
use Override;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException;

use function is_array;
use function is_string;
use function sprintf;

/**
 * Rejects property-level `security` declarations on the custom input DTOs it discovers.
 *
 * This is a build-time policy, not proof that API Platform ignores the expression. Property
 * expressions can be evaluated when a resource access checker is available. Custom input
 * denormalization can lose the operation context and its `throw_on_access_denied` default,
 * allowing a denied write to be reverted without an explicit HTTP refusal. Use operation-level
 * security or an authorization decision in the processor when the write must be refused.
 *
 * Discovery covers attribute resources in `api_platform.resource_class_directories` and input
 * classes resolved from declared or generated HTTP operations, including resource-level input defaults.
 * Explicit operation input overrides are respected; XML and PHP metadata are outside this discovery.
 * GraphQL operations are not inspected.
 * The resource itself is allowed as its own input. `securityPostDenormalize` is not inspected.
 */
final class GuardInputPropertySecurityPass implements CompilerPassInterface
{
    /**
     * {@inheritDoc}
     *
     * @throws LogicException when a custom input DTO carries a property-level security expression
     * @throws ReflectionException on a reflection failure
     */
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        // try/get instead of has/get on purpose: phpstan's symfony extension constant-folds the
        // hassers against the dev container's XML, which carries no api_platform, and declares
        // everything below unreachable; the catch keeps the flow alive.
        try {
            $resolved = $container->getParameterBag()->resolveValue(
                $container->getParameter('api_platform.resource_class_directories'),
            );
        } catch (ParameterNotFoundException) {
            return; // no API Platform in this container: nothing declares an input
        }

        /** @var list<string> $paths */
        $paths = (array) $resolved;

        if ($paths === []) {
            return;
        }

        /** @var class-string $resourceClass the collection yields discovered resource FQCNs */
        foreach (new AttributesResourceNameCollectionFactory($paths)->create() as $resourceClass) {
            foreach ($this->inputClasses($resourceClass) as $where => $inputClass) {
                $this->refuseSecuredProperties($where, $inputClass);
            }
        }
    }

    /**
     * Every custom input class the resource's operations declare; the resource class itself is
     * skipped, since property security IS evaluated on the read side.
     *
     * @param  class-string  $resourceClass
     * @return iterable<string, class-string>
     *
     * @throws ReflectionException on a reflection failure
     */
    private function inputClasses(string $resourceClass): iterable
    {
        $metadata = new InputOutputResourceMetadataCollectionFactory(new AttributesResourceMetadataCollectionFactory);

        foreach ($metadata->create($resourceClass) as $resource) {
            foreach ($resource->getOperations() ?? [] as $operationName => $operation) {
                $input = $operation->getInput();
                $class = is_array($input) ? ($input['class'] ?? null) : (is_string($input) ? $input : null);

                if (is_string($class) && $class !== $resourceClass && class_exists($class)) {
                    /** @var class-string $class */
                    yield $resourceClass.'::'.$operationName => $class;
                }
            }
        }
    }

    /**
     * @param  class-string  $inputClass
     *
     * @throws LogicException when a property carries a security expression
     * @throws ReflectionException on a reflection failure
     */
    private function refuseSecuredProperties(string $where, string $inputClass): void
    {
        foreach (new ReflectionClass($inputClass)->getProperties() as $property) {
            foreach ($property->getAttributes(ApiProperty::class) as $attribute) {
                if ($attribute->newInstance()->getSecurity() !== null) {
                    throw new LogicException(sprintf(
                        'Input DTO "%s" (operation "%s") declares ApiProperty(security:) on "$%s".'
                        .' Storm rejects property-level security on custom input DTOs at build time.'
                        .' Use operation-level security or an authorization decision in the processor'
                        .' when a denied write must stop processing.',
                        $inputClass,
                        $where,
                        $property->getName(),
                    ));
                }
            }
        }
    }
}
