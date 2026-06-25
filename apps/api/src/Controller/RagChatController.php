<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\Llm\LlmClientFactory;
use App\Ai\Llm\LlmMessage;
use App\Entity\Publication;
use App\Harvester\Ai\EmbeddingClientFactory;
use App\Repository\PublicationRepository;
use App\Service\SettingsService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoint de chat RAG **compatible OpenAI** (chat completions) pour Open WebUI.
 * Étape pilote de docs/spec-openwebui-rag.md : réutilise l'index existant
 * (récupération pgvector via PublicationRepository::nearestTo, garde-fous RAG)
 * et le LLM auto-hébergé (OpenAiCompatibleLlmClient → Ollama). Le passage à
 * l'orchestration native (bundle Symfony AI) sera un refactor isolé de ce
 * contrôleur, sans changer le contrat exposé à Open WebUI.
 *
 * Contrat : GET /api/rag/models (liste) + POST /api/rag/chat/completions
 * (stream SSE format OpenAI, ou réponse unique si stream=false).
 */
final class RagChatController
{
    /** Distance cosinus max pour qu'une publication soit jugée pertinente (garde-fou RAG). */
    private const MAX_SOURCE_DISTANCE = 0.62;

    /**
     * Nombre de sources retenues (après fusion hybride vecteur + lexical). Le chat
     * fait une recherche GLOBALE (tout le corpus multi-domaines) ; la fusion RRF
     * (cf. PublicationRepository::nearestHybrid) remonte le pertinent même quand un
     * terme générique domine l'embedding, donc un K modéré suffit (contexte LLM léger).
     */
    private const CHAT_NEIGHBORS = 12;

    /** Modèle RAG par défaut (LLM = réglage rag.model / LLM_MODEL courant). */
    private const MODEL_ID = 'scienceswiki-rag';

    /**
     * « Modèles » RAG exposés à Open WebUI : MÊME pipeline (récupération hybride +
     * garde-fous sourcés), mais LLM différent dessous → comparaison côte à côte via
     * le « + » d'Open WebUI, sur les MÊMES sources. La valeur = le tag Ollama à
     * utiliser ; null = LLM courant. Ajuste/complète selon les modèles tirés sur Marvin.
     */
    private const RAG_MODELS = [
        self::MODEL_ID => null,
        'scienceswiki-rag-mistral' => 'mistral-medium-3.5:latest',
        'scienceswiki-rag-qwen' => 'qwen3.6:latest',
        'scienceswiki-rag-gemma' => 'gemma4:latest',
    ];

    private const CHAT_SYSTEM = <<<'TXT'
        Tu es l'assistant de recherche de SciencesWiki. Tu réponds en FRANÇAIS,
        UNIQUEMENT à partir des SOURCES fournies (extraits de publications), de façon
        claire et sourcée.

        Règles impératives :
        - N'invente RIEN. Si les sources ne permettent pas de répondre, dis-le
          explicitement et n'élabore pas.
        - Cite tes sources par leur NUMÉRO entre crochets dans le texte, ex. [1], [2][3].
        - Distingue ce qui est établi de ce qui est incertain/contesté.
        - Reste neutre, rigoureux, et concis.
        TXT;

    public function __construct(
        private readonly EmbeddingClientFactory $embeddingFactory,
        private readonly PublicationRepository $publications,
        private readonly LlmClientFactory $llmFactory,
        private readonly SettingsService $settings,
        #[Autowire(env: 'RAG_API_TOKEN')]
        private readonly string $apiToken = '',
    ) {
    }

    /** Liste de modèles (Open WebUI interroge {base}/models au démarrage). */
    #[Route('/api/rag/models', name: 'api_rag_models', methods: ['GET'])]
    public function models(Request $request): JsonResponse
    {
        if (!$this->authorized($request)) {
            return new JsonResponse(['error' => ['message' => 'Non autorisé.']], 401);
        }

        $now = time();
        $data = [];
        foreach (array_keys(self::RAG_MODELS) as $id) {
            $data[] = ['id' => $id, 'object' => 'model', 'created' => $now, 'owned_by' => 'scienceswiki'];
        }

        return new JsonResponse(['object' => 'list', 'data' => $data]);
    }

    #[Route('/api/rag/chat/completions', name: 'api_rag_chat', methods: ['POST'])]
    public function completions(Request $request): Response
    {
        if (!$this->authorized($request)) {
            return new JsonResponse(['error' => ['message' => 'Non autorisé.']], 401);
        }

        // La génération LLM dépasse souvent les 30 s de max_execution_time PHP
        // (vrai aussi pour le chemin non-stream, ex. génération de titre par Open
        // WebUI). On lève la limite, comme StreamAnswerController.
        @set_time_limit(0);
        ignore_user_abort(true);

        /** @var array<string,mixed> $body */
        $body = json_decode($request->getContent() ?: '[]', true) ?? [];
        /** @var list<array{role?:string,content?:mixed}> $incoming */
        $incoming = \is_array($body['messages'] ?? null) ? $body['messages'] : [];
        $stream = (bool) ($body['stream'] ?? false);

        // « Modèle » RAG demandé : même pipeline, LLM dessous éventuellement différent
        // (comparaison côte à côte). On ignore tout id inconnu (→ défaut).
        $modelId = (string) ($body['model'] ?? '');
        $modelId = \array_key_exists($modelId, self::RAG_MODELS) ? $modelId : self::MODEL_ID;

        $query = $this->lastUserText($incoming);
        if ('' === $query) {
            return new JsonResponse(['error' => ['message' => 'Aucun message utilisateur.']], 422);
        }

        // Récupération sourcée sur l'index existant (Voie A : embeddings ML + pgvector).
        [$sources, $noSourceNotice, $embedding] = $this->retrieve($query);

        // Pas de source pertinente : on renvoie un message honnête (garde-fou), sans LLM.
        if (null !== $noSourceNotice) {
            return $stream ? $this->streamText($noSourceNotice, $modelId) : $this->jsonText($noSourceNotice, $modelId);
        }

        $messages = $this->buildMessages($incoming, $query, $sources);
        $opts = ['temperature' => $this->settings->temperature(), 'max_tokens' => $this->settings->maxTokens(), 'timeout' => 300];
        // LLM dessous : override du « modèle » RAG choisi, sinon réglage courant.
        if (null !== (self::RAG_MODELS[$modelId] ?? null)) {
            $opts['model'] = self::RAG_MODELS[$modelId];
        } elseif (null !== ($m = $this->settings->model())) {
            $opts['model'] = $m;
        }

        $llm = $this->llmFactory->create();

        if (!$stream) {
            $content = $llm->complete($messages, $opts)->content.$this->sourcesAppendix($sources, $embedding);

            return $this->jsonText($content, $modelId);
        }

        $response = new StreamedResponse(function () use ($llm, $messages, $opts, $modelId, $sources, $embedding): void {
            @set_time_limit(0);
            ignore_user_abort(true);
            $id = 'chatcmpl-'.bin2hex(random_bytes(8));
            $created = time();
            $emit = function (array $delta, ?string $finish = null) use ($id, $created, $modelId): void {
                echo 'data: '.json_encode([
                    'id' => $id,
                    'object' => 'chat.completion.chunk',
                    'created' => $created,
                    'model' => $modelId,
                    'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finish]],
                ], \JSON_UNESCAPED_UNICODE)."\n\n";
                @ob_flush();
                flush();
            };

            $emit(['role' => 'assistant']);
            try {
                foreach ($llm->stream($messages, $opts) as $chunk) {
                    if ('' !== $chunk) {
                        $emit(['content' => $chunk]);
                    }
                }
            } catch (\Throwable) {
                $emit(['content' => "\n\n[La génération a échoué — réessayez.]"]);
            }
            // Locator : bloc « Sources & extraits » (passage exact derrière chaque [n]).
            $appendix = $this->sourcesAppendix($sources, $embedding);
            if ('' !== $appendix) {
                $emit(['content' => $appendix]);
            }
            $emit([], 'stop');
            echo "data: [DONE]\n\n";
            @ob_flush();
            flush();
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    /**
     * @param list<array{role?:string,content?:mixed}> $messages
     */
    private function lastUserText(array $messages): string
    {
        for ($i = \count($messages) - 1; $i >= 0; --$i) {
            if ('user' === ($messages[$i]['role'] ?? '') && \is_string($messages[$i]['content'] ?? null)) {
                return trim($messages[$i]['content']);
            }
        }

        return '';
    }

    /**
     * @return array{0:list<Publication>,1:?string,2:list<float>} sources + éventuel
     *                                                             message « pas de source » + embedding de la requête
     */
    private function retrieve(string $query): array
    {
        try {
            $embedding = $this->embeddingFactory->create()->embed($query);
        } catch (\Throwable) {
            return [[], "Le service d'indexation est momentanément indisponible. Réessayez dans un instant.", []];
        }

        $sources = [];
        foreach ($this->publications->nearestHybrid($embedding, $query, self::CHAT_NEIGHBORS, self::MAX_SOURCE_DISTANCE) as $hit) {
            $sources[] = $hit['publication'];
        }

        if ([] === $sources) {
            return [[], "Je n'ai pas trouvé de source scientifique suffisamment proche dans le corpus SciencesWiki pour répondre de façon fiable. Reformulez, ou élargissez le sujet.", $embedding];
        }

        return [$sources, null, $embedding];
    }

    /**
     * Bloc « Sources & extraits » ajouté APRÈS la réponse (locator) : pour chaque
     * source [n], le passage exact qui la justifie (meilleur chunk de texte intégral
     * vis-à-vis de la question, sinon le résumé). Rend chaque citation vérifiable.
     *
     * @param list<Publication> $sources
     * @param list<float>       $embedding
     */
    private function sourcesAppendix(array $sources, array $embedding): string
    {
        if ([] === $sources) {
            return '';
        }
        $lines = ["\n\n---", '', '**📚 Sources & extraits**', ''];
        foreach ($sources as $i => $s) {
            $authors = implode(', ', array_map(static fn (array $a): string => $a['name'], $s->getAuthors()));
            $year = $s->getPublicationDate()?->format('Y') ?? 's.d.';
            $head = \sprintf('**[%d]** %s — %s (%s)', $i + 1, $s->getTitle(), '' !== $authors ? $authors : 'auteurs inconnus', $year);
            $doi = $s->getDoi();
            if (null !== $doi && '' !== $doi) {
                $head .= \sprintf(' · [DOI](https://doi.org/%s)', $doi);
            }
            $lines[] = $head;

            $passage = (null !== $s->getId() && [] !== $embedding) ? $this->publications->bestPassageFor($s->getId(), $embedding) : null;
            $passage ??= $s->getAbstract();
            if (null !== $passage && '' !== trim($passage)) {
                $clean = trim((string) preg_replace('/\s+/', ' ', $passage));
                $excerpt = mb_substr($clean, 0, 320);
                $lines[] = '> '.$excerpt.(mb_strlen($clean) > 320 ? '…' : '');
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Construit les messages LLM : système RAG + garde-fou périmètre, l'historique
     * de conversation, puis un message final portant les SOURCES + la question.
     *
     * @param list<array{role?:string,content?:mixed}> $incoming
     * @param list<Publication>                        $sources
     *
     * @return list<LlmMessage>
     */
    private function buildMessages(array $incoming, string $query, array $sources): array
    {
        $out = [LlmMessage::system(self::CHAT_SYSTEM."\n\n".SettingsService::GEO_SCOPE_GUARD)];

        // Historique (mémoire conversationnelle), hors system et hors dernier user.
        $lastUserIdx = null;
        for ($i = \count($incoming) - 1; $i >= 0; --$i) {
            if ('user' === ($incoming[$i]['role'] ?? '')) {
                $lastUserIdx = $i;
                break;
            }
        }
        foreach ($incoming as $i => $m) {
            if ($i === $lastUserIdx) {
                continue;
            }
            $role = (string) ($m['role'] ?? '');
            $content = \is_string($m['content'] ?? null) ? $m['content'] : '';
            if ('' === $content) {
                continue;
            }
            $out[] = match ($role) {
                'assistant' => LlmMessage::assistant($content),
                'system' => LlmMessage::system($content),
                default => LlmMessage::user($content),
            };
        }

        $out[] = LlmMessage::user($this->sourcesBlock($query, $sources));

        return $out;
    }

    /**
     * @param list<Publication> $sources
     */
    private function sourcesBlock(string $query, array $sources): string
    {
        $lines = ['QUESTION : '.$query, '', 'SOURCES :'];
        foreach ($sources as $i => $s) {
            $authors = implode(', ', array_map(static fn (array $a): string => $a['name'], $s->getAuthors()));
            $year = $s->getPublicationDate()?->format('Y') ?? 's.d.';
            $lines[] = \sprintf(
                "[%d] %s — %s (%s). DOI:%s\n    Résumé : %s",
                $i + 1,
                $s->getTitle(),
                '' !== $authors ? $authors : 'auteurs inconnus',
                $year,
                $s->getDoi() ?? 'n/a',
                mb_substr($s->getAbstract() ?? '(pas de résumé)', 0, 700),
            );
        }

        return implode("\n", $lines);
    }

    private function authorized(Request $request): bool
    {
        if ('' === $this->apiToken) {
            return true; // pas de jeton configuré : endpoint ouvert (réseau interne)
        }
        $auth = $request->headers->get('Authorization', '');

        return hash_equals('Bearer '.$this->apiToken, $auth);
    }

    private function streamText(string $text, string $modelId = self::MODEL_ID): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($text, $modelId): void {
            $id = 'chatcmpl-'.bin2hex(random_bytes(8));
            $created = time();
            $base = ['id' => $id, 'object' => 'chat.completion.chunk', 'created' => $created, 'model' => $modelId];
            echo 'data: '.json_encode($base + ['choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => null]]], \JSON_UNESCAPED_UNICODE)."\n\n";
            echo 'data: '.json_encode($base + ['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]], \JSON_UNESCAPED_UNICODE)."\n\n";
            echo "data: [DONE]\n\n";
            @ob_flush();
            flush();
        });
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    private function jsonText(string $text, string $modelId = self::MODEL_ID): JsonResponse
    {
        return new JsonResponse([
            'id' => 'chatcmpl-'.bin2hex(random_bytes(8)),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $modelId,
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']],
        ]);
    }
}
