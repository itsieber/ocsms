# TODO: SMS-Versand von Nextcloud via Android-Gerät

## Übersicht

Die Web-UI kann bereits SMS über `/front-api/v1/send` in eine Sende-Queue schreiben
(`ocsms_sendmess_queue`). Die Android-App soll diese Queue beim Sync abholen und die
SMS über das Telefon versenden. Die Server-Seite ist dafür noch nicht vollständig
implementiert.

## Ist-Zustand

| Komponente | Status |
|---|---|
| Web-UI: Nachricht schreiben & absenden | ✅ funktioniert (`conversation.js` → `POST /front-api/v1/send`) |
| `SmsController::sendMessage()` | ✅ schreibt in `ocsms_sendmess_queue` |
| `SendQueueMapper` | ✅ CRUD auf `ocsms_sendmess_queue` vorhanden |
| DB-Migration für `ocsms_sendmess_queue` | ✅ Tabelle wird erstellt |
| `ApiController::fetchMessagesToSend()` | ✅ implementiert |
| API-Route `/api/v4/messages/sendqueue` | ✅ Route existiert (GET) |
| Endpunkt: Nachricht als gesendet bestätigen | ✅ implementiert (`DELETE /api/v4/messages/sendqueue/{id}`) |
| Endpunkt: Sende-Fehler melden | ❌ **fehlt komplett** |

## Änderungen

### 1. `fetchMessagesToSend()` implementieren

**Datei:** `lib/Controller/ApiController.php` (Zeile 198)

Aktuell:
```php
public function fetchMessagesToSend(): JSONResponse {
    return new JSONResponse(['messages' => []]);
}
```

Soll:
```php
public function fetchMessagesToSend(): JSONResponse {
    $messages = $this->sendQueueMapper->getMessagesForUser($this->userId ?? '');
    return new JSONResponse(['messages' => $messages]);
}
```

### 2. Bestätigungs-Endpunkt hinzufügen

Wenn die Android-App eine SMS erfolgreich versendet hat, muss sie dem Server
mitteilen, dass die Nachricht aus der Queue entfernt werden kann.

**Neue Route in `appinfo/routes.php`:**
```php
['name' => 'api#ack_sent_message', 'url' => '/api/v4/messages/sendqueue/{id}', 'verb' => 'DELETE'],
```

**Neue Methode in `lib/Controller/ApiController.php`:**
```php
/**
 * @NoAdminRequired
 * @NoCSRFRequired
 */
public function ackSentMessage(int $id): JSONResponse {
    $this->sendQueueMapper->deleteMessage($this->userId ?? '', $id);
    return new JSONResponse(['status' => 'ok']);
}
```

### 3. (Optional) Fehler-Endpunkt hinzufügen

Damit die Web-UI dem Benutzer anzeigen kann, wenn eine SMS nicht zugestellt
werden konnte, sollte ein Fehler-Status pro Nachricht gespeichert werden.

**Änderungen:**
- `ocsms_sendmess_queue` um Spalte `status` erweitern (`pending`, `sent`, `failed`)
- `ocsms_sendmess_queue` um Spalte `error_msg` erweitern
- Neuer Endpunkt `POST /api/v4/messages/sendqueue/{id}/failed`
- `SendQueueMapper` um `markFailed($userId, $id, $errorMsg)` erweitern
- Web-UI: Pending-Nachrichten mit Status-Icon anzeigen

### 4. Web-UI: Sende-Status anzeigen

Aktuell zeigt `conversation.js` versendete Nachrichten sofort als "sent (pending)" an,
ohne jemals den tatsächlichen Status zu prüfen.

**Änderungen:**
- Beim Laden einer Konversation den Status aus der Queue abfragen
- Pending-Nachrichten visuell kennzeichnen (z.B. Uhr-Icon)
- Fehlgeschlagene Nachrichten mit Fehler-Icon + Retry-Button anzeigen

## API-Zusammenfassung (Ziel-Zustand)

| Methode | URL | Beschreibung |
|---|---|---|
| `GET` | `/api/v4/messages/sendqueue` | Queue-Nachrichten für den User abholen |
| `DELETE` | `/api/v4/messages/sendqueue/{id}` | Nachricht nach erfolgreichem Versand entfernen |
| `POST` | `/api/v4/messages/sendqueue/{id}/failed` | Sende-Fehler melden (optional) |
