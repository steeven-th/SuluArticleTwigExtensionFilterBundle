<?php

declare(strict_types = 1);

namespace ItechWorld\SuluArticleTwigExtensionFilterBundle\Twig;

use ItechWorld\SuluArticleTwigExtensionFilterBundle\Service\ArticleService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Extension Twig pour charger les articles dans les templates.
 *
 * Fournit des fonctions pour récupérer les articles récents et similaires
 * compatible avec SULU 3.0 et le nouveau système d'articles.
 */
class ArticleViewDocumentTwigExtension extends AbstractExtension
{
    public function __construct(
        private ArticleService $articleService
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('sulu_article_load_by_uuid', [$this, 'loadByUuid']),
            new TwigFunction('sulu_article_count_published', [$this, 'countPublished']),
            new TwigFunction('sulu_article_load_recent', [$this, 'loadRecent']),
            new TwigFunction('sulu_article_load_recent_paginated', [$this, 'loadRecentPaginated']),
        ];
    }


    /**
     * Charge un article par son UUID.
     *
     * @param string $uuid UUID de l'article
     * @param string|null $locale Locale (par défaut: locale courante)
     *
     * @return array|null Article ou Null
     */
    public function loadByUuid(string $uuid, ?string $locale = null): ?array
    {
        return $this->articleService->findByUuid($uuid, $locale);
    }


    /**
     * Compte le nombre d'articles publiés.
     *
     * @param string|null $locale Locale (par défaut: locale courante)
     * @param array $filters Filtres supplémentaires
     *
     * @return int Nombre d'articles
     */
    public function countPublished(?string $locale = null, array $filters = []): int
    {
        return $this->articleService->countPublished($locale, $filters);
    }

    /**
     * Charge les articles récents avec contenu résolu (compatible templates).
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
        return $this->articleService->loadRecent(
            $limit,
            $templateKeys,
            $locale,
            $ignoreWebspace,
            $categoryKeys,
            $tagNames,
            $webspaceKeys
        );
    }

    /**
     * Charge les articles récents avec pagination (offset).
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
     * @return array Liste des articles récents avec contenu résolu
     */
    public function loadRecentPaginated(
        int $limit = 12,
        int $offset = 0,
        array $templateKeys = [],
        ?string $locale = null,
        bool $ignoreWebspace = false,
        array $categoryKeys = [],
        array $tagNames = [],
        array $webspaceKeys = []
    ): array {
        return $this->articleService->loadRecentPaginated(
            $limit,
            $offset,
            $templateKeys,
            $locale,
            $ignoreWebspace,
            $categoryKeys,
            $tagNames,
            $webspaceKeys
        );
    }
}
