<?php

namespace Splicewire\Beam\Embed\Sources;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Splicewire\Beam\Embed\Contracts\EmbedSourceResolver;

class EmbedSourceRegistry
{
    /** @var array<string, EmbedSourceResolver> */
    private array $resolvers = [];

    public function register(string $morphAlias, EmbedSourceResolver $resolver): static
    {
        if ($morphAlias === '') {
            throw new InvalidArgumentException('An embed source resolver requires a morph alias.');
        }

        $this->resolvers[$morphAlias] = $resolver;

        return $this;
    }

    public function resolve(Model $source): EmbedSourceResolution
    {
        $alias = $source->getMorphClass();
        $resolver = $this->resolvers[$alias] ?? null;

        if ($resolver === null) {
            throw new InvalidArgumentException("No embed source resolver is registered for [{$alias}].");
        }

        return $resolver->resolve($source);
    }
}
