<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\Llm\LlmClientFactory;
use App\Ai\Llm\LlmMessage;
use App\Catalog\PublicationType;
use App\Entity\Publication;
use App\Harvester\Ai\EmbeddingClientFactory;
use App\Repository\PublicationRepository;
use App\Service\SettingsService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Revue de littérature assistée (RAG sourcé), diffusée en flux (SSE) — espace
 * chercheur (cf. spec §3). Pipeline : embedding du sujet → kNN (papiers primaires,
 * OA/cités privilégiés) → LLM (modèle Q/R configuré) produit une synthèse
 * structurée (consensus · méthodes · lacunes) avec citations [n] + bibliographie.
 *
 * Événements SSE : {"sources":[…]} d'abord, puis {"delta":"…"} au fil de l'eau,
 * enfin {"done":true}.
 */
final class LiteratureReviewController
{
    private const SYSTEM_PROMPT = <<<'TXT'
        Tu es un assistant de recherche scientifique. À partir EXCLUSIVEMENT des sources
        numérotées fournies (n'invente jamais de fait ni de référence), rédige en français
        une REVUE DE LITTÉRATURE structurée et nuancée sur le sujet demandé.

        Format Markdown EXACT, dans cet ordre :
        ## Synthèse
        <2 à 4 phrases de cadrage du sujet et de l'état des connaissances>
        ## Consensus établi
        <points qui font consensus, chacun appuyé par une ou plusieurs citations [n]>
        ## Méthodes dominantes
        <approches/méthodologies récurrentes dans les sources, avec citations [n]>
        ## Lacunes et controverses
        <désaccords, limites, questions ouvertes, avec citations [n]>
        ## Conclusion
        <ce que la littérature permet d'affirmer aujourd'hui, et avec quel degré de certitude>

        Règles : chaque affirmation factuelle DOIT être suivie de sa ou ses citations sous
        la forme [n] renvoyant au numéro de la source. N'utilise QUE les sources fournies.
        Si les sources sont insuffisantes sur un point, dis-le explicitement. Reste neutre,
        précis et concis. N'ajoute PAS de bibliographie (elle est générée séparément).
        TXT;

    public function __construct(
        private readonly EmbeddingClientFactory $embeddingFactory,
        private readonly PublicationRepository $publications,
        private readonly LlmClientFactory $llmFactory,
        private readonly SettingsService $settings,
    ) {
    }

    #[Route('/api/literature-review/stream', name: 'api_literature_review', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $query = trim((string) $request->query->get('q', ''));
        if (mb_strlen($query) < 3) {
            return new JsonResponse(['error' => 'Indiquez un sujet (3 caractères minimum).'], 400);
        }
        $k = max(6, min(20, $request->query->getInt('n', 12)));

        $response = new StreamedResponse(function () use ($query, $k): void {
            @set_time_limit(0);
            ignore_user_abort(true);
            $send = static function (array $payload): void {
                echo 'data: '.json_encode($payload, \JSON_UNESCAPED_UNICODE)."\n\n";
                @ob_flush();
                flush();
            };

            try {
                $embedding = $this->embeddingFactory->create()->embed($query);
                $hits = $this->publications->nearestTo($embedding, $k, PublicationType::PRIMARY);
                /** @var list<Publication> $sources */
                $sources = array_map(static fn (array $h): Publication => $h['publication'], $hits);

                if ([] === $sources) {
                    $send(['nosource' => true, 'message' => "Aucune source scientifique pertinente n'a été trouvée dans le corpus pour ce sujet."]);

                    return;
                }

                $send(['sources' => $this->sourcesPayload($sources)]);

                $opts = ['temperature' => $this->settings->temperature(), 'max_tokens' => $this->settings->maxTokens()];
                if (null !== $this->settings->model()) {
                    $opts['model'] = $this->settings->model();
                }

                foreach ($this->llmFactory->create()->stream($this->messages($query, $sources), $opts) as $chunk) {
                    $send(['delta' => $chunk]);
                }

                $send(['done' => true]);
            } catch (\Throwable) {
                $send(['error' => 'La revue de littérature a échoué. Réessayez plus tard.']);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    /**
     * @param list<Publication> $sources
     *
     * @return list<array<string,mixed>>
     */
    private function sourcesPayload(array $sources): array
    {
        $out = [];
        foreach ($sources as $i => $p) {
            $authors = array_map(static fn (array $a): string => $a['name'], $p->getAuthors());
            $out[] = [
                'n' => $i + 1,
                'title' => $p->getTitle(),
                'authors' => $authors,
                'year' => $p->getPublicationDate()?->format('Y'),
                'venue' => $p->getVenue(),
                'doi' => $p->getDoi(),
                'oaUrl' => $p->getOaUrl(),
                'citedByCount' => $p->getCitedByCount(),
            ];
        }

        return $out;
    }

    /**
     * @param list<Publication> $sources
     *
     * @return list<LlmMessage>
     */
    private function messages(string $query, array $sources): array
    {
        $lines = ['SUJET : '.$query, '', 'SOURCES :'];
        foreach ($sources as $i => $p) {
            $authors = implode(', ', array_map(static fn (array $a): string => $a['name'], $p->getAuthors()));
            $lines[] = \sprintf(
                "[%d] %s — %s (%s). DOI:%s\n    Résumé : %s",
                $i + 1,
                $p->getTitle(),
                '' !== $authors ? $authors : 'auteurs inconnus',
                $p->getPublicationDate()?->format('Y') ?? 's.d.',
                $p->getDoi() ?? 'n/a',
                mb_substr($p->getAbstract() ?? '(pas de résumé)', 0, 700),
            );
        }

        return [
            LlmMessage::system(self::SYSTEM_PROMPT),
            LlmMessage::user(implode("\n", $lines)),
        ];
    }
}
