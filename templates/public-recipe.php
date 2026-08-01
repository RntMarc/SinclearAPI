<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{title}} – Sinclear Beyond Rezept</title>
    <meta name="description" content="{{description}}">
    <meta name="robots" content="noindex">
    <script async="async" src="//platform.getbring.com/widgets/import.js"></script>
    <script type="application/ld+json">{{jsonLd}}</script>
</head>
<body>
    <article itemscope itemtype="http://schema.org/Recipe">
        <header>
            <h1 itemprop="name">{{title}}</h1>
            {{#description}}
            <p itemprop="description tagline">{{description}}</p>
            {{/description}}
        </header>

        <a href="{{openUrl}}" class="beyond-open-btn" style="display:inline-block;margin:16px 0;padding:14px 28px;background:linear-gradient(135deg,#4b1e6d 0%,#1a2a6c 100%);color:#ffffff;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:17px;font-weight:600;text-decoration:none;border-radius:10px;box-shadow:0 4px 14px rgba(27,20,90,0.35);transition:filter 0.2s ease;" onmouseover="this.style.filter='brightness(1.15)'" onmouseout="this.style.filter=''">
            Rezept in Beyond &ouml;ffnen
        </a>

        <div data-bring-import="" style="display:none"></div>
        <a href="https://www.getbring.com">Bring! Einkaufslisten App f&uuml;r Android und iPhone</a>
        <p>Achtung: Bring verwendet Tracker von Google und Facebook. Benutzung auf eigene Gefahr!</p>

        {{#imageSrc}}
        <img itemprop="image" src="{{imageSrc}}" alt="{{imageAlt}}">
        {{/imageSrc}}

        <dl>
            <dt>Kategorie</dt>
            <dd itemprop="recipeCategory">{{category}}</dd>
            <dt>Portionen</dt>
            <dd itemprop="yield">{{servings}}</dd>
            {{#dietaryTags}}
            <dt>Ernährung</dt>
            <dd itemprop="keywords">{{dietaryTags}}</dd>
            {{/dietaryTags}}
            {{#rating}}
            <dt>Bewertung</dt>
            <dd itemprop="aggregateRating" itemscope itemtype="http://schema.org/AggregateRating">
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
