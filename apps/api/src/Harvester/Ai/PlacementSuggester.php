<?php

declare(strict_types=1);

namespace App\Harvester\Ai;

use App\Entity\PlacementSuggestion;
use App\Entity\Publication;
use App\Enum\ProcessingStatus;
use App\Repository\TreeNodeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Propose (sans décider) le placement d'une publication dans l'arbre, par
 * similarité d'embeddings (kNN cosinus). La validation reste humaine
 * (cf. spec §6.3, Phase 1 §8).
 */
final class PlacementSuggester
{
    public function __construct(
        private readonly TreeNodeRepository $nodes,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return int nombre de suggestions créées
     */
    public function suggest(Publication $publication, int $k): int
    {
        $embedding = $publication->getEmbedding();
        if (null === $embedding) {
            return 0;
        }

        // Placements déjà existants pour cette publication (idempotence : évite la
        // collision UNIQUE (publication_id, tree_node_id) sur un re-run).
        $existing = [];
        if (null !== $publication->getId()) {
            foreach ($this->em->getConnection()->executeQuery(
                'SELECT tree_node_id FROM placement_suggestion WHERE publication_id = :p',
                ['p' => $publication->getId()],
            )->fetchFirstColumn() as $nid) {
                $existing[(int) $nid] = true;
            }
        }

        $created = 0;
        foreach ($this->nodes->nearestTo($embedding->toArray(), $k) as $hit) {
            $nodeId = $hit['node']->getId();
            if (null !== $nodeId && isset($existing[$nodeId])) {
                continue; // déjà proposé (ou doublon dans le kNN)
            }
            if (null !== $nodeId) {
                $existing[$nodeId] = true;
            }
            // Score de similarité cosinus = 1 - distance.
            $score = 1.0 - $hit['distance'];
            $this->em->persist(new PlacementSuggestion($publication, $hit['node'], $score));
            ++$created;
        }

        // La publication entre en file de validation humaine.
        $publication->setProcessingStatus(ProcessingStatus::InValidation);
        $publication->touch();

        return $created;
    }
}
