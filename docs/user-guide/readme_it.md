# sattle.me per PrestaShop: guida all'uso

Il modulo sattle.me permette ai vostri clienti di pagare i loro ordini in bitcoin, con Lightning o on-chain. I bitcoin vanno direttamente nel vostro portafoglio sattle.me: il negozio crea e verifica da sé le fatture, e la chiave che conserva può creare fatture che vi pagano, mai spendere i vostri fondi.

## Prima di cominciare

- PrestaShop 9.0, 9.1 o 9.2, su PHP 8.2 o successivo (64 bit). Il modulo verifica il server all'installazione, e dice cosa manca, se manca qualcosa.
- Un account commerciante di sattle.me, e la sua app.
- Il cron del sistema sul server del negozio, che chiami l'indirizzo del modulo ogni minuto (vedi «Verifiche in background»).
- Connessioni in uscita dal server del negozio: verso i relay del commerciante, il suo indirizzo Lightning, le fonti dei tassi di cambio, un block explorer (o il vostro nodo Bitcoin, se ne indicate uno nell'app sattle.me) e, per una transazione che nessuna verifica ha visto in tempo, un secondo servizio della blockchain. Il browser del cliente parla solo con il negozio.

## Installazione

1. Scaricate `sattleme.zip` dall'ultima versione del modulo su GitHub: il suo repository è sattleme/checkout-prestashop, sezione **Releases**. Il file `SHA256SUMS`, accanto, permette di verificare il download.
2. Nel back office, aprite **Moduli**, poi la **Gestione moduli**, e caricate `sattleme.zip`. PrestaShop installa il modulo.

Per aggiornare il modulo, caricate la sua nuova versione allo stesso modo. Disinstallato, il modulo rimuove le sue impostazioni e la sua chiave; conserva le registrazioni dei suoi pagamenti e i suoi stati d'ordine, a cui i vostri ordini fanno riferimento.

## Configurazione

1. Nell'app di sattle.me, create un'integrazione per il vostro negozio, e copiate la sua chiave segreta (inizia con `sattleme_ck_`).
2. Nel back office, trovate **sattle.me** nella gestione moduli, e cliccate su **Configura**.
3. Incollate la chiave in **Chiave di integrazione**, e salvate. Il modulo la verifica subito, e rifiuta una chiave che non può usare. Una volta salvata, la chiave non viene più mostrata: lasciate il campo vuoto per conservarla.
4. Attivate **Accetta pagamenti in bitcoin**.
5. **Tempo per pagare**: quanto tempo ha un cliente per pagare una fattura, da 1 a 60 minuti (15 per impostazione predefinita).
6. Se volete, cambiate il **Titolo al pagamento** e la **Descrizione al pagamento**, in ciascuna lingua del negozio.
7. Configurate il cron con la riga che mostra la pagina delle impostazioni (vedi «Verifiche in background»).

Valgono anche le restrizioni di PrestaShop per un metodo di pagamento (**Pagamento**, poi **Preferenze**): valute, paesi, gruppi di clienti e corrieri. Usate una chiave di integrazione per un solo negozio, e cambiatela solo quando nessun pagamento in bitcoin è in sospeso. sattle.me non ha una rete di test: ogni pagamento è un vero pagamento in bitcoin, verso il vostro portafoglio.

## Pagamenti e ordini

1. Al momento del pagamento, il cliente sceglie **Paga con bitcoin**. Quando invia l'ordine, il modulo ottiene prima la fattura; solo allora PrestaShop crea l'ordine, nello stato **In attesa del pagamento bitcoin**. Se la fattura non può essere creata, non viene creato alcun ordine, e il cliente può scegliere un altro metodo.
2. Il cliente arriva sulla pagina di pagamento del negozio: un codice QR, un pulsante che apre il suo portafoglio, la fattura Lightning e l'indirizzo da copiare, l'importo in sats, in bitcoin e nella valuta dell'ordine, e il tempo che resta per pagare.
3. La pagina segue il pagamento da sola. Un pagamento on-chain mette l'ordine nello stato **Pagamento bitcoin in conferma** finché non ha 3 conferme (circa 30 minuti).
4. Una volta completo il pagamento, il modulo lo registra e l'ordine passa a **Pagamento accettato**: PrestaShop invia la sua e-mail e crea la sua fattura, come per ogni pagamento. Un pagamento on-chain inferiore all'importo è completo anche quando la vostra tolleranza lo accetta, o quando accettate nell'app di sattle.me quanto l'ordine ha ricevuto: una nota sull'ordine dice cosa è arrivato.

Se l'ordine è cambiato dopo la creazione della sua fattura (il suo totale, la sua valuta, un altro pagamento o una nota di credito), o se è stato rimborsato nel frattempo, un pagamento che arriva comunque viene registrato, e l'ordine passa a **Pagamento bitcoin da verificare**, mai a «Pagamento accettato». Una nota sull'ordine indica i sats ricevuti, il prezzo della fattura e quanto l'ordine deve ora. Le pagine del cliente dicono che il pagamento è stato ricevuto e che il negozio lo contatterà. Regolate la differenza con il cliente, poi cambiate voi stessi lo stato dell'ordine: ripristinate l'ordine, o rimborsate il cliente.

Una fattura che scade senza pagamento lascia l'ordine in attesa di pagamento, e la sua pagina di pagamento offre al cliente una nuova fattura. Il modulo non annulla mai un ordine: annullate voi stessi un ordine non pagato una volta che la sua fattura è terminata senza pagamento, come per un pagamento con bonifico. Finché la sua fattura può ancora essere pagata o il suo pagamento è in corso di conferma, e non appena sono arrivati bitcoin per esso (pagato, o meno dell'importo, che ha raggiunto il vostro wallet), il modulo rifiuta di annullare l'ordine (gli stati **Annullato** e **Errore di pagamento**), e il back office dice perché: regolate ciò che è arrivato con il cliente, rimborsatelo dal vostro wallet, poi registrate il rimborso (una nota di credito, lo stato **Rimborsato**).

La pagina dell'ordine nel back office mostra ogni tentativo di pagamento: il suo riferimento, l'importo e il tasso di cambio, come è stato pagato, l'indirizzo e le transazioni (come testo da copiare), la prova di pagamento, l'ultima verifica e la successiva, e il suo errore in parole semplici.

## Verifiche in background

Il modulo verifica le fatture dal server del negozio. Finché la pagina di pagamento di un cliente è aperta, verifica spesso; per il resto, il cron del sistema chiama l'indirizzo del modulo ogni minuto, con il token segreto del negozio. La pagina delle impostazioni mostra questo indirizzo e una riga pronta per la crontab dell'utente del server web. In un'installazione multinegozio, la pagina elenca un indirizzo per ogni negozio: il cron li chiama tutti.

La pagina delle impostazioni mostra quando le verifiche sono state eseguite l'ultima volta, e vi avvisa se è stato più di 10 minuti fa. Senza il cron, i pagamenti vengono verificati solo finché la pagina del cliente, o la pagina dell'ordine nel back office, è aperta.

## Rimborsi

I rimborsi non sono mai automatici: un pagamento Lightning può essere rimborsato solo verso una fattura del cliente, uno on-chain verso un suo indirizzo, che il negozio non chiede mai. Chiedetene uno al cliente, rimborsatelo dal vostro portafoglio, poi registrate il rimborso in PrestaShop (una nota di credito, lo stato dell'ordine).

## Dati personali

- Il modulo conserva, per ogni tentativo di pagamento: il suo riferimento, il carrello e l'ordine, l'importo e la valuta, la fattura (importo in sats, tasso di cambio, fattura Lightning, indirizzo di deposito) e ciò che le sue verifiche hanno trovato. Né nome né indirizzo e-mail: quelli del cliente sono quelli di PrestaShop.
- Il server del negozio invia ogni fattura ai relay del commerciante, cifrata per il commerciante, con il nome del negozio e il riferimento dell'ordine; chiede la fattura all'indirizzo Lightning del commerciante, il prezzo del bitcoin alle fonti dei tassi di cambio, e l'indirizzo e le transazioni di una fattura on-chain a un block explorer: di default uno pubblico, o il vostro nodo Bitcoin se ne indicate uno nell'app sattle.me (con quello pubblico come riserva, salvo se non ne volete nessuno). Ognuno di loro vede il server del negozio, mai il cliente.
- Con il modulo GDPR di PrestaShop, l'esportazione di un cliente contiene i suoi pagamenti in bitcoin, e la sua cancellazione ne rimuove gli indirizzi di deposito e le transazioni; riferimenti, importi, tassi, date e stati restano, per la vostra contabilità.

## Se qualcosa va storto

I log di PrestaShop (**Parametri avanzati**, poi **Log**, messaggi che iniziano con `sattle.me:`) dicono cosa è andato storto, mai con la chiave. La pagina dell'ordine nel back office mostra l'ultima verifica di ogni pagamento e il suo errore. Un ordine nello stato **Pagamento bitcoin da verificare** vi aspetta: leggete la sua nota, regolate con il cliente, poi cambiate il suo stato.
