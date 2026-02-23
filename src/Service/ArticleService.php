<?php

declare(strict_types = 1);

namespace ItechWorld\SuluArticleTwigExtensionFilterBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Sulu\Article\Domain\Model\ArticleInterface;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Service wrapper pour l'accès aux articles SULU 3.0.
 *
 * Encapsule l'accès au repository d'articles et fournit des méthodes
 * simplifiées pour récupérer les articles avec filtrage.
 */
class ArticleService
{
    public function __construct(
        private ArticleRepositoryInterface $articleRepository,
        private ContentManagerInterface $contentManager,
        private EntityManagerInterface $entityManager,
        private RequestStack $requestStack,
        private WebspaceManagerInterface $webspaceManager
    ) {
    }

    /**
     * Récupère un article par son UUID.
     *
     * @param string $uuid UUID de l'article
     * @param string|null $locale Locale (par défaut: locale courante)
     *
     * @return ArticleInterface|null
     */
    public function findByUuid(string $uuid, ?string $locale = null): ?array
    {
        $request = $this->requestStack->getCurrentRequest();

        if (!$locale && $request) {
            $locale = $request->getLocale();
        }

        $article = $this->articleRepository->findOneBy([
            'uuid' => $uuid,
            'locale' => $locale,
            'stage' => 'live',
        ], [
            ArticleRepositoryInterface::GROUP_SELECT_ARTICLE_WEBSITE => true,
            ArticleRepositoryInterface::SELECT_ARTICLE_CONTENT => true,
        ]);

        return $this->resolveArticleContent($article, $locale);
    }

    /**
     * Compte le nombre total d'articles publiés.
     *
     * @param string|null $locale Locale (par défaut: locale courante)
     * @param array $filters Filtres supplémentaires
     *
     * @return int Nombre d'articles
     */
    public function countPublished(?string $locale = null, array $filters = []): int
    {
        $request = $this->requestStack->getCurrentRequest();

        if (!$locale && $request) {
            $locale = $request->getLocale();
        }

        $defaultFilters = [
            'locale' => $locale,
            'stage' => 'live',
        ];

        return $this->articleRepository->countBy(array_merge($defaultFilters, $filters));
    }

    /**
     * Résout le contenu d'un article pour le rendre accessible dans les templates.
     *
     * @param ArticleInterface $article
     * @param string|null $locale
     *
     * @return array Le contenu résolu de l'article
     */
    public function resolveArticleContent(ArticleInterface $article, ?string $locale = null): array
    {
        $request = $this->requestStack->getCurrentRequest();

        if (!$locale && $request) {
            $locale = $request->getLocale();
        }

        try {
            // Résoudre le contenu de l'article via le ContentManager (correct pour SULU 3.0)
            $dimensionAttributes = [
                'locale' => $locale,
                'stage' => 'live', // Articles publiés
            ];

            $dimensionContent = $this->contentManager->resolve($article, $dimensionAttributes);

            // Extraire les données utiles du DimensionContent
            $templateData = $dimensionContent->getTemplateData();

            // Résoudre les catégories en tableaux exploitables dans Twig
            $resolvedCategories = [];
            foreach ($dimensionContent->getExcerptCategories() as $category) {
                $translation = $category->findTranslationByLocale($locale);
                $name = $translation ? $translation->getTranslation() : $category->getKey();
                $resolvedCategories[] = [
                    'id' => $category->getId(),
                    'key' => $category->getKey(),
                    'name' => $name,
                    'title' => $name,
                ];
            }

            // Résoudre les tags en tableaux exploitables dans Twig
            $resolvedTags = [];
            foreach ($dimensionContent->getExcerptTags() as $tag) {
                $resolvedTags[] = [
                    'name' => $tag->getName(),
                ];
            }

            return [
                'uuid' => $article->getUuid(),
                'id' => $article->getId(),
                'title' => $dimensionContent->getTitle() ?? 'Article sans titre',
                'description' => $dimensionContent->getExcerptDescription() ?? '',
                'excerptTitle' => $dimensionContent->getExcerptTitle() ?? '',
                'excerptMore' => $dimensionContent->getExcerptMore() ?? '',
                'url' => $this->resolveArticleUrl($templateData['url'] ?? null),
                'template' => $dimensionContent->getTemplateKey() ?? null,
                'stage' => $dimensionContent->getStage(),
                'locale' => $dimensionContent->getLocale(),
                'published' => $dimensionContent->getWorkflowPublished(),
                'workflowPlace' => $dimensionContent->getWorkflowPlace(),
                'categories' => $resolvedCategories,
                'tags' => $resolvedTags,
                'authored' => $dimensionContent->getAuthored(),
                'created' => $article->getCreated(),
                'changed' => $article->getChanged(),
                'author' => $dimensionContent->getAuthor()->getFullName(),
                'creator' => $article->getCreator()->getContact()->getFullName(),
                'changer' => $article->getChanger()->getContact()->getFullName(),
                'content' => $templateData,
                '_original' => $article,
                '_dimensionContent' => $dimensionContent
            ];
        } catch (\Exception $e) {
            // En cas d'erreur, retourner un contenu minimal
            return [
                'uuid' => $article->getUuid(),
                'id' => $article->getId(),
                'title' => 'Article ' . $article->getUuid(),
                'description' => 'Erreur de résolution: ' . $e->getMessage(),
                'url' => null,
                'content' => [],
                '_original' => $article,
                '_error' => $e->getMessage()
            ];
        }
    }

    /**
     * Récupère les articles récents avec contenu résolu.
     *
     * @param int $limit Nombre maximum d'articles à retourner
     * @param array $templateKeys Filtrer par types de templates d'articles
     * @param string|null $locale Locale spécifique (par défaut: locale courante)
     * @param bool $ignoreWebspace Ignorer les restrictions de webspace
     * @param array $categoryKeys Filtrer par clés de catégories
     * @param array $tagNames Filtrer par noms de tags
     * @param array $webspaceKeys Filtrer par clés de webspaces spécifiques (si vide et ignoreWebspace=false, utilise le webspace courant)
     *
     * @return array Liste des articles récents avec contenu résolu
     */
    public function loadRecent(
        int $limit = 12,
        array $templateKeys = [],
        ?string $locale = null,
        bool $ignoreWebspace = false,
        array $categoryKeys = [],
        array $tagNames = [],
        array $webspaceKeys = []
    ): array {
        $request = $this->requestStack->getCurrentRequest();

        if (!$locale && $request) {
            $locale = $request->getLocale();
        }

        // Construire la requête avec tous les filtres
        $qb = $this->buildArticleQueryBuilder(
            $locale,
            $ignoreWebspace,
            $templateKeys,
            $categoryKeys,
            $tagNames,
            $webspaceKeys
        );

        $qb->select('DISTINCT a')
            ->addSelect('dc')
            ->addSelect('COALESCE(dc.authored, a.created) AS HIDDEN effective_date')
            ->orderBy('effective_date', 'DESC')
            ->setMaxResults($limit);

        $articles = $qb->getQuery()->getResult();

        $resolvedArticles = [];
        foreach ($articles as $article) {
            $resolvedArticles[] = $this->resolveArticleContent($article, $locale);
        }

        return $resolvedArticles;
    }

    /**
     * Récupère les articles récents avec contenu résolu et pagination.
     *
     * Utilise un QueryBuilder partagé : un clone pour le COUNT total,
     * puis l'original avec LIMIT/OFFSET pour les articles paginés.
     *
     * @param int $limit Nombre maximum d'articles à retourner
     * @param int $offset Nombre d'articles à ignorer (pour la pagination)
     * @param array $templateKeys Filtrer par types de templates d'articles
     * @param string|null $locale Locale spécifique (par défaut: locale courante)
     * @param bool $ignoreWebspace Ignorer les restrictions de webspace
     * @param array $categoryKeys Filtrer par clés de catégories
     * @param array $tagNames Filtrer par noms de tags
     * @param array $webspaceKeys Filtrer par clés de webspaces spécifiques (si vide et ignoreWebspace=false, utilise le webspace courant)
     *
     * @return array Liste des articles récents avec contenu résolu et informations de pagination
     */
    public function loadRecentPaginated(
        int $limit = 6,
        int $offset = 0,
        array $templateKeys = [],
        ?string $locale = null,
        bool $ignoreWebspace = false,
        array $categoryKeys = [],
        array $tagNames = [],
        array $webspaceKeys = []
    ): array {
        $request = $this->requestStack->getCurrentRequest();

        if (!$locale && $request) {
            $locale = $request->getLocale();
        }

        // Construire la requête de base avec tous les filtres (FROM, JOIN, WHERE)
        $qb = $this->buildArticleQueryBuilder(
            $locale,
            $ignoreWebspace,
            $templateKeys,
            $categoryKeys,
            $tagNames,
            $webspaceKeys
        );

        // Compter le total via un clone du QueryBuilder de base
        $countQb = clone $qb;
        $countQb->select('COUNT(DISTINCT a.uuid)');
        $totalCount = (int) $countQb->getQuery()->getSingleScalarResult();

        // Récupérer les articles paginés avec l'original
        $qb->select('DISTINCT a')
            ->addSelect('dc')
            ->addSelect('COALESCE(dc.authored, a.created) AS HIDDEN effective_date')
            ->orderBy('effective_date', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $paginatedArticles = $qb->getQuery()->getResult();

        // Résoudre le contenu de chaque article paginé
        $resolvedArticles = [];
        foreach ($paginatedArticles as $article) {
            $resolvedArticles[] = $this->resolveArticleContent($article, $locale);
        }

        return [
            'articles' => $resolvedArticles,
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'currentCount' => count($resolvedArticles),
                'totalCount' => $totalCount,
                'hasMore' => ($offset + $limit) < $totalCount,
                'nextOffset' => $offset + $limit,
            ],
            'debug' => [
                'totalArticlesFound' => $totalCount,
                'requestedOffset' => $offset,
                'requestedLimit' => $limit,
                'webspaceFiltering' => !$ignoreWebspace,
                'webspaceKeys' => $webspaceKeys,
            ],
        ];
    }

    /**
     * Construit le QueryBuilder de base avec tous les filtres communs.
     *
     * Factorisation des clauses FROM, JOIN et WHERE partagées entre
     * les requêtes de comptage et de récupération d'articles.
     *
     * @param string|null $locale Locale
     * @param bool $ignoreWebspace Ignorer le filtrage webspace
     * @param array $templateKeys Types de templates
     * @param array $categoryKeys Clés de catégories
     * @param array $tagNames Noms de tags
     * @param array $webspaceKeys Clés de webspaces spécifiques
     *
     * @return QueryBuilder Le QueryBuilder prêt à recevoir un SELECT
     */
    private function buildArticleQueryBuilder(
        ?string $locale,
        bool $ignoreWebspace,
        array $templateKeys,
        array $categoryKeys,
        array $tagNames,
        array $webspaceKeys
    ): QueryBuilder {
        $qb = $this->entityManager->createQueryBuilder();

        $qb->from(ArticleInterface::class, 'a')
            ->leftJoin('a.dimensionContents', 'dc')
            ->where('dc.locale = :locale')
            ->andWhere('dc.stage = :stage')
            ->setParameter('locale', $locale)
            ->setParameter('stage', 'live');

        // Filtrage Webspace (Main + Additional)
        if (!$ignoreWebspace) {
            $webspaces = $this->determineWebspacesToFilter($webspaceKeys);
            if (!empty($webspaces)) {
                $qb->leftJoin('dc.additionalWebspaces', 'aw');
                $qb->andWhere('(dc.mainWebspace IN (:webspaces) OR aw.additionalWebspace IN (:webspaces))')
                    ->setParameter('webspaces', $webspaces);
            }
        }

        // Filtres Templates / Categories / Tags
        if (!empty($templateKeys)) {
            $qb->andWhere('dc.templateKey IN (:templates)')
                ->setParameter('templates', $templateKeys);
        }
        if (!empty($categoryKeys)) {
            $qb->innerJoin('dc.excerptCategories', 'category')
                ->andWhere('category.key IN (:categoryKeys)')
                ->setParameter('categoryKeys', $categoryKeys);
        }
        if (!empty($tagNames)) {
            $qb->innerJoin('dc.excerptTags', 'tag')
                ->andWhere('tag.name IN (:tagNames)')
                ->setParameter('tagNames', $tagNames);
        }

        return $qb;
    }

    /**
     * Résout l'URL d'un article depuis les données brutes du template.
     *
     * Gère les deux formats possibles :
     * - String (type "route") : retourne la valeur telle quelle
     * - Array (type "page_tree_route") : construit l'URL depuis page.path + suffix
     *
     * @param mixed $urlData Données brutes du champ URL
     *
     * @return string|null L'URL résolue ou null
     */
    private function resolveArticleUrl(mixed $urlData): ?string
    {
        if (\is_string($urlData)) {
            return $urlData;
        }

        if (\is_array($urlData)
            && \is_array($urlData['page'] ?? null)
            && \is_string($urlData['page']['path'] ?? null)
            && \is_string($urlData['suffix'] ?? null)
        ) {
            return \rtrim($urlData['page']['path'], '/') . '/' . \ltrim($urlData['suffix'], '/');
        }

        return null;
    }

    /**
     * Détermine les webspaces à utiliser pour le filtrage.
     *
     * @param array $webspaceKeys Webspaces spécifiés explicitement
     *
     * @return array Liste des clés de webspaces à filtrer
     */
    private function determineWebspacesToFilter(array $webspaceKeys): array
    {
        // Si des webspaces sont spécifiés explicitement, les utiliser
        if (!empty($webspaceKeys)) {
            return $webspaceKeys;
        }

        // Sinon, déterminer le webspace courant depuis la requête
        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            return [];
        }

        try {
            $portalInformation = $this->webspaceManager->findPortalInformationByUrl(
                $request->getSchemeAndHttpHost(),
                $request->get('_environment')
            );

            if ($portalInformation && $portalInformation->getWebspace()) {
                return [$portalInformation->getWebspace()->getKey()];
            }
        } catch (\Exception) {
            // En cas d'erreur, ne pas filtrer par webspace
        }

        return [];
    }
}
