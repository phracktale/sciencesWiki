<?php

declare(strict_types=1);

namespace App\ApiPlatform;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Answer;
use App\Enum\AnswerValidationStatus;
use Doctrine\ORM\QueryBuilder;

/**
 * Mur de publication (spec §8.4) appliqué à l'API : seules les réponses
 * **publiques** (validées ou non relues) sont exposées ; les brouillons en
 * relecture comité ne fuitent jamais par l'API.
 */
final class PublicAnswerExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    private const PUBLIC_STATUSES = [
        AnswerValidationStatus::Validated->value,
        AnswerValidationStatus::Unreviewed->value,
    ];

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $this->restrict($queryBuilder, $resourceClass);
    }

    /**
     * @param array<string,mixed> $identifiers
     */
    public function applyToItem(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        array $identifiers,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $this->restrict($queryBuilder, $resourceClass);
    }

    private function restrict(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        if (Answer::class !== $resourceClass) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $queryBuilder
            ->andWhere(\sprintf('%s.validationStatus IN (:publicStatuses)', $alias))
            ->setParameter('publicStatuses', self::PUBLIC_STATUSES);
    }
}
