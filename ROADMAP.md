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

Een batch haalt 25 boeken op en schrijft de offset pas weg als ze allemaal verwerkt zijn. Met bestanden van tientallen MB en een download-timeout van 300 seconden kan dat langer duren dan de server een proces laat leven. Wordt het proces afgeschoten, dan blijft de offset staan, wordt er geen vervolg ingepland en pakt pas de uurlijkse ronde de etalage weer op. Ter indicatie: 440 gewijzigde boeken van vijftien etalages leverden op een lege uploadmap 1,1 GB op, waarvan 522 MB mp3- en 233 MB pdf-fragmenten.

Laat een batch stoppen na een vaste tijd (bijvoorbeeld 90 seconden, via een filter aan te passen), schuif de offset op met het aantal werkelijk verwerkte boeken en plan direct de volgende batch in. Verlaag daarnaast de download-timeout per bestand, zodat één boek de tijdslimiet niet alleen al kan overschrijden.

Voorwaarde is dat de API de producten bij elke aanvraag in dezelfde volgorde teruggeeft, anders worden er boeken overgeslagen of dubbel gedaan. Dat geldt bij batches van 25 ook al, maar een offset die per boek opschuift maakt het zichtbaarder.

## Sleutelcontrole met een derde uitkomst

`validate_api_key()` kent alleen geldig en ongeldig. Alleen een 401 telt als ongeldig; een timeout, een 403 of een 500 telt als geldig. Dat is bewust, want `start_import()` verwijdert een etalage met een ongeldige sleutel, en een storing bij BoekDB mag geen etalages kosten. Maar daardoor slaat het instellingenscherm een nieuwe etalage op zonder dat de sleutel echt gecontroleerd is, als alleen de tweede aanroep faalt.

Geef de controle drie uitkomsten: geldig, ongeldig en onbekend. Verwijder alleen bij ongeldig, en weiger bij onbekend een nieuwe etalage met een melding. Neem daarbij mee dat de `catch` in `start_import()` om de hele lus heen staat, zodat één storing de ronde voor alle etalages stopt in plaats van alleen voor die ene. Verwijder een etalage bovendien pas na een paar 401's achter elkaar: nu kost één 401, bijvoorbeeld van een tussenstation dat de Authorization-header weglaat, de etalage en al zijn boeken.

## Tests voor de voorbeeldtemplates

`template-single-example.php` en `template-archive-example.php` worden door sitebouwers gekopieerd, maar geen test rendert ze. Een test die ze met een volledig gevuld boek rendert, vangt fouten in escaping en lege of afwijkende velden op voordat iemand ze overneemt.

## Mislukte boeken: langer proberen en zichtbaar opgeven

Een boek dat niet te importeren is, krijgt drie pogingen: de batch, en de herkansing aan het eind van die ronde en van de volgende. Binnen ongeveer een uur is het budget op, en daarna wordt het boek nooit meer opgehaald tot BoekDB het wijzigt of iemand op Reset drukt. Een storing die na een uur is opgelost, laat dus boeken achter. Verhoog het aantal pogingen of spreid ze over de tijd, zet de tellers terug bij Draai import of Reset, en laat op het instellingenscherm zien welke boeken zijn opgegeven.

## Kleinere randen in de import

- `handle_boek()` controleert het resultaat van `wp_insert_post()` niet. Een mislukte insert telt als geïmporteerd en laat koppelrijen met boek 0 achter. Dit zat ook al in 1.1.1.
- `wp_insert_attachment()` wordt aangeroepen zonder `$wp_error`, dus de `is_wp_error()`-controles erna doen niets. Een mislukte insert geeft 0, en verwijzingen gaan naar 0.
- Een kapot bestand wordt verwijderd voordat de vervanger binnen is. Mislukt de sideload, dan wijzen cover, achterkant, auteursfoto of seriebeeld nergens meer naar.
- Een bijlage waarvan het maken van de afmetingen mislukte, wordt nu herkend en hergebruikt, maar krijgt haar afmetingen niet alsnog. Maak ze bij hergebruik aan als ze ontbreken.
- `store_attachment_metadata()` slaat metadata over voor alles wat geen afbeelding is, dus ook mp3-fragmenten missen ID3- en lengtegegevens. Of iets die gebruikt, is niet bekend.
- De permalink-cache van een boek dat een etalage verlaat, wordt niet geleegd. Een oude prefix-url blijft tot 12 uur staan.
- `migrate_primary_meta()` verwijdert de oude sleutel ook als het kopiëren mislukte.
- `reclaim_stale_locks()` leest de lock via `get_option()`, zodat een verouderde notoptions-cache een dode lock kan verbergen.
- De opschoning laat foto's van illustratoren en sprekers achter als hun term verdwijnt.
- De user-agent van de plugin omzeilt een filter op `http_headers_useragent` van de site, en downloads van bestanden sturen de pluginversie niet mee.

## Statusbalk en knoppen

- Een batch die uren te laat is, staat als "binnen een minuut".
- Draai import en Opruimen plannen stilletjes niets in als hetzelfde event binnen tien minuten al staat.
- Een verlopen formuliertoken geeft geen melding.

## Testgaten

Een paar beveiligingen kunnen weg zonder dat een test faalt: de `holds_lock()`-controle in `ImportLockTest`, het vastleggen van de start van een ronde in `start_import()`, en de koppeling met `register_shutdown_function`. Een test in `ImportResilienceTest` controleert niets, `PrimaryMetaTest` gaat nog uit van WordPress 5.5, en `FileDownloadTest` laat bij één test een gedownload bestand achter.
