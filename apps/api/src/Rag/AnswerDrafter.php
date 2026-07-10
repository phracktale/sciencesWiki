<?php

declare(strict_types=1);

namespace App\Rag;

use App\Ai\Llm\LlmClient;
use App\Entity\Answer;
use App\Entity\AnswerRevision;
use App\Entity\Footnote;
use App\Entity\Publication;
use App\Entity\Question;
use App\Enum\AnswerType;
use App\Enum\RevisionAuthorType;
use App\Harvester\Ai\EmbeddingClientFactory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Génère le brouillon de réponse (Q/R) ancré RAG (cf. spec §8.2/§8.4) :
 * récupération → prompt sourcé → LLM → parsing → persistance (Answer +
 * AnswerRevision IA + notes de bas de page). Non publié : passe en relecture.
 */
final class AnswerDrafter
{
    public function __construct(
        private readonly RagRetriever $retriever,
        private readonly PromptBuilder $promptBuilder,
        private readonly LlmClient $llm,
        private readonly EmbeddingClientFactory $embeddingFactory,
        private readonly EntityManagerInterface $em,
        private readonly \App\Service\SettingsService $settings,
        private readonly FaithfulnessChecker $faithfulness,
        private readonly WikipediaLinker $wikipedia,
    ) {
    }

    public function draft(Question $question, AnswerType $type, int $k = 5): Answer
    {
        $sources = $this->retrieveSources($question, $k);
        // Respecte le modèle Q/R choisi en back-office (rag.model) ; sinon LLM_MODEL.
        // Budget large : l'article 5 sections (vulgarisation ~1500 signes/sous-partie +
        // aller plus loin + idées reçues + académique) dépasse largement 1200 tokens.
        $opts = ['temperature' => 0.2, 'max_tokens' => 4000];
        if (null !== ($m = $this->settings->model())) {
            $opts['model'] = $m;
        }
        $start = hrtime(true);
        // Génération d'article → prompt système « rédaction » (riche, 5 sections).
        $completion = $this->llm->complete($this->buildMessages($question, $sources, true), $opts);
        $ms = (int) round((hrtime(true) - $start) / 1e6);

        return $this->persistFromText($question, $type, $sources, $completion->content, $ms);
    }

    /**
     * Garantit l'embedding de la question puis récupère les sources RAG
     * pertinentes (filtrées par distance si $maxDistance est fourni).
     *
     * @return list<Publication>
     */
    public function retrieveSources(Question $question, int $k = 5, ?float $maxDistance = null): array
    {
        if (null === $question->getEmbedding()) {
            $question->setEmbedding($this->embeddingFactory->create()->embed($question->getText()));
        }

        return $this->retriever->retrieve($question, $k, $maxDistance);
    }

    /**
     * @param list<Publication> $sources
     *
     * @return list<\App\Ai\Llm\LlmMessage>
     */
    public function buildMessages(Question $question, array $sources, bool $forArticle = false): array
    {
        return $this->promptBuilder->build($question, $sources, $forArticle);
    }

    /**
     * Parse le texte (sections délimitées) et persiste Answer + révision IA +
     * notes de bas de page. Sert à la fois à la génération directe et au flux SSE.
     *
     * @param list<Publication> $sources
     */
    public function persistFromText(Question $question, AnswerType $type, array $sources, string $content, ?int $generationMs = null): Answer
    {
        $parsed = $this->analyze($content, $sources);

        // Vérification de fidélité : marque les affirmations non soutenues, puis
        // promeut les marqueurs en liens Wikipédia réels quand un article existe
        // (anti-hallucination ; le relecteur tranche le reste). Cran 2 : on passe
        // l'embedding de la question pour vérifier contre les PASSAGES plein texte.
        $embedding = $question->getEmbedding()?->toArray();
        $parsed['vulgarization'] = $this->wikipedia->resolve($this->faithfulness->annotate($parsed['vulgarization'], $sources, $embedding));
        $parsed['academic'] = $this->wikipedia->resolve($this->faithfulness->annotate($parsed['academic'], $sources, $embedding));

        if (null === $question->getTitle() && '' !== $parsed['title']) {
            $question->setTitle($parsed['title']);
        }

        // Modèle effectif FIGÉ à la génération : un changement de réglage ultérieur
        // ne modifie pas la signature des réponses déjà rédigées.
        $model = $this->settings->model() ?? $this->llm->model();

        $answer = new Answer($question, $type);
        $answer->setGenerationModel($model)->setGenerationMs($generationMs);
        $revision = (new AnswerRevision(RevisionAuthorType::Ai))
            ->setAcademicContent($parsed['academic'])
            ->setVulgarizationContent($parsed['vulgarization'])
            ->setChangeSummary(\sprintf('Brouillon initial généré par IA (%s)', $model));
        $answer->addRevision($revision);

        foreach ($parsed['footnotes'] as $footnote) {
            $revision->addFootnote(new Footnote($footnote['publication'], $footnote['marker']));
        }

        $this->em->persist($question);
        $this->em->persist($answer);

        return $answer;
    }

    /**
     * Parse la sortie en sections « ## TITRE / ## VULGARISATION / ## ACADEMIQUE »
     * et déduit les notes de bas de page des marqueurs [n] présents dans le texte.
     *
     * @param list<Publication> $sources
     *
     * @return array{title:string,academic:string,vulgarization:string,footnotes:list<array{marker:int,publication:Publication}>}
     */
    public function analyze(string $content, array $sources): array
    {
        $sections = $this->splitSections($content);
        $title = trim($sections['titre'] ?? '');
        $vulgarization = trim($sections['vulgarisation'] ?? '');
        $academic = trim($sections['academique'] ?? '');

        // Les sections « grand public » ALLER PLUS LOIN et IDEES RECUES sont repliées dans la
        // vulgarisation (en sous-titres markdown) : pas de champ dédié en base ni au front.
        $allerPlusLoin = trim($sections['aller plus loin'] ?? '');
        $ideesRecues = trim($sections['idees recues'] ?? '');
        if ('' !== $allerPlusLoin) {
            $vulgarization .= "\n\n## Aller plus loin\n\n".$allerPlusLoin;
        }
        if ('' !== $ideesRecues) {
            $vulgarization .= "\n\n## Idées reçues\n\n".$ideesRecues;
        }

        // Aucune section reconnue (ex. LLM stub) : tout en vulgarisation.
        if ('' === $vulgarization && '' === $academic && '' === $title) {
            $vulgarization = trim($content);
        }

        // Notes de bas de page : marqueurs [n] cités dans le texte → source n.
        $footnotes = [];
        $seen = [];
        if (preg_match_all('/\[(\d{1,2})\]/', $vulgarization.' '.$academic, $m)) {
            foreach ($m[1] as $raw) {
                $marker = (int) $raw;
                $idx = $marker - 1;
                if (isset($sources[$idx]) && !isset($seen[$marker])) {
                    $seen[$marker] = true;
                    $footnotes[] = ['marker' => $marker, 'publication' => $sources[$idx]];
                }
            }
        }

        return ['title' => $title, 'academic' => $academic, 'vulgarization' => $vulgarization, 'footnotes' => $footnotes];
    }

    /**
     * @return array<string,string> clés normalisées : titre | vulgarisation | academique
     */
    private function splitSections(string $content): array
    {
        $content = (string) preg_replace('/^```[a-z]*|```$/mi', '', trim($content));
        $sections = [];
        // Découpe sur les 5 en-têtes « ## XXX » (insensible à la casse/accents simples).
        $parts = preg_split('/^\s*#{1,3}\s*(TITRE|VULGARISATION|ALLER PLUS LOIN|ID[EÉ]ES RE[CÇ]UES|ACAD[EÉ]MIQUE)\s*$/mui', $content, -1, \PREG_SPLIT_DELIM_CAPTURE);
        if (false === $parts || \count($parts) < 3) {
            return [];
        }

        for ($i = 1; $i < \count($parts); $i += 2) {
            $key = mb_strtolower((string) $parts[$i]);
            $key = str_replace(['é', 'è', 'ç'], ['e', 'e', 'c'], $key); // clés : titre | vulgarisation | aller plus loin | idees recues | academique
            $sections[$key] = (string) ($parts[$i + 1] ?? '');
        }

        return $sections;
    }
}
