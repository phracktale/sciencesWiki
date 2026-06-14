<?php

declare(strict_types=1);

namespace App\Harvester\Oa;

use App\Entity\Publication;
use App\Entity\PublicationProvenance;
use App\Entity\Source;
use App\Harvester\Oa\Unpaywall\UnpaywallMapper;
use App\Harvester\Pipeline\LicenseGate;
use App\Repository\SourceRepository;

/**
 * Enrichit une publication avec sa résolution d'accès ouvert (Unpaywall) :
 * statut OA, meilleure URL légale, licence — puis ré-applique le portier de
 * licence pour décider du stockage du full-text (cf. Phase 1 §4, étape C/E).
 */
final class OaEnricher
{
    private ?Source $resolverSource = null;

    public function __construct(
        private readonly OpenAccessResolver $resolver,
        private readonly LicenseGate $licenseGate,
        private readonly SourceRepository $sources,
    ) {
    }

    /**
     * @return bool true si une résolution a été trouvée et appliquée
     */
    public function enrich(Publication $publication): bool
    {
        $doi = $publication->getDoi();
        if (null === $doi) {
            $publication->markOaResolved();

            return false;
        }

        $resolution = $this->resolver->resolve($doi);

        // On marque comme résolu même en l'absence de réponse, pour respecter le
        // quota Unpaywall et ne pas re-questionner indéfiniment les DOI inconnus.
        $publication->markOaResolved()->touch();

        if (null === $resolution) {
            return false;
        }

        $this->apply($publication, $resolution);
        $this->ensureResolverProvenance($publication, $resolution);

        return true;
    }

    private function apply(Publication $publication, OaResolution $resolution): void
    {
        // Unpaywall fait autorité sur l'état OA légal.
        $publication->setOaStatus($resolution->oaStatus);
        $publication->setFulltextAvailable($resolution->isOa);

        if (null !== $resolution->bestOaUrl) {
            $publication->setOaUrl($resolution->bestOaUrl);
        }
        if (null !== $resolution->license) {
            $publication->setLicense($resolution->license);
        }

        $publication->setFulltextStored(
            $resolution->isOa
            && $this->licenseGate->mayStoreFullText($publication->getLicense())
        );
    }

    private function ensureResolverProvenance(Publication $publication, OaResolution $resolution): void
    {
        $source = $this->resolverSource();
        if (null === $source || $publication->hasProvenanceFrom($source)) {
            return;
        }

        $idInSource = $publication->getDoi() ?? '';
        $publication->addProvenance(
            new PublicationProvenance($source, $idInSource, $resolution->license)
        );
    }

    private function resolverSource(): ?Source
    {
        return $this->resolverSource ??= $this->sources->findOneByCode(UnpaywallMapper::SOURCE_CODE);
    }
}
