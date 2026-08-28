<?php

declare(strict_types=1);

namespace Storm\Api;

use Override;
use Storm\Api\Compiler\GuardFreshnessTopologyPass;
use Storm\Api\Compiler\GuardInputPropertySecurityPass;
use Storm\Api\Freshness\FreshnessStrategy;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * HTTP exposure of a Storm application through the API Platform bridge, the module's own bundle
 * kept separate from StormBundle on purpose: a CLI-only app ships none of this.
 *
 * The bridge is a strict leaf that sees the world through the two buses only: reads ask the
 * Story QueryBus, writes dispatch on the command bus, never Chronicler, Projector, or Aggregate
 * directly; the freshness seam consumes the contracted ProjectionFreshness port. A read that
 * "would need" the store is a missing query, not an exception to the rule.
 *
 * The bundle carries the generic provider and processor bases, the error boundary, the
 * freshness seam and its topology gate, and the bridge's hardened defaults: plain `json` first,
 * strict query parameters, and throw-on-denied properties.
 */
final class StormApiBundle extends AbstractBundle
{
    #[Override]
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new GuardFreshnessTopologyPass);
        $container->addCompilerPass(new GuardInputPropertySecurityPass);
    }

    /**
     * {@inheritDoc}
     *
     * The bridge's hardened defaults, prepended so the app's own config ALWAYS wins: every line
     * here is an opinion, none is a cage.
     *
     *  - Unknown query parameters are refused with a 400, never silently ignored;
     *
     *  - A property the caller may not read or write is an explicit refusal, never a silent drop;
     *
     *  - Plain `json` first: a CQRS bridge needs no Hydra vocabulary, and one line re-enables it;
     *
     *  - The docs and the entrypoint follow that choice: `docs_formats` drops `jsonld`. API
     *    Platform keeps `jsonld` in ITS default even when `formats` has none, so the entrypoint
     *    then negotiates a format whose serializer chain is gone: a 500 on a wildcard Accept, a
     *    406 on plain json. The entrypoint, a Hydra home document by nature, is disabled outright.
     *    OpenAPI as json and yaml and the HTML docs stay; re-enabling Hydra is one line each;
     *
     *  - The API Platform Doctrine integration stays OFF: the bridge never rides the ORM, and
     *    letting it auto-enable makes any storm app without api-platform/doctrine-orm refuse to
     *    boot;
     *
     *  - Property access is opt-in outside the Symfony full-stack, and the item normalizer needs
     *    it.
     */
    #[Override]
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->prependExtensionConfig('api_platform', [
            'formats' => ['json' => ['mime_types' => ['application/json']]],
            // the third face of the same jsonld hazard: API Platform's default error_formats keeps
            // jsonld FIRST, the error listener negotiates a wildcard or absent Accept onto it, and
            // the encoder was never registered, so every documented problem response, 404 to 500,
            // would escape the kernel as an UnsupportedFormatException for curl and every browser
            'error_formats' => [
                'jsonproblem' => ['mime_types' => ['application/problem+json']],
                'json' => ['mime_types' => ['application/problem+json', 'application/json']],
            ],
            'docs_formats' => [
                'jsonopenapi' => ['mime_types' => ['application/vnd.openapi+json']],
                'yamlopenapi' => ['mime_types' => ['application/vnd.openapi+yaml']],
                'html' => ['mime_types' => ['text/html']],
            ],
            'enable_entrypoint' => false,
            'doctrine' => false,
            'defaults' => [
                'strict_query_parameter_validation' => true,
                // the body-side mirror of the strict query parameters: an unknown or misspelled
                // input field is a 400 naming the field, never a silent drop that reads as "the
                // write took my value"; an app that genuinely wants lax bodies overrides this
                'denormalization_context' => ['allow_extra_attributes' => false],
                'extra_properties' => ['throw_on_access_denied' => true],
            ],
        ]);

        $builder->prependExtensionConfig('framework', [
            'property_access' => ['enabled' => true],
        ]);
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<string, mixed>  $config
     */
    #[Override]
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import(__DIR__.'/config/services.php');

        // every FreshnessStrategy joins the per-operation override locator; the tag is the
        // interface name, and the keys are service ids, meaning class names. The app-level
        // DEFAULT stays an explicit app-side alias: the framework elects no strategy
        $builder->registerForAutoconfiguration(FreshnessStrategy::class)
            ->addTag(FreshnessStrategy::class);
    }
}
