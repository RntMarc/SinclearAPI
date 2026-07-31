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
    <article>
        <header>
            <h1>{{title}}</h1>
            {{#description}}
            <p>{{description}}</p>
            {{/description}}
        </header>

        <img src="{{imageSrc}}" alt="{{imageAlt}}">

        <dl>
            <dt>Kategorie</dt>
            <dd>{{category}}</dd>
            <dt>Portionen</dt>
            <dd>{{servings}}</dd>
            {{#dietaryTags}}
            <dt>Ernährung</dt>
            <dd>{{dietaryTags}}</dd>
            {{/dietaryTags}}
            {{#rating}}
            <dt>Bewertung</dt>
            <dd>{{rating}} ({{ratingCount}} Bewertungen)</dd>
            {{/rating}}
        </dl>

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
