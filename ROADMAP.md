# Roadmap

## Eigen update-kanaal

Sites installeren de plugin met de hand uit een zip en krijgen alleen een melding dát er een nieuwe versie is. Daardoor lopen versies uiteen en heeft een beheerder ooit zijn eigen patch in de code gezet.

Haak in op `pre_set_site_transient_update_plugins`, zodat de update gewoon in het updatescherm van WordPress verschijnt. Stuur de plugin- en PHP-versie mee, dan weet de bron wat er draait.

## Cron-volgorde en de guard die eraan hangt

`BoekDB_Import::init()` plant het import-event in op het `minutely`-interval voordat `boekdb.php` dat interval registreert, dus WordPress weigert het. Er staat geen terugkerende import; dat het toch loopt komt doordat elke batch zelf een vervolg-event inplant.

Het interval eerder registreren laat dat event wél bestaan, en dan gaat de `wp_next_scheduled()`-guard in `import()` aan en blijft het bij één batch per minuut. Volgorde en guard moeten dus samen veranderen, met een keuze over het importtempo.

## Goedkoper opnieuw importeren na een filterwijziging

Andere filters zetten de importdatum terug naar 2015, waarna elk boek opnieuw wordt opgehaald. De ISBN-lijst van de API vergelijken met wat de site al heeft, laat zien welke boeken er werkelijk bij komen.

## Batches met een tijdslimiet

Een batch haalt 100 boeken op en schrijft de offset pas weg als ze allemaal verwerkt zijn. Met bestanden van tientallen MB en een download-timeout van 300 seconden kan dat langer duren dan de server een proces laat leven. Wordt het proces afgeschoten, dan blijft de offset staan, wordt er geen vervolg ingepland en pakt pas de uurlijkse ronde de etalage weer op. Ter indicatie: 440 gewijzigde boeken van vijftien etalages leverden op een lege uploadmap 1,1 GB op, waarvan 522 MB mp3- en 233 MB pdf-fragmenten.

Laat een batch stoppen na een vaste tijd (bijvoorbeeld 90 seconden, via een filter aan te passen), schuif de offset op met het aantal werkelijk verwerkte boeken en plan direct de volgende batch in. Verlaag daarnaast de download-timeout per bestand, zodat één boek de tijdslimiet niet alleen al kan overschrijden.

Voorwaarde is dat de API de producten bij elke aanvraag in dezelfde volgorde teruggeeft, anders worden er boeken overgeslagen of dubbel gedaan. Dat geldt bij batches van 100 ook al, maar een offset die per boek opschuift maakt het zichtbaarder.

## Sleutelcontrole met een derde uitkomst

`validate_api_key()` kent alleen geldig en ongeldig. Alleen een 401 telt als ongeldig; een timeout, een 403 of een 500 telt als geldig. Dat is bewust, want `start_import()` verwijdert een etalage met een ongeldige sleutel, en een storing bij BoekDB mag geen etalages kosten. Maar daardoor slaat het instellingenscherm een nieuwe etalage op zonder dat de sleutel echt gecontroleerd is, als alleen de tweede aanroep faalt.

Geef de controle drie uitkomsten: geldig, ongeldig en onbekend. Verwijder alleen bij ongeldig, en weiger bij onbekend een nieuwe etalage met een melding. Neem daarbij mee dat de `catch` in `start_import()` om de hele lus heen staat, zodat één storing de ronde voor alle etalages stopt in plaats van alleen voor die ene.

## Tests voor de voorbeeldtemplates

`template-single-example.php` en `template-archive-example.php` worden door sitebouwers gekopieerd, maar geen test rendert ze. Een test die ze met een volledig gevuld boek rendert, vangt fouten in escaping en lege of afwijkende velden op voordat iemand ze overneemt.
