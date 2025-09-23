<?php

declare(strict_types = 1);

namespace ItechWorld\SuluArticleTwigExtensionFilterBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
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

            return [
                'uuid' => $article->getUuid(),
                'id' => $article->getId(),
                'title' => $dimensionContent->getTitle() ?? 'Article sans titre',
                'description' => $dimensionContent->getExcerptDescription() ?? '',
                'excerptTitle' => $dimensionContent->getExcerptTitle() ?? '',
                'excerptMore' => $dimensionContent->getExcerptMore() ?? '',
                'url' => $templateData['url'] ?? null,
                'template' => $dimensionContent->getTemplateKey() ?? null,
                'stage' => $dimensionContent->getStage(),
                'locale' => $dimensionContent->getLocale(),
                'published' => $dimensionContent->getWorkflowPublished(),
                'workflowPlace' => $dimensionContent->getWorkflowPlace(),
                'categories' => $dimensionContent->getExcerptCategories(),
                'tags' => $dimensionContent->getExcerptTags(),
                'created' => $article->getCreated(),
                'changed' => $article->getChanged(),
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

        // Utiliser une requête custom avec filtrage webspace
        $articles = $this->findArticlesWithWebspaceFilter(
            $limit,
            0, // offset = 0 pour loadRecent
            $templateKeys,
            $locale,
            $ignoreWebspace,
            $categoryKeys,
            $tagNames,
            $webspaceKeys
        );

        $resolvedArticles = [];
        foreach ($articles as $article) {
            $resolved = $this->resolveArticleContent($article, $locale);
            $resolved['_original'] = $article; // Garder une référence à l'article original
            $resolvedArticles[] = $resolved;
        }

        return $resolvedArticles;
    }

    /**
     * Récupère les articles récents avec contenu résolu et pagination.
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

        // Compter le total d'articles (pour pagination AJAX)
        $totalCount = $this->countArticlesWithWebspaceFilter(
            $templateKeys,
            $locale,
            $ignoreWebspace,
            $categoryKeys,
            $tagNames,
            $webspaceKeys
        );

        // Récupérer les articles paginés (pour AJAX)
        $paginatedArticles = $this->findArticlesWithWebspaceFilter(
            $limit,
            $offset,
            $templateKeys,
            $locale,
            $ignoreWebspace,
            $categoryKeys,
            $tagNames,
            $webspaceKeys
        );

        // Résoudre le contenu de chaque article paginé
        $resolvedArticles = [];
        foreach ($paginatedArticles as $article) {
            $resolved = $this->resolveArticleContent($article, $locale);
            $resolvedArticles[] = $resolved;
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
                'webspaceKeys' => $webspaceKeys
            ]
        ];
    }

    /**
     * Récupère les articles avec filtrage webspace via requête Doctrine custom.
     *
     * @param int $limit Nombre maximum d'articles
     * @param int $offset Décalage pour la pagination
     * @param array $templateKeys Types de templates
     * @param string|null $locale Locale
     * @param bool $ignoreWebspace Ignorer le filtrage webspace
     * @param array $categoryKeys Clés de catégories
     * @param array $tagNames Noms de tags
     * @param array $webspaceKeys Clés de webspaces spécifiques
     *
     * @return ArticleInterface[] Articles trouvés
     */
    private function findArticlesWithWebspaceFilter(
        int $limit,
        int $offset,
        array $templateKeys = [],
        ?string $locale = null,
        bool $ignoreWebspace = false,
        array $categoryKeys = [],
        array $tagNames = [],
        array $webspaceKeys = []
    ): array {
        $qb = $this->entityManager->createQueryBuilder();

        $qb->select('DISTINCT a')
            ->from(ArticleInterface::class, 'a')
            ->leftJoin('a.dimensionContents', 'dc')
            ->where('dc.locale = :locale')
            ->andWhere('dc.stage = :stage')
            ->setParameter('locale', $locale)
            ->setParameter('stage', 'live')
            ->orderBy('a.created', 'DESC')
            ->setMaxResults($limit);

        if ($offset > 0) {
            $qb->setFirstResult($offset);
        }

        // Filtrage webspace
        if (!$ignoreWebspace) {
            $webspacesToFilter = $this->determineWebspacesToFilter($webspaceKeys);
            if (!empty($webspacesToFilter)) {
                $qb->andWhere('dc.mainWebspace IN (:webspaces)')
                    ->setParameter('webspaces', $webspacesToFilter);
            }
        }

        // Filtres optionnels existants via le repository SULU
        if (!empty($templateKeys) || !empty($categoryKeys) || !empty($tagNames)) {
            // Pour les filtres complexes, utiliser le repository SULU puis filtrer les résultats
            $suluFilters = [
                'locale' => $locale,
                'stage' => 'live',
            ];

            if (!empty($templateKeys)) {
                $suluFilters['templateKeys'] = $templateKeys;
            }

            if (!empty($categoryKeys)) {
                $suluFilters['categoryKeys'] = $categoryKeys;
                $suluFilters['categoryOperator'] = 'OR';
            }

            if (!empty($tagNames)) {
                $suluFilters['tagNames'] = $tagNames;
                $suluFilters['tagOperator'] = 'OR';
            }

            $selects = [
                ArticleRepositoryInterface::GROUP_SELECT_ARTICLE_WEBSITE => true,
                ArticleRepositoryInterface::SELECT_ARTICLE_CONTENT => true,
            ];

            $allFilteredArticles = $this->articleRepository->findBy($suluFilters, ['created' => 'desc'], $selects);

            // Filtrer par webspace si nécessaire
            if (!$ignoreWebspace) {
                $webspacesToFilter = $this->determineWebspacesToFilter($webspaceKeys);
                if (!empty($webspacesToFilter)) {
                    $filteredByWebspace = [];
                    foreach ($allFilteredArticles as $article) {
                        // Vérifier le webspace de l'article
                        foreach ($article->getDimensionContents() as $dimensionContent) {
                            if ($dimensionContent->getLocale() === $locale
                                && $dimensionContent->getStage() === 'live'
                                && in_array($dimensionContent->getMainWebspace(), $webspacesToFilter, true)) {
                                $filteredByWebspace[] = $article;
                                break;
                            }
                        }
                    }
                    $allFilteredArticles = $filteredByWebspace;
                }
            }

            // Appliquer pagination manuelle
            return array_slice(iterator_to_array($allFilteredArticles), $offset, $limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Compte les articles avec filtrage webspace.
     *
     * @param array $templateKeys Types de templates
     * @param string|null $locale Locale
     * @param bool $ignoreWebspace Ignorer le filtrage webspace
     * @param array $categoryKeys Clés de catégories
     * @param array $tagNames Noms de tags
     * @param array $webspaceKeys Clés de webspaces spécifiques
     *
     * @return int Nombre d'articles
     */
    private function countArticlesWithWebspaceFilter(
        array $templateKeys = [],
        ?string $locale = null,
        bool $ignoreWebspace = false,
        array $categoryKeys = [],
        array $tagNames = [],
        array $webspaceKeys = []
    ): int {
        // Pour les filtres complexes (templates, categories, tags),
        // utiliser le repository SULU puis compter les résultats
        if (!empty($templateKeys) || !empty($categoryKeys) || !empty($tagNames)) {
            $suluFilters = [
                'locale' => $locale,
                'stage' => 'live',
            ];

            if (!empty($templateKeys)) {
                $suluFilters['templateKeys'] = $templateKeys;
            }

            if (!empty($categoryKeys)) {
                $suluFilters['categoryKeys'] = $categoryKeys;
                $suluFilters['categoryOperator'] = 'OR';
            }

            if (!empty($tagNames)) {
                $suluFilters['tagNames'] = $tagNames;
                $suluFilters['tagOperator'] = 'OR';
            }

            // Utiliser countBy du repository SULU (plus efficace pour ces filtres)
            $totalWithSuluFilters = $this->articleRepository->countBy($suluFilters);

            // Si pas de filtrage webspace, retourner directement
            if ($ignoreWebspace) {
                return $totalWithSuluFilters;
            }

            // Sinon, récupérer les articles et filtrer par webspace
            $allFilteredArticles = $this->articleRepository->findBy($suluFilters, ['created' => 'desc'], [
                ArticleRepositoryInterface::GROUP_SELECT_ARTICLE_WEBSITE => true,
                ArticleRepositoryInterface::SELECT_ARTICLE_CONTENT => true,
            ]);

            $webspacesToFilter = $this->determineWebspacesToFilter($webspaceKeys);
            if (empty($webspacesToFilter)) {
                return $totalWithSuluFilters;
            }

            $count = 0;
            foreach ($allFilteredArticles as $article) {
                foreach ($article->getDimensionContents() as $dimensionContent) {
                    if ($dimensionContent->getLocale() === $locale
                        && $dimensionContent->getStage() === 'live'
                        && in_array($dimensionContent->getMainWebspace(), $webspacesToFilter, true)) {
                        $count++;
                        break;
                    }
                }
            }
            return $count;
        }

        // Pour les cas simples (pas de filtres complexes), requête COUNT optimisée
        $qb = $this->entityManager->createQueryBuilder();

        $qb->select('COUNT(DISTINCT a.uuid)')
            ->from(ArticleInterface::class, 'a')
            ->leftJoin('a.dimensionContents', 'dc')
            ->where('dc.locale = :locale')
            ->andWhere('dc.stage = :stage')
            ->setParameter('locale', $locale)
            ->setParameter('stage', 'live');

        // Filtrage webspace
        if (!$ignoreWebspace) {
            $webspacesToFilter = $this->determineWebspacesToFilter($webspaceKeys);
            if (!empty($webspacesToFilter)) {
                $qb->andWhere('dc.mainWebspace IN (:webspaces)')
                    ->setParameter('webspaces', $webspacesToFilter);
            }
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
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
