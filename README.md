# ilbronza/static-site

Generatore di siti statici per Laravel. Usa route e template dell'applicazione
ospitante, senza dipendere da un CMS, modello Eloquent, tema o motore di filtri.
Laravel serve durante la generazione; l'output può essere servito da un normale
hosting di file statici. Il package non effettua upload su server remoti.

## Installazione

Installare con Composer nell'applicazione Laravel:

```sh
composer require ilbronza/static-site:^0.1
```

[Repository GitHub](https://github.com/ilBronza/StaticSite) ·
[Package Composer](https://packagist.org/packages/ilbronza/static-site)

Per lo sviluppo da un checkout locale è possibile usare un repository `path`:

```json
{
  "repositories": [{
    "type": "path", "url": "../static-site",
    "options": {"symlink": true, "versions": {"ilbronza/static-site": "dev-main"}}
  }],
  "require": {"ilbronza/static-site": "dev-main"}
}
```

Laravel scopre `StaticSiteServiceProvider`. La configurazione si pubblica con
`php artisan vendor:publish --tag=static-site.config`.
Requisiti: PHP 8.2+ con DOM, applicazione Laravel completa e componenti Illuminate
12. Non ci sono migration o dipendenze da altri package IlBronza.

## Route e sorgenti

Marcare esclusivamente le route GET pubbliche, tutte con un nome:

```php
use IlBronza\StaticSite\Http\Middleware\Exportable;

Route::middleware(Exportable::class)->group(function () {
    Route::get('/', HomeController::class)->name('home');
    Route::get('/articles/{slug}', ArticleController::class)->name('articles.show');
});
```

In alternativa `static-site.routes` accetta nomi/pattern; `exclude` esclude route
selezionate. L'assenza di middleware auth non è interpretata come prova che una
route sia pubblica. Le route senza parametri sono incluse automaticamente.
Per le altre registrare in `sources` una classe che applichi le regole del sito:

```php
use IlBronza\StaticSite\Contracts\PageSource;
use IlBronza\StaticSite\Page;

final class ArticlePages implements PageSource
{
    public function routes(): array { return ['articles.show']; }

    public function pages(): iterable
    {
        foreach (Article::published()->orderBy('id')->cursor() as $article) {
            yield new Page('articles.show', ['slug' => $article->slug]);
        }
    }
}
```

`routes()` va dichiarato anche se non ci sono record pubblicati. Una route con
parametri priva di sorgente è un errore. Lingue, varianti e paginazioni sono
enumerate dalle sorgenti dell'app. Per una query con un HTML distinto:

```php
yield new Page('articles.index', query: ['page' => 2], path: '/articles/page/2');
```

La sorgente viene passata al kernel Laravel. I collegamenti a quello stato sono
riscritti verso il percorso statico. Percorsi senza estensione diventano directory
con `index.html`; collisioni e collegamenti locali non esportati sono errori.

## Risorse e comportamento durante la generazione

```php
'assets' => [
    ['source' => public_path('assets'), 'destination' => 'assets', 'copy' => 'all'],
    ['source' => storage_path('app/public'), 'destination' => 'storage'],
],
'build_config' => ['filesystems.disks.public.url' => '{url}/storage'],
```

Di default vengono copiate solo risorse referenziate. `copy => all` serve per
asset statici richiamati dal JavaScript: non usarlo per una Media Library che
contenga bozze o file privati. Sono ammessi solo formati statici noti; PHP, file
nascosti e riferimenti esterni alla directory consentita sono esclusi. I
riferimenti CSS `url()` e `@import` vengono risolti ricorsivamente. Risorse esterne
come immagini o script CDN fanno fallire l'export: devono essere locali.

Durante la build `app(ExportContext::class)->building` e l'attributo request
`static-site.building` sono true. L'app può scegliere filtri locali o sostituire un
form di cambio lingua con link. `build_config` applica override temporanei,
ripristinati anche in caso di errore. Il rendering non riceve cookie o sessioni
amministrative. Le regole di autorizzazione dell'app restano in esecuzione.

Ricerca libera e combinazioni di filtri devono funzionare nel browser su dati
pubblici incorporati o file statici. Il package non contiene un motore di filtri.
Le query gestite dal browser vanno consentite per nome della route:

```php
'allowed_query_parameters' => ['articles.index' => ['q', 'sort']],
'query_defaults' => ['articles.index' => ['page' => 1, 'sort' => 'name']],
```

La prima opzione conserva i parametri nei link; `query_defaults` riconosce stati
equivalenti, ad esempio `?page=1`, e permette di riscrivere correttamente i link di
paginazione che includono anche l'ordinamento predefinito. Non convertono un controller dinamico.
Form POST e token CSRF sono rifiutati. I form GET richiedono un comportamento
locale fornito dall'app. Sono controllati HTML, stili e JSON incorporati; il
package non può dimostrare l'assenza di fetch costruite arbitrariamente dal
JavaScript. Verificare anche nel browser su un server di soli file.

## Comandi e distribuzione

```sh
php artisan static-site:routes
php artisan static-site:build --url=https://www.example.com
php artisan static-site:build --output=/srv/exports/site --url=https://www.example.com
```

La URL deve essere un'origine HTTP(S) senza sottocartelle; l'output un percorso
assoluto. I link sono relativi alla radice, la sitemap usa l'origine configurata.
La build prepara una directory temporanea e sostituisce l'export solo alla fine.
Una directory esistente viene sostituita solo se contiene `.static-site.json`.
Un lock impedisce build concorrenti. La rigenerazione completa rimuove contenuti
depubblicati. Sospendere modifiche redazionali e upload durante la generazione:
non viene aperta una transazione globale sui dati.

L'output include sitemap, robots, `404.html`, `.htaccess`, `_redirects` e rapporto
`.static-site.json`. Risposte 301/302/307/308 producono regole Apache/hosting
compatibili con `_redirects`, più HTML di ripiego. Su altri hosting configurare
redirect HTTP e pagina 404. `robots` mantiene di default `Disallow: /`: impostare
la politica di indicizzazione del sito, incluse le meta robots dei template.
Distribuire solo l'export; il backend va in un'altra document root protetta.

## Test

La suite Testbench non usa database e copia il runtime nel package per non
scrivere nel vendor condiviso. Con un vendor di test già disponibile:

```sh
STATIC_SITE_TEST_VENDOR=/path/to/vendor php /path/to/vendor/phpunit/phpunit/phpunit -c phpunit.xml
```

La release 0.1 supporta Laravel 12. La CI verifica PHP 8.2 e 8.4 con Testbench 10.
Laravel 11 non è incluso nella release: le sue dipendenze sono attualmente
bloccate dagli avvisi di sicurezza di Composer. Maclay usa Laravel 12.
I test coprono rendering anonimo, enumerazione delle sorgenti, query, dipendenze
CSS, esclusione dei file privati, collisioni, form, redirect e conservazione
dell'export precedente. I test Maclay coprono anche lingue, varianti e paginazioni.
