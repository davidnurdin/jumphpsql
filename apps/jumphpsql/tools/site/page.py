"""Writes site/index.html (French, the main page), site/en/index.html, robots.txt and sitemap.xml.
Run: python3 -I apps/jumphpsql/tools/site/page.py apps/jumphpsql   (shots.sh does it)."""
import html
import json
import sys
from pathlib import Path

app = Path(sys.argv[1])
site = app / 'site'
REPO = 'https://github.com/davidnurdin/jumphpsql'
LANGS = {'fr': 'Français', 'en': 'English'}
MAIN = 'fr'

T = {
    'fr': {
        'locale': 'fr_FR',
        'title': 'jumphpsql — client MySQL / MariaDB écrit en PHP, gratuit',
        'description': 'jumphpsql est un client MySQL et MariaDB gratuit écrit en PHP : parcourez les bases, éditez les lignes, '
                       'exécutez du SQL, faites des dumps, à travers un tunnel SSH si besoin. Linux, Windows, macOS.',
        'tagline': 'Le client MySQL / MariaDB écrit en PHP',
        'lead': 'Parcourez vos bases, éditez les lignes, exécutez du SQL et faites vos sauvegardes dans une fenêtre rapide et '
                'légère. Le protocole MySQL est parlé en PHP pur, sans mysqli ni PDO, et la fenêtre ne se bloque jamais.',
        'source': 'Code source sur GitHub',
        'preview': 'Voir l’interface',
        'preview_note': 'L’aperçu dans le navigateur montre l’interface : la connexion à un serveur demande la version de bureau.',
        'features_h': 'Ce qu’il fait',
        'features': [
            ('Connexions enregistrées', 'Hôte, port, utilisateur, base par défaut ; le mot de passe enregistré ou demandé. Chaque connexion est testée avant d’être enregistrée.'),
            ('L’arbre des bases', 'Les bases, leurs tables et leurs vues, avec le nombre de lignes de chaque table.'),
            ('Les lignes d’une table', 'Filtre WHERE, tri en cliquant sur une colonne, pages de 200 lignes. Les valeurs NULL sont bien distinguées.'),
            ('Édition sûre', 'Modifiez les cellules sur place, ajoutez et supprimez des lignes, puis enregistrez tout en une transaction : rien n’est écrit si une requête échoue.'),
            ('Éditeur SQL', 'Coloration, Ctrl+Entrée exécute la requête sous le curseur, F5 tout le script ; les lignes de chaque résultat.'),
            ('La structure', 'Les colonnes d’une table (type, clé, défaut…) et son CREATE TABLE.'),
            ('Dumps et imports', 'Sauvegardez une base ou une table en .sql, rejouez un fichier .sql, avec une barre de progression.'),
            ('Tunnels SSH', 'Passez par un bastion : agent SSH de la session, fichier de clé avec sa phrase de passe, mot de passe, ou votre ~/.ssh/config.'),
            ('Thème sombre', 'Clair ou sombre, d’un clic.'),
        ],
        'shots_h': 'En images',
        'shots': [
            ('data', 'Les lignes d’une table, éditables, avec le filtre et la pagination'),
            ('sql', 'L’éditeur SQL et le résultat d’une requête'),
            ('structure', 'La structure d’une table et son CREATE TABLE'),
            ('dark', 'Le thème sombre'),
        ],
        'tech_h': 'Du PHP, de bout en bout',
        'tech': 'jumphpsql est fait avec <a href="https://jumphp.net" target="_blank" rel="noopener">JumPHP</a> : la fenêtre, les boutons, la grille de données et '
                'l’éditeur sont dessinés par PHP (JumPHP\\Gui, sans HTML). Le client MySQL est écrit en PHP lui aussi, non bloquant : '
                'les requêtes partent en file, les réponses arrivent entre deux images. Compatible MySQL 5.7 / 8.x et MariaDB '
                '(mysql_native_password, caching_sha2_password, client_ed25519).',
        'install_h': 'L’installer',
        'install': 'Clonez le dépôt et lancez l’application avec la commande de JumPHP (tout tourne dans Docker, rien à installer d’autre) :',
        'faq_h': 'Questions fréquentes',
        'faq': [
            ('Est-ce gratuit ?', 'Oui, et son code source est sur GitHub.'),
            ('Quels serveurs sont pris en charge ?', 'MySQL 5.7 et 8.x, et MariaDB, avec les méthodes d’authentification courantes.'),
            ('Mes modifications peuvent-elles casser une table ?', 'Les changements restent en attente jusqu’à « Save changes », puis partent dans une seule transaction, annulée à la première erreur.'),
            ('Puis-je me connecter à un serveur derrière un bastion ?', 'Oui, par un tunnel SSH : agent, clé, mot de passe ou ~/.ssh/config. Les secrets ne passent jamais par la ligne de commande.'),
            ('Fonctionne-t-il dans le navigateur ?', 'L’interface s’y affiche, mais un navigateur ne peut pas ouvrir de connexion TCP vers MySQL : utilisez la version de bureau.'),
        ],
        'footer': 'jumphpsql, fait avec JumPHP.',
        'langs': 'Langue',
    },
    'en': {
        'locale': 'en_US',
        'title': 'jumphpsql — a free MySQL / MariaDB client written in PHP',
        'description': 'jumphpsql is a free MySQL and MariaDB client written in PHP: browse databases, edit rows, run SQL, '
                       'make dumps, through an SSH tunnel if needed. Linux, Windows, macOS.',
        'tagline': 'The MySQL / MariaDB client written in PHP',
        'lead': 'Browse your databases, edit rows, run SQL and back up your data in a fast, light window. The MySQL protocol '
                'is spoken in pure PHP, without mysqli or PDO, and the window never freezes.',
        'source': 'Source code on GitHub',
        'preview': 'See the interface',
        'preview_note': 'The browser preview shows the interface: connecting to a server needs the desktop version.',
        'features_h': 'What it does',
        'features': [
            ('Saved connections', 'Host, port, user, default database; the password saved or asked for. Each connection is tested before it is saved.'),
            ('The database tree', 'Databases, their tables and views, with the row count of each table.'),
            ('The rows of a table', 'WHERE filter, sort by clicking a column, pages of 200 rows. NULL values clearly shown.'),
            ('Safe editing', 'Edit cells in place, add and delete rows, then save everything in one transaction: nothing is written if one statement fails.'),
            ('SQL editor', 'Syntax colours, Ctrl+Return runs the statement under the cursor, F5 the whole script; the rows of each result.'),
            ('The structure', 'The columns of a table (type, key, default…) and its CREATE TABLE.'),
            ('Dumps and imports', 'Save a database or a table as .sql, run a .sql file, with a progress bar.'),
            ('SSH tunnels', 'Go through a bastion: the session’s SSH agent, a key file with its passphrase, a password, or your ~/.ssh/config.'),
            ('Dark theme', 'Light or dark, in one click.'),
        ],
        'shots_h': 'Screenshots',
        'shots': [
            ('data', 'The rows of a table, editable, with the filter and the pages'),
            ('sql', 'The SQL editor and the result of a query'),
            ('structure', 'The structure of a table and its CREATE TABLE'),
            ('dark', 'The dark theme'),
        ],
        'tech_h': 'PHP, all the way down',
        'tech': 'jumphpsql is made with <a href="https://jumphp.net" target="_blank" rel="noopener">JumPHP</a>: the window, the buttons, the data grid and the '
                'editor are drawn by PHP (JumPHP\\Gui, no HTML). The MySQL client is written in PHP too, non-blocking: queries are '
                'queued, answers arrive between two frames. Works with MySQL 5.7 / 8.x and MariaDB (mysql_native_password, '
                'caching_sha2_password, client_ed25519).',
        'install_h': 'Install it',
        'install': 'Clone the repository and start the app with JumPHP’s command (everything runs in Docker, nothing else to install):',
        'faq_h': 'FAQ',
        'faq': [
            ('Is it free?', 'Yes, and its source code is on GitHub.'),
            ('Which servers are supported?', 'MySQL 5.7 and 8.x, and MariaDB, with the usual authentication methods.'),
            ('Can my edits break a table?', 'Changes wait until “Save changes”, then go in a single transaction, rolled back at the first error.'),
            ('Can I reach a server behind a bastion?', 'Yes, through an SSH tunnel: agent, key, password or ~/.ssh/config. Secrets never go on the command line.'),
            ('Does it run in the browser?', 'The interface shows there, but a browser cannot open a TCP connection to MySQL: use the desktop version.'),
        ],
        'footer': 'jumphpsql, made with JumPHP.',
        'langs': 'Language',
    },
}


def page(lang: str) -> str:
    t, e = T[lang], html.escape
    main = lang == MAIN
    up = '' if main else '../'
    url = 'https://{{DOMAIN}}/' + ('' if main else f'{lang}/')
    alternates = '\n'.join(
        f'  <link rel="alternate" hreflang="{code}" href="https://{{{{DOMAIN}}}}/{"" if code == MAIN else code + "/"}">'
        for code in LANGS) + '\n  <link rel="alternate" hreflang="x-default" href="https://{{DOMAIN}}/">'
    menu = ' · '.join(
        f'<span aria-current="page">{name}</span>' if code == lang
        else f'<a href="{up}{"" if code == MAIN else code + "/"}" hreflang="{code}" lang="{code}">{name}</a>'
        for code, name in LANGS.items())
    ld = [{
        '@context': 'https://schema.org', '@type': 'SoftwareApplication', 'name': 'jumphpsql',
        'description': t['description'], 'applicationCategory': 'DeveloperApplication',
        'operatingSystem': 'Linux, Windows, macOS', 'url': url, 'image': 'https://{{DOMAIN}}/images/og.png',
        'screenshot': [f'https://{{{{DOMAIN}}}}/images/{n}.webp' for n, _ in t['shots']], 'inLanguage': lang,
        'isAccessibleForFree': True, 'offers': {'@type': 'Offer', 'price': '0', 'priceCurrency': 'EUR'},
        'codeRepository': REPO, 'softwareVersion': '0.1.0',
    }, {
        '@context': 'https://schema.org', '@type': 'FAQPage',
        'mainEntity': [{'@type': 'Question', 'name': q, 'acceptedAnswer': {'@type': 'Answer', 'text': a}} for q, a in t['faq']],
    }]
    features = '\n'.join(f'      <li><h3>{e(h)}</h3><p>{e(p)}</p></li>' for h, p in t['features'])
    shots = '\n'.join(
        f'      <figure><img src="{up}images/{n}.webp" alt="{e(c)}" width="1400" height="957" loading="lazy">'
        f'<figcaption>{e(c)}</figcaption></figure>' for n, c in t['shots'])
    faq = '\n'.join(f'      <details><summary>{e(q)}</summary><p>{e(a)}</p></details>' for q, a in t['faq'])
    return f'''<!doctype html>
<html lang="{lang}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{e(t['title'])}</title>
  <meta name="description" content="{e(t['description'])}">
  <link rel="canonical" href="{url}">
{alternates}
  <link rel="icon" type="image/png" href="{up}images/favicon.png">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="jumphpsql">
  <meta property="og:title" content="{e(t['title'])}">
  <meta property="og:description" content="{e(t['description'])}">
  <meta property="og:url" content="{url}">
  <meta property="og:image" content="https://{{{{DOMAIN}}}}/images/og.png">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta property="og:locale" content="{t['locale']}">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="{e(t['title'])}">
  <meta name="twitter:description" content="{e(t['description'])}">
  <meta name="twitter:image" content="https://{{{{DOMAIN}}}}/images/og.png">
  <meta name="theme-color" content="#2c2578">
  <script type="application/ld+json">{json.dumps(ld, ensure_ascii=False)}</script>
  <style>
    :root {{ --ink: #1d1f33; --muted: #5b5f7a; --bg: #f3f4f7; --card: #fff; --brand: #4f5bd5; --deep: #2c2578; --orange: #ff8c28; }}
    * {{ box-sizing: border-box; }}
    body {{ margin: 0; font: 17px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--ink); background: var(--bg); }}
    a {{ color: var(--brand); }}
    .wrap {{ max-width: 1100px; margin: 0 auto; padding: 0 16px; }}
    header.hero {{ background: linear-gradient(180deg, #232060, #15133a); color: #fff; padding: 20px 0 56px; }}
    nav {{ display: flex; justify-content: space-between; align-items: center; gap: 12px; font-size: 15px; }}
    nav .brand {{ display: flex; align-items: center; gap: 10px; color: #fff; text-decoration: none; font-weight: 700; }}
    nav .langs {{ color: #c4c8f0; }} nav .langs a {{ color: #fff; }}
    .intro {{ display: grid; grid-template-columns: 1fr; gap: 32px; align-items: center; margin-top: 40px; }}
    @media (min-width: 860px) {{ .intro {{ grid-template-columns: 5fr 7fr; }} }}
    h1 {{ font-size: clamp(38px, 7vw, 60px); line-height: 1.05; margin: 16px 0 8px; }}
    .tagline {{ color: var(--orange); font-size: 22px; font-weight: 600; margin: 0 0 16px; }}
    .lead {{ color: #d6d9f6; margin: 0 0 24px; }}
    .cta {{ display: flex; flex-wrap: wrap; gap: 12px; }}
    .btn {{ display: inline-block; padding: 12px 22px; border-radius: 10px; font-weight: 700; text-decoration: none; }}
    .btn.primary {{ background: var(--orange); color: #fff; }}
    .btn.ghost {{ border: 2px solid #8f96e8; color: #fff; }}
    .note {{ color: #a7acdb; font-size: 14px; margin-top: 12px; }}
    .intro img.hero-shot {{ width: 100%; height: auto; border-radius: 12px; box-shadow: 0 20px 50px rgba(0,0,0,.45); }}
    section {{ padding: 56px 0 8px; }}
    h2 {{ font-size: 30px; margin: 0 0 24px; }}
    ul.features {{ list-style: none; padding: 0; margin: 0; display: grid; gap: 16px; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); }}
    ul.features li {{ background: var(--card); border-radius: 12px; padding: 18px 20px; box-shadow: 0 1px 3px rgba(20,20,60,.08); }}
    ul.features h3 {{ margin: 0 0 6px; font-size: 18px; color: var(--deep); }}
    ul.features p {{ margin: 0; color: var(--muted); font-size: 15px; }}
    .gallery {{ display: grid; gap: 24px; grid-template-columns: 1fr; }}
    @media (min-width: 860px) {{ .gallery {{ grid-template-columns: 1fr 1fr; }} }}
    figure {{ margin: 0; }}
    figure img {{ width: 100%; height: auto; border-radius: 10px; box-shadow: 0 6px 20px rgba(20,20,60,.15); }}
    figcaption {{ color: var(--muted); font-size: 15px; margin-top: 8px; }}
    pre {{ background: #15133a; color: #e8e9ff; padding: 16px; border-radius: 10px; overflow-x: auto; font-size: 15px; }}
    details {{ background: var(--card); border-radius: 10px; padding: 14px 18px; margin-bottom: 10px; }}
    summary {{ font-weight: 700; cursor: pointer; }}
    details p {{ margin: 8px 0 0; color: var(--muted); }}
    .again {{ text-align: center; margin: 40px 0 0; }}
    footer {{ margin-top: 56px; padding: 24px 0; color: var(--muted); font-size: 14px; border-top: 1px solid #dfe1ea; }}
  </style>
</head>
<body>
  <header class="hero">
    <div class="wrap">
      <nav>
        <a class="brand" href="{up or './'}"><img src="{up}images/favicon.png" alt="" width="28" height="28">jumphpsql</a>
        <span class="langs" aria-label="{e(t['langs'])}">{menu}</span>
      </nav>
      <div class="intro">
        <div>
          <img src="{up}images/icon.png" alt="jumphpsql" width="96" height="96">
          <h1>jumphpsql</h1>
          <p class="tagline">{e(t['tagline'])}</p>
          <p class="lead">{e(t['lead'])}</p>
          <div class="cta">
            <a class="btn primary" href="{REPO}" target="_blank" rel="noopener">{e(t['source'])}</a>
            <a class="btn ghost" href="{up}play/">▶ {e(t['preview'])}</a>
          </div>
          <p class="note">{e(t['preview_note'])}</p>
        </div>
        <img class="hero-shot" src="{up}images/data.webp" alt="{e(t['shots'][0][1])}" width="1400" height="957">
      </div>
    </div>
  </header>
  <main class="wrap">
    <section>
      <h2>{e(t['features_h'])}</h2>
      <ul class="features">
{features}
      </ul>
    </section>
    <section>
      <h2>{e(t['shots_h'])}</h2>
      <div class="gallery">
{shots}
      </div>
    </section>
    <section>
      <h2>{e(t['tech_h'])}</h2>
      <p>{t['tech']}</p>
    </section>
    <section>
      <h2>{e(t['install_h'])}</h2>
      <p>{e(t['install'])}</p>
      <pre><code>git clone {REPO}.git
cd jumphpsql
bin/jumphp run jumphpsql</code></pre>
    </section>
    <section>
      <h2>{e(t['faq_h'])}</h2>
{faq}
      <p class="again"><a class="btn primary" href="{REPO}" target="_blank" rel="noopener">{e(t['source'])}</a></p>
    </section>
  </main>
  <footer><div class="wrap">{e(t['footer'])} · <a href="{REPO}" target="_blank" rel="noopener">GitHub</a> · <a href="{up}play/">{e(t['preview'])}</a></div></footer>
</body>
</html>
'''


for code in LANGS:
    target = site / 'index.html' if code == MAIN else site / code / 'index.html'
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(page(code), encoding='utf-8')
(site / 'robots.txt').write_text('User-agent: *\nAllow: /\nSitemap: https://{{DOMAIN}}/sitemap.xml\n')
links = ''.join(f'\n    <xhtml:link rel="alternate" hreflang="{c}" href="https://{{{{DOMAIN}}}}/{"" if c == MAIN else c + "/"}"/>' for c in LANGS)
urls = ''.join(f'\n  <url>\n    <loc>https://{{{{DOMAIN}}}}/{"" if c == MAIN else c + "/"}</loc>{links}\n  </url>' for c in LANGS)
(site / 'sitemap.xml').write_text('<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" '
                                  f'xmlns:xhtml="http://www.w3.org/1999/xhtml">{urls}\n</urlset>\n')
