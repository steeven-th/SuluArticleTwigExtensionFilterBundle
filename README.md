<div align="center">
    <img width="150" src="./doc/images/logo.png" alt="Itech World logo">
</div>

<h1 align="center">Article Twig Extension Filter Bundle for <a href="https://sulu.io" target="_blank">Sulu</a></h1>

<h3 align="center">Developed by <a href="https://github.com/steeven-th" target="_blank">Steeven THOMAS</a></h3>
<p align="center">
    <a href="LICENSE" target="_blank">
        <img src="https://img.shields.io/badge/license-MIT-green" alt="GitHub license">
    </a>
    <a href="https://sulu.io/" target="_blank">
        <img src="https://img.shields.io/badge/sulu_compatibility-%3E=3.0-cyan" alt="Sulu compatibility">
    </a>
</p>
ArticleTwigExtensionFilterBundle extends the Sulu CMS to enable article retrieval in TWIG without ElasticSearch

## 📂 Requirements

* PHP ^8.2
* Sulu ^3.0@dev

## 🛠️ Features

* TWIG extension `sulu_article_load_by_uuid allowing` an article to be retrieved by its identifier
* TWIG extension `sulu_article_count_published` for counting the number of published articles
* TWIG extension `sulu_article_load_recent` allowing you to retrieve the latest recent articles
* TWIG extension `sulu_article_load_recent_paginated` allowing you to retrieve the latest recent articles with pagination

## 📝 Installation

### Composer
```bash
composer require itech-world/sulu-article-twig-extension-filter-bundle
```

### Symfony Flex
If you don't use Symfony Flex, you can add the bundle to your `config/bundles.php` file:
```php
return [
    // ...
    ItechWorld\SuluArticleTwigExtensionFilterBundle\ItechWorldSuluArticleTwigExtensionFilterBundle::class => true,
];
```

### Instructions

#### Retrieve an item using its UUID

Use `sulu_article_load_by_uuid`.
Possible parameters:
- `uuid` : The UUID of the article
- `locale` : The locale of the article

#### Count the number of published articles

Use `sulu_article_count_published`.
Possible parameters:
- `locale` : The locale of the article
- `filters` : An array of filters to apply to the query

#### Retrieve the latest recent articles

Use `sulu_article_load_recent`.
Possible parameters:
- `limit` : The number of articles to retrieve
- `templateKeys` : An array of template keys to filter the articles
- `locale` : The locale of the article
- `ignoreWebspace` : Ignore webspace and return all articles
- `categoryKeys` : An array of category keys to filter the articles
- `tagNames` : An array of tag names to filter the articles
- `webspaceKeys` : An array of webspace keys to filter the articles (only if `ignoreWebspace` is false)

#### Retrieve the latest recent articles with pagination

Use `sulu_article_load_recent_paginated`.
Possible parameters:
- `limit` : The number of articles to retrieve
- `offset` : The offset of the articles to retrieve
- `templateKeys` : An array of template keys to filter the articles
- `locale` : The locale of the article
- `ignoreWebspace` : Ignore webspace and return all articles
- `categoryKeys` : An array of category keys to filter the articles
- `tagNames` : An array of tag names to filter the articles
- `webspaceKeys` : An array of webspace keys to filter the articles (only if `ignoreWebspace` is false)

### Examples of usage

Create a `templates/articles.html.twig` file with the following content:
```html
{% extends 'base.html.twig' %}

{% block content %}

    {% set paginatedResult = sulu_article_load_recent_paginated(12, 0, ['article-template-key'], app.request.locale) %}
    {% set recentArticles = paginatedResult.articles %}
    {% set pagination = paginatedResult.pagination %}
    
    {% if recentArticles %}
        {% for article in articles %}
            {{ article.title }}
        {% endfor %}
    {% endif %}

{% endblock %}
```

**Note :** Replace `article-template-key` with the key of your XML template.

For pagination, you can create an AJAX route in a Controller and use `ItechWorld\SuluArticleTwigExtensionFilterBundle\Service\ArticleService`.

Example:
```php
<?php

declare(strict_types = 1);

namespace App\Controller\Front;

use ItechWorld\SuluArticleTwigExtensionFilterBundle\Service\ArticleService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class ArticleController extends AbstractController
{
    public function __construct(
        private ArticleService $articleService
    ) {
    }

    /**
     * API AJAX to load more articles with pagination.
     *
     * @param Request $request
     * @return JsonResponse
     */
    #[Route('/api/articles/load-more', name: 'api_articles_load_more', methods: ['GET'])]
    public function loadMore(Request $request): JsonResponse
    {
        $offset = (int)$request->query->get('offset', 0);
        $limit = (int)$request->query->get('limit', 12);
        $templateType = $request->query->get('type', 'project');
        $locale = $request->query->get('locale', $request->getLocale());

        try {
            $result = $this->articleService->loadRecentPaginated(
                $limit,
                $offset,
                [$templateType],
                $locale
            );

            // Render the HTML of articles
            $articlesHtml = '';
            if (!empty($result['articles'])) {
                $articlesHtml = $this->renderView('articles_cards.html.twig', [
                    'articles' => $result['articles']
                ]);
            }

            return new JsonResponse([
                'success' => true,
                'html' => $articlesHtml,
                'pagination' => $result['pagination'],
                'articlesCount' => count($result['articles'])
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
```

## 🐛 Bug and Idea

See the [open issues](https://github.com/steeven-th/SuluGrapesJsBundle/issues) for a list of proposed
features (and known issues).

## 💰 Support me

You can buy me a coffee to support me **this plugin is 100% free**.

[Buy me a coffee](https://www.buymeacoffee.com/steeven.th)

## 👨‍💻 Contact

<a href="https://steeven-th.dev"><img src="https://avatars.githubusercontent.com/u/82022828?s=96&v=4" width="48"></a>
<a href="https://x.com/ThomasSteeven2"><img src="https://upload.wikimedia.org/wikipedia/commons/thumb/2/2d/Twitter_X.png/640px-Twitter_X.png" width="48"></a>

## 📘&nbsp; License

This bundle is under the [MIT License](LICENSE).
