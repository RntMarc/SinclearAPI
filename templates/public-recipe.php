<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{title}} – Sinclear Beyond Rezept</title>
    <meta name="description" content="{{description}}">
    <meta name="robots" content="noindex">
    <script type="application/ld+json">{{jsonLd}}</script>
</head>
<body>
    <article itemscope itemtype="https://schema.org/Recipe">
        <header>
            <h1 itemprop="name">{{title}}</h1>
            {{#description}}
            <p itemprop="description tagline">{{description}}</p>
            {{/description}}
        </header>

        <img itemprop="image" src="{{imageSrc}}" alt="{{imageAlt}}">

        <dl>
            <dt>Kategorie</dt>
            <dd itemprop="recipeCategory">{{category}}</dd>
            <dt>Portionen</dt>
            <dd itemprop="recipeYield yield">{{servings}}</dd>
            {{#dietaryTags}}
            <dt>Ernährung</dt>
            <dd itemprop="keywords">{{dietaryTags}}</dd>
            {{/dietaryTags}}
            {{#rating}}
            <dt>Bewertung</dt>
            <dd itemprop="aggregateRating" itemscope itemtype="https://schema.org/AggregateRating">
                <span itemprop="ratingValue">{{ratingValue}}</span> / 5
                (<span itemprop="reviewCount">{{ratingCount}}</span> Bewertungen)
            </dd>
            {{/rating}}
            {{#author}}
            <dt>Autor</dt>
            <dd itemprop="author">{{author}}</dd>
            {{/author}}
        </dl>

        <meta itemprop="datePublished" content="{{datePublished}}">
        <meta itemprop="dateModified" content="{{dateModified}}">

        <h2>Zutaten</h2>
        <ul>
            {{ingredients}}
        </ul>

        <h2>Zubereitung</h2>
        <ol>
            {{steps}}
        </ol>
    </article>
</body>
</html>
