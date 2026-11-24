# sattle.me für PrestaShop: Benutzerhandbuch

Mit dem Modul sattle.me bezahlen Ihre Kunden ihre Bestellungen in Bitcoin, über Lightning oder On-Chain. Die Bitcoin gehen direkt in Ihr sattle.me-Wallet: Der Shop erstellt und prüft die Rechnungen selbst, und der Schlüssel, den er hält, kann Rechnungen erstellen, die Sie bezahlen, nie Ihre Mittel ausgeben.

## Bevor Sie beginnen

- PrestaShop 9.0, 9.1 oder 9.2, auf PHP 8.2 oder neuer (64 Bit). Das Modul prüft den Server bei der Installation und sagt, was gegebenenfalls fehlt.
- Ein Händlerkonto von sattle.me und dessen App.
- Den Cron des Systems auf dem Server des Shops, der die Adresse des Moduls jede Minute aufruft (siehe «Prüfungen im Hintergrund»).
- Ausgehende Verbindungen vom Server des Shops: zu den Relays des Händlers, seiner Lightning-Adresse, den Quellen der Wechselkurse, einem Block-Explorer (oder Ihrem eigenen Bitcoin-Node, wenn Sie in der sattle.me-App einen angeben) und, für eine Transaktion, die keine Prüfung rechtzeitig gesehen hat, einem zweiten Blockchain-Dienst. Der Browser des Kunden spricht nur mit dem Shop.

## Installation

1. Beziehen Sie das Modul auf PrestaShop Addons: Seine Datei, `sattleme.zip`, liegt dann in Ihrem Addons-Konto.
2. Öffnen Sie im Backoffice **Module**, dann den **Modul-Manager**, und laden Sie `sattleme.zip` hoch. PrestaShop installiert das Modul.

Um das Modul zu aktualisieren, laden Sie seine neue Version auf dieselbe Weise hoch. Deinstalliert, entfernt das Modul seine Einstellungen und seinen Schlüssel; es behält die Aufzeichnungen seiner Zahlungen und seine Bestellstatus, auf die sich Ihre Bestellungen beziehen.

## Einrichtung

1. Erstellen Sie in der App von sattle.me eine Integration für Ihren Shop, und kopieren Sie ihren geheimen Schlüssel (er beginnt mit `sattleme_ck_`).
2. Suchen Sie im Backoffice **sattle.me** im Modul-Manager, und klicken Sie auf **Konfigurieren**.
3. Fügen Sie den Schlüssel unter **Integrationsschlüssel** ein, und speichern Sie. Das Modul prüft ihn sofort und lehnt einen Schlüssel ab, den es nicht verwenden kann. Einmal gespeichert, wird der Schlüssel nie wieder angezeigt: Lassen Sie das Feld leer, um ihn zu behalten.
4. Schalten Sie **Bitcoin-Zahlungen akzeptieren** ein.
5. **Zahlungsfrist**: wie lange ein Kunde Zeit hat, eine Rechnung zu bezahlen, 1 bis 60 Minuten (standardmässig 15).
6. Ändern Sie nach Wunsch den **Titel an der Kasse** und die **Beschreibung an der Kasse**, in jeder Sprache des Shops.
7. Richten Sie den Cron mit der Zeile ein, die die Einstellungsseite zeigt (siehe «Prüfungen im Hintergrund»).

Die Einschränkungen von PrestaShop für eine Zahlungsart gelten ebenfalls (**Zahlung**, dann **Einstellungen**): Währungen, Länder, Kundengruppen und Versanddienste. Verwenden Sie einen Integrationsschlüssel für einen einzigen Shop, und ändern Sie ihn nur, solange keine Bitcoin-Zahlung aussteht. sattle.me hat kein Testnetz: Jede Zahlung ist eine echte Bitcoin-Zahlung an Ihr Wallet.

## Zahlungen und Bestellungen

1. An der Kasse wählt der Kunde **Mit Bitcoin bezahlen**. Wenn er die Bestellung aufgibt, holt das Modul zuerst die Rechnung; erst dann erstellt PrestaShop die Bestellung, im Status **Warten auf Bitcoin-Zahlung**. Kann die Rechnung nicht erstellt werden, entsteht keine Bestellung, und der Kunde kann eine andere Zahlungsart wählen.
2. Der Kunde gelangt auf die Zahlungsseite des Shops: ein QR-Code, eine Schaltfläche, die sein Wallet öffnet, die Lightning-Rechnung und die Adresse zum Kopieren, der Betrag in Sats, in Bitcoin und in der Währung der Bestellung, und die verbleibende Zeit zum Bezahlen.
3. Die Seite verfolgt die Zahlung von selbst. Eine On-Chain-Zahlung setzt die Bestellung auf **Bitcoin-Zahlung wird bestätigt**, bis sie 3 Bestätigungen hat (etwa 30 Minuten).
4. Sobald die Zahlung vollständig ist, erfasst das Modul sie, und die Bestellung geht auf **Zahlung eingegangen**: PrestaShop sendet seine E-Mail und erstellt seine Rechnung, wie bei jeder Zahlung. Eine On-Chain-Zahlung unter dem Betrag ist ebenfalls vollständig, wenn Ihre Toleranz sie akzeptiert oder wenn Sie in der App von sattle.me annehmen, was die Bestellung erhalten hat: Eine Notiz an der Bestellung nennt, was eingegangen ist.

Hat sich die Bestellung geändert, nachdem ihre Rechnung erstellt wurde (ihr Total, ihre Währung, eine andere Zahlung oder eine Gutschrift), oder wurde sie inzwischen erstattet, so wird eine Zahlung, die trotzdem eingeht, erfasst, und die Bestellung geht auf **Bitcoin-Zahlung zu prüfen**, nie auf «Zahlung eingegangen». Eine Notiz an der Bestellung nennt die erhaltenen Sats, den Preis der Rechnung und was die Bestellung jetzt schuldet. Die Seiten des Kunden sagen, dass die Zahlung eingegangen ist und der Shop sich bei ihm meldet. Regeln Sie die Differenz mit dem Kunden, und setzen Sie dann den Status der Bestellung selbst: Stellen Sie die Bestellung wieder her, oder erstatten Sie dem Kunden den Betrag.

Eine Rechnung, die unbezahlt endet, lässt die Bestellung auf die Zahlung warten, und ihre Zahlungsseite bietet dem Kunden eine neue Rechnung an. Das Modul storniert nie eine Bestellung: Stornieren Sie eine unbezahlte Bestellung selbst, sobald ihre Rechnung unbezahlt abgelaufen ist, wie bei einer Zahlung per Überweisung. Solange ihre Rechnung noch bezahlt werden kann oder ihre Zahlung bestätigt wird, und sobald Bitcoin für sie eingegangen sind (bezahlt, oder weniger als der Betrag, der in Ihrer Wallet angekommen ist), verweigert das Modul die Stornierung der Bestellung (die Status **Bestellung storniert** und **Fehler bei der Bezahlung**), und das Backoffice sagt warum: Regeln Sie das Eingegangene mit dem Kunden, erstatten Sie den Betrag aus Ihrer Wallet und erfassen Sie die Erstattung anschliessend (eine Gutschrift, der Status **Erstattet**).

Die Seite der Bestellung im Backoffice zeigt jeden Zahlungsversuch: seine Referenz, den Betrag und den Wechselkurs, wie er bezahlt wurde, die Adresse und die Transaktionen (als Text zum Kopieren), den Zahlungsnachweis, die letzte Prüfung und die nächste, und seinen Fehler in einfachen Worten.

## Prüfungen im Hintergrund

Das Modul prüft die Rechnungen vom Server des Shops aus. Solange die Zahlungsseite eines Kunden offen ist, prüft es häufig; für den Rest ruft der Cron des Systems die Adresse des Moduls jede Minute auf, mit dem geheimen Token des Shops. Die Einstellungsseite zeigt diese Adresse und eine fertige Zeile für die Crontab des Benutzers des Webservers. In einer Multistore-Installation listet die Seite eine Adresse für jeden Shop auf: Der Cron ruft jede davon auf.

Die Einstellungsseite zeigt, wann die Prüfungen zuletzt liefen, und warnt Sie, wenn das mehr als 10 Minuten her ist. Ohne den Cron werden Zahlungen nur geprüft, solange die Seite des Kunden oder die Seite der Bestellung im Backoffice offen ist.

## Rückerstattungen

Rückerstattungen erfolgen nie automatisch: Eine Lightning-Zahlung kann nur auf eine Rechnung des Kunden erstattet werden, eine On-Chain-Zahlung nur an eine seiner Adressen, nach denen der Shop nie fragt. Bitten Sie den Kunden um eine, erstatten Sie ihm den Betrag aus Ihrem Wallet, und erfassen Sie die Rückerstattung dann in PrestaShop (eine Gutschrift, der Status der Bestellung).

## Datenschutz

- Das Modul speichert für jeden Zahlungsversuch: seine Referenz, den Warenkorb und die Bestellung, den Betrag und die Währung, die Rechnung (Betrag in Sats, Wechselkurs, Lightning-Rechnung, Einzahlungsadresse) und was seine Prüfungen fanden. Kein Name und keine E-Mail-Adresse: Die des Kunden sind die von PrestaShop.
- Der Server des Shops sendet jede Rechnung an die Relays des Händlers, für den Händler verschlüsselt, mit dem Namen des Shops und der Referenz der Bestellung; er fragt die Lightning-Adresse des Händlers nach der Rechnung, die Quellen der Wechselkurse nach dem Bitcoin-Preis und einen Block-Explorer nach der Adresse und den Transaktionen einer On-Chain-Rechnung: standardmässig einen öffentlichen, oder Ihren eigenen Bitcoin-Node, wenn Sie in der sattle.me-App einen angeben (mit dem öffentlichen als Ersatz, ausser Sie wählen keinen). Jeder von ihnen sieht den Server des Shops, nie den Kunden.
- Mit dem DSGVO-Modul von PrestaShop enthält der Export eines Kunden seine Bitcoin-Zahlungen, und seine Löschung entfernt daraus die Einzahlungsadressen und die Transaktionen; Referenzen, Beträge, Kurse, Daten und Status bleiben, für Ihre Buchhaltung.

## Wenn etwas schiefgeht

Die Protokolle von PrestaShop (**Erweiterte Einstellungen**, dann **Logs**, Meldungen, die mit `sattle.me:` beginnen) sagen, was schiefging, nie mit dem Schlüssel. Die Seite der Bestellung im Backoffice zeigt die letzte Prüfung jeder Zahlung und ihren Fehler. Eine Bestellung im Status **Bitcoin-Zahlung zu prüfen** wartet auf Sie: Lesen Sie ihre Notiz, regeln Sie es mit dem Kunden, und setzen Sie dann ihren Status.
