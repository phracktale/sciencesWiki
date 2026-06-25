<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AnswerRepository;
use App\Repository\PublicationRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Locator de passage pour une réponse publiée : pour chaque note [n], renvoie
 * l'EXTRAIT de texte intégral de la publication citée le plus proche de la
 * question — afin que le lecteur puisse vérifier que la citation est réellement
 * ancrée dans la source.
 *
 * Endpoint séparé (et non champ sérialisé de l'entité Answer) car le passage est
 * CONTEXTUEL : il dépend de l'embedding de la question, pas seulement de la publi.
 *
 * Réponse : { "passages": { "<marqueur>": "<extrait>", … } }.
 */
final class AnswerPassagesController
{
    private const MAX_LEN = 420;

    public function __construct(
        private readonly AnswerRepository $answers,
        private readonly PublicationRepository $publications,
    ) {
    }

    #[Route('/api/answers/{id}/passages', name: 'api_answer_passages', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function __invoke(int $id): JsonResponse
    {
        $answer = $this->answers->find($id);
        if (null === $answer) {
            return new JsonResponse(['error' => 'Réponse introuvable.'], 404);
        }

        $embedding = $answer->getQuestion()->getEmbedding()?->toArray();
        $revision = $answer->getLatestRevision();

        $passages = [];
        if (null !== $embedding && null !== $revision) {
            foreach ($revision->getFootnotes() as $footnote) {
                $pub = $footnote->getPublication();
                $pubId = $pub->getId();
                if (null === $pubId) {
                    continue;
                }
                // Meilleur chunk de texte intégral vis-à-vis de la question, sinon résumé.
                $passage = $this->publications->bestPassageFor($pubId, $embedding) ?? $pub->getAbstract();
                if (null === $passage || '' === trim($passage)) {
                    continue;
                }
                $clean = trim((string) preg_replace('/\s+/', ' ', $passage));
                $passages[(string) $footnote->getMarker()] = mb_substr($clean, 0, self::MAX_LEN)
                    .(mb_strlen($clean) > self::MAX_LEN ? '…' : '');
            }
        }

        return new JsonResponse(['passages' => $passages]);
    }
}
