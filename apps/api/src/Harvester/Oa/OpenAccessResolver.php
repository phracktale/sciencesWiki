<?php

declare(strict_types=1);

namespace App\Harvester\Oa;

/**
 * Résout l'état d'accès ouvert légal d'une publication à partir de son DOI
 * (cf. Phase 1 §4, étape C « ResolveOpenAccess »).
 */
interface OpenAccessResolver
{
    /** Code de la source de résolution (« unpaywall »). */
    public function code(): string;

    /** Renvoie la résolution OA, ou null si le DOI est inconnu de la source. */
    public function resolve(string $doi): ?OaResolution;
}
