<?php

declare(strict_types=1);

namespace Storm\Api\Compiler;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Resource\Factory\AttributesResourceNameCollectionFactory;
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
 * Refuses at BUILD a property-level `security` expression on a custom input DTO: API Platform
 * never evaluates `ApiProperty(security:)` during denormalization of an input class, so the
 * declaration reads as a guard while guarding nothing, and the property it decorates is written
 * from the request body for every caller. The hazard was documented prose; a gate in the same
 * spirit as the freshness topology guard turns it into a compile witness.
 *
 * The check fires only when the operation's input class differs from the resource class: on the
 * resource itself the expression is evaluated on the read side and is a legitimate shaping tool.
 * The remedy the refusal names: move the check to the operation's `security`, or decide it in
 * the processor, where the actor and the command meet.
 *
 * Same discovery boundary as the topology gate, stated honestly: attribute-discovered resources
 * over `api_platform.resource_class_directories`; XML/PHP metadata escapes it.
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
        foreach (new ReflectionClass($resourceClass)->getAttributes(ApiResource::class) as $attribute) {
            $resource = $attribute->newInstance();

            foreach ($resource->getOperations() ?? [] as $operation) {
                $input = $operation->getInput();
                $class = is_array($input) ? ($input['class'] ?? null) : (is_string($input) ? $input : null);

                if (is_string($class) && $class !== $resourceClass && class_exists($class)) {
                    /** @var class-string $class */
                    yield $resourceClass.'::'.($operation->getName() ?? $operation::class) => $class;
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
                        'Input DTO "%s" (operation "%s") declares ApiProperty(security:) on "$%s" — property-level'
                        .' security is NEVER evaluated on a custom input class, so the expression guards nothing'
                        .' and the property is written from the body for every caller. Move the check to the'
                        .' operation\'s security, or decide it in the processor, where actor and command meet.',
                        $inputClass,
                        $where,
                        $property->getName(),
                    ));
                }
            }
        }
    }
}
