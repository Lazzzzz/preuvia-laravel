# Preuvia pour Laravel

SDK Laravel de l'API Preuvia v1 : modèles, dossiers, documents de preuve, certifications Datasure,
certificats eIDAS, PDF signés, utilisateurs, jetons, et réception des callbacks signés.

- PHP 8.2+, Laravel 11, 12 ou 13
- Les 42 opérations de l'API v1, plus `/health`
- Retries sûrs, exceptions typées, pagination automatique
- Récepteur de webhooks prêt à l'emploi, avec un event Laravel par type de callback
- Helpers de test : `Preuvia::fake()`, `PreuviaFake`, `SignsPreuviaWebhooks`

## Installation

Le package est installé depuis son dépôt Git. Dans le `composer.json` du projet :

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/Lazzzzz/preuvia-laravel" }
]
```

```bash
composer require laz/preuvia-laravel
```

Pour développer le SDK et un projet en même temps, utiliser un dépôt de type `path`, qui crée un lien symbolique :

```json
"repositories": [
    { "type": "path", "url": "../preuvia-laravel" }
]
```

## Configuration

```dotenv
PREUVIA_API_BASE_URL=https://preuvia.eliteinspection.fr/api/v1
PREUVIA_API_KEY=preuvia_...
PREUVIA_WEBHOOK_SECRET=...          # secret de callback de l'application API
PREUVIA_WEBHOOK_PATH=webhooks/preuvia  # facultatif : active le récepteur intégré
```

Les autres réglages ont une valeur par défaut. Pour les modifier, publier la config avec
`php artisan vendor:publish --tag=preuvia-config` :

| Clé | Env | Défaut |
| --- | --- | --- |
| `timeout` | `PREUVIA_API_TIMEOUT` | 30 s |
| `connect_timeout` | `PREUVIA_API_CONNECT_TIMEOUT` | 10 s |
| `retry.times` | `PREUVIA_API_RETRY_TIMES` | 2 |
| `retry.sleep_ms` | `PREUVIA_API_RETRY_SLEEP_MS` | 500 (multiplié par le numéro de tentative) |
| `retry.max_retry_after` | `PREUVIA_API_MAX_RETRY_AFTER` | 10 s |
| `documents.send_hash` | `PREUVIA_DOCUMENTS_SEND_HASH` | `true` |
| `health_token` | `PREUVIA_HEALTH_TOKEN` | — |
| `webhooks.tolerance` | `PREUVIA_WEBHOOK_TOLERANCE` | 300 s |

## Utilisation

```php
use Preuvia\Laravel\Facades\Preuvia;

$template = Preuvia::templates()->list()->data('0');

$dossier = Preuvia::dossiers()->create([
    'template_uuid' => $template['uuid'],
    'name' => 'Visite 2026-10-17',
    'due_at' => now()->addWeek(),
]);

$document = Preuvia::documents()->upload(
    $dossier->data('uuid'),
    storage_path('app/rapports/rapport.pdf'),
    stepIndex: 0,
    proofIndex: 0,
    options: [
        'client_capture_id' => 'rapport-'.$rapport->uuid, // rend l'envoi rejouable sans doublon
        'captured_at' => now(),
        'device' => ['name' => 'Back-office', 'platform' => 'web'],
    ],
);
```

Le client est aussi injectable (`Preuvia\Laravel\Preuvia`). Chaque `with*()` renvoie une copie, sans modifier
le client partagé :

```php
Preuvia::withApiKey($client->preuvia_api_key)->dossiers()->list(['status' => 'active']);
Preuvia::withoutRetries()->dossiers()->show($uuid);
```

### Réponses

Les méthodes JSON renvoient une `PreuviaResponse`. On peut la lire comme le tableau de l'enveloppe complète,
ou passer par ses méthodes :

```php
$response = Preuvia::dossiers()->show($uuid);

$response['data']['status'];          // lecture comme un tableau
$response->data('status');            // data_get sur data
$response->data('steps.0.proofs');
$response->meta('added_steps');
$response->message();
$response->pagination()?->lastPage;   // listes
$response->status();                  // code HTTP
$response->toArray();                 // enveloppe brute
```

### Pagination

`list()` renvoie une page. `lazy()` parcourt toutes les pages (100 éléments par requête) et renvoie une
`LazyCollection` d'éléments :

```php
Preuvia::dossiers()->lazy(['template_uuid' => $uuid])
    ->filter(fn (array $dossier) => $dossier['status'] === 'completed')
    ->each(fn (array $dossier) => ...);
```

### Téléchargements

```php
$pdf = Preuvia::documents()->downloadSignedPdf($dossierUuid, $documentUuid);

$pdf->filename();        // depuis Content-Disposition
$pdf->mimeType();
$pdf->body();
$pdf->save(storage_path('app/certifs/'.$pdf->filename()));
$pdf->store('certifs/rapport.pdf', 's3');
```

### Toutes les opérations

| Ressource | Méthodes |
| --- | --- |
| `Preuvia::` | `health()`, `me()`, `get()`, `post()`, `patch()`, `delete()` (pour un endpoint pas encore couvert) |
| `templates()` | `list`, `lazy` |
| `customTemplates()` | `list`, `lazy`, `create`, `show`, `update`, `publish`, `archive`, `unarchive`, `duplicate` |
| `dossiers()` | `list`, `lazy`, `create`, `show`, `update`, `delete`, `assign($uuid, $userUuid)`, `unassign`, `complete`, `reopen`, `archive`, `unarchive`, `syncTemplate` |
| `documents()` | `list`, `lazy`, `upload`, `show`, `download($d, $doc, preview: true)`, `artifacts`, `certify`, `generateEidasCertificate`, `downloadEidasCertificate`, `signPdf`, `downloadSignedPdf` |
| `certifications()` | `list`, `lazy`, `show` |
| `users()` | `list`, `lazy`, `create`, `show`, `update`, `activate`, `deactivate` |
| `tokens()` | `balance`, `transactions`, `lazyTransactions` |

La référence des champs se trouve sur `/developpeurs` de l'instance Preuvia (OpenAPI : `/developpeurs/openapi.json`).
[`llms.txt`](llms.txt) résume en anglais toutes les signatures publiques du SDK, ses règles et ses helpers de test,
dans un format condensé facile à charger par un outil d'aide au code. Il est livré avec le package
(`vendor/laz/preuvia-laravel/llms.txt`).

### Envoi de documents

`upload()` accepte un chemin, un `SplFileInfo` (dont `UploadedFile`) ou un flux. Par défaut, le SDK calcule le
SHA-256 du fichier et l'envoie dans `client_sha256` : Preuvia refuse un fichier modifié en route
(`capture_hash_mismatch`). On peut le désactiver pour un envoi avec `'client_sha256' => false`.

Avec un `client_capture_id`, un renvoi du même fichier renvoie le document déjà enregistré au lieu d'en créer un
second. C'est pour cette raison que le SDK ne rejoue un upload qu'en présence de cet identifiant.

## Erreurs et retries

Toutes les erreurs étendent `Preuvia\Laravel\Exceptions\PreuviaException` :

| Exception | Cas |
| --- | --- |
| `ConfigurationException` | clé API ou URL absente (aucune requête envoyée) |
| `ConnectionException` | réseau, DNS, timeout |
| `AuthenticationException` | 401 `invalid_api_key`, `api_key_not_valid` |
| `InsufficientTokensException` | 402 `insufficient_tokens` |
| `AuthorizationException` | 403 `missing_api_scope`, `missing_permission`, `forbidden`, `step_role_forbidden` |
| `NotFoundException` | 404 |
| `ConflictException` | 409 `capture_id_conflict`, `template_snapshot_diverged` |
| `ValidationException` | 422 `validation_failed`, `capture_hash_mismatch` |
| `RateLimitException` | 429 |
| `ServerException` | 5xx |
| `RequestException` | autre statut, ou `success: false` |
| `InvalidResponseException` | réponse 2xx qui n'est pas l'enveloppe JSON |
| `FileException` | fichier à envoyer illisible |

```php
try {
    Preuvia::documents()->signPdf($dossierUuid, $documentUuid);
} catch (InsufficientTokensException $e) {
    // recharger les jetons
} catch (PreuviaException $e) {
    $e->status();       // ?int
    $e->errorCode();    // meta.code
    $e->errors();       // erreurs par champ (422)
    $e->retryAfter();   // ?int, en secondes
    $e->isTemporary();  // connexion, 429 ou 5xx : à rejouer plus tard (job)
    throw $e;
}
```

Le SDK rejoue une requête après une erreur de connexion, un 429, 502, 503 ou 504, et seulement si la requête
est sûre : une lecture (GET), ou un upload avec `client_capture_id`. Une création de dossier n'est jamais
rejouée automatiquement. Un `Retry-After` supérieur à `retry.max_retry_after` n'est pas attendu : l'exception
part tout de suite, et un job peut utiliser `$e->retryAfter()` pour fixer son `release()`.

## Webhooks (callbacks)

Preuvia signe chaque callback (`X-Preuvia-Signature: t=…,sha256=…`, HMAC-SHA256 de `"{t}.{corps brut}"`).

### Récepteur intégré

Avec `PREUVIA_WEBHOOK_PATH=webhooks/preuvia`, le package enregistre `POST /webhooks/preuvia`
(nom `preuvia.webhook`). La route est hors du groupe `web`, donc sans session ni CSRF : c'est la signature qui
authentifie. Indiquer cette URL comme URL de callback de l'application API dans Preuvia.

Il suffit ensuite d'écouter les events :

```php
use Preuvia\Laravel\Webhooks\Events\PdfSigned;
use Preuvia\Laravel\Webhooks\Exceptions\RetryWebhookLater;

Event::listen(function (PdfSigned $event) {
    $webhook = $event->webhook;

    $webhook->deliveryId();      // stable entre les tentatives : pour dédoublonner
    $webhook->dossierUuid();
    $webhook->documentUuid();
    $webhook->clientCaptureId();
    $webhook->artifact();        // ['uuid', 'type', 'status', 'filename', ...]
    $webhook->isSandbox();

    if (! $rapport = Rapport::forPreuviaDocument($webhook->documentUuid())) {
        throw new RetryWebhookLater(60); // 503 + Retry-After : Preuvia renverra le callback
    }
});
```

| Event | Callback |
| --- | --- |
| `WebhookReceived` | tous, y compris les events inconnus du SDK |
| `CertificationSucceeded` | `certification.success` |
| `CertificationFailed` | `certification.failed` |
| `CertificationInsufficientTokens` | `certification.insufficient_tokens` |
| `EidasCertificateGenerated` | `certification.eidas_certificate.generated` |
| `EidasCertificateFailed` | `certification.eidas_certificate.failed` |
| `PdfSigned` | `certification.pdf_signed` |
| `PdfSignatureFailed` | `certification.pdf_signature_failed` |

Réponses du récepteur :
- 200 : callback traité.
- 401 : signature absente, invalide, ou horodatage hors tolérance.
- 503 : secret non configuré, ou `RetryWebhookLater`.
- 500 : toute autre exception d'un listener. Preuvia renverra le callback.

Les listeners en file d'attente sont possibles : `PreuviaWebhook` est sérialisable.

### Contrôleur maison

Si on garde son propre contrôleur, il suffit d'ajouter le middleware et de récupérer le webhook vérifié :

```php
use Preuvia\Laravel\Webhooks\Http\VerifyPreuviaWebhook;
use Preuvia\Laravel\Webhooks\PreuviaWebhook;

Route::post('webhooks/preuvia', PreuviaWebhookController::class)
    ->middleware(VerifyPreuviaWebhook::class)
    ->withoutMiddleware(VerifyCsrfToken::class);

// dans le contrôleur
$webhook = $request->attributes->get(PreuviaWebhook::class);
```

Ou à la main : `WebhookSignature::verify($request->getContent(), $request->header('X-Preuvia-Signature'), $secret)`.

## Tests

```php
use Preuvia\Laravel\Facades\Preuvia;
use Preuvia\Laravel\Testing\PreuviaFake;

Preuvia::fake([
    'dossiers' => PreuviaFake::created(['uuid' => 'd-1', 'status' => 'active']),
    'dossiers/*/documents/*/signed-pdf' => PreuviaFake::file('%PDF-1.7', 'application/pdf', 'signe.pdf'),
    'tokens/balance' => PreuviaFake::error('insufficient_tokens', status: 402),
]);

// ... code testé ...

Http::assertSent(fn ($request) => $request->url() === 'https://preuvia.test/api/v1/dossiers');
```

Les clés relatives sont préfixées par `base_url`. Sans argument, `Preuvia::fake()` répond à tout avec une
enveloppe vide réussie. Autres builders : `PreuviaFake::ok()`, `paginated()`, `validation()`, `notFound()`.

Pour simuler un callback signé :

```php
use Preuvia\Laravel\Webhooks\Testing\SignsPreuviaWebhooks;
use Preuvia\Laravel\Webhooks\Testing\PreuviaWebhookPayload;
use Preuvia\Laravel\Webhooks\WebhookEvent;

class PreuviaWebhookTest extends TestCase
{
    use SignsPreuviaWebhooks;

    public function test_pdf_signed(): void
    {
        $this->postPreuviaWebhook('/webhooks/preuvia', WebhookEvent::PdfSigned, PreuviaWebhookPayload::pdfSigned([
            'document' => ['uuid' => 'doc-1'],
        ]))->assertOk();
    }
}
```

## Développement du package

```bash
composer install
vendor/bin/phpunit
vendor/bin/pint
```

Quand l'API Preuvia change, mettre à jour dans le même commit la ressource, `tests/Client/EndpointsTest.php`,
ce README et `llms.txt`, puis augmenter `Preuvia::VERSION`.

Publier une version : `git tag v1.0.0 && git push --tags`.
