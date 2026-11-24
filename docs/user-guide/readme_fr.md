# sattle.me pour PrestaShop : guide d'utilisation

Le module sattle.me permet à vos clients de payer leurs commandes en bitcoin, par Lightning ou on-chain. Les bitcoins vont directement dans votre portefeuille sattle.me : la boutique crée et vérifie elle-même les factures, et la clé qu'elle détient peut créer des factures qui vous paient, jamais dépenser vos fonds.

## Avant de commencer

- PrestaShop 9.0, 9.1 ou 9.2, sur PHP 8.2 ou plus récent (64 bits). Le module vérifie le serveur à son installation, et dit ce qui manque, le cas échéant.
- Un compte marchand sattle.me, et son application.
- Le cron du système sur le serveur de la boutique, pour appeler l'adresse du module chaque minute (voir « Vérifications en arrière-plan »).
- Des connexions sortantes depuis le serveur de la boutique : vers les relais du marchand, son adresse Lightning, les sources de taux de change, un explorateur de blocs (ou votre propre serveur Bitcoin, si vous en indiquez un dans l'app sattle.me) et, pour une transaction qu'aucune vérification n'a vue à temps, un second service de la blockchain. Le navigateur du client ne parle qu'à la boutique.

## Installation

1. Téléchargez `sattleme.zip` depuis la dernière version du module sur GitHub : son dépôt est sattleme/checkout-prestashop, rubrique **Releases**. Le fichier `SHA256SUMS`, à côté, permet de vérifier le téléchargement.
2. Dans le back-office, ouvrez **Modules**, puis le **Gestionnaire de modules**, et envoyez `sattleme.zip`. PrestaShop installe le module.

Pour mettre le module à jour, envoyez sa nouvelle version de la même façon. Désinstallé, le module supprime ses réglages et sa clé ; il garde les enregistrements de ses paiements et ses états de commande, auxquels vos commandes se réfèrent.

## Configuration

1. Dans l'application sattle.me, créez une intégration pour votre boutique, et copiez sa clé secrète (elle commence par `sattleme_ck_`).
2. Dans le back-office, trouvez **sattle.me** dans le gestionnaire de modules, et cliquez sur **Configurer**.
3. Collez la clé dans **Clé d'intégration**, et enregistrez. Le module la vérifie aussitôt, et refuse une clé qu'il ne peut pas utiliser. Une fois enregistrée, la clé n'est plus jamais affichée : laissez le champ vide pour la garder.
4. Activez **Accepter les paiements en bitcoin**.
5. **Délai de paiement** : le temps dont dispose un client pour payer une facture, de 1 à 60 minutes (15 par défaut).
6. Si vous le souhaitez, modifiez le **Titre au paiement** et la **Description au paiement**, dans chacune des langues de la boutique.
7. Configurez le cron avec la ligne qu'affiche la page des réglages (voir « Vérifications en arrière-plan »).

Les restrictions de PrestaShop pour un moyen de paiement s'appliquent aussi (**Paiement**, puis **Préférences**) : devises, pays, groupes de clients et transporteurs. Utilisez une clé d'intégration pour une seule boutique, et ne la changez que lorsqu'aucun paiement en bitcoin n'est en attente. sattle.me n'a pas de réseau de test : chaque paiement est un vrai paiement en bitcoin, vers votre portefeuille.

## Paiements et commandes

1. Au moment de payer, le client choisit **Payer en bitcoin**. Quand il passe la commande, le module obtient d'abord la facture ; alors seulement PrestaShop crée la commande, dans l'état **En attente du paiement bitcoin**. Si la facture ne peut pas être créée, aucune commande n'est créée, et le client peut choisir un autre moyen.
2. Le client arrive sur la page de paiement de la boutique : un code QR, un bouton qui ouvre son portefeuille, la facture Lightning et l'adresse à copier, le montant en sats, en bitcoin et dans la devise de la commande, et le temps restant pour payer.
3. La page suit le paiement d'elle-même. Un paiement on-chain met la commande dans l'état **Paiement bitcoin en cours de confirmation** jusqu'à ses 3 confirmations (environ 30 minutes).
4. Une fois le paiement complet, le module l'enregistre et la commande passe à **Paiement accepté** : PrestaShop envoie son e-mail et crée sa facture, comme pour tout paiement. Un paiement on-chain inférieur au montant est complet aussi quand votre tolérance l'accepte, ou quand vous acceptez dans l'application sattle.me ce que la commande a reçu : une note sur la commande dit ce qui est arrivé.

Si la commande a changé après la création de sa facture (son total, sa devise, un autre paiement ou un avoir), ou si elle a été remboursée entre-temps, un paiement qui arrive malgré tout est enregistré, et la commande passe à **Paiement bitcoin à vérifier**, jamais à « Paiement accepté ». Une note sur la commande donne les sats reçus, le prix de la facture et ce que la commande doit désormais. Les pages du client disent que le paiement a été reçu et que la boutique le contactera. Réglez la différence avec le client, puis changez vous-même l'état de la commande : rétablissez la commande, ou remboursez le client.

Une facture qui se termine sans paiement laisse la commande en attente de paiement, et sa page de paiement propose au client une nouvelle facture. Le module n'annule jamais une commande : annulez vous-même une commande impayée une fois sa facture terminée sans paiement, comme pour un paiement par virement. Tant que sa facture peut encore être payée ou que son paiement est en cours de confirmation, et dès que des bitcoins sont arrivés pour elle (payée, ou moins que le montant, qui a atteint votre portefeuille), le module refuse d'annuler la commande (les états **Annulé** et **Erreur de paiement**), et le back-office dit pourquoi : réglez ce qui est arrivé avec le client, remboursez-le depuis votre portefeuille, puis enregistrez le remboursement (un avoir, l'état **Remboursé**).

La page de la commande dans le back-office montre chaque tentative de paiement : sa référence, le montant et le taux de change, comment elle a été payée, l'adresse et les transactions (en texte à copier), la preuve de paiement, la dernière vérification et la suivante, et son erreur en termes simples.

## Vérifications en arrière-plan

Le module vérifie les factures depuis le serveur de la boutique. Tant que la page de paiement d'un client est ouverte, il vérifie souvent ; pour le reste, le cron du système appelle l'adresse du module chaque minute, avec le jeton secret de la boutique. La page des réglages affiche cette adresse et une ligne prête pour la crontab de l'utilisateur du serveur web. Dans une installation multiboutique, la page liste une adresse pour chaque boutique : le cron les appelle toutes.

La page des réglages indique quand les vérifications ont tourné pour la dernière fois, et vous avertit si c'était il y a plus de 10 minutes. Sans le cron, les paiements ne sont vérifiés que tant que la page du client, ou la page de la commande dans le back-office, est ouverte.

## Remboursements

Les remboursements ne sont jamais automatiques : un paiement Lightning ne peut être remboursé que vers une facture du client, un paiement on-chain vers une de ses adresses, que la boutique ne demande jamais. Demandez-en une au client, remboursez-le depuis votre portefeuille, puis enregistrez le remboursement dans PrestaShop (un avoir, l'état de la commande).

## Données personnelles

- Le module enregistre, pour chaque tentative de paiement : sa référence, le panier et la commande, le montant et la devise, la facture (montant en sats, taux de change, facture Lightning, adresse de dépôt) et ce que ses vérifications ont trouvé. Ni nom ni adresse e-mail : ceux du client sont ceux de PrestaShop.
- Le serveur de la boutique envoie chaque facture aux relais du marchand, chiffrée pour le marchand, avec le nom de la boutique et la référence de la commande ; il demande la facture à l'adresse Lightning du marchand, le prix du bitcoin aux sources de taux de change, et l'adresse et les transactions d'une facture on-chain à un explorateur de blocs : par défaut un explorateur public, ou votre propre serveur Bitcoin si vous en indiquez un dans l'app sattle.me (avec l'explorateur public en secours, sauf si vous n'en voulez aucun). Chacun d'eux voit le serveur de la boutique, jamais le client.
- Avec le module RGPD de PrestaShop, l'export d'un client contient ses paiements en bitcoin, et son effacement en retire les adresses de dépôt et les transactions ; les références, montants, taux, dates et états restent, pour votre comptabilité.

## En cas de problème

Les journaux de PrestaShop (**Paramètres avancés**, puis **Logs**, messages commençant par `sattle.me:`) disent ce qui s'est mal passé, jamais avec la clé. La page de la commande dans le back-office montre la dernière vérification de chaque paiement et son erreur. Une commande dans l'état **Paiement bitcoin à vérifier** vous attend : lisez sa note, réglez avec le client, puis changez son état.
